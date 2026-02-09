<?php

/**
 * Controller responsible for the moderation and admin dashboard.
 */
class ModerationController
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
     * Moderation panel dashboard.
     *
     * Shows total images (excluding deleted/rejected), approved, pending,
     * and storage used/remaining with percentage.
     */
    public static function dashboard()
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);


        // Fetch counts
        $totalUserResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_users WHERE status NOT IN ('deleted')");
        $totalImagesResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status IN ('approved', 'pending', 'deleted', 'rejected')");
        $approvedCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'approved'");
        $pendingCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'");
        $removedCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status IN ('deleted', 'rejected')");

        $total_users = (int)($totalUserResult['cnt'] ?? 0);
        $total_images = (int)($totalImagesResult['cnt'] ?? 0);
        $approved_count = (int)($approvedCountResult['cnt'] ?? 0);
        $pending_count = (int)($pendingCountResult['cnt'] ?? 0);
        $removed_count = (int)($removedCountResult['cnt'] ?? 0);

        // Storage info using StorageHelper
        $storage_used = StorageHelper::getUsedStorageReadable();
        $storage_remaining = StorageHelper::getRemainingStorageReadable();
        $storage_total = StorageHelper::getMaxStorageReadable();
        $storage_percent = StorageHelper::getStorageUsagePercent();

        // Get percentage as numeric value
        $percentString = StorageHelper::getStorageUsagePercent(2);
        $percentNumeric = (float) rtrim($percentString, '%');

        // Convert to degrees for conic-gradient
        $storage_usage_percent = ($percentNumeric / 100) * 360 . 'deg';

        // Assign flat template variables
        $template->assign('total_users', NumericalHelper::formatCount($total_users));
        $template->assign('total_images', NumericalHelper::formatCount($total_images));
        $template->assign('approved_count', NumericalHelper::formatCount($approved_count));
        $template->assign('pending_count', NumericalHelper::formatCount($pending_count));
        $template->assign('removed_count', NumericalHelper::formatCount($removed_count));

        $template->assign('storage_used', $storage_used);
        $template->assign('storage_remaining', $storage_remaining);
        $template->assign('storage_total', $storage_total);
        $template->assign('storage_percent', $storage_percent);
        $template->assign('storage_usage_percent', $storage_usage_percent);

        $template->render('panel/moderation_dashboard.html');
    }

    /**
     * Display pending images for moderation with pagination.
     *
     * @param int|null $page Current page number (optional, defaults to 1)
     */
    public static function pending($page = null): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        $page = (int)($page ?? 1);
        $perPage = 15; // number of images per page
        $offset = ($page - 1) * $perPage;

        // Fetch total pending images count
        $totalCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'");
        $totalCount = (int)($totalCountResult['cnt'] ?? 0);

        // Fetch paginated pending images
        $rows = Database::fetchAll("
            SELECT 
                image_hash,
                user_id,
                age_sensitive,
                mime_type,
                created_at
            FROM app_images
            WHERE status = 'pending'
            ORDER BY created_at DESC
            LIMIT :offset, :perpage
        ", [
            'offset' => $offset,
            'perpage' => $perPage
        ]);

        // Flatten each row for template engine
        $flattenedRows = [];
        foreach ($rows as $row)
        {
            $flattenedRows[] = [
                $row['image_hash'],
                self::getUsernameById($row['user_id']),
                DateHelper::format($row['created_at']),
            ];
        }

        // Assign template variables
        $template->assign('pending_rows', $flattenedRows);
        $template->assign('pending_count', count($flattenedRows));

        // Pagination calculation
        $totalPages = (int)ceil($totalCount / $perPage);

        $paginationPages = [];
        for ($i = 1; $i <= $totalPages; $i++)
        {
            $paginationPages[] = [
                "/moderation/image-pending/page/{$i}",
                $i,
                $i === $page // current
            ];
        }

        $paginationPrev = $page > 1 ? "/moderation/image-pending/page/" . ($page - 1) : null;
        $paginationNext = $page < $totalPages ? "/moderation/image-pending/page/" . ($page + 1) : null;

        $template->assign('pagination_pages', $paginationPages);
        $template->assign('pagination_prev', $paginationPrev);
        $template->assign('pagination_next', $paginationNext);

        $template->render('panel/moderation_pending.html');
    }

    /**
     * Approve a pending image from the moderation panel.
     *
     * This action is POST-only and requires a valid CSRF token.
     * Only images in "pending" status may be approved.
     *
     * @param string $hash The image hash identifier from the route.
     * @return void
     */
    public static function approveImage(string $hash): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        // Approve requests must be POST-only (prevents accidental approvals from URL visits)
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

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = trim($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', '404 Not Found');
            $template->assign('message', 'Oops! We couldn’t find that image.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow approving images that are currently pending.)
        $sql = "SELECT image_hash, status
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

        // Only pending images can be approved
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /moderation/image-pending');
            exit;
        }

        // Approve image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $appUserId = SessionManager::get('user_id');
        $sql = "UPDATE app_images
                SET status = 'approved',
                    approved_by = $appUserId,
                    moderated_at = NOW(),
                    updated_at = NOW()
                WHERE image_hash = :hash
                AND status = 'pending'";
        Database::execute($sql, [':hash' => $hash]);

        // Redirect back to the pending list after action completes
        header('Location: /moderation/image-pending');
        exit;
    }

    /**
     * Approve a pending image from the moderation panel.
     *
     * This action is POST-only and requires a valid CSRF token.
     * Only images in "pending" status may be approved.
     *
     * @param string $hash The image hash identifier from the route.
     * @return void
     */
    public static function approveImageSensitive(string $hash): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        // Approve requests must be POST-only (prevents accidental approvals from URL visits)
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

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = trim($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', '404 Not Found');
            $template->assign('message', 'Oops! We couldn’t find that image.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow approving images that are currently pending.)
        $sql = "SELECT image_hash, status
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

        // Only pending images can be approved
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /moderation/image-pending');
            exit;
        }

        // Approve image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $appUserId = SessionManager::get('user_id');
        $sql = "UPDATE app_images
                SET age_sensitive = 1,
                    status = 'approved',
                    approved_by = $appUserId,
                    moderated_at = NOW(),
                    updated_at = NOW()
                WHERE image_hash = :hash
                AND status = 'pending'";
        Database::execute($sql, [':hash' => $hash]);

        // Redirect back to the pending list after action completes
        header('Location: /moderation/image-pending');
        exit;
    }

    /**
     * Reject a pending image from the moderation panel.
     *
     * This action is POST-only and requires a valid CSRF token.
     * Only images in "pending" status may be rejected.
     *
     * @param string $hash The image hash identifier from the route.
     * @return void
     */
    public static function rejectImage(string $hash): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        // Reject requests must be POST-only (prevents accidental rejections from URL visits)
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

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = trim($hash);
        if ($hash === '')
        {
            http_response_code(404);
            $template->assign('title', '404 Not Found');
            $template->assign('message', 'Oops! We couldn’t find that image.');
            $template->render('errors/error_page.html');
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow rejecting images that are currently pending.)
        $sql = "SELECT image_hash, status
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

        // Only pending images can be rejected
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /moderation/image-pending');
            exit;
        }

        // Reject image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $rejUserId = SessionManager::get('user_id');
        $sql = "UPDATE app_images
                SET status = 'rejected',
                    rejected_by = $rejUserId,
                    moderated_at = NOW(),
                    updated_at = NOW()
                WHERE image_hash = :hash
                AND status = 'pending'";
        Database::execute($sql, [':hash' => $hash]);

        // Redirect back to the pending list after action completes
        header('Location: /moderation/image-pending');
        exit;
    }

    /**
     * Image comparison tool.
     *
     * Allows moderators to select two images and calculate similarity
     * distances using aHash, pHash, and dHash. Assigns results to template.
     *
     * Fetches all approved image hashes for dropdown selection.
     */
    public static function comparison(): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        $comparisonResult  = null;      // Stores calculated hash distances
        $selectedImage1    = null;      // First selected image
        $selectedImage2    = null;      // Second selected image
        $similarityPercent = 0;         // Overall similarity percentage

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
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

            $hash1 = $_POST['image1_hash'] ?? '';
            $hash2 = $_POST['image2_hash'] ?? '';

            if ($hash1 && $hash2)
            {
                // Fetch image hash data for both selected images
                $selectedImage1 = Database::fetch("SELECT * FROM app_image_hashes WHERE image_hash = :hash LIMIT 1", ['hash' => $hash1]);
                $selectedImage2 = Database::fetch("SELECT * FROM app_image_hashes WHERE image_hash = :hash LIMIT 1", ['hash' => $hash2]);

                if ($selectedImage1 && $selectedImage2)
                {
                    // Calculate aHash distance
                    $ahashDistance = HashingHelper::hammingDistance($selectedImage1['ahash'], $selectedImage2['ahash']);
                    // Calculate dHash distance
                    $dhashDistance = HashingHelper::hammingDistance($selectedImage1['dhash'], $selectedImage2['dhash']);

                    // Calculate average pHash distance over all 16 blocks
                    $phashDistanceTotal = 0;
                    for ($i = 0; $i <= 15; $i++)
                    {
                        $block1 = $selectedImage1["phash_block_$i"] ?? '';
                        $block2 = $selectedImage2["phash_block_$i"] ?? '';
                        $phashDistanceTotal += HashingHelper::hammingDistance($block1, $block2);
                    }
                    $phashDistanceAvg = round($phashDistanceTotal / 16);

                    $comparisonResult = [
                        'ahash_distance' => $ahashDistance,
                        'phash_distance' => $phashDistanceAvg,
                        'dhash_distance' => $dhashDistance,
                    ];
                }
            }
        }

        // Fetch all approved image hashes for dropdown selection
        $imageHashes = Database::fetchAll("SELECT image_hash FROM app_images WHERE status IN ('approved') ORDER BY id DESC");
        $flatImageHashes = array_column($imageHashes, 'image_hash');

        // Calculate similarity percentage if comparison was performed
        if ($comparisonResult)
        {
            $maxDistance = 100;
            $avgDistance = ($comparisonResult['ahash_distance'] + $comparisonResult['phash_distance'] + $comparisonResult['dhash_distance']) / 2;
            $similarityPercent = max(0, 100 - round(($avgDistance / $maxDistance) * 100));
        }

        // Assign results and selections to template
        $template->assign('ahash_distance', $comparisonResult['ahash_distance'] ?? null);
        $template->assign('phash_distance', $comparisonResult['phash_distance'] ?? null);
        $template->assign('dhash_distance', $comparisonResult['dhash_distance'] ?? null);
        $template->assign('similarity_percent', $similarityPercent);

        $template->assign('image_hashes', $flatImageHashes);
        $template->assign('selected_image1_hash', $selectedImage1['image_hash'] ?? '');
        $template->assign('selected_image1_original_path', !empty($selectedImage1['image_hash']) ? '/image/original/' . $selectedImage1['image_hash'] : '');
        $template->assign('selected_image2_hash', $selectedImage2['image_hash'] ?? '');
        $template->assign('selected_image2_original_path', !empty($selectedImage2['image_hash']) ? '/image/original/' . $selectedImage2['image_hash'] : '');

        $template->render('panel/moderation_comparison.html');
    }

    /**
     * Image rehash tool.
     *
     * Allows moderators to recalculate aHash, pHash, and dHash values for a
     * single image or a batch of images that have not been rehashed yet.
     *
     * Uses same image fetching logic as comparison for single image selection.
     * Updates hashes in database and marks image as rehashed.
     */
    public static function rehash(): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator'], $template);

        $message = '';
        $processedImages = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
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

            $mode = $_POST['rehash_mode'] ?? 'single';
            $imageHash = $_POST['image_hash'] ?? null;

            // Fetch images to rehash
            if ($mode === 'single' && $imageHash)
            {
                // Check both tables for existence
                $image = Database::fetch("SELECT * FROM app_images WHERE image_hash = :hash AND status IN ('approved') LIMIT 1", ['hash' => $imageHash]);
                $hashRow = Database::fetch("SELECT * FROM app_image_hashes WHERE image_hash = :hash LIMIT 1", ['hash' => $imageHash]);

                if (!$image || !$hashRow)
                {
                    $message = "Error: Image hash missing in " . (!$image ? 'app_images' : 'app_image_hashes') . ". Cannot rehash.";
                    $images = [];
                }
                else
                {
                    $images = [$image];
                }
            }
            elseif ($mode === 'batch')
            {
                // Batch: select images not yet rehashed
                $images = Database::fetchAll("SELECT * FROM app_images WHERE rehashed = 0 OR rehashed_on IS NULL AND status IN ('approved')  ORDER BY id ASC LIMIT 10");
            }
            else
            {
                $images = [];
            }

            // Recalculate hashes for each selected image
            foreach ($images as $img)
            {
                $imgPath = __DIR__ . '/../uploads/' . str_replace("uploads/", "", $img['original_path']);

                if (file_exists($imgPath))
                {
                    // Generate hashes
                    $newPhash = HashingHelper::pHash($imgPath, 32, 16);
                    $newAhash = HashingHelper::aHash($imgPath, 16);
                    $newDhash = HashingHelper::dHash($imgPath, 17, 16);

                    // Split pHash into 16 blocks
                    $phashBlocks = [];
                    for ($i = 0; $i < 16; $i++)
                    {
                        $phashBlocks["phash_block_$i"] = substr($newPhash, $i * 4, 4);
                    }

                    // Insert or update app_image_hashes
                    Database::execute("
                        INSERT INTO app_image_hashes (image_hash, ahash, dhash, phash, phash_block_0, phash_block_1, phash_block_2, phash_block_3,
                                                     phash_block_4, phash_block_5, phash_block_6, phash_block_7,
                                                     phash_block_8, phash_block_9, phash_block_10, phash_block_11,
                                                     phash_block_12, phash_block_13, phash_block_14, phash_block_15)
                        VALUES (:image_hash, :ahash, :dhash, :phash,
                                :phash_block_0, :phash_block_1, :phash_block_2, :phash_block_3,
                                :phash_block_4, :phash_block_5, :phash_block_6, :phash_block_7,
                                :phash_block_8, :phash_block_9, :phash_block_10, :phash_block_11,
                                :phash_block_12, :phash_block_13, :phash_block_14, :phash_block_15)
                        ON DUPLICATE KEY UPDATE
                            ahash = VALUES(ahash),
                            dhash = VALUES(dhash),
                            phash = VALUES(phash),
                            phash_block_0 = VALUES(phash_block_0),
                            phash_block_1 = VALUES(phash_block_1),
                            phash_block_2 = VALUES(phash_block_2),
                            phash_block_3 = VALUES(phash_block_3),
                            phash_block_4 = VALUES(phash_block_4),
                            phash_block_5 = VALUES(phash_block_5),
                            phash_block_6 = VALUES(phash_block_6),
                            phash_block_7 = VALUES(phash_block_7),
                            phash_block_8 = VALUES(phash_block_8),
                            phash_block_9 = VALUES(phash_block_9),
                            phash_block_10 = VALUES(phash_block_10),
                            phash_block_11 = VALUES(phash_block_11),
                            phash_block_12 = VALUES(phash_block_12),
                            phash_block_13 = VALUES(phash_block_13),
                            phash_block_14 = VALUES(phash_block_14),
                            phash_block_15 = VALUES(phash_block_15)
                    ", array_merge([
                        'image_hash' => $img['image_hash'],
                        'ahash' => $newAhash,
                        'dhash' => $newDhash,
                        'phash' => $newPhash,
                    ], $phashBlocks));

                    // Mark image as rehashed in app_images
                    Database::execute("
                        UPDATE app_images SET
                            rehashed = 1,
                            rehashed_on = NOW()
                        WHERE id = :id
                    ", [
                        'id' => $img['id']
                    ]);

                    $processedImages[] = $img['image_hash'];
                }
            }

            $count = count($processedImages);
            $message = $message ?: "Rehashed {$count} image" . ($count === 1 ? '' : 's') . " successfully.";
        }

        // Fetch all image hashes for selection dropdown
        $imageHashes = Database::fetchAll("SELECT image_hash FROM app_images WHERE status IN ('approved') ORDER BY id DESC");
        $flatImageHashes = array_column($imageHashes, 'image_hash');

        // Assign template variables
        $template->assign('image_hashes', $flatImageHashes);
        $template->assign('processed_images', $processedImages);
        $template->assign('message', $message);

        // Render moderation panel template with rehash tool
        $template->render('panel/moderation_rehash.html');
    }

    /**
     * Serve an image file directly from the uploads directory.
     *
     * @param string $hash Unique hash identifier for the image.
     * @return void
     */
    public static function servePendingImage(string $hash): void
    {
        $template = self::initTemplate();

        // Require login and role check
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        $sql = "
            SELECT
                original_path,
                mime_type,
                age_sensitive
            FROM app_images
            WHERE image_hash = :hash
              AND (status = 'pending')
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

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=604800'); // Allow caching (7 days)
        readfile($fullPath);
        exit;
    }
}
