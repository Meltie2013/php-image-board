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
        $imagesPerPage = $config['gallery']['images_displayed'];

        RedirectHelper::rememberGalleryPage(RedirectHelper::getCurrentRequestUri());

        // Route param parsing should be tolerant (never throw)
        $page = TypeHelper::toInt($page) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }

        // Config values should be tolerant as well (never throw in production)
        $limit = TypeHelper::toInt($imagesPerPage) ?? 1;
        if ($limit < 1)
        {
            $limit = 1;
        }

        // Session values are frequently strings; parse safely
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $currentUser = null;

        // Fetch logged-in user's DOB and verification status if available
        if ($userId > 0)
        {
            RoleHelper::requireLogin();
            if (!GroupPermissionHelper::hasPermission('view_gallery'))
            {
                $template = self::initTemplate();
                http_response_code(403);
                $template->assign('title', 'Access Denied');
                $template->assign('message', 'Your account group cannot view the gallery.');
                $template->render('errors/error_page.html');
                return;
            }

            $currentUser = UserModel::findAgeVerificationById($userId);
        }

        $viewerContentAccessLevel = AgeGateHelper::getViewerContentAccessLevel($currentUser, $config);

        if (RequestGuard::isGalleryPageRateLimited())
        {
            $template = self::initTemplate();
            http_response_code(429);
            $template->assign('title', 'Too Many Requests');
            $template->assign('message', 'Too many gallery page requests. Please wait and try again.');
            $template->render('errors/error_page.html');
            return;
        }

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

        $loopImages = [];
        foreach ($pageImages as $img)
        {
            $imageHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
            if ($imageHash === '')
            {
                // Skip malformed rows rather than throwing
                continue;
            }

            $views = TypeHelper::toInt($img['views'] ?? null) ?? 0;
            $loopImages[] = [
                self::buildTokenizedImageUrl($imageHash, $galleryPageToken),
                self::buildGalleryViewUrl($imageHash, $galleryBackUrl),
                $views,
            ];
        }

        // Pagination navigation links
        $pagination_prev = $page > 1 ? ($page - 1 === 1 ? '/gallery' : '/gallery/page/' . ($page - 1)) : null;
        $pagination_next = $page < $totalPages ? '/gallery/page/' . ($page + 1) : null;

        $range = TypeHelper::toInt($config['gallery']['pagination_range'] ?? null) ?? 2;
        if ($range < 0)
        {
            $range = 0;
        }

        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);

        $pagination_pages = [];
        if ($start > 1)
        {
            $pagination_pages[] = ['/gallery/page/1', 1, false];
            if ($start > 2)
            {
                $pagination_pages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $pagination_pages[] = [
                $i === 1 ? '/gallery/page/1' : '/gallery/page/' . $i,
                $i,
                $i === $page
            ];
        }

        if ($end < $totalPages)
        {
            if ($end < $totalPages - 1)
            {
                $pagination_pages[] = [null, '...', false];
            }

            $pagination_pages[] = ['/gallery/page/' . $totalPages, $totalPages, false];
        }

        $template = self::initTemplate();
        $template->assign('images', $loopImages);
        $template->assign('pagination_prev', $pagination_prev);
        $template->assign('pagination_next', $pagination_next);
        $template->assign('pagination_pages', $pagination_pages);

        $template->render('gallery/gallery_index.html');
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

        // Session values are frequently strings; parse safely
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;

        // Hash is a route param; never throw here
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        $currentUser = null;

        $requestedGalleryBackUrl = RedirectHelper::sanitizeInternalPath($_GET['back_to'] ?? null);
        if ($requestedGalleryBackUrl !== null)
        {
            RedirectHelper::rememberGalleryPage($requestedGalleryBackUrl);

            $cleanViewUrl = RedirectHelper::removeQueryParameter(RedirectHelper::getCurrentRequestUri(), 'back_to', true);
            if ($cleanViewUrl !== null && $cleanViewUrl !== RedirectHelper::getCurrentRequestUri())
            {
                header('Location: ' . $cleanViewUrl);
                exit;
            }
        }

        $galleryBackUrl = self::resolveGalleryBackUrl();

        $commentsPerPage = TypeHelper::toInt($config['gallery']['comments_per_page']) ?? 5;
        if ($commentsPerPage < 1)
        {
            $commentsPerPage = 10;
        }

        $commentsPage = TypeHelper::toInt($_GET['cpage'] ?? 1);
        if ($commentsPage < 1)
        {
            $commentsPage = 1;
        }

        if ($userId > 0)
        {
            RoleHelper::requireLogin();
            if (!GroupPermissionHelper::hasPermission('view_gallery'))
            {
                http_response_code(403);
                $template->assign('title', 'Access Denied');
                $template->assign('message', 'Your account group cannot view this gallery content.');
                $template->render('errors/error_page.html');
                return;
            }

            $currentUser = UserModel::findAgeVerificationById($userId);
        }

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

        $imageId = TypeHelper::toInt($img['id']) ?? null;
        if ($imageId < 1)
        {
            self::renderImageNotFound($template);
            return;
        }

        // Track views per session (guest and logged-in users) to prevent inflating view count
        $viewedImages = SessionManager::get('viewed_images', []);
        if (!is_array($viewedImages))
        {
            $viewedImages = [];
        }

        $imgHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
        if ($imgHash !== '' && !in_array($imgHash, $viewedImages, true))
        {
            ImageModel::incrementViews($imageId);
            ControlServer::bumpImageLiveTick($config, $imgHash);

            $viewedImages[] = $imgHash;
            SessionManager::set('viewed_images', $viewedImages);

            $img['views'] = (TypeHelper::toInt($img['views']) ?? 0) + 1;
        }

        $ownerUserId = TypeHelper::toInt($img['user_id'] ?? null);
        $username = self::getUsernameById($ownerUserId);
        $uploaderHasBirthdayBadge = AgeGateHelper::shouldShowBirthdayBadge($img['date_of_birth'] ?? null, $config) ? 1 : 0;

        $hasVoted = false;
        if ($userId > 0)
        {
            $hasVoted = ImageModel::hasUserVote($userId, $imageId);
        }

        $hasFavorited = false;
        if ($userId > 0)
        {
            $hasFavorited = ImageModel::hasUserFavorite($userId, $imageId);
        }

        // Fetch comments count (pagination)
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

        // Determine interactive action permissions for the UI.
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

        $appUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($appUserId > 0)
        {
            $imgUserId = TypeHelper::toInt($img['user_id']) ?? 0;
            $isOwner = ($imgUserId === $appUserId);
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

        $permissionNoticeMessage = '';
        if ($appUserId > 0)
        {
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

            if (!empty($disabledActions))
            {
                $permissionNoticeMessage = 'Some actions are disabled for your current account group: ' . implode(', ', $disabledActions) . '.';
            }
        }

        // Image has tags (todo: not implemented)
        $image_tags = false;

        $template->assign('img_comment_count', $commentCount);
        $template->assign('comment_rows', $commentRows);
        $template->assign('comments_page', $commentsPage);
        $template->assign('comments_total_pages', $commentsTotalPages);
        $template->assign('comments_has_prev', $commentsPage > 1);
        $template->assign('comments_has_next', $commentsPage < $commentsTotalPages);
        $template->assign('comments_prev_page', $commentsPage - 1);
        $template->assign('comments_next_page', $commentsPage + 1);

        $template->assign('img_hash', $img['image_hash']);
        $template->assign('img_username', ucfirst($username));
        $template->assign('img_description', $img['description']);
        $template->assign('img_mime_type', $img['mime_type']);
        $template->assign('img_width', $img['width']);
        $template->assign('img_height', $img['height']);
        $template->assign('img_size', StorageHelper::formatFileSize($img['size_bytes']));
        $template->assign('img_md5', $img['md5']);
        $template->assign('img_sha1', $img['sha1']);
        $template->assign('img_sha256', $img['sha256']);
        $template->assign('img_sha512', $img['sha512']);
        $template->assign('img_reject_reason', $img['reject_reason']);
        $template->assign('img_approved_status', ucfirst($img['status']));
        $template->assign('img_created_at', DateHelper::format($img['created_at']));
        $template->assign('img_age_sensitive', $img['age_sensitive']);
        $template->assign('img_content_rating', $contentRating);
        $template->assign('img_content_rating_label', AgeGateHelper::getContentRatingLabel($contentRating));
        $template->assign('img_content_rating_pill_class', AgeGateHelper::getContentRatingPillClass($contentRating));
        $template->assign('img_uploader_has_birthday_badge', $uploaderHasBirthdayBadge);
        $template->assign('img_votes', NumericalHelper::formatCount($img['votes']));
        $template->assign('img_has_voted', $hasVoted);
        $template->assign('img_favorites', NumericalHelper::formatCount($img['favorites']));
        $template->assign('img_views', NumericalHelper::formatCount($img['views']));
        $template->assign('img_has_favorited', $hasFavorited);
        $template->assign('can_edit_image', $canEditImage);
        $template->assign('can_comment_image', $canCommentImage ? 1 : 0);
        $template->assign('can_favorite_image', $canFavoriteImage ? 1 : 0);
        $template->assign('can_vote_image', $canVoteImage ? 1 : 0);
        $template->assign('can_report_image', $canReportImage ? 1 : 0);
        $template->assign('edit_permission_message', $editPermissionMessage);
        $template->assign('comment_permission_message', $commentPermissionMessage);
        $template->assign('favorite_permission_message', $favoritePermissionMessage);
        $template->assign('vote_permission_message', $votePermissionMessage);
        $template->assign('report_permission_message', $reportPermissionMessage);
        $template->assign('img_has_tag', $image_tags);
        $template->assign('gallery_back_url', $galleryBackUrl);
        $template->assign('gallery_back_url_encoded', rawurlencode($galleryBackUrl));

        $openReportCount = TypeHelper::toInt($img['open_reports'] ?? 0) ?? 0;
        $reportNotice = self::resolveReportNoticeFromQuery();
        $template->assign('img_has_open_reports', $openReportCount > 0 ? 1 : 0);
        $template->assign('img_open_report_count', $openReportCount);
        $template->assign('report_notice_class', $reportNotice['alert_class']);
        $template->assign('report_notice_message', $reportNotice['alert_message']);
        $template->assign('permission_notice_message', $permissionNoticeMessage);
        $template->assign('report_modal_auto_open', (int)$reportNotice['auto_open']);

        // todo: clean this code up, so it looks cleaner
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
        $template->assign('img_image_url', self::buildTokenizedImageUrl($img['image_hash'], $viewImageToken));
        $template->assign('img_original_url', '/gallery/original/' . $img['image_hash']);
        $template->assign('img_live_poll_url', '/gallery/' . $img['image_hash'] . '/live');
        $template->assign('img_live_websocket_url', $webSocketScheme . '://' . $webSocketHost . ':' . $webSocketPort . $publicPath);
        $template->assign('img_live_tick', ControlServer::imageLiveTick($config, $img['image_hash']));

        $template->render('gallery/gallery_view.html');
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

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
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

        // Require login
        RoleHelper::requireLogin();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        if (RequestGuard::isInteractiveActionRateLimited('edit'))
        {
            self::renderInteractiveActionRateLimited($template);
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
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

        $currentUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $ownerUserId = TypeHelper::toInt($img['user_id'] ?? 0) ?? 0;
        $isOwner = $currentUserId > 0 && $ownerUserId === $currentUserId;
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
        $description = TypeHelper::toString($description); // todo: this line might not be needed

        ImageModel::updateDescriptionByHash($image, $description);

        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null));
        exit;
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

        // Require login
        RoleHelper::requireLogin();
        GroupPermissionHelper::requirePermission('favorite_images');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        if (RequestGuard::isInteractiveActionRateLimited('favorite'))
        {
            self::renderInteractiveActionRateLimited($template, self::wantsJsonResponse());
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            self::renderInvalidRequest($template);
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        // Find the image by hash
        $imageId = ImageModel::findImageIdByHash($hash);

        if ($imageId < 1)
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "The image you attempted to favorite does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        // Check if user already favorited
        if (ImageModel::hasUserFavorite($userId, $imageId))
        {
            if (self::wantsJsonResponse())
            {
                self::sendJsonResponse(false, 'You have already marked this image as a favorite.', [
                    'state' => self::getLiveInteractionState($imageId, $userId),
                ], 409);
                return;
            }
        }

        // Insert favorite
        ImageModel::insertFavorite($userId, $imageId);

        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        if (self::wantsJsonResponse())
        {
            self::sendJsonResponse(true, 'You have successfully marked this image as a favorite.', [
                'state' => self::getLiveInteractionState($imageId, $userId),
            ]);
            return;
        }

        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null));
        exit;
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

        // Require login
        RoleHelper::requireLogin();
        GroupPermissionHelper::requirePermission('vote_images');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        if (RequestGuard::isInteractiveActionRateLimited('upvote'))
        {
            self::renderInteractiveActionRateLimited($template, self::wantsJsonResponse());
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            self::renderInvalidRequest($template);
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        // Find the image by hash
        $imageId = ImageModel::findImageIdByHash($hash);

        if ($imageId < 1)
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "The image you attempted to upvote does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        // Check if user already voted
        if (ImageModel::hasUserVote($userId, $imageId))
        {
            if (self::wantsJsonResponse())
            {
                self::sendJsonResponse(false, 'You have already upvoted this image.', [
                    'state' => self::getLiveInteractionState($imageId, $userId),
                ], 409);
                return;
            }
        }

        // Insert vote
        ImageModel::insertVote($userId, $imageId);

        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        if (self::wantsJsonResponse())
        {
            self::sendJsonResponse(true, 'You have successfully upvoted this image.', [
                'state' => self::getLiveInteractionState($imageId, $userId),
            ]);
            return;
        }

        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null));
        exit;
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        if (RequestGuard::isInteractiveActionRateLimited('report'))
        {
            self::renderInteractiveActionRateLimited($template);
            return;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId > 0)
        {
            RoleHelper::requireLogin();
            GroupPermissionHelper::requirePermission('report_images');
        }

        $category = ImageReportHelper::normalizeCategory(Security::sanitizeString($_POST['report_category'] ?? ''));
        $subject = Security::sanitizeString($_POST['report_subject'] ?? '');
        $message = Security::sanitizeString($_POST['report_message'] ?? '');

        if ($message === '')
        {
            header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null, ['report' => 'invalid']));
            exit;
        }

        $imageId = ImageModel::findApprovedImageIdByHash($hash);
        if ($imageId < 1)
        {
            self::renderImageNotFound($template);
            return;
        }

        $sessionId = TypeHelper::toString(session_id(), allowEmpty: true) ?? '';
        $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
        $userAgent = TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? '', allowEmpty: true) ?? '';

        if (ImageReportModel::hasOpenDuplicate($imageId, $userId > 0 ? $userId : null, $sessionId))
        {
            header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null, ['report' => 'exists']));
            exit;
        }

        if ($subject === '')
        {
            $subject = ImageReportHelper::categoryLabel($category);
        }

        ImageReportModel::create([
            ':image_id' => $imageId,
            ':reporter_user_id' => $userId > 0 ? $userId : null,
            ':report_category' => $category,
            ':report_subject' => $subject,
            ':report_message' => $message,
            ':session_id' => $sessionId !== '' ? $sessionId : null,
            ':ip' => $ip,
            ':ua' => $userAgent !== '' ? $userAgent : null,
        ]);

        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null, ['report' => 'submitted']));
        exit;
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

        // Require login
        RoleHelper::requireLogin();
        GroupPermissionHelper::requirePermission('comment_images');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        if (RequestGuard::isInteractiveActionRateLimited('comment'))
        {
            self::renderInteractiveActionRateLimited($template, self::wantsJsonResponse());
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            self::renderInvalidRequest($template);
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderImageNotFound($template);
            return;
        }

        $commentBody = Security::sanitizeString($_POST['comment_body'] ?? '');
        if ($commentBody === '')
        {
            header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null));
            exit;
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

        ControlServer::bumpImageLiveTick(self::getConfig(), $hash);

        header('Location: ' . self::buildGalleryViewUrl($hash, $_POST['back_to'] ?? null));
        exit;
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
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
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
