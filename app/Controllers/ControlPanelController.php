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

        $notice = '';
        $noticeType = '';
        $settingsError = Security::sanitizeString($_GET['error'] ?? '');
        $settingsNotice = Security::sanitizeString($_GET['notice'] ?? '');

        if ($settingsNotice === 'saved' || isset($_GET['success']))
        {
            $notice = 'Setting saved successfully.';
            $noticeType = 'success';
        }
        else if ($settingsNotice === 'reset')
        {
            $notice = 'Setting reset to its default value successfully.';
            $noticeType = 'success';
        }
        else if ($settingsNotice === 'deleted')
        {
            $notice = 'Registry entry removed successfully.';
            $noticeType = 'success';
        }
        else if ($settingsNotice === 'category_saved')
        {
            $notice = 'Category details saved successfully.';
            $noticeType = 'success';
        }
        else if ($settingsNotice === 'category_reset')
        {
            $notice = 'Category display details reset to their default values.';
            $noticeType = 'success';
        }
        else if ($settingsNotice === 'category_deleted')
        {
            $notice = 'Custom category removed successfully.';
            $noticeType = 'success';
        }
        else if ($settingsError === 'csrf')
        {
            $notice = 'The request could not be verified. Please try again.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'key')
        {
            $notice = 'A valid registry key is required before saving.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'reserved')
        {
            $notice = 'That key belongs to protected runtime configuration and cannot be managed from this settings area.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'value')
        {
            $notice = 'The setting value is not valid for that field type.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'length')
        {
            $notice = 'That value is too large to store.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'category')
        {
            $notice = 'A valid category slug is required before saving category details.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'category_not_empty')
        {
            $notice = 'That category still contains settings entries. Remove or move those entries before deleting the category.';
            $noticeType = 'error';
        }

        $builtInDefinitionCatalog = self::getSettingsDefinitionCatalog();
        $definitionCatalog = $builtInDefinitionCatalog;
        $builtInDefinitionKeys = array_fill_keys(array_keys($builtInDefinitionCatalog), true);

        $baseCategoryCatalog = self::getSettingsCategoryCatalog();
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

        $categoryRows = SettingsModel::listCategories();

        $categoryIdMap = [];
        foreach ($categoryRows as $row)
        {
            $slug = self::normalizeSettingsCategorySlug(TypeHelper::toString($row['slug'] ?? ''));
            if ($slug === '')
            {
                continue;
            }

            $categoryIdMap[$slug] = TypeHelper::toInt($row['id'] ?? 0) ?? 0;
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

        $settingsRows = SettingsModel::listSettingRows();

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
                $categorySlug = class_exists('SettingsRegistry') ? SettingsRegistry::inferCategoryFromKey($key) : self::normalizeSettingsCategorySlug(explode('.', $key, 2)[0] ?? 'custom');
            }

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

            $row['category_slug'] = $categorySlug;
            $rowMap[$key] = $row;

            if (!isset($builtInDefinitionKeys[$key]))
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

                $definitionCatalog[$key] = [
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
        }

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

        if ($selectedCategory !== '' && !isset($categoryCatalog[$selectedCategory]))
        {
            $selectedCategory = '';
            if ($notice === '')
            {
                $notice = 'That settings category was not found.';
                $noticeType = 'error';
            }
        }

        $groupedSettingsRaw = [];
        foreach ($definitionCatalog as $key => $definition)
        {
            $categorySlug = self::normalizeSettingsCategorySlug(TypeHelper::toString($definition['category'] ?? ''));
            if ($categorySlug === '')
            {
                $categorySlug = class_exists('SettingsRegistry') ? SettingsRegistry::inferCategoryFromKey($key) : 'custom';
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

            $row = $rowMap[$key] ?? null;
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
            $options = [];

            if ($input === 'bool')
            {
                $currentBool = self::normalizeBoolStorageValue($valueForDisplay);
                $options = [
                    ['1', 'Enable', $currentBool === '1' ? 1 : 0],
                    ['0', 'Disable', $currentBool === '0' ? 1 : 0],
                ];
            }
            else if ($input === 'select')
            {
                $currentValue = TypeHelper::toString($valueAttribute);
                foreach (($definition['options'] ?? []) as $option)
                {
                    $optionValue = TypeHelper::toString($option[0] ?? '');
                    $optionLabel = TypeHelper::toString($option[1] ?? $optionValue);
                    $options[] = [$optionValue, $optionLabel, $optionValue === $currentValue ? 1 : 0];
                }
            }

            $groupedSettingsRaw[$categorySlug][] = [
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
                'options' => $options,
                'category' => $categorySlug,
                'status_class' => $statusClass,
                'has_stored_row' => $hasStoredRow,
                'is_custom_entry' => isset($builtInDefinitionKeys[$key]) ? 0 : 1,
                'sort_order' => TypeHelper::toInt($row['sort_order'] ?? ($definition['sort_order'] ?? 9999)) ?? 9999,
            ];
        }

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

        $pageTitle = 'Application Settings';
        $pageDescription = 'Choose a settings category to manage board behavior with cleaner labels, safer inputs, and clearer descriptions.';
        $selectedCategoryTitle = 'Settings Overview';
        $selectedCategoryDescription = 'Select a category below to manage those settings on a dedicated page.';
        $selectedCategoryIcon = 'fa-sliders';
        $selectedSettings = [];
        $currentNav = 'settings';

        if ($selectedCategory !== '')
        {
            $categoryMeta = $categoryCatalog[$selectedCategory];
            $selectedCategoryTitle = TypeHelper::toString($categoryMeta['title'] ?? self::humanizeSettingsToken($selectedCategory));
            $selectedCategoryDescription = TypeHelper::toString($categoryMeta['description'] ?? '');
            $selectedCategoryIcon = TypeHelper::toString($categoryMeta['icon'] ?? 'fa-sliders');
            $selectedSettings = $groupedSettings[$selectedCategory] ?? [];
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

        self::assignPanelPage(
            $template,
            $currentNav,
            $pageTitle,
            $pageDescription,
            'configuration'
        );

        $template->assign('settings_categories', $categoryCards);
        $template->assign('settings_selected_category', $selectedCategory);
        $template->assign('settings_selected_category_title', $selectedCategoryTitle);
        $template->assign('settings_selected_category_description', $selectedCategoryDescription);
        $template->assign('settings_selected_rows', $selectedSettings);
        $template->assign('settings_manage_categories', $managedCategories);
        $template->assign('settings_manage_mode', $manageCategories ? 1 : 0);
        $template->assign('settings_category_icon_options', self::getSettingsCategoryIconOptions());
        $template->assign('settings_overview_url', self::buildSettingsCategoryUrl(''));
        $template->assign('settings_category_manager_url', self::buildSettingsCategoryManagerUrl());
        $template->assign('settings_total_categories', $manageCategories ? count($managedCategories) : count($categoryCards));
        $template->assign('settings_total_settings', $totalSettingsCount);
        $template->assign('settings_total_stored', $storedSettingsCount);
        $template->assign('settings_total_fallback', $fallbackSettingsCount);
        $template->assign('settings_total_built_in_categories', $builtInCategoryCount);
        $template->assign('settings_total_custom_categories', $customCategoryCount);
        $template->assign('settings_selected_icon', $selectedCategoryIcon);
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: ' . self::buildSettingsCategoryUrl(''));
            exit();
        }

        $category = self::normalizeSettingsCategorySlug(Security::sanitizeString($_POST['category'] ?? ''));
        $redirectBase = self::buildSettingsCategoryUrl($category);

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: ' . $redirectBase . '?error=csrf');
            exit();
        }

        $key = self::normalizeSettingsKey(Security::sanitizeString($_POST['key'] ?? ''));
        $type = self::normalizeSettingsType(Security::sanitizeString($_POST['type'] ?? 'string'));
        $value = trim((string) ($_POST['value'] ?? ''));

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

        $normalized = self::normalizeSettingsValueForStorage($key, $value, $type);
        if (!$normalized['valid'])
        {
            $errorCode = $normalized['error'] === 'length' ? 'length' : 'value';
            header('Location: ' . $redirectBase . '?error=' . $errorCode);
            exit();
        }

        $definitionCatalog = self::getSettingsDefinitionCatalog();
        $builtInDefinition = $definitionCatalog[$key] ?? null;
        $existing = SettingsModel::findSettingByKey($key);

        if ($builtInDefinition !== null)
        {
            $category = self::normalizeSettingsCategorySlug(TypeHelper::toString($builtInDefinition['category'] ?? ''));
            $title = TypeHelper::toString($builtInDefinition['label'] ?? self::humanizeSettingsToken($key));
            $description = TypeHelper::toString($builtInDefinition['description'] ?? '');
            $inputType = TypeHelper::toString($builtInDefinition['input'] ?? 'text');
            $sortOrder = TypeHelper::toInt($builtInDefinition['sort_order'] ?? 0) ?? 0;
            $isSystem = !empty($builtInDefinition['is_system']) ? 1 : 0;
        }
        else
        {
            $category = $category !== '' ? $category : (class_exists('SettingsRegistry') ? SettingsRegistry::inferCategoryFromKey($key) : self::normalizeSettingsCategorySlug(explode('.', $key, 2)[0] ?? 'custom'));
            $generated = class_exists('SettingsRegistry') ? SettingsRegistry::buildGeneratedDefinition($key, $type, is_array($existing) ? $existing : null) : [];
            $title = trim(TypeHelper::toString($existing['title'] ?? ($generated['title'] ?? self::humanizeSettingsToken($key))));
            $description = trim(TypeHelper::toString($existing['description'] ?? ($generated['description'] ?? '')));
            $inputType = TypeHelper::toString($existing['input_type'] ?? ($generated['input'] ?? (class_exists('SettingsRegistry') ? SettingsRegistry::defaultInputForType($type) : 'text')));
            $sortOrder = TypeHelper::toInt($existing['sort_order'] ?? ($generated['sort_order'] ?? 9999)) ?? 9999;
            $isSystem = !empty($existing['is_system']) ? 1 : 0;
        }

        if ($category === '')
        {
            $category = 'custom';
        }

        $categoryId = self::ensureSettingsCategoryExists($category, $builtInDefinition !== null ? 1 : 0);
        if ($categoryId <= 0)
        {
            header('Location: ' . $redirectBase . '?error=category');
            exit();
        }

        SettingsModel::upsertSetting([
            'category_id' => $categoryId,
            'key_name' => $key,
            'title' => $title,
            'description' => $description,
            'value_data' => $normalized['value'],
            'type_name' => $type,
            'input_type' => $inputType,
            'sort_order' => $sortOrder,
            'is_system' => $isSystem,
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: ' . self::buildSettingsCategoryUrl(''));
            exit();
        }

        $redirectBase = self::buildSettingsCategoryManagerUrl();
        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: ' . $redirectBase . '?error=csrf');
            exit();
        }

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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: ' . self::buildSettingsCategoryUrl(''));
            exit();
        }

        $redirectBase = self::buildSettingsCategoryManagerUrl();
        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: ' . $redirectBase . '?error=csrf');
            exit();
        }

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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: ' . self::buildSettingsCategoryUrl(''));
            exit();
        }

        $category = self::normalizeSettingsCategorySlug(Security::sanitizeString($_POST['category'] ?? ''));
        $redirectBase = self::buildSettingsCategoryUrl($category);

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: ' . $redirectBase . '?error=csrf');
            exit();
        }

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

        $category = class_exists('SettingsRegistry') ? SettingsRegistry::inferCategoryFromKey($key) : self::normalizeSettingsCategorySlug(explode('.', $key, 2)[0] ?? 'custom');
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
    public static function securityLogs(): void
    {
        self::requirePanelPermission('view_security');

        $template = self::initTemplate();

        $filters = [
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
            'device_fingerprint' => Security::sanitizeString($_GET['device_fingerprint'] ?? ''),
            'browser_fingerprint' => Security::sanitizeString($_GET['browser_fingerprint'] ?? ''),
            'session_id' => Security::sanitizeString($_GET['session_id'] ?? ''),
            'user_id' => TypeHelper::toInt($_GET['user_id'] ?? 0) ?? 0,
            'category' => Security::sanitizeString($_GET['category'] ?? ''),
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
        ];

        // Current page for pagination. Defaults to page 1.
        $page = TypeHelper::toInt($_GET['page'] ?? 1) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }

        // Pagination sizing. Keep in sync with template expectations.
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($filters['ip'] !== '')
        {
            $packedIp = self::packIpFilter($filters['ip']);
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

        if ($filters['fingerprint'] !== '')
        {
            $where[] = 'l.fingerprint LIKE :fp';
            $params['fp'] = $filters['fingerprint'] . '%';
        }

        if ($filters['device_fingerprint'] !== '')
        {
            $where[] = 'l.device_fingerprint LIKE :dfp';
            $params['dfp'] = $filters['device_fingerprint'] . '%';
        }

        if ($filters['browser_fingerprint'] !== '')
        {
            $where[] = 'l.browser_fingerprint LIKE :bfp';
            $params['bfp'] = $filters['browser_fingerprint'] . '%';
        }

        if ($filters['session_id'] !== '')
        {
            $where[] = 'l.session_id LIKE :sid';
            $params['sid'] = $filters['session_id'] . '%';
        }

        if ($filters['user_id'] > 0)
        {
            $where[] = 'l.user_id = :uid';
            $params['uid'] = $filters['user_id'];
        }

        if ($filters['category'] !== '')
        {
            $where[] = 'l.category = :cat';
            $params['cat'] = $filters['category'];
        }

        if ($filters['q'] !== '')
        {
            $where[] = '(l.message LIKE :q OR l.ua LIKE :q OR l.session_id LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sqlWhere = '';
        if (!empty($where))
        {
            $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        }

        $total = 0;
        $logs = [];
        $categories = [];

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
            $logs = SecurityLogModel::fetchPage($sqlWhere, $params, $perPage, $offset);
        }
        catch (Throwable $e)
        {
            $logs = [];
        }

        // Fetch category options for filter dropdown.
        try
        {
            $categories = SecurityLogModel::listCategories();
        }
        catch (Throwable $e)
        {
            $categories = [];
        }

        // Normalize rows into template-friendly arrays.
        $logRows = $logs;
        $logs = [];
        foreach ($logRows as $l)
        {
            $id = TypeHelper::toInt($l['id'] ?? 0) ?? 0;
            $createdAt = TypeHelper::toString(DateHelper::date_only_format($l['created_at']) ?? '', allowEmpty: true) ?? '';
            $deviceFingerprint = TypeHelper::toString($l['device_fingerprint'] ?? '', allowEmpty: true) ?? '';
            $browserFingerprint = TypeHelper::toString($l['browser_fingerprint'] ?? '', allowEmpty: true) ?? '';
            $requestFingerprint = TypeHelper::toString($l['fingerprint'] ?? '', allowEmpty: true) ?? '';
            $sessionId = TypeHelper::toString($l['session_id'] ?? '', allowEmpty: true) ?? '';
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
                TypeHelper::toString($l['category'] ?? ''),
                TypeHelper::toString(ucfirst(self::getUsernameById($l['user_id'])) ?? ''),
                self::formatStoredIp($l['ip'] ?? null),
                implode(' | ', $signalSummary),
                '/panel/security/logs/view?id=' . $id,
            ];
        }

        $catRows = $categories;
        $categories = [];
        foreach ($catRows as $c)
        {
            $categories[] = [
                TypeHelper::toString($c['category'] ?? ''),
            ];
        }

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

        $queryParams = [];
        if ($filters['ip'] !== '')
        {
            $queryParams['ip'] = $filters['ip'];
        }

        if ($filters['fingerprint'] !== '')
        {
            $queryParams['fingerprint'] = $filters['fingerprint'];
        }

        if ($filters['device_fingerprint'] !== '')
        {
            $queryParams['device_fingerprint'] = $filters['device_fingerprint'];
        }

        if ($filters['browser_fingerprint'] !== '')
        {
            $queryParams['browser_fingerprint'] = $filters['browser_fingerprint'];
        }

        if ($filters['session_id'] !== '')
        {
            $queryParams['session_id'] = $filters['session_id'];
        }

        if ($filters['user_id'] > 0)
        {
            $queryParams['user_id'] = $filters['user_id'];
        }

        if ($filters['category'] !== '')
        {
            $queryParams['category'] = $filters['category'];
        }

        if ($filters['q'] !== '')
        {
            $queryParams['q'] = $filters['q'];
        }

        $baseUrl = '/panel/security/logs';
        $buildUrl = function (int $p) use ($baseUrl, $queryParams): string
        {
            $params = $queryParams;
            if ($p > 1)
            {
                $params['page'] = $p;
            }

            $qs = http_build_query($params);
            return $qs !== '' ? $baseUrl . '?' . $qs : $baseUrl;
        };

        $pagination_prev = $page > 1 ? $buildUrl($page - 1) : null;
        $pagination_next = $page < $totalPages ? $buildUrl($page + 1) : null;

        $range = 2;
        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);

        $pagination_pages = [];
        if ($start > 1)
        {
            $pagination_pages[] = [$buildUrl(1), 1, false];
            if ($start > 2)
            {
                $pagination_pages[] = [null, '...', false];
            }
        }

        for ($i = $start; $i <= $end; $i++)
        {
            $pagination_pages[] = [
                $buildUrl($i),
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
            $pagination_pages[] = [$buildUrl($totalPages), $totalPages, false];
        }

        self::assignPanelPage(
            $template,
            'security_logs',
            'Security Logs',
            'Filter and review audit events across request, device, browser, and session signals before drilling into the full request details.',
            'security'
        );

        $template->assign('logs', $logs);
        $template->assign('filter_ip', TypeHelper::toString($filters['ip']));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint']));
        $template->assign('filter_device_fingerprint', TypeHelper::toString($filters['device_fingerprint']));
        $template->assign('filter_browser_fingerprint', TypeHelper::toString($filters['browser_fingerprint']));
        $template->assign('filter_session_id', TypeHelper::toString($filters['session_id']));
        $template->assign('filter_user_id', TypeHelper::toString($filters['user_id']));
        $template->assign('filter_category', TypeHelper::toString($filters['category']));
        $template->assign('filter_q', TypeHelper::toString($filters['q']));
        $template->assign('categories', $categories);

        $template->assign('pagination_prev', $pagination_prev);
        $template->assign('pagination_next', $pagination_next);
        $template->assign('pagination_pages', $pagination_pages);

        $template->render('panel/control_panel_security_logs.html');
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

        $template = self::initTemplate();

        $log = null;
        try
        {
            $log = SecurityLogModel::findById($id);
        }
        catch (Throwable $e)
        {
            $log = null;
        }

        if (empty($log))
        {
            header('Location: /panel/security/logs');
            exit();
        }

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

        $notice = '';
        $noticeType = '';
        $blockError = Security::sanitizeString($_GET['error'] ?? '');
        if (isset($_GET['created']))
        {
            $notice = 'Block entry saved successfully.';
            $noticeType = 'success';
        }
        else if (isset($_GET['removed']))
        {
            $notice = 'Matching block entry records were removed.';
            $noticeType = 'success';
        }
        else if ($blockError === 'csrf')
        {
            $notice = 'The request could not be verified. Please try again.';
            $noticeType = 'error';
        }
        else if ($blockError === 'scope')
        {
            $notice = 'Choose a supported scope and provide a matching value before creating a block entry.';
            $noticeType = 'error';
        }
        else if ($blockError === 'match')
        {
            $notice = 'Provide at least one exact match value before removing entries.';
            $noticeType = 'error';
        }

        $filters = [
            'scope' => Security::sanitizeString($_GET['scope'] ?? ''),
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
            'device_fingerprint' => Security::sanitizeString($_GET['device_fingerprint'] ?? ''),
            'browser_fingerprint' => Security::sanitizeString($_GET['browser_fingerprint'] ?? ''),
            'user_id' => TypeHelper::toInt($_GET['user_id'] ?? 0) ?? 0,
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];

        $where = [];
        $params = [];

        if ($filters['scope'] !== '')
        {
            $where[] = 'scope = :scope';
            $params['scope'] = $filters['scope'];
        }

        if ($filters['ip'] !== '')
        {
            $packedIp = self::packIpFilter($filters['ip']);
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

        if ($filters['fingerprint'] !== '')
        {
            $where[] = 'fingerprint LIKE :fp';
            $params['fp'] = $filters['fingerprint'] . '%';
        }

        if ($filters['device_fingerprint'] !== '')
        {
            $where[] = 'device_fingerprint LIKE :dfp';
            $params['dfp'] = $filters['device_fingerprint'] . '%';
        }

        if ($filters['browser_fingerprint'] !== '')
        {
            $where[] = 'browser_fingerprint LIKE :bfp';
            $params['bfp'] = $filters['browser_fingerprint'] . '%';
        }

        if ($filters['user_id'] > 0)
        {
            $where[] = 'user_id = :uid';
            $params['uid'] = $filters['user_id'];
        }

        if ($filters['status'] !== '')
        {
            $where[] = 'status = :st';
            $params['st'] = $filters['status'];
        }

        $sqlWhere = '';
        if (!empty($where))
        {
            $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        }

        $blocks = [];
        try
        {
            $blocks = BlockListModel::listFiltered($sqlWhere, $params);
        }
        catch (Throwable $e)
        {
            $blocks = [];
        }

        $blockRows = $blocks;
        $blocks = [];
        foreach ($blockRows as $b)
        {
            $scope = TypeHelper::toString($b['scope'] ?? '', allowEmpty: true) ?? '';
            $matchValue = '';

            if ($scope === 'user_id')
            {
                $matchValue = TypeHelper::toString($b['user_id'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'ip')
            {
                $matchValue = self::formatStoredIp($b['ip'] ?? null);
            }
            else if ($scope === 'ua')
            {
                $matchValue = TypeHelper::toString($b['ua'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'device_fingerprint')
            {
                $matchValue = TypeHelper::toString($b['device_fingerprint'] ?? '', allowEmpty: true) ?? '';
            }
            else if ($scope === 'browser_fingerprint')
            {
                $matchValue = TypeHelper::toString($b['browser_fingerprint'] ?? '', allowEmpty: true) ?? '';
            }
            else
            {
                $matchValue = TypeHelper::toString($b['fingerprint'] ?? '', allowEmpty: true) ?? '';
            }

            $blocks[] = [
                TypeHelper::toInt($b['id'] ?? ''),
                $scope,
                TypeHelper::toString($b['status'] ?? ''),
                TypeHelper::toString($b['reason'] ?? ''),
                TypeHelper::toString($b['user_id'] ?? ''),
                TypeHelper::toString($matchValue),
                TypeHelper::toString(DateHelper::date_only_format($b['last_seen']) ?? '', allowEmpty: true) ?? '',
                TypeHelper::toString(DateHelper::date_only_format($b['expires_at']) ?? '', allowEmpty: true) ?? '',
            ];
        }

        self::assignPanelPage(
            $template,
            'block_list',
            'Block List',
            'Create, review, and remove enforcement records across user, IP, request, device, browser, and user-agent scopes.',
            'security'
        );

        $template->assign('blocks', $blocks);
        $template->assign('control_panel_notice', $notice);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->assign('filter_scope', TypeHelper::toString($filters['scope']));
        $template->assign('filter_ip', TypeHelper::toString($filters['ip']));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint']));
        $template->assign('filter_device_fingerprint', TypeHelper::toString($filters['device_fingerprint']));
        $template->assign('filter_browser_fingerprint', TypeHelper::toString($filters['browser_fingerprint']));
        $template->assign('filter_user_id', TypeHelper::toString($filters['user_id']));
        $template->assign('filter_status', TypeHelper::toString($filters['status']));
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

        $status = Security::sanitizeString($_POST['status'] ?? 'blocked');
        $reason = Security::sanitizeString($_POST['reason'] ?? '');
        $duration = TypeHelper::toInt($_POST['duration_minutes'] ?? 0) ?? 0;

        $scope = Security::sanitizeString($_POST['scope'] ?? '');
        $matchValue = Security::sanitizeString($_POST['match_value'] ?? '');

        // Backwards-compatible support for older templates posting individual fields.
        $userId = TypeHelper::toInt($_POST['user_id'] ?? 0) ?? 0;
        $ip = Security::sanitizeString($_POST['ip'] ?? '');
        $fingerprint = Security::sanitizeString($_POST['fingerprint'] ?? '');
        $deviceFingerprint = Security::sanitizeString($_POST['device_fingerprint'] ?? '');
        $browserFingerprint = Security::sanitizeString($_POST['browser_fingerprint'] ?? '');
        $ua = Security::sanitizeString($_POST['ua'] ?? '');

        if ($scope === '' || $matchValue === '')
        {
            if ($userId > 0)
            {
                $scope = 'user_id';
                $matchValue = (string)$userId;
            }
            else if ($ip !== '')
            {
                $scope = 'ip';
                $matchValue = $ip;
            }
            else if ($deviceFingerprint !== '')
            {
                $scope = 'device_fingerprint';
                $matchValue = $deviceFingerprint;
            }
            else if ($browserFingerprint !== '')
            {
                $scope = 'browser_fingerprint';
                $matchValue = $browserFingerprint;
            }
            else if ($fingerprint !== '')
            {
                $scope = 'fingerprint';
                $matchValue = $fingerprint;
            }
            else if ($ua !== '')
            {
                $scope = 'ua';
                $matchValue = $ua;
            }
        }

        if (!in_array($status, ['blocked', 'banned', 'jailed', 'rate_limited'], true))
        {
            $status = 'blocked';
        }

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
                header('Location: /panel/security/blocks?error=scope');
                exit();
            }
            $valueHash = hash('sha256', 'user|' . $uidStore);
        }
        else if ($scope === 'ip')
        {
            $packed = self::packIpFilter($matchValue);
            if ($packed === null)
            {
                header('Location: /panel/security/blocks?error=scope');
                exit();
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

        BlockListModel::upsert([
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
        ]);

        header('Location: /panel/security/blocks?created=1');
        exit();
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
            http_response_code(404);
            $template = self::initTemplate();
            $template->assign('title', 'Not Found');
            $template->assign('message', 'Block entry not found.');
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

            $status = Security::sanitizeString($_POST['status'] ?? $block['status']);
            $reason = Security::sanitizeString($_POST['reason'] ?? '');
            $expiresAt = Security::sanitizeString($_POST['expires_at'] ?? '');

            if (!in_array($status, ['blocked', 'banned', 'jailed', 'rate_limited'], true))
            {
                $status = TypeHelper::toString($block['status']);
            }

            $expires = null;
            if ($expiresAt !== '')
            {
                $expires = $expiresAt;
            }

            if (empty($errors))
            {
                try
                {
                    BlockListModel::updateById($id, $status, $reason !== '' ? $reason : null, $expires);

                    $success = 'Block entry updated.';
                    $block = BlockListModel::findById($id);
                }
                catch (Throwable $e)
                {
                    $errors[] = 'Failed to update entry.';
                }
            }
        }

        $template = self::initTemplate();
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

        $scope = Security::sanitizeString($_POST['scope'] ?? '');
        $matchValue = Security::sanitizeString($_POST['match_value'] ?? '');

        // Backwards-compatible support for older remove buttons.
        $userId = TypeHelper::toInt($_POST['user_id'] ?? 0) ?? 0;
        $ip = Security::sanitizeString($_POST['ip'] ?? '');
        $fingerprint = Security::sanitizeString($_POST['fingerprint'] ?? '');
        $deviceFingerprint = Security::sanitizeString($_POST['device_fingerprint'] ?? '');
        $browserFingerprint = Security::sanitizeString($_POST['browser_fingerprint'] ?? '');
        $ua = Security::sanitizeString($_POST['ua'] ?? '');

        if ($scope === '' || $matchValue === '')
        {
            if ($userId > 0)
            {
                $scope = 'user_id';
                $matchValue = (string)$userId;
            }
            else if ($ip !== '')
            {
                $scope = 'ip';
                $matchValue = $ip;
            }
            else if ($deviceFingerprint !== '')
            {
                $scope = 'device_fingerprint';
                $matchValue = $deviceFingerprint;
            }
            else if ($browserFingerprint !== '')
            {
                $scope = 'browser_fingerprint';
                $matchValue = $browserFingerprint;
            }
            else if ($fingerprint !== '')
            {
                $scope = 'fingerprint';
                $matchValue = $fingerprint;
            }
            else if ($ua !== '')
            {
                $scope = 'ua';
                $matchValue = $ua;
            }
        }

        $where = [];
        $params = [];

        if ($scope === 'user_id')
        {
            $uid = TypeHelper::toInt($matchValue) ?? 0;
            if ($uid > 0)
            {
                $where[] = 'scope = :scope AND user_id = :uid';
                $params['scope'] = $scope;
                $params['uid'] = $uid;
            }
        }
        else if ($scope === 'ip')
        {
            $packedIp = self::packIpFilter($matchValue);
            if ($packedIp !== null)
            {
                $where[] = 'scope = :scope AND ip = :ip';
                $params['scope'] = $scope;
                $params['ip'] = $packedIp;
            }
        }
        else if ($scope === 'fingerprint')
        {
            $where[] = 'scope = :scope AND fingerprint = :fp';
            $params['scope'] = $scope;
            $params['fp'] = $matchValue;
        }
        else if ($scope === 'device_fingerprint')
        {
            $where[] = 'scope = :scope AND device_fingerprint = :dfp';
            $params['scope'] = $scope;
            $params['dfp'] = $matchValue;
        }
        else if ($scope === 'browser_fingerprint')
        {
            $where[] = 'scope = :scope AND browser_fingerprint = :bfp';
            $params['scope'] = $scope;
            $params['bfp'] = $matchValue;
        }
        else if ($scope === 'ua')
        {
            $where[] = 'scope = :scope AND value_hash = :vh';
            $params['scope'] = $scope;
            $params['vh'] = hash('sha256', mb_strtolower($matchValue));
        }

        if (empty($where))
        {
            header('Location: /panel/security/blocks?error=match');
            exit();
        }

        BlockListModel::deleteWhere(implode(' OR ', $where), $params);

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


    public static function pending($page = null): void
    {
        $template = self::initTemplate();

        // Require login and permission check
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

        $page = TypeHelper::toInt($page ?? null) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }
        $perPage = 15; // number of images per page
        $offset = ($page - 1) * $perPage;

        // Fetch total pending images count
        $totalCount = ImageModel::countPendingImages();

        // Fetch paginated pending images
        $rows = ImageModel::fetchPendingImagesPage($offset, $perPage);

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
                "/panel/image-pending/page/{$i}",
                $i,
                $i === $page // current
            ];
        }

        $paginationPrev = $page > 1 ? "/panel/image-pending/page/" . ($page - 1) : null;
        $paginationNext = $page < $totalPages ? "/panel/image-pending/page/" . ($page + 1) : null;

        $template->assign('pagination_pages', $paginationPages);
        $template->assign('pagination_prev', $paginationPrev);
        $template->assign('pagination_next', $paginationNext);

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

        // Require login and permission check
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

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
            self::renderInvalidRequest($template);
            return;
        }

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow approving images that are currently pending.)
        $sql = "SELECT image_hash, status
                FROM app_images
                WHERE image_hash = :hash LIMIT 1";
        $image = ImageModel::findModerationStatusByHash($hash);

        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        // Only pending images can be approved
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /panel/image-pending');
            exit;
        }

        // Approve image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $appUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        ImageModel::approvePendingImage($hash, $appUserId, false);

        // Redirect back to the pending list after action completes
        header('Location: /panel/image-pending');
        exit;
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

        // Require login and permission check
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

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
            self::renderInvalidRequest($template);
            return;
        }

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow approving images that are currently pending.)
        $sql = "SELECT image_hash, status
                FROM app_images
                WHERE image_hash = :hash LIMIT 1";
        $image = ImageModel::findModerationStatusByHash($hash);

        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        // Only pending images can be approved
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /panel/image-pending');
            exit;
        }

        // Approve image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $appUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        ImageModel::approvePendingImage($hash, $appUserId, true);

        // Redirect back to the pending list after action completes
        header('Location: /panel/image-pending');
        exit;
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

        // Require login and permission check
        self::requirePanelAnyPermission(['moderate_site', 'moderate_gallery', 'moderate_image_queue'], $template);

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
            self::renderInvalidRequest($template);
            return;
        }

        // Ensure a valid hash is provided
        // (The router passes the hash from the URL, but we still validate it here.)
        $hash = TypeHelper::toString($hash);
        if ($hash === '')
        {
            self::renderErrorPage(404, '404 Not Found', 'Oops! We couldn’t find that image.', $template);
            return;
        }

        // Find the target image and confirm moderation state
        // (We only allow rejecting images that are currently pending.)
        $sql = "SELECT image_hash, status
                FROM app_images
                WHERE image_hash = :hash LIMIT 1";
        $image = ImageModel::findModerationStatusByHash($hash);

        if (!$image)
        {
            self::renderImageNotFound($template);
            return;
        }

        // Only pending images can be rejected
        // (If it has already been moderated, just return back to the pending list.)
        if (($image['status'] ?? '') !== 'pending')
        {
            header('Location: /panel/image-pending');
            exit;
        }

        // Reject image
        // - Record the moderator user id for audit/history tracking
        // - Store moderation timestamps for UI / reporting
        $rejUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        ImageModel::rejectPendingImage($hash, $rejUserId);

        // Redirect back to the pending list after action completes
        header('Location: /panel/image-pending');
        exit;
    }

    /**
     * Render the image report queue for staff review.
     *
     * @param int|null $page Current pagination page.
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

        $rows = ImageReportModel::fetchQueuePage($perPage, $offset);

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

        $staffNotes = ImageReportModel::fetchCommentsByReportId($id);

        $reporterUserId = TypeHelper::toInt($report['reporter_user_id'] ?? 0) ?? 0;
        $assignedUserId = TypeHelper::toInt($report['assigned_to_user_id'] ?? 0) ?? 0;
        $resolvedBy = TypeHelper::toInt($report['resolved_by'] ?? 0) ?? 0;
        $status = TypeHelper::toString($report['status'] ?? 'open');
        $normalizedStatus = ImageReportHelper::normalizeStatus($status);
        $currentStaffUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;

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

        self::assignPanelPage(
            $template,
            'reported',
            'Image Report Details',
            'Inspect the full report body, reporter context, assignment state, and staff findings for this image ticket.',
            'queue'
        );

        $template->assign('report_id', TypeHelper::toInt($report['id'] ?? 0));
        $template->assign('report_image_hash', TypeHelper::toString($report['image_hash'] ?? ''));
        $template->assign('report_image_status', ucfirst(TypeHelper::toString($report['image_status'] ?? '')));
        $template->assign('report_image_visibility', (TypeHelper::toInt($report['age_sensitive'] ?? 0) ?? 0) === 1 ? 'Sensitive' : 'Standard');
        $template->assign('report_image_created_at', DateHelper::date_only_format(TypeHelper::toString($report['image_created_at'] ?? '')));
        $template->assign('report_category', ImageReportHelper::categoryLabel(TypeHelper::toString($report['report_category'] ?? 'other')));
        $template->assign('report_subject', TypeHelper::toString($report['report_subject'] ?? ''));
        $template->assign('report_message', TypeHelper::toString($report['report_message'] ?? ''));
        $template->assign('report_status_label', ImageReportHelper::workflowStatusLabel($status, $assignedUserId));
        $template->assign('report_status_class', ImageReportHelper::workflowStatusClass($status, $assignedUserId));
        $template->assign('report_created_at', DateHelper::date_only_format(TypeHelper::toString($report['created_at'] ?? '')));
        $template->assign('report_updated_at', DateHelper::date_only_format(TypeHelper::toString($report['updated_at'] ?? '')));
        $assignedAt = TypeHelper::toString($report['assigned_at'] ?? null, allowEmpty: true);
        $resolvedAt = TypeHelper::toString($report['resolved_at'] ?? null, allowEmpty: true);
        $template->assign('report_assigned_at', $assignedAt ? DateHelper::date_only_format($assignedAt) : '');
        $template->assign('report_resolved_at', $resolvedAt ? DateHelper::date_only_format($resolvedAt) : '');
        $template->assign('report_reporter', $reporterUserId > 0 ? ucfirst(self::getUsernameById($reporterUserId)) : 'Guest');
        $template->assign('report_reporter_user_id', $reporterUserId > 0 ? (string)$reporterUserId : '');
        $template->assign('report_session_id', TypeHelper::toString($report['session_id'] ?? ''));
        $template->assign('report_ip', self::formatStoredIp($report['ip'] ?? null));
        $template->assign('report_ua', TypeHelper::toString($report['ua'] ?? ''));
        $template->assign('report_assigned_to', $assignedUserId > 0 ? ucfirst(TypeHelper::toString($report['assigned_username'] ?? self::getUsernameById($assignedUserId))) : '');
        $template->assign('report_assigned_user_id', $assignedUserId > 0 ? (string)$assignedUserId : '');
        $template->assign('report_resolved_by', $resolvedBy > 0 ? ucfirst(self::getUsernameById($resolvedBy)) : '');
        $template->assign('report_public_image_url', '/gallery/' . TypeHelper::toString($report['image_hash'] ?? ''));
        $template->assign('report_public_original_url', '/gallery/original/' . TypeHelper::toString($report['image_hash'] ?? ''));
        $template->assign('report_is_open', $normalizedStatus === 'open' ? 1 : 0);
        $template->assign('report_is_closed', $normalizedStatus === 'closed' ? 1 : 0);
        $template->assign('report_can_assign_self', $normalizedStatus === 'open' && $currentStaffUserId > 0 && $assignedUserId !== $currentStaffUserId ? 1 : 0);
        $template->assign('report_can_release_assignment', $normalizedStatus === 'open' && $assignedUserId > 0 ? 1 : 0);
        $template->assign('report_is_assigned_to_current_user', $assignedUserId > 0 && $assignedUserId === $currentStaffUserId ? 1 : 0);
        $template->assign('report_staff_notes', $noteRows);
        $template->assign('report_notes_count', count($noteRows));
        $template->assign('report_notice_state', Security::sanitizeString($_GET['updated'] ?? ''));

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
        $staffUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

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

        $staffUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $staffComment = Security::sanitizeString($_POST['staff_comment'] ?? '');
        $imageAction = strtolower(Security::sanitizeString($_POST['image_action'] ?? 'none'));
        $takeAssignment = isset($_POST['take_assignment']) && (TypeHelper::toInt($_POST['take_assignment'] ?? 0) ?? 0) === 1;
        $closeReport = isset($_POST['close_report']) && (TypeHelper::toInt($_POST['close_report'] ?? 0) ?? 0) === 1;

        $allowedImageActions = [
            'none',
            'set_standard',
            'set_sensitive',
            'set_pending',
            'set_approved',
            'set_rejected',
            'set_deleted',
        ];

        if (!in_array($imageAction, $allowedImageActions, true))
        {
            $imageAction = 'none';
        }

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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            $template->assign('title', 'Method Not Allowed');
            $template->assign('message', 'Invalid request.');
            $template->render('errors/error_page.html');
            return;
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            self::renderInvalidRequest($template);
            return;
        }

        $id = TypeHelper::toInt($id) ?? 0;
        if ($id < 1)
        {
            header('Location: /panel/image-reports');
            exit();
        }

        $status = ImageReportHelper::normalizeStatus($status);
        $staffUserId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;

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
