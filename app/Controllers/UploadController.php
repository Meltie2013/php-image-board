<?php

/**
 * Controller responsible for handling image uploads, hashing, duplicate detection,
 * resizing, and secure storage of uploaded files.
 *
 * Responsibilities:
 * - Render the upload form and process upload submissions
 * - Validate uploaded files (size, MIME type, extension, image integrity)
 * - Enforce storage limits before accepting large files
 * - Resize uploaded images to configured maximum dimensions
 * - Generate strong cryptographic hashes (md5/sha1/sha256/sha512) for integrity
 * - Generate perceptual hashes (pHash/aHash/dHash) for similarity/duplicate detection
 * - Store image metadata and hashes in the database
 * - Log upload attempts (success/failure) for auditing and troubleshooting
 *
 * Security considerations:
 * - CSRF protection on upload POST requests
 * - Extension and MIME validation (server-side detection via finfo)
 * - Image validity check via getimagesize()
 * - Blocks executable/script file extensions
 * - Randomized storage names to prevent predictable paths and overwrites
 */
class UploadController extends BaseController
{
    /**
     * Static template variables assigned for all upload templates.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
    ];

    /**
     * Upload templates require a CSRF token for form submission.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Base directory where uploaded files are stored.
     *
     * This path is used as the filesystem root for writing resized images and
     * reading stored files for hashing/metadata extraction.
     */
    private static $uploadDir = IMAGE_PATH . '/';

    /**
     * Disallowed file extensions for uploads (for security).
     *
     * These extensions are blocked to reduce the risk of uploading executable
     * or script content that could be run if misconfigured server rules exist.
     */
    private static $blockedExtensions = [
        'php','php3','php4','php5','phtml','cgi','pl','asp','aspx',
        'exe','bat','sh','cmd','com','dll','js','vbs','py','rb'
    ];

    /**
     * Convert a PHP ini size string into bytes.
     *
     * @param string $value PHP size string (for example: 128M, 2G)
     * @return int Size in bytes
     */
    private static function convertPhpSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '')
        {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $bytes = (int)$value;

        switch ($unit)
        {
            case 'g':
                $bytes *= 1024;
            case 'm':
                $bytes *= 1024;
            case 'k':
                $bytes *= 1024;
                break;
        }

        return max(0, $bytes);
    }

    /**
     * Estimate whether processing an image is likely to exceed available memory.
     *
     * Uses a conservative multiplier because upload validation, resizing, and
     * hashing may allocate multiple image buffers during one request.
     *
     * @param int $width Source image width
     * @param int $height Source image height
     * @param int $multiplier Safety multiplier for temporary image buffers
     * @return bool True when the image is likely too expensive to process safely
     */
    private static function wouldImageLikelyExceedMemory(int $width, int $height, int $multiplier = 8): bool
    {
        if ($width < 1 || $height < 1)
        {
            return true;
        }

        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === false || $memoryLimit === '' || $memoryLimit === '-1')
        {
            return false;
        }

        $limitBytes = self::convertPhpSizeToBytes($memoryLimit);
        if ($limitBytes <= 0)
        {
            return false;
        }

        $estimatedBytes = $width * $height * 4 * $multiplier;
        $currentUsage = memory_get_usage(true);

        return ($currentUsage + $estimatedBytes) >= (int)($limitBytes * 0.85);
    }

    /**
     * Validate uploaded image dimensions before expensive processing occurs.
     *
     * @param array $imageInfo Result from getimagesize()
     * @param array $config Application configuration
     * @return string|null Validation error message or null when valid
     */
    private static function validateUploadedImageDimensions(array $imageInfo, array $config): ?string
    {
        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);

        $maxWidth = (int)($config['gallery']['max_width'] ?? 8000);
        $maxHeight = (int)($config['gallery']['max_height'] ?? 8000);
        $maxPixels = (int)($config['gallery']['max_pixels'] ?? 40000000);

        if ($width < 1 || $height < 1)
        {
            return 'Invalid image dimensions.';
        }

        if ($width > $maxWidth || $height > $maxHeight)
        {
            return "Image dimensions exceed the maximum allowed size of {$maxWidth}x{$maxHeight}.";
        }

        if (($width * $height) > $maxPixels)
        {
            return 'Image resolution is too large to process safely.';
        }

        if (self::wouldImageLikelyExceedMemory($width, $height))
        {
            return 'Image is too large to process safely on this server.';
        }

        return null;
    }

    /**
     * Log details of an upload attempt into the database.
     *
     * Captures:
     * - Original filename and detected extension
     * - Reported MIME from the browser (untrusted) and detected MIME via finfo
     * - File size and IP address
     * - Final stored path when successful (or null when failed)
     * - Failure reason(s) when validation fails
     *
     * This creates an audit trail that is useful for debugging user issues,
     * detecting abuse patterns, and reviewing repeated malicious attempts.
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

        ImageModel::logUploadAttempt([
            ':user_id'           => $userId,
            ':ip_address'        => $ip,
            ':filename_original' => $originalName,
            ':mime_reported'     => $mimeReported,
            ':mime_detected'     => $mimeDetected,
            ':file_extension'    => $extension,
            ':file_size'         => $size,
            ':status'            => $status,
            ':failure_reason'    => $failureReason,
            ':stored_path'       => $storedPath,
        ]);
    }

    /**
     * Handle image upload request.
     * - Validates file type and size
     * - Generates perceptual hashes
     * - Detects near-duplicates
     * - Stores metadata in database
     *
     * Expected behavior:
     * - GET: Render upload form with max size and CSRF token
     * - POST: Validate upload and store image + metadata/hashes on success
     *
     * Security considerations:
     * - Requires authentication (RoleHelper::requireLogin())
     * - CSRF token validation
     * - Enforces max upload size and storage capacity constraints
     * - Validates MIME via finfo and final extension allowlist
     * - Ensures uploaded file is a real image via getimagesize()
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
        GroupPermissionHelper::requirePermission('upload_images');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && RequestGuard::isInteractiveActionRateLimited('upload'))
        {
            http_response_code(429);
            $errors[] = 'Too many upload attempts. Please wait and try again.';
        }

        // Initialize template engine with caching support
        $template = self::initTemplate();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']))
        {
            $file = $_FILES['image'];
            $description = Security::sanitizeString($_POST['description'] ?? '');
            $userId = TypeHelper::toInt(SessionManager::get('user_id'));

            // Verify CSRF token to prevent cross-site request forgery
            $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid request.";
            }

            if ($file['error'] !== UPLOAD_ERR_OK)
            {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the server upload_max_filesize limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form MAX_FILE_SIZE limit.',
                    UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
                ];

                $uploadErrorsUser = [
                    UPLOAD_ERR_INI_SIZE   => 'This file is too large. Please choose a smaller image.',
                    UPLOAD_ERR_FORM_SIZE  => 'This file is too large. Please choose a smaller image.',
                    UPLOAD_ERR_PARTIAL    => 'The upload did not complete. Please try again.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded. Please choose an image.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Upload is temporarily unavailable. Please try again later.',
                    UPLOAD_ERR_CANT_WRITE => 'Upload is temporarily unavailable. Please try again later.',
                    UPLOAD_ERR_EXTENSION  => 'Upload is temporarily unavailable. Please try again later.'
                ];

                $errors[] = $uploadErrorsUser[$file['error']] ?? "Upload failed. Try again later!";

                // Keep the detailed reason for the database log, without exposing it to the user
                $errorsDetailed = [$uploadErrors[$file['error']] ?? 'Unknown upload error.'];
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

                $mimeType = false;

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo)
                {
                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                }

                // Some modified/optimized images may be detected as octet-stream or fail finfo detection.
                // Fall back to getimagesize() (image parser) which usually returns a reliable image MIME.
                $imgInfo = @getimagesize($file['tmp_name']);

                if (empty($mimeType) || $mimeType === 'application/octet-stream')
                {
                    if (!empty($imgInfo['mime']))
                    {
                        $mimeType = $imgInfo['mime'];
                    }
                }

                // Last resort fallback (less strict), keeps behavior predictable if the server lacks proper magic DB.
                if (empty($mimeType) || $mimeType === 'application/octet-stream')
                {
                    $mimeType = @mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'unknown');
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

                // Block dangerous/exec extensions regardless of MIME (defense-in-depth)
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, self::$blockedExtensions))
                {
                    $errors[] = "File extension .$ext is not allowed.";
                }

                // Prefer extension derived from detected MIME to avoid spoofed filenames
                $safeExt = $mimeToExt[$mimeType] ?? $ext;

                // Ensure the final extension is one of the allowed types
                if (!in_array($safeExt, array_values($mimeToExt), true))
                {
                    $errors[] = "File extension .$safeExt is not allowed.";
                }

                // Verify the file signature matches the detected image type (blocks common spoofing/polyglots)
                if (empty($errors) && !self::hasValidImageSignature($file['tmp_name'], $mimeType))
                {
                    $errors[] = "The file is not a valid image.";
                }

                // Validate image dimensions and processing cost before moving or hashing the file
                if (empty($errors))
                {
                    if (!$imgInfo)
                    {
                        $errors[] = "The file is not a valid image.";
                    }
                    else
                    {
                        $dimensionError = self::validateUploadedImageDimensions($imgInfo, $config);
                        if ($dimensionError !== null)
                        {
                            $errors[] = $dimensionError;
                        }
                    }
                }
            }

            if (!empty($errors))
            {
                // Record failed attempts for auditing/troubleshooting
                self::logUploadAttempt($userId, $file, 'failed', $errorsDetailed ?? $errors);
            }

            if (empty($errors))
            {
                // Persist the upload using a randomized basename to avoid collisions and predictability
                $ext = $safeExt;
                $basename = 'img_' . bin2hex(random_bytes(16));
                $originalPath = "images/original/" . $basename . "." . $ext;

                // Move to a temporary location first (safer lifecycle before final resize/store)
                $tmpPath = self::$uploadDir . "tmp_" . $basename . "." . $ext;

                // Ensure base upload directory exists and is writable
                if (!is_dir(self::$uploadDir) || !is_writable(self::$uploadDir))
                {
                    $errors[] = "Upload failed. Upload directory is not writable.";
                }

                // Ensure destination directory exists (images/original/)
                $finalPath = self::$uploadDir . str_replace("images/", "", $originalPath);
                $finalDir = dirname($finalPath);
                if (empty($errors) && (!is_dir($finalDir)))
                {
                    if (!@mkdir($finalDir, 0755, true) && !is_dir($finalDir))
                    {
                        $errors[] = "Upload failed. Could not create destination directory.";
                    }
                }

                if (empty($errors) && !move_uploaded_file($file['tmp_name'], $tmpPath))
                {
                    $errors[] = "Upload failed. Could not move uploaded file.";
                }

                // Create final resized copy and store in uploads path
                if (empty($errors))
                {
                    self::makeResized($tmpPath, $originalPath, 1280, 1280);

                    // Ensure the resize actually produced the destination file
                    if (!file_exists($finalPath) || filesize($finalPath) <= 0)
                    {
                        $errors[] = "Upload failed. Image processing failed.";
                    }
                }

                // Remove temporary file after resize attempt
                if (file_exists($tmpPath))
                {
                    unlink($tmpPath);
                }

                if (!empty($errors))
                {
                    // Record failed attempts for auditing/troubleshooting
                    self::logUploadAttempt($userId, $file, 'failed', $errors);
                }
                else
                {
                    // Read stored file contents for integrity hashes
                    $fileData = file_get_contents($finalPath);
                    $md5 = md5($fileData);
                    $sha1 = sha1($fileData);
                    $sha256 = hash("sha256", $fileData);
                    $sha512 = hash("sha512", $fileData);

                    // Extract image metadata from the stored file
                    [$width, $height] = getimagesize($finalPath);
                    $sizeBytes = filesize($finalPath);
                    $mimeType = mime_content_type($finalPath);

                    // Generate a unique, user-facing image identifier
                    $imageHash = self::generateImageHashFormatted();


                    // Use updated safe hash functions
                    $fullPath = $finalPath;
                    $phash = HashingHelper::pHash($fullPath, 32, 16);
                    $ahash = HashingHelper::aHash($fullPath);
                    $dhash = HashingHelper::dHash($fullPath);

                    // Split phash into 16 blocks of 4 chars each (supports faster similarity querying)
                    $phashBlocks = [];
                    for ($i = 0; $i < 16; $i++)
                    {
                        $phashBlocks[$i] = substr($phash, $i * 4, 4);
                    }

                    // Insert MD5/SHA hashes into app_images
                    ImageModel::createUploadedImage([
                        ':image_hash'   => $imageHash,
                        ':user_id'      => $userId,
                        ':description'  => $description,
                        ':status'       => $config['debugging']['allow_approve_uploads'] ? 'approved' : 'pending',
                        ':original_path'=> $originalPath,
                        ':mime_type'    => $mimeType,
                        ':width'        => $width,
                        ':height'       => $height,
                        ':size_bytes'   => $sizeBytes,
                        ':md5'          => $md5,
                        ':sha1'         => $sha1,
                        ':sha256'       => $sha256,
                        ':sha512'       => $sha512,
                    ], (bool)$config['debugging']['allow_approve_uploads']);

                    // Insert into app_image_hashes
                    ImageModel::createImageHashes(array_merge([
                        ':image_hash' => $imageHash,
                        ':phash'      => $phash,
                        ':ahash'      => $ahash,
                        ':dhash'      => $dhash,
                    ], array_combine(array_map(fn($i) => ":phash_block_$i", range(0, 15)), $phashBlocks)));

                    $success = "Uploaded successfully! Image pending approval!";
                    self::logUploadAttempt($userId, $file, 'success');
                }
            }
        }

        // Provide page state to the view (errors, success text, and upload limits)
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
     * Implementation notes:
     * - Generates a small batch of candidates per loop, then checks all at once
     *   to reduce repeated round trips to the database.
     * - Uses cryptographically secure randomness (random_int) for each character.
     * - Alternates per-group balance to avoid generating overly digit-heavy hashes.
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
            $existing = ImageModel::findExistingHashes($hashes);
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
     * Delegates the heavy lifting to ImageHelper::resize(), which centralizes
     * the resizing implementation so upload handling stays focused on workflow.
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
        $destPath = self::$uploadDir . str_replace("images/", "", $dest);

        // Use the new ImageHelper class to handle resizing
        ImageHelper::resize($srcPath, $destPath, $maxWidth, $maxHeight);
    }

    /**
     * Read the first N bytes of a file for signature checks.
     *
     * @param string $path
     * @param int $length
     * @return string
     */
    private static function readFileHead($path, $length = 64)
    {
        $fh = @fopen($path, 'rb');
        if (!$fh)
        {
            return '';
        }

        $data = fread($fh, $length);
        fclose($fh);

        return $data !== false ? $data : '';
    }

    /**
     * Validates the uploaded payload signature against the detected MIME type.
     *
     * Blocks common executable/script formats regardless of extension/MIME spoofing,
     * and ensures the header bytes match the expected image container.
     *
     * @param string $tmpPath
     * @param string $mimeType
     * @return bool
     */
    private static function hasValidImageSignature($tmpPath, $mimeType)
    {
        $head = self::readFileHead($tmpPath, 64);
        if ($head === '')
        {
            return false;
        }

        // Block obvious executable / script containers (defense-in-depth)
        if (strncmp($head, "MZ", 2) === 0) // Windows PE/EXE
        {
            return false;
        }

        if (strncmp($head, "\x7F" . "ELF", 4) === 0) // Linux ELF
        {
            return false;
        }

        if (strncmp($head, "#!", 2) === 0) // Shebang scripts
        {
            return false;
        }

        if (strncmp($head, "PK\x03\x04", 4) === 0) // ZIP (often used for polyglots)
        {
            return false;
        }

        // Validate expected image container signatures
        switch ($mimeType)
        {
            case 'image/jpeg':
                return (strncmp($head, "\xFF\xD8\xFF", 3) === 0);

            case 'image/png':
                return (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0);

            case 'image/gif':
                return (strncmp($head, "GIF87a", 6) === 0 || strncmp($head, "GIF89a", 6) === 0);

            case 'image/webp':
                // WebP is RIFF....WEBP
                return (strncmp($head, "RIFF", 4) === 0 && substr($head, 8, 4) === "WEBP");

            default:
                return false;
        }
    }
}
