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
class GalleryController
{
    /**
     * Cached config for controller usage.
     *
     * Stored statically so configuration is loaded once per request and reused
     * across controller actions without repeated disk reads.
     *
     * @var array
     */
    private static array $config;

    /**
     * Load and cache config once per request.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (empty(self::$config))
        {
            self::$config = SettingsManager::isInitialized() ? SettingsManager::getConfig() : (require __DIR__ . '/../config/config.php');
        }

        return self::$config;
    }

    /**
     * Initialize template engine with optional cache clearing.
     *
     * Also injects a CSRF token into the template scope so all rendered forms
     * can submit state-changing actions safely.
     *
     * @return TemplateEngine
     */
    private static function initTemplate(): TemplateEngine
    {
        $config = self::getConfig();
        $template = new TemplateEngine(__DIR__ . '/../templates', __DIR__ . '/../cache/templates', $config);
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        $template->assign('csrf_token', Security::generateCsrfToken());
        return $template;
    }

    /**
     * Retrieve a username by user ID.
     *
     * Used for display purposes in the gallery UI. Returns an empty string when
     * the user ID is missing or no matching user is found.
     *
     * @param int|null $userId ID of the user, or null if not available.
     * @return string Username if found, otherwise empty string.
     */
    private static function getUsernameById(?int $userId): string
    {
        if ($userId === null)
        {
            return '';
        }

        // Query to fetch username by user ID
        $result = Database::fetch("SELECT username FROM app_users WHERE id = :id LIMIT 1", [':id' => $userId]);
        return TypeHelper::toString($result['username']) ?? '';
    }

    /**
     * Check if a user has access to age-sensitive content.
     *
     * Access requires:
     * - A valid date_of_birth
     * - A non-null age_verified_at timestamp (indicating verification occurred)
     * - DOB being older than the minimum allowed birth date based on site policy
     *
     * @param array|null $user Array containing user's date_of_birth and age_verified_at
     * @param string $minBirthDate Calculated minimum birth date
     * @return bool True if allowed, false otherwise
     */
    private static function checkAgeSensitiveAccess(?array $user, string $minBirthDate): bool
    {
        return $user && !empty($user['date_of_birth']) && !empty($user['age_verified_at']) && $user['date_of_birth'] <= $minBirthDate;
    }

    /**
     * Display the gallery index with paginated images.
     *
     * Loads approved images, filters out age-sensitive items when the current user
     * does not meet the age requirement, then builds pagination links and a compact
     * image list for the template.
     *
     * @param int|null $page Current page number, defaults to 1.
     * @return void
     */
    public static function index($page = 1): void
    {
        $config = self::getConfig();
        $imagesPerPage = $config['gallery']['images_displayed'];

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

        $offset = ($page - 1) * $limit;

        // Session values are frequently strings; parse safely
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $currentUser = null;

        // Fetch logged-in user's DOB and verification status if available
        if ($userId > 0)
        {
            $currentUser = Database::fetch(
                "SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        $requiredYears = TypeHelper::toInt($config['profile']['years'] ?? 0) ?? 0;
        if ($requiredYears < 0)
        {
            $requiredYears = 0;
        }

        $minBirthDate = (new DateTime())->modify("-{$requiredYears} years")->format('Y-m-d');

        // Fetch all approved images
        $sql = "SELECT i.image_hash, i.original_path, i.age_sensitive, i.created_at, i.views, u.date_of_birth, u.age_verified_at FROM app_images i
                LEFT JOIN app_users u ON i.user_id = u.id WHERE i.status = 'approved' ORDER BY i.created_at DESC";

        $stmt = Database::getPDO()->prepare($sql);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter images based on age-sensitive access
        $filteredImages = array_filter($images, function ($img) use ($currentUser, $minBirthDate)
        {
            $ageSensitive = TypeHelper::toInt($img['age_sensitive'] ?? 0) ?? 0;
            return ($ageSensitive === 0) || self::checkAgeSensitiveAccess($currentUser, $minBirthDate);
        });

        $totalImages = count($filteredImages);
        $totalPages = max(1, (int)ceil($totalImages / $limit));

        // Slice array for pagination
        $pageImages = array_slice($filteredImages, $offset, $limit);

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
                "/" . $img['original_path'],
                "/gallery/" . $imageHash,
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
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $currentUser = null;

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
            $currentUser = Database::fetch("SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        $img = Database::fetch("
            SELECT
                i.id,
                i.user_id,
                i.image_hash,
                i.status,
                i.description,
                i.age_sensitive,
                i.created_at,
                i.mime_type,
                i.width,
                i.height,
                i.size_bytes,
                i.md5,
                i.sha1,
                i.reject_reason,
                COALESCE(v.votes, 0) AS votes,
                COALESCE(f.favorites, 0) AS favorites,
                i.views,
                u.date_of_birth,
                u.age_verified_at
            FROM app_images i
            LEFT JOIN app_users u ON i.user_id = u.id
            LEFT JOIN (
                SELECT image_id, COUNT(*) AS favorites
                FROM app_image_favorites
                GROUP BY image_id
            ) f ON i.id = f.image_id
            LEFT JOIN (
                SELECT image_id, COUNT(*) AS votes
                FROM app_image_votes
                GROUP BY image_id
            ) v ON i.id = v.image_id
            WHERE i.image_hash = :hash
            AND i.status = 'approved'
            LIMIT 1
        ", [':hash' => $hash]);

        if (!$img)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $requiredYears = TypeHelper::toInt($config['profile']['years']) ?? 0;
        if ($requiredYears < 0)
        {
            $requiredYears = 0;
        }

        $minBirthDate = (new DateTime())->modify("-{$requiredYears} years")->format('Y-m-d');

        $ageSensitive = TypeHelper::toInt($img['age_sensitive']) ?? 0;
        if ($ageSensitive === 1 && !self::checkAgeSensitiveAccess($currentUser, $minBirthDate))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'The server understood your request, but you are not authorized to view this image.');
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = TypeHelper::toInt($img['id']) ?? null;
        if ($imageId < 1)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
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
            Database::execute("UPDATE app_images SET views = views + 1 WHERE id = :id LIMIT 1", [':id' => $imageId]);

            $viewedImages[] = $imgHash;
            SessionManager::set('viewed_images', $viewedImages);

            $img['views'] = (TypeHelper::toInt($img['views']) ?? 0) + 1;
        }

        if ($ageSensitive === 1)
        {
            $alertColor   = 'alert-warning';
            $alertTag     = '<b>Heads Up!</b>';
            $alertMessage = 'This image is marked <b>sensitive</b> and may not be suitable for users under <b>' . ($config['profile']['years'] ?? 0) . '</b> years of age.';

            $template->assign('alert_color', $alertColor);
            $template->assign('alert_tag', $alertTag);
            $template->assign('alert_message', $alertMessage);
        }

        if ($img['status'] === 'rejected' && $img['reject_reason'] != null)
        {
            $alertColor   = 'alert-danger';
            $alertTag     = '<b>Rejected</b>! <b>Message</b>: ';
            $alertMessage = $img['reject_reason'];

            $template->assign('alert_color', $alertColor);
            $template->assign('alert_tag', $alertTag);
            $template->assign('alert_message', $alertMessage);
        }

        $ownerUserId = TypeHelper::toInt($img['user_id'] ?? null);
        $username = self::getUsernameById($ownerUserId);

        $hasVoted = false;
        if ($userId > 0)
        {
            $voted = Database::fetch("SELECT 1 FROM app_image_votes WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
                [':user_id' => $userId, ':image_id' => $imageId]
            );
            $hasVoted = TypeHelper::rowExists($voted);
        }

        $hasFavorited = false;
        if ($userId > 0)
        {
            $favorited = Database::fetch("SELECT 1 FROM app_image_favorites WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
                [':user_id' => $userId, ':image_id' => $imageId]
            );
            $hasFavorited = TypeHelper::rowExists($favorited);
        }

        // Fetch comments count (pagination)
        $commentCountRow = Database::fetch("SELECT COUNT(*) AS count FROM app_image_comments WHERE image_id = :image_id AND is_deleted = 0",
            [':image_id' => $imageId]
        );

        $commentCount = TypeHelper::toInt($commentCountRow['count']) ?? 0;
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

        $comments = Database::fetchAll( "SELECT c.comment_body, c.created_at, u.username FROM app_image_comments c LEFT JOIN app_users u ON c.user_id = u.id
              WHERE c.image_id = :image_id AND c.is_deleted = 0 ORDER BY c.created_at DESC LIMIT {$commentsPerPage} OFFSET {$commentsOffset}",
            [':image_id' => $imageId]
        );

        $commentRows = [];
        foreach ($comments as $row)
        {
            $commentRows[] = [
                $row['username'] ?? 'Unknown',
                DateHelper::format($row['created_at']),
                $row['comment_body']
            ];
        }

        // Determine edit permissions for the UI (owner or staff)
        $canEditImage = false;

        $appUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($appUserId > 0)
        {
            $imgUserId = TypeHelper::toInt($img['user_id']) ?? 0;
            $isOwner = ($imgUserId === $appUserId);

            $userRole = Database::fetch("SELECT role_id FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $appUserId]
            );

            $roleId = TypeHelper::toInt($userRole['role_id']) ?? 0;
            $roleName = RoleHelper::getRoleNameById($roleId);
            $isStaff = in_array($roleName, ['administrator', 'moderator'], true);

            $canEditImage = ($isOwner || $isStaff);
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
        $template->assign('img_reject_reason', $img['reject_reason']);
        $template->assign('img_approved_status', ucfirst($img['status']));
        $template->assign('img_created_at', DateHelper::format($img['created_at']));
        $template->assign('img_age_sensitive', $img['age_sensitive']);
        $template->assign('img_votes', NumericalHelper::formatCount($img['votes']));
        $template->assign('img_has_voted', $hasVoted);
        $template->assign('img_favorites', NumericalHelper::formatCount($img['favorites']));
        $template->assign('img_views', NumericalHelper::formatCount($img['views']));
        $template->assign('img_has_favorited', $hasFavorited);

        $template->assign('can_edit_image', $canEditImage);
        $template->assign('img_has_tag', $image_tags);

        $template->render('gallery/gallery_view.html');
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

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Ensure hash is provided
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', '404 Not Found');
            $template->assign('message', 'Oops! We couldn’t find that image.');
            $template->render('errors/error_page.html');
            return;
        }

        $img = Database::fetch(
            "SELECT id, user_id, image_hash FROM app_images WHERE image_hash = :hash LIMIT 1",
            [':hash' => $hash]
        );

        if (!$img)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Description should be tolerant (user input)
        $image = TypeHelper::requireString($img['image_hash'] ?? null, allowEmpty: false);
        $description = TypeHelper::toString($_POST['description'] ?? null, allowEmpty: true) ?? '';
        $description = TypeHelper::toString($description); // todo: this line might not be needed

        Database::execute("UPDATE app_images SET description = :description, updated_at = NOW() WHERE image_hash = :image_hash LIMIT 1",
            [':description' => $description, ':image_hash' => $image]
        );

        header('Location: /gallery/' . $hash);
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the image by hash
        $image = Database::fetch("SELECT id FROM app_images WHERE image_hash = :hash LIMIT 1", [':hash' => $hash]);

        if (!$image)
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "The image you attempted to favorite does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = TypeHelper::toInt($image['id'] ?? null) ?? 0;
        if ($imageId < 1)
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "The image you attempted to favorite does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        // Check if user already favorited
        $existing = Database::fetch(
            "SELECT 1 FROM app_image_favorites WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
            [':user_id' => $userId, ':image_id' => $imageId]
        );

        if (TypeHelper::rowExists($existing))
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "You have already marked this image as a favorite.");
            $template->assign('link', "/gallery/{$hash}");
            $template->render('errors/error_page.html');
            return;
        }

        // Insert favorite
        Database::execute(
            "INSERT INTO app_image_favorites (user_id, image_id) VALUES (:user_id, :image_id)",
            [':user_id' => $userId, ':image_id' => $imageId]
        );

        $template->assign('title', 'Successful Favorite!');
        $template->assign('message', "You have successfully marked this image as a favorite.");
        $template->assign('link', "/gallery/{$hash}");
        $template->render('errors/error_page.html');
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the image by hash
        $image = Database::fetch("SELECT id FROM app_images WHERE image_hash = :hash LIMIT 1", [':hash' => $hash]);

        if (!$image)
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "The image you attempted to upvote does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = TypeHelper::toInt($image['id'] ?? null) ?? 0;
        if ($imageId < 1)
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "The image you attempted to upvote does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        // Check if user already voted
        $existing = Database::fetch(
            "SELECT 1 FROM app_image_votes WHERE user_id = :user_id AND image_id = :image_id LIMIT 1",
            [':user_id' => $userId, ':image_id' => $imageId]
        );

        if (TypeHelper::rowExists($existing))
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "You have already upvoted this image.");
            $template->assign('link', "/gallery/{$hash}");
            $template->render('errors/error_page.html');
            return;
        }

        // Insert vote
        Database::execute(
            "INSERT INTO app_image_votes (user_id, image_id) VALUES (:user_id, :image_id)",
            [':user_id' => $userId, ':image_id' => $imageId]
        );

        $template->assign('title', 'Successful Upvote!');
        $template->assign('message', "You have successfully upvoted this image.");
        $template->assign('link', "/gallery/{$hash}");
        $template->render('errors/error_page.html');
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Verify CSRF token to prevent cross-site request forgery
        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $commentBody = Security::sanitizeString($_POST['comment_body'] ?? '');
        if ($commentBody === '')
        {
            header('Location: /gallery/' . $hash);
            exit;
        }

        // Find the approved image by hash
        $image = Database::fetch("SELECT id FROM app_images WHERE image_hash = :hash AND status = 'approved' LIMIT 1",
            [':hash' => $hash]
        );

        if (!$image)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = TypeHelper::toInt($image['id'] ?? null) ?? 0;
        if ($imageId < 1)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Insert comment
        Database::execute(
            "INSERT INTO app_image_comments (image_id, user_id, comment_body)
             VALUES (:image_id, :user_id, :comment_body)",
            [
                ':image_id' => $imageId,
                ':user_id' => $userId,
                ':comment_body' => $commentBody
            ]
        );

        header('Location: /gallery/' . $hash);
        exit;
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
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Rate limit and bot heuristics (guests + members)
        RequestGuard::enforceImageRequest($hash);

        // Fetch current user data for age-sensitive checks
        if ($userId > 0)
        {
            $currentUser = Database::fetch("SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        // Fetch image metadata from the database
        $sql = "SELECT
                original_path,
                mime_type,
                age_sensitive,
                i.user_id,
                u.date_of_birth,
                u.age_verified_at
            FROM app_images i
            LEFT JOIN app_users u ON i.user_id = u.id
            WHERE i.image_hash = :hash
            AND (i.status = 'approved' OR i.status = 'rejected')
            LIMIT 1";

        $image = Database::fetch($sql, [':hash' => $hash]);
        if (!$image)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $ageSensitive = TypeHelper::toInt($image['age_sensitive']) ?? 0;

        // Determine if per-user cache should be applied
        $usePerUserCache = ($ageSensitive === 1);

        // Age-restricted access check
        $config = self::getConfig();
        $requiredYears = TypeHelper::toInt($config['profile']['years']) ?? 0;
        if ($requiredYears < 0)
        {
            $requiredYears = 0;
        }

        $minBirthDate = (new DateTime())->modify("-{$requiredYears} years")->format('Y-m-d');
        if ($usePerUserCache && !self::checkAgeSensitiveAccess($currentUser, $minBirthDate))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'You are not authorized to view this image.');
            $template->render('errors/error_page.html');
            return;
        }

        // Check cache AFTER passing age restriction
        $cachedPath = ImageCacheEngine::getCachedImage($hash, $usePerUserCache ? $userId : null);
        if ($cachedPath)
        {
            // Cached image exists – serve immediately
            $mimeType = mime_content_type($cachedPath) ?: ($image['mime_type'] ?: 'application/octet-stream');
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($cachedPath));
            header('Cache-Control: public, max-age=604800'); // Browser caching (7 days)
            readfile($cachedPath);
            exit;
        }

        // Resolve original file path
        $baseDir = realpath(__DIR__ . '/../images/');
        $originalPath = TypeHelper::toString($image['original_path'] ?? null, allowEmpty: false) ?? '';
        if (!$baseDir || $originalPath === '')
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $fullPath = realpath($baseDir . '/' . ltrim(str_replace("images/", "", $originalPath), '/'));
        if (!$fullPath || strpos($fullPath, $baseDir) !== 0 || !file_exists($fullPath))
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Store image in cache and serve
        $cachedPath = ImageCacheEngine::storeImage($hash, $fullPath, $usePerUserCache ? $userId : null);

        header('Content-Type: ' . ($image['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($cachedPath));
        header('Cache-Control: public, max-age=604800'); // Allow browser caching (7 days)
        readfile($cachedPath);
        exit;
    }
}
