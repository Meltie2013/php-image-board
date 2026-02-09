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

        $template->assign('csrf_token', Security::generateCsrfToken());
        return $template;
    }

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
    public static function index($page = 1): void
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
        $sql = "SELECT i.image_hash, i.age_sensitive, i.created_at, i.views, u.date_of_birth, u.age_verified_at
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
            return (int)$img['age_sensitive'] === 0 || self::checkAgeSensitiveAccess($currentUser, $minBirthDate);
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
                (int)($img['views'] ?? 0),
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
        $config = self::getConfig();
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');

        $currentUser = null;
        $commentsPerPage = $config['gallery']['comments_per_page'];
        $commentsPage = (int)($_GET['cpage'] ?? 1);

        if ($commentsPage < 1)
        {
            $commentsPage = 1;
        }

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

        // Track views per session (guest and logged-in users) to prevent inflating view count
        $viewedImages = SessionManager::get('viewed_images', []);
        if (!is_array($viewedImages))
        {
            $viewedImages = [];
        }

        if (!in_array($img['image_hash'], $viewedImages, true))
        {
            $sql = "UPDATE app_images SET views = views + 1 WHERE id = :id LIMIT 1";
            Database::execute($sql, [':id' => (int)$img['id']]);

            $viewedImages[] = $img['image_hash'];
            SessionManager::set('viewed_images', $viewedImages);

            $img['views'] = (int)($img['views'] ?? 0) + 1;
        }

        if ((int)$img['age_sensitive'] === 1)
        {
            $alertColor   = 'alert-warning';
            $alertTag     = '<b>Heads Up!</b>';
            $alertMessage = 'This image is marked <b>sensitive</b> and may not be suitable for users under <b>' . self::$config['profile']['years'] . '</b> years of age.';

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

        // Fetch comments count (pagination)
        $commentCountRow = Database::fetch(
            "SELECT COUNT(*) AS count
               FROM app_image_comments
              WHERE image_id = :image_id
                AND is_deleted = 0",
            [':image_id' => $img['id']]
        );

        $commentCount = (int)($commentCountRow['count'] ?? 0);
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

        $comments = Database::fetchAll(
            "SELECT c.comment_body, c.created_at, u.username
               FROM app_image_comments c
               LEFT JOIN app_users u ON c.user_id = u.id
              WHERE c.image_id = :image_id
                AND c.is_deleted = 0
              ORDER BY c.created_at DESC
              LIMIT {$commentsPerPage} OFFSET {$commentsOffset}",
            [':image_id' => $img['id']]
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

        $csrfToken = $_POST['csrf_token'] ?? '';

        // Verify CSRF token to prevent cross-site request forgery
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the image by hash
        $sql = "SELECT id FROM app_images WHERE image_hash = :hash LIMIT 1";
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

        $csrfToken = $_POST['csrf_token'] ?? '';

        // Verify CSRF token to prevent cross-site request forgery
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the image by hash
        $sql = "SELECT id FROM app_images WHERE image_hash = :hash LIMIT 1";
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

        $template->assign('title', 'Successful Upvote!');
        $template->assign('message', "You have successfully upvoted this image.");
        $template->assign('link', "/image/{$hash}");
        $template->render('errors/error_page.html');
    }

    /**
     * Handle posting a comment on an image by logged-in user.
     *
     * @param string $hash Unique hash of the image.
     */
    public static function comment(string $hash): void
    {
        $template = self::initTemplate();
        $userId = SessionManager::get('user_id');

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

        $csrfToken = $_POST['csrf_token'] ?? '';

        // Verify CSRF token to prevent cross-site request forgery
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $commentBody = $_POST['comment_body'] ?? '';
        $commentBody = Security::sanitizeString($commentBody);

        if ($commentBody === '')
        {
            header('Location: /image/' . $hash);
            exit;
        }

        // Find the approved image by hash
        $sql = "SELECT id FROM app_images WHERE image_hash = :hash AND status = 'approved' LIMIT 1";
        $image = Database::fetch($sql, [':hash' => $hash]);

        if (!$image)
        {
            http_response_code(404);
            $template->assign('title', 'Image Not Found');
            $template->assign('message', 'The requested image could not be found.');
            $template->render('errors/error_page.html');
            return;
        }

        $imageId = (int)$image['id'];

        // Insert comment
        $sql = "INSERT INTO app_image_comments (image_id, user_id, comment_body)
                VALUES (:image_id, :user_id, :comment_body)";
        Database::execute($sql, [
            ':image_id' => $imageId,
            ':user_id' => (int)$userId,
            ':comment_body' => $commentBody
        ]);

        header('Location: /image/' . $hash);
        exit;
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
            AND (i.status = 'approved' OR i.status = 'rejected')
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
