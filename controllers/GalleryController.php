<?php

/**
 * Controller responsible for handling gallery views, image display,
 * and serving stored image files to the client.
 */
class GalleryController
{
    /**
     * Cached config for controller usage.
     *
     * @var array
     */
    private static array $config;

    /**
     * Retrieve a username by user ID.
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
        $sql = "SELECT username FROM app_users WHERE id = :id LIMIT 1";
        $result = Database::fetch($sql, [':id' => $userId]);

        return $result['username'] ?? '';
    }

    /**
     * Load and cache config once per request.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (empty(self::$config))
        {
            self::$config = require __DIR__ . '/../config/config.php';
        }

        return self::$config;
    }

    /**
     * Initialize template engine with optional cache clearing.
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

        return $template;
    }

    /**
     * Check if a user has access to age-sensitive content.
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
     * @param int|null $page Current page number, defaults to 1.
     * @return void
     */
    public static function index($page = null): void
    {
        $config = self::getConfig();
        $imagesPerPage = $config['gallery']['images_displayed'];

        $page = max(1, (int)($page ?? 1));
        $limit = $imagesPerPage;
        $offset = ($page - 1) * $limit;

        $userId = SessionManager::get('user_id');
        $currentUser = null;

        // Fetch logged-in user's DOB and verification status if available
        if ($userId)
        {
            $currentUser = Database::fetch(
                "SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        $requiredYears = (int)($config['profile']['years'] ?? 0);
        $minBirthDate = (new DateTime())->modify("-{$requiredYears} years")->format('Y-m-d');

        // Fetch all approved images
        $sql = "SELECT i.image_hash, i.age_sensitive, i.created_at, u.date_of_birth, u.age_verified_at
                FROM app_images i
                LEFT JOIN app_users u ON i.user_id = u.id
                WHERE i.status = 'approved'
                ORDER BY i.created_at DESC";

        $stmt = Database::getPDO()->prepare($sql);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter images based on age-sensitive access
        $filteredImages = array_filter($images, function ($img) use ($currentUser, $minBirthDate)
        {
            return (int)$img['age_sensitive'] === 0
                || self::checkAgeSensitiveAccess($currentUser, $minBirthDate);
        });

        $totalImages = count($filteredImages);
        $totalPages = max(1, ceil($totalImages / $limit));

        // Slice array for pagination
        $pageImages = array_slice($filteredImages, $offset, $limit);

        $loopImages = [];
        foreach ($pageImages as $img)
        {
            $loopImages[] = [
                "/image/original/" . $img['image_hash'],
                "/image/" . $img['image_hash'],
            ];
        }

        // Pagination navigation links
        $pagination_prev = $page > 1 ? ($page - 1 === 1 ? '/' : '/page/' . ($page - 1)) : null;
        $pagination_next = $page < $totalPages ? '/page/' . ($page + 1) : null;

        $range = $config['gallery']['pagination_range'];
        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);

        $pagination_pages = [];
        if ($start > 1)
        {
            $pagination_pages[] = ['/page/1', 1, false];
            if ($start > 2)
            {
                $pagination_pages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $pagination_pages[] = [
                $i === 1 ? '/page/1' : '/page/' . $i,
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

            $pagination_pages[] = ['/page/' . $totalPages, $totalPages, false];
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
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function view(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');
        $currentUser = null;

        if ($userId)
        {
            $currentUser = Database::fetch(
                "SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        $sql = "
            SELECT
                i.id,
                i.user_id,
                i.image_hash,
                i.status,
                i.description,
                i.original_path,
                i.age_sensitive,
                i.created_at,
                i.mime_type,
                i.width,
                i.height,
                i.size_bytes,
                i.md5,
                i.sha1,
                i.reject_reason,
                i.votes,
                i.favorites,
                u.date_of_birth,
                u.age_verified_at
            FROM app_images i
            LEFT JOIN app_users u ON i.user_id = u.id
            WHERE i.image_hash = :hash
              AND (i.status = 'approved' AND NOT i.status = 'deleted')
            LIMIT 1
        ";

        $img = Database::fetch($sql, ['hash' => $hash]);

        if (!$img)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        if ((int)$img['age_sensitive'] === 1 && !self::checkAgeSensitiveAccess($currentUser, (new DateTime())->modify("-" . (int)(self::$config['profile']['years'] ?? 0) . " years")->format('Y-m-d')))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'The server understood your request, but you are not authorized to view this image.');
            $template->render('errors/error_page.html');
            return;
        }

        if ((int)$img['age_sensitive'] === 1)
        {
            $alertColor   = 'alert-warning';
            $alertTag     = '<b>Heads Up!</b>';
            $alertMessage = 'This image is marked <b>sensitive</b> and may not be suitable for users under ' . self::$config['profile']['years'] . ' years of age.';

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

        $username = self::getUsernameById((int)$img['user_id']);

        $hasVoted = false;
        if ($userId)
        {
            $sql = "SELECT 1 FROM app_image_votes WHERE user_id = :user_id AND image_id = :image_id LIMIT 1";
            $voted = Database::fetch($sql, [
                ':user_id' => $userId,
                ':image_id' => $img['id']
            ]);
            $hasVoted = (bool)$voted;
        }

        $hasFavorited = false;
        if ($userId)
        {
            $sql = "SELECT 1 FROM app_image_favorites WHERE user_id = :user_id AND image_id = :image_id LIMIT 1";
            $favorited = Database::fetch($sql, [
                ':user_id' => $userId,
                ':image_id' => $img['id']
            ]);
            $hasFavorited = (bool)$favorited;
        }

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
        $template->assign('img_has_favorited', $hasFavorited);

        $template->render('gallery/gallery_view.html');
    }

    /**
     * Handle marking an image as favorite by logged-in user.
     *
     * @param string $hash Unique hash of the image.
     */
    public static function favorite(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');

        // Require login
        RoleHelper::requireLogin();

        // Find the image by hash
        $sql = "SELECT id, favorites FROM app_images WHERE image_hash = :hash LIMIT 1";
        $image = Database::fetch($sql, [':hash' => $hash]);

        if (!$image)
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "The image you attempted to favorite does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = (int)$image['id'];

        // Check if user already favorited
        $sql = "SELECT 1 FROM app_image_favorites WHERE user_id = :user_id AND image_id = :image_id LIMIT 1";
        $existing = Database::fetch($sql, [':user_id' => $userId, ':image_id' => $imageId]);

        if ($existing)
        {
            $template->assign('title', "Favorite Failed");
            $template->assign('message', "You have already marked this image as a favorite.");
            $template->assign('link', "/image/{$hash}");
            $template->render('errors/error_page.html');
            return;
        }

        // Insert favorite
        $sql = "INSERT INTO app_image_favorites (user_id, image_id) VALUES (:user_id, :image_id)";
        Database::execute($sql, [':user_id' => $userId, ':image_id' => $imageId]);

        // Increment favorite count
        $sql = "UPDATE app_images SET favorites = favorites + 1 WHERE id = :id";
        Database::execute($sql, [':id' => $imageId]);

        $template->assign('title', 'Successful Favorite!');
        $template->assign('message', "You have successfully marked this image as a favorite.");
        $template->assign('link', "/image/{$hash}");
        $template->render('errors/error_page.html');
    }

    /**
     * Handle up-vote for an image by logged-in user.
     *
     * @param string $hash Unique hash of the image.
     */
    public static function upvote(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');

        // Require login
        RoleHelper::requireLogin();

        // Find the image by hash
        $sql = "SELECT id, votes FROM app_images WHERE image_hash = :hash LIMIT 1";
        $image = Database::fetch($sql, [':hash' => $hash]);

        if (!$image)
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "The image you attempted to upvote does not exist.");
            $template->assign('link', null);
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = (int)$image['id'];

        // Check if user already voted
        $sql = "SELECT 1 FROM app_image_votes WHERE user_id = :user_id AND image_id = :image_id LIMIT 1";
        $existing = Database::fetch($sql, [':user_id' => $userId, ':image_id' => $imageId]);

        if ($existing)
        {
            $template->assign('title', "Upvote Failed");
            $template->assign('message', "You have already upvoted this image.");
            $template->assign('link', "/image/{$hash}");
            $template->render('errors/error_page.html');
            return;
        }

        // Insert vote
        $sql = "INSERT INTO app_image_votes (user_id, image_id) VALUES (:user_id, :image_id)";
        Database::execute($sql, [':user_id' => $userId, ':image_id' => $imageId]);

        // Increment vote count
        $sql = "UPDATE app_images SET votes = votes + 1 WHERE id = :id";
        Database::execute($sql, [':id' => $imageId]);

        $template->assign('title', 'Successful Upvote!');
        $template->assign('message', "You have successfully upvoted this image.");
        $template->assign('link', "/image/{$hash}");
        $template->render('errors/error_page.html');
    }

    /**
     * Soft delete an image by hash (Administrator or Moderator only).
     *
     * Marks the image as deleted instead of permanently removing it.
     *
     * @param string $hash
     */
    public static function delete(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');

        // Require login
        RoleHelper::requireLogin();

        // Fetch user's role from the database
        $sql = "
            SELECT r.name AS role_name
            FROM app_users u
            JOIN app_roles r ON u.role_id = r.id
            WHERE u.id = :user_id
            LIMIT 1
        ";
        $userRoleData = Database::fetch($sql, [':user_id' => $userId]);
        $role = $userRoleData['role_name'] ?? null;

        // Only admins and moderators can delete
        if (!$role || !in_array(strtolower($role), ['administrator','moderator'], true))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'You do not have permission to delete images.');
            $template->render('errors/error_page.html');
            return;
        }

        // Ensure hash is provided
        $hash = trim($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', '404 Not Found');
            $template->assign('message', 'Oops! We couldn’t find that image.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the image
        $sql = "SELECT image_hash, description, status
                  FROM app_images
                 WHERE image_hash = :hash LIMIT 1";
        $image = Database::fetch($sql, [':hash' => $hash]);

        if (!$image)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Soft delete: update status instead of deleting row
        $sql = "UPDATE app_images
                   SET status = 'deleted'
                 WHERE image_hash = :hash";
        Database::execute($sql, [':hash' => $hash]);

        // Render confirmation template
        $template->assign('title', 'Image Deleted');
        $template->assign('message', "This image has been deleted: {$image['image_hash']}");
        $template->render('gallery/gallery_image_deleted.html');
    }

    /**
     * Serve an image file directly from the uploads directory.
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function serveImage(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');
        $currentUser = null;

        // Fetch current user data for age-sensitive checks
        if ($userId)
        {
            $currentUser = Database::fetch(
                "SELECT date_of_birth, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            );
        }

        // Fetch image metadata from the database
        $sql = "
            SELECT
                original_path,
                mime_type,
                age_sensitive,
                i.user_id,
                u.date_of_birth,
                u.age_verified_at
            FROM app_images i
            LEFT JOIN app_users u ON i.user_id = u.id
            WHERE i.image_hash = :hash
              AND (i.status = 'approved' OR i.status = 'deleted')
            LIMIT 1
        ";
        $image = Database::fetch($sql, ['hash' => $hash]);

        if (!$image)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        // Determine if per-user cache should be applied
        $usePerUserCache = (int)$image['age_sensitive'] === 1;

        // Age-restricted access check
        if ($usePerUserCache && !self::checkAgeSensitiveAccess($currentUser,
            (new DateTime())->modify("-" . (int)(self::$config['profile']['years'] ?? 0) . " years")->format('Y-m-d')))
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
            $mimeType = mime_content_type($cachedPath) ?: $image['mime_type'] ?: 'application/octet-stream';
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($cachedPath));
            header('Cache-Control: public, max-age=604800'); // Browser caching (7 days)
            readfile($cachedPath);
            exit;
        }

        // Resolve original file path
        $baseDir = realpath(__DIR__ . '/../uploads/');
        $fullPath = realpath($baseDir . '/' . ltrim(str_replace("uploads/", "", $image['original_path']), '/'));

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

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . filesize($cachedPath));
        header('Cache-Control: public, max-age=604800'); // Allow browser caching (7 days)
        readfile($cachedPath);
        exit;
    }
}
