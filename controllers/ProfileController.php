<?php

class ProfileController
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
            self::$config = SettingsManager::isInitialized() ? SettingsManager::getConfig() : (require __DIR__ . '/../config/config.php');
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
     * Main profile overview page.
     *
     * Ensures the user is logged in, fetches profile details from the database,
     * and renders the profile overview template.
     *
     * @return void
     */
    public static function index(): void
    {
        // Require login
        RoleHelper::requireLogin();
    
        // Fetch current user data from database
        $userId = TypeHelper::toInt(SessionManager::get('user_id'));
        $user = Database::fetch(
            "SELECT
                u.id,
                u.role_id,
                u.username,
                u.email,
                u.avatar_path,
                u.date_of_birth,
                u.status,
                u.last_login,
                u.created_at,
                COALESCE(f.favorite_count, 0) AS favorite_count,
                COALESCE(v.vote_count, 0) AS vote_count
             FROM app_users u
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS favorite_count
                  FROM app_image_favorites
                 GROUP BY user_id
             ) f ON u.id = f.user_id
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS vote_count
                  FROM app_image_votes
                 GROUP BY user_id
             ) v ON u.id = v.user_id
             WHERE u.id = :id LIMIT 1",
            ['id' => $userId]
        );
    
        $uploadedImages = Database::fetch(
            "SELECT COUNT(*) AS count FROM app_images WHERE user_id = :id AND status = 'approved'",
            ['id' => $userId]
        );
    
        // Initialize template engine with caching support
        $template = self::initTemplate();
    
        // Assign user data to template variables
        $template->assign('avatar_path', $user['avatar_path']);
        $template->assign('username', ucfirst($user['username']));
        $template->assign('account_role', RoleHelper::getRoleNameById($user['role_id']));
        $template->assign('account_status', ucfirst($user['status']));
        $template->assign('email', $user['email']);
        $template->assign('last_login', DateHelper::format($user['last_login']));
        $template->assign('date_of_birth', DateHelper::birthday_format($user['date_of_birth']) ?? 'Not set');
        $template->assign('registered_date', DateHelper::format($user['created_at']));
        $template->assign('user_image_count', NumericalHelper::formatCount($uploadedImages['count']));
        $template->assign('user_favorite_count', NumericalHelper::formatCount($user['favorite_count']));
        $template->assign('user_vote_count', NumericalHelper::formatCount($user['vote_count']));
    
        // Render profile overview template
        $template->render('profile/profile_overview.html');
    }    

    /**
     * Avatar update page.
     *
     * Forwards to handleSingleUpdate() with type 'avatar'.
     *
     * @return void
     */
    public static function avatar(): void
    {
        self::handleSingleUpdate('avatar');
    }

    /**
     * Email update page.
     *
     * Forwards to handleSingleUpdate() with type 'email'.
     *
     * @return void
     */
    public static function email(): void
    {
        self::handleSingleUpdate('email');
    }

    /**
     * Date of birth update page.
     *
     * Forwards to handleSingleUpdate() with type 'dob'.
     *
     * @return void
     */
    public static function dob(): void
    {
        self::handleSingleUpdate('dob');
    }

    /**
     * Change password page.
     *
     * Forwards to handleSingleUpdate() with type 'change_password'.
     *
     * @return void
     */
    public static function change_password(): void
    {
        self::handleSingleUpdate('change_password');
    }

    /**
     * Private helper to handle single-field profile updates.
     *
     * Handles updating avatar, email, date of birth, or password depending on $type.
     * Includes form validation, file handling, resizing, and database updates.
     *
     * @param string $type The type of update (email, dob, avatar, change_password).
     * @return void
     */
    private static function handleSingleUpdate(string $type): void
    {
        $errors = [];
        $success = '';

        $config = self::getConfig();

        // Fetch user record
        $userId = TypeHelper::toInt(SessionManager::get('user_id'));
        if (!$userId)
        {
            $errors[] = "User not found.";
        }

        $user = Database::fetch("SELECT id, username, display_name, email, avatar_path, date_of_birth, password_hash, age_verified_at FROM app_users WHERE id = :id LIMIT 1",
            ['id' => $userId]
        );

        if (!$user)
        {
            $errors[] = "User not found.";
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrfToken = $_POST['csrf_token'] ?? '';

            // Verify CSRF token to prevent cross-site request forgery
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid request.";
            }

            switch ($type)
            {
                case 'email':
                    // Validate and update email
                    $newEmail = Security::sanitizeEmail($_POST['email'] ?? '');
                    if (!$newEmail)
                    {
                        $errors[] = "Invalid email address.";
                    }
                    else
                    {
                        Database::query(
                            "UPDATE app_users SET email = :email WHERE id = :id",
                            ['email' => $newEmail, 'id' => $userId]
                        );

                        $success = "Email updated successfully.";
                        $user['email'] = $newEmail;
                    }
                    break;

                case 'dob':
                    // Validate and update date of birth
                    $dobInput = $_POST['date_of_birth'] ?? '';

                    // YYYY-MM-DD, must be a real calendar date, and not in the future
                    $dob = Security::sanitizeDate($dobInput, 'Y-m-d', '1900-01-01',
                        (new DateTimeImmutable('now'))->format('Y-m-d')
                    );

                    if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob))
                    {
                        $errors[] = "Invalid date format. Use YYYY-MM-DD.";
                    }
                    else
                    {
                        Database::query(
                            "UPDATE app_users SET date_of_birth = :dob, age_verified_at = NOW() WHERE id = :id",
                            ['dob' => $dob, 'id' => $userId]
                        );

                        $success = "Date of birth updated successfully.";
                        $user['date_of_birth'] = $dob;
                    }
                    break;

                case 'avatar':
                    // Handle avatar upload and validation
                    if (isset($_FILES['avatar']))
                    {
                        $avatar = $_FILES['avatar'];
                        if ($avatar['error'] === UPLOAD_ERR_OK)
                        {
                            $ext = strtolower(pathinfo($avatar['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg','jpeg','png','gif'];

                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mimeType = $finfo ? finfo_file($finfo, $avatar['tmp_name']) : '';
                            if ($finfo)
                            {
                                finfo_close($finfo);
                            }

                            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
                            if (!in_array($mimeType, $allowedMimes, true))
                            {
                                $errors[] = "Invalid file type. Only JPG, PNG, GIF allowed.";
                            }

                            if (!in_array($ext, $allowed, true))
                            {
                                $errors[] = "Invalid file type. Only JPG, PNG, GIF allowed.";
                            }
                            else
                            {
                                $size = getimagesize($avatar['tmp_name']);
                                if (!$size)
                                {
                                    $errors[] = "Uploaded file is not a valid image.";
                                }
                                else if ($size[0] !== $size[1])
                                {
                                    $errors[] = "Avatar must be a square image (width and height must match).";
                                }
                                else
                                {
                                    $avatar_size = $config['profile']['avatar_size'];
                                    $resized = false;

                                    // Resize if uploaded image is not the correct size
                                    if ($size[0] != $avatar_size)
                                    {
                                        $resized = true;

                                        // Create image resource based on extension
                                        switch ($ext)
                                        {
                                            case 'jpg':
                                            case 'jpeg':
                                                $srcImg = @imagecreatefromjpeg($avatar['tmp_name']);
                                                break;

                                            case 'png':
                                                $srcImg = @imagecreatefrompng($avatar['tmp_name']);
                                                break;

                                            case 'gif':
                                                $srcImg = @imagecreatefromgif($avatar['tmp_name']);
                                                break;
                                        }

                                        if (!$srcImg)
                                        {
                                            $errors[] = "Failed to process uploaded image.";
                                            break;
                                        }

                                        $dstImg = imagecreatetruecolor($avatar_size, $avatar_size);

                                        // Preserve transparency for PNG and GIF
                                        if ($ext === 'png' || $ext === 'gif')
                                        {
                                            imagecolortransparent($dstImg, imagecolorallocatealpha($dstImg, 0, 0, 0, 127));
                                            imagealphablending($dstImg, false);
                                            imagesavealpha($dstImg, true);
                                        }

                                        // Resample image to desired size
                                        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $avatar_size, $avatar_size, $size[0], $size[1]);

                                        // Save temporary resized image
                                        $tmpPath = sys_get_temp_dir() . '/' . uniqid('avatar_') . '.' . $ext;
                                        switch ($ext)
                                        {
                                            case 'jpg':
                                            case 'jpeg':
                                                imagejpeg($dstImg, $tmpPath, 90);
                                                break;
                                            case 'png':
                                                imagepng($dstImg, $tmpPath);
                                                break;
                                            case 'gif':
                                                imagegif($dstImg, $tmpPath);
                                                break;
                                        }

                                        // Free memory
                                        imagedestroy($srcImg);
                                        imagedestroy($dstImg);

                                        // Replace tmp_name with resized file
                                        $avatar['tmp_name'] = $tmpPath;
                                    }

                                    // Save avatar to permanent location
                                    $filename = uniqid('avatar_') . '.' . $ext;
                                    $dest = __DIR__ . '/../uploads/avatars/' . $filename;
                                    if (!is_dir(__DIR__ . '/../uploads/avatars/'))
                                    {
                                        mkdir(__DIR__ . '/../uploads/avatars/', 0750, true);
                                    }

                                    // Copy resized image or move original upload
                                    if ($resized)
                                    {
                                        if (!copy($avatar['tmp_name'], $dest) || !@unlink($avatar['tmp_name']))
                                        {
                                            $errors[] = "Failed to upload avatar.";
                                        }
                                    }
                                    else
                                    {
                                        if (!move_uploaded_file($avatar['tmp_name'], $dest))
                                        {
                                            $errors[] = "Failed to upload avatar.";
                                        }
                                    }

                                    // Remove old avatar if it exists
                                    if (empty($errors) && !empty($user['avatar_path'])
                                        && strpos($user['avatar_path'], '/uploads/avatars/') === 0)
                                    {
                                        $oldAvatar = __DIR__ . '/../' . ltrim($user['avatar_path'], '/');
                                        if (file_exists($oldAvatar))
                                        {
                                            if (!@unlink($oldAvatar))
                                            {
                                                $errors[] = "Warning: failed to remove old avatar.";
                                            }
                                        }

                                        // Update DB with new avatar path
                                        Database::query(
                                            "UPDATE app_users SET avatar_path = :path WHERE id = :id",
                                            ['path' => '/uploads/avatars/' . $filename, 'id' => $userId]
                                        );

                                        $success = "Avatar updated successfully.";
                                        $user['avatar_path'] = '/uploads/avatars/' . $filename;
                                    }
                                }
                            }
                        }
                        else
                        {
                            $errors[] = "Error uploading avatar.";
                        }
                    }
                    break;

                case 'change_password':
                    // Handle password change
                    $currentPassword = $_POST['current_password'] ?? '';
                    $newPassword = $_POST['new_password'] ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';

                    // Verify current password
                    if (!$currentPassword || !Security::verifyPassword($currentPassword, $user['password_hash']))
                    {
                        $errors[] = "Current password is incorrect.";
                    }

                    // Validate new password
                    if (!$newPassword || strlen($newPassword) < 8)
                    {
                        $errors[] = "New password must be at least 8 characters long.";
                    }
                    else if ($newPassword !== $confirmPassword)
                    {
                        $errors[] = "New password and confirmation do not match.";
                    }

                    if ($currentPassword === $newPassword)
                    {
                        $errors[] = "Your new password cannot be the same as your current one.";
                    }

                    // If all validations pass, update password
                    if (empty($errors))
                    {
                        $hashedPassword = Security::hashPassword($newPassword);
                        Database::query(
                            "UPDATE app_users SET password_hash = :hash WHERE id = :id",
                            ['hash' => $hashedPassword, 'id' => $userId]
                        );

                        $success = "Password updated successfully.";

                        // Regenerate session after password change
                        SessionManager::regenerate();
                    }
                    break;
            }
        }

        // Initialize template engine
        $template = self::initTemplate();

        // Assign template variables
        $template->assign('avatar_size', $config['profile']['avatar_size']);
        $template->assign('email', $user['email']);
        $template->assign('avatar_path', $user['avatar_path']);
        $template->assign('date_of_birth', $user['date_of_birth'] ?? '');
        $template->assign('user', $user);
        $template->assign('error', $errors);
        $template->assign('success', $success);

        // Render update template based on update type
        $template->render("profile/profile_{$type}.html");
    }
}
