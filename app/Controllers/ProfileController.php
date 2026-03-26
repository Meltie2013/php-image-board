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
     * Resolve the current authenticated user id for profile flows.
     *
     * Forces login first, then hard-fails back to the login page when the
     * session no longer contains a usable numeric user id. This prevents
     * nullable session values from reaching strictly typed model methods.
     *
     * @return int
     */
    private static function requireAuthenticatedProfileUserId(): int
    {
        return self::requireAuthenticatedUserId(true);
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
        // Fetch current user data from database
        $userId = self::requireAuthenticatedProfileUserId();
        $user = UserModel::findProfileOverviewById($userId);
        if (!$user)
        {
            SessionManager::destroy();
            RedirectHelper::rememberLoginDestination();
            header('Location: /user/login');
            exit();
        }

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
     * Handle one self-serve mature-content unlock request.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @param array $config Application configuration.
     * @param string $status Current age-gate status.
     * @param array<int, string> $errors Collected validation errors.
     * @param string $success Success banner text.
     * @return void
     */
    private static function handleSelfServeAgeGateUnlock(int $userId, array &$user, array $config, string $status, array &$errors, string &$success): void
    {
        if (!AgeGateHelper::isEnabled($config))
        {
            $errors[] = 'The board age gate is currently disabled by the site configuration.';
            return;
        }

        if (!AgeGateHelper::isSelfServeEnabled($config))
        {
            $errors[] = 'Self-serve mature-content access is currently disabled on this board.';
            return;
        }

        if (empty($_POST['mature_access_acknowledge']))
        {
            $errors[] = 'Please confirm that you understand the board content access notice before continuing.';
            return;
        }

        if (in_array($status, ['forced_review', 'restricted_minor'], true))
        {
            $errors[] = 'This account cannot use self-serve access while a staff restriction is active.';
            return;
        }

        UserModel::markSelfServeAgeGate($userId);
        $success = 'Sensitive-content access has been enabled for your account.';
        $user = self::loadRequiredProfileSettingsUser($userId);
    }

    /**
     * Validate one posted date of birth for the age-gate workflow.
     *
     * @param array $user Current user record.
     * @param string $status Current age-gate status.
     * @param array<int, string> $errors Collected validation errors.
     * @return string|null Sanitized date of birth when valid.
     */
    private static function validateAgeGateDateOfBirth(array $user, string $status, array &$errors): ?string
    {
        $dob = Security::sanitizeDate(
            $_POST['date_of_birth'] ?? '',
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

        return empty($errors) ? $dob : null;
    }

    /**
     * Handle one date-of-birth verification request for the age gate.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @param array $config Application configuration.
     * @param string $status Current age-gate status.
     * @param array<int, string> $errors Collected validation errors.
     * @param string $success Success banner text.
     * @return void
     */
    private static function handleAgeGateDobVerification(int $userId, array &$user, array $config, string $status, array &$errors, string &$success): void
    {
        $dob = self::validateAgeGateDateOfBirth($user, $status, $errors);
        if ($dob === null)
        {
            return;
        }

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

        $user = self::loadRequiredProfileSettingsUser($userId);
        SessionManager::set('user_date_of_birth', $user['date_of_birth'] ?? null);
    }

    /**
     * Process one posted age-gate action.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @param array $config Application configuration.
     * @param array<int, string> $errors Collected validation errors.
     * @param string $success Success banner text.
     * @return void
     */
    private static function processAgeGateActionSubmission(int $userId, array &$user, array $config, array &$errors, string &$success): void
    {
        $action = Security::sanitizeString($_POST['age_gate_action'] ?? '');
        $status = AgeGateHelper::getUserAgeGateStatus($user);

        switch ($action)
        {
            case 'self_serve_unlock':
                self::handleSelfServeAgeGateUnlock($userId, $user, $config, $status, $errors, $success);
                break;

            case 'verify_date_of_birth':
                self::handleAgeGateDobVerification($userId, $user, $config, $status, $errors, $success);
                break;

            default:
                $errors[] = 'Invalid request.';
                break;
        }
    }

    /**
     * Assign the profile age-gate template state.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array $user Current user record.
     * @param array $config Application configuration.
     * @param array<int, string> $errors Validation errors.
     * @param string $success Success banner text.
     * @return void
     */
    private static function assignAgeGatePage(TemplateEngine $template, array $user, array $config, array $errors, string $success): void
    {
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
        $errors = [];
        $success = '';
        $config = self::getConfig();
        $userId = self::requireAuthenticatedProfileUserId();
        $user = self::loadRequiredProfileSettingsUser($userId);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = 'Invalid request.';
            }
            else
            {
                self::processAgeGateActionSubmission($userId, $user, $config, $errors, $success);
            }
        }

        $template = self::initTemplate();
        self::assignAgeGatePage($template, $user, $config, $errors, $success);
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
     * Load the settings-focused profile record for the current account.
     *
     * Profile settings pages depend on this record for avatar/email/password
     * state. When the account can no longer be found, the session is treated as
     * stale and the visitor is returned to login.
     *
     * @param int $userId Authenticated user id.
     * @return array
     */
    private static function loadRequiredProfileSettingsUser(int $userId): array
    {
        $user = UserModel::findProfileSettingsUserById($userId);
        if ($user)
        {
            return $user;
        }

        SessionManager::destroy();
        RedirectHelper::rememberLoginDestination();
        header('Location: /user/login');
        exit();
    }

    /**
     * Apply page metadata for one of the single-field profile editors.
     *
     * Keeping this in one method makes it easier to extend the account center
     * without scattering template copy across the controller.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param string $type Editor type (avatar, email, dob, change_password).
     * @return void
     */
    private static function assignSingleUpdatePage(TemplateEngine $template, string $type): void
    {
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
    }

    /**
     * Update the account email address from the profile editor.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @return array<string>
     */
    private static function handleEmailUpdate(int $userId, array &$user): array
    {
        $errors = [];
        $newEmail = Security::sanitizeEmail($_POST['email'] ?? '');
        if (!$newEmail)
        {
            $errors[] = 'Invalid email address.';
            return $errors;
        }

        UserModel::updateEmail($userId, $newEmail);
        $user['email'] = $newEmail;

        return $errors;
    }

    /**
     * Update the stored date of birth from the legacy single-field editor.
     *
     * The dedicated content-access workflow remains the preferred path, but
     * this editor is still supported so existing routes and templates continue
     * to work as expected.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @return array<string>
     */
    private static function handleLegacyDobUpdate(int $userId, array &$user): array
    {
        $errors = [];
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
            return $errors;
        }

        UserModel::updateDobAndVerify($userId, $dob);
        $user['date_of_birth'] = $dob;
        $user['age_verified_at'] = date('Y-m-d H:i:s');

        return $errors;
    }

    /**
     * Update the account password after verifying the current credential.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Current user record including password hash.
     * @return array<string>
     */
    private static function handlePasswordUpdate(int $userId, array $user): array
    {
        $errors = [];
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$currentPassword || !Security::verifyPassword($currentPassword, $user['password_hash']))
        {
            $errors[] = 'Current password is incorrect.';
        }

        if (!$newPassword || strlen($newPassword) < 8)
        {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        else if ($newPassword !== $confirmPassword)
        {
            $errors[] = 'New password and confirmation do not match.';
        }

        if ($currentPassword === $newPassword)
        {
            $errors[] = 'Your new password cannot be the same as your current one.';
        }

        if (!empty($errors))
        {
            return $errors;
        }

        $hashedPassword = Security::hashPassword($newPassword);
        UserModel::updatePasswordHash($userId, $hashedPassword);
        SessionManager::regenerate();

        return $errors;
    }

    /**
     * Resize one uploaded avatar image to the configured square dimensions.
     *
     * @param string $tmpPath Temporary source file path.
     * @param string $ext Avatar extension used to select image handlers.
     * @param int $targetSize Required avatar width/height.
     * @param array $sourceSize Original image dimensions from getimagesize().
     * @return string|null Temporary resized file path or null on failure.
     */
    private static function resizeAvatarToConfiguredSquare(string $tmpPath, string $ext, int $targetSize, array $sourceSize): ?string
    {
        switch ($ext)
        {
            case 'jpg':
            case 'jpeg':
                $srcImg = @imagecreatefromjpeg($tmpPath);
                break;

            case 'png':
                $srcImg = @imagecreatefrompng($tmpPath);
                break;

            case 'gif':
                $srcImg = @imagecreatefromgif($tmpPath);
                break;

            default:
                $srcImg = false;
                break;
        }

        if (!$srcImg)
        {
            return null;
        }

        $dstImg = imagecreatetruecolor($targetSize, $targetSize);

        if ($ext === 'png' || $ext === 'gif')
        {
            imagecolortransparent($dstImg, imagecolorallocatealpha($dstImg, 0, 0, 0, 127));
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
        }

        imagecopyresampled(
            $dstImg,
            $srcImg,
            0,
            0,
            0,
            0,
            $targetSize,
            $targetSize,
            (int)($sourceSize[0] ?? 0),
            (int)($sourceSize[1] ?? 0)
        );

        $resizedPath = sys_get_temp_dir() . '/' . uniqid('avatar_') . '.' . $ext;
        $saved = match ($ext)
        {
            'jpg', 'jpeg' => imagejpeg($dstImg, $resizedPath, 90),
            'png' => imagepng($dstImg, $resizedPath),
            'gif' => imagegif($dstImg, $resizedPath),
            default => false,
        };

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return $saved ? $resizedPath : null;
    }

    /**
     * Persist a validated avatar file into the permanent avatar directory.
     *
     * @param array $avatar Uploaded avatar payload.
     * @param string $ext Sanitized avatar extension.
     * @param bool $wasResized Whether tmp_name points to a generated temporary file.
     * @return string|null Stored public avatar path or null on failure.
     */
    private static function storeAvatarFile(array $avatar, string $ext, bool $wasResized): ?string
    {
        $filename = uniqid('avatar_') . '.' . $ext;
        $avatarDirectory = UPLOAD_PATH . '/avatars/';
        $dest = $avatarDirectory . $filename;

        if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0750, true) && !is_dir($avatarDirectory))
        {
            return null;
        }

        if ($wasResized)
        {
            if (!copy($avatar['tmp_name'], $dest) || !@unlink($avatar['tmp_name']))
            {
                return null;
            }
        }
        else if (!move_uploaded_file($avatar['tmp_name'], $dest))
        {
            return null;
        }

        return '/uploads/avatars/' . $filename;
    }

    /**
     * Remove the previous stored avatar when it belongs to this application.
     *
     * @param string|null $avatarPath Existing avatar path from the user record.
     * @return string|null Error message when cleanup fails.
     */
    private static function removePreviousAvatar(?string $avatarPath): ?string
    {
        if (empty($avatarPath) || strpos($avatarPath, '/uploads/avatars/') !== 0)
        {
            return null;
        }

        $oldAvatar = APP_ROOT . '/' . ltrim($avatarPath, '/');
        if (file_exists($oldAvatar) && !@unlink($oldAvatar))
        {
            return 'Warning: failed to remove old avatar.';
        }

        return null;
    }

    /**
     * Validate, optionally resize, and persist one new profile avatar.
     *
     * This helper keeps avatar file-processing concerns grouped in one place so
     * the main profile update action can stay focused on routing and template
     * rendering.
     *
     * @param int $userId Authenticated user id.
     * @param array $user Mutable user record used by the template.
     * @param array $config Application configuration.
     * @return array{0: array<string>, 1: string}
     */
    private static function handleAvatarUpdate(int $userId, array &$user, array $config): array
    {
        $errors = [];
        if (!isset($_FILES['avatar']))
        {
            return [$errors, ''];
        }

        $avatar = $_FILES['avatar'];
        if (($avatar['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        {
            return [['Error uploading avatar.'], ''];
        }

        $ext = strtolower(pathinfo($avatar['name'] ?? '', PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $maxAvatarMb = (int)($config['profile']['avatar_max_upload_size_mb'] ?? 5);
        $maxAvatarBytes = $maxAvatarMb * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $avatar['tmp_name']) : '';
        if ($finfo)
        {
            finfo_close($finfo);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mimeType, $allowedMimes, true) || !in_array($ext, $allowed, true))
        {
            $errors[] = 'Invalid file type. Only JPG, PNG, GIF allowed.';
        }

        if (($avatar['size'] ?? 0) > $maxAvatarBytes)
        {
            $errors[] = "Avatar exceeds maximum allowed size of {$maxAvatarMb} MB.";
        }

        if (empty($errors) && !self::hasValidAvatarSignature($avatar['tmp_name'], $mimeType))
        {
            $errors[] = 'Uploaded file signature does not match the detected image type.';
        }

        $size = null;
        if (empty($errors))
        {
            $size = @getimagesize($avatar['tmp_name']);
            if (!$size)
            {
                $errors[] = 'Uploaded file is not a valid image.';
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

        if (!empty($errors) || !is_array($size))
        {
            return [$errors, ''];
        }

        $avatarSize = (int)($config['profile']['avatar_size'] ?? 150);
        $wasResized = false;
        if ((int)($size[0] ?? 0) !== $avatarSize)
        {
            $resizedPath = self::resizeAvatarToConfiguredSquare($avatar['tmp_name'], $ext, $avatarSize, $size);
            if ($resizedPath === null)
            {
                return [['Failed to process uploaded image.'], ''];
            }

            $avatar['tmp_name'] = $resizedPath;
            $wasResized = true;
        }

        $storedAvatarPath = self::storeAvatarFile($avatar, $ext, $wasResized);
        if ($storedAvatarPath === null)
        {
            return [['Failed to upload avatar.'], ''];
        }

        $cleanupError = self::removePreviousAvatar($user['avatar_path'] ?? null);
        if ($cleanupError !== null)
        {
            return [[$cleanupError], ''];
        }

        UserModel::updateAvatarPath($userId, $storedAvatarPath);
        $user['avatar_path'] = $storedAvatarPath;

        return [$errors, 'Avatar updated successfully.'];
    }

    /**
     * Private helper to handle single-field profile updates.
     *
     * Handles updating avatar, email, date of birth, or password depending on
     * $type while keeping each update path grouped into smaller helper methods.
     *
     * @param string $type The type of update (email, dob, avatar, change_password).
     * @return void
     */
    private static function handleSingleUpdate(string $type): void
    {
        $errors = [];
        $success = '';
        $config = self::getConfig();

        $userId = self::requireAuthenticatedProfileUserId();
        $user = self::loadRequiredProfileSettingsUser($userId);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = 'Invalid request.';
            }
            else
            {
                switch ($type)
                {
                    case 'email':
                        $errors = self::handleEmailUpdate($userId, $user);
                        $success = empty($errors) ? 'Email updated successfully.' : '';
                        break;

                    case 'dob':
                        $errors = self::handleLegacyDobUpdate($userId, $user);
                        $success = empty($errors) ? 'Date of birth updated successfully.' : '';
                        break;

                    case 'avatar':
                        [$errors, $success] = self::handleAvatarUpdate($userId, $user, $config);
                        break;

                    case 'change_password':
                        $errors = self::handlePasswordUpdate($userId, $user);
                        $success = empty($errors) ? 'Password updated successfully.' : '';
                        break;
                }
            }
        }

        $template = self::initTemplate();
        self::assignProfileChrome($template, $user ?: []);
        self::assignSingleUpdatePage($template, $type);

        $template->assign('avatar_size', $config['profile']['avatar_size']);
        $template->assign('email', $user['email']);
        $template->assign('avatar_path', $user['avatar_path']);
        $template->assign('date_of_birth', $user['date_of_birth'] ?? '');
        $template->assign('date_of_birth_format', DateHelper::birthday_format($user['date_of_birth']) ?? 'Not set');
        $template->assign('user', $user);
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render("profile/profile_{$type}.html");
    }
}
