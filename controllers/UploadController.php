<?php

/**
* Controller responsible for handling image uploads, hashing, duplicate detection,
* resizing, and secure storage of uploaded files.
*/
class UploadController
{
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

        $config = require __DIR__ . '/../config/config.php';
        $template = new TemplateEngine(__DIR__ . '/../templates', __DIR__ . '/../cache/templates', $config);
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        $userId = SessionManager::get('user_id');
        if (!$userId)
        {
            header('Location: /user/login');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']))
        {
            $file = $_FILES['image'];
            $description = $_POST['description'] ?? '';

            if ($file['error'] !== UPLOAD_ERR_OK)
            {
                $errors[] = "Upload failed. Try again later!";
            }
            else
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($file['type'], $allowedTypes))
                {
                    $errors[] = "Invalid file type.";
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, self::$blockedExtensions))
                {
                    $errors[] = "File extension .$ext is not allowed.";
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
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $basename = 'img_' . bin2hex(random_bytes(16));
                $originalPath = "uploads/images/original/" . $basename . "_1280." . $ext;

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

                // Insert MD5/SHA hashes into app_images (existing table)
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
        $template->render('gallery/image_upload.html');
    }

    /**
    * Generates a unique, URL-safe alphanumeric hash
    * consisting of 5 groups of 5 characters separated by dashes.
    *
    * The function checks the "app_images" table to ensure the hash
    * does not already exist. If a collision is found, it regenerates
    * until a unique hash is produced.
    *
    * Example: abcde-fghij-klmno-pqrst-uvwxy
    *
    * @return string A unique image hash
    */
    private static function generateImageHashFormatted(): string
    {
        do {
            $parts = [];
            for ($i = 0; $i < 5; $i++)
            {
                $bytes = random_bytes(3); // 3 bytes = 6 hex chars
                $parts[] = substr(bin2hex($bytes), 0, 5); // take first 5 chars
            }

            $hash = implode('-', $parts);

            // Check if hash exists in the database
            $sql = "SELECT COUNT(*) AS cnt FROM app_images WHERE image_hash = :image_hash";
            $result = Database::fetch($sql, [':image_hash' => $hash]);

        } while (!empty($result['cnt'])); // regenerate if exists

        return $hash;
    }

    /**
    * Creates a resized copy of the given image while preserving
    * aspect ratio and handling transparency for PNG/WebP formats.
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

        $image = imagecreatefromstring(file_get_contents($srcPath));
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min($maxWidth / $width, $maxHeight / $height);
        if ($scale > 1)
        {
            $scale = 1;
        }

        $newWidth = (int)($width * $scale);
        $newHeight = (int)($height * $scale);

        $tmp = imagecreatetruecolor($newWidth, $newHeight);

        $mimeType = mime_content_type($srcPath);
        if (in_array($mimeType, ['image/png', 'image/webp']))
        {
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);
            $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
            imagefilledrectangle($tmp, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($tmp, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        switch ($mimeType)
        {
            case 'image/jpeg':
                imagejpeg($tmp, $destPath, 90);
                break;

            case 'image/png':
                imagepng($tmp, $destPath);
                break;

            case 'image/webp':
                imagewebp($tmp, $destPath, 90);
                break;

            default:
                imagejpeg($tmp, $destPath, 90);
        }

        imagedestroy($image);
        imagedestroy($tmp);
    }
}
