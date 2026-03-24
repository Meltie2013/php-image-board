<?php

/**
 * Controller responsible for rendering and updating authenticated user profile pages.
 *
 * Responsibilities:
 * - Render profile overview data
 * - Update avatar, email address, and date of birth
 * - Handle password changes with current-password verification
 *
 * Security considerations:
 * - Login required for all actions
 * - CSRF protection for state-changing form submissions
 * - Uploaded avatars are validated before storage
 */
class ProfileController extends BaseController
{
    /**
     * Static template variables assigned for all profile templates.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_profile_page' => 1,
    ];

    /**
     * Profile templates require a CSRF token for account actions.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Assign shared profile page metadata used by the sidebar and page header.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param string $nav Current navigation key for sidebar highlighting.
     * @param string $title Current page title.
     * @param string $description Current page summary/description.
     * @param string $section Current sidebar section key.
     * @return void
     */
    private static function assignProfilePage(TemplateEngine $template, string $nav, string $title, string $description, string $section = ''): void
    {
        $template->assign('current_profile_nav', $nav);
        $template->assign('current_profile_section', $section);
        $template->assign('profile_page_title', $title);
        $template->assign('profile_page_description', $description);
    }

    /**
     * Assign shared profile shell data used by the sidebar and overview hero.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array $user Current user record.
     * @return void
     */
    private static function assignProfileChrome(TemplateEngine $template, array $user): void
    {
        $username = TypeHelper::toString($user['username'] ?? '') ?? '';
        $displayName = trim(TypeHelper::toString($user['display_name'] ?? '') ?? '');
        if ($displayName === '')
        {
            $displayName = ucfirst($username);
        }

        $status = ucfirst(TypeHelper::toString($user['status'] ?? 'active') ?? 'Active');

        $groupId = TypeHelper::toInt($user['group_id'] ?? null);
        $roleName = null;

        if ($groupId !== null && $groupId > 0)
        {
            $roleName = RoleHelper::getRoleNameById($groupId);
        }

        $ageGateStatus = AgeGateHelper::getUserAgeGateStatus($user);

        $template->assign('profile_username', ucfirst($username));
        $template->assign('profile_display_name', $displayName);
        $template->assign('profile_avatar_path', $user['avatar_path'] ?? '');
        $template->assign('profile_status', $status);
        $template->assign('profile_role_name', $roleName ?: 'Member');
        $template->assign('profile_email', $user['email'] ?? '');
        $template->assign('profile_age_status', AgeGateHelper::getAgeGateStatusLabel($ageGateStatus));
        $template->assign('profile_member_since', !empty($user['created_at']) ? DateHelper::format($user['created_at']) : 'Unknown');
        $template->assign('profile_has_birthday_badge', AgeGateHelper::shouldShowBirthdayBadge($user['date_of_birth'] ?? null, self::getConfig()) ? 1 : 0);
    }

    /**
     * Validate uploaded avatar dimensions and processing cost before decoding.
     *
     * @param array $imageInfo Result from getimagesize()
     * @param array $config Application configuration
     * @return string|null Validation error message or null when valid
     */
    private static function validateAvatarImage(array $imageInfo, array $config): ?string
    {
        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        $maxPixels = (int)($config['profile']['avatar_max_pixels'] ?? 16000000);

        if ($width < 1 || $height < 1)
        {
            return 'Invalid avatar dimensions.';
        }

        if ($width !== $height)
        {
            return 'Avatar must be a square image (width and height must match).';
        }

        if (($width * $height) > $maxPixels)
        {
            return 'Avatar resolution is too large to process safely.';
        }

        return null;
    }

    /**
     * Validate the uploaded payload signature for common avatar image types.
     *
     * @param string $tmpPath Temporary uploaded file path
     * @param string $mimeType Detected MIME type
     * @return bool True when the signature matches the expected container
     */
    private static function hasValidAvatarSignature(string $tmpPath, string $mimeType): bool
    {
        $handle = @fopen($tmpPath, 'rb');
        if (!$handle)
        {
            return false;
        }

        $header = fread($handle, 12);
        fclose($handle);

        if (!is_string($header) || $header === '')
        {
            return false;
        }

        switch ($mimeType)
        {
            case 'image/jpeg':
                return strlen($header) >= 3 && strncmp($header, "\xFF\xD8\xFF", 3) === 0;

            case 'image/png':
                return strlen($header) >= 8 && strncmp($header, "\x89PNG\r\n\x1A\n", 8) === 0;

            case 'image/gif':
                return strlen($header) >= 6
                    && (strncmp($header, 'GIF87a', 6) === 0 || strncmp($header, 'GIF89a', 6) === 0);

            default:
                return false;
        }
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
        $user = UserModel::findProfileOverviewById($userId);
        $uploadedImages = [
            'count' => UserModel::countApprovedImagesByUserId($userId),
        ];

        // Initialize template engine with caching support
        $template = self::initTemplate();
        self::assignProfileChrome($template, $user ?: []);
        self::assignProfilePage(
            $template,
            'overview',
            'Profile Overview',
            'A summary of your account identity, activity, and visibility across the image board.',
            'account'
        );

        $displayName = trim(TypeHelper::toString($user['display_name'] ?? '') ?? '');
        if ($displayName === '')
        {
            $displayName = ucfirst(TypeHelper::toString($user['username'] ?? '') ?? '');
        }

        $accountStatus = ucfirst(TypeHelper::toString($user['status'] ?? 'active') ?? 'Active');

        // Assign user data to template variables
        $template->assign('avatar_path', $user['avatar_path']);
        $template->assign('username', ucfirst($user['username']));
        $template->assign('display_name', $displayName);
        $template->assign('account_role', RoleHelper::getRoleNameById(TypeHelper::toInt($user['group_id'] ?? 0) ?? 0));
        $template->assign('account_status', $accountStatus);
        $template->assign('account_standing', strtolower($accountStatus) === 'active' ? 'In good standing' : $accountStatus);
        $template->assign('age_verification_status', AgeGateHelper::getAgeGateStatusLabel(AgeGateHelper::getUserAgeGateStatus($user)));
        $template->assign('email', $user['email']);
        $template->assign('last_login', DateHelper::format($user['last_login']));
        $template->assign('date_of_birth', DateHelper::birthday_format($user['date_of_birth']) ?? 'Not set');
        $template->assign('registered_date', DateHelper::format($user['created_at']));
        $template->assign('user_image_count', NumericalHelper::formatCount($uploadedImages['count']));
        $template->assign('user_favorite_count', NumericalHelper::formatCount($user['favorite_count']));
        $template->assign('user_vote_count', NumericalHelper::formatCount($user['vote_count']));
        $template->assign('has_birthday_badge', AgeGateHelper::shouldShowBirthdayBadge($user['date_of_birth'] ?? null, self::getConfig()) ? 1 : 0);

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
        self::handleAgeGateUpdate();
    }

    /**
     * Age gate and content access page.
     *
     * Provides:
     * - A self-serve sensitive-content unlock for normal accounts
     * - An optional DOB verification path for explicit-content access
     * - A forced-review DOB flow when staff locks the account for review
     *
     * @return void
     */
    private static function handleAgeGateUpdate(): void
    {
        RoleHelper::requireLogin();

        $errors = [];
        $success = '';
        $config = self::getConfig();

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId < 1)
        {
            RedirectHelper::rememberLoginDestination();
            header('Location: /user/login');
            exit();
        }

        $user = UserModel::findProfileSettingsUserById($userId);
        if (!$user)
        {
            SessionManager::destroy();
            RedirectHelper::rememberLoginDestination();
            header('Location: /user/login');
            exit();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = 'Invalid request.';
            }
            else
            {
                $action = Security::sanitizeString($_POST['age_gate_action'] ?? '');
                $status = AgeGateHelper::getUserAgeGateStatus($user);

                switch ($action)
                {
                    case 'self_serve_unlock':
                        if (!AgeGateHelper::isEnabled($config))
                        {
                            $errors[] = 'The board age gate is currently disabled by the site configuration.';
                        }
                        else if (!AgeGateHelper::isSelfServeEnabled($config))
                        {
                            $errors[] = 'Self-serve mature-content access is currently disabled on this board.';
                        }
                        else if (!empty($_POST['mature_access_acknowledge']))
                        {
                            if (in_array($status, ['forced_review', 'restricted_minor'], true))
                            {
                                $errors[] = 'This account cannot use self-serve access while a staff restriction is active.';
                            }
                            else
                            {
                                UserModel::markSelfServeAgeGate($userId);
                                $success = 'Sensitive-content access has been enabled for your account.';
                                $user = UserModel::findProfileSettingsUserById($userId) ?: $user;
                            }
                        }
                        else
                        {
                            $errors[] = 'Please confirm that you understand the board content access notice before continuing.';
                        }
                        break;

                    case 'verify_date_of_birth':
                        $dobInput = $_POST['date_of_birth'] ?? '';
                        $dob = Security::sanitizeDate(
                            $dobInput,
                            'Y-m-d',
                            '1900-01-01',
                            (new DateTimeImmutable('now'))->format('Y-m-d')
                        );

                        if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob))
                        {
                            $errors[] = 'Invalid date format. Use YYYY-MM-DD.';
                        }

                        if (empty($_POST['mature_access_acknowledge']))
                        {
                            $errors[] = 'Please confirm that you understand the board content access notice before continuing.';
                        }

                        if ($status !== 'forced_review' && !empty($user['date_of_birth']))
                        {
                            $errors[] = 'Your date of birth is already on file. Contact staff if you need this record reviewed.';
                        }

                        if (empty($errors))
                        {
                            $isRestrictedMinor = !AgeGateHelper::isDateOfBirthOldEnough($dob, AgeGateHelper::getSensitiveYears($config));

                            if ($status === 'forced_review')
                            {
                                UserModel::updateDobForForcedReview($userId, $dob, $isRestrictedMinor);
                                $success = $isRestrictedMinor
                                    ? 'This account does not meet the board minimum age requirement for mature content.'
                                    : 'Your staff-requested age review has been completed.';
                            }
                            else
                            {
                                UserModel::updateDobForOptionalVerification($userId, $dob, $isRestrictedMinor);
                                $success = $isRestrictedMinor
                                    ? 'This account does not meet the board minimum age requirement for mature content.'
                                    : 'Date of birth verification has been saved for your account.';
                            }

                            $user = UserModel::findProfileSettingsUserById($userId) ?: $user;
                            SessionManager::set('user_date_of_birth', $user['date_of_birth'] ?? null);
                        }
                        break;

                    default:
                        $errors[] = 'Invalid request.';
                        break;
                }
            }
        }

        $template = self::initTemplate();
        self::assignProfileChrome($template, $user ?: []);
        self::assignProfilePage(
            $template,
            'dob',
            'Content Access',
            'Control how your account handles sensitive and explicit content while keeping the process friendlier for normal members and available for staff review when needed.',
            'security'
        );

        $ageGateStatus = AgeGateHelper::getUserAgeGateStatus($user);
        $viewerAccessLevel = AgeGateHelper::getViewerContentAccessLevel($user, $config);

        $template->assign('date_of_birth', $user['date_of_birth'] ?? '');
        $template->assign('date_of_birth_format', DateHelper::birthday_format($user['date_of_birth']) ?? 'Not set');
        $template->assign('age_gate_enabled', AgeGateHelper::isEnabled($config) ? 1 : 0);
        $template->assign('age_gate_self_serve_enabled', AgeGateHelper::isSelfServeEnabled($config) ? 1 : 0);
        $template->assign('age_gate_status', $ageGateStatus);
        $template->assign('age_gate_status_label', AgeGateHelper::getAgeGateStatusLabel($ageGateStatus));
        $template->assign('age_gate_status_tone', AgeGateHelper::getAgeGateStatusTone($ageGateStatus));
        $template->assign('age_gate_sensitive_years', AgeGateHelper::getSensitiveYears($config));
        $template->assign('age_gate_explicit_years', AgeGateHelper::getExplicitYears($config));
        $template->assign('age_gate_access_sensitive', in_array($viewerAccessLevel, ['sensitive', 'explicit'], true) ? 1 : 0);
        $template->assign('age_gate_access_explicit', $viewerAccessLevel === 'explicit' ? 1 : 0);
        $template->assign('age_gate_force_reason', TypeHelper::toString($user['age_gate_force_reason'] ?? '', allowEmpty: true) ?? '');
        $template->assign('age_gate_forced_at', !empty($user['age_gate_forced_at']) ? DateHelper::format($user['age_gate_forced_at']) : '');
        $template->assign('age_gate_method', TypeHelper::toString($user['age_gate_method'] ?? '', allowEmpty: true) ?? 'none');
        $template->assign('age_gate_method_label', AgeGateHelper::getAgeGateMethodLabel(TypeHelper::toString($user['age_gate_method'] ?? '', allowEmpty: true) ?? 'none'));
        $template->assign('age_gate_acknowledged_at', !empty($user['mature_content_acknowledged_at']) ? DateHelper::format($user['mature_content_acknowledged_at']) : '');
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->assign('user', $user);
        $template->render('profile/profile_dob.html');
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

        $user = UserModel::findProfileSettingsUserById($userId);

        if (!$user)
        {
            SessionManager::destroy();
            RedirectHelper::rememberLoginDestination();
            header('Location: /user/login');
            exit();
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
                        UserModel::updateEmail($userId, $newEmail);

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
                        UserModel::updateDobAndVerify($userId, $dob);

                        $success = "Date of birth updated successfully.";
                        $user['date_of_birth'] = $dob;
                        $user['age_verified_at'] = date('Y-m-d H:i:s');
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
                            $maxAvatarMb = (int)($config['profile']['avatar_max_upload_size_mb'] ?? 5);
                            $maxAvatarBytes = $maxAvatarMb * 1024 * 1024;

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

                            if (($avatar['size'] ?? 0) > $maxAvatarBytes)
                            {
                                $errors[] = "Avatar exceeds maximum allowed size of {$maxAvatarMb} MB.";
                            }

                            if (empty($errors) && !self::hasValidAvatarSignature($avatar['tmp_name'], $mimeType))
                            {
                                $errors[] = "Uploaded file signature does not match the detected image type.";
                            }

                            $size = null;
                            if (empty($errors))
                            {
                                $size = @getimagesize($avatar['tmp_name']);
                                if (!$size)
                                {
                                    $errors[] = "Uploaded file is not a valid image.";
                                }
                                else
                                {
                                    $avatarValidationError = self::validateAvatarImage($size, $config);
                                    if ($avatarValidationError !== null)
                                    {
                                        $errors[] = $avatarValidationError;
                                    }
                                }
                            }

                            if (empty($errors) && is_array($size))
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

                                        default:
                                            $srcImg = false;
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
                                $dest = UPLOAD_PATH . '/avatars/' . $filename;
                                if (!is_dir(UPLOAD_PATH . '/avatars/'))
                                {
                                    mkdir(UPLOAD_PATH . '/avatars/', 0750, true);
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

                                if (empty($errors))
                                {
                                    // Remove old avatar if it exists
                                    if (!empty($user['avatar_path'])
                                        && strpos($user['avatar_path'], '/uploads/avatars/') === 0)
                                    {
                                        $oldAvatar = APP_ROOT . '/' . ltrim($user['avatar_path'], '/');
                                        if (file_exists($oldAvatar) && !@unlink($oldAvatar))
                                        {
                                            $errors[] = "Warning: failed to remove old avatar.";
                                        }
                                    }

                                    if (empty($errors))
                                    {
                                        // Update DB with new avatar path
                                        UserModel::updateAvatarPath($userId, '/uploads/avatars/' . $filename);

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
                        UserModel::updatePasswordHash($userId, $hashedPassword);

                        $success = "Password updated successfully.";

                        // Regenerate session after password change
                        SessionManager::regenerate();
                    }
                    break;
            }
        }

        // Initialize template engine
        $template = self::initTemplate();
        self::assignProfileChrome($template, $user ?: []);

        switch ($type)
        {
            case 'avatar':
                self::assignProfilePage(
                    $template,
                    'avatar',
                    'Profile Avatar',
                    'Refresh the identity badge used across your account pages with a square avatar that fits the board aesthetic.',
                    'account'
                );
                break;

            case 'email':
                self::assignProfilePage(
                    $template,
                    'email',
                    'Email Address',
                    'Keep your recovery and verification address current so future account notices always reach you.',
                    'account'
                );
                break;

            case 'dob':
                self::assignProfilePage(
                    $template,
                    'dob',
                    'Date of Birth',
                    'Manage age verification for sensitive content access while preserving a clear record inside your account center.',
                    'security'
                );
                break;

            case 'change_password':
            default:
                self::assignProfilePage(
                    $template,
                    'change_password',
                    'Password & Security',
                    'Update your credentials with a cleaner security workflow designed to keep the account area polished and trustworthy.',
                    'security'
                );
                break;
        }

        // Assign template variables
        $template->assign('avatar_size', $config['profile']['avatar_size']);
        $template->assign('email', $user['email']);
        $template->assign('avatar_path', $user['avatar_path']);
        $template->assign('date_of_birth', $user['date_of_birth'] ?? '');
        $template->assign('date_of_birth_format', DateHelper::birthday_format($user['date_of_birth']) ?? 'Not set');
        $template->assign('user', $user);
        $template->assign('error', $errors);
        $template->assign('success', $success);

        // Render update template based on update type
        $template->render("profile/profile_{$type}.html");
    }
}
