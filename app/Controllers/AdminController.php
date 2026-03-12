<?php

/**
 * AdminController
 *
 * Provides an Administrator Control Panel for:
 * - Viewing security logs and block list entries
 * - Managing users (add/edit/remove)
 * - Managing application settings (app_settings)
 *
 * Security considerations:
 * - Administrator role required
 * - CSRF validation for all state-changing actions
 */
class AdminController
{
    /**
     * Cached configuration for controller usage.
     *
     * Stored statically so configuration is loaded once per request and reused
     * across controller actions without repeated disk reads.
     *
     * @var array
     */
    private static array $config;

    /**
     * Load and cache config once per request.
     *
     * Uses SettingsManager when available/initialized, otherwise falls back to
     * reading config/config.php from disk.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (empty(self::$config))
        {
            self::$config = SettingsManager::isInitialized() ? SettingsManager::getConfig() : (require CONFIG_PATH . '/config.php');
        }

        return self::$config;
    }

    /**
     * Initialize the template engine for admin panel pages.
     *
     * Creates TemplateEngine and assigns admin panel marker variables used by
     * header/navigation templates to render admin-specific styling.
     *
     * @return TemplateEngine
     */
    private static function initTemplate(): TemplateEngine
    {
        $config = self::getConfig();
        $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }        // Mark this request as an admin panel page so the header can include
        // admin-specific styling.
        $template->assign('is_gallery_page', 1);
        $template->assign('is_admin_panel', 1);

        return $template;
    }

    /**
     * Retrieve a username by user ID.
     *
     * Used for display purposes in the gallery UI. Returns an empty string when
     * the user ID is missing or no matching user is found.
     *
     * @param int|null $userId ID of the user, or null if not available.
     * @return string Username if found, otherwise empty string.
     */
    private static function getUsernameById(?int $userId): string
    {
        if ($userId === null)
        {
            return '';
        }

        // Query to fetch username by user ID
        $result = Database::fetch("SELECT username FROM app_users WHERE id = :id LIMIT 1", [':id' => $userId]);
        return TypeHelper::toString($result['username']) ?? '';
    }

    /**
     * Enforce administrator authorization for the current request.
     *
     * Reads session user_id and user_role. If the user is not an administrator,
     * respond with a 403 page and terminate the request.
     *
     * @return void
     */
    private static function requireAdmin(): void
    {
        $userId = TypeHelper::toInt(SessionManager::get('user_id'));
        $role = TypeHelper::toString(SessionManager::get('user_role'), allowEmpty: true) ?? '';

        if (!$userId || $role !== 'Administrator')
        {
            http_response_code(403);
            $template = self::initTemplate();
            $template->assign('title', 'Access Denied');
            $template->assign('message', 'Administrator access required.');
            $template->render('errors/error_page.html');
            exit();
        }
    }

    /**
     * Render the admin dashboard.
     *
     * Provides basic counts and recent security activity for quick visibility.
     *
     * @return void
     */
    public static function dashboard(): void
    {
        self::requireAdmin();

        $template = self::initTemplate();

        $totalUsers = Database::fetch("SELECT COUNT(*) AS total FROM app_users WHERE status != 'deleted'")['total'] ?? 0;
        $activeBlocks = 0;
        $recentLogs = [];

        try
        {
            $row = Database::fetch("SELECT COUNT(*) AS total FROM app_block_list WHERE expires_at IS NULL OR expires_at > NOW()") ?? [];
            $activeBlocks = TypeHelper::toInt($row['total'] ?? 0);
        }
        catch (Throwable $e)
        {
            $activeBlocks = 0;
        }

        try
        {
            $rows = Database::fetchAll("SELECT id, user_id, category, message, created_at FROM app_security_logs ORDER BY id DESC LIMIT 15");
            foreach ($rows as $r)
            {
                $recentLogs[] = [
                    TypeHelper::toString(DateHelper::date_only_format($r['created_at']) ?? ''),
                    TypeHelper::toString($r['category'] ?? ''),
                    TypeHelper::toString($r['message'] ?? ''),
                    TypeHelper::toString(ucfirst(self::getUsernameById($r['user_id'])) ?? ''),
                ];
            }
        }
        catch (Throwable $e)
        {
            $recentLogs = [];
        }

        $template->assign('total_users', $totalUsers);
        $template->assign('active_blocks', $activeBlocks);
        $template->assign('recent_logs', $recentLogs);
        $template->render('admin/admin_dashboard.html');
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
        self::requireAdmin();

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

        $template->assign('users', $users);
        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('admin/admin_users.html');
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
        self::requireAdmin();

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

        $template->assign('roles', $roles);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render('admin/admin_user_create.html');
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
        self::requireAdmin();

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
        $template->render('admin/admin_user_edit.html');
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
        self::requireAdmin();

        $template = self::initTemplate();

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

        $template->assign('settings', $settings);
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('admin/admin_settings.html');
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
        self::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /admin/settings');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /admin/settings?error=csrf');
            exit();
        }

        $key = Security::sanitizeString($_POST['key'] ?? '');
        $value = Security::sanitizeString($_POST['value'] ?? '');
        $type = Security::sanitizeString($_POST['type'] ?? 'string');

        if ($key === '')
        {
            header('Location: /admin/settings?error=key');
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

        header('Location: /admin/settings?success=1');
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
        self::requireAdmin();

        $template = self::initTemplate();

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
                '/admin/security/logs/view?id=' . $id,
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

        $baseUrl = '/admin/security/logs';
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

        $template->render('admin/admin_security_logs.html');
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
        self::requireAdmin();

        $id = TypeHelper::toInt($_GET['id'] ?? 0) ?? 0;
        if ($id < 1)
        {
            header('Location: /admin/security/logs');
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
            header('Location: /admin/security/logs');
            exit();
        }

        $template->assign('log_id', TypeHelper::toString($log['id'] ?? ''));
        $template->assign('log_created_at', TypeHelper::toString(DateHelper::date_only_format($log['created_at']) ?? ''));
        $template->assign('log_category', TypeHelper::toString($log['category'] ?? ''));
        $template->assign('log_message', TypeHelper::toString($log['message'] ?? ''));
        $template->assign('log_fingerprint', TypeHelper::toString($log['fingerprint'] ?? ''));
        $template->assign('log_ip', TypeHelper::toString(inet_ntop($log['ip'])) ?? '');
        $template->assign('log_ua', TypeHelper::toString($log['ua'] ?? ''));

        $userId = TypeHelper::toInt($log['user_id'] ?? 0) ?? 0;
        $template->assign('log_user', TypeHelper::toString(ucfirst(self::getUsernameById($userId)) ?? ''));

        $template->render('admin/admin_security_log_view.html');
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
        self::requireAdmin();

        $template = self::initTemplate();

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

        $template->assign('blocks', $blocks);
        $template->assign('filter_ip', TypeHelper::toString($filters['ip']));
        $template->assign('filter_fingerprint', TypeHelper::toString($filters['fingerprint']));
        $template->assign('filter_user_id', TypeHelper::toString($filters['user_id']));
        $template->assign('filter_status', TypeHelper::toString($filters['status']));
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->render('admin/admin_block_list.html');
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
        self::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /admin/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /admin/security/blocks?error=csrf');
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
            header('Location: /admin/security/blocks?error=scope');
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

        header('Location: /admin/security/blocks?created=1');
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
        self::requireAdmin();

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
        $template->render('admin/admin_block_edit.html');
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
        self::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /admin/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /admin/security/blocks?error=csrf');
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
            header('Location: /admin/security/blocks?error=match');
            exit();
        }

        Database::query(
            'DELETE FROM app_block_list WHERE ' . implode(' OR ', $where),
            $params
        );

        header('Location: /admin/security/blocks?removed=1');
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
        self::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            header('Location: /admin/security/blocks');
            exit();
        }

        $csrf = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrf))
        {
            header('Location: /admin/security/blocks?error=csrf');
            exit();
        }

        Database::query("DELETE FROM app_block_list WHERE id = :id", ['id' => $id]);

        header('Location: /admin/security/blocks?removed=1');
        exit();
    }
}
