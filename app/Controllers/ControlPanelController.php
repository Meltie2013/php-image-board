<?php

/**
 * ControlPanelController
 *
 * Provides the merged Control Panel for both administrators and moderators.
 *
 * Responsibilities:
 * - Render the shared dashboard and permission-aware navigation
 * - Handle moderation queue and image tooling pages
 * - Handle administrator-only users, settings, logs, and enforcement pages
 *
 * Security considerations:
 * - Staff access required for the control panel shell
 * - Administrative permissions required for administrative modules
 * - CSRF validation for all state-changing actions
 */
class ControlPanelController extends BaseController
{
    /**
     * Static template variables assigned for all control panel templates.
     *
     * @var array
     */
    protected static array $templateAssignments = [
        'is_gallery_page' => 1,
        'is_control_panel' => 1,
    ];

    /**
     * Control panel templates require CSRF support for the shared header and
     * multiple inline action forms.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = true;

    /**
     * Assign shared control panel page metadata used by the merged menu and page hero.
     *
     * All unified control panel templates consume these shared assignments.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param string $nav Current navigation key for sidebar highlighting.
     * @param string $title Current page title.
     * @param string $description Current page summary/description.
     * @param string $section Current sidebar section key.
     * @return void
     */
    private static function assignPanelPage(TemplateEngine $template, string $nav, string $title, string $description, string $section = ''): void
    {
        $groupLabel = self::getCurrentRole();
        $groupSlug = strtolower(TypeHelper::toString(SessionManager::get('user_group_slug'), allowEmpty: true) ?? '');
        $canManageUsers = GroupPermissionHelper::hasPermission('manage_users');
        $canManageGroups = GroupPermissionHelper::hasPermission('manage_groups');
        $canManageGroupPermissions = GroupPermissionHelper::hasPermission('manage_group_permissions');
        $canManageSettings = GroupPermissionHelper::hasPermission('manage_settings');
        $canManageRules = GroupPermissionHelper::hasPermission('manage_rules');
        $canViewSecurity = GroupPermissionHelper::hasPermission('view_security');
        $canManageBlocks = GroupPermissionHelper::hasPermission('manage_block_list');
        $canModerateImages = GroupPermissionHelper::hasAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue', 'manage_image_reports']);
        $canCompareImages = GroupPermissionHelper::hasPermission('compare_images');
        $canRehashImages = GroupPermissionHelper::hasPermission('rehash_images');
        $isStaff = GroupPermissionHelper::hasPermission('access_control_panel');
        $isSiteAdministrator = $groupSlug === 'site-administrator';

        $template->assign('current_control_panel_nav', $nav);
        $template->assign('current_control_panel_section', $section);
        $template->assign('control_panel_page_title', $title);
        $template->assign('control_panel_page_description', $description);
        $template->assign('control_panel_role', $groupLabel);
        $template->assign('cp_is_admin', $isSiteAdministrator ? 1 : 0);
        $template->assign('cp_is_moderator', $canModerateImages ? 1 : 0);
        $template->assign('cp_is_staff', $isStaff ? 1 : 0);
        $template->assign('cp_can_manage_users', $canManageUsers ? 1 : 0);
        $template->assign('cp_can_manage_groups', $canManageGroups ? 1 : 0);
        $template->assign('cp_can_manage_group_permissions', $canManageGroupPermissions ? 1 : 0);
        $template->assign('cp_can_manage_settings', $canManageSettings ? 1 : 0);
        $template->assign('cp_can_manage_rules', $canManageRules ? 1 : 0);
        $template->assign('cp_can_view_security', $canViewSecurity ? 1 : 0);
        $template->assign('cp_can_manage_blocks', $canManageBlocks ? 1 : 0);
        $template->assign('cp_can_moderate_images', $canModerateImages ? 1 : 0);
        $template->assign('cp_can_compare_images', $canCompareImages ? 1 : 0);
        $template->assign('cp_can_rehash_images', $canRehashImages ? 1 : 0);
    }

    /**
     * Get the normalized current control panel group label from session data.
     *
     * @return string
     */
    private static function getCurrentRole(): string
    {
        return TypeHelper::toString(SessionManager::get('user_group'), allowEmpty: true)
            ?? TypeHelper::toString(SessionManager::get('user_role'), allowEmpty: true)
            ?? '';
    }

    /**
     * Return the configured rules enforcement window in days.
     *
     * @return int
     */
    private static function getRulesEnforcementWindowDays(): int
    {
        return max(0, TypeHelper::toInt(SettingsManager::get('rules.enforcement_window_days', 14)) ?? 14);
    }

    /**
     * Create one user notification after a pending image moderation action.
     *
     * @param string $hash
     * @param string $action
     * @param string $contentRating
     * @param string $rejectReason
     * @return void
     */
    private static function createPendingImageNotification(string $hash, string $action, string $contentRating = 'standard', string $rejectReason = ''): void
    {
        if (!NotificationModel::isSchemaAvailable())
        {
            return;
        }

        $image = ImageModel::findEditableImageByHash($hash);
        if (!$image)
        {
            return;
        }

        $userId = TypeHelper::toInt($image['user_id'] ?? 0) ?? 0;
        $imageHash = TypeHelper::toString($image['image_hash'] ?? '', allowEmpty: true) ?? '';
        if ($userId < 1 || $imageHash === '')
        {
            return;
        }

        $contentRating = AgeGateHelper::normalizeContentRating($contentRating);
        $contentRatingLabel = AgeGateHelper::getContentRatingLabel($contentRating);
        $rejectReason = trim($rejectReason);

        switch ($action)
        {
            case 'approved':
                $title = 'Image Approved';
                $message = 'Your image submission has been approved and is now available in the community gallery.';

                if ($contentRating !== 'standard')
                {
                    $message .= ' It is listed with the ' . $contentRatingLabel . ' content setting.';
                }

                NotificationModel::create($userId, 'image_approved', $title, $message, '/gallery/' . rawurlencode($imageHash));
                break;

            case 'rejected':
                $title = 'Image Moderation Update';
                $message = 'Your recent image submission could not be added to the community gallery at this time.';

                if ($rejectReason !== '')
                {
                    $message .= ' Moderator note: ' . $rejectReason;
                }

                NotificationModel::create($userId, 'image_rejected', $title, $message, '');
                break;
        }
    }

    /**
     * Enforce staff authorization for the merged control panel.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requirePanelAccess(?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        GroupPermissionHelper::requirePermission('access_control_panel', $template);
    }

    /**
     * Enforce administrative authorization for administrative modules.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requirePanelAdmin(?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        self::requirePanelAccess($template);
        GroupPermissionHelper::requireAnyPermission(['manage_users', 'manage_settings', 'view_security', 'manage_block_list', 'rehash_images'], $template);
    }

    /**
     * Enforce one ACP permission while also requiring shared panel access.
     *
     * @param string $token
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requirePanelPermission(string $token, ?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        self::requirePanelAccess($template);
        GroupPermissionHelper::requirePermission($token, $template);
    }

    /**
     * Enforce one of several ACP permissions while also requiring shared panel access.
     *
     * @param array $tokens
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requirePanelAnyPermission(array $tokens, ?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        self::requirePanelAccess($template);
        GroupPermissionHelper::requireAnyPermission($tokens, $template);
    }

    /**
     * Restrict owner-only group and RBAC management pages to the built-in Site Administrator group.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requireSiteAdministrator(?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        self::requirePanelAccess($template);

        if (!GroupPermissionHelper::hasGroupSlug(['site-administrator']))
        {
            http_response_code(403);
            $template->assign('title', 'Forbidden');
            $template->assign('message', 'Only the Site Administrator can manage groups and RBAC permissions.');
            $template->render('errors/error_page.html');
            exit;
        }
    }

    /**
     * Convert a packed database IP value into a display-safe string.
     *
     * @param mixed $value Raw database value.
     * @return string
     */
    private static function formatStoredIp(mixed $value): string
    {
        if (!is_string($value) || $value === '')
        {
            return '';
        }

        $ip = @inet_ntop($value);
        if (is_string($ip) && $ip !== '')
        {
            return $ip;
        }

        return TypeHelper::toString($value, allowEmpty: true) ?? '';
    }

    /**
     * Normalize an IP filter value to packed binary storage format.
     *
     * @param string $value Raw filter value.
     * @return string|null
     */
    private static function packIpFilter(string $value): ?string
    {
        $value = trim($value);
        if ($value === '')
        {
            return null;
        }

        $packed = @inet_pton($value);
        if ($packed === false)
        {
            return null;
        }

        return $packed;
    }

    /**
     * Shorten long fingerprint values for list views while preserving the full
     * value in the detailed record pages.
     *
     * @param string $value Raw hash/fingerprint.
     * @param int $visible Number of leading characters to keep.
     * @return string
     */
    private static function shortenHash(string $value, int $visible = 14): string
    {
        $value = trim($value);
        if ($value === '')
        {
            return '';
        }

        if (mb_strlen($value) <= ($visible + 3))
        {
            return $value;
        }

        return mb_substr($value, 0, $visible) . '...';
    }

    /**
     * Build one pagination URL for the image report list.
     *
     * @param int $page Page number.
     * @return string
     */
    private static function buildImageReportListUrl(int $page): string
    {
        if ($page <= 1)
        {
            return '/panel/image-reports';
        }

        return '/panel/image-reports/page/' . $page;
    }


    /**
     * Resolve related account usernames linked through device or browser
     * fingerprints for a security log entry.
     *
     * @param string $deviceFingerprint Stable browser-instance fingerprint.
     * @param string $browserFingerprint Softer browser fingerprint.
     * @param int $currentUserId Current event user identifier.
     * @return array<int, array<int, string|int>>
     */
    private static function getLinkedSecurityUsers(string $deviceFingerprint, string $browserFingerprint, int $currentUserId = 0): array
    {
        if ($deviceFingerprint === '' && $browserFingerprint === '')
        {
            return [];
        }

        $where = [];
        $params = [];

        if ($deviceFingerprint !== '')
        {
            $where[] = 'd.device_fingerprint = :dfp';
            $params['dfp'] = $deviceFingerprint;
        }

        if ($browserFingerprint !== '')
        {
            $where[] = 'd.browser_fingerprint = :bfp';
            $params['bfp'] = $browserFingerprint;
        }

        try
        {
            $rows = SecurityLogModel::findLinkedUsers($deviceFingerprint, $browserFingerprint);
        }
        catch (Throwable $e)
        {
            return [];
        }

        $users = [];
        foreach ($rows as $row)
        {
            $userId = TypeHelper::toInt($row['id'] ?? 0) ?? 0;
            $username = TypeHelper::toString($row['username'] ?? '', allowEmpty: true) ?? '';
            if ($userId < 1 || $username === '')
            {
                continue;
            }

            $label = $username;
            if ($currentUserId > 0 && $userId === $currentUserId)
            {
                $label .= ' (event user)';
            }

            $users[] = [
                $userId,
                $label,
            ];
        }

        return $users;
    }

    /**
     * Render the merged control panel dashboard.
     *
     * Administrators receive the broader operational overview while moderators
     * see the focused image and queue statistics they need most.
     *
     * @return void
     */
    public static function dashboard(): void
    {
        $template = self::initTemplate();
        self::requirePanelAccess($template);

        $role = self::getCurrentRole();
        $isAdmin = GroupPermissionHelper::hasAnyPermission(['manage_users', 'manage_settings', 'view_security', 'manage_block_list']);

        $totalUsers = 0;
        $activeBlocks = 0;
        $recentLogs = [];

        if ($isAdmin)
        {
            try
            {
                $totalUsers = UserModel::countNonDeletedUsers();
            }
            catch (Throwable $e)
            {
                $totalUsers = 0;
            }

            try
            {
                $activeBlocks = BlockListModel::countActive();
            }
            catch (Throwable $e)
            {
                $activeBlocks = 0;
            }

            try
            {
                $rows = SecurityLogModel::listRecent(5);
                foreach ($rows as $r)
                {
                    $recentLogs[] = [
                        TypeHelper::toString(DateHelper::date_only_format($r['created_at']) ?? ''),
                        TypeHelper::toString($r['category'] ?? ''),
                        TypeHelper::toString($r['message'] ?? ''),
                        TypeHelper::toString(ucfirst(self::getUsernameById(TypeHelper::toInt($r['user_id'] ?? 0) ?? 0)) ?? ''),
                    ];
                }
            }
            catch (Throwable $e)
            {
                $recentLogs = [];
            }
        }

        $imageCounts = ImageModel::getDashboardCounts();
        $totalImages = TypeHelper::toInt($imageCounts['total'] ?? 0) ?? 0;
        $approvedCount = TypeHelper::toInt($imageCounts['approved'] ?? 0) ?? 0;
        $pendingCount = TypeHelper::toInt($imageCounts['pending'] ?? 0) ?? 0;
        $openReportsCount = ImageReportModel::countOpen();
        $removedCount = TypeHelper::toInt($imageCounts['removed'] ?? 0) ?? 0;
        $rejectedCount = TypeHelper::toInt($imageCounts['rejected'] ?? 0) ?? 0;
        $combinedCount = TypeHelper::toInt($imageCounts['views'] ?? 0) ?? 0;

        $storageUsed = StorageHelper::getUsedStorageReadable();
        $storageRemaining = StorageHelper::getRemainingStorageReadable();
        $storageTotal = StorageHelper::getMaxStorageReadable();
        $storagePercent = StorageHelper::getStorageUsagePercent();
        $storageUsagePercent = StorageHelper::getStorageUsagePercent(2);

        self::assignPanelPage(
            $template,
            'dashboard',
            'Dashboard',
            'Central visibility for users, security, moderation queues, and image volume across the platform.',
            'overview'
        );

        $template->assign('total_users', NumericalHelper::formatCount($totalUsers));
        $template->assign('active_blocks', NumericalHelper::formatCount($activeBlocks));
        $template->assign('recent_logs', $recentLogs);

        $template->assign('total_images', NumericalHelper::formatCount($totalImages));
        $template->assign('approved_count', NumericalHelper::formatCount($approvedCount));
        $template->assign('pending_count', NumericalHelper::formatCount($pendingCount));
        $template->assign('open_report_count', NumericalHelper::formatCount($openReportsCount));
        $template->assign('removed_count', NumericalHelper::formatCount($removedCount));
        $template->assign('rejected_count', NumericalHelper::formatCount($rejectedCount));
        $template->assign('total_view_count', NumericalHelper::formatCount($combinedCount));

        $template->assign('storage_used', $storageUsed);
        $template->assign('storage_remaining', $storageRemaining);
        $template->assign('storage_total', $storageTotal);
        $template->assign('storage_percent', $storagePercent);
        $template->assign('storage_usage_percent', $storageUsagePercent);

        $template->render('panel/control_panel_dashboard.html');
    }

    /**
     * Render the user management page.
     *
     * Lists users and groups for administrator management, and provides CSRF
     * token for create/edit actions.
     *
     * @return void
     */
    public static function users(): void
    {
        self::requirePanelPermission('manage_users');

        $template = self::initTemplate();

        $rows = UserModel::listPanelUsers();

        $users = [];
        foreach ($rows as $u)
        {
            $users[] = [
                TypeHelper::toInt($u['id'] ?? ''),
                TypeHelper::toString(ucfirst($u['username']) ?? ''),
                TypeHelper::toString($u['email'] ?? ''),
                TypeHelper::toString($u['group_name'] ?? ''),
                TypeHelper::toString($u['status'] ?? ''),
                TypeHelper::toString(DateHelper::format($u['created_at']) ?? ''),
            ];
        }

        $groupRows = GroupModel::listAssignableGroups();
        $roles = [];
        foreach ($groupRows as $r)
        {
            $roles[] = [
                TypeHelper::toInt($r['id'] ?? ''),
                TypeHelper::toString($r['name'] ?? ''),
            ];
        }

        self::assignPanelPage(
            $template,
            'users',
            'User Management',
            'Review member accounts, group assignments, and account states from a single control surface.',
            'accounts'
        );

        $template->assign('users', $users);
        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_users.html');
    }

    /**
     * Create a new user (admin action).
     *
     * Validates CSRF token and input values, hashes the password, and inserts a
     * new row into app_users.
     *
     * @return void
     */
    public static function userCreate(): void
    {
        self::requirePanelPermission('manage_users');

        $errors = [];
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $username = Security::sanitizeString($_POST['username'] ?? '');
            $display = Security::sanitizeString($_POST['display_name'] ?? '');
            $email = Security::sanitizeEmail($_POST['email'] ?? '');
            $password = Security::sanitizeString($_POST['password'] ?? '');
            $groupId = TypeHelper::toInt($_POST['group_id'] ?? 6) ?? 6;
            $status = Security::sanitizeString($_POST['status'] ?? 'active');

            if ($username === '' || !$email || $password === '')
            {
                $errors[] = 'Username, email and password are required.';
            }

            $selectedGroup = GroupModel::findGroupById($groupId);
            if (!$selectedGroup || empty($selectedGroup['is_assignable']))
            {
                $errors[] = 'Choose an assignable group for this account.';
            }

            if (!in_array($status, ['active', 'pending', 'suspended'], true))
            {
                $status = 'active';
            }

            if (empty($errors))
            {
                $hash = Security::hashPassword($password);

                try
                {
                    UserModel::createPanelUser($groupId, $username, $display !== '' ? $display : null, $email, $hash, $status);

                    $success = 'User created.';
                }
                catch (Throwable $e)
                {
                    $errors[] = 'Failed to create user.';
                }
            }
        }

        $template = self::initTemplate();
        $groupRows = GroupModel::listAssignableGroups();
        $roles = [];
        foreach ($groupRows as $r)
        {
            $roles[] = [
                TypeHelper::toInt($r['id'] ?? ''),
                TypeHelper::toString($r['name'] ?? ''),
            ];
        }

        self::assignPanelPage(
            $template,
            'users_create',
            'Create User',
            'Create a new account with the correct group, status, and profile defaults.',
            'accounts'
        );

        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render('panel/control_panel_user_create.html');
    }

    /**
     * Edit an existing user (admin action).
     *
     * Renders the edit form, validates CSRF, updates user properties and
     * optionally updates password when provided.
     *
     * @param int $id User ID to edit.
     * @return void
     */
    public static function userEdit(int $id): void
    {
        self::requirePanelPermission('manage_users');

        $errors = [];
        $success = '';

        $user = UserModel::findPanelUserById($id);

        if (!$user)
        {
            http_response_code(404);
            $template = self::initTemplate();
            $template->assign('title', 'Not Found');
            $template->assign('message', 'User not found.');
            $template->render('errors/error_page.html');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $username = Security::sanitizeString($_POST['username'] ?? '');
            $display = Security::sanitizeString($_POST['display_name'] ?? '');
            $email = Security::sanitizeEmail($_POST['email'] ?? '');
            $groupId = TypeHelper::toInt($_POST['group_id'] ?? $user['group_id']) ?? TypeHelper::toInt($user['group_id']);
            $status = Security::sanitizeString($_POST['status'] ?? $user['status']);
            $password = Security::sanitizeString($_POST['password'] ?? '');
            $forceAgeReview = !empty($_POST['force_age_review']);
            $restrictMatureContent = !empty($_POST['restrict_mature_content']);
            $resetMatureContentAccess = !empty($_POST['reset_mature_content_access']);
            $ageGateReason = Security::sanitizeString($_POST['age_gate_reason'] ?? '');

            if ($username === '' || !$email)
            {
                $errors[] = 'Username and email are required.';
            }

            $selectedGroup = GroupModel::findGroupById($groupId);
            if (!$selectedGroup || empty($selectedGroup['is_assignable']))
            {
                $errors[] = 'Choose an assignable group for this account.';
            }

            if (!in_array($status, ['active', 'pending', 'suspended', 'deleted'], true))
            {
                $status = TypeHelper::toString($user['status']);
            }

            if (empty($errors))
            {
                try
                {
                    UserModel::updatePanelUser($id, $groupId, $username, $display !== '' ? $display : null, $email, $status);

                    if ($password !== '')
                    {
                        $hash = Security::hashPassword($password);
                        UserModel::updatePasswordHash($id, $hash);
                    }

                    $currentStaffUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
                    if ($resetMatureContentAccess)
                    {
                        UserModel::resetAgeGateAccess($id);
                    }
                    else if ($restrictMatureContent)
                    {
                        UserModel::setRestrictedMinor($id, $currentStaffUserId, $ageGateReason !== '' ? $ageGateReason : null);
                    }
                    else if ($forceAgeReview)
                    {
                        UserModel::setForcedAgeReview($id, $currentStaffUserId, $ageGateReason !== '' ? $ageGateReason : null);
                    }

                    $success = 'User updated.';
                    $user = UserModel::findPanelUserById($id);
                }
                catch (Throwable $e)
                {
                    $errors[] = 'Failed to update user.';
                }
            }
        }

        $template = self::initTemplate();
        $groupRows = GroupModel::listAssignableGroups();
        $roles = [];
        foreach ($groupRows as $r)
        {
            $roles[] = [
                TypeHelper::toInt($r['id'] ?? ''),
                TypeHelper::toString($r['name'] ?? ''),
            ];
        }

        self::assignPanelPage(
            $template,
            'users',
            'Edit User',
            'Update account identity, status, group assignments, and password data.',
            'accounts'
        );

        $template->assign('user_id', TypeHelper::toInt($user['id'] ?? ''));
        $template->assign('user_role_id', TypeHelper::toInt($user['group_id'] ?? ''));
        $template->assign('user_username', TypeHelper::toString($user['username'] ?? ''));
        $template->assign('user_display_name', TypeHelper::toString($user['display_name'] ?? ''));
        $template->assign('user_email', TypeHelper::toString($user['email'] ?? ''));
        $template->assign('user_status', TypeHelper::toString($user['status'] ?? 'active'));
        $template->assign('user_date_of_birth', !empty($user['date_of_birth']) ? DateHelper::birthday_format($user['date_of_birth']) : 'Not set');
        $template->assign('user_age_verified_at', !empty($user['age_verified_at']) ? DateHelper::format($user['age_verified_at']) : 'Not set');
        $template->assign('user_age_gate_status', AgeGateHelper::getAgeGateStatusLabel(TypeHelper::toString($user['age_gate_status'] ?? 'not_started') ?? 'not_started'));
        $template->assign('user_age_gate_method', TypeHelper::toString($user['age_gate_method'] ?? 'none'));
        $template->assign('user_age_gate_force_reason', TypeHelper::toString($user['age_gate_force_reason'] ?? '', allowEmpty: true) ?? '');
        $currentGroup = GroupModel::findGroupById(TypeHelper::toInt($user['group_id'] ?? 0) ?? 0);
        $template->assign('user_current_group_name', TypeHelper::toString($currentGroup['name'] ?? ''));
        $template->assign('user_current_group_is_assignable', !empty($currentGroup['is_assignable']) ? 1 : 0);

        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render('panel/control_panel_user_edit.html');
    }

    /**
     * Render the group management overview for Site Administrator users.
     *
     * @return void
     */
    public static function groups(): void
    {
        self::requireSiteAdministrator();
        self::requirePanelAnyPermission(['manage_groups', 'manage_group_permissions']);

        $errors = [];
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $action = Security::sanitizeString($_POST['action'] ?? '');

            if ($action === 'save_default_registration_group')
            {
                $defaultGroupId = TypeHelper::toInt($_POST['default_registration_group_id'] ?? 0) ?? 0;
                $group = GroupModel::findGroupById($defaultGroupId);

                if (!$group || empty($group['is_assignable']))
                {
                    $errors[] = 'Choose an assignable group for new registrations.';
                }
                else if (empty($errors))
                {
                    GroupModel::setDefaultRegistrationGroupId($defaultGroupId);
                    $success = 'Default registration group updated.';
                }
            }
            else if ($action === 'create_group')
            {
                $name = trim(Security::sanitizeString($_POST['name'] ?? ''));
                $description = trim(Security::sanitizeString($_POST['description'] ?? ''));
                $isAssignable = !empty($_POST['is_assignable']) ? 1 : 0;
                $sortOrder = TypeHelper::toInt($_POST['sort_order'] ?? GroupModel::getNextSortOrder()) ?? GroupModel::getNextSortOrder();
                $slug = self::normalizeGroupSlug($name);

                if ($name === '')
                {
                    $errors[] = 'Group name is required.';
                }
                else if ($slug === '')
                {
                    $errors[] = 'Group name could not be converted into a safe group slug.';
                }
                else if (GroupModel::findGroupBySlug($slug))
                {
                    $errors[] = 'A group with that name already exists.';
                }
                else if (empty($errors))
                {
                    $groupId = GroupModel::saveGroup([
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $description,
                        'is_assignable' => $isAssignable,
                        'sort_order' => $sortOrder,
                    ]);

                    GroupModel::replacePermissions($groupId, []);
                    $success = 'Group created.';
                }
            }
        }

        $template = self::initTemplate();
        $groups = [];
        foreach (GroupModel::listGroups() as $group)
        {
            $groups[] = [
                TypeHelper::toInt($group['id'] ?? 0) ?? 0,
                TypeHelper::toString($group['name'] ?? ''),
                TypeHelper::toString($group['slug'] ?? ''),
                TypeHelper::toString($group['description'] ?? ''),
                !empty($group['is_built_in']) ? 1 : 0,
                !empty($group['is_assignable']) ? 1 : 0,
            ];
        }

        $assignableGroups = [];
        foreach (GroupModel::listAssignableGroups() as $group)
        {
            $assignableGroups[] = [
                TypeHelper::toInt($group['id'] ?? 0) ?? 0,
                TypeHelper::toString($group['name'] ?? ''),
            ];
        }

        $defaultRegistrationGroup = GroupModel::getDefaultRegistrationGroup();

        self::assignPanelPage(
            $template,
            'groups',
            'Group Management',
            'Manage built-in and custom groups, choose the registration default, and open the RBAC editor for each group.',
            'accounts'
        );

        $template->assign('groups', $groups);
        $template->assign('assignable_groups', $assignableGroups);
        $template->assign('default_registration_group_id', TypeHelper::toInt($defaultRegistrationGroup['id'] ?? 0) ?? 0);
        $template->assign('default_registration_group_name', TypeHelper::toString($defaultRegistrationGroup['name'] ?? 'Member'));
        $template->assign('next_group_sort_order', GroupModel::getNextSortOrder());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_groups.html');
    }

    /**
     * Edit one group and its RBAC permission values.
     *
     * @param int $id
     * @return void
     */
    public static function groupEdit(int $id): void
    {
        self::requireSiteAdministrator();
        self::requirePanelAnyPermission(['manage_groups', 'manage_group_permissions']);

        $group = GroupModel::findGroupById($id);
        if (!$group)
        {
            http_response_code(404);
            $template = self::initTemplate();
            $template->assign('title', 'Not Found');
            $template->assign('message', 'Group not found.');
            $template->render('errors/error_page.html');
            return;
        }

        $errors = [];
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $name = trim(Security::sanitizeString($_POST['name'] ?? TypeHelper::toString($group['name'] ?? '')));
            $description = trim(Security::sanitizeString($_POST['description'] ?? TypeHelper::toString($group['description'] ?? '')));
            $isAssignable = !empty($_POST['is_assignable']) ? 1 : 0;
            $sortOrder = TypeHelper::toInt($_POST['sort_order'] ?? $group['sort_order']) ?? (TypeHelper::toInt($group['sort_order'] ?? 0) ?? 0);

            if ($name === '')
            {
                $errors[] = 'Group name is required.';
            }

            if (!empty($group['is_built_in']))
            {
                $name = TypeHelper::toString($group['name'] ?? $name);
            }

            if (strtolower(TypeHelper::toString($group['slug'] ?? '', allowEmpty: true) ?? '') === 'banned')
            {
                $isAssignable = 0;
            }

            $permissionPayload = [];
            foreach (GroupPermissionHelper::getPermissionCatalog() as $token => $meta)
            {
                $permissionPayload[$token] = !empty($_POST['permissions'][$token]) ? 1 : 0;
            }

            if (empty($errors))
            {
                GroupModel::saveGroup([
                    'id' => TypeHelper::toInt($group['id'] ?? 0) ?? 0,
                    'name' => $name,
                    'description' => $description,
                    'is_assignable' => $isAssignable,
                    'sort_order' => $sortOrder,
                ]);
                GroupModel::replacePermissions(TypeHelper::toInt($group['id'] ?? 0) ?? 0, $permissionPayload);

                $success = 'Group updated.';
                $group = GroupModel::findGroupById($id) ?: $group;
                if ((TypeHelper::toInt(SessionManager::get('user_group_id')) ?? 0) === (TypeHelper::toInt($group['id'] ?? 0) ?? 0))
                {
                    GroupPermissionHelper::syncSessionForUser(TypeHelper::toInt(SessionManager::get('user_id')) ?? 0, true);
                }
            }
        }

        $template = self::initTemplate();
        $permissions = GroupModel::getPermissionMapByGroupId(TypeHelper::toInt($group['id'] ?? 0) ?? 0);
        $permissionRows = [];
        foreach (GroupPermissionHelper::getPermissionCatalog() as $token => $meta)
        {
            $permissionRows[] = [
                $token,
                TypeHelper::toString($meta['label'] ?? $token),
                TypeHelper::toString($meta['description'] ?? ''),
                TypeHelper::toString($meta['input_type'] ?? 'select'),
                !empty($permissions[$token]) ? 1 : 0,
            ];
        }

        self::assignPanelPage(
            $template,
            'groups',
            'Edit Group',
            'Update group metadata and fine-tune RBAC permission tokens for this group.',
            'accounts'
        );

        $template->assign('group_id', TypeHelper::toInt($group['id'] ?? 0) ?? 0);
        $template->assign('group_name', TypeHelper::toString($group['name'] ?? ''));
        $template->assign('group_slug', TypeHelper::toString($group['slug'] ?? ''));
        $template->assign('group_description', TypeHelper::toString($group['description'] ?? ''));
        $template->assign('group_is_built_in', !empty($group['is_built_in']) ? 1 : 0);
        $template->assign('group_is_assignable', !empty($group['is_assignable']) ? 1 : 0);
        $template->assign('group_sort_order', TypeHelper::toInt($group['sort_order'] ?? 0) ?? 0);
        $template->assign('group_permission_rows', $permissionRows);
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_group_edit.html');
    }

    /**
     * Normalize a free-form group name into a safe internal slug.
     *
     * @param string $value
     * @return string
     */
    private static function normalizeGroupSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value;
    }


    /**
     * Normalize a rules category or rule title into a safe slug.
     *
     * @param string $value
     * @return string
     */
    private static function normalizeRulesSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if (strlen($value) > 80)
        {
            $value = substr($value, 0, 80);
            $value = trim($value, '-');
        }

        return $value;
    }

    /**
     * Return known registry key definitions for the settings UI.
     *
     * @return array
     */
    private static function getSettingsDefinitionCatalog(): array
    {
        if (!class_exists('SettingsRegistry'))
        {
            return [];
        }

        $catalog = [];
        foreach (SettingsRegistry::getSettingDefinitions() as $key => $definition)
        {
            $catalog[$key] = [
                'category' => TypeHelper::toString($definition['category'] ?? SettingsRegistry::inferCategoryFromKey($key)),
                'label' => TypeHelper::toString($definition['title'] ?? self::humanizeSettingsToken($key)),
                'description' => TypeHelper::toString($definition['description'] ?? ''),
                'type' => self::normalizeSettingsType(TypeHelper::toString($definition['type'] ?? 'string')),
                'input' => TypeHelper::toString($definition['input'] ?? SettingsRegistry::defaultInputForType((string) ($definition['type'] ?? 'string'))),
                'help' => TypeHelper::toString($definition['help'] ?? ''),
                'placeholder' => TypeHelper::toString($definition['placeholder'] ?? ''),
                'min' => isset($definition['min']) ? (string) $definition['min'] : '',
                'max' => isset($definition['max']) ? (string) $definition['max'] : '',
                'options' => $definition['options'] ?? [],
                'sort_order' => TypeHelper::toInt($definition['sort_order'] ?? 0) ?? 0,
                'default' => $definition['default'] ?? '',
                'is_system' => !empty($definition['is_system']) ? 1 : 0,
            ];
        }

        return $catalog;
    }


    /**
     * Return the category catalog used by the Control Panel settings UI.
     *
     * @return array
     */
    private static function getSettingsCategoryCatalog(): array
    {
        if (!class_exists('SettingsRegistry'))
        {
            return [];
        }

        $catalog = [];
        foreach (SettingsRegistry::getCategoryDefinitions() as $slug => $meta)
        {
            $catalog[$slug] = [
                'title' => TypeHelper::toString($meta['title'] ?? self::humanizeSettingsToken($slug)),
                'description' => TypeHelper::toString($meta['description'] ?? ''),
                'icon' => TypeHelper::toString($meta['icon'] ?? 'fa-sliders'),
                'sort_order' => TypeHelper::toInt($meta['sort_order'] ?? 0) ?? 0,
                'is_system' => !empty($meta['is_system']) ? 1 : 0,
            ];
        }

        return $catalog;
    }

    /**
     * Render the rules management overview.
     *
     * @return void
     */
    public static function rules(): void
    {
        self::requirePanelPermission('manage_rules');

        $template = self::initTemplate();
        $notice = Security::sanitizeString($_GET['notice'] ?? '');
        $noticeMessage = '';
        $noticeType = 'success';

        if ($notice === 'saved')
        {
            $noticeMessage = 'Rule saved and rules update published.';
        }
        else if ($notice === 'schema_missing')
        {
            $noticeMessage = 'The rules database tables are not available yet. Please apply the latest rules migration first.';
            $noticeType = 'error';
        }

        $ruleRows = [];
        foreach (RulesModel::listRulesForAdmin() as $row)
        {
            $body = TypeHelper::toString($row['body'] ?? '', allowEmpty: true) ?? '';
            $preview = trim(preg_replace('/\s+/', ' ', $body) ?? '');
            if (strlen($preview) > 140)
            {
                $preview = substr($preview, 0, 137) . '...';
            }

            $ruleRows[] = [
                TypeHelper::toInt($row['id'] ?? 0) ?? 0,
                TypeHelper::toString($row['title'] ?? ''),
                TypeHelper::toString($row['slug'] ?? ''),
                TypeHelper::toString($row['category_title'] ?? ''),
                $preview,
                TypeHelper::toInt($row['sort_order'] ?? 0) ?? 0,
                !empty($row['is_active']) ? 1 : 0,
                DateHelper::format(TypeHelper::toString($row['updated_at'] ?? '', allowEmpty: true)),
            ];
        }

        self::assignPanelPage(
            $template,
            'rules',
            'Rules Management',
            'Organize rules by category, edit rule entries, and publish updates that notify members when the rules change.',
            'rules'
        );

        $template->assign('control_panel_notice', $noticeMessage);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->assign('rule_rows', $ruleRows);
        $template->render('panel/control_panel_rules.html');
    }

    /**
     * Render the rules categories management overview.
     *
     * @return void
     */
    public static function rulesCategories(): void
    {
        self::requirePanelPermission('manage_rules');

        $template = self::initTemplate();
        $notice = Security::sanitizeString($_GET['notice'] ?? '');
        $noticeMessage = '';
        $noticeType = 'success';

        if ($notice === 'saved')
        {
            $noticeMessage = 'Rules category saved and rules update published.';
        }
        else if ($notice === 'schema_missing')
        {
            $noticeMessage = 'The rules database tables are not available yet. Please apply the latest rules migration first.';
            $noticeType = 'error';
        }

        $categoryRows = [];
        foreach (RulesModel::listCategorySummaries() as $row)
        {
            $categoryRows[] = [
                TypeHelper::toInt($row['id'] ?? 0) ?? 0,
                TypeHelper::toString($row['title'] ?? ''),
                TypeHelper::toString($row['slug'] ?? ''),
                TypeHelper::toString($row['description'] ?? ''),
                TypeHelper::toInt($row['sort_order'] ?? 0) ?? 0,
                !empty($row['is_active']) ? 1 : 0,
                TypeHelper::toInt($row['rule_count'] ?? 0) ?? 0,
            ];
        }

        self::assignPanelPage(
            $template,
            'rule-categories',
            'Rules Categories',
            'Maintain the top-level rules sections that group related rule entries for the public rules page.',
            'rules'
        );

        $template->assign('control_panel_notice', $noticeMessage);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->assign('rule_category_rows', $categoryRows);
        $template->render('panel/control_panel_rule_categories.html');
    }

    /**
     * Create one rules category.
     *
     * @return void
     */
    public static function ruleCategoryCreate(): void
    {
        self::requirePanelPermission('manage_rules');
        self::handleRuleCategoryEditor(0);
    }

    /**
     * Edit one rules category.
     *
     * @param int $id
     * @return void
     */
    public static function ruleCategoryEdit(int $id): void
    {
        self::requirePanelPermission('manage_rules');
        self::handleRuleCategoryEditor($id);
    }

    /**
     * Create one rule entry.
     *
     * @return void
     */
    public static function ruleCreate(): void
    {
        self::requirePanelPermission('manage_rules');
        self::handleRuleEditor(0);
    }

    /**
     * Edit one rule entry.
     *
     * @param int $id
     * @return void
     */
    public static function ruleEdit(int $id): void
    {
        self::requirePanelPermission('manage_rules');
        self::handleRuleEditor($id);
    }

    /**
     * Shared rules category create/edit handler.
     *
     * @param int $id
     * @return void
     */
    private static function handleRuleCategoryEditor(int $id): void
    {
        if (!RulesModel::isSchemaAvailable())
        {
            header('Location: /panel/rules/categories?notice=schema_missing');
            exit();
        }

        $category = $id > 0 ? RulesModel::findCategoryById($id) : null;
        if ($id > 0 && !$category)
        {
            self::renderErrorPage(404, 'Category Not Found', 'That rules category could not be found.');
            return;
        }

        $errors = [];
        $currentUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $isCreate = $category === null;
        $form = [
            'title' => TypeHelper::toString($category['title'] ?? '', allowEmpty: true) ?? '',
            'slug' => TypeHelper::toString($category['slug'] ?? '', allowEmpty: true) ?? '',
            'description' => TypeHelper::toString($category['description'] ?? '', allowEmpty: true) ?? '',
            'sort_order' => TypeHelper::toInt($category['sort_order'] ?? 0) ?? 0,
            'is_active' => !empty($category['is_active']) ? 1 : 0,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $form['title'] = trim(Security::sanitizeString($_POST['title'] ?? ''));
            $submittedSlug = trim(Security::sanitizeString($_POST['slug'] ?? ''));
            $form['slug'] = $submittedSlug !== '' ? self::normalizeRulesSlug($submittedSlug) : self::normalizeRulesSlug($form['title']);
            $form['description'] = trim(Security::sanitizeString($_POST['description'] ?? ''));
            $form['sort_order'] = TypeHelper::toInt($_POST['sort_order'] ?? 0) ?? 0;
            $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

            if ($form['title'] === '')
            {
                $errors[] = 'Category title is required.';
            }

            if ($form['slug'] === '')
            {
                $errors[] = 'Category slug is required.';
            }

            $existing = RulesModel::findCategoryBySlug($form['slug']);
            if ($existing && (TypeHelper::toInt($existing['id'] ?? 0) ?? 0) !== $id)
            {
                $errors[] = 'Another rules category already uses that slug.';
            }

            if (empty($errors))
            {
                RulesModel::saveCategory([
                    'id' => $id,
                    'title' => $form['title'],
                    'slug' => $form['slug'],
                    'description' => $form['description'],
                    'sort_order' => $form['sort_order'],
                    'is_active' => $form['is_active'],
                    'created_by' => $currentUserId,
                    'updated_by' => $currentUserId,
                ]);

                RulesModel::publishCurrentRulesRelease(
                    $currentUserId,
                    ($isCreate ? 'Rules category created: ' : 'Rules category updated: ') . $form['title'],
                    self::getRulesEnforcementWindowDays()
                );

                header('Location: /panel/rules/categories?notice=saved');
                exit();
            }
        }

        $template = self::initTemplate();
        self::assignPanelPage(
            $template,
            'rule-categories',
            $isCreate ? 'Create Rules Category' : 'Edit Rules Category',
            'Manage one rules category and publish the latest rules structure to members after saving.',
            'rules'
        );

        $template->assign('rule_category_form_mode', $isCreate ? 'create' : 'edit');
        $template->assign('rule_category_id', $id);
        $template->assign('rule_category_title', $form['title']);
        $template->assign('rule_category_slug', $form['slug']);
        $template->assign('rule_category_description', $form['description']);
        $template->assign('rule_category_sort_order', $form['sort_order']);
        $template->assign('rule_category_is_active', $form['is_active']);
        $template->assign('error', $errors);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_rule_category_edit.html');
    }

    /**
     * Shared rule create/edit handler.
     *
     * @param int $id
     * @return void
     */
    private static function handleRuleEditor(int $id): void
    {
        if (!RulesModel::isSchemaAvailable())
        {
            header('Location: /panel/rules?notice=schema_missing');
            exit();
        }

        $rule = $id > 0 ? RulesModel::findRuleById($id) : null;
        if ($id > 0 && !$rule)
        {
            self::renderErrorPage(404, 'Rule Not Found', 'That rule entry could not be found.');
            return;
        }

        $errors = [];
        $currentUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $isCreate = $rule === null;
        $form = [
            'category_id' => TypeHelper::toInt($rule['category_id'] ?? 0) ?? 0,
            'title' => TypeHelper::toString($rule['title'] ?? '', allowEmpty: true) ?? '',
            'slug' => TypeHelper::toString($rule['slug'] ?? '', allowEmpty: true) ?? '',
            'body' => TypeHelper::toString($rule['body'] ?? '', allowEmpty: true) ?? '',
            'sort_order' => TypeHelper::toInt($rule['sort_order'] ?? 0) ?? 0,
            'is_active' => !empty($rule['is_active']) ? 1 : 0,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
            if (!Security::verifyCsrfToken($csrf))
            {
                $errors[] = 'Invalid request.';
            }

            $form['category_id'] = TypeHelper::toInt($_POST['category_id'] ?? 0) ?? 0;
            $form['title'] = trim(Security::sanitizeString($_POST['title'] ?? ''));
            $submittedSlug = trim(Security::sanitizeString($_POST['slug'] ?? ''));
            $form['slug'] = $submittedSlug !== '' ? self::normalizeRulesSlug($submittedSlug) : self::normalizeRulesSlug($form['title']);
            $form['body'] = trim(Security::sanitizeString($_POST['body'] ?? ''));
            $form['sort_order'] = TypeHelper::toInt($_POST['sort_order'] ?? 0) ?? 0;
            $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

            if ($form['category_id'] < 1)
            {
                $errors[] = 'Choose a rules category.';
            }
            else if (!RulesModel::findCategoryById($form['category_id']))
            {
                $errors[] = 'The selected rules category does not exist.';
            }

            if ($form['title'] === '')
            {
                $errors[] = 'Rule title is required.';
            }

            if ($form['slug'] === '')
            {
                $errors[] = 'Rule slug is required.';
            }

            if ($form['body'] === '')
            {
                $errors[] = 'Rule body is required.';
            }

            $existing = RulesModel::findRuleBySlug($form['slug']);
            if ($existing && (TypeHelper::toInt($existing['id'] ?? 0) ?? 0) !== $id)
            {
                $errors[] = 'Another rule already uses that slug.';
            }

            if (empty($errors))
            {
                RulesModel::saveRule([
                    'id' => $id,
                    'category_id' => $form['category_id'],
                    'title' => $form['title'],
                    'slug' => $form['slug'],
                    'body' => $form['body'],
                    'sort_order' => $form['sort_order'],
                    'is_active' => $form['is_active'],
                    'created_by' => $currentUserId,
                    'updated_by' => $currentUserId,
                ]);

                RulesModel::publishCurrentRulesRelease(
                    $currentUserId,
                    ($isCreate ? 'Rule created: ' : 'Rule updated: ') . $form['title'],
                    self::getRulesEnforcementWindowDays()
                );

                header('Location: /panel/rules?notice=saved');
                exit();
            }
        }

        $categoryOptions = [];
        foreach (RulesModel::listCategoryOptions() as $row)
        {
            $categoryOptions[] = [
                TypeHelper::toInt($row['id'] ?? 0) ?? 0,
                TypeHelper::toString($row['title'] ?? ''),
            ];
        }

        $template = self::initTemplate();
        self::assignPanelPage(
            $template,
            'rules',
            $isCreate ? 'Create Rule Entry' : 'Edit Rule Entry',
            'Update one rule entry, place it inside a rules category, and publish the latest rules update after saving.',
            'rules'
        );

        $template->assign('rule_form_mode', $isCreate ? 'create' : 'edit');
        $template->assign('rule_id', $id);
        $template->assign('rule_category_id', $form['category_id']);
        $template->assign('rule_title', $form['title']);
        $template->assign('rule_slug', $form['slug']);
        $template->assign('rule_body', $form['body']);
        $template->assign('rule_sort_order', $form['sort_order']);
        $template->assign('rule_is_active', $form['is_active']);
        $template->assign('rule_category_options', $categoryOptions);
        $template->assign('error', $errors);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_rule_edit.html');
    }

    /**
     * Render application settings overview page.
     *
     * @return void
     */
    public static function settings(): void
    {
        self::renderSettingsPage('');
    }

    /**
     * Render the category settings manager page.
     *
     * @return void
     */
    public static function settingsCategories(): void
    {
        self::renderSettingsPage('', true);
    }

    /**
     * Render a specific settings category page.
     *
     * @param string $category
     * @return void
     */
    public static function settingsCategory(string $category): void
    {
        self::renderSettingsPage($category);
    }

    /**
     * Render the Control Panel settings experience.
     *
     * @param string $selectedCategory
     * @param bool $manageCategories
     * @return void
     */
    private static function renderSettingsPage(string $selectedCategory = '', bool $manageCategories = false): void
    {
        self::requirePanelPermission('manage_settings');

        $template = self::initTemplate();
        $selectedCategory = self::normalizeSettingsCategorySlug($selectedCategory);
        [$notice, $noticeType] = self::resolveSettingsPanelNotice();

        $catalogState = self::buildSettingsPageCatalogState();
        $categoryCatalog = $catalogState['category_catalog'];
        $definitionCatalog = $catalogState['definition_catalog'];
        $builtInDefinitionKeys = $catalogState['built_in_definition_keys'];
        $rowMap = $catalogState['row_map'];

        if ($selectedCategory !== '' && !isset($categoryCatalog[$selectedCategory]))
        {
            $selectedCategory = '';
            if ($notice === '')
            {
                $notice = 'That settings category was not found.';
                $noticeType = 'error';
            }
        }

        $groupedSettings = self::buildSettingsGroupedRows($definitionCatalog, $rowMap, $categoryCatalog, $builtInDefinitionKeys);
        [$categoryCards, $managedCategories] = self::buildSettingsCategoryCollections($categoryCatalog, $groupedSettings, $selectedCategory);
        $summary = self::buildSettingsSummaryCounts($groupedSettings, $rowMap, $categoryCatalog);
        $pageState = self::buildSettingsPageState($categoryCatalog, $groupedSettings, $selectedCategory, $manageCategories);

        self::assignPanelPage(
            $template,
            TypeHelper::toString($pageState['current_nav'] ?? 'settings'),
            TypeHelper::toString($pageState['page_title'] ?? 'Application Settings'),
            TypeHelper::toString($pageState['page_description'] ?? ''),
            'configuration'
        );

        $selectedSettings = $groupedSettings[$selectedCategory] ?? [];

        $template->assign('settings_categories', $categoryCards);
        $template->assign('settings_selected_category', $selectedCategory);
        $template->assign('settings_selected_category_title', TypeHelper::toString($pageState['selected_category_title'] ?? 'Settings Overview'));
        $template->assign('settings_selected_category_description', TypeHelper::toString($pageState['selected_category_description'] ?? ''));
        $template->assign('settings_selected_rows', $selectedSettings);
        $template->assign('settings_manage_categories', $managedCategories);
        $template->assign('settings_manage_mode', $manageCategories ? 1 : 0);
        $template->assign('settings_category_icon_options', self::getSettingsCategoryIconOptions());
        $template->assign('settings_overview_url', self::buildSettingsCategoryUrl(''));
        $template->assign('settings_category_manager_url', self::buildSettingsCategoryManagerUrl());
        $template->assign('settings_total_categories', $manageCategories ? count($managedCategories) : count($categoryCards));
        $template->assign('settings_total_settings', TypeHelper::toInt($summary['total_settings_count'] ?? 0) ?? 0);
        $template->assign('settings_total_stored', TypeHelper::toInt($summary['stored_settings_count'] ?? 0) ?? 0);
        $template->assign('settings_total_fallback', TypeHelper::toInt($summary['fallback_settings_count'] ?? 0) ?? 0);
        $template->assign('settings_total_built_in_categories', TypeHelper::toInt($summary['built_in_category_count'] ?? 0) ?? 0);
        $template->assign('settings_total_custom_categories', TypeHelper::toInt($summary['custom_category_count'] ?? 0) ?? 0);
        $template->assign('settings_selected_icon', TypeHelper::toString($pageState['selected_category_icon'] ?? 'fa-sliders'));
        $template->assign('settings_selected_count', count($selectedSettings));
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('control_panel_notice', $notice);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->render('panel/control_panel_settings.html');
    }


    /**
     * Save an application setting (admin action).
     *
     * Validates CSRF and then inserts/updates app_settings_data via upsert.
     *
     * @return void
     */
    public static function settingsSave(): void
    {
        self::requirePanelPermission('manage_settings');

        $redirectBase = self::buildSettingsCategoryUrl('');
        self::requireSettingsPostRequest($redirectBase);

        $request = self::readSettingsSaveRequest();
        $redirectBase = $request['redirect_base'];
        self::verifySettingsCsrfOrRedirect(TypeHelper::toString($request['csrf'] ?? ''), $redirectBase);
        self::guardSettingsSaveKeyOrRedirect(TypeHelper::toString($request['key'] ?? ''), $redirectBase);

        $normalized = self::normalizeSettingsValueForStorage(
            TypeHelper::toString($request['key'] ?? ''),
            TypeHelper::toString($request['value'] ?? ''),
            TypeHelper::toString($request['type'] ?? 'string')
        );

        if (!$normalized['valid'])
        {
            $errorCode = $normalized['error'] === 'length' ? 'length' : 'value';
            header('Location: ' . $redirectBase . '?error=' . $errorCode);
            exit();
        }

        $metadata = self::buildSettingsSaveMetadata(
            TypeHelper::toString($request['key'] ?? ''),
            TypeHelper::toString($request['type'] ?? 'string'),
            TypeHelper::toString($request['category'] ?? ''),
            SettingsModel::findSettingByKey(TypeHelper::toString($request['key'] ?? ''))
        );

        $category = TypeHelper::toString($metadata['category'] ?? 'custom');
        $categoryId = self::ensureSettingsCategoryExists($category, !empty($metadata['is_built_in']) ? 1 : 0);
        if ($categoryId <= 0)
        {
            header('Location: ' . $redirectBase . '?error=category');
            exit();
        }

        SettingsModel::upsertSetting([
            'category_id' => $categoryId,
            'key_name' => TypeHelper::toString($request['key'] ?? ''),
            'title' => TypeHelper::toString($metadata['title'] ?? self::humanizeSettingsToken(TypeHelper::toString($request['key'] ?? ''))),
            'description' => TypeHelper::toString($metadata['description'] ?? ''),
            'value_data' => TypeHelper::toString($normalized['value'] ?? ''),
            'type_name' => TypeHelper::toString($request['type'] ?? 'string'),
            'input_type' => TypeHelper::toString($metadata['input_type'] ?? 'text'),
            'sort_order' => TypeHelper::toInt($metadata['sort_order'] ?? 0) ?? 0,
            'is_system' => !empty($metadata['is_system']) ? 1 : 0,
        ]);

        header('Location: ' . self::buildSettingsCategoryUrl($category) . '?notice=saved');
        exit();
    }



    /**
     * Save category display details for the settings UI.
     *
     * @return void
     */
    public static function settingsCategorySave(): void
    {
        self::requirePanelPermission('manage_settings');

        $redirectBase = self::buildSettingsCategoryManagerUrl();
        self::requireSettingsPostRequest($redirectBase);
        self::verifySettingsCsrfOrRedirect(Security::sanitizeString($_POST['csrf_token'] ?? ''), $redirectBase);

        $baseCategoryCatalog = self::getSettingsCategoryCatalog();
        $category = self::normalizeSettingsCategorySlug(TypeHelper::toString($_POST['category_slug'] ?? ''));
        if ($category === '')
        {
            $category = self::normalizeSettingsCategorySlug(TypeHelper::toString($_POST['new_category_slug'] ?? ''));
        }

        if ($category === '')
        {
            header('Location: ' . $redirectBase . '?error=category');
            exit();
        }

        $title = self::normalizeSettingsCategoryDisplayTitle(trim(TypeHelper::toString($_POST['category_title'] ?? '')));
        $description = trim(TypeHelper::toString($_POST['category_description'] ?? ''));
        $icon = trim(TypeHelper::toString($_POST['category_icon'] ?? ''));

        $baseMeta = $baseCategoryCatalog[$category] ?? [];
        $isSystem = !empty($baseMeta['is_system']) ? 1 : 0;
        if ($title === '')
        {
            $title = self::normalizeSettingsCategoryDisplayTitle(TypeHelper::toString($baseMeta['title'] ?? self::humanizeSettingsToken($category)));
        }

        if ($description === '')
        {
            $description = TypeHelper::toString($baseMeta['description'] ?? 'Additional registry entries stored for this settings group.');
        }

        if ($icon === '' || !preg_match('/^fa-[a-z0-9-]+$/', $icon))
        {
            $icon = TypeHelper::toString($baseMeta['icon'] ?? 'fa-sliders');
        }

        if (strlen($title) > 80 || strlen($description) > 255 || strlen($icon) > 32)
        {
            header('Location: ' . $redirectBase . '?error=length');
            exit();
        }

        $existing = SettingsModel::findCategoryBySlug($category);
        $sortOrder = TypeHelper::toInt($existing['sort_order'] ?? ($baseMeta['sort_order'] ?? 0)) ?? 0;
        if ($sortOrder <= 0)
        {
            $sortOrder = SettingsModel::getNextCategorySortOrder();
        }

        if (!empty($existing['is_system']))
        {
            $isSystem = 1;
        }

        SettingsModel::upsertCategory([
            'slug' => $category,
            'title' => $title,
            'description' => $description,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_system' => $isSystem,
        ]);

        header('Location: ' . $redirectBase . '?notice=category_saved');
        exit();
    }


    /**
     * Delete category metadata or remove a custom category when it is empty.
     *
     * @return void
     */
    public static function settingsCategoryDelete(): void
    {
        self::requirePanelPermission('manage_settings');

        $redirectBase = self::buildSettingsCategoryManagerUrl();
        self::requireSettingsPostRequest($redirectBase);
        self::verifySettingsCsrfOrRedirect(Security::sanitizeString($_POST['csrf_token'] ?? ''), $redirectBase);

        $category = self::normalizeSettingsCategorySlug(TypeHelper::toString($_POST['category_slug'] ?? ''));
        if ($category === '')
        {
            header('Location: ' . $redirectBase . '?error=category');
            exit();
        }

        $baseCategoryCatalog = self::getSettingsCategoryCatalog();
        $isSystemCategory = isset($baseCategoryCatalog[$category]);
        $storedCount = self::countStoredSettingsEntriesForCategory($category);

        if (!$isSystemCategory && $storedCount > 0)
        {
            header('Location: ' . $redirectBase . '?error=category_not_empty');
            exit();
        }

        if ($isSystemCategory)
        {
            $baseMeta = $baseCategoryCatalog[$category];
            SettingsModel::upsertCategory([
                'slug' => $category,
                'title' => self::normalizeSettingsCategoryDisplayTitle(TypeHelper::toString($baseMeta['title'] ?? self::humanizeSettingsToken($category))),
                'description' => TypeHelper::toString($baseMeta['description'] ?? ''),
                'icon' => TypeHelper::toString($baseMeta['icon'] ?? 'fa-sliders'),
                'sort_order' => TypeHelper::toInt($baseMeta['sort_order'] ?? 0) ?? 0,
                'is_system' => 1,
            ]);

            header('Location: ' . $redirectBase . '?notice=category_reset');
            exit();
        }

        SettingsModel::deleteCategoryBySlug($category);
        header('Location: ' . $redirectBase . '?notice=category_deleted');
        exit();
    }


    /**
     * Delete a stored registry entry.
     *
     * Built-in settings fall back to config.php when removed, while custom
     * registry entries are deleted outright.
     *
     * @return void
     */
    public static function settingsDelete(): void
    {
        self::requirePanelPermission('manage_settings');

        $redirectBase = self::buildSettingsCategoryUrl('');
        self::requireSettingsPostRequest($redirectBase);

        $category = self::normalizeSettingsCategorySlug(Security::sanitizeString($_POST['category'] ?? ''));
        $redirectBase = self::buildSettingsCategoryUrl($category);
        self::verifySettingsCsrfOrRedirect(Security::sanitizeString($_POST['csrf_token'] ?? ''), $redirectBase);

        $key = self::normalizeSettingsKey(Security::sanitizeString($_POST['key'] ?? ''));
        if ($key === '')
        {
            header('Location: ' . $redirectBase . '?error=key');
            exit();
        }

        if (self::isSettingsReservedKey($key))
        {
            header('Location: ' . $redirectBase . '?error=reserved');
            exit();
        }

        $definitionCatalog = self::getSettingsDefinitionCatalog();
        $builtInDefinition = $definitionCatalog[$key] ?? null;

        if ($builtInDefinition !== null)
        {
            $type = self::normalizeSettingsType(TypeHelper::toString($builtInDefinition['type'] ?? 'string'));
            $defaultValue = $builtInDefinition['default'] ?? '';
            $normalized = self::normalizeSettingsValueForStorage($key, is_string($defaultValue) ? $defaultValue : json_encode($defaultValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $type);
            $categorySlug = self::normalizeSettingsCategorySlug(TypeHelper::toString($builtInDefinition['category'] ?? ''));
            $categoryId = self::ensureSettingsCategoryExists($categorySlug, 1);

            SettingsModel::upsertSetting([
                'category_id' => $categoryId,
                'key_name' => $key,
                'title' => TypeHelper::toString($builtInDefinition['label'] ?? self::humanizeSettingsToken($key)),
                'description' => TypeHelper::toString($builtInDefinition['description'] ?? ''),
                'value_data' => $normalized['value'],
                'type_name' => $type,
                'input_type' => TypeHelper::toString($builtInDefinition['input'] ?? 'text'),
                'sort_order' => TypeHelper::toInt($builtInDefinition['sort_order'] ?? 0) ?? 0,
                'is_system' => 1,
            ]);

            header('Location: ' . self::buildSettingsCategoryUrl($categorySlug) . '?notice=reset');
            exit();
        }

        SettingsModel::deleteSettingByKey($key);
        header('Location: ' . $redirectBase . '?notice=deleted');
        exit();
    }




    /**
     * Resolve the current settings page notice banner.
     *
     * @return array{0:string,1:string}
     */
    private static function resolveSettingsPanelNotice(): array
    {
        $settingsError = Security::sanitizeString($_GET['error'] ?? '');
        $settingsNotice = Security::sanitizeString($_GET['notice'] ?? '');

        if ($settingsNotice === 'saved' || isset($_GET['success']))
        {
            return ['Setting saved successfully.', 'success'];
        }

        $noticeMap = [
            'reset' => ['Setting reset to its default value successfully.', 'success'],
            'deleted' => ['Registry entry removed successfully.', 'success'],
            'category_saved' => ['Category details saved successfully.', 'success'],
            'category_reset' => ['Category display details reset to their default values.', 'success'],
            'category_deleted' => ['Custom category removed successfully.', 'success'],
        ];

        if (isset($noticeMap[$settingsNotice]))
        {
            return $noticeMap[$settingsNotice];
        }

        $errorMap = [
            'csrf' => ['The request could not be verified. Please try again.', 'error'],
            'key' => ['A valid registry key is required before saving.', 'error'],
            'reserved' => ['That key belongs to protected runtime configuration and cannot be managed from this settings area.', 'error'],
            'value' => ['The setting value is not valid for that field type.', 'error'],
            'length' => ['That value is too large to store.', 'error'],
            'category' => ['A valid category slug is required before saving category details.', 'error'],
            'category_not_empty' => ['That category still contains settings entries. Remove or move those entries before deleting the category.', 'error'],
        ];

        return $errorMap[$settingsError] ?? ['', ''];
    }

    /**
     * Build the settings page catalogs used by the renderer.
     *
     * @return array<string, mixed>
     */
    private static function buildSettingsPageCatalogState(): array
    {
        $builtInDefinitionCatalog = self::getSettingsDefinitionCatalog();
        $definitionCatalog = $builtInDefinitionCatalog;
        $builtInDefinitionKeys = array_fill_keys(array_keys($builtInDefinitionCatalog), true);
        $categoryCatalog = self::createBaseSettingsCategoryCatalog(self::getSettingsCategoryCatalog());

        self::mergeStoredSettingsCategoriesIntoCatalog($categoryCatalog, SettingsModel::listCategories());
        $rowMap = self::mergeStoredSettingsRowsIntoCatalog($definitionCatalog, $builtInDefinitionKeys, $categoryCatalog, SettingsModel::listSettingRows());
        self::sortSettingsCategoryCatalog($categoryCatalog);

        return [
            'built_in_definition_catalog' => $builtInDefinitionCatalog,
            'definition_catalog' => $definitionCatalog,
            'built_in_definition_keys' => $builtInDefinitionKeys,
            'category_catalog' => $categoryCatalog,
            'row_map' => $rowMap,
        ];
    }

    /**
     * Create the base category catalog from the built-in settings registry.
     *
     * @param array $baseCategoryCatalog
     * @return array<string, array<string, mixed>>
     */
    private static function createBaseSettingsCategoryCatalog(array $baseCategoryCatalog): array
    {
        $categoryCatalog = [];
        foreach ($baseCategoryCatalog as $slug => $meta)
        {
            $categoryCatalog[$slug] = [
                'title' => self::normalizeSettingsCategoryDisplayTitle(TypeHelper::toString($meta['title'] ?? self::humanizeSettingsToken($slug))),
                'description' => TypeHelper::toString($meta['description'] ?? ''),
                'icon' => TypeHelper::toString($meta['icon'] ?? 'fa-sliders'),
                'sort_order' => TypeHelper::toInt($meta['sort_order'] ?? 0) ?? 0,
                'is_system' => !empty($meta['is_system']) ? 1 : 0,
                'is_custom' => 0,
                'has_meta' => 0,
            ];
        }

        return $categoryCatalog;
    }

    /**
     * Merge stored category rows into the normalized settings category catalog.
     *
     * @param array $categoryCatalog
     * @param array $categoryRows
     * @return void
     */
    private static function mergeStoredSettingsCategoriesIntoCatalog(array &$categoryCatalog, array $categoryRows): void
    {
        foreach ($categoryRows as $row)
        {
            $slug = self::normalizeSettingsCategorySlug(TypeHelper::toString($row['slug'] ?? ''));
            if ($slug === '')
            {
                continue;
            }

            $categoryCatalog[$slug] = [
                'title' => self::normalizeSettingsCategoryDisplayTitle(TypeHelper::toString($row['title'] ?? ($categoryCatalog[$slug]['title'] ?? self::humanizeSettingsToken($slug)))),
                'description' => TypeHelper::toString($row['description'] ?? ($categoryCatalog[$slug]['description'] ?? '')),
                'icon' => TypeHelper::toString($row['icon'] ?? ($categoryCatalog[$slug]['icon'] ?? 'fa-sliders')),
                'sort_order' => TypeHelper::toInt($row['sort_order'] ?? ($categoryCatalog[$slug]['sort_order'] ?? 9999)) ?? 9999,
                'is_system' => !empty($row['is_system']) ? 1 : 0,
                'is_custom' => empty($row['is_system']) ? 1 : 0,
                'has_meta' => 1,
                'updated_at' => TypeHelper::toString($row['updated_at'] ?? ''),
            ];
        }
    }

    /**
     * Merge stored setting rows into the settings catalogs used by the UI.
     *
     * @param array $definitionCatalog
     * @param array $builtInDefinitionKeys
     * @param array $categoryCatalog
     * @param array $settingsRows
     * @return array<string, array<string, mixed>>
     */
    private static function mergeStoredSettingsRowsIntoCatalog(array &$definitionCatalog, array $builtInDefinitionKeys, array &$categoryCatalog, array $settingsRows): array
    {
        $rowMap = [];
        foreach ($settingsRows as $row)
        {
            $key = self::normalizeSettingsKey(TypeHelper::toString($row['key'] ?? ''));
            if ($key === '')
            {
                continue;
            }

            $categorySlug = self::normalizeSettingsCategorySlug(TypeHelper::toString($row['category_slug'] ?? ''));
            if ($categorySlug === '')
            {
                $categorySlug = self::inferSettingsCategoryFromKey($key);
            }

            self::ensureSettingsCatalogCategory($categoryCatalog, $categorySlug);
            $row['category_slug'] = $categorySlug;
            $rowMap[$key] = $row;

            if (!isset($builtInDefinitionKeys[$key]))
            {
                $definitionCatalog[$key] = self::buildGeneratedStoredSettingsDefinition($key, $row, $categorySlug);
            }
        }

        return $rowMap;
    }

    /**
     * Ensure a settings category exists in the in-memory catalog.
     *
     * @param array $categoryCatalog
     * @param string $categorySlug
     * @return void
     */
    private static function ensureSettingsCatalogCategory(array &$categoryCatalog, string $categorySlug): void
    {
        if ($categorySlug === '')
        {
            $categorySlug = 'custom';
        }

        if (!isset($categoryCatalog[$categorySlug]))
        {
            $categoryCatalog[$categorySlug] = [
                'title' => self::humanizeSettingsToken($categorySlug),
                'description' => 'Additional registry entries stored for this settings group.',
                'icon' => 'fa-sliders',
                'sort_order' => 9999,
                'is_system' => 0,
                'is_custom' => 1,
                'has_meta' => 0,
            ];
        }
    }

    /**
     * Build a generic settings definition for stored registry entries.
     *
     * @param string $key
     * @param array $row
     * @param string $categorySlug
     * @return array<string, mixed>
     */
    private static function buildGeneratedStoredSettingsDefinition(string $key, array $row, string $categorySlug): array
    {
        $generated = class_exists('SettingsRegistry')
            ? SettingsRegistry::buildGeneratedDefinition($key, TypeHelper::toString($row['type'] ?? 'string'), $row)
            : [
                'category' => $categorySlug,
                'title' => TypeHelper::toString($row['title'] ?? self::humanizeSettingsToken($key)),
                'description' => TypeHelper::toString($row['description'] ?? ''),
                'type' => self::normalizeSettingsType(TypeHelper::toString($row['type'] ?? 'string')),
                'input' => TypeHelper::toString($row['input_type'] ?? 'text'),
                'help' => 'This setting is not part of the built-in catalog, so generic editing rules are being used.',
                'default' => '',
                'sort_order' => TypeHelper::toInt($row['sort_order'] ?? 9999) ?? 9999,
                'is_system' => 0,
            ];

        return [
            'category' => $categorySlug,
            'label' => TypeHelper::toString($generated['title'] ?? self::humanizeSettingsToken($key)),
            'description' => TypeHelper::toString($generated['description'] ?? ''),
            'type' => self::normalizeSettingsType(TypeHelper::toString($row['type'] ?? ($generated['type'] ?? 'string'))),
            'input' => TypeHelper::toString($row['input_type'] ?? ($generated['input'] ?? 'text')),
            'help' => TypeHelper::toString($generated['help'] ?? ''),
            'placeholder' => TypeHelper::toString($generated['placeholder'] ?? ''),
            'min' => isset($generated['min']) ? (string) $generated['min'] : '',
            'max' => isset($generated['max']) ? (string) $generated['max'] : '',
            'options' => $generated['options'] ?? [],
            'sort_order' => TypeHelper::toInt($row['sort_order'] ?? ($generated['sort_order'] ?? 9999)) ?? 9999,
            'default' => $generated['default'] ?? '',
            'is_system' => !empty($row['is_system']) ? 1 : 0,
        ];
    }

    /**
     * Sort the settings category catalog into display order.
     *
     * @param array $categoryCatalog
     * @return void
     */
    private static function sortSettingsCategoryCatalog(array &$categoryCatalog): void
    {
        uasort($categoryCatalog, static function (array $left, array $right): int
        {
            $leftOrder = TypeHelper::toInt($left['sort_order'] ?? 9999) ?? 9999;
            $rightOrder = TypeHelper::toInt($right['sort_order'] ?? 9999) ?? 9999;
            if ($leftOrder === $rightOrder)
            {
                return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            }

            return $leftOrder <=> $rightOrder;
        });
    }

    /**
     * Build grouped settings rows for the settings template.
     *
     * @param array $definitionCatalog
     * @param array $rowMap
     * @param array $categoryCatalog
     * @param array $builtInDefinitionKeys
     * @return array<string, array<int, array<int, mixed>>>
     */
    private static function buildSettingsGroupedRows(array $definitionCatalog, array $rowMap, array &$categoryCatalog, array $builtInDefinitionKeys): array
    {
        $groupedSettingsRaw = [];
        foreach ($definitionCatalog as $key => $definition)
        {
            $categorySlug = self::normalizeSettingsCategorySlug(TypeHelper::toString($definition['category'] ?? ''));
            if ($categorySlug === '')
            {
                $categorySlug = self::inferSettingsCategoryFromKey($key);
            }

            self::ensureSettingsCatalogCategory($categoryCatalog, $categorySlug);
            $groupedSettingsRaw[$categorySlug][] = self::buildSettingsDisplayRow($key, $definition, $rowMap[$key] ?? null, $categorySlug, $builtInDefinitionKeys);
        }

        return self::normalizeGroupedSettingsForTemplate($groupedSettingsRaw);
    }

    /**
     * Build one settings row for the category settings template.
     *
     * @param string $key
     * @param array $definition
     * @param array|null $row
     * @param string $categorySlug
     * @param array $builtInDefinitionKeys
     * @return array<string, mixed>
     */
    private static function buildSettingsDisplayRow(string $key, array $definition, ?array $row, string $categorySlug, array $builtInDefinitionKeys): array
    {
        $type = self::normalizeSettingsType(TypeHelper::toString($row['type'] ?? ($definition['type'] ?? 'string')));
        $input = TypeHelper::toString($row['input_type'] ?? ($definition['input'] ?? 'text'));

        if ($row !== null)
        {
            $valueForDisplay = self::formatSettingsValueForDisplay($row['value'] ?? '', $type, true);
            $statusLabel = TypeHelper::toString(DateHelper::date_only_format(TypeHelper::toString($row['updated_at'] ?? '')) ?? 'Stored in database');
            $statusClass = 'stored';
            $hasStoredRow = 1;
        }
        else
        {
            $defaultValue = $definition['default'] ?? '';
            $valueForDisplay = self::formatSettingsValueForDisplay($defaultValue, $type, false);
            $statusLabel = 'Using registry default';
            $statusClass = 'fallback';
            $hasStoredRow = 0;
        }

        $valueAttribute = self::formatSettingsValueForAttribute($valueForDisplay, $type);
        $valueTextArea = $type === 'json' ? $valueForDisplay : TypeHelper::toString($valueForDisplay);

        return [
            'label' => TypeHelper::toString($row['title'] ?? ($definition['label'] ?? self::humanizeSettingsToken($key))),
            'description' => TypeHelper::toString($row['description'] ?? ($definition['description'] ?? '')),
            'key' => $key,
            'type' => $type,
            'status' => $statusLabel,
            'input' => $input,
            'value_attr' => TypeHelper::toString($valueAttribute),
            'value_text' => TypeHelper::toString($valueTextArea),
            'placeholder' => TypeHelper::toString($definition['placeholder'] ?? ''),
            'min' => TypeHelper::toString($definition['min'] ?? ''),
            'max' => TypeHelper::toString($definition['max'] ?? ''),
            'help' => TypeHelper::toString($definition['help'] ?? ''),
            'options' => self::buildSettingsInputOptions($input, $valueAttribute, $valueForDisplay, $definition),
            'category' => $categorySlug,
            'status_class' => $statusClass,
            'has_stored_row' => $hasStoredRow,
            'is_custom_entry' => isset($builtInDefinitionKeys[$key]) ? 0 : 1,
            'sort_order' => TypeHelper::toInt($row['sort_order'] ?? ($definition['sort_order'] ?? 9999)) ?? 9999,
        ];
    }

    /**
     * Build settings form input options for boolean/select inputs.
     *
     * @param string $input
     * @param string $valueAttribute
     * @param mixed $valueForDisplay
     * @param array $definition
     * @return array<int, array<int, mixed>>
     */
    private static function buildSettingsInputOptions(string $input, string $valueAttribute, $valueForDisplay, array $definition): array
    {
        $options = [];

        if ($input === 'bool')
        {
            $currentBool = self::normalizeBoolStorageValue($valueForDisplay);
            return [
                ['1', 'Enable', $currentBool === '1' ? 1 : 0],
                ['0', 'Disable', $currentBool === '0' ? 1 : 0],
            ];
        }

        if ($input === 'select')
        {
            $currentValue = TypeHelper::toString($valueAttribute);
            foreach (($definition['options'] ?? []) as $option)
            {
                $optionValue = TypeHelper::toString($option[0] ?? '');
                $optionLabel = TypeHelper::toString($option[1] ?? $optionValue);
                $options[] = [$optionValue, $optionLabel, $optionValue === $currentValue ? 1 : 0];
            }
        }

        return $options;
    }

    /**
     * Normalize grouped settings rows into the compact template array format.
     *
     * @param array $groupedSettingsRaw
     * @return array<string, array<int, array<int, mixed>>>
     */
    private static function normalizeGroupedSettingsForTemplate(array $groupedSettingsRaw): array
    {
        $groupedSettings = [];
        foreach ($groupedSettingsRaw as $categorySlug => $settingsGroup)
        {
            usort($settingsGroup, static function (array $left, array $right): int
            {
                $leftOrder = TypeHelper::toInt($left['sort_order'] ?? 9999) ?? 9999;
                $rightOrder = TypeHelper::toInt($right['sort_order'] ?? 9999) ?? 9999;
                if ($leftOrder === $rightOrder)
                {
                    return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
                }

                return $leftOrder <=> $rightOrder;
            });

            foreach ($settingsGroup as $setting)
            {
                $groupedSettings[$categorySlug][] = [
                    $setting['label'],
                    $setting['description'],
                    $setting['key'],
                    $setting['type'],
                    $setting['status'],
                    $setting['input'],
                    $setting['value_attr'],
                    $setting['value_text'],
                    $setting['placeholder'],
                    $setting['min'],
                    $setting['max'],
                    $setting['help'],
                    $setting['options'],
                    $setting['category'],
                    $setting['status_class'],
                    $setting['has_stored_row'],
                    $setting['is_custom_entry'],
                ];
            }
        }

        return $groupedSettings;
    }

    /**
     * Build the category card and category manager collections.
     *
     * @param array $categoryCatalog
     * @param array $groupedSettings
     * @param string $selectedCategory
     * @return array{0: array<int, array<int, mixed>>, 1: array<int, array<int, mixed>>}
     */
    private static function buildSettingsCategoryCollections(array $categoryCatalog, array $groupedSettings, string $selectedCategory): array
    {
        $categoryCards = [];
        $managedCategories = [];

        foreach ($categoryCatalog as $categorySlug => $categoryMeta)
        {
            $count = count($groupedSettings[$categorySlug] ?? []);
            $countLabel = $count === 1 ? '1 setting' : $count . ' settings';
            $title = TypeHelper::toString($categoryMeta['title'] ?? self::humanizeSettingsToken($categorySlug));
            $description = TypeHelper::toString($categoryMeta['description'] ?? '');
            $icon = TypeHelper::toString($categoryMeta['icon'] ?? 'fa-sliders');
            $isSystem = !empty($categoryMeta['is_system']) ? 1 : 0;
            $hasMeta = !empty($categoryMeta['has_meta']) ? 1 : 0;
            $deleteAllowed = $isSystem ? 1 : ($count === 0 ? 1 : 0);
            $deleteLabel = $isSystem ? 'Reset Defaults' : 'Delete Category';
            $helperText = $isSystem
                ? 'Built-in category. Any changes here update the stored category record without touching the built-in registry defaults.'
                : ($count > 0
                    ? 'Custom category. Delete or move the settings inside this category before removing the category itself.'
                    : 'Custom category. This category is empty and can be removed safely.');

            $categoryCards[] = [
                $categorySlug,
                $title,
                $description,
                $countLabel,
                self::buildSettingsCategoryUrl($categorySlug),
                $selectedCategory === $categorySlug ? 1 : 0,
                $icon,
            ];

            $managedCategories[] = [
                $categorySlug,
                $title,
                $description,
                $icon,
                $countLabel,
                $isSystem,
                $hasMeta,
                $deleteAllowed,
                $deleteLabel,
                $helperText,
            ];
        }

        return [$categoryCards, $managedCategories];
    }

    /**
     * Build summary counters for the settings overview page.
     *
     * @param array $groupedSettings
     * @param array $rowMap
     * @param array $categoryCatalog
     * @return array<string, int>
     */
    private static function buildSettingsSummaryCounts(array $groupedSettings, array $rowMap, array $categoryCatalog): array
    {
        $totalSettingsCount = 0;
        foreach ($groupedSettings as $settingsGroup)
        {
            $totalSettingsCount += count($settingsGroup);
        }

        $storedSettingsCount = count($rowMap);
        $fallbackSettingsCount = max(0, $totalSettingsCount - $storedSettingsCount);
        $builtInCategoryCount = 0;
        $customCategoryCount = 0;
        foreach ($categoryCatalog as $categoryMeta)
        {
            if (!empty($categoryMeta['is_system']))
            {
                $builtInCategoryCount++;
            }
            else
            {
                $customCategoryCount++;
            }
        }

        return [
            'total_settings_count' => $totalSettingsCount,
            'stored_settings_count' => $storedSettingsCount,
            'fallback_settings_count' => $fallbackSettingsCount,
            'built_in_category_count' => $builtInCategoryCount,
            'custom_category_count' => $customCategoryCount,
        ];
    }

    /**
     * Build the current settings page heading and navigation state.
     *
     * @param array $categoryCatalog
     * @param array $groupedSettings
     * @param string $selectedCategory
     * @param bool $manageCategories
     * @return array<string, string>
     */
    private static function buildSettingsPageState(array $categoryCatalog, array $groupedSettings, string $selectedCategory, bool $manageCategories): array
    {
        $pageTitle = 'Application Settings';
        $pageDescription = 'Choose a settings category to manage board behavior with cleaner labels, safer inputs, and clearer descriptions.';
        $selectedCategoryTitle = 'Settings Overview';
        $selectedCategoryDescription = 'Select a category below to manage those settings on a dedicated page.';
        $selectedCategoryIcon = 'fa-sliders';
        $currentNav = 'settings';

        if ($selectedCategory !== '')
        {
            $categoryMeta = $categoryCatalog[$selectedCategory] ?? [];
            $selectedCategoryTitle = TypeHelper::toString($categoryMeta['title'] ?? self::humanizeSettingsToken($selectedCategory));
            $selectedCategoryDescription = TypeHelper::toString($categoryMeta['description'] ?? '');
            $selectedCategoryIcon = TypeHelper::toString($categoryMeta['icon'] ?? 'fa-sliders');
            $pageTitle = preg_match('/settings$/i', $selectedCategoryTitle) ? $selectedCategoryTitle : ($selectedCategoryTitle . ' Settings');
            $pageDescription = $selectedCategoryDescription;
        }
        else if ($manageCategories)
        {
            $pageTitle = 'Category Settings';
            $pageDescription = 'Manage settings category names, descriptions, icons, and custom category groups from one dedicated page.';
            $selectedCategoryTitle = 'Category Settings';
            $selectedCategoryDescription = 'Update category presentation details or create a new custom settings group for advanced registry keys.';
            $currentNav = 'settings-categories';
        }

        return [
            'page_title' => $pageTitle,
            'page_description' => $pageDescription,
            'selected_category_title' => $selectedCategoryTitle,
            'selected_category_description' => $selectedCategoryDescription,
            'selected_category_icon' => $selectedCategoryIcon,
            'current_nav' => $currentNav,
        ];
    }

    /**
     * Require a POST request for a settings action or redirect back.
     *
     * @param string $redirectUrl
     * @return void
     */
    private static function requireSettingsPostRequest(string $redirectUrl): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: ' . $redirectUrl);
            exit();
        }
    }

    /**
     * Verify a settings form CSRF token or redirect back with an error.
     *
     * @param string $csrf
     * @param string $redirectUrl
     * @return void
     */
    private static function verifySettingsCsrfOrRedirect(string $csrf, string $redirectUrl): void
    {
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: ' . $redirectUrl . '?error=csrf');
            exit();
        }
    }

    /**
     * Read the normalized settings save request payload.
     *
     * @return array<string, string>
     */
    private static function readSettingsSaveRequest(): array
    {
        $category = self::normalizeSettingsCategorySlug(Security::sanitizeString($_POST['category'] ?? ''));

        return [
            'category' => $category,
            'redirect_base' => self::buildSettingsCategoryUrl($category),
            'csrf' => Security::sanitizeString($_POST['csrf_token'] ?? ''),
            'key' => self::normalizeSettingsKey(Security::sanitizeString($_POST['key'] ?? '')),
            'type' => self::normalizeSettingsType(Security::sanitizeString($_POST['type'] ?? 'string')),
            'value' => trim((string) ($_POST['value'] ?? '')),
        ];
    }

    /**
     * Guard settings save key access before storing data.
     *
     * @param string $key
     * @param string $redirectUrl
     * @return void
     */
    private static function guardSettingsSaveKeyOrRedirect(string $key, string $redirectUrl): void
    {
        if ($key === '')
        {
            header('Location: ' . $redirectUrl . '?error=key');
            exit();
        }

        if (self::isSettingsReservedKey($key) || self::isSiteAdministratorOnlySettingsKey($key))
        {
            header('Location: ' . $redirectUrl . '?error=reserved');
            exit();
        }
    }

    /**
     * Determine whether a settings key is restricted to the site administrator role.
     *
     * @param string $key
     * @return bool
     */
    private static function isSiteAdministratorOnlySettingsKey(string $key): bool
    {
        $siteAdministratorOnlyKeys = [
            'profile.age_gate_enabled',
            'profile.self_serve_age_gate',
            'profile.explicit_years',
            'profile.birthday_badge_enabled',
        ];

        return in_array($key, $siteAdministratorOnlyKeys, true)
            && !GroupPermissionHelper::hasGroupSlug(['site-administrator']);
    }

    /**
     * Resolve the metadata used when saving a settings row.
     *
     * @param string $key
     * @param string $type
     * @param string $requestedCategory
     * @param array|false|null $existing
     * @return array<string, mixed>
     */
    private static function buildSettingsSaveMetadata(string $key, string $type, string $requestedCategory, $existing): array
    {
        $definitionCatalog = self::getSettingsDefinitionCatalog();
        $builtInDefinition = $definitionCatalog[$key] ?? null;

        if ($builtInDefinition !== null)
        {
            return [
                'category' => self::normalizeSettingsCategorySlug(TypeHelper::toString($builtInDefinition['category'] ?? '')),
                'title' => TypeHelper::toString($builtInDefinition['label'] ?? self::humanizeSettingsToken($key)),
                'description' => TypeHelper::toString($builtInDefinition['description'] ?? ''),
                'input_type' => TypeHelper::toString($builtInDefinition['input'] ?? 'text'),
                'sort_order' => TypeHelper::toInt($builtInDefinition['sort_order'] ?? 0) ?? 0,
                'is_system' => !empty($builtInDefinition['is_system']) ? 1 : 0,
                'is_built_in' => 1,
            ];
        }

        $resolvedCategory = $requestedCategory !== '' ? $requestedCategory : self::inferSettingsCategoryFromKey($key);
        $generated = class_exists('SettingsRegistry') ? SettingsRegistry::buildGeneratedDefinition($key, $type, is_array($existing) ? $existing : null) : [];

        return [
            'category' => $resolvedCategory !== '' ? $resolvedCategory : 'custom',
            'title' => trim(TypeHelper::toString($existing['title'] ?? ($generated['title'] ?? self::humanizeSettingsToken($key)))),
            'description' => trim(TypeHelper::toString($existing['description'] ?? ($generated['description'] ?? ''))),
            'input_type' => TypeHelper::toString($existing['input_type'] ?? ($generated['input'] ?? (class_exists('SettingsRegistry') ? SettingsRegistry::defaultInputForType($type) : 'text'))),
            'sort_order' => TypeHelper::toInt($existing['sort_order'] ?? ($generated['sort_order'] ?? 9999)) ?? 9999,
            'is_system' => !empty($existing['is_system']) ? 1 : 0,
            'is_built_in' => 0,
        ];
    }

    /**
     * Infer the most likely settings category slug for a registry key.
     *
     * @param string $key
     * @return string
     */
    private static function inferSettingsCategoryFromKey(string $key): string
    {
        $category = self::inferSettingsCategoryFromKey($key);

        return $category !== '' ? $category : 'custom';
    }

    /**
     * Build a settings category URL.
     *
     * @param string $category
     * @return string
     */
    private static function buildSettingsCategoryUrl(string $category = ''): string
    {
        $category = self::normalizeSettingsCategorySlug($category);
        if ($category === '')
        {
            return '/panel/settings';
        }

        return '/panel/settings/categories/' . rawurlencode($category);
    }

    /**
     * Build the settings category manager URL.
     *
     * @return string
     */
    private static function buildSettingsCategoryManagerUrl(): string
    {
        return '/panel/settings/categories';
    }


    /**
     * Ensure a settings category row exists and return its identifier.
     *
     * @param string $category
     * @param int $isSystem
     * @return int
     */
    private static function ensureSettingsCategoryExists(string $category, int $isSystem = 0): int
    {
        $category = self::normalizeSettingsCategorySlug($category);
        if ($category === '')
        {
            return 0;
        }

        $existingId = SettingsModel::getCategoryIdBySlug($category);
        if ($existingId > 0)
        {
            return $existingId;
        }

        $baseCatalog = self::getSettingsCategoryCatalog();
        $baseMeta = $baseCatalog[$category] ?? [];
        $title = self::normalizeSettingsCategoryDisplayTitle(TypeHelper::toString($baseMeta['title'] ?? self::humanizeSettingsToken($category)));
        $description = TypeHelper::toString($baseMeta['description'] ?? 'Additional registry entries stored for this settings group.');
        $icon = TypeHelper::toString($baseMeta['icon'] ?? 'fa-sliders');
        $sortOrder = TypeHelper::toInt($baseMeta['sort_order'] ?? 0) ?? 0;
        if ($sortOrder <= 0)
        {
            $sortOrder = SettingsModel::getNextCategorySortOrder();
        }

        SettingsModel::createCategory([
            'slug' => $category,
            'title' => $title,
            'description' => $description,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_system' => $isSystem || !empty($baseMeta['is_system']) ? 1 : 0,
        ]);

        return SettingsModel::getCategoryIdBySlug($category);
    }

    /**
     * Normalize a category display title for ACP presentation.
     *
     * @param string $title
     * @return string
     */
    private static function normalizeSettingsCategoryDisplayTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '')
        {
            return '';
        }

        return preg_replace('/\s+settings$/i', '', $title) ?: $title;
    }

    /**
     * Determine whether a registry key is reserved for settings UI metadata.
     *
     * @param string $key
     * @return bool
     */
    private static function isSettingsReservedKey(string $key): bool
    {
        $key = self::normalizeSettingsKey($key);
        if ($key === '' || $key === 'timezone')
        {
            return $key === 'timezone';
        }

        $topLevel = explode('.', $key, 2)[0] ?? '';
        $reserved = [
            'db',
            'session',
            'security',
            'control_server',
            'request_guard',
            'settings_manager',
        ];

        return in_array($topLevel, $reserved, true) || strpos($key, '_meta.') === 0;
    }

    /**
     * Build a metadata key for a settings category.
     *
     * @param string $category
     * @param string $field
     * @return string
     */
    private static function buildSettingsCategoryMetaKey(string $category, string $field): string
    {
        $category = self::normalizeSettingsCategorySlug($category);
        $field = strtolower(trim($field));

        return '_meta.category.' . $category . '.' . $field;
    }

    /**
     * Merge stored category metadata over the built-in fallback catalog.
     *
     * @param array $baseCatalog
     * @param array $metaMap
     * @return array
     */
    private static function mergeSettingsCategoryCatalog(array $baseCatalog, array $metaMap): array
    {
        $catalog = [];
        foreach ($baseCatalog as $categorySlug => $categoryMeta)
        {
            $catalog[$categorySlug] = [
                'title' => TypeHelper::toString($categoryMeta['title'] ?? self::humanizeSettingsToken($categorySlug)),
                'description' => TypeHelper::toString($categoryMeta['description'] ?? ''),
                'icon' => TypeHelper::toString($categoryMeta['icon'] ?? 'fa-sliders'),
                'is_system' => 1,
                'is_custom' => 0,
                'has_meta' => 0,
            ];
        }

        foreach ($metaMap as $categorySlug => $categoryMeta)
        {
            if (!isset($catalog[$categorySlug]))
            {
                $catalog[$categorySlug] = [
                    'title' => self::humanizeSettingsToken($categorySlug),
                    'description' => 'Additional registry entries stored for this settings group.',
                    'icon' => 'fa-sliders',
                    'is_system' => 0,
                    'is_custom' => 1,
                    'has_meta' => 1,
                ];
            }
            else
            {
                $catalog[$categorySlug]['has_meta'] = 1;
            }

            if (!empty($categoryMeta['title']))
            {
                $catalog[$categorySlug]['title'] = TypeHelper::toString($categoryMeta['title']);
            }

            if (!empty($categoryMeta['description']))
            {
                $catalog[$categorySlug]['description'] = TypeHelper::toString($categoryMeta['description']);
            }

            if (!empty($categoryMeta['icon']))
            {
                $catalog[$categorySlug]['icon'] = TypeHelper::toString($categoryMeta['icon']);
            }
        }

        return $catalog;
    }

    /**
     * Return the supported icon choices for category cards.
     *
     * @return array
     */
    private static function getSettingsCategoryIconOptions(): array
    {
        return [
            ['fa-sliders', 'Sliders'],
            ['fa-bug', 'Bug'],
            ['fa-images', 'Images'],
            ['fa-id-card', 'Profile'],
            ['fa-window-maximize', 'Window'],
            ['fa-code', 'Code'],
            ['fa-upload', 'Upload'],
            ['fa-shield-halved', 'Shield'],
            ['fa-user-gear', 'User Gear'],
            ['fa-gears', 'Gears'],
            ['fa-database', 'Database'],
            ['fa-wand-magic-sparkles', 'Magic'],
        ];
    }

    /**
     * Upsert a registry value directly into app_settings_data.
     *
     * @param string $key
     * @param string $value
     * @param string $type
     * @return void
     */
    private static function upsertSettingsRegistryValue(string $key, string $value, string $type = 'string'): void
    {
        $key = self::normalizeSettingsKey($key);
        if ($key === '' || self::isSettingsReservedKey($key))
        {
            return;
        }

        $type = self::normalizeSettingsType($type);
        $normalized = self::normalizeSettingsValueForStorage($key, $value, $type);
        if (!$normalized['valid'])
        {
            return;
        }

        $category = self::inferSettingsCategoryFromKey($key);
        $categoryId = self::ensureSettingsCategoryExists($category, 0);

        SettingsModel::upsertSetting([
            'category_id' => $categoryId,
            'key_name' => $key,
            'title' => self::humanizeSettingsToken($key),
            'description' => 'Custom database-backed setting.',
            'value_data' => $normalized['value'],
            'type_name' => $type,
            'input_type' => class_exists('SettingsRegistry') ? SettingsRegistry::defaultInputForType($type) : 'text',
            'sort_order' => 9999,
            'is_system' => 0,
        ]);
    }

    /**
     * Delete all metadata rows for a settings category.
     *
     * @param string $category
     * @return void
     */
    private static function deleteSettingsCategoryMetaRows(string $category): void
    {
        $category = self::normalizeSettingsCategorySlug($category);
        if ($category === '')
        {
            return;
        }

        SettingsModel::deleteCategoryBySlug($category, true);
    }

    /**
     * Count stored settings entries for a category, excluding metadata rows.
     *
     * @param string $category
     * @return int
     */
    private static function countStoredSettingsEntriesForCategory(string $category): int
    {
        $category = self::normalizeSettingsCategorySlug($category);
        if ($category === '')
        {
            return 0;
        }

        return SettingsModel::countEntriesForCategory($category);
    }

    /**
     * Normalize a registry key to a safe dot-notation format.
     *
     * @param string $key
     * @return string
     */
    private static function normalizeSettingsKey(string $key): string
    {
        $key = trim($key);
        if ($key === '')
        {
            return '';
        }

        $key = str_replace(['/', '\\', ':'], '.', $key);
        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '', $key);
        $key = preg_replace('/\.+/', '.', $key);
        $key = trim($key, '.');

        if (strlen($key) > 128)
        {
            $key = substr($key, 0, 128);
        }

        return $key;
    }

    /**
     * Normalize a settings type to a supported registry value.
     *
     * @param string $type
     * @return string
     */
    private static function normalizeSettingsType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['string', 'int', 'bool', 'json'], true) ? $type : 'string';
    }

    /**
     * Normalize a settings category slug.
     *
     * @param string $category
     * @return string
     */
    private static function normalizeSettingsCategorySlug(string $category): string
    {
        $category = strtolower(trim($category));
        $category = preg_replace('/[^a-z0-9_.-]/', '', $category);
        $category = trim($category, '.-_');

        if (strlen($category) > 64)
        {
            $category = substr($category, 0, 64);
        }

        return $category;
    }

    /**
     * Convert an internal token into a human-friendly title.
     *
     * @param string $value
     * @return string
     */
    private static function humanizeSettingsToken(string $value): string
    {
        $value = str_replace(['.', '_', '-'], ' ', trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = strtolower($value);

        return ucwords($value);
    }

    /**
     * Fetch a nested config value using a dot-notation key.
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function getNestedConfigValue(array $config, string $key, $default = '')
    {
        $parts = explode('.', $key);
        $current = $config;
        foreach ($parts as $part)
        {
            if (!is_array($current) || !array_key_exists($part, $current))
            {
                return $default;
            }

            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Format a stored or fallback value for display in the settings UI.
     *
     * @param mixed $value
     * @param string $type
     * @param bool $preferRawJsonString
     * @return string
     */
    private static function formatSettingsValueForDisplay($value, string $type, bool $preferRawJsonString = false): string
    {
        switch ($type)
        {
            case 'bool':
                return self::normalizeBoolStorageValue($value);

            case 'int':
                if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value))))
                {
                    return (string)((int)$value);
                }
                return '0';

            case 'json':
                if ($preferRawJsonString && is_string($value))
                {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE)
                    {
                        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        return is_string($encoded) ? $encoded : $value;
                    }

                    return $value;
                }

                if (is_string($value))
                {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE)
                    {
                        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        return is_string($encoded) ? $encoded : $value;
                    }
                }
                else if (is_array($value))
                {
                    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return is_string($encoded) ? $encoded : '[]';
                }

                return '[]';

            case 'string':
            default:
                if (is_array($value))
                {
                    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return is_string($encoded) ? $encoded : '';
                }

                return trim((string)$value);
        }
    }

    /**
     * Format a value for single-line form controls.
     *
     * @param string $value
     * @param string $type
     * @return string
     */
    private static function formatSettingsValueForAttribute(string $value, string $type): string
    {
        if ($type === 'json')
        {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE)
            {
                $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return is_string($encoded) ? $encoded : '';
            }
        }

        return $value;
    }

    /**
     * Normalize a boolean-like value into the registry storage format.
     *
     * @param mixed $value
     * @return string
     */
    private static function normalizeBoolStorageValue($value): string
    {
        if (is_bool($value))
        {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value))
        {
            return ((int)$value) === 1 ? '1' : '0';
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true) ? '1' : '0';
    }

    /**
     * Validate and normalize a setting value before saving.
     *
     * @param string $key
     * @param string $value
     * @param string $type
     * @return array
     */
    private static function normalizeSettingsValueForStorage(string $key, string $value, string $type): array
    {
        if (class_exists('SettingsRegistry'))
        {
            return SettingsRegistry::normalizeValueForStorage($key, $value, $type);
        }

        return ['valid' => true, 'value' => trim($value), 'error' => ''];
    }

    /**
     * Render the security log list page.
     *
     * Supports filtering and pagination. Displays limited columns suitable for
     * scanning (date/time, category, user), while detailed message/user-agent
     * content can be accessed via securityLogView().
     *
     * @return void
     */

    /**
     * Read and normalize the active security log filter values from the request.
     *
     * @return array<string, int|string>
     */
    private static function getSecurityLogFilters(): array
    {
        return [
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
            'device_fingerprint' => Security::sanitizeString($_GET['device_fingerprint'] ?? ''),
            'browser_fingerprint' => Security::sanitizeString($_GET['browser_fingerprint'] ?? ''),
            'session_id' => Security::sanitizeString($_GET['session_id'] ?? ''),
            'user_id' => TypeHelper::toInt($_GET['user_id'] ?? 0) ?? 0,
            'category' => Security::sanitizeString($_GET['category'] ?? ''),
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
        ];
    }

    /**
     * Build the SQL where clause and bound parameters for security log filters.
     *
     * Invalid IP filters intentionally force an empty result set rather than
     * broadening the query unexpectedly.
     *
     * @param array<string, int|string> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildSecurityLogFilterQuery(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['ip'] ?? '') !== '')
        {
            $packedIp = self::packIpFilter(TypeHelper::toString($filters['ip'] ?? ''));
            if ($packedIp !== null)
            {
                $where[] = 'l.ip = :ip';
                $params['ip'] = $packedIp;
            }
            else
            {
                $where[] = '1 = 0';
            }
        }

        if (($filters['fingerprint'] ?? '') !== '')
        {
            $where[] = 'l.fingerprint LIKE :fp';
            $params['fp'] = TypeHelper::toString($filters['fingerprint'] ?? '') . '%';
        }

        if (($filters['device_fingerprint'] ?? '') !== '')
        {
            $where[] = 'l.device_fingerprint LIKE :dfp';
            $params['dfp'] = TypeHelper::toString($filters['device_fingerprint'] ?? '') . '%';
        }

        if (($filters['browser_fingerprint'] ?? '') !== '')
        {
            $where[] = 'l.browser_fingerprint LIKE :bfp';
            $params['bfp'] = TypeHelper::toString($filters['browser_fingerprint'] ?? '') . '%';
        }

        if (($filters['session_id'] ?? '') !== '')
        {
            $where[] = 'l.session_id LIKE :sid';
            $params['sid'] = TypeHelper::toString($filters['session_id'] ?? '') . '%';
        }

        if ((TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0) > 0)
        {
            $where[] = 'l.user_id = :uid';
            $params['uid'] = TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0;
        }

        if (($filters['category'] ?? '') !== '')
        {
            $where[] = 'l.category = :cat';
            $params['cat'] = TypeHelper::toString($filters['category'] ?? '');
        }

        if (($filters['q'] ?? '') !== '')
        {
            $where[] = '(l.message LIKE :q OR l.ua LIKE :q OR l.session_id LIKE :q)';
            $params['q'] = '%' . TypeHelper::toString($filters['q'] ?? '') . '%';
        }

        if (empty($where))
        {
            return ['', $params];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Convert one page of security log rows into the compact template format.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function buildSecurityLogRows(array $rows): array
    {
        $logs = [];
        foreach ($rows as $row)
        {
            $id = TypeHelper::toInt($row['id'] ?? 0) ?? 0;
            $createdAt = TypeHelper::toString(DateHelper::date_only_format($row['created_at']) ?? '', allowEmpty: true) ?? '';
            $deviceFingerprint = TypeHelper::toString($row['device_fingerprint'] ?? '', allowEmpty: true) ?? '';
            $browserFingerprint = TypeHelper::toString($row['browser_fingerprint'] ?? '', allowEmpty: true) ?? '';
            $requestFingerprint = TypeHelper::toString($row['fingerprint'] ?? '', allowEmpty: true) ?? '';
            $sessionId = TypeHelper::toString($row['session_id'] ?? '', allowEmpty: true) ?? '';
            $signalSummary = [];

            if ($deviceFingerprint !== '')
            {
                $signalSummary[] = 'Device: ' . self::shortenHash($deviceFingerprint);
            }

            if ($browserFingerprint !== '')
            {
                $signalSummary[] = 'Browser: ' . self::shortenHash($browserFingerprint);
            }
            else if ($requestFingerprint !== '')
            {
                $signalSummary[] = 'Request: ' . self::shortenHash($requestFingerprint);
            }

            if ($sessionId !== '')
            {
                $signalSummary[] = 'Session: ' . self::shortenHash($sessionId, 18);
            }

            $logs[] = [
                $createdAt,
                TypeHelper::toString($row['category'] ?? ''),
                TypeHelper::toString(ucfirst(self::getUsernameById(TypeHelper::toInt($row['user_id'] ?? 0) ?? 0)) ?? ''),
                self::formatStoredIp($row['ip'] ?? null),
                implode(' | ', $signalSummary),
                '/panel/security/logs/view?id=' . $id,
            ];
        }

        return $logs;
    }

    /**
     * Convert the available security log categories into filter option rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, string>>
     */
    private static function buildSecurityLogCategoryRows(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row)
        {
            $categories[] = [
                TypeHelper::toString($row['category'] ?? ''),
            ];
        }

        return $categories;
    }

    /**
     * Build pagination links for list views that preserve active query params.
     *
     * @param string $baseUrl
     * @param int $page
     * @param int $totalPages
     * @param array<string, int|string> $queryParams
     * @return array{prev: ?string, next: ?string, pages: array<int, array<int, bool|int|string|null>>}
     */
    private static function buildFilteredPaginationState(string $baseUrl, int $page, int $totalPages, array $queryParams = []): array
    {
        $buildUrl = static function (int $targetPage) use ($baseUrl, $queryParams): string
        {
            $params = $queryParams;
            if ($targetPage > 1)
            {
                $params['page'] = $targetPage;
            }

            $qs = http_build_query($params);
            return $qs !== '' ? $baseUrl . '?' . $qs : $baseUrl;
        };

        $paginationPrev = $page > 1 ? $buildUrl($page - 1) : null;
        $paginationNext = $page < $totalPages ? $buildUrl($page + 1) : null;

        $range = 2;
        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);
        $paginationPages = [];

        if ($start > 1)
        {
            $paginationPages[] = [$buildUrl(1), 1, false];
            if ($start > 2)
            {
                $paginationPages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $paginationPages[] = [
                $buildUrl($i),
                $i,
                $i === $page,
            ];
        }

        if ($end < $totalPages)
        {
            if ($end < $totalPages - 1)
            {
                $paginationPages[] = [null, '...', false];
            }

            $paginationPages[] = [$buildUrl($totalPages), $totalPages, false];
        }

        return [
            'prev' => $paginationPrev,
            'next' => $paginationNext,
            'pages' => $paginationPages,
        ];
    }

    /**
     * Remove empty values from the security log filter state for URL building.
     *
     * @param array<string, int|string> $filters
     * @return array<string, int|string>
     */
    private static function buildSecurityLogQueryParams(array $filters): array
    {
        $params = [];

        foreach (['ip', 'fingerprint', 'device_fingerprint', 'browser_fingerprint', 'session_id', 'category', 'q'] as $key)
        {
            if (($filters[$key] ?? '') !== '')
            {
                $params[$key] = TypeHelper::toString($filters[$key] ?? '');
            }
        }

        if ((TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0) > 0)
        {
            $params['user_id'] = TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0;
        }

        return $params;
    }

    /**
     * Resolve the current block list page notice state from the request.
     *
     * @return array{notice: string, type: string}
     */
    private static function resolveBlockListNotice(): array
    {
        $blockError = Security::sanitizeString($_GET['error'] ?? '');

        if (isset($_GET['created']))
        {
            return ['notice' => 'Block entry saved successfully.', 'type' => 'success'];
        }

        if (isset($_GET['removed']))
        {
            return ['notice' => 'Matching block entry records were removed.', 'type' => 'success'];
        }

        if ($blockError === 'csrf')
        {
            return ['notice' => 'The request could not be verified. Please try again.', 'type' => 'error'];
        }

        if ($blockError === 'scope')
        {
            return ['notice' => 'Choose a supported scope and provide a matching value before creating a block entry.', 'type' => 'error'];
        }

        if ($blockError === 'match')
        {
            return ['notice' => 'Provide at least one exact match value before removing entries.', 'type' => 'error'];
        }

        return ['notice' => '', 'type' => ''];
    }

    /**
     * Read and normalize the active block list filter values.
     *
     * @return array<string, int|string>
     */
    private static function getBlockListFilters(): array
    {
        return [
            'scope' => Security::sanitizeString($_GET['scope'] ?? ''),
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
            'device_fingerprint' => Security::sanitizeString($_GET['device_fingerprint'] ?? ''),
            'browser_fingerprint' => Security::sanitizeString($_GET['browser_fingerprint'] ?? ''),
            'user_id' => TypeHelper::toInt($_GET['user_id'] ?? 0) ?? 0,
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];
    }

    /**
     * Build the SQL where clause and parameters for block list filtering.
     *
     * @param array<string, int|string> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildBlockListFilterQuery(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['scope'] ?? '') !== '')
        {
            $where[] = 'scope = :scope';
            $params['scope'] = TypeHelper::toString($filters['scope'] ?? '');
        }

        if (($filters['ip'] ?? '') !== '')
        {
            $packedIp = self::packIpFilter(TypeHelper::toString($filters['ip'] ?? ''));
            if ($packedIp !== null)
            {
                $where[] = 'ip = :ip';
                $params['ip'] = $packedIp;
            }
            else
            {
                $where[] = '1 = 0';
            }
        }

        if (($filters['fingerprint'] ?? '') !== '')
        {
            $where[] = 'fingerprint LIKE :fp';
            $params['fp'] = TypeHelper::toString($filters['fingerprint'] ?? '') . '%';
        }

        if (($filters['device_fingerprint'] ?? '') !== '')
        {
            $where[] = 'device_fingerprint LIKE :dfp';
            $params['dfp'] = TypeHelper::toString($filters['device_fingerprint'] ?? '') . '%';
        }

        if (($filters['browser_fingerprint'] ?? '') !== '')
        {
            $where[] = 'browser_fingerprint LIKE :bfp';
            $params['bfp'] = TypeHelper::toString($filters['browser_fingerprint'] ?? '') . '%';
        }

        if ((TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0) > 0)
        {
            $where[] = 'user_id = :uid';
            $params['uid'] = TypeHelper::toInt($filters['user_id'] ?? 0) ?? 0;
        }

        if (($filters['status'] ?? '') !== '')
        {
            $where[] = 'status = :st';
            $params['st'] = TypeHelper::toString($filters['status'] ?? '');
        }

        if (empty($where))
        {
            return ['', $params];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Convert block list rows into the compact table shape used by the panel.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function buildBlockListRows(array $rows): array
    {
        $blocks = [];
        foreach ($rows as $row)
        {
            $scope = TypeHelper::toString($row['scope'] ?? '', allowEmpty: true) ?? '';
            $matchValue = '';

            if ($scope === 'user_id')
            {
                $matchValue = TypeHelper::toString($row['user_id'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'ip')
            {
                $matchValue = self::formatStoredIp($row['ip'] ?? null);
            }
            else if ($scope === 'ua')
            {
                $matchValue = TypeHelper::toString($row['ua'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'device_fingerprint')
            {
                $matchValue = TypeHelper::toString($row['device_fingerprint'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'browser_fingerprint')
            {
                $matchValue = TypeHelper::toString($row['browser_fingerprint'] ?? '', allowEmpty: true) ?? '';
            }
            else
            {
                $matchValue = TypeHelper::toString($row['fingerprint'] ?? '', allowEmpty: true) ?? '';
            }

            $blocks[] = [
                TypeHelper::toInt($row['id'] ?? ''),
                $scope,
                TypeHelper::toString($row['status'] ?? ''),
                TypeHelper::toString($row['reason'] ?? ''),
                TypeHelper::toString($row['user_id'] ?? ''),
                TypeHelper::toString($matchValue),
                TypeHelper::toString(DateHelper::date_only_format($row['last_seen']) ?? '', allowEmpty: true) ?? '',
                TypeHelper::toString(DateHelper::date_only_format($row['expires_at']) ?? '', allowEmpty: true) ?? '',
            ];
        }

        return $blocks;
    }

    /**
     * Resolve the block scope and matching value from one request payload.
     *
     * Supports both the newer scope/match_value pair and older templates that
     * still post dedicated scope fields.
     *
     * @param array<string, mixed> $input
     * @return array{scope: string, match_value: string}
     */
    private static function resolveBlockScopePayload(array $input): array
    {
        $scope = Security::sanitizeString($input['scope'] ?? '');
        $matchValue = Security::sanitizeString($input['match_value'] ?? '');
        $userId = TypeHelper::toInt($input['user_id'] ?? 0) ?? 0;
        $ip = Security::sanitizeString($input['ip'] ?? '');
        $fingerprint = Security::sanitizeString($input['fingerprint'] ?? '');
        $deviceFingerprint = Security::sanitizeString($input['device_fingerprint'] ?? '');
        $browserFingerprint = Security::sanitizeString($input['browser_fingerprint'] ?? '');
        $ua = Security::sanitizeString($input['ua'] ?? '');

        if ($scope !== '' && $matchValue !== '')
        {
            return [
                'scope' => $scope,
                'match_value' => $matchValue,
            ];
        }

        if ($userId > 0)
        {
            return ['scope' => 'user_id', 'match_value' => (string)$userId];
        }

        if ($ip !== '')
        {
            return ['scope' => 'ip', 'match_value' => $ip];
        }

        if ($deviceFingerprint !== '')
        {
            return ['scope' => 'device_fingerprint', 'match_value' => $deviceFingerprint];
        }

        if ($browserFingerprint !== '')
        {
            return ['scope' => 'browser_fingerprint', 'match_value' => $browserFingerprint];
        }

        if ($fingerprint !== '')
        {
            return ['scope' => 'fingerprint', 'match_value' => $fingerprint];
        }

        if ($ua !== '')
        {
            return ['scope' => 'ua', 'match_value' => $ua];
        }

        return ['scope' => '', 'match_value' => ''];
    }

    /**
     * Normalize one posted block status to the supported enforcement states.
     *
     * @param string $status
     * @return string
     */
    private static function normalizeBlockStatus(string $status): string
    {
        return in_array($status, ['blocked', 'banned', 'jailed', 'rate_limited'], true)
            ? $status
            : 'blocked';
    }

    /**
     * Build the normalized storage payload for a block list upsert request.
     *
     * Returns null when the provided scope or value cannot be normalized into
     * a valid stored record.
     *
     * @param string $scope
     * @param string $matchValue
     * @param string $status
     * @param string $reason
     * @param string|null $expires
     * @return array<string, mixed>|null
     */
    private static function buildBlockUpsertPayload(string $scope, string $matchValue, string $status, string $reason, ?string $expires): ?array
    {
        $valueHash = '';
        $ipStore = null;
        $fpStore = null;
        $dfpStore = null;
        $bfpStore = null;
        $uaStore = null;
        $uidStore = null;

        if ($scope === 'user_id')
        {
            $uidStore = TypeHelper::toInt($matchValue) ?? 0;
            if ($uidStore < 1)
            {
                return null;
            }

            $valueHash = hash('sha256', 'user|' . $uidStore);
        }
        else if ($scope === 'ip')
        {
            $packed = self::packIpFilter($matchValue);
            if ($packed === null)
            {
                return null;
            }

            $ipStore = $packed;
            $ipNorm = @inet_ntop($packed);
            if (!is_string($ipNorm) || $ipNorm === '')
            {
                $ipNorm = $matchValue;
            }

            $valueHash = hash('sha256', 'ip|' . $ipNorm);
        }
        else if ($scope === 'fingerprint')
        {
            $fpStore = $matchValue;
            $valueHash = hash('sha256', 'fp|' . $matchValue);
        }
        else if ($scope === 'device_fingerprint')
        {
            $dfpStore = $matchValue;
            $valueHash = hash('sha256', 'dfp|' . $matchValue);
        }
        else if ($scope === 'browser_fingerprint')
        {
            $bfpStore = $matchValue;
            $valueHash = hash('sha256', 'bfp|' . $matchValue);
        }
        else if ($scope === 'ua')
        {
            $uaStore = mb_substr($matchValue, 0, 255);
            $valueHash = hash('sha256', mb_strtolower($uaStore));
        }
        else
        {
            return null;
        }

        return [
            'scope' => $scope,
            'vh' => $valueHash,
            'status' => $status,
            'reason' => $reason !== '' ? $reason : null,
            'uid' => $uidStore,
            'ip' => $ipStore,
            'ua' => $uaStore,
            'fp' => $fpStore,
            'dfp' => $dfpStore,
            'bfp' => $bfpStore,
            'exp' => $expires,
            'status_upd' => $status,
            'reason_upd' => $reason !== '' ? $reason : null,
            'exp_upd' => $expires,
        ];
    }

    /**
     * Build the exact-match deletion condition for one block entry request.
     *
     * @param string $scope
     * @param string $matchValue
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function buildBlockRemovalCondition(string $scope, string $matchValue): ?array
    {
        $params = ['scope' => $scope];

        if ($scope === 'user_id')
        {
            $uid = TypeHelper::toInt($matchValue) ?? 0;
            if ($uid < 1)
            {
                return null;
            }

            $params['uid'] = $uid;
            return ['scope = :scope AND user_id = :uid', $params];
        }

        if ($scope === 'ip')
        {
            $packedIp = self::packIpFilter($matchValue);
            if ($packedIp === null)
            {
                return null;
            }

            $params['ip'] = $packedIp;
            return ['scope = :scope AND ip = :ip', $params];
        }

        if ($scope === 'fingerprint')
        {
            $params['fp'] = $matchValue;
            return ['scope = :scope AND fingerprint = :fp', $params];
        }

        if ($scope === 'device_fingerprint')
        {
            $params['dfp'] = $matchValue;
            return ['scope = :scope AND device_fingerprint = :dfp', $params];
        }

        if ($scope === 'browser_fingerprint')
        {
            $params['bfp'] = $matchValue;
            return ['scope = :scope AND browser_fingerprint = :bfp', $params];
        }

        if ($scope === 'ua')
        {
            $params['vh'] = hash('sha256', mb_strtolower($matchValue));
            return ['scope = :scope AND value_hash = :vh', $params];
        }

        return null;
    }

    /**
     * Render the filtered security log overview page.
     *
     * @return void
     */
    public static function securityLogs(): void
    {
        self::requirePanelPermission('view_security');

        $template = self::initTemplate();
        $filters = self::getSecurityLogFilters();

        // Current page for pagination. Defaults to page 1.
        $page = TypeHelper::toInt($_GET['page'] ?? 1) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }

        // Pagination sizing. Keep in sync with template expectations.
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        [$sqlWhere, $params] = self::buildSecurityLogFilterQuery($filters);

        $total = 0;
        $logRows = [];
        $categoryRows = [];

        // Total matching rows for pagination.
        try
        {
            $total = SecurityLogModel::countMatching($sqlWhere, $params);
        }
        catch (Throwable $e)
        {
            $total = 0;
        }

        // Fetch the current page of results.
        try
        {
            $logRows = SecurityLogModel::fetchPage($sqlWhere, $params, $perPage, $offset);
        }
        catch (Throwable $e)
        {
            $logRows = [];
        }

        // Fetch category options for filter dropdown.
        try
        {
            $categoryRows = SecurityLogModel::listCategories();
        }
        catch (Throwable $e)
        {
            $categoryRows = [];
        }

        $logs = self::buildSecurityLogRows($logRows);
        $categories = self::buildSecurityLogCategoryRows($categoryRows);

        // Build pagination links while preserving active filters.
        $totalPages = (int)ceil($total / $perPage);
        if ($totalPages < 1)
        {
            $totalPages = 1;
        }

        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $pagination = self::buildFilteredPaginationState(
            '/panel/security/logs',
            $page,
            $totalPages,
            self::buildSecurityLogQueryParams($filters)
        );

        self::assignPanelPage(
            $template,
            'security_logs',
            'Security Logs',
            'Filter and review audit events across request, device, browser, and session signals before drilling into the full request details.',
            'security'
        );

        $template->assign('logs', $logs);
        $template->assign('filter_ip', TypeHelper::toString($filters['ip'] ?? ''));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint'] ?? ''));
        $template->assign('filter_device_fingerprint', TypeHelper::toString($filters['device_fingerprint'] ?? ''));
        $template->assign('filter_browser_fingerprint', TypeHelper::toString($filters['browser_fingerprint'] ?? ''));
        $template->assign('filter_session_id', TypeHelper::toString($filters['session_id'] ?? ''));
        $template->assign('filter_user_id', TypeHelper::toString($filters['user_id'] ?? 0));
        $template->assign('filter_category', TypeHelper::toString($filters['category'] ?? ''));
        $template->assign('filter_q', TypeHelper::toString($filters['q'] ?? ''));
        $template->assign('categories', $categories);

        $template->assign('pagination_prev', $pagination['prev']);
        $template->assign('pagination_next', $pagination['next']);
        $template->assign('pagination_pages', $pagination['pages']);

        $template->render('panel/control_panel_security_logs.html');
    }


    /**
     * Load one security log row for the detail page.
     *
     * @param int $id Security log id.
     * @return array<string, mixed>|null
     */
    private static function loadSecurityLogDetailRow(int $id): ?array
    {
        try
        {
            $log = SecurityLogModel::findById($id);
            return is_array($log) && !empty($log) ? $log : null;
        }
        catch (Throwable $e)
        {
            return null;
        }
    }

    /**
     * Assign the security log detail template state.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array<string, mixed> $log Security log row.
     * @return void
     */
    private static function assignSecurityLogViewPage(TemplateEngine $template, array $log): void
    {
        $deviceFingerprint = TypeHelper::toString($log['device_fingerprint'] ?? '', allowEmpty: true) ?? '';
        $browserFingerprint = TypeHelper::toString($log['browser_fingerprint'] ?? '', allowEmpty: true) ?? '';
        $userId = TypeHelper::toInt($log['user_id'] ?? 0) ?? 0;
        $linkedUsers = self::getLinkedSecurityUsers($deviceFingerprint, $browserFingerprint, $userId);

        self::assignPanelPage(
            $template,
            'security_logs',
            'Security Log Details',
            'Inspect the full event payload, device/browser linkage signals, and audit metadata for a single entry.',
            'security'
        );

        $template->assign('log_id', TypeHelper::toInt($log['id'] ?? ''));
        $template->assign('log_created_at', TypeHelper::toString(DateHelper::date_only_format($log['created_at']) ?? ''));
        $template->assign('log_category', TypeHelper::toString($log['category'] ?? ''));
        $template->assign('log_message', TypeHelper::toString($log['message'] ?? ''));
        $template->assign('log_session_id', TypeHelper::toString($log['session_id'] ?? ''));
        $template->assign('log_fingerprint', TypeHelper::toString($log['fingerprint'] ?? ''));
        $template->assign('log_device_fingerprint', $deviceFingerprint);
        $template->assign('log_browser_fingerprint', $browserFingerprint);
        $template->assign('log_ip', self::formatStoredIp($log['ip'] ?? null));
        $template->assign('log_ua', TypeHelper::toString($log['ua'] ?? ''));
        $template->assign('linked_users', $linkedUsers);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('log_user_id', $userId > 0 ? (string)$userId : '');
        $template->assign('log_user', TypeHelper::toString(ucfirst(self::getUsernameById($userId)) ?? ''));
    }

    /**
     * Render the security log detail page for a single entry.
     *
     * Displays full details such as message, fingerprint, IP, and user agent.
     *
     * @return void
     */
    public static function securityLogView(): void
    {
        self::requirePanelPermission('view_security');

        $id = TypeHelper::toInt($_GET['id'] ?? 0) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/security/logs');
            exit();
        }

        $log = self::loadSecurityLogDetailRow($id);
        if ($log === null)
        {
            header('Location: /panel/security/logs');
            exit();
        }

        $template = self::initTemplate();
        self::assignSecurityLogViewPage($template, $log);
        $template->render('panel/control_panel_security_log_view.html');
    }

    /**
     * Render the block list management page.
     *
     * Provides filter options and lists recent block entries for review and editing.
     *
     * @return void
     */
    public static function blockList(): void
    {
        self::requirePanelPermission('manage_block_list');

        $template = self::initTemplate();
        $noticeState = self::resolveBlockListNotice();
        $filters = self::getBlockListFilters();
        [$sqlWhere, $params] = self::buildBlockListFilterQuery($filters);

        $blockRows = [];
        try
        {
            $blockRows = BlockListModel::listFiltered($sqlWhere, $params);
        }
        catch (Throwable $e)
        {
            $blockRows = [];
        }

        $blocks = self::buildBlockListRows($blockRows);

        self::assignPanelPage(
            $template,
            'block_list',
            'Block List',
            'Create, review, and remove enforcement records across user, IP, request, device, browser, and user-agent scopes.',
            'security'
        );

        $template->assign('blocks', $blocks);
        $template->assign('control_panel_notice', $noticeState['notice']);
        $template->assign('control_panel_notice_type', $noticeState['type']);
        $template->assign('filter_scope', TypeHelper::toString($filters['scope'] ?? ''));
        $template->assign('filter_ip', TypeHelper::toString($filters['ip'] ?? ''));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint'] ?? ''));
        $template->assign('filter_device_fingerprint', TypeHelper::toString($filters['device_fingerprint'] ?? ''));
        $template->assign('filter_browser_fingerprint', TypeHelper::toString($filters['browser_fingerprint'] ?? ''));
        $template->assign('filter_user_id', TypeHelper::toString($filters['user_id'] ?? 0));
        $template->assign('filter_status', TypeHelper::toString($filters['status'] ?? ''));
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('panel/control_panel_block_list.html');
    }


    /**
     * Create or update a block list entry (admin action).
     *
     * Matches by user_id, ip, or fingerprint and stores a normalized value hash.
     * Uses an upsert to update existing entries with new status/reason/expiry.
     *
     * @return void
     */
    public static function blockCreate(): void
    {
        self::requirePanelPermission('manage_block_list');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /panel/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /panel/security/blocks?error=csrf');
            exit();
        }

        $status = self::normalizeBlockStatus(Security::sanitizeString($_POST['status'] ?? 'blocked'));
        $reason = Security::sanitizeString($_POST['reason'] ?? '');
        $duration = TypeHelper::toInt($_POST['duration_minutes'] ?? 0) ?? 0;
        $scopePayload = self::resolveBlockScopePayload($_POST);
        $scope = $scopePayload['scope'];
        $matchValue = $scopePayload['match_value'];
        $allowedScopes = ['user_id', 'ip', 'fingerprint', 'device_fingerprint', 'browser_fingerprint', 'ua'];

        if (!in_array($scope, $allowedScopes, true) || $matchValue === '')
        {
            header('Location: /panel/security/blocks?error=scope');
            exit();
        }

        $expires = null;
        if ($duration > 0)
        {
            $expires = gmdate('Y-m-d H:i:s', time() + ($duration * 60));
        }

        $payload = self::buildBlockUpsertPayload($scope, $matchValue, $status, $reason, $expires);
        if ($payload === null)
        {
            header('Location: /panel/security/blocks?error=scope');
            exit();
        }

        BlockListModel::upsert($payload);

        header('Location: /panel/security/blocks?created=1');
        exit();
    }


    /**
     * Normalize one posted block-edit payload.
     *
     * @param array<string, mixed> $block Current block entry.
     * @return array{status: string, reason: ?string, expires_at: ?string}
     */
    private static function readBlockEditPayload(array $block): array
    {
        $status = Security::sanitizeString($_POST['status'] ?? $block['status']);
        if (!in_array($status, ['blocked', 'banned', 'jailed', 'rate_limited'], true))
        {
            $status = TypeHelper::toString($block['status']);
        }

        $reason = Security::sanitizeString($_POST['reason'] ?? '');
        $expiresAt = Security::sanitizeString($_POST['expires_at'] ?? '');

        return [
            'status' => $status,
            'reason' => $reason !== '' ? $reason : null,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        ];
    }

    /**
     * Process one block-edit submission.
     *
     * @param int $id Block entry id.
     * @param array<string, mixed> $block Current block entry.
     * @param array<int, string> $errors Collected validation errors.
     * @param string $success Success banner text.
     * @return array<string, mixed>
     */
    private static function processBlockEditSubmission(int $id, array $block, array &$errors, string &$success): array
    {
        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            $errors[] = 'Invalid request.';
            return $block;
        }

        $payload = self::readBlockEditPayload($block);

        try
        {
            BlockListModel::updateById($id, $payload['status'], $payload['reason'], $payload['expires_at']);
            $success = 'Block entry updated.';
        }
        catch (Throwable $e)
        {
            $errors[] = 'Failed to update entry.';
            return $block;
        }

        return BlockListModel::findById($id) ?: $block;
    }

    /**
     * Assign the block-edit template state.
     *
     * @param TemplateEngine $template Active template engine instance.
     * @param array<string, mixed> $block Current block entry.
     * @param array<int, string> $errors Validation errors.
     * @param string $success Success banner text.
     * @return void
     */
    private static function assignBlockEditPage(TemplateEngine $template, array $block, array $errors, string $success): void
    {
        self::assignPanelPage(
            $template,
            'block_list',
            'Edit Block Entry',
            'Adjust the status, reason, and expiration for an existing enforcement record.',
            'security'
        );
        $template->assign('block_id', TypeHelper::toInt($block['id'] ?? ''));
        $template->assign('block_scope', TypeHelper::toString($block['scope'] ?? ''));
        $template->assign('block_status', TypeHelper::toString($block['status'] ?? ''));
        $template->assign('block_reason', TypeHelper::toString($block['reason'] ?? ''));
        $template->assign('block_user_id', TypeHelper::toString($block['user_id'] ?? ''));
        $template->assign('block_ip', self::formatStoredIp($block['ip'] ?? null));
        $template->assign('block_ua', TypeHelper::toString($block['ua'] ?? '', allowEmpty: true) ?? '');
        $template->assign('block_fingerprint', TypeHelper::toString($block['fingerprint'] ?? '', allowEmpty: true) ?? '');
        $template->assign('block_device_fingerprint', TypeHelper::toString($block['device_fingerprint'] ?? '', allowEmpty: true) ?? '');
        $template->assign('block_browser_fingerprint', TypeHelper::toString($block['browser_fingerprint'] ?? '', allowEmpty: true) ?? '');
        $template->assign('block_expires_at', TypeHelper::toString($block['expires_at'] ?? ''));
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
    }

    /**
     * Edit an existing block list entry (admin action).
     *
     * Supports updating status/reason/expiry. Requires CSRF token validation.
     *
     * @param int $id Block list entry ID.
     * @return void
     */
    public static function blockEdit(int $id): void
    {
        self::requirePanelPermission('manage_block_list');

        $errors = [];
        $success = '';
        $block = BlockListModel::findById($id);

        if (!$block)
        {
            self::renderErrorPage(404, 'Not Found', 'Block entry not found.');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            $block = self::processBlockEditSubmission($id, $block, $errors, $success);
        }

        $template = self::initTemplate();
        self::assignBlockEditPage($template, $block, $errors, $success);
        $template->render('panel/control_panel_block_edit.html');
    }

    /**
     * Remove block list entries by matching values (admin action).
     *
     * Deletes matching entries based on user_id, ip, and/or fingerprint.
     * At least one match criterion must be provided.
     *
     * @return void
     */
    public static function blockRemoveMatch(): void
    {
        self::requirePanelPermission('manage_block_list');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /panel/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /panel/security/blocks?error=csrf');
            exit();
        }

        $scopePayload = self::resolveBlockScopePayload($_POST);
        $condition = self::buildBlockRemovalCondition($scopePayload['scope'], $scopePayload['match_value']);
        if ($condition === null)
        {
            header('Location: /panel/security/blocks?error=match');
            exit();
        }

        BlockListModel::deleteWhere($condition[0], $condition[1]);

        header('Location: /panel/security/blocks?removed=1');
        exit();
    }


    /**
     * Remove a single block list entry by ID (admin action).
     *
     * Requires CSRF token validation and POST request.
     *
     * @param int $id Block list entry ID.
     * @return void
     */
    public static function blockRemove(int $id): void
    {
        self::requirePanelPermission('manage_block_list');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /panel/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /panel/security/blocks?error=csrf');
            exit();
        }

        BlockListModel::deleteById($id);

        header('Location: /panel/security/blocks?removed=1');
        exit();
    }


    /**
     * Render the pending image moderation queue.
     *
     * @param mixed $page
     * @return void
     */
    public static function pending($page = null): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

        $page = self::normalizePanelPageNumber($page);
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        $totalCount = ImageModel::countPendingImages();
        $rows = ImageModel::fetchPendingImagesPage($offset, $perPage);
        $pagination = self::buildPanelPaginationState('/panel/image-pending/page/', $page, $perPage, $totalCount);
        $templateState = self::buildPendingQueueTemplateState($rows, $totalCount, $pagination);

        foreach ($templateState as $key => $value)
        {
            $template->assign($key, $value);
        }

        self::assignPanelPage(
            $template,
            'pending',
            'Pending Images',
            'Review queued uploads, inspect previews, and approve or reject submissions with a faster moderation flow.',
            'queue'
        );

        $template->render('panel/control_panel_pending.html');
    }

    /**
     * Normalize one control-panel page value into a usable positive number.
     *
     * @param mixed $page Incoming route/page value.
     * @return int Positive page number.
     */
    private static function normalizePanelPageNumber($page): int
    {
        $page = TypeHelper::toInt($page ?? null) ?? 1;
        if ($page < 1)
        {
            return 1;
        }

        return $page;
    }

    /**
     * Build one simple panel pagination payload for numbered page routes.
     *
     * @param string $basePath Route prefix ending with a slash.
     * @param int $page Current page number.
     * @param int $perPage Entries per page.
     * @param int $totalCount Total number of matching entries.
     * @return array{pagination_pages: array<int, array{0: string, 1: int, 2: bool}>, pagination_prev: string|null, pagination_next: string|null}
     */
    private static function buildPanelPaginationState(string $basePath, int $page, int $perPage, int $totalCount): array
    {
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $paginationPages = [];
        for ($i = 1; $i <= $totalPages; $i++)
        {
            $paginationPages[] = [
                $basePath . $i,
                $i,
                $i === $page,
            ];
        }

        return [
            'pagination_pages' => $paginationPages,
            'pagination_prev' => $page > 1 ? $basePath . ($page - 1) : null,
            'pagination_next' => $page < $totalPages ? $basePath . ($page + 1) : null,
        ];
    }

    /**
     * Build template assignments for the pending-image queue page.
     *
     * @param array $rows Pending queue rows from the image model.
     * @param int $totalCount Total number of pending images.
     * @param array $pagination Pagination state payload.
     * @return array<string, mixed> Template assignment payload.
     */
    private static function buildPendingQueueTemplateState(array $rows, int $totalCount, array $pagination): array
    {
        return $pagination + [
            'pending_rows' => self::buildPendingQueueRows($rows),
            'pending_count' => $totalCount,
            'pending_count_display' => NumericalHelper::formatCount($totalCount),
            'pending_notice' => self::resolvePendingQueueNotice(),
        ];
    }

    /**
     * Flatten pending-image rows for the existing moderation queue template.
     *
     * @param array $rows Pending image rows.
     * @return array<int, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    private static function buildPendingQueueRows(array $rows): array
    {
        $flattenedRows = [];
        foreach ($rows as $index => $row)
        {
            $contentRating = AgeGateHelper::normalizeContentRating(
                TypeHelper::toString($row['content_rating'] ?? '', allowEmpty: true) ?? '',
                TypeHelper::toInt($row['age_sensitive'] ?? 0) ?? 0
            );

            $flattenedRows[] = [
                $index + 1,
                TypeHelper::toString($row['image_hash'] ?? ''),
                ucfirst(TypeHelper::toString($row['username'] ?? 'Unknown') ?? 'Unknown'),
                DateHelper::format($row['created_at']),
                self::buildPendingQueueImageDetails($row),
                AgeGateHelper::getContentRatingLabel($contentRating),
                'pending-rating-pill-' . $contentRating,
            ];
        }

        return $flattenedRows;
    }

    /**
     * Build the compact metadata summary shown beside one pending queue entry.
     *
     * @param array $row Pending image row.
     * @return string Formatted details string for the template.
     */
    private static function buildPendingQueueImageDetails(array $row): string
    {
        $imageDetails = [];

        $width = TypeHelper::toInt($row['width'] ?? 0) ?? 0;
        $height = TypeHelper::toInt($row['height'] ?? 0) ?? 0;
        if ($width > 0 && $height > 0)
        {
            $imageDetails[] = $width . ' × ' . $height . ' px';
        }

        $sizeBytes = TypeHelper::toInt($row['size_bytes'] ?? 0) ?? 0;
        if ($sizeBytes > 0)
        {
            $imageDetails[] = StorageHelper::formatFileSize($sizeBytes);
        }

        $mimeType = TypeHelper::toString($row['mime_type'] ?? '', allowEmpty: true) ?? '';
        if ($mimeType !== '')
        {
            $imageDetails[] = $mimeType;
        }

        return !empty($imageDetails) ? implode(' • ', $imageDetails) : 'Details unavailable';
    }

    /**
     * Resolve the current queue-page notice message from the query string.
     *
     * @return string User-facing queue notice copy.
     */
    private static function resolvePendingQueueNotice(): string
    {
        return match (Security::sanitizeString($_GET['notice'] ?? ''))
        {
            'approved' => 'The image has been approved.',
            'saved' => 'Pending-image moderation changes were saved.',
            'rejected' => 'The image has been rejected.',
            default => '',
        };
    }

    /**
     * Render and process one pending-image moderation review page.
     *
     * @param string $hash The image hash identifier from the route.
     * @return void
     */
    public static function pendingImageReview(string $hash): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            self::handlePendingImageReviewSubmission($template, $hash);
            return;
        }

        $image = ImageModel::findPendingModerationImageByHash($hash);
        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        $templateState = self::buildPendingImageReviewTemplateState($image, $hash);
        foreach ($templateState as $key => $value)
        {
            $template->assign($key, $value);
        }

        self::assignPanelPage(
            $template,
            'pending',
            'Pending Image Review',
            'Open one queued upload in a focused moderation view so description edits, content rating, and final approval are handled from one page.',
            'queue'
        );

        $template->render('panel/control_panel_pending_review.html');
    }

    /**
     * Process one pending-image moderation submission.
     *
     * @param TemplateEngine $template Prepared template instance.
     * @param string $hash Pending image hash.
     * @return void
     */
    private static function handlePendingImageReviewSubmission(TemplateEngine $template, string $hash): void
    {
        self::requireProtectedPanelPost($template);

        $image = ImageModel::findPendingModerationImageByHash($hash);
        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        $description = TypeHelper::toString($_POST['description'] ?? null, allowEmpty: true) ?? '';
        $contentRating = AgeGateHelper::normalizeContentRating(TypeHelper::toString($_POST['content_rating'] ?? 'standard', allowEmpty: true) ?? 'standard');
        $rejectReason = TypeHelper::toString($_POST['reject_reason'] ?? null, allowEmpty: true) ?? '';
        $reviewAction = Security::sanitizeString($_POST['review_action'] ?? 'save');

        switch ($reviewAction)
        {
            case 'approve':
                ImageModel::updatePendingModerationDraft($hash, $description, $contentRating);
                self::completePendingImageApproval($hash, $contentRating);
                return;

            case 'reject':
                self::completePendingImageRejection($hash, $rejectReason);
                return;

            default:
                ImageModel::updatePendingModerationDraft($hash, $description, $contentRating);
                header('Location: /panel/image-pending/review/' . rawurlencode($hash) . '?notice=saved');
                exit;
        }
    }

    /**
     * Build template assignments for one pending-image review page.
     *
     * @param array $image Pending moderation image row.
     * @param string $hash Pending image hash from the route.
     * @return array<string, mixed> Template assignment payload.
     */
    private static function buildPendingImageReviewTemplateState(array $image, string $hash): array
    {
        $contentRating = AgeGateHelper::normalizeContentRating(
            TypeHelper::toString($image['content_rating'] ?? '', allowEmpty: true) ?? '',
            TypeHelper::toInt($image['age_sensitive'] ?? 0) ?? 0
        );

        return [
            'pending_review_notice' => Security::sanitizeString($_GET['notice'] ?? '') === 'saved' ? 'Pending-image moderation changes were saved.' : '',
            'pending_review_hash' => TypeHelper::toString($image['image_hash'] ?? ''),
            'pending_review_description' => TypeHelper::toString($image['description'] ?? '', allowEmpty: true) ?? '',
            'pending_review_reject_reason' => TypeHelper::toString($image['reject_reason'] ?? '', allowEmpty: true) ?? '',
            'pending_review_content_rating' => $contentRating,
            'pending_review_content_rating_label' => AgeGateHelper::getContentRatingLabel($contentRating),
            'pending_review_uploader' => ucfirst(self::getUsernameById(TypeHelper::toInt($image['user_id'] ?? 0) ?? 0)),
            'pending_review_created_at' => !empty($image['created_at']) ? DateHelper::format($image['created_at']) : 'Unknown',
            'pending_review_updated_at' => !empty($image['updated_at']) ? DateHelper::format($image['updated_at']) : 'Unknown',
            'pending_review_dimensions' => (TypeHelper::toInt($image['width'] ?? 0) ?? 0) . ' × ' . (TypeHelper::toInt($image['height'] ?? 0) ?? 0) . ' px',
            'pending_review_file_size' => StorageHelper::formatFileSize(TypeHelper::toInt($image['size_bytes'] ?? 0) ?? 0),
            'pending_review_mime_type' => TypeHelper::toString($image['mime_type'] ?? 'Unknown'),
            'pending_review_original_path' => TypeHelper::toString($image['original_path'] ?? '', allowEmpty: true) ?? '',
            'pending_review_md5' => TypeHelper::toString($image['md5'] ?? '', allowEmpty: true) ?? '',
            'pending_review_sha1' => TypeHelper::toString($image['sha1'] ?? '', allowEmpty: true) ?? '',
            'pending_review_sha256' => TypeHelper::toString($image['sha256'] ?? '', allowEmpty: true) ?? '',
            'pending_review_sha512' => TypeHelper::toString($image['sha512'] ?? '', allowEmpty: true) ?? '',
            'pending_review_preview_url' => '/panel/image-pending/' . rawurlencode($hash),
            'pending_review_back_url' => '/panel/image-pending',
        ];
    }

    /**
     * Require one valid control-panel POST request with CSRF protection.
     *
     * @param TemplateEngine $template Prepared template instance.
     * @return void
     */
    private static function requireProtectedPanelPost(TemplateEngine $template): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            self::renderErrorPage(405, 'Method Not Allowed', 'Invalid request.', $template);
            exit;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            exit;
        }
    }

    /**
     * Resolve one pending-image action target from the route hash.
     *
     * @param TemplateEngine $template Prepared template instance.
     * @param string $hash Pending image hash from the route.
     * @return string|null Normalized image hash when the image is still pending.
     */
    private static function resolvePendingImageActionHash(TemplateEngine $template, string $hash): ?string
    {
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return null;
        }

        $image = ImageModel::findModerationStatusByHash($hash);
        if (!$image)
        {
            self::renderImageNotFound($template);
            return null;
        }

        if (($image['status'] ?? '') !== 'pending')
        {
            self::redirectPendingQueue();
            return null;
        }

        return $hash;
    }

    /**
     * Redirect back to the pending moderation queue with an optional notice.
     *
     * @param string $notice Optional queue notice state.
     * @return void
     */
    private static function redirectPendingQueue(string $notice = ''): void
    {
        $location = '/panel/image-pending';
        if ($notice !== '')
        {
            $location .= '?notice=' . rawurlencode($notice);
        }

        header('Location: ' . $location);
        exit;
    }

    /**
     * Approve one pending image and emit the matching queue notification.
     *
     * @param string $hash Pending image hash.
     * @param string $contentRating Final content rating to store.
     * @return void
     */
    private static function completePendingImageApproval(string $hash, string $contentRating): void
    {
        $updatedRows = ImageModel::approvePendingImage($hash, self::getCurrentUserId(), $contentRating);
        if ($updatedRows > 0)
        {
            self::createPendingImageNotification($hash, 'approved', $contentRating);
        }

        self::redirectPendingQueue('approved');
    }

    /**
     * Reject one pending image and emit the matching queue notification.
     *
     * @param string $hash Pending image hash.
     * @param string $rejectReason Optional rejection reason.
     * @return void
     */
    private static function completePendingImageRejection(string $hash, string $rejectReason = ''): void
    {
        $updatedRows = ImageModel::rejectPendingImage($hash, self::getCurrentUserId(), $rejectReason);
        if ($updatedRows > 0)
        {
            self::createPendingImageNotification($hash, 'rejected', 'standard', $rejectReason);
        }

        self::redirectPendingQueue('rejected');
    }

    /**
     * Approve a pending image from the control panel.
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
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);
        self::requireProtectedPanelPost($template);

        $hash = self::resolvePendingImageActionHash($template, $hash);
        if ($hash === null)
        {
            return;
        }

        self::completePendingImageApproval($hash, 'standard');
    }

    /**
     * Approve a pending image from the control panel.
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
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);
        self::requireProtectedPanelPost($template);

        $hash = self::resolvePendingImageActionHash($template, $hash);
        if ($hash === null)
        {
            return;
        }

        self::completePendingImageApproval($hash, 'sensitive');
    }

    /**
     * Approve a pending image from the control panel and mark it explicit.
     *
     * This action is POST-only and requires a valid CSRF token.
     * Only images in "pending" status may be approved.
     *
     * @param string $hash The image hash identifier from the route.
     * @return void
     */
    public static function approveImageExplicit(string $hash): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);
        self::requireProtectedPanelPost($template);

        $hash = self::resolvePendingImageActionHash($template, $hash);
        if ($hash === null)
        {
            return;
        }

        self::completePendingImageApproval($hash, 'explicit');
    }

    /**
     * Reject a pending image from the control panel.
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
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);
        self::requireProtectedPanelPost($template);

        $hash = self::resolvePendingImageActionHash($template, $hash);
        if ($hash === null)
        {
            return;
        }

        self::completePendingImageRejection($hash);
    }

    /**
     * Render the image report queue for staff review.
     *
     * @param int|null $page Current pagination page.
     * @return void
     */

    /**
     * Convert one image report queue page into compact template rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function buildImageReportQueueRows(array $rows): array
    {
        $reportRows = [];
        foreach ($rows as $row)
        {
            $reportId = TypeHelper::toInt($row['id'] ?? 0) ?? 0;
            $reporterUserId = TypeHelper::toInt($row['reporter_user_id'] ?? 0) ?? 0;
            $assignedUserId = TypeHelper::toInt($row['assigned_to_user_id'] ?? 0) ?? 0;
            $reporterLabel = $reporterUserId > 0
                ? ucfirst(self::getUsernameById($reporterUserId))
                : 'Guest';
            $assignedLabel = $assignedUserId > 0
                ? ucfirst(TypeHelper::toString($row['assigned_username'] ?? self::getUsernameById($assignedUserId)))
                : 'Unassigned';
            $status = TypeHelper::toString($row['status'] ?? 'open');

            $reportRows[] = [
                $reportId,
                TypeHelper::toString($row['image_hash'] ?? ''),
                ImageReportHelper::categoryLabel(TypeHelper::toString($row['report_category'] ?? 'other')),
                TypeHelper::toString($row['report_subject'] ?? ''),
                $reporterLabel,
                $assignedLabel,
                ImageReportHelper::workflowStatusLabel($status, $assignedUserId),
                ImageReportHelper::workflowStatusClass($status, $assignedUserId),
                DateHelper::date_only_format(TypeHelper::toString($row['created_at'] ?? '')),
                '/panel/image-reports/view?id=' . $reportId,
            ];
        }

        return $reportRows;
    }

    /**
     * Convert report staff notes into template rows.
     *
     * @param array<int, array<string, mixed>> $staffNotes
     * @return array<int, array<int, mixed>>
     */
    private static function buildImageReportStaffNoteRows(array $staffNotes): array
    {
        $noteRows = [];
        foreach ($staffNotes as $note)
        {
            $noteRows[] = [
                TypeHelper::toInt($note['user_id'] ?? 0) ?? 0,
                ucfirst(TypeHelper::toString($note['username'] ?? 'Unknown Staff')),
                DateHelper::date_only_format(TypeHelper::toString($note['created_at'] ?? '')),
                TypeHelper::toString($note['comment_body'] ?? ''),
                DateHelper::date_only_format(TypeHelper::toString($note['updated_at'] ?? '')),
            ];
        }

        return $noteRows;
    }

    /**
     * Assemble the image report detail template state.
     *
     * @param array<string, mixed> $report
     * @param array<int, array<int, mixed>> $noteRows
     * @param int $currentStaffUserId
     * @return array<string, mixed>
     */
    private static function buildImageReportDetailTemplateState(array $report, array $noteRows, int $currentStaffUserId): array
    {
        $reporterUserId = TypeHelper::toInt($report['reporter_user_id'] ?? 0) ?? 0;
        $assignedUserId = TypeHelper::toInt($report['assigned_to_user_id'] ?? 0) ?? 0;
        $resolvedBy = TypeHelper::toInt($report['resolved_by'] ?? 0) ?? 0;
        $status = TypeHelper::toString($report['status'] ?? 'open');
        $normalizedStatus = ImageReportHelper::normalizeStatus($status);
        $assignedAt = TypeHelper::toString($report['assigned_at'] ?? null, allowEmpty: true);
        $resolvedAt = TypeHelper::toString($report['resolved_at'] ?? null, allowEmpty: true);

        return [
            'report_id' => TypeHelper::toInt($report['id'] ?? 0),
            'report_image_hash' => TypeHelper::toString($report['image_hash'] ?? ''),
            'report_image_status' => ucfirst(TypeHelper::toString($report['image_status'] ?? '')),
            'report_image_visibility' => AgeGateHelper::getContentRatingLabel(AgeGateHelper::normalizeContentRating(
                TypeHelper::toString($report['content_rating'] ?? '', allowEmpty: true) ?? '',
                TypeHelper::toInt($report['age_sensitive'] ?? 0) ?? 0
            )),
            'report_image_created_at' => DateHelper::date_only_format(TypeHelper::toString($report['image_created_at'] ?? '')),
            'report_category' => ImageReportHelper::categoryLabel(TypeHelper::toString($report['report_category'] ?? 'other')),
            'report_subject' => TypeHelper::toString($report['report_subject'] ?? ''),
            'report_message' => TypeHelper::toString($report['report_message'] ?? ''),
            'report_status_label' => ImageReportHelper::workflowStatusLabel($status, $assignedUserId),
            'report_status_class' => ImageReportHelper::workflowStatusClass($status, $assignedUserId),
            'report_created_at' => DateHelper::date_only_format(TypeHelper::toString($report['created_at'] ?? '')),
            'report_updated_at' => DateHelper::date_only_format(TypeHelper::toString($report['updated_at'] ?? '')),
            'report_assigned_at' => $assignedAt ? DateHelper::date_only_format($assignedAt) : '',
            'report_resolved_at' => $resolvedAt ? DateHelper::date_only_format($resolvedAt) : '',
            'report_reporter' => $reporterUserId > 0 ? ucfirst(self::getUsernameById($reporterUserId)) : 'Guest',
            'report_reporter_user_id' => $reporterUserId > 0 ? (string)$reporterUserId : '',
            'report_session_id' => TypeHelper::toString($report['session_id'] ?? ''),
            'report_ip' => self::formatStoredIp($report['ip'] ?? null),
            'report_ua' => TypeHelper::toString($report['ua'] ?? ''),
            'report_assigned_to' => $assignedUserId > 0 ? ucfirst(TypeHelper::toString($report['assigned_username'] ?? self::getUsernameById($assignedUserId))) : '',
            'report_assigned_user_id' => $assignedUserId > 0 ? (string)$assignedUserId : '',
            'report_resolved_by' => $resolvedBy > 0 ? ucfirst(self::getUsernameById($resolvedBy)) : '',
            'report_public_image_url' => '/gallery/' . TypeHelper::toString($report['image_hash'] ?? ''),
            'report_public_original_url' => '/gallery/original/' . TypeHelper::toString($report['image_hash'] ?? ''),
            'report_is_open' => $normalizedStatus === 'open' ? 1 : 0,
            'report_is_closed' => $normalizedStatus === 'closed' ? 1 : 0,
            'report_can_assign_self' => $normalizedStatus === 'open' && $currentStaffUserId > 0 && $assignedUserId !== $currentStaffUserId ? 1 : 0,
            'report_can_release_assignment' => $normalizedStatus === 'open' && $assignedUserId > 0 ? 1 : 0,
            'report_is_assigned_to_current_user' => $assignedUserId > 0 && $assignedUserId === $currentStaffUserId ? 1 : 0,
            'report_staff_notes' => $noteRows,
            'report_notes_count' => count($noteRows),
            'report_notice_state' => Security::sanitizeString($_GET['updated'] ?? ''),
        ];
    }

    /**
     * Normalize one posted image report workflow action.
     *
     * @param string $imageAction
     * @return string
     */
    private static function normalizeImageReportAction(string $imageAction): string
    {
        $allowedImageActions = [
            'none',
            'set_standard',
            'set_sensitive',
            'set_explicit',
            'set_pending',
            'set_approved',
            'set_rejected',
            'set_deleted',
        ];

        return in_array($imageAction, $allowedImageActions, true)
            ? $imageAction
            : 'none';
    }

    /**
     * Render the paginated image report queue.
     *
     * @param int|null $page Requested queue page number.
     * @return void
     */
    public static function imageReports($page = null): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'manage_image_reports'], $template);

        $page = TypeHelper::toInt($page ?? null) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }

        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $reportCounts = ImageReportModel::getQueueCounts();
        $totalCount = TypeHelper::toInt($reportCounts['total'] ?? 0) ?? 0;
        $openCount = TypeHelper::toInt($reportCounts['open'] ?? 0) ?? 0;
        $closedCount = TypeHelper::toInt($reportCounts['closed'] ?? 0) ?? 0;
        $reportRows = self::buildImageReportQueueRows(ImageReportModel::fetchQueuePage($perPage, $offset));

        $totalPages = (int)ceil($totalCount / $perPage);
        if ($totalPages < 1)
        {
            $totalPages = 1;
        }

        if ($page > $totalPages)
        {
            $page = $totalPages;
        }

        $paginationPages = [];
        for ($i = 1; $i <= $totalPages; $i++)
        {
            $paginationPages[] = [
                self::buildImageReportListUrl($i),
                $i,
                $i === $page,
            ];
        }

        self::assignPanelPage(
            $template,
            'reported',
            'Reported Images',
            'Review image reports, investigate the submitted details, and close or reopen moderation tickets as needed.',
            'queue'
        );

        $template->assign('report_rows', $reportRows);
        $template->assign('report_total_count', $totalCount);
        $template->assign('report_open_count', $openCount);
        $template->assign('report_closed_count', $closedCount);
        $template->assign('pagination_prev', $page > 1 ? self::buildImageReportListUrl($page - 1) : null);
        $template->assign('pagination_next', $page < $totalPages ? self::buildImageReportListUrl($page + 1) : null);
        $template->assign('pagination_pages', $paginationPages);
        $template->assign('report_notice_state', Security::sanitizeString($_GET['updated'] ?? ''));

        $template->render('panel/control_panel_reports.html');
    }


    /**
     * Render one image report detail page.
     *
     * @return void
     */
    public static function imageReportView(): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'manage_image_reports'], $template);

        $id = TypeHelper::toInt($_GET['id'] ?? 0) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $report = ImageReportModel::findDetailedById($id);
        if (!$report)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $noteRows = self::buildImageReportStaffNoteRows(ImageReportModel::fetchCommentsByReportId($id));
        $templateState = self::buildImageReportDetailTemplateState($report, $noteRows, self::getCurrentUserId());

        self::assignPanelPage(
            $template,
            'reported',
            'Image Report Details',
            'Inspect the full report body, reporter context, assignment state, and staff findings for this image ticket.',
            'queue'
        );

        foreach ($templateState as $key => $value)
        {
            $template->assign($key, $value);
        }

        $template->render('panel/control_panel_report_view.html');
    }


    /**
     * Assign one image report to the current staff member.
     *
     * @param int $id Report identifier.
     * @return void
     */
    public static function assignImageReport(int $id): void
    {
        $staffUserId = self::getCurrentUserId();
        self::syncImageReportAssignment($id, $staffUserId > 0 ? $staffUserId : null, 'assigned');
    }

    /**
     * Release any current staff assignment on one image report.
     *
     * @param int $id Report identifier.
     * @return void
     */
    public static function releaseImageReport(int $id): void
    {
        self::syncImageReportAssignment($id, null, 'unassigned');
    }

    /**
     * Close one open image report.
     *
     * @param int $id Report identifier.
     * @return void
     */
    public static function closeImageReport(int $id): void
    {
        self::updateImageReportStatus($id, 'closed');
    }

    /**
     * Re-open one closed image report.
     *
     * @param int $id Report identifier.
     * @return void
     */
    public static function reopenImageReport(int $id): void
    {
        self::updateImageReportStatus($id, 'open');
    }

    /**
     * Save one staff note and optional image/report workflow updates.
     *
     * @param int $id Report identifier.
     * @return void
     */
    public static function updateImageReport(int $id): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'manage_image_reports'], $template);
        self::requireProtectedPanelPost($template);

        $id = TypeHelper::toInt($id) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $report = ImageReportModel::findWorkflowRowById($id);
        if (!$report)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $staffUserId = self::getCurrentUserId();
        $staffComment = Security::sanitizeString($_POST['staff_comment'] ?? '');
        $imageAction = self::normalizeImageReportAction(strtolower(Security::sanitizeString($_POST['image_action'] ?? 'none')));
        $takeAssignment = isset($_POST['take_assignment']) && (TypeHelper::toInt($_POST['take_assignment'] ?? 0) ?? 0) === 1;
        $closeReport = isset($_POST['close_report']) && (TypeHelper::toInt($_POST['close_report'] ?? 0) ?? 0) === 1;
        $didUpdate = false;

        if ($takeAssignment && $staffUserId > 0)
        {
            ImageReportModel::takeAssignmentIfOpen($id, $staffUserId);
            $didUpdate = true;
        }

        if ($staffComment !== '')
        {
            ImageReportModel::addComment($id, $staffUserId > 0 ? $staffUserId : null, $staffComment);
            $didUpdate = true;
        }

        if ($imageAction !== 'none')
        {
            self::applyImageReportImageAction(TypeHelper::toInt($report['image_id'] ?? 0) ?? 0, $imageAction);
            $didUpdate = true;
        }

        if ($closeReport)
        {
            ImageReportModel::close($id, $staffUserId > 0 ? $staffUserId : null);

            header('Location: /panel/image-reports/view?id=' . $id . '&updated=closed');
            exit();
        }

        if ($didUpdate)
        {
            ImageReportModel::touch($id);

            header('Location: /panel/image-reports/view?id=' . $id . '&updated=saved');
            exit();
        }

        header('Location: /panel/image-reports/view?id=' . $id);
        exit();
    }


    /**
     * Apply one assignment change for an image report.
     *
     * @param int $id Report identifier.
     * @param int|null $assignedUserId Assigned staff user id or null to clear.
     * @param string $noticeState Redirect state flag.
     * @return void
     */
    private static function syncImageReportAssignment(int $id, ?int $assignedUserId, string $noticeState): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'manage_image_reports'], $template);
        self::requireProtectedPanelPost($template);

        $id = TypeHelper::toInt($id) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        if ($assignedUserId !== null && $assignedUserId > 0)
        {
            ImageReportModel::assignOpenReport($id, $assignedUserId);
        }
        else
        {
            ImageReportModel::releaseOpenAssignment($id);
        }

        header('Location: /panel/image-reports/view?id=' . $id . '&updated=' . rawurlencode($noticeState));
        exit();
    }

    /**
     * Apply one report-status transition for staff review workflows.
     *
     * @param int $id Report identifier.
     * @param string $status Target status.
     * @return void
     */
    private static function updateImageReportStatus(int $id, string $status): void
    {
        $template = self::initTemplate();
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'manage_image_reports'], $template);
        self::requireProtectedPanelPost($template);

        $id = TypeHelper::toInt($id) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $status = ImageReportHelper::normalizeStatus($status);
        $staffUserId = self::getCurrentUserId();

        if ($status === 'closed')
        {
            ImageReportModel::close($id, $staffUserId > 0 ? $staffUserId : null);

            header('Location: /panel/image-reports/view?id=' . $id . '&updated=closed');
            exit();
        }

        ImageReportModel::reopen($id);

        header('Location: /panel/image-reports/view?id=' . $id . '&updated=reopened');
        exit();
    }

    /**
     * Apply one optional moderation action to the image linked to a report.
     *
     * @param int $imageId Image identifier.
     * @param string $action Requested action key.
     * @return void
     */
    private static function applyImageReportImageAction(int $imageId, string $action): void
    {
        if ($imageId < 1)
        {
            return;
        }

        switch ($action)
        {
            case 'set_standard':
                ImageModel::applyReportImageAction($imageId, $action);
                break;

            case 'set_sensitive':
            case 'set_pending':
            case 'set_approved':
            case 'set_rejected':
            case 'set_deleted':
                ImageModel::applyReportImageAction($imageId, $action);
                break;
        }
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

        // Require login and permission check
        self::requirePanelPermission('compare_images', $template);

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
                $selectedImage1 = ImageModel::findImageHashRow($hash1);
                $selectedImage2 = ImageModel::findImageHashRow($hash2);

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
        $imageHashes = ImageModel::listApprovedImageHashes();
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
        $template->assign('selected_image1_original_path', !empty($selectedImage1['image_hash']) ? '/gallery/original/' . $selectedImage1['image_hash'] : '');
        $template->assign('selected_image2_hash', $selectedImage2['image_hash'] ?? '');
        $template->assign('selected_image2_original_path', !empty($selectedImage2['image_hash']) ? '/gallery/original/' . $selectedImage2['image_hash'] : '');

        self::assignPanelPage(
            $template,
            'comparison',
            'Image Comparison',
            'Compare two approved uploads side-by-side and review hash similarity before taking moderation action.',
            'tools'
        );

        $template->render('panel/control_panel_comparison.html');
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

        // Require login and permission check
        self::requirePanelPermission('rehash_images', $template);

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
                $image = ImageModel::findApprovedImageByHash($imageHash);
                $hashRow = ImageModel::findImageHashRow($imageHash);

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
                $images = ImageModel::fetchImagesPendingRehash(10);
            }
            else
            {
                $images = [];
            }

            // Recalculate hashes for each selected image
            foreach ($images as $img)
            {
                $imgPath = IMAGE_PATH . '/' . str_replace("images/", "", $img['original_path']);

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
                    ImageModel::upsertImageHashes(array_merge([
                        'image_hash' => $img['image_hash'],
                        'ahash' => $newAhash,
                        'dhash' => $newDhash,
                        'phash' => $newPhash,
                    ], $phashBlocks));

                    // Mark image as rehashed in app_images
                    ImageModel::markImageRehashed((int) $img['id']);

                    $processedImages[] = $img['image_hash'];
                }
            }

            $count = count($processedImages);
            $message = $message ?: "Rehashed {$count} image" . ($count === 1 ? '' : 's') . " successfully.";
        }

        // Fetch all image hashes for selection dropdown
        $imageHashes = ImageModel::listApprovedImageHashes();
        $flatImageHashes = array_column($imageHashes, 'image_hash');

        // Assign template variables
        $template->assign('image_hashes', $flatImageHashes);
        $template->assign('processed_images', $processedImages);
        $template->assign('message', $message);

        self::assignPanelPage(
            $template,
            'rehash',
            'Image Rehashing',
            'Rebuild perceptual hash data for approved uploads when legacy records need to be refreshed or repaired.',
            'tools'
        );

        // Render control panel template with the rehash tool.
        $template->render('panel/control_panel_rehash.html');
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

        // Require login and permission check
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

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
        $image = ImageModel::findPendingServableImageByHash($hash);

        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        $baseDir = realpath(IMAGE_PATH);
        $fullPath = realpath($baseDir . '/' . ltrim(str_replace("images/", "", $image['original_path']), '/'));

        if (!$fullPath || strpos($fullPath, $baseDir) !== 0 || !file_exists($fullPath))
        {
            self::renderImageNotFound($template);
            return;
        }

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie');
        readfile($fullPath);
        exit;
    }
}
