<?php

/**
* Controller responsible for handling image uploads, hashing, duplicate detection,
* resizing, and secure storage of uploaded files.
*/
class UploadController
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
    * Base directory where uploaded files are stored.
    */
    private static $uploadDir = __DIR__ . "/../uploads/";

    /**
    * Disallowed file extensions for uploads (for security).
    */
    private static $blockedExtensions = [
        'php','php3','php4','php5','phtml','cgi','pl','asp','aspx',
        'exe','bat','sh','cmd','com','dll','js','vbs','py','rb'
    ];

    /**
    * Log details of an upload attempt into the database.
    *
    * @param int $userId ID of the user attempting the upload.
    * @param array $file File array from $_FILES.
    * @param string $status Upload status (success/failed).
    * @param array $errors Error messages if failed.
    * @param string|null $storedPath Path where the file was stored.
    * @return void
    */
    private static function logUploadAttempt($userId, $file, $status, $errors = [], $storedPath = null)
    {
        $originalName   = $file['name'] ?? 'unknown';
        $extension      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $size           = $file['size'] ?? 0;
        $tmp            = $file['tmp_name'] ?? '';
        $mimeReported   = $file['type'] ?? 'unknown';
        $mimeDetected   = ($tmp && file_exists($tmp))
            ? (function($tmp)
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $det   = $finfo ? finfo_file($finfo, $tmp) : 'unknown';
                if ($finfo)
                {
                    finfo_close($finfo);
                }

                return $det;
            })($tmp) : $mimeReported;

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $failureReason = !empty($errors) ? implode("; ", $errors) : null;

        Database::insert("
            INSERT INTO app_image_upload_logs
                (user_id, ip_address, filename_original, mime_reported, mime_detected,
                 file_extension, file_size, status, failure_reason, stored_path, created_at)
            VALUES
                (:user_id, :ip_address, :filename_original, :mime_reported, :mime_detected,
                 :file_extension, :file_size, :status, :failure_reason, :stored_path, NOW())
        ", [
            ':user_id'          => $userId,
            ':ip_address'       => $ip,
            ':filename_original'=> $originalName,
            ':mime_reported'    => $mimeReported,
            ':mime_detected'    => $mimeDetected,
            ':file_extension'   => $extension,
            ':file_size'        => $size,
            ':status'           => $status,
            ':failure_reason'   => $failureReason,
            ':stored_path'      => $storedPath
        ]);
    }

    /**
    * Handle image upload request.
    * - Validates file type and size
    * - Generates perceptual hashes
    * - Detects near-duplicates
    * - Stores metadata in database
    *
    * @return void
    */
    public static function upload()
    {
        $errors = [];
        $success = '';

        $config = self::getConfig();

        // Require login
        RoleHelper::requireLogin();

        // Initialize template engine with caching support
        $template = self::initTemplate();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']))
        {
            $file = $_FILES['image'];
            $description = Security::sanitizeString($_POST['description'] ?? '');

            $userId = SessionManager::get('user_id');

            $csrfToken = $_POST['csrf_token'] ?? '';

            // Verify CSRF token to prevent cross-site request forgery
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid request.";
            }

            if ($file['error'] !== UPLOAD_ERR_OK)
            {
                $errors[] = "Upload failed. Try again later!";
            }
            else
            {
                // Maximum allowed image size for upload
                $maxSizeMb = $config['gallery']['upload_max_image_size'];
                $maxSizeBytes = $maxSizeMb * 1024 * 1024;

                if ($file['size'] > $maxSizeBytes)
                {
                    $errors[] = "File exceeds maximum allowed size of {$maxSizeMb} mb.";
                }

                if (!StorageHelper::canStoreFile($file['size']))
                {
                    $errors[] = "Upload failed. Not enough storage available.";
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);

                if ($finfo)
                {
                    finfo_close($finfo);
                }

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp'
                ];

                if (!in_array($mimeType, $allowedTypes, true))
                {
                    $errors[] = "Invalid file type.";
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, self::$blockedExtensions))
                {
                    $errors[] = "File extension .$ext is not allowed.";
                }
                
                $safeExt = $mimeToExt[$mimeType] ?? $ext;
                
                // Ensure the final extension is one of the allowed types
                if (!in_array($safeExt, array_values($mimeToExt), true))
                {
                    $errors[] = "File extension .$safeExt is not allowed.";
                }

                if (!@getimagesize($file['tmp_name']))
                {
                    $errors[] = "The file is not a valid image.";
                }
            }

            if (!empty($errors))
            {
                self::logUploadAttempt($userId, $file, 'failed', $errors);
            }

            if (empty($errors))
            {
                $ext = $safeExt;
                $basename = 'img_' . bin2hex(random_bytes(16));
                $originalPath = "uploads/images/original/" . $basename . "." . $ext;

                $tmpPath = self::$uploadDir . "tmp_" . $basename . "." . $ext;
                move_uploaded_file($file['tmp_name'], $tmpPath);

                self::makeResized($tmpPath, $originalPath, 1280, 1280);

                unlink($tmpPath);

                $fileData = file_get_contents(self::$uploadDir . str_replace("uploads/", "", $originalPath));
                $md5 = md5($fileData);
                $sha1 = sha1($fileData);
                $sha256 = hash("sha256", $fileData);
                $sha512 = hash("sha512", $fileData);

                [$width, $height] = getimagesize(self::$uploadDir . str_replace("uploads/", "", $originalPath));
                $sizeBytes = filesize(self::$uploadDir . str_replace("uploads/", "", $originalPath));
                $mimeType = mime_content_type(self::$uploadDir . str_replace("uploads/", "", $originalPath));

                $imageHash = self::generateImageHashFormatted();

                $moderated = $config['debugging']['allow_approve_uploads'] ? 'NOW()' : 'NULL';

                // Use updated safe hash functions
                $fullPath = self::$uploadDir . str_replace("uploads/", "", $originalPath);
                $phash = HashingHelper::pHash($fullPath, 32, 16);
                $ahash = HashingHelper::aHash($fullPath);
                $dhash = HashingHelper::dHash($fullPath);

                // Split phash into 16 blocks of 4 chars each
                $phashBlocks = [];
                for ($i = 0; $i < 16; $i++)
                {
                    $phashBlocks[$i] = substr($phash, $i * 4, 4);
                }

                // Insert MD5/SHA hashes into app_images
                Database::insert("
                    INSERT INTO app_images
                        (image_hash, user_id, description, status,
                         original_path, mime_type, width, height, size_bytes,
                         md5, sha1, sha256, sha512, moderated_at, created_at, updated_at)
                    VALUES
                        (:image_hash, :user_id, :description, :status,
                         :original_path, :mime_type, :width, :height, :size_bytes,
                         :md5, :sha1, :sha256, :sha512, $moderated, NOW(), NOW())
                ", [
                    ':image_hash' => $imageHash,
                    ':user_id' => $userId,
                    ':description' => $description,
                    ':status' => $config['debugging']['allow_approve_uploads'] ? 'approved' : 'pending',
                    ':original_path' => $originalPath,
                    ':mime_type' => $mimeType,
                    ':width' => $width,
                    ':height' => $height,
                    ':size_bytes' => $sizeBytes,
                    ':md5' => $md5,
                    ':sha1' => $sha1,
                    ':sha256' => $sha256,
                    ':sha512' => $sha512
                ]);

                // Insert into app_image_hashes
                Database::insert("
                    INSERT INTO app_image_hashes
                        (image_hash, phash, ahash, dhash,
                         phash_block_0, phash_block_1, phash_block_2, phash_block_3,
                         phash_block_4, phash_block_5, phash_block_6, phash_block_7,
                         phash_block_8, phash_block_9, phash_block_10, phash_block_11,
                         phash_block_12, phash_block_13, phash_block_14, phash_block_15)
                    VALUES
                        (:image_hash, :phash, :ahash, :dhash,
                         :phash_block_0, :phash_block_1, :phash_block_2, :phash_block_3,
                         :phash_block_4, :phash_block_5, :phash_block_6, :phash_block_7,
                         :phash_block_8, :phash_block_9, :phash_block_10, :phash_block_11,
                         :phash_block_12, :phash_block_13, :phash_block_14, :phash_block_15)
                ", array_merge([
                    ':image_hash' => $imageHash,
                    ':phash' => $phash,
                    ':ahash' => $ahash,
                    ':dhash' => $dhash
                ], array_combine(array_map(fn($i)=>":phash_block_$i", range(0,15)), $phashBlocks)));

                $success = "Uploaded successfully! Image pending approval!";
                self::logUploadAttempt($userId, $file, 'success');
            }
        }

        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->assign('max_image_size', $config['gallery']['upload_max_image_size']);
        $template->render('image_upload.html');
    }

    /**
     * Generates a unique, URL-safe alphanumeric hash
     * consisting of 5 groups of 5 characters separated by dashes.
     *
     * Supports configuration for hash type:
     *  - all_digits
     *  - all_letters_lower
     *  - all_letters_upper
     *  - mixed_lower (letters+digits, lowercase letters)
     *  - mixed_upper (letters+digits, uppercase letters)
     *
     * Uses batch checking to reduce database queries for collisions.
     *
     * @param string|null $hashType Optional hash type, defaults to 'mixed_lower'
     * @return string A unique image hash
     */
    private static function generateImageHashFormatted(?string $hashType = null): string
    {
        // Fetch configuration
        $config = self::getConfig();
        $hashType = $hashType ?? ($config['upload']['hash_type'] ?? 'mixed_lower');

        $lettersLower = 'abcdefghijklmnopqrstuvwxyz';
        $lettersUpper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits       = '0123456789';

        static $toggle = false; // flips each call to maintain balance

        do
        {
            $hashes = [];
            $candidateCount = 5; // batch of candidates

            for ($i = 0; $i < $candidateCount; $i++)
            {
                $parts = [];

                // Generate 5 groups of 5 characters
                for ($g = 0; $g < 5; $g++)
                {
                    $chars = [];

                    switch ($hashType)
                    {
                        case 'all_digits':
                            for ($c = 0; $c < 5; $c++)
                            {
                                $chars[] = $digits[random_int(0, strlen($digits) - 1)];
                            }
                            break;

                        case 'all_letters_lower':
                            for ($c = 0; $c < 5; $c++)
                            {
                                $chars[] = $lettersLower[random_int(0, strlen($lettersLower) - 1)];
                            }
                            break;

                        case 'all_letters_upper':
                            for ($c = 0; $c < 5; $c++)
                            {
                                $chars[] = $lettersUpper[random_int(0, strlen($lettersUpper) - 1)];
                            }
                            break;

                        case 'mixed_lower':
                        case 'mixed_upper':
                        default:
                            // Mixed types with 50/50 letter-digit balance per group
                            $letters = $hashType === 'mixed_lower' ? $lettersLower : $lettersUpper;

                            if (($g + (int)$toggle) % 2 === 0)
                            {
                                // 3 letters, 2 digits
                                for ($l = 0; $l < 3; $l++)
                                {
                                    $chars[] = $letters[random_int(0, strlen($letters) - 1)];
                                }

                                for ($d = 0; $d < 2; $d++)
                                {
                                    $chars[] = $digits[random_int(0, strlen($digits) - 1)];
                                }
                            }
                            else
                            {
                                // 2 letters, 3 digits
                                for ($l = 0; $l < 2; $l++)
                                {
                                    $chars[] = $letters[random_int(0, strlen($letters) - 1)];
                                }

                                for ($d = 0; $d < 3; $d++)
                                {
                                    $chars[] = $digits[random_int(0, strlen($digits) - 1)];
                                }
                            }

                            shuffle($chars);
                            break;
                    }

                    $parts[] = implode('', $chars);
                }

                $hashes[] = implode('-', $parts);
            }

            // Batch check for collisions
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $sql = "SELECT image_hash FROM app_images WHERE image_hash IN ($placeholders)";
            $existing = Database::fetchAll($sql, $hashes);
            $existingHashes = array_column($existing, 'image_hash');

            // Pick first non-colliding hash
            $hash = null;
            foreach ($hashes as $h)
            {
                if (!in_array($h, $existingHashes, true))
                {
                    $hash = $h;
                    break;
                }
            }

        } while ($hash === null); // regenerate if all collided

        // Flip toggle for next call → ensures overall balance
        $toggle = !$toggle;

        return $hash;
    }
  
    /**
    * Creates a resized copy of the given image while preserving
    * aspect ratio and handling transparency for PNG/WebP formats.
    *
    * Upscales images ≤600x600 by 2x but never exceeds 1280x1280.
    * Maintains maximum image quality and optionally sharpens upscaled images.
    *
    * @param string $src Path to source image
    * @param string $dest Destination relative path for resized image
    * @param int $maxWidth Maximum width constraint
    * @param int $maxHeight Maximum height constraint
    *
    * @return void
    */
    private static function makeResized($src, $dest, $maxWidth, $maxHeight)
    {
        $srcPath = $src;
        $destPath = self::$uploadDir . str_replace("uploads/", "", $dest);

        // Use the new ImageHelper class to handle resizing
        ImageHelper::resize($srcPath, $destPath, $maxWidth, $maxHeight);
    }
}
