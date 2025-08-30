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

        return $template;
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
        $template->assign('total_users', number_format($total_users));
        $template->assign('total_images', number_format($total_images));
        $template->assign('approved_count', number_format($approved_count));
        $template->assign('pending_count', number_format($pending_count));
        $template->assign('removed_count', number_format($removed_count));

        $template->assign('storage_used', $storage_used);
        $template->assign('storage_remaining', $storage_remaining);
        $template->assign('storage_total', $storage_total);
        $template->assign('storage_percent', $storage_percent);
        $template->assign('storage_usage_percent', $storage_usage_percent);

        $template->render('panel/moderation_dashboard.html');
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
        RoleHelper::requireRole(['administrator', 'moderator'], $template);

        $message = '';
        $processedImages = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
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
}
