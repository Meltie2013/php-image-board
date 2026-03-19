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
 * - Administrator role required for administrative modules
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
        $role = self::getCurrentRole();
        $isAdmin = $role === 'administrator';
        $isModerator = $role === 'moderator';
        $isStaff = $isAdmin || $isModerator;

        $template->assign('current_control_panel_nav', $nav);
        $template->assign('current_control_panel_section', $section);
        $template->assign('control_panel_page_title', $title);
        $template->assign('control_panel_page_description', $description);
        $template->assign('control_panel_role', $role);
        $template->assign('cp_is_admin', $isAdmin ? 1 : 0);
        $template->assign('cp_is_moderator', $isModerator ? 1 : 0);
        $template->assign('cp_is_staff', $isStaff ? 1 : 0);
        $template->assign('cp_can_manage_users', $isAdmin ? 1 : 0);
        $template->assign('cp_can_manage_settings', $isAdmin ? 1 : 0);
        $template->assign('cp_can_view_security', $isAdmin ? 1 : 0);
        $template->assign('cp_can_manage_blocks', $isAdmin ? 1 : 0);
        $template->assign('cp_can_moderate_images', $isStaff ? 1 : 0);
        $template->assign('cp_can_compare_images', $isStaff ? 1 : 0);
        $template->assign('cp_can_rehash_images', $isAdmin ? 1 : 0);

    }

    /**
     * Get the normalized current control panel role from session data.
     *
     * @return string
     */
    private static function getCurrentRole(): string
    {
        return strtolower(TypeHelper::toString(SessionManager::get('user_role'), allowEmpty: true) ?? '');
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
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator', 'moderator'], $template);
    }

    /**
     * Enforce administrator authorization for administrative modules.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    private static function requirePanelAdmin(?TemplateEngine $template = null): void
    {
        $template = $template ?: self::initTemplate();
        RoleHelper::requireLogin();
        RoleHelper::requireRole(['administrator'], $template);
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
        $isAdmin = $role === 'administrator';

        $totalUsers = 0;
        $activeBlocks = 0;
        $recentLogs = [];

        if ($isAdmin)
        {
            try
            {
                $row = Database::fetch("SELECT COUNT(*) AS total FROM app_users WHERE status != 'deleted'") ?? [];
                $totalUsers = TypeHelper::toInt($row['total'] ?? 0) ?? 0;
            }
            catch (Throwable $e)
            {
                $totalUsers = 0;
            }

            try
            {
                $row = Database::fetch("SELECT COUNT(*) AS total FROM app_block_list WHERE expires_at IS NULL OR expires_at > NOW()") ?? [];
                $activeBlocks = TypeHelper::toInt($row['total'] ?? 0) ?? 0;
            }
            catch (Throwable $e)
            {
                $activeBlocks = 0;
            }

            try
            {
                $rows = Database::fetchAll("SELECT id, user_id, category, message, created_at FROM app_security_logs ORDER BY id DESC LIMIT 5");
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

        $totalImagesResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status IN ('approved', 'pending', 'deleted', 'rejected')");
        $approvedCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'approved'");
        $pendingCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'");
        $removedCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'deleted'");
        $rejectedCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'rejected'");
        $viewsCountResult = Database::fetch("SELECT COALESCE(SUM(views), 0) AS total_views FROM app_images WHERE status = 'approved';");

        $totalImages = TypeHelper::toInt($totalImagesResult['cnt'] ?? null) ?? 0;
        $approvedCount = TypeHelper::toInt($approvedCountResult['cnt'] ?? null) ?? 0;
        $pendingCount = TypeHelper::toInt($pendingCountResult['cnt'] ?? null) ?? 0;
        $removedCount = TypeHelper::toInt($removedCountResult['cnt'] ?? null) ?? 0;
        $rejectedCount = TypeHelper::toInt($rejectedCountResult['cnt'] ?? null) ?? 0;
        $combinedCount = TypeHelper::toInt($viewsCountResult['total_views'] ?? null) ?? 0;

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
     * Lists users and roles for administrator management, and provides CSRF
     * token for create/edit actions.
     *
     * @return void
     */
    public static function users(): void
    {
        self::requirePanelAdmin();

        $template = self::initTemplate();

        $rows = Database::fetchAll(
            "SELECT u.id, u.username, u.display_name, u.email, u.status, u.created_at, r.name AS role_name
             FROM app_users u
             INNER JOIN app_roles r ON u.role_id = r.id
             WHERE u.status != 'deleted'
             ORDER BY u.id ASC"
        );

        $users = [];
        foreach ($rows as $u)
        {
            $users[] = [
                TypeHelper::toInt($u['id'] ?? ''),
                TypeHelper::toString(ucfirst($u['username']) ?? ''),
                TypeHelper::toString($u['email'] ?? ''),
                TypeHelper::toString($u['role_name'] ?? ''),
                TypeHelper::toString($u['status'] ?? ''),
                TypeHelper::toString(DateHelper::format($u['created_at']) ?? ''),
            ];
        }

        $roleRows = Database::fetchAll("SELECT id, name FROM app_roles ORDER BY id ASC");
        $roles = [];
        foreach ($roleRows as $r)
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
            'Review member accounts, roles, and account states from a single control surface.',
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
        self::requirePanelAdmin();

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
            $roleId = TypeHelper::toInt($_POST['role_id'] ?? 3) ?? 3;
            $status = Security::sanitizeString($_POST['status'] ?? 'active');

            if ($username === '' || !$email || $password === '')
            {
                $errors[] = 'Username, email and password are required.';
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
                    Database::query(
                        "INSERT INTO app_users (role_id, username, display_name, email, password_hash, status, created_at, updated_at)
                         VALUES (:role, :username, :display, :email, :hash, :status, NOW(), NOW())",
                        [
                            'role' => $roleId,
                            'username' => $username,
                            'display' => $display !== '' ? $display : null,
                            'email' => $email,
                            'hash' => $hash,
                            'status' => $status,
                        ]
                    );

                    $success = 'User created.';
                }
                catch (Throwable $e)
                {
                    $errors[] = 'Failed to create user.';
                }
            }
        }

        $template = self::initTemplate();
        $roleRows = Database::fetchAll("SELECT id, name FROM app_roles ORDER BY id ASC");
        $roles = [];
        foreach ($roleRows as $r)
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
            'Create a new account with the correct role, status, and profile defaults.',
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
        self::requirePanelAdmin();

        $errors = [];
        $success = '';

        $user = Database::fetch(
            "SELECT u.id, u.role_id, u.username, u.display_name, u.email, u.status, r.name AS role_name
             FROM app_users u
             INNER JOIN app_roles r ON u.role_id = r.id
             WHERE u.id = :id LIMIT 1",
            ['id' => $id]
        );

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
            $roleId = TypeHelper::toInt($_POST['role_id'] ?? $user['role_id']) ?? TypeHelper::toInt($user['role_id']);
            $status = Security::sanitizeString($_POST['status'] ?? $user['status']);
            $password = Security::sanitizeString($_POST['password'] ?? '');

            if ($username === '' || !$email)
            {
                $errors[] = 'Username and email are required.';
            }

            if (!in_array($status, ['active', 'pending', 'suspended', 'deleted'], true))
            {
                $status = TypeHelper::toString($user['status']);
            }

            if (empty($errors))
            {
                try
                {
                    Database::query(
                        "UPDATE app_users SET role_id = :role, username = :username, display_name = :display, email = :email, status = :status, updated_at = NOW()
                         WHERE id = :id",
                        [
                            'role' => $roleId,
                            'username' => $username,
                            'display' => $display !== '' ? $display : null,
                            'email' => $email,
                            'status' => $status,
                            'id' => $id,
                        ]
                    );

                    if ($password !== '')
                    {
                        $hash = Security::hashPassword($password);
                        Database::query("UPDATE app_users SET password_hash = :hash WHERE id = :id",
                            ['hash' => $hash, 'id' => $id]
                        );
                    }

                    $success = 'User updated.';
                    $user = Database::fetch(
                        "SELECT u.id, u.role_id, u.username, u.display_name, u.email, u.status, r.name AS role_name
                         FROM app_users u
                         INNER JOIN app_roles r ON u.role_id = r.id
                         WHERE u.id = :id LIMIT 1",
                        ['id' => $id]
                    );
                }
                catch (Throwable $e)
                {
                    $errors[] = 'Failed to update user.';
                }
            }
        }

        $template = self::initTemplate();
        $roleRows = Database::fetchAll("SELECT id, name FROM app_roles ORDER BY id ASC");
        $roles = [];
        foreach ($roleRows as $r)
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
            'Update account identity, status, role assignments, and password data.',
            'accounts'
        );

        $template->assign('user_id', TypeHelper::toInt($user['id'] ?? ''));
        $template->assign('user_role_id', TypeHelper::toInt($user['role_id'] ?? ''));
        $template->assign('user_username', TypeHelper::toString($user['username'] ?? ''));
        $template->assign('user_display_name', TypeHelper::toString($user['display_name'] ?? ''));
        $template->assign('user_email', TypeHelper::toString($user['email'] ?? ''));
        $template->assign('user_status', TypeHelper::toString($user['status'] ?? ''));

        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render('panel/control_panel_user_edit.html');
    }

    /**
     * Render application settings management page.
     *
     * Reads app_settings and prepares safe/escaped data for display and editing.
     *
     * @return void
     */
    public static function settings(): void
    {
        self::requirePanelAdmin();

        $template = self::initTemplate();

        $notice = '';
        $noticeType = '';
        $settingsError = Security::sanitizeString($_GET['error'] ?? '');
        if (isset($_GET['success']))
        {
            $notice = 'Setting saved successfully.';
            $noticeType = 'success';
        }
        else if ($settingsError === 'csrf')
        {
            $notice = 'The request could not be verified. Please try again.';
            $noticeType = 'error';
        }
        else if ($settingsError === 'key')
        {
            $notice = 'A setting key is required before saving.';
            $noticeType = 'error';
        }

        $rows = Database::fetchAll("SELECT `key`, `value`, `type`, updated_at FROM app_settings ORDER BY `key` ASC");
        $settings = [];
        foreach ($rows as $s)
        {
            $key = TypeHelper::toString($s['key'] ?? '');
            $value = TypeHelper::toString($s['value'] ?? '');
            $type = TypeHelper::toString($s['type'] ?? '');
            $updatedAt = TypeHelper::toString(DateHelper::date_only_format($s['updated_at']) ?? '');

            // Escape values for safe rendering inside admin HTML (attribute + text contexts)
            $keyEsc = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $valueEsc = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $typeEsc = htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $settings[] = [
                $keyEsc,    // $s_key_disp
                $updatedAt, // $s_updated_at
                $keyEsc,    // $s_key_attr
                $valueEsc,  // $s_value_attr
                $typeEsc,   // $s_type
            ];
        }

        self::assignPanelPage(
            $template,
            'settings',
            'Application Settings',
            'Manage the site configuration keys that power gallery, chat, forum, and security behaviour.',
            'configuration'
        );

        $template->assign('settings', $settings);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('control_panel_notice', $notice);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->render('panel/control_panel_settings.html');
    }

    /**
     * Save an application setting (admin action).
     *
     * Validates CSRF and then inserts/updates app_settings via upsert.
     *
     * @return void
     */
    public static function settingsSave(): void
    {
        self::requirePanelAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /panel/settings');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /panel/settings?error=csrf');
            exit();
        }

        $key = Security::sanitizeString($_POST['key'] ?? '');
        $value = Security::sanitizeString($_POST['value'] ?? '');
        $type = Security::sanitizeString($_POST['type'] ?? 'string');

        if ($key === '')
        {
            header('Location: /panel/settings?error=key');
            exit();
        }

        if (!in_array($type, ['string', 'int', 'bool', 'json'], true))
        {
            $type = 'string';
        }

        Database::query(
            "INSERT INTO app_settings (`key`, `value`, `type`) VALUES (:k, :v, :t)
             ON DUPLICATE KEY UPDATE `value` = :v2, `type` = :t2",
            [
                'k' => $key,
                'v' => $value,
                't' => $type,
                'v2' => $value,
                't2' => $type,
            ]
        );

        header('Location: /panel/settings?success=1');
        exit();
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
        self::requirePanelAdmin();

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
            $notice = 'Choose a user, IP address, or fingerprint before creating a block entry.';
            $noticeType = 'error';
        }
        else if ($blockError === 'match')
        {
            $notice = 'Provide at least one block match value before removing entries.';
            $noticeType = 'error';
        }

        $filters = [
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
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
            $where[] = 'l.ip LIKE :ip';
            $params['ip'] = $filters['ip'] . '%';
        }

        if ($filters['fingerprint'] !== '')
        {
            $where[] = 'l.fingerprint LIKE :fp';
            $params['fp'] = $filters['fingerprint'] . '%';
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
            $where[] = '(l.message LIKE :q OR l.ua LIKE :q)';
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
            $row = Database::fetch(
                "SELECT COUNT(*) AS cnt
                 FROM app_security_logs l
                 {$sqlWhere}",
                $params
            );
            $total = TypeHelper::toInt($row['cnt'] ?? 0) ?? 0;
        }
        catch (Throwable $e)
        {
            $total = 0;
        }

        // Fetch the current page of results.
        try
        {
            $logs = Database::fetchAll(
                "SELECT l.id, l.user_id, l.category, l.created_at
                 FROM app_security_logs l
                 {$sqlWhere}
                 ORDER BY l.id DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );
        }
        catch (Throwable $e)
        {
            $logs = [];
        }

        // Fetch category options for filter dropdown.
        try
        {
            $categories = Database::fetchAll("SELECT DISTINCT category FROM app_security_logs ORDER BY category ASC LIMIT 100");
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

            $logs[] = [
                TypeHelper::toString(DateHelper::date_only_format($l['created_at']) ?? ''),
                TypeHelper::toString($l['category'] ?? ''),
                TypeHelper::toString(ucfirst(self::getUsernameById($l['user_id'])) ?? ''),
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
            'Filter and review audit events before drilling into the full request details.',
            'security'
        );

        $template->assign('logs', $logs);
        $template->assign('filter_ip', TypeHelper::toString(inet_ntop(hex2bin($filters['ip']))));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint']));
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
        self::requirePanelAdmin();

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
            $log = Database::fetch(
                "SELECT l.id, l.user_id, l.ip, l.ua, l.fingerprint, l.category, l.message, l.created_at
                 FROM app_security_logs l
                 WHERE l.id = :id
                 LIMIT 1",
                ['id' => $id]
            );
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

        self::assignPanelPage(
            $template,
            'security_logs',
            'Security Log Details',
            'Inspect the full event payload, client details, and audit metadata for a single entry.',
            'security'
        );

        $template->assign('log_id', TypeHelper::toInt($log['id'] ?? ''));
        $template->assign('log_created_at', TypeHelper::toString(DateHelper::date_only_format($log['created_at']) ?? ''));
        $template->assign('log_category', TypeHelper::toString($log['category'] ?? ''));
        $template->assign('log_message', TypeHelper::toString($log['message'] ?? ''));
        $template->assign('log_fingerprint', TypeHelper::toString($log['fingerprint'] ?? ''));
        $template->assign('log_ip', TypeHelper::toString(inet_ntop($log['ip'])) ?? '');
        $template->assign('log_ua', TypeHelper::toString($log['ua'] ?? ''));

        $userId = TypeHelper::toInt($log['user_id'] ?? 0) ?? 0;
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
        self::requirePanelAdmin();

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
            $notice = 'Choose a user, IP address, or fingerprint before creating a block entry.';
            $noticeType = 'error';
        }
        else if ($blockError === 'match')
        {
            $notice = 'Provide at least one block match value before removing entries.';
            $noticeType = 'error';
        }

        $filters = [
            'ip' => Security::sanitizeString($_GET['ip'] ?? ''),
            'fingerprint' => Security::sanitizeString($_GET['fingerprint'] ?? ''),
            'user_id' => TypeHelper::toInt($_GET['user_id'] ?? 0) ?? 0,
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
        ];

        $where = [];
        $params = [];

        if ($filters['ip'] !== '')
        {
            $where[] = 'ip LIKE :ip';
            $params['ip'] = $filters['ip'] . '%';
        }

        if ($filters['fingerprint'] !== '')
        {
            $where[] = 'fingerprint LIKE :fp';
            $params['fp'] = $filters['fingerprint'] . '%';
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
            $blocks = Database::fetchAll(
                "SELECT id, scope, status, reason, user_id, ip, ua, fingerprint, created_at, last_seen, expires_at
                 FROM app_block_list
                 {$sqlWhere}
                 ORDER BY id DESC
                 LIMIT 500",
                $params
            );
        }
        catch (Throwable $e)
        {
            $blocks = [];
        }

        $blockRows = $blocks;
        $blocks = [];
        foreach ($blockRows as $b)
        {
            $blocks[] = [
                TypeHelper::toInt($b['id'] ?? ''),
                TypeHelper::toString($b['scope'] ?? ''),
                TypeHelper::toString($b['status'] ?? ''),
                TypeHelper::toString($b['reason'] ?? ''),
                TypeHelper::toString($b['user_id'] ?? ''),
                TypeHelper::toString($b['ip'] ?? ''),
                TypeHelper::toString($b['fingerprint'] ?? ''),
                TypeHelper::toString($b['expires_at'] ?? ''),
            ];
        }

        self::assignPanelPage(
            $template,
            'block_list',
            'Block List',
            'Create, review, and remove temporary or permanent enforcement records.',
            'security'
        );

        $template->assign('blocks', $blocks);
        $template->assign('control_panel_notice', $notice);
        $template->assign('control_panel_notice_type', $noticeType);
        $template->assign('filter_ip', TypeHelper::toString($filters['ip']));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint']));
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
        self::requirePanelAdmin();

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

        $userId = TypeHelper::toInt($_POST['user_id'] ?? 0) ?? 0;
        $ip = Security::sanitizeString($_POST['ip'] ?? '');
        $fingerprint = Security::sanitizeString($_POST['fingerprint'] ?? '');

        if (!in_array($status, ['blocked', 'banned', 'jailed', 'rate_limited'], true))
        {
            $status = 'blocked';
        }

        $expires = null;
        if ($duration > 0)
        {
            $expires = gmdate('Y-m-d H:i:s', time() + ($duration * 60));
        }

        $scope = '';
        $valueHash = '';
        $ipStore = null;
        $fpStore = null;
        $uidStore = null;

        if ($userId > 0)
        {
            $scope = 'user_id';
            $uidStore = $userId;
            $valueHash = hash('sha256', 'user|' . $userId);
        }
        else if ($ip !== '')
        {
            $scope = 'ip';
            $ipNorm = $ip;
            $packed = @inet_pton($ip);
            if ($packed !== false)
            {
                $ipStore = $packed;
                $ipNorm = @inet_ntop($packed);
                if ($ipNorm === false)
                {
                    $ipNorm = $ip;
                }
            }
            $valueHash = hash('sha256', 'ip|' . $ipNorm);
        }
        else if ($fingerprint !== '')
        {
            $scope = 'fingerprint';
            $fpStore = $fingerprint;
            $valueHash = hash('sha256', 'fp|' . $fingerprint);
        }

        if ($scope === '')
        {
            header('Location: /panel/security/blocks?error=scope');
            exit();
        }

        Database::query(
            "INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES (:scope, :vh, :uid, :ip, NULL, :fp, :status, :reason, NOW(), NOW(), :exp)
             ON DUPLICATE KEY UPDATE status = :status_upd, reason = :reason_upd, last_seen = NOW(), expires_at = :exp_upd",
            [
                'scope' => $scope,
                'vh' => $valueHash,
                'status' => $status,
                'reason' => $reason !== '' ? $reason : null,
                'uid' => $uidStore,
                'ip' => $ipStore,
                'fp' => $fpStore,
                'exp' => $expires,
                'status_upd' => $status,
                'reason_upd' => $reason !== '' ? $reason : null,
                'exp_upd' => $expires,
            ]
        );

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
        self::requirePanelAdmin();

        $errors = [];
        $success = '';

        $block = Database::fetch(
            "SELECT id, scope, status, reason, user_id, ip, ua, fingerprint, created_at, last_seen, expires_at
             FROM app_block_list
             WHERE id = :id LIMIT 1",
            ['id' => $id]
        );

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
                    Database::query(
                        "UPDATE app_block_list
                         SET status = :status, reason = :reason, expires_at = :exp, last_seen = NOW()
                         WHERE id = :id",
                        [
                            'status' => $status,
                            'reason' => $reason !== '' ? $reason : null,
                            'exp' => $expires,
                            'id' => $id,
                        ]
                    );

                    $success = 'Block entry updated.';
                    $block = Database::fetch(
                        "SELECT id, scope, status, reason, user_id, ip, ua, fingerprint, created_at, last_seen, expires_at
                         FROM app_block_list
                         WHERE id = :id LIMIT 1",
                        ['id' => $id]
                    );
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
        $template->assign('block_ip', TypeHelper::toString($block['ip'] ?? ''));
        $template->assign('block_fingerprint', TypeHelper::toString($block['fingerprint'] ?? ''));
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
        self::requirePanelAdmin();

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

        $userId = TypeHelper::toInt($_POST['user_id'] ?? 0) ?? 0;
        $ip = Security::sanitizeString($_POST['ip'] ?? '');
        $fingerprint = Security::sanitizeString($_POST['fingerprint'] ?? '');

        $where = [];
        $params = [];

        if ($userId > 0)
        {
            $where[] = 'user_id = :uid';
            $params['uid'] = $userId;
        }

        if ($ip !== '')
        {
            $where[] = 'ip = :ip';
            $params['ip'] = $ip;
        }

        if ($fingerprint !== '')
        {
            $where[] = 'fingerprint = :fp';
            $params['fp'] = $fingerprint;
        }

        if (empty($where))
        {
            header('Location: /panel/security/blocks?error=match');
            exit();
        }

        Database::query(
            'DELETE FROM app_block_list WHERE ' . implode(' OR ', $where),
            $params
        );

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
        self::requirePanelAdmin();

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

        Database::query("DELETE FROM app_block_list WHERE id = :id", ['id' => $id]);

        header('Location: /panel/security/blocks?removed=1');
        exit();
    }


    public static function pending($page = null): void
    {
        $template = self::initTemplate();

        // Require login and role check
        self::requirePanelAccess($template);

        $page = TypeHelper::toInt($page ?? null) ?? 1;
        if ($page < 1)
        {
            $page = 1;
        }
        $perPage = 15; // number of images per page
        $offset = ($page - 1) * $perPage;

        // Fetch total pending images count
        $totalCountResult = Database::fetch("SELECT COUNT(*) AS cnt FROM app_images WHERE status = 'pending'");
        $totalCount = TypeHelper::toInt($totalCountResult['cnt'] ?? null) ?? 0;

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

        // Require login and role check
        self::requirePanelAccess($template);

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
        $image = Database::fetch($sql, [':hash' => $hash]);

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

        // Require login and role check
        self::requirePanelAccess($template);

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
        $image = Database::fetch($sql, [':hash' => $hash]);

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

        // Require login and role check
        self::requirePanelAccess($template);

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
        $image = Database::fetch($sql, [':hash' => $hash]);

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
        header('Location: /panel/image-pending');
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
        self::requirePanelAccess($template);

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

        // Require login and role check
        self::requirePanelAdmin($template);

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

        // Require login and role check
        self::requirePanelAccess($template);

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
