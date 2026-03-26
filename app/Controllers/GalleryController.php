<?php

/**
 * Controller responsible for handling gallery views, image display,
 * and serving stored image files to the client.
 *
 * Responsibilities:
 * - Render the main gallery index with pagination
 * - Render individual image detail pages (metadata, votes, favorites, comments)
 * - Handle interactive actions (edit description, favorite, upvote, comment)
 * - Serve original image files securely with optional per-user caching
 *
 * Security considerations:
 * - CSRF protection for all state-changing POST actions
 * - Per-session view tracking to reduce artificial view inflation
 * - Age-gating logic for sensitive content (requires verified DOB)
 * - Path normalization/realpath checks when serving files from disk
 */
class GalleryController extends BaseController
{
    /**
     * Static template variables assigned for all gallery templates.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
    ];

    /**
     * Gallery templates require a CSRF token for interactive actions.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;


    /**
     * Retrieve the current request's stable device cookie value.
     *
     * Gallery page image tokens bind to the existing browser/device cookie so
     * follow-up image requests can be validated without starting the full
     * application session lifecycle again.
     *
     * @return string Device cookie value, or empty string when unavailable.
     */
    private static function getCurrentRequestDeviceId(): string
    {
        return GalleryPageTokenStore::getCurrentDeviceId(self::getConfig());
    }


    /**
     * Render one friendly locked-content page for age-gated images.
     *
     * @param TemplateEngine $template
     * @param array|null $user
     * @param string $contentRating
     * @param array $config
     * @return void
     */
    private static function renderLockedContentPage(TemplateEngine $template, ?array $user, string $contentRating, array $config): void
    {
        $copy = AgeGateHelper::getLockedContentCopy($user, $contentRating, $config);

        http_response_code(423);
        $template->assign('title', $copy['title']);
        $template->assign('message', $copy['message']);
        $template->assign('status_code', 423);
        $template->assign('status_label', 'Locked');
        $template->assign('link', $user ? '/profile/dob' : '/user/login?return_to=' . rawurlencode(RedirectHelper::getCurrentRequestUri()));
        $template->render('errors/error_page.html');
    }

    /**
     * Build a clean tokenized image URL for fast gallery/detail image delivery.
     *
     * @param string $hash Unique image hash identifier.
     * @param string $token Short-lived gallery image token.
     * @return string Tokenized image URL.
     */
    private static function buildTokenizedImageUrl(string $hash, string $token): string
    {
        return '/image/' . $hash . '/token/' . $token;
    }

    /**
     * Build one gallery image-view URL with optional query-string values.
     *
     * Gallery return navigation is stored in the session.
     *
     * @param string $hash Unique image hash identifier.
     * @param string|null $galleryBackUrl Optional gallery return destination.
     * @param array $query Additional query string values.
     * @return string Gallery image-view URL.
     */
    private static function buildGalleryViewUrl(string $hash, ?string $galleryBackUrl = null, array $query = []): string
    {
        $url = '/gallery/' . $hash;

        if (!empty($query))
        {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Resolve the gallery page that image-view actions should return to.
     *
     * @param string|null $galleryBackUrl Optional explicit gallery page.
     * @return string Safe gallery page URL.
     */
    private static function resolveGalleryBackUrl(?string $galleryBackUrl = null): string
    {
        return RedirectHelper::resolveGalleryPage($galleryBackUrl, '/gallery');
    }

    /**
     * Resolve one stored image path safely inside the images directory.
     *
     * @param string $originalPath Stored original image path from the database/session.
     * @return string|null Absolute validated file path, or null when invalid.
     */
    private static function resolveStoredImagePath(string $originalPath): ?string
    {
        $baseDir = realpath(IMAGE_PATH);
        if (!$baseDir || $originalPath === '')
        {
            return null;
        }

        $fullPath = realpath($baseDir . '/' . ltrim(str_replace("images/", "", $originalPath), '/'));
        if (!$fullPath || strpos($fullPath, $baseDir) !== 0 || !file_exists($fullPath))
        {
            return null;
        }

        return $fullPath;
    }

    /**
     * Resolve one gallery report-form notice from the current query string.
     *
     * @return array{alert_class: string, alert_message: string, auto_open: int}
     */
    private static function resolveReportNoticeFromQuery(): array
    {
        $state = Security::sanitizeString($_GET['report'] ?? '');

        return match ($state)
        {
            'submitted' => [
                'alert_class' => 'alert-success',
                'alert_message' => 'Your report has been submitted and this image is now under review.',
                'auto_open' => 0,
            ],
            'exists' => [
                'alert_class' => 'alert-warning',
                'alert_message' => 'You already have an open report for this image.',
                'auto_open' => 0,
            ],
            'invalid' => [
                'alert_class' => 'alert-danger',
                'alert_message' => 'Please choose a report category and include the details for your report.',
                'auto_open' => 1,
            ],
            default => [
                'alert_class' => '',
                'alert_message' => '',
                'auto_open' => 0,
            ],
        };
    }


    /**
     * Serve one stored image from cache or disk using trusted metadata.
     *
     * @param string $hash Image hash identifier.
     * @param string $originalPath Stored original file path.
     * @param string $mimeType Stored mime type.
     * @param bool $usePerUserCache Whether the cache should be user-scoped.
     * @param int $userId Current user ID, or 0 for guests.
     * @param bool $galleryPageCache Whether gallery-page browser cache headers should be used.
     * @return bool True when the response was served, otherwise false.
     */
    private static function serveStoredImageFile(string $hash, string $originalPath, string $mimeType, bool $usePerUserCache, int $userId = 0, bool $galleryPageCache = false): bool
    {
        $cacheUserId = ($usePerUserCache && $userId > 0) ? $userId : null;

        $cachedPath = ImageCacheEngine::getCachedImage($hash, $cacheUserId);
        if ($cachedPath)
        {
            $resolvedMimeType = mime_content_type($cachedPath) ?: ($mimeType ?: 'application/octet-stream');
            header('Content-Type: ' . $resolvedMimeType);
            header('Content-Length: ' . filesize($cachedPath));

            if ($galleryPageCache)
            {
                self::applyGalleryPageImageCacheHeaders();
            }
            else
            {
                self::applyServedImageCacheHeaders($usePerUserCache);
            }

            readfile($cachedPath);
            exit;
        }

        $fullPath = self::resolveStoredImagePath($originalPath);
        if ($fullPath === null)
        {
            return false;
        }

        $cachedPath = ImageCacheEngine::storeImage($hash, $fullPath, $cacheUserId);

        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($cachedPath));

        if ($galleryPageCache)
        {
            self::applyGalleryPageImageCacheHeaders();
        }
        else
        {
            self::applyServedImageCacheHeaders($usePerUserCache);
        }

        readfile($cachedPath);
        exit;
    }

    /**
     * Display the gallery index with paginated images.
     *
     * Uses SQL-level pagination for the current page, filters age-sensitive
     * content before the query is executed, then issues one lightweight page
     * token so follow-up gallery image requests avoid the heavy session path.
     *
     * @param int|null $page Current page number, defaults to 1.
     * @return void
     */
    public static function index($page = 1): void
    {
        $config = self::getConfig();
        $template = self::initTemplate();

        RedirectHelper::rememberGalleryPage(RedirectHelper::getCurrentRequestUri());

        $page = self::normalizeGalleryPageNumber($page);
        $limit = self::resolveGalleryImagesPerPage($config);
        $userId = self::getCurrentUserId();
        $currentUser = self::resolveGalleryViewer($template, $userId, 'Your account group cannot view the gallery.');

        if (RequestGuard::isGalleryPageRateLimited())
        {
            self::renderErrorPage(429, 'Too Many Requests', 'Too many gallery page requests. Please wait and try again.', $template);
            return;
        }

        $viewerContentAccessLevel = AgeGateHelper::getViewerContentAccessLevel($currentUser, $config);
        $totalImages = ImageModel::countGalleryImages($viewerContentAccessLevel);
        $totalPages = max(1, (int)ceil($totalImages / $limit));
        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $limit;
        $pageImages = ImageModel::fetchGalleryPage($viewerContentAccessLevel, $limit, $offset);
        $galleryPageToken = GalleryPageTokenStore::issue(
            $pageImages,
            $userId,
            session_id(),
            self::getCurrentRequestDeviceId()
        );

        $galleryBackUrl = self::resolveGalleryBackUrl(RedirectHelper::getCurrentRequestUri());
        $templateState = self::buildGalleryIndexTemplateState($config, $pageImages, $galleryPageToken, $galleryBackUrl, $page, $totalPages);

        foreach ($templateState as $key => $value)
        {
            $template->assign($key, $value);
        }

        $template->render('gallery/gallery_index.html');
    }

    /**
     * Normalize one gallery page value from routing into a usable page number.
     *
     * @param mixed $page Incoming route/page value.
     * @return int Positive gallery page number.
     */
    private static function normalizeGalleryPageNumber($page): int
    {
        $page = TypeHelper::toInt($page) ?? 1;
        if ($page < 1)
        {
            return 1;
        }

        return $page;
    }

    /**
     * Resolve the configured gallery page size safely for production use.
     *
     * @param array $config Runtime configuration array.
     * @return int Positive gallery page size.
     */
    private static function resolveGalleryImagesPerPage(array $config): int
    {
        $limit = TypeHelper::toInt($config['gallery']['images_displayed'] ?? null) ?? 1;
        if ($limit < 1)
        {
            return 1;
        }

        return $limit;
    }

    /**
     * Load the current gallery viewer record when one authenticated user exists.
     *
     * Guests may still view public-safe gallery content, so this only looks up a
     * profile record for authenticated requests. Group permissions are enforced
     * before the user record is returned.
     *
     * @param TemplateEngine $template Prepared template instance.
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @param string $deniedMessage User-facing denial copy.
     * @return array|null Age-verification user row, or null for guests.
     */
    private static function resolveGalleryViewer(TemplateEngine $template, int $userId, string $deniedMessage): ?array
    {
        if ($userId < 1)
        {
            return null;
        }

        RoleHelper::requireLogin();
        if (!GroupPermissionHelper::hasPermission('view_gallery'))
        {
            self::renderErrorPage(403, 'Access Denied', $deniedMessage, $template);
            exit;
        }

        return UserModel::findAgeVerificationById($userId);
    }

    /**
     * Build the gallery-index template payload.
     *
     * @param array $config Runtime configuration array.
     * @param array $pageImages Gallery rows for the current page.
     * @param string $galleryPageToken Page token used for fast image delivery.
     * @param string $galleryBackUrl Current gallery page URL.
     * @param int $page Current page number.
     * @param int $totalPages Total number of pages.
     * @return array<string, mixed> Template assignment payload.
     */
    private static function buildGalleryIndexTemplateState(array $config, array $pageImages, string $galleryPageToken, string $galleryBackUrl, int $page, int $totalPages): array
    {
        $pagination = self::buildGalleryPaginationState($config, $page, $totalPages);

        return [
            'images' => self::buildGalleryIndexImageRows($pageImages, $galleryPageToken, $galleryBackUrl),
            'pagination_prev' => $pagination['pagination_prev'],
            'pagination_next' => $pagination['pagination_next'],
            'pagination_pages' => $pagination['pagination_pages'],
        ];
    }

    /**
     * Build the gallery-index image rows expected by the existing template.
     *
     * @param array $pageImages Gallery rows fetched for the current page.
     * @param string $galleryPageToken Page token for fast image delivery.
     * @param string $galleryBackUrl Current gallery page URL.
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private static function buildGalleryIndexImageRows(array $pageImages, string $galleryPageToken, string $galleryBackUrl): array
    {
        $loopImages = [];
        foreach ($pageImages as $img)
        {
            $imageHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
            if ($imageHash === '')
            {
                continue;
            }

            $views = TypeHelper::toInt($img['views'] ?? null) ?? 0;
            $loopImages[] = [
                self::buildTokenizedImageUrl($imageHash, $galleryPageToken),
                self::buildGalleryViewUrl($imageHash, $galleryBackUrl),
                $views,
            ];
        }

        return $loopImages;
    }

    /**
     * Build pagination assignments for the gallery-index template.
     *
     * @param array $config Runtime configuration array.
     * @param int $page Current page number.
     * @param int $totalPages Total number of available pages.
     * @return array{pagination_prev: string|null, pagination_next: string|null, pagination_pages: array<int, array{0: string|null, 1: int|string, 2: bool}>}
     */
    private static function buildGalleryPaginationState(array $config, int $page, int $totalPages): array
    {
        $paginationPrev = $page > 1 ? ($page - 1 === 1 ? '/gallery' : '/gallery/page/' . ($page - 1)) : null;
        $paginationNext = $page < $totalPages ? '/gallery/page/' . ($page + 1) : null;

        $range = TypeHelper::toInt($config['gallery']['pagination_range'] ?? null) ?? 2;
        if ($range < 0)
        {
            $range = 0;
        }

        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);
        $paginationPages = [];

        if ($start > 1)
        {
            $paginationPages[] = ['/gallery/page/1', 1, false];
            if ($start > 2)
            {
                $paginationPages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $paginationPages[] = [
                $i === 1 ? '/gallery/page/1' : '/gallery/page/' . $i,
                $i,
                $i === $page,
            ];
        }

        if ($end < $totalPages)
        {
            if ($end < $totalPages - 1)
            {
                $paginationPages[] = [null, '...', false];
            }

            $paginationPages[] = ['/gallery/page/' . $totalPages, $totalPages, false];
        }

        return [
            'pagination_prev' => $paginationPrev,
            'pagination_next' => $paginationNext,
            'pagination_pages' => $paginationPages,
        ];
    }

    /**
     * Display a single image view with metadata.
     *
     * Includes:
     * - Image metadata (dimensions, hashes, file size, created time, etc.)
     * - Aggregated stats (votes, favorites, views)
     * - Comment pagination and display
     * - Permission flags for owner/staff edit actions
     *
     * Also enforces:
     * - Approved-only visibility (404 for missing/unapproved)
     * - Age-sensitive access control (403 when not allowed)
     * - Per-session view tracking to prevent inflating counts by refreshing
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function view(string $hash): void
    {
        $config = self::getConfig();
        $template = self::initTemplate();
        $userId = self::getCurrentUserId();

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        self::captureRequestedGalleryBackUrl();

        $galleryBackUrl = self::resolveGalleryBackUrl();
        $commentsPerPage = self::resolveGalleryCommentsPerPage($config);
        $commentsPage = self::normalizeGalleryPageNumber($_GET['cpage'] ?? 1);
        $currentUser = self::resolveGalleryViewer($template, $userId, 'Your account group cannot view this gallery content.');

        $img = ImageModel::findApprovedGalleryImageByHash($hash);
        if (!$img)
        {
            self::renderImageNotFound($template);
            return;
        }

        $contentRating = AgeGateHelper::normalizeContentRating(
            TypeHelper::toString($img['content_rating'] ?? '', allowEmpty: true) ?? '',
            TypeHelper::toInt($img['age_sensitive'] ?? 0) ?? 0
        );

        if (!AgeGateHelper::canAccessContentRating($currentUser, $contentRating, $config))
        {
            self::renderLockedContentPage($template, $currentUser, $contentRating, $config);
            return;
        }

        $imageId = TypeHelper::toInt($img['id']) ?? 0;
        if ($imageId < 1)
        {
            self::renderImageNotFound($template);
            return;
        }

        self::trackViewedGalleryImage($img, $imageId, $config);

        $ownerUserId = TypeHelper::toInt($img['user_id'] ?? null);
        $username = self::getUsernameById($ownerUserId);
        $uploaderHasBirthdayBadge = AgeGateHelper::shouldShowBirthdayBadge($img['date_of_birth'] ?? null, $config) ? 1 : 0;
        $hasVoted = $userId > 0 ? ImageModel::hasUserVote($userId, $imageId) : false;
        $hasFavorited = $userId > 0 ? ImageModel::hasUserFavorite($userId, $imageId) : false;
        $commentState = self::resolveGalleryCommentState($imageId, $commentsPerPage, $commentsPage, $config);
        $permissionState = self::resolveGalleryActionPermissions($img, $userId);
        $templateState = self::buildGalleryViewTemplateState(
            $config,
            $img,
            $userId,
            $galleryBackUrl,
            $contentRating,
            $username,
            $uploaderHasBirthdayBadge,
            $hasVoted,
            $hasFavorited,
            $commentState,
            $permissionState
        );

        foreach ($templateState as $key => $value)
        {
            $template->assign($key, $value);
        }

        $template->render('gallery/gallery_view.html');
    }

    /**
     * Capture one explicit gallery return URL and clean it out of the request.
     *
     * Image view routes support a one-time back_to query value so actions can
     * return to the caller's exact gallery page. The value is stored in the
     * session and then stripped from the visible URL.
     *
     * @return void
     */
    private static function captureRequestedGalleryBackUrl(): void
    {
        $requestedGalleryBackUrl = RedirectHelper::sanitizeInternalPath($_GET['back_to'] ?? null);
        if ($requestedGalleryBackUrl === null)
        {
            return;
        }

        RedirectHelper::rememberGalleryPage($requestedGalleryBackUrl);

        $currentRequestUri = RedirectHelper::getCurrentRequestUri();
        $cleanViewUrl = RedirectHelper::removeQueryParameter($currentRequestUri, 'back_to', true);
        if ($cleanViewUrl !== null && $cleanViewUrl !== $currentRequestUri)
        {
            header('Location: ' . $cleanViewUrl);
            exit;
        }
    }

    /**
     * Resolve the configured per-image comment page size safely.
     *
     * @param array $config Runtime configuration array.
     * @return int Positive comment page size.
     */
    private static function resolveGalleryCommentsPerPage(array $config): int
    {
        $commentsPerPage = TypeHelper::toInt($config['gallery']['comments_per_page'] ?? null) ?? 5;
        if ($commentsPerPage < 1)
        {
            return 10;
        }

        return $commentsPerPage;
    }

    /**
     * Track one image view inside the current session to avoid repeat inflation.
     *
     * @param array $img Image metadata row, updated in-place when views increase.
     * @param int $imageId Database image ID.
     * @param array $config Runtime configuration array.
     * @return void
     */
    private static function trackViewedGalleryImage(array &$img, int $imageId, array $config): void
    {
        $viewedImages = SessionManager::get('viewed_images', []);
        if (!is_array($viewedImages))
        {
            $viewedImages = [];
        }

        $imgHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
        if ($imgHash === '' || in_array($imgHash, $viewedImages, true))
        {
            return;
        }

        ImageModel::incrementViews($imageId);
        ControlServer::bumpImageLiveTick($config, $imgHash);

        $viewedImages[] = $imgHash;
        SessionManager::set('viewed_images', $viewedImages);
        $img['views'] = (TypeHelper::toInt($img['views']) ?? 0) + 1;
    }

    /**
     * Resolve paginated comment data for one gallery image.
     *
     * @param int $imageId Database image ID.
     * @param int $commentsPerPage Configured comments per page.
     * @param int $commentsPage Requested comment page.
     * @param array $config Runtime configuration array.
     * @return array<string, mixed> Template-ready comment pagination state.
     */
    private static function resolveGalleryCommentState(int $imageId, int $commentsPerPage, int $commentsPage, array $config): array
    {
        $commentCount = ImageModel::countVisibleComments($imageId);
        $commentsTotalPages = (int)ceil($commentCount / $commentsPerPage);
        if ($commentsTotalPages < 1)
        {
            $commentsTotalPages = 1;
        }

        if ($commentsPage > $commentsTotalPages)
        {
            $commentsPage = $commentsTotalPages;
        }

        $commentsOffset = ($commentsPage - 1) * $commentsPerPage;
        $comments = ImageModel::fetchVisibleCommentsPage($imageId, $commentsPerPage, $commentsOffset);

        return [
            'img_comment_count' => $commentCount,
            'comment_rows' => self::buildGalleryCommentRows($comments, $config),
            'comments_page' => $commentsPage,
            'comments_total_pages' => $commentsTotalPages,
            'comments_has_prev' => $commentsPage > 1,
            'comments_has_next' => $commentsPage < $commentsTotalPages,
            'comments_prev_page' => $commentsPage - 1,
            'comments_next_page' => $commentsPage + 1,
        ];
    }

    /**
     * Normalize comment rows for the gallery view template.
     *
     * @param array $comments Raw comment rows from the model.
     * @param array $config Runtime configuration array.
     * @return array<int, array{0: string, 1: string, 2: mixed, 3: int}>
     */
    private static function buildGalleryCommentRows(array $comments, array $config): array
    {
        $commentRows = [];
        foreach ($comments as $row)
        {
            $commentRows[] = [
                $row['username'] ?? 'Unknown',
                DateHelper::format($row['created_at']),
                $row['comment_body'],
                AgeGateHelper::shouldShowBirthdayBadge($row['date_of_birth'] ?? null, $config) ? 1 : 0,
            ];
        }

        return $commentRows;
    }

    /**
     * Resolve interactive action availability and denial copy for one image view.
     *
     * @param array $img Gallery image row.
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @return array<string, mixed> Template-ready permission state.
     */
    private static function resolveGalleryActionPermissions(array $img, int $userId): array
    {
        $canEditImage = false;
        $canCommentImage = false;
        $canFavoriteImage = false;
        $canVoteImage = false;
        $canReportImage = true;

        $editPermissionMessage = 'You do not have permission to edit this image.';
        $commentPermissionMessage = 'You do not have permission to comment on images.';
        $favoritePermissionMessage = 'You do not have permission to favorite images.';
        $votePermissionMessage = 'You do not have permission to like images.';
        $reportPermissionMessage = 'You do not have permission to report images.';

        if ($userId > 0)
        {
            $imgUserId = TypeHelper::toInt($img['user_id'] ?? null) ?? 0;
            $isOwner = ($imgUserId === $userId);
            $canEditOwn = GroupPermissionHelper::hasPermission('edit_own_image');
            $canEditAny = GroupPermissionHelper::hasPermission('edit_any_image');

            $canEditImage = (($isOwner && $canEditOwn) || $canEditAny);
            $canCommentImage = GroupPermissionHelper::hasPermission('comment_images');
            $canFavoriteImage = GroupPermissionHelper::hasPermission('favorite_images');
            $canVoteImage = GroupPermissionHelper::hasPermission('vote_images');
            $canReportImage = GroupPermissionHelper::hasPermission('report_images');

            $editPermissionMessage = $canEditImage
                ? ''
                : ($isOwner ? 'Your account group cannot edit your own images.' : 'Your account group cannot edit this image.');
            $commentPermissionMessage = $canCommentImage ? '' : 'Your account group cannot comment on images.';
            $favoritePermissionMessage = $canFavoriteImage ? '' : 'Your account group cannot favorite images.';
            $votePermissionMessage = $canVoteImage ? '' : 'Your account group cannot like or vote on images.';
            $reportPermissionMessage = $canReportImage ? '' : 'Your account group cannot report images.';
        }
        else
        {
            $commentPermissionMessage = 'Please login to comment on images.';
            $favoritePermissionMessage = 'Please login to favorite images.';
            $votePermissionMessage = 'Please login to like images.';
            $reportPermissionMessage = '';
        }

        return [
            'can_edit_image' => $canEditImage,
            'can_comment_image' => $canCommentImage ? 1 : 0,
            'can_favorite_image' => $canFavoriteImage ? 1 : 0,
            'can_vote_image' => $canVoteImage ? 1 : 0,
            'can_report_image' => $canReportImage ? 1 : 0,
            'edit_permission_message' => $editPermissionMessage,
            'comment_permission_message' => $commentPermissionMessage,
            'favorite_permission_message' => $favoritePermissionMessage,
            'vote_permission_message' => $votePermissionMessage,
            'report_permission_message' => $reportPermissionMessage,
            'permission_notice_message' => self::resolveGalleryPermissionNotice($userId, $canEditImage, $canCommentImage, $canReportImage, $canFavoriteImage, $canVoteImage),
        ];
    }

    /**
     * Build one summary notice for disabled gallery actions on the current image.
     *
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @param bool $canEditImage Whether editing is allowed.
     * @param bool $canCommentImage Whether commenting is allowed.
     * @param bool $canReportImage Whether reporting is allowed.
     * @param bool $canFavoriteImage Whether favoriting is allowed.
     * @param bool $canVoteImage Whether voting is allowed.
     * @return string User-facing notice copy.
     */
    private static function resolveGalleryPermissionNotice(int $userId, bool $canEditImage, bool $canCommentImage, bool $canReportImage, bool $canFavoriteImage, bool $canVoteImage): string
    {
        if ($userId < 1)
        {
            return '';
        }

        $disabledActions = [];
        if (!$canEditImage)
        {
            $disabledActions[] = 'edit';
        }

        if (!$canCommentImage)
        {
            $disabledActions[] = 'comment';
        }

        if (!$canReportImage)
        {
            $disabledActions[] = 'report';
        }

        if (!$canFavoriteImage)
        {
            $disabledActions[] = 'favorite';
        }

        if (!$canVoteImage)
        {
            $disabledActions[] = 'like';
        }

        if (empty($disabledActions))
        {
            return '';
        }

        return 'Some actions are disabled for your current account group: ' . implode(', ', $disabledActions) . '.';
    }

    /**
     * Build the gallery-view template payload.
     *
     * @param array $config Runtime configuration array.
     * @param array $img Approved gallery image row.
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @param string $galleryBackUrl Resolved gallery return path.
     * @param string $contentRating Normalized content rating value.
     * @param string $username Image owner username.
     * @param int $uploaderHasBirthdayBadge Whether the uploader should show a birthday badge.
     * @param bool $hasVoted Whether the current user already voted.
     * @param bool $hasFavorited Whether the current user already favorited.
     * @param array $commentState Comment pagination/template state.
     * @param array $permissionState Interactive permission/template state.
     * @return array<string, mixed> Template assignment payload.
     */
    private static function buildGalleryViewTemplateState(array $config, array $img, int $userId, string $galleryBackUrl, string $contentRating, string $username, int $uploaderHasBirthdayBadge, bool $hasVoted, bool $hasFavorited, array $commentState, array $permissionState): array
    {
        $openReportCount = TypeHelper::toInt($img['open_reports'] ?? 0) ?? 0;
        $reportNotice = self::resolveReportNoticeFromQuery();

        return $commentState + $permissionState + self::resolveGalleryLiveViewState($config, $img, $userId) + [
            'img_hash' => $img['image_hash'],
            'img_username' => ucfirst($username),
            'img_description' => $img['description'],
            'img_mime_type' => $img['mime_type'],
            'img_width' => $img['width'],
            'img_height' => $img['height'],
            'img_size' => StorageHelper::formatFileSize($img['size_bytes']),
            'img_md5' => $img['md5'],
            'img_sha1' => $img['sha1'],
            'img_sha256' => $img['sha256'],
            'img_sha512' => $img['sha512'],
            'img_reject_reason' => $img['reject_reason'],
            'img_approved_status' => ucfirst($img['status']),
            'img_created_at' => DateHelper::format($img['created_at']),
            'img_age_sensitive' => $img['age_sensitive'],
            'img_content_rating' => $contentRating,
            'img_content_rating_label' => AgeGateHelper::getContentRatingLabel($contentRating),
            'img_content_rating_pill_class' => AgeGateHelper::getContentRatingPillClass($contentRating),
            'img_uploader_has_birthday_badge' => $uploaderHasBirthdayBadge,
            'img_votes' => NumericalHelper::formatCount($img['votes']),
            'img_has_voted' => $hasVoted,
            'img_favorites' => NumericalHelper::formatCount($img['favorites']),
            'img_views' => NumericalHelper::formatCount($img['views']),
            'img_has_favorited' => $hasFavorited,
            'img_has_tag' => false,
            'gallery_back_url' => $galleryBackUrl,
            'gallery_back_url_encoded' => rawurlencode($galleryBackUrl),
            'img_has_open_reports' => $openReportCount > 0 ? 1 : 0,
            'img_open_report_count' => $openReportCount,
            'report_notice_class' => $reportNotice['alert_class'],
            'report_notice_message' => $reportNotice['alert_message'],
            'report_modal_auto_open' => (int)$reportNotice['auto_open'],
        ];
    }

    /**
     * Resolve gallery live-view transport values and tokenized image URLs.
     *
     * @param array $config Runtime configuration array.
     * @param array $img Approved gallery image row.
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @return array<string, mixed> Live-view template state.
     */
    private static function resolveGalleryLiveViewState(array $config, array $img, int $userId): array
    {
        $imageHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
        $controlBlock = $config['control_server'] ?? ($config['maintenance_server'] ?? []);
        $webSocketConfig = is_array($controlBlock['websocket'] ?? null) ? $controlBlock['websocket'] : [];
        $webSocketPort = max(1, min(65535, TypeHelper::toInt($webSocketConfig['port'] ?? null) ?? (ControlServer::controlPort($config) + 1)));
        $requestHost = TypeHelper::toString($_SERVER['HTTP_HOST'] ?? '', allowEmpty: true) ?? '';
        $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: '127.0.0.1';
        $publicHost = TypeHelper::toString($webSocketConfig['public_host'] ?? '', allowEmpty: true) ?? '';
        $bindAddress = TypeHelper::toString($webSocketConfig['bind_address'] ?? '', allowEmpty: true) ?? '';
        $webSocketHost = $publicHost !== '' ? $publicHost : $requestHost;
        if ($webSocketHost === 'localhost' && $bindAddress !== '' && strpos($bindAddress, ':') === false)
        {
            $webSocketHost = '127.0.0.1';
        }

        $publicScheme = TypeHelper::toString($webSocketConfig['public_scheme'] ?? '', allowEmpty: true) ?? '';
        $webSocketScheme = $publicScheme !== ''
            ? $publicScheme
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws');

        $publicPath = TypeHelper::toString($webSocketConfig['public_path'] ?? '/gallery-live', allowEmpty: true) ?? '/gallery-live';
        if ($publicPath === '')
        {
            $publicPath = '/gallery-live';
        }
        elseif ($publicPath[0] !== '/')
        {
            $publicPath = '/' . $publicPath;
        }

        $viewImageToken = GalleryPageTokenStore::issue([$img], $userId, session_id(), GalleryPageTokenStore::getCurrentDeviceId($config));

        return [
            'img_image_url' => self::buildTokenizedImageUrl($imageHash, $viewImageToken),
            'img_original_url' => '/gallery/original/' . $imageHash,
            'img_live_poll_url' => '/gallery/' . $imageHash . '/live',
            'img_live_websocket_url' => $webSocketScheme . '://' . $webSocketHost . ':' . $webSocketPort . $publicPath,
            'img_live_tick' => ControlServer::imageLiveTick($config, $imageHash),
        ];
    }

    /**
     * Apply cache headers for one served image response.
     *
     * Public images may be cached by browsers and shared caches. Any image that
     * depends on authentication or age-verification is restricted to private,
     * non-shared browser handling.
     *
     * @param bool $private Whether the response is authorization-dependent
     * @return void
     */
    private static function applyServedImageCacheHeaders(bool $private): void
    {
        if ($private)
        {
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Vary: Cookie');
            return;
        }

        header('Cache-Control: public, max-age=604800');
    }

    /**
     * Apply browser-only cache headers for gallery page image requests.
     *
     * Gallery thumbnails remain authorization-scoped to the current session, so
     * they should not be stored by shared caches, but browsers may reuse them
     * briefly during normal paging/back-navigation.
     *
     * @return void
     */
    private static function applyGalleryPageImageCacheHeaders(): void
    {
        header('Cache-Control: private, max-age=300');
        header('Vary: Cookie');
    }

    /**
     * Render one rate-limit response for interactive gallery actions.
     *
     * @param TemplateEngine $template Template engine instance
     * @param bool $json Whether JSON is expected by the client
     * @return void
     */
    private static function renderInteractiveActionRateLimited(TemplateEngine $template, bool $json = false): void
    {
        if ($json)
        {
            self::sendJsonResponse(false, 'Too many requests. Please wait and try again.', [], 429);
            return;
        }

        http_response_code(429);
        $template->assign('title', 'Too Many Requests');
        $template->assign('message', 'Too many requests. Please wait and try again.');
        $template->render('errors/error_page.html');
    }

    /**
     * Determine whether the current gallery request expects a JSON response.
     *
     * @return bool True when the client requested JSON
     */
    private static function wantsJsonResponse(): bool
    {
        $requestedWith = strtolower(TypeHelper::toString($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', allowEmpty: true) ?? '');
        $accept = strtolower(TypeHelper::toString($_SERVER['HTTP_ACCEPT'] ?? '', allowEmpty: true) ?? '');

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    /**
     * Build the current live interaction state for one image.
     *
     * @param int $imageId Database image ID
     * @param int $userId Current user ID or 0 when guest
     * @return array<string, mixed> Live state payload
     */
    private static function getLiveInteractionState(int $imageId, int $userId = 0): array
    {
        $stats = ImageModel::getInteractionStatsByImageId($imageId);

        if (!$stats)
        {
            return [];
        }

        $hasVoted = false;
        $hasFavorited = false;
        if ($userId > 0)
        {
            $hasVoted = ImageModel::hasUserVote($userId, $imageId);
            $hasFavorited = ImageModel::hasUserFavorite($userId, $imageId);
        }

        $votes = TypeHelper::toInt($stats['votes'] ?? null) ?? 0;
        $favorites = TypeHelper::toInt($stats['favorites'] ?? null) ?? 0;
        $views = TypeHelper::toInt($stats['views'] ?? null) ?? 0;
        $comments = TypeHelper::toInt($stats['comments'] ?? null) ?? 0;

        return [
            'image_hash' => TypeHelper::toString($stats['image_hash'] ?? '', allowEmpty: true) ?? '',
            'votes' => $votes,
            'favorites' => $favorites,
            'views' => $views,
            'comments' => $comments,
            'votes_display' => NumericalHelper::formatCount($votes),
            'favorites_display' => NumericalHelper::formatCount($favorites),
            'views_display' => NumericalHelper::formatCount($views),
            'comments_display' => NumericalHelper::formatCount($comments),
            'has_voted' => $hasVoted,
            'has_favorited' => $hasFavorited,
        ];
    }

    /**
     * Emit a JSON response for live gallery actions.
     *
     * @param bool $ok Whether the request succeeded
     * @param string $message Response message
     * @param array<string, mixed> $payload Additional payload values
     * @param int $statusCode HTTP status code
     * @return void
     */
    private static function sendJsonResponse(bool $ok, string $message, array $payload = [], int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge([
            'ok' => $ok,
            'message' => $message,
        ], $payload), JSON_UNESCAPED_SLASHES);
    }


    /**
     * Render one shared method-not-allowed page for gallery actions.
     *
     * @param TemplateEngine $template Template engine instance.
     * @return void
     */
    private static function renderMethodNotAllowed(TemplateEngine $template): void
    {
        http_response_code(405);
        $template->assign('title', 'Method Not Allowed');
        $template->assign('message', 'Invalid request.');
        $template->render('errors/error_page.html');
    }

    /**
     * Validate the shared POST / rate-limit / CSRF requirements for gallery actions.
     *
     * @param TemplateEngine $template Template engine instance.
     * @param string $rateLimitAction RequestGuard action bucket.
     * @param bool $json Whether the caller expects JSON responses.
     * @return bool True when the request may continue.
     */
    private static function validateGalleryPostActionRequest(TemplateEngine $template, string $rateLimitAction, bool $json = false): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            self::renderMethodNotAllowed($template);
            return false;
        }

        if (RequestGuard::isInteractiveActionRateLimited($rateLimitAction))
        {
            self::renderInteractiveActionRateLimited($template, $json);
            return false;
        }

        if (!self::hasValidGalleryPostCsrfToken())
        {
            self::renderInvalidRequest($template);
            return false;
        }

        return true;
    }

    /**
     * Verify the POSTed CSRF token used by gallery action forms.
     *
     * @return bool True when the token is valid.
     */
    private static function hasValidGalleryPostCsrfToken(): bool
    {
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        return Security::verifyCsrfToken($csrfToken);
    }

    /**
     * Require an authenticated user for one gallery action and return its user id.
     *
     * @param string $permission Permission slug required for the action.
     * @return int Authenticated user id.
     */
    private static function requireGalleryActionUser(string $permission): int
    {
        RoleHelper::requireLogin();
        GroupPermissionHelper::requirePermission($permission);

        return self::requireAuthenticatedUserId();
    }

    /**
     * Normalize one gallery image hash route value for action handlers.
     *
     * @param string $hash Route-provided image hash.
     * @param TemplateEngine $template Template engine instance.
     * @return string|null Normalized hash or null when invalid.
     */
    private static function normalizeGalleryActionHash(string $hash, TemplateEngine $template): ?string
    {
        $hash = TypeHelper::toString($hash);
        if ($hash !== '')
        {
            return $hash;
        }

        self::renderImageNotFound($template);
        return null;
    }

    /**
     * Redirect back to the image view while preserving the remembered gallery page.
     *
     * @param string $hash Image hash identifier.
     * @param array $query Additional query string values.
     * @return void
     */
    private static function redirectToGalleryImageView(string $hash, array $query = []): void
    {
        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null, $query));
        exit;
    }

    /**
     * Render one lightweight error page when an interaction target image is missing.
     *
     * @param TemplateEngine $template Template engine instance.
     * @param string $title Error page title.
     * @param string $message User-facing error message.
     * @return void
     */
    private static function renderMissingInteractionImagePage(TemplateEngine $template, string $title, string $message): void
    {
        $template->assign('title', $title);
        $template->assign('message', $message);
        $template->assign('link', null);
        $template->render('errors/error_page.html');
    }

    /**
     * Finish one successful image interaction with JSON or redirect output.
     *
     * @param string $hash Image hash identifier.
     * @param int $imageId Database image id.
     * @param int $userId Current user id.
     * @param bool $json Whether JSON output is expected.
     * @param string $message Success message.
     * @return void
     */
    private static function completeInteractiveImageAction(string $hash, int $imageId, int $userId, bool $json, string $message): void
    {
        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        if ($json)
        {
            self::sendJsonResponse(true, $message, [
                'state' => self::getLiveInteractionState($imageId, $userId),
            ]);
            return;
        }

        self::redirectToGalleryImageView($hash);
    }

    /**
     * Respond to one duplicate vote/favorite interaction without re-inserting it.
     *
     * @param string $hash Image hash identifier.
     * @param int $imageId Database image id.
     * @param int $userId Current user id.
     * @param bool $json Whether JSON output is expected.
     * @param string $message Duplicate-state message.
     * @return void
     */
    private static function handleDuplicateInteractiveImageAction(string $hash, int $imageId, int $userId, bool $json, string $message): void
    {
        if ($json)
        {
            self::sendJsonResponse(false, $message, [
                'state' => self::getLiveInteractionState($imageId, $userId),
            ], 409);
            return;
        }

        self::redirectToGalleryImageView($hash);
    }

    /**
     * Return the current live gallery state for one image.
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function live(string $hash): void
    {
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::sendJsonResponse(false, 'Invalid image request.', [], 404);
            return;
        }

        $imageId = ImageModel::findApprovedImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::sendJsonResponse(false, 'Image not found.', [], 404);
            return;
        }

        $userId = self::getCurrentUserId();
        $currentTick = ControlServer::imageLiveTick(self::getConfig(), $hash);
        $sinceTick = max(0, TypeHelper::toInt($_GET['since'] ?? null) ?? 0);

        $state = self::getLiveInteractionState($imageId, $userId);

        if ($sinceTick > 0 && $currentTick <= $sinceTick)
        {
            self::sendJsonResponse(true, 'No live gallery changes detected.', [
                'changed' => false,
                'tick' => $currentTick,
                'state' => $state,
            ]);
            return;
        }

        self::sendJsonResponse(true, 'Live gallery state loaded.', [
            'changed' => true,
            'tick' => $currentTick,
            'state' => $state,
        ]);
    }

    /**
     * Edit a single image view with metadata.
     *
     * Updates the image description for a specific image hash.
     * Access is restricted to authenticated users (RoleHelper::requireLogin()).
     *
     * Security considerations:
     * - POST-only endpoint (rejects other methods)
     * - CSRF protection for state-changing updates
     * - Validates the image exists before updating
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function edit(string $hash): void
    {
        $template = self::initTemplate();

        RoleHelper::requireLogin();
        $currentUserId = self::requireAuthenticatedUserId();

        if (!self::validateGalleryPostActionRequest($template, 'edit'))
        {
            return;
        }

        // Ensure hash is provided
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return;
        }

        $img = ImageModel::findEditableImageByHash($hash);
        if (!$img)
        {
            self::renderImageNotFound($template);
            return;
        }

        $ownerUserId = TypeHelper::toInt($img['user_id'] ?? 0) ?? 0;
        $isOwner = $ownerUserId === $currentUserId;
        $canEditOwn = GroupPermissionHelper::hasPermission('edit_own_image');
        $canEditAny = GroupPermissionHelper::hasPermission('edit_any_image');

        if (!(($isOwner && $canEditOwn) || $canEditAny))
        {
            self::renderErrorPage(403, 'Access Denied', 'Your account group cannot edit this image.', $template);
            return;
        }

        // Description should be tolerant (user input)
        $image = TypeHelper::requireString($img['image_hash'] ?? null, allowEmpty: false);
        $description = TypeHelper::toString($_POST['description'] ?? null, allowEmpty: true) ?? '';

        ImageModel::updateDescriptionByHash($image, $description);
        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        self::redirectToGalleryImageView($hash);
    }

    /**
     * Handle marking an image as favorite by logged-in user.
     *
     * Creates a favorite association for the current user and the target image.
     * Duplicate favorites are rejected to keep the relation unique.
     *
     * Security considerations:
     * - Requires authentication
     * - CSRF protection for POST action
     * - Validates the image exists before inserting
     *
     * @param string $hash Unique hash of the image.
     */
    public static function favorite(string $hash): void
    {
        $template = self::initTemplate();
        $expectsJson = self::wantsJsonResponse();
        $userId = self::requireGalleryActionUser('favorite_images');

        if (!self::validateGalleryPostActionRequest($template, 'favorite', $expectsJson))
        {
            return;
        }

        $hash = self::normalizeGalleryActionHash($hash, $template);
        if ($hash === null)
        {
            return;
        }

        // Find the image by hash
        $imageId = ImageModel::findImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::renderMissingInteractionImagePage($template, 'Favorite Failed', 'The image you attempted to favorite does not exist.');
            return;
        }

        // Check if user already favorited
        if (ImageModel::hasUserFavorite($userId, $imageId))
        {
            self::handleDuplicateInteractiveImageAction($hash, $imageId, $userId, $expectsJson, 'You have already marked this image as a favorite.');
            return;
        }

        // Insert favorite
        ImageModel::insertFavorite($userId, $imageId);
        self::completeInteractiveImageAction($hash, $imageId, $userId, $expectsJson, 'You have successfully marked this image as a favorite.');
    }

    /**
     * Handle up-vote for an image by logged-in user.
     *
     * Creates a vote association for the current user and the target image.
     * Duplicate votes are rejected to enforce one vote per user per image.
     *
     * Security considerations:
     * - Requires authentication
     * - CSRF protection for POST action
     * - Validates the image exists before inserting
     *
     * @param string $hash Unique hash of the image.
     */
    public static function upvote(string $hash): void
    {
        $template = self::initTemplate();
        $expectsJson = self::wantsJsonResponse();
        $userId = self::requireGalleryActionUser('vote_images');

        if (!self::validateGalleryPostActionRequest($template, 'upvote', $expectsJson))
        {
            return;
        }

        $hash = self::normalizeGalleryActionHash($hash, $template);
        if ($hash === null)
        {
            return;
        }

        // Find the image by hash
        $imageId = ImageModel::findImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::renderMissingInteractionImagePage($template, 'Upvote Failed', 'The image you attempted to upvote does not exist.');
            return;
        }

        // Check if user already voted
        if (ImageModel::hasUserVote($userId, $imageId))
        {
            self::handleDuplicateInteractiveImageAction($hash, $imageId, $userId, $expectsJson, 'You have already upvoted this image.');
            return;
        }

        // Insert vote
        ImageModel::insertVote($userId, $imageId);
        self::completeInteractiveImageAction($hash, $imageId, $userId, $expectsJson, 'You have successfully upvoted this image.');
    }

    /**
     * Enforce the public gallery image-report permission for signed-in users.
     *
     * Guests are still allowed to report images, so permission checks only run
     * when a valid account is attached to the current request.
     *
     * @param int $userId Current authenticated user id, or 0 for guests.
     * @return void
     */
    private static function enforceGalleryReportPermission(int $userId): void
    {
        if ($userId < 1)
        {
            return;
        }

        RoleHelper::requireLogin();
        GroupPermissionHelper::requirePermission('report_images');
    }

    /**
     * Read and sanitize the public gallery image-report submission payload.
     *
     * @return array{category: string, subject: string, message: string}
     */
    private static function readGalleryReportPayload(): array
    {
        return [
            'category' => ImageReportHelper::normalizeCategory(Security::sanitizeString($_POST['report_category'] ?? '')),
            'subject' => Security::sanitizeString($_POST['report_subject'] ?? ''),
            'message' => Security::sanitizeString($_POST['report_message'] ?? ''),
        ];
    }

    /**
     * Build one image-report creation payload from the current request state.
     *
     * @param int $imageId Target image id.
     * @param int $userId Current authenticated user id, or 0.
     * @param array{category: string, subject: string, message: string} $payload
     * @return array<string, mixed>
     */
    private static function buildGalleryImageReportCreatePayload(int $imageId, int $userId, array $payload): array
    {
        $sessionId = TypeHelper::toString(session_id(), allowEmpty: true) ?? '';
        $userAgent = TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? '', allowEmpty: true) ?? '';

        return [
            ':image_id' => $imageId,
            ':reporter_user_id' => $userId > 0 ? $userId : null,
            ':report_category' => $payload['category'],
            ':report_subject' => $payload['subject'] !== '' ? $payload['subject'] : ImageReportHelper::categoryLabel($payload['category']),
            ':report_message' => $payload['message'],
            ':session_id' => $sessionId !== '' ? $sessionId : null,
            ':ip' => inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            ':ua' => $userAgent !== '' ? $userAgent : null,
        ];
    }

    /**
     * Submit one image report from the public gallery view.
     *
     * Reports may be submitted by signed-in users or guests. Duplicate open
     * reports from the same account/session for the same image are prevented so
     * the control panel queue stays readable.
     *
     * @param string $hash Unique hash of the image.
     * @return void
     */
    public static function report(string $hash): void
    {
        $template = self::initTemplate();

        if (!self::validateGalleryPostActionRequest($template, 'report'))
        {
            return;
        }

        $hash = self::normalizeGalleryActionHash($hash, $template);
        if ($hash === null)
        {
            return;
        }

        $userId = self::getCurrentUserId();
        self::enforceGalleryReportPermission($userId);

        $payload = self::readGalleryReportPayload();
        if ($payload['message'] === '')
        {
            self::redirectToGalleryImageView($hash, ['report' => 'invalid']);
        }

        $imageId = ImageModel::findApprovedImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::renderImageNotFound($template);
            return;
        }

        $sessionId = TypeHelper::toString(session_id(), allowEmpty: true) ?? '';
        if (ImageReportModel::hasOpenDuplicate($imageId, $userId > 0 ? $userId : null, $sessionId))
        {
            self::redirectToGalleryImageView($hash, ['report' => 'exists']);
        }

        ImageReportModel::create(self::buildGalleryImageReportCreatePayload($imageId, $userId, $payload));
        self::redirectToGalleryImageView($hash, ['report' => 'submitted']);
    }

    /**
     * Handle posting a comment on an image by logged-in user.
     *
     * Inserts a new comment row tied to the image and current user, then redirects
     * back to the image view. Empty comments are ignored.
     *
     * Security considerations:
     * - Requires authentication
     * - POST-only endpoint (rejects other methods)
     * - CSRF protection for POST action
     * - Sanitizes comment content before storing
     *
     * @param string $hash Unique hash of the image.
     */
    public static function comment(string $hash): void
    {
        $template = self::initTemplate();
        $expectsJson = self::wantsJsonResponse();
        $userId = self::requireGalleryActionUser('comment_images');

        if (!self::validateGalleryPostActionRequest($template, 'comment', $expectsJson))
        {
            return;
        }

        $hash = self::normalizeGalleryActionHash($hash, $template);
        if ($hash === null)
        {
            return;
        }

        $commentBody = Security::sanitizeString($_POST['comment_body'] ?? '');
        if ($commentBody === '')
        {
            self::redirectToGalleryImageView($hash);
        }

        // Find the approved image by hash
        $imageId = ImageModel::findApprovedImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::renderImageNotFound($template);
            return;
        }

        // Insert comment
        ImageModel::insertComment($imageId, $userId, $commentBody);
        self::completeInteractiveImageAction($hash, $imageId, $userId, $expectsJson, 'Your comment has been posted.');
    }

    /**
     * Emit a minimal error response for lightweight gallery image requests.
     *
     * The fast gallery image path intentionally avoids template rendering and
     * other heavy application services. Error responses remain small/plain so
     * failed image requests do not consume unnecessary work.
     *
     * @param int $status HTTP status code.
     * @return void
     */
    private static function sendGalleryImageError(int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        echo ($status === 403) ? 'Forbidden' : 'Not Found';
        exit;
    }

    /**
     * Serve one validated gallery page image directly from the original file.
     *
     * This path avoids the heavier image cache/session lifecycle used by the
     * single-image original endpoint so gallery grid rendering stays fast.
     *
     * @param string $hash Unique hash identifier for the image.
     * @param string $originalPath Stored original file path.
     * @param string $mimeType Trusted stored mime type.
     * @return void
     */
    private static function serveFastGalleryPageImageFile(string $hash, string $originalPath, string $mimeType): void
    {
        $fullPath = self::resolveStoredImagePath($originalPath);
        if ($fullPath === null)
        {
            self::sendGalleryImageError(404);
        }

        $lastModified = @filemtime($fullPath) ?: time();
        $fileSize = @filesize($fullPath) ?: 0;
        $etag = '"' . sha1($hash . '|' . $fileSize . '|' . $lastModified) . '"';
        $ifNoneMatch = TypeHelper::toString($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', allowEmpty: true) ?? '';
        if ($ifNoneMatch !== '' && hash_equals(trim($ifNoneMatch), $etag))
        {
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            self::applyGalleryPageImageCacheHeaders();
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . $fileSize);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        self::applyGalleryPageImageCacheHeaders();
        readfile($fullPath);
        exit;
    }

    /**
     * Serve one gallery page image previously authorized by index().
     *
     * This endpoint is intentionally lightweight: it validates a short-lived
     * page token against the current request cookies, then streams the original
     * image file directly without starting the heavier session/database flow.
     *
     * @param string $token Gallery page token.
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function servePageImage(string $token, string $hash): void
    {
        $config = self::getConfig();
        $hash = TypeHelper::toString($hash, allowEmpty: false) ?? '';
        $token = TypeHelper::toString($token, allowEmpty: false) ?? '';

        if ($token === '' || $hash === '')
        {
            self::sendGalleryImageError(404);
        }

        $sessionCookieName = TypeHelper::toString($config['session']['name'] ?? 'cms_session', allowEmpty: false) ?? 'cms_session';
        $sessionId = TypeHelper::toString($_COOKIE[$sessionCookieName] ?? '', allowEmpty: true) ?? '';
        $deviceId = GalleryPageTokenStore::getCurrentDeviceId($config);

        $image = GalleryPageTokenStore::getAuthorizedImage($token, $hash, $sessionId, $deviceId);
        if (!$image)
        {
            self::sendGalleryImageError(404);
        }

        $originalPath = TypeHelper::toString($image['original_path'] ?? null, allowEmpty: false) ?? '';
        $mimeType = TypeHelper::toString($image['mime_type'] ?? null, allowEmpty: false) ?? 'application/octet-stream';
        self::serveFastGalleryPageImageFile($hash, $originalPath, $mimeType);
    }

    /**
     * Fast-path entry point for gallery page images.
     *
     * This method is called before the main application bootstrap finishes so
     * gallery tile requests avoid database/session initialization entirely.
     *
     * @param string $token Gallery page token.
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function serveFastPageImageRequest(string $token, string $hash): void
    {
        self::servePageImage($token, $hash);
    }

    /**
     * Serve an image file directly from the uploads directory.
     *
     * This endpoint is used by the gallery to deliver the actual image bytes.
     * It enforces age restrictions (when applicable), uses a disk cache for speed,
     * and protects against path traversal by resolving and validating real paths.
     *
     * Caching behavior:
     * - Non-sensitive images may use global cache entries
     * - Age-sensitive images use per-user cache keys to avoid cross-user leakage
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function serveImage(string $hash): void
    {
        $template = self::initTemplate();
        $userId = self::getCurrentUserId();
        $currentUser = null;

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        // Rate limit and bot heuristics (guests + members)
        RequestGuard::enforceImageRequest($hash);

        // Fetch current user data for age-sensitive checks
        if ($userId > 0)
        {
            RulesHelper::enforceBlockingRedirectIfNeeded($userId);
            $currentUser = UserModel::findAgeVerificationById($userId);
        }

        // Fetch image metadata from the database
        $image = ImageModel::findApprovedServableImageByHash($hash);
        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        $config = self::getConfig();
        $contentRating = AgeGateHelper::normalizeContentRating(
            TypeHelper::toString($image['content_rating'] ?? '', allowEmpty: true) ?? '',
            TypeHelper::toInt($image['age_sensitive'] ?? 0) ?? 0
        );

        // Determine if per-user cache should be applied
        $usePerUserCache = AgeGateHelper::shouldUsePerUserCache($contentRating, $config);

        // Age-restricted access check
        if (!AgeGateHelper::canAccessContentRating($currentUser, $contentRating, $config))
        {
            self::renderLockedContentPage($template, $currentUser, $contentRating, $config);
            return;
        }

        $originalPath = TypeHelper::toString($image['original_path'] ?? null, allowEmpty: false) ?? '';
        $mimeType = TypeHelper::toString($image['mime_type'] ?? null, allowEmpty: false) ?? 'application/octet-stream';
        if (!self::serveStoredImageFile($hash, $originalPath, $mimeType, $usePerUserCache, $userId))
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
        }
    }
}
