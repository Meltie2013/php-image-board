<?php

/**
 * Installer / Updater Portal for PHP Image Board.
 *
 * Responsibilities:
 * - Provide a safe, guided first-time install experience (config + database)
 * - Provide a locked-down update panel for applying SQL migrations and config merges
 * - Verify filesystem permissions and create required directories safely
 *
 * Security considerations:
 * - Dedicated login (separate from the site's account system)
 * - CSRF protection for all state-changing POST actions
 * - Minimal error disclosure (detailed errors only when explicitly requested)
 *
 * IMPORTANT:
 * - Remove or restrict the /install directory after successful setup.
 */

// -------------------------------------------------
// Bootstrap installer runtime (no app bootstrap here)
// -------------------------------------------------

declare(strict_types=1);

session_name('installer_session');

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

// -------------------------------------------------
// Paths / constants
// -------------------------------------------------

define('APP_ROOT', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
define('CONFIG_DIR', APP_ROOT . '/config');
define('CONFIG_DIST', CONFIG_DIR . '/config.php.dist');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');

define('INSTALLER_AUTH_FILE', CONFIG_DIR . '/installer_auth.php');

define('INSTALL_SQL_FILE', __DIR__ . '/base_database.sql');
define('UPDATES_DIR', APP_ROOT .  '/updates');

define('INSTALLER_CSS', '/assets/css/installer.css');

// -------------------------------------------------
// Minimal security helpers (standalone)
// -------------------------------------------------

final class InstallerSecurity
{
    public static function initCsrf(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        self::initCsrf();
        return (string) $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::initCsrf();
        return is_string($token) && hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }

        return $hash ?: '';
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// -------------------------------------------------
// Utility helpers
// -------------------------------------------------

function installer_redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}


function installer_safe_return_to(string $candidate, string $fallback): string
{
    $candidate = trim($candidate);

    if ($candidate === '') {
        return $fallback;
    }

    // Only allow local returns to index.php to avoid open-redirects.
    if (strpos($candidate, 'index.php') !== 0) {
        return $fallback;
    }

    return $candidate;
}


function installer_flash_add(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function installer_flash_get_all(): array
{
    $flash = $_SESSION['flash'] ?? [];
    $_SESSION['flash'] = [];
    return is_array($flash) ? $flash : [];
}

function installer_is_logged_in(): bool
{
    return !empty($_SESSION['installer_authed']);
}

function installer_require_login(): void
{
    if (!installer_is_logged_in()) {
        installer_redirect('index.php');
    }
}

function installer_read_auth_config(): ?array
{
    if (!is_file(INSTALLER_AUTH_FILE)) {
        return null;
    }

    $data = require INSTALLER_AUTH_FILE;

    if (!is_array($data)) {
        return null;
    }

    if (empty($data['username']) || empty($data['password_hash'])) {
        return null;
    }

    return $data;
}

function installer_write_auth_config(string $username, string $password): array
{
    $username = trim($username);
    $password = (string) $password;

    $hash = InstallerSecurity::hashPassword($password);

    $payload = [
        'username'      => $username,
        'password_hash' => $hash,
        'created_at'    => date('c'),
    ];

    $php = "<?php\n\n";
    $php .= "/**\n";
    $php .= " * Private Installer/Updater Credentials\n";
    $php .= " *\n";
    $php .= " * This file is intentionally NOT referenced by the main application.\n";
    $php .= " * Keep this private. If you remove /install after setup, you can also\n";
    $php .= " * remove this file.\n";
    $php .= " */\n\n";
    $php .= "return " . var_export($payload, true) . ";\n";

    $ok = false;
    $err = '';

    if (is_dir(CONFIG_DIR) && is_writable(CONFIG_DIR)) {
        $ok = @file_put_contents(INSTALLER_AUTH_FILE, $php, LOCK_EX) !== false;
        if (!$ok) {
            $err = 'Unable to write credentials file.';
        }
    } else {
        $err = 'Config directory is not writable.';
    }

    return [
        'ok'      => $ok,
        'error'   => $err,
        'content' => $php,
        'path'    => INSTALLER_AUTH_FILE,
    ];
}

function installer_load_config_dist(): array
{
    if (!is_file(CONFIG_DIST)) {
        return [];
    }

    $arr = require CONFIG_DIST;
    return is_array($arr) ? $arr : [];
}

function installer_load_config_existing(): array
{
    if (!is_file(CONFIG_FILE)) {
        return [];
    }

    $arr = require CONFIG_FILE;
    return is_array($arr) ? $arr : [];
}

/**
 * Recursively merge existing config values into the dist structure.
 * - For keys in dist: keep existing if present, else use dist default.
 * - For keys not in dist: keep existing (so nothing is lost).
 */
function installer_merge_config(array $dist, array $existing): array
{
    $merged = $dist;

    foreach ($merged as $k => $v) {
        if (array_key_exists($k, $existing)) {
            if (is_array($v) && is_array($existing[$k])) {
                $merged[$k] = installer_merge_config($v, $existing[$k]);
            } else {
                $merged[$k] = $existing[$k];
            }
        }
    }

    foreach ($existing as $k => $v) {
        if (!array_key_exists($k, $merged)) {
            $merged[$k] = $v;
        }
    }

    return $merged;
}

/**
 * Collect leaf key paths for any missing keys from dist compared to existing config.
 * Used by the updater to show what will be added during a config merge.
 *
 * Example output:
 * - site.version
 * - gallery.images_per_page
 */
function installer_collect_missing_config_keys(array $dist, array $existing, string $prefix = ''): array
{
    $missing = [];

    foreach ($dist as $k => $v) {
        $path = ($prefix === '') ? (string) $k : ($prefix . '.' . (string) $k);

        if (!array_key_exists($k, $existing)) {
            // If a whole branch is missing, list the leaf keys beneath it for clarity.
            if (is_array($v)) {
                $missing = array_merge($missing, installer_collect_missing_config_keys($v, [], $path));
            } else {
                $missing[] = $path;
            }
            continue;
        }

        if (is_array($v) && is_array($existing[$k])) {
            $missing = array_merge($missing, installer_collect_missing_config_keys($v, $existing[$k], $path));
        }
    }

    return $missing;
}

/**
 * Convert a PHP value to a single-line literal that matches config.php.dist style.
 */
function installer_value_literal(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_null($value)) {
        return 'null';
    }

    if (is_string($value)) {
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
        return "'" . $escaped . "'";
    }

    // Arrays should not appear here (we only replace leaf values), but fallback anyway.
    return var_export($value, true);
}

/**
 * Replace leaf values in the config template (config.php.dist content) by key-path.
 * Keeps ALL comments, formatting, and section layout from the dist file.
 */
function installer_apply_values_to_template(string $template, array $finalConfig): string
{
    $lines = preg_split("/\r\n|\n|\r/", $template);
    if (!is_array($lines)) {
        return $template;
    }

    $stack = []; // each item: ['key' => string, 'indent' => int]
    $out = [];

    foreach ($lines as $line) {
        $trim = ltrim($line);
        $indent = strlen($line) - strlen($trim);

        // Pop stack when indentation decreases at a closing bracket
        if (preg_match('/^\s*\],\s*$/', $line)) {
            while (!empty($stack) && $indent <= $stack[count($stack) - 1]['indent']) {
                array_pop($stack);
            }
            $out[] = $line;
            continue;
        }

        // Array start: 'key' => [
        if (preg_match("/^(\s*)'([^']+)'\s*=>\s*\[\s*(\/\/.*)?$/", $line, $m)) {
            $stack[] = ['key' => $m[2], 'indent' => $indent];
            $out[] = $line;
            continue;
        }

        // Leaf assignment: 'key' => value,
        if (preg_match("/^(\s*)'([^']+)'\s*=>\s*(.+?),\s*(\/\/.*)?$/", $line, $m)) {
            $key = $m[2];

            // Build path
            $path = array_map(fn($i) => $i['key'], $stack);
            $path[] = $key;

            // Resolve value
            $value = $finalConfig;
            $found = true;
            foreach ($path as $p) {
                if (is_array($value) && array_key_exists($p, $value)) {
                    $value = $value[$p];
                } else {
                    $found = false;
                    break;
                }
            }

            if ($found && !is_array($value)) {
                $literal = installer_value_literal($value);

                // Preserve inline comment spacing if present
                $comment = isset($m[4]) && trim((string) $m[4]) !== '' ? ' ' . rtrim((string) $m[4]) : '';
                $out[] = $m[1] . "'" . $key . "' => " . $literal . "," . $comment;
                continue;
            }
        }

        $out[] = $line;
    }

    return implode("\n", $out) . "\n";
}

function installer_write_config_from_dist(array $finalConfig): array
{
    if (!is_file(CONFIG_DIST)) {
        return ['ok' => false, 'error' => 'Missing config.php.dist', 'content' => ''];
    }

    $template = (string) file_get_contents(CONFIG_DIST);
    $rendered = installer_apply_values_to_template($template, $finalConfig);

    // Ensure config file begins with php tag (dist already does)
    $ok = false;
    $err = '';
    if (is_dir(CONFIG_DIR) && is_writable(CONFIG_DIR)) {
        // If config.php exists but is not writable, writing will fail (we handle fallback)
        $ok = @file_put_contents(CONFIG_FILE, $rendered, LOCK_EX) !== false;
        if (!$ok) {
            $err = 'Unable to write config.php (permission denied).';
        }
    } else {
        $err = 'Config directory is not writable.';
    }

    return ['ok' => $ok, 'error' => $err, 'content' => $rendered];
}

function installer_required_dirs(): array
{
    return [
        APP_ROOT . '/cache',
        APP_ROOT . '/cache/images',
        APP_ROOT . '/cache/templates',
        APP_ROOT . '/images',
        APP_ROOT . '/images/original',
    ];
}

function installer_ensure_index_html(string $dir): void
{
    $idx = rtrim($dir, '/') . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '', LOCK_EX);
    }
}

function installer_check_dirs(): array
{
    $results = [];

    foreach (installer_required_dirs() as $dir) {
        $exists = is_dir($dir);
        $writable = $exists ? is_writable($dir) : (is_dir(dirname($dir)) && is_writable(dirname($dir)));

        $results[] = [
            'path'     => $dir,
            'exists'   => $exists,
            'writable' => $writable,
        ];
    }

    // Config write checks
    $results[] = [
        'path'     => CONFIG_DIR,
        'exists'   => is_dir(CONFIG_DIR),
        'writable' => is_dir(CONFIG_DIR) && is_writable(CONFIG_DIR),
    ];

    $results[] = [
        'path'     => CONFIG_FILE,
        'exists'   => is_file(CONFIG_FILE),
        'writable' => is_file(CONFIG_FILE) ? is_writable(CONFIG_FILE) : (is_dir(CONFIG_DIR) && is_writable(CONFIG_DIR)),
    ];

    return $results;
}

function installer_create_dirs(): array
{
    $created = [];
    $errors = [];

    foreach (installer_required_dirs() as $dir) {
        if (!is_dir($dir)) {
            $ok = @mkdir($dir, 0755, true);
            if ($ok) {
                $created[] = $dir;
                installer_ensure_index_html($dir);
            } else {
                $errors[] = 'Unable to create directory: ' . $dir;
            }
        } else {
            installer_ensure_index_html($dir);
        }
    }

    // Ensure cache subfolders also have an index
    installer_ensure_index_html(APP_ROOT . '/cache');
    installer_ensure_index_html(APP_ROOT . '/cache/images');
    installer_ensure_index_html(APP_ROOT . '/cache/templates');

    return ['created' => $created, 'errors' => $errors];
}

function installer_pdo_from_config(array $config): ?PDO
{
    if (empty($config['db']) || !is_array($config['db'])) {
        return null;
    }

    $db = $config['db'];
    $host = (string) ($db['host'] ?? '');
    $dbname = (string) ($db['dbname'] ?? '');
    $user = (string) ($db['user'] ?? '');
    $pass = (string) ($db['pass'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($host === '' || $dbname === '' || $user === '') {
        return null;
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Allow multi statements for SQL dump execution
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);

        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function installer_db_test(array $config): array
{
    $db = $config['db'] ?? [];
    $host = (string) ($db['host'] ?? '');
    $dbname = (string) ($db['dbname'] ?? '');
    $user = (string) ($db['user'] ?? '');
    $pass = (string) ($db['pass'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($host === '' || $dbname === '' || $user === '') {
        return ['ok' => false, 'error' => 'Database fields are missing.'];
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

    try {
        new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function installer_db_ensure_updates_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function installer_db_get_applied_updates(PDO $pdo): array
{
    installer_db_ensure_updates_table($pdo);

    $stmt = $pdo->query("SELECT filename FROM app_updates");
    $rows = $stmt ? $stmt->fetchAll() : [];

    $applied = [];
    foreach ($rows as $r) {
        if (!empty($r['filename'])) {
            $applied[(string) $r['filename']] = true;
        }
    }

    return $applied;
}

function installer_db_mark_update_applied(PDO $pdo, string $filename): void
{
    installer_db_ensure_updates_table($pdo);

    $stmt = $pdo->prepare("INSERT IGNORE INTO app_updates (filename, applied_at) VALUES (:f, NOW())");
    $stmt->execute([':f' => $filename]);
}

function installer_apply_sql_file(PDO $pdo, string $sqlFilePath): array
{
    if (!is_file($sqlFilePath)) {
        return ['ok' => false, 'error' => 'SQL file not found: ' . $sqlFilePath];
    }

    $sql = (string) file_get_contents($sqlFilePath);
    if (trim($sql) === '') {
        return ['ok' => true, 'error' => ''];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->commit();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function installer_list_update_files(): array
{
    if (!is_dir(UPDATES_DIR)) {
        return [];
    }

    $files = glob(UPDATES_DIR . '/*.sql') ?: [];
    sort($files, SORT_NATURAL);

    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'path'     => $file,
            'filename' => basename($file),
        ];
    }

    return $out;
}

// -------------------------------------------------
// Handle actions
// -------------------------------------------------

InstallerSecurity::initCsrf();

$action = $_POST['action'] ?? '';
$tab = $_GET['tab'] ?? 'install';
$page = $_GET['page'] ?? 'overview';

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    installer_redirect('index.php');
}

// Auth setup / login
$authConfig = installer_read_auth_config();

if ($action === 'auth_setup') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        installer_redirect('index.php');
    }

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    $errors = [];

    if (trim($username) === '') $errors[] = 'Username is required.';
    if ($password === '') $errors[] = 'Password is required.';
    if ($password !== $password2) $errors[] = 'Password confirmation does not match.';
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters.';

    if (!empty($errors)) {
        foreach ($errors as $err) installer_flash_add('danger', $err);
        installer_redirect('index.php');
    }

    $result = installer_write_auth_config($username, $password);

    if ($result['ok']) {
        installer_flash_add('success', 'Installer credentials saved. Please log in.');
        installer_redirect('index.php');
    }

    // Fallback: show copy/paste content
    $_SESSION['auth_setup_fallback'] = $result;
    installer_redirect('index.php');
}

if ($action === 'login') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        installer_redirect('index.php');
    }

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $authConfig = installer_read_auth_config();

    if (!$authConfig) {
        installer_flash_add('danger', 'Installer credentials are not configured yet.');
        installer_redirect('index.php');
    }

    if (!hash_equals((string) $authConfig['username'], trim($username))) {
        installer_flash_add('danger', 'Invalid credentials.');
        installer_redirect('index.php');
    }

    if (!InstallerSecurity::verifyPassword($password, (string) $authConfig['password_hash'])) {
        installer_flash_add('danger', 'Invalid credentials.');
        installer_redirect('index.php');
    }

    $_SESSION['installer_authed'] = true;

    // Regenerate session id to reduce fixation risk
    session_regenerate_id(true);

    installer_flash_add('success', 'Logged in.');
    installer_redirect('index.php');
}

// Installer / updater actions require login
if ($action !== '' && !in_array($action, ['auth_setup', 'login'], true)) {
    installer_require_login();
}

$distConfig = installer_load_config_dist();
$existingConfig = installer_load_config_existing();
$mergedConfig = installer_merge_config($distConfig, $existingConfig);

/**
 * Overlay values onto an existing config array.
 * - Only keys present in $overlay are applied.
 * - Preserves any keys not included in $overlay.
 */
function installer_overlay_config(array $base, array $overlay): array
{
    foreach ($overlay as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = installer_overlay_config($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }

    return $base;
}

// Save config
if ($action === 'save_config') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=install&page=config');
    installer_redirect($rt);
    }

    $incoming = $_POST['config'] ?? [];
    if (!is_array($incoming)) {
        $incoming = [];
    }

    // Cast types based on dist defaults

    $cast = function ($default, $value) use (&$cast) {
        if (is_array($default) && is_array($value)) {
            $out = $default;
            foreach ($default as $k => $dv) {
                if (array_key_exists($k, $value)) {
                    $out[$k] = $cast($dv, $value[$k]);
                }
            }
            // keep extra keys
            foreach ($value as $k => $vv) {
                if (!array_key_exists($k, $out)) {
                    $out[$k] = $vv;
                }
            }
            return $out;
        }

        if (is_bool($default)) {
            return $value === '1' || $value === 1 || $value === true || $value === 'true';
        }

        if (is_int($default)) {
            return (int) $value;
        }

        if (is_float($default)) {
            return (float) $value;
        }

        // string
        return (string) $value;
    };

    // Start from: dist structure + existing values
    $final = $mergedConfig;

    // Apply user-submitted values on top (only submitted keys are changed)
    $submitted = $cast($distConfig, $incoming);

    // Validate timezone values if present.
    $allowedTimezones = array_fill_keys(DateTimeZone::listIdentifiers(), true);

    $sanitizeTimezones = function (array $arr) use (&$sanitizeTimezones, $allowedTimezones): array {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $sanitizeTimezones($v);
                continue;
            }

            if ($k === 'timezone') {
                $tz = (string) $v;
                if (!isset($allowedTimezones[$tz])) {
                    unset($arr[$k]);
                }
            }
        }
        return $arr;
    };

    $submitted = $sanitizeTimezones($submitted);
    $final = installer_overlay_config($final, $submitted);

    // -------------------------------------------------
    // Export DB-managed settings (app_settings)
    // -------------------------------------------------

    $allowList = [];
    if (!empty($distConfig['settings_manager']['override_allow_list']) && is_array($distConfig['settings_manager']['override_allow_list'])) {
        $allowList = $distConfig['settings_manager']['override_allow_list'];
    }

    // Store installer-selected DB settings so the DB install step can insert them.
    $_SESSION['installer_app_settings_rows'] = installer_build_app_settings_rows($final, $allowList);

    // Keep config.php focused on boot + security.
    // Reset DB-managed sections back to dist defaults before writing config.php.
    foreach ($allowList as $section) {
        $section = (string) $section;
        if ($section === '') continue;
        if (array_key_exists($section, $distConfig)) {
            $final[$section] = $distConfig[$section];
        }
    }

    $result = installer_write_config_from_dist($final);

    if ($result['ok']) {
        installer_flash_add('success', 'config/config.php written successfully.');
    } else {
        installer_flash_add('danger', 'Unable to write config/config.php. You can copy/paste it manually below.');
        $_SESSION['config_write_fallback'] = $result['content'];
    }

    installer_redirect('index.php?tab=install');
}

// DB test
if ($action === 'db_test') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=install&page=config');
    installer_redirect($rt);
    }

    $incoming = $_POST['config'] ?? [];
    if (!is_array($incoming)) {
        $incoming = [];
    }

    $testConfig = $mergedConfig;
    if (isset($incoming['db']) && is_array($incoming['db'])) {
        $testConfig['db'] = array_merge((array) ($testConfig['db'] ?? []), $incoming['db']);
    }

    $res = installer_db_test($testConfig);

    if ($res['ok']) {
        installer_flash_add('success', 'Database connection successful.');
    } else {
        installer_flash_add('danger', 'Database connection failed: ' . $res['error']);
    }

    installer_redirect('index.php?tab=install');
}

// Create directories
if ($action === 'fix_dirs') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=install&page=filesystem');
    installer_redirect($rt);
    }

    $res = installer_create_dirs();

    if (!empty($res['created'])) {
        installer_flash_add('success', 'Created: ' . implode(', ', $res['created']));
    }

    if (!empty($res['errors'])) {
        foreach ($res['errors'] as $err) installer_flash_add('danger', $err);
    }

    if (empty($res['created']) && empty($res['errors'])) {
        installer_flash_add('success', 'All required directories already exist.');
    }

    installer_redirect('index.php?tab=install');
}

// Install base database
if ($action === 'install_db') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=install&page=database');
    installer_redirect($rt);
    }

    $config = installer_load_config_existing();
    if (empty($config)) {
        installer_flash_add('danger', 'Please write config/config.php before installing the database.');
        installer_redirect('index.php?tab=install');
    }

    $pdo = installer_pdo_from_config($config);
    if (!$pdo) {
        installer_flash_add('danger', 'Unable to connect using config/config.php database settings.');
        installer_redirect('index.php?tab=install');
    }

    $res = installer_apply_sql_file($pdo, INSTALL_SQL_FILE);

    if ($res['ok']) {
        installer_flash_add('success', 'Base database installed successfully.');

        // -------------------------------------------------
        // Seed app_settings with installer-selected defaults
        // -------------------------------------------------

        $distConfig = installer_load_config_dist();
        $allowList = [];
        if (!empty($distConfig['settings_manager']['override_allow_list']) && is_array($distConfig['settings_manager']['override_allow_list'])) {
            $allowList = $distConfig['settings_manager']['override_allow_list'];
        }

        $rows = $_SESSION['installer_app_settings_rows'] ?? installer_build_app_settings_rows($distConfig, $allowList);
        $seed = installer_insert_app_settings($pdo, $rows);

        if ($seed['ok']) {
            installer_flash_add('success', 'app_settings seeded successfully.');
        } else {
            installer_flash_add('warning', 'app_settings seed failed: ' . $seed['error']);
        }

    } else {
        installer_flash_add('danger', 'Database install failed: ' . $res['error']);
    }

    installer_redirect('index.php?tab=install');
}

// Apply updates
if ($action === 'apply_updates') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=update&page=database');
    installer_redirect($rt);
    }

    $config = installer_load_config_existing();
    if (empty($config)) {
        installer_flash_add('danger', 'Missing config/config.php. Run the installer first.');
        installer_redirect('index.php?tab=update');
    }

    $pdo = installer_pdo_from_config($config);
    if (!$pdo) {
        installer_flash_add('danger', 'Unable to connect using config/config.php database settings.');
        installer_redirect('index.php?tab=update');
    }

    $applied = installer_db_get_applied_updates($pdo);
    $updates = installer_list_update_files();

    $appliedCount = 0;
    foreach ($updates as $u) {
        if (!empty($applied[$u['filename']])) {
            continue;
        }

        $res = installer_apply_sql_file($pdo, $u['path']);

        if (!$res['ok']) {
            installer_flash_add('danger', 'Update failed (' . $u['filename'] . '): ' . $res['error']);
            installer_redirect('index.php?tab=update');
        }

        installer_db_mark_update_applied($pdo, $u['filename']);
        $appliedCount++;
    }

    if ($appliedCount > 0) {
        installer_flash_add('success', 'Applied ' . $appliedCount . ' update(s).');
    } else {
        installer_flash_add('success', 'No pending updates.');
    }

    installer_redirect('index.php?tab=update');
}

// Merge config from dist
if ($action === 'merge_config') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=update&page=config');
        installer_redirect($rt);
    }

    $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=update&page=config');

    $dist = installer_load_config_dist();
    if (empty($dist)) {
        installer_flash_add('danger', 'Missing config/config.php.dist.');
        installer_redirect($rt);
    }

    $existing = installer_load_config_existing();
    if (empty($existing)) {
        installer_flash_add('danger', 'Missing config/config.php. Run the installer first.');
        installer_redirect($rt);
    }

    // Determine what will be added before attempting to write.
    $missingKeys = installer_collect_missing_config_keys($dist, $existing);

    $_SESSION['config_merge_last_time'] = date('Y-m-d H:i:s');
    $_SESSION['config_merge_last_missing'] = $missingKeys;

    if (empty($missingKeys)) {
        installer_flash_add('info', 'No new configuration keys were found to merge. Your config is already up to date.');
        installer_redirect($rt);
    }

    $final = installer_merge_config($dist, $existing);

    $result = installer_write_config_from_dist($final);

    if ($result['ok']) {
        installer_flash_add('success', 'Config merged successfully. Added ' . count($missingKeys) . ' new key(s) from config.php.dist.');
    } else {
        installer_flash_add('danger', 'Unable to write config/config.php. Copy/paste it manually below.');
        $_SESSION['config_write_fallback'] = $result['content'];
    }

    installer_redirect($rt);
}


// -------------------------------------------------
// Render helpers
// -------------------------------------------------

function installer_render_header(string $title = 'Installer'): void
{
    $siteName = 'PHP Image Board Installer';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo InstallerSecurity::e($title); ?> - <?php echo InstallerSecurity::e($siteName); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo InstallerSecurity::e(INSTALLER_CSS); ?>">
</head>
<body class="installer-page">
    <header>
        <div class="logo"><?php echo InstallerSecurity::e($siteName); ?></div>

        <nav>
            <a class="nav-icon" href="/gallery" aria-label="Back to Gallery" data-tooltip="Back to Gallery">
                <i class="fa-regular fa-image"></i>
            </a>

            <a class="nav-icon" href="index.php?logout=1" aria-label="Logout" data-tooltip="Logout">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </nav>
    </header>
<?php
}

/**
 * Build app_settings rows from a config array.
 *
 * Only sections listed in settings_manager.override_allow_list are exported.
 * Keys are stored using dot-notation (e.g. "gallery.images_displayed").
 *
 * Security note:
 * - This table must NOT store secrets (DB passwords, app keys, etc.)
 */
function installer_build_app_settings_rows(array $config, array $allowList): array
{
    $rows = [];

    $detectType = function ($value): string {
        if (is_bool($value))  return 'bool';
        if (is_int($value))   return 'int';
        if (is_float($value)) return 'float';
        if (is_array($value)) return 'json';
        return 'string';
    };

    $encodeValue = function ($value, string $type): string {
        if ($type === 'bool') {
            return ($value ? '1' : '0');
        }

        if ($type === 'json') {
            // Keep JSON stable and predictable for caching + comparison.
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    };

    $flatten = function (array $arr, string $prefix = '') use (&$flatten, &$rows, $detectType, $encodeValue): void {
        foreach ($arr as $k => $v) {
            $key = ($prefix === '') ? (string) $k : ($prefix . '.' . (string) $k);

            if (is_array($v)) {
                // If this is a list (numeric keys), store the entire list as JSON.
                $isList = array_keys($v) === range(0, count($v) - 1);
                if ($isList) {
                    $type = 'json';
                    $rows[] = [
                        'key'   => $key,
                        'value' => $encodeValue($v, $type),
                        'type'  => $type,
                    ];
                    continue;
                }

                $flatten($v, $key);
                continue;
            }

            $type = $detectType($v);
            $rows[] = [
                'key'   => $key,
                'value' => $encodeValue($v, $type),
                'type'  => $type,
            ];
        }
    };

    foreach ($allowList as $section) {
        $section = (string) $section;
        if ($section === '') continue;
        if (!isset($config[$section]) || !is_array($config[$section])) continue;
        $flatten($config[$section], $section);
    }

    return $rows;
}

/**
 * Insert or update app_settings rows.
 */
function installer_insert_app_settings(PDO $pdo, array $rows): array
{
    if (empty($rows)) {
        return ['ok' => true, 'error' => ''];
    }

    $sql = "INSERT INTO app_settings (`key`, `value`, `type`) VALUES (:k, :v, :t)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)";

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);

        foreach ($rows as $row) {
            $k = (string) ($row['key'] ?? '');
            $v = (string) ($row['value'] ?? '');
            $t = (string) ($row['type'] ?? 'string');

            if ($k === '') continue;

            $stmt->execute([
                ':k' => $k,
                ':v' => $v,
                ':t' => $t,
            ]);
        }

        $pdo->commit();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}


function installer_render_footer(): void
{
    ?>
</body>
</html>
<?php
}

function installer_render_flash(): void
{
    $flash = installer_flash_get_all();
    foreach ($flash as $msg) {
        $type = $msg['type'] ?? 'info';
        $class = 'alert alert-info';

        if ($type === 'success') $class = 'alert alert-success';
        if ($type === 'danger') $class = 'alert alert-danger';
        if ($type === 'warning') $class = 'alert alert-warning';

        echo '<div class="' . InstallerSecurity::e($class) . '">';
        echo InstallerSecurity::e((string) ($msg['message'] ?? ''));
        echo '</div>';
    }
}

/**
 * Render a nested config form.
 *
 * This is used by the installer "Configuration" page, and is structured
 * to keep sections visually grouped inside cards (rather than one long list).
 */
function installer_render_config_form_fields(array $config, array $dist, array $path = [], int $depth = 0): void
{
    foreach ($dist as $key => $default) {
        $current = $config[$key] ?? $default;
        $newPath = array_merge($path, [$key]);

        if (is_array($default)) {
            $title = ucwords(str_replace('_', ' ', (string) $key));
            echo '<h3 class="installer-config-subheading">' . InstallerSecurity::e($title) . '</h3>';
            echo '<div class="installer-config-subgroup">';
            installer_render_config_form_fields((is_array($current) ? $current : []), $default, $newPath, $depth + 1);
            echo '</div>';
            continue;
        }

        $name = 'config';
        foreach ($newPath as $p) {
            $name .= '[' . $p . ']';
        }

        $label = ucwords(str_replace('_', ' ', (string) $key));

        $type = 'text';
        if (is_int($default) || is_float($default)) $type = 'number';
        if (is_bool($default)) $type = 'checkbox';

        // Hide DB password by default.
        $isPassword = (count($newPath) >= 2 && $newPath[count($newPath) - 2] === 'db' && $key === 'pass');

        echo '<div class="installer-field">';
        echo '<label class="installer-label"><b>' . InstallerSecurity::e($label) . '</b></label>';

        // Timezone dropdown (prevents typos and enforces valid identifiers).
        $isTimezone = ($key === 'timezone' && (is_string($default) || $default === null));

        if ($type === 'checkbox') {
            $checked = ($current === true) ? 'checked' : '';
            echo '<label class="installer-checkbox">';
            echo '<input type="checkbox" name="' . InstallerSecurity::e($name) . '" value="1" ' . $checked . '>';
            echo '<span>Enabled</span>';
            echo '</label>';
        } elseif ($isTimezone) {
            $value = is_string($current) ? $current : (is_string($default) ? $default : 'UTC');
            $all = DateTimeZone::listIdentifiers();
            $groups = [];

            foreach ($all as $tz) {
                $parts = explode('/', $tz, 2);
                $region = $parts[0] ?? 'Other';
                if (!isset($groups[$region])) $groups[$region] = [];
                $groups[$region][] = $tz;
            }

            ksort($groups);

            echo '<select class="installer-input" name="' . InstallerSecurity::e($name) . '">';
            foreach ($groups as $region => $items) {
                echo '<optgroup label="' . InstallerSecurity::e($region) . '">';
                foreach ($items as $tz) {
                    $selected = ($tz === $value) ? 'selected' : '';
                    echo '<option value="' . InstallerSecurity::e($tz) . '" ' . $selected . '>' . InstallerSecurity::e($tz) . '</option>';
                }
                echo '</optgroup>';
            }
            echo '</select>';
        } else {
            $value = is_string($current) || is_numeric($current) ? (string) $current : '';
            echo '<input class="installer-input" type="' . ($isPassword ? 'password' : $type) . '" name="' . InstallerSecurity::e($name) . '" value="' . InstallerSecurity::e($value) . '">';
        }

        echo '</div>';
    }
}

function installer_render_config_cards(array $config, array $dist): void
{
    echo '<div class="installer-config-grid">';

    foreach ($dist as $sectionKey => $sectionDefault) {
        $sectionTitle = ucwords(str_replace('_', ' ', (string) $sectionKey));
        $sectionCurrent = $config[$sectionKey] ?? $sectionDefault;

        echo '<section class="installer-card installer-card--padded">';
        echo '<h2 class="installer-card-title">' . InstallerSecurity::e($sectionTitle) . '</h2>';

        if (is_array($sectionDefault)) {
            installer_render_config_form_fields((is_array($sectionCurrent) ? $sectionCurrent : []), $sectionDefault, [$sectionKey], 0);
        } else {
            // Edge case: top-level scalar.
            installer_render_config_form_fields([$sectionKey => $sectionCurrent], [$sectionKey => $sectionDefault], [], 0);
        }

        echo '</section>';
    }

    echo '</div>';
}

function installer_render_security_reminder(): void
{
    echo '<div class="alert alert-warning installer-security-reminder">';
    echo '<b>Security Reminder:</b> After setup, remove or restrict access to the <b>/install</b> directory.';
    echo '</div>';
}

// -------------------------------------------------
// Page rendering
// -------------------------------------------------

if (!installer_is_logged_in()) {
    installer_render_header('Login');

    echo '<main>';

    installer_render_flash();

    // First-run setup
    if (!$authConfig) {
        echo '<h1>Installer Setup</h1>';
        echo '<p>This installer requires its own login. Set a strong username/password below.</p>';

        $fallback = $_SESSION['auth_setup_fallback'] ?? null;
        unset($_SESSION['auth_setup_fallback']);

        if (is_array($fallback) && !empty($fallback['content'])) {
            echo '<div class="alert alert-danger">Unable to write the credentials file automatically. Copy/paste the file below to: <b>' . InstallerSecurity::e((string) ($fallback['path'] ?? INSTALLER_AUTH_FILE)) . '</b></div>';
            echo '<textarea style="width:100%; height: 240px;">' . InstallerSecurity::e((string) $fallback['content']) . '</textarea>';
        }

        echo '<form method="post" style="margin-top: 14px;">';
        echo '<input type="hidden" name="action" value="auth_setup">';
        echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';

        echo '<div style="margin: 10px 0;">';
        echo '<label style="display:block; margin-bottom: 6px;"><b>Username</b></label>';
        echo '<input class="installer-input" type="text" name="username" autocomplete="username">';
        echo '</div>';

        echo '<div style="margin: 10px 0;">';
        echo '<label style="display:block; margin-bottom: 6px;"><b>Password</b> (min 12 chars)</label>';
        echo '<input class="installer-input" type="password" name="password" autocomplete="new-password">';
        echo '</div>';

        echo '<div style="margin: 10px 0;">';
        echo '<label style="display:block; margin-bottom: 6px;"><b>Confirm Password</b></label>';
        echo '<input class="installer-input" type="password" name="password_confirm" autocomplete="new-password">';
        echo '</div>';

        echo '<button type="submit" style="margin-top: 10px;">Save Installer Credentials</button>';
        echo '</form>';

        echo '</main>';
        installer_render_footer();
        exit;
    }

    // Login form
    echo '<h1>Installer Login</h1>';

    echo '<form method="post" style="margin-top: 14px;">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';

    echo '<div style="margin: 10px 0;">';
    echo '<label style="display:block; margin-bottom: 6px;"><b>Username</b></label>';
        echo '<input class="installer-input" type="text" name="username" autocomplete="username">';
    echo '</div>';

    echo '<div style="margin: 10px 0;">';
    echo '<label style="display:block; margin-bottom: 6px;"><b>Password</b></label>';
        echo '<input class="installer-input" type="password" name="password" autocomplete="current-password">';
    echo '</div>';

    echo '<button type="submit" style="margin-top: 10px;">Login</button>';
    echo '</form>';

    echo '</main>';
    installer_render_footer();
    exit;
}

// Logged in UI
installer_render_header('Installer');

echo '<main class="installer-authenticated">';

installer_render_flash();

// Primary navigation (Installer / Updater)
echo '<div class="installer-topnav">';
echo '<a class="installer-topnav__item' . ($tab === 'install' ? ' is-active' : '') . '" href="index.php?tab=install&page=overview"><i class="fa-solid fa-screwdriver-wrench"></i> Installer</a>';
echo '<a class="installer-topnav__item' . ($tab === 'update' ? ' is-active' : '') . '" href="index.php?tab=update&page=overview"><i class="fa-solid fa-rotate"></i> Updater</a>';
echo '</div>';

// Secondary navigation (per section)
$subNav = [];

if ($tab === 'update') {
    $subNav = [
        'overview'  => ['Updater Overview', 'fa-solid fa-circle-info'],
        'config'    => ['Config Merge', 'fa-solid fa-code-merge'],
        'database'  => ['Database Updates', 'fa-solid fa-database'],
    ];
} else {
    $subNav = [
        'overview'     => ['Installer Overview', 'fa-solid fa-circle-info'],
        'requirements' => ['Requirements', 'fa-solid fa-list-check'],
        'filesystem'   => ['Filesystem', 'fa-solid fa-folder-tree'],
        'config'       => ['Configuration', 'fa-solid fa-gear'],
        'database'     => ['Database Install', 'fa-solid fa-database'],
    ];
}

echo '<div class="installer-subnav">';
foreach ($subNav as $k => $meta) {
    $active = ($page === $k) ? ' is-active' : '';
    echo '<a class="installer-subnav__item' . $active . '" href="index.php?tab=' . InstallerSecurity::e($tab) . '&page=' . InstallerSecurity::e($k) . '">';
    echo '<i class="' . InstallerSecurity::e($meta[1]) . '"></i> ' . InstallerSecurity::e($meta[0]);
    echo '</a>';
}
echo '</div>';

installer_render_security_reminder();

// =========================================================
// UPDATER PAGES
// =========================================================
if ($tab === 'update') {
    if (!isset($subNav[$page])) {
        $page = 'overview';
    }

    if ($page === 'overview') {
        echo '<h1>Updater</h1>';
        echo '<p>Apply database updates and merge new config defaults from <b>config.php.dist</b>.</p>';

        echo '<div class="installer-card-grid">';
        echo '<div class="installer-card">';
        echo '<h2>Config Merge</h2>';
        echo '<p>Pull new settings from <b>config.php.dist</b> into <b>config.php</b> without losing your existing values.</p>';
        echo '<a class="installer-button installer-button--secondary" href="index.php?tab=update&page=config">Open Config Merge</a>';
        echo '</div>';

        echo '<div class="installer-card">';
        echo '<h2>Database Updates</h2>';
        echo '<p>Apply SQL files in <b>/install/updates</b> and track which updates have already been applied.</p>';
        echo '<a class="installer-button installer-button--secondary" href="index.php?tab=update&page=database">Open Database Updates</a>';
        echo '</div>';
        echo '</div>';

        echo '</main>';
        installer_render_footer();
        exit;
    }

    if ($page === 'config') {
        echo '<h1>Config Merge</h1>';
        echo '<p>Merges missing keys from <b>config.php.dist</b> into <b>config.php</b> (your existing values stay intact).</p>';

        if (empty($existingConfig)) {
            echo '<div class="alert alert-danger">Missing <b>config/config.php</b>. Run the installer first.</div>';
        } else {
            $missingKeys = installer_collect_missing_config_keys($distConfig, $existingConfig);

            // Show what will be merged (or that everything is already up to date).
            if (empty($missingKeys)) {
                echo '<div class="alert alert-info">No new configuration keys were found. Your config already contains all keys from <b>config.php.dist</b>.</div>';
            } else {
                echo '<div class="alert alert-warning">Missing <b>' . count($missingKeys) . '</b> key(s) that can be merged from <b>config.php.dist</b>.</div>';
                echo '<div class="installer-card installer-card--padded" style="margin-top: 12px;">';
                echo '<div class="installer-card-title">Keys to be added</div>';
                echo '<ul class="installer-key-list">';
                foreach ($missingKeys as $k) {
                    echo '<li><code>' . InstallerSecurity::e($k) . '</code></li>';
                }
                echo '</ul>';
                echo '</div>';
            }

            // Last merge report (optional)
            $lastTime = $_SESSION['config_merge_last_time'] ?? '';
            $lastMissing = $_SESSION['config_merge_last_missing'] ?? null;

            if (is_string($lastTime) && $lastTime !== '' && is_array($lastMissing)) {
                echo '<div class="installer-card installer-card--padded" style="margin-top: 12px;">';
                echo '<div class="installer-card-title">Last merge attempt</div>';
                echo '<p style="margin: 6px 0 0 0;">Time: <b>' . InstallerSecurity::e($lastTime) . '</b><br>New keys detected: <b>' . count($lastMissing) . '</b></p>';
                echo '</div>';
            }

            echo '<form method="post" style="margin-top: 14px;">';
            echo '<input type="hidden" name="action" value="merge_config">';
            echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
            echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=config">';
            echo '<button type="submit">Merge config.php from config.php.dist</button>';
            echo '</form>';
        }

        $fallbackCfg = $_SESSION['config_write_fallback'] ?? '';
        unset($_SESSION['config_write_fallback']);

        if (is_string($fallbackCfg) && trim($fallbackCfg) !== '') {
            echo '<h2 style="margin-top: 18px;">Manual Config Copy/Paste</h2>';
            echo '<div class="alert alert-danger">The updater could not write config/config.php. Copy/paste the content below into <b>config/config.php</b>.</div>';
            echo '<textarea class="installer-textarea-code">' . InstallerSecurity::e($fallbackCfg) . '</textarea>';
        }

        echo '</main>';
        installer_render_footer();
        exit;
    }

    // page === database
    echo '<h1>Database Updates</h1>';
    echo '<p>Applies missing SQL updates in <b>/install/updates</b>.</p>';

    $config = installer_load_config_existing();
    $pdo = $config ? installer_pdo_from_config($config) : null;

    if (!$config) {
        echo '<div class="alert alert-danger">Missing config/config.php. Run the installer first.</div>';
    } elseif (!$pdo) {
        echo '<div class="alert alert-danger">Unable to connect using database settings in config/config.php.</div>';
    } else {
        $applied = installer_db_get_applied_updates($pdo);
        $updates = installer_list_update_files();

        $pending = [];
        foreach ($updates as $u) {
            if (empty($applied[$u['filename']])) $pending[] = $u;
        }

        if (empty($updates)) {
            echo '<div class="alert alert-warning">No update files found in /install/updates.</div>';
        } elseif (empty($pending)) {
            echo '<div class="alert alert-success">No pending updates.</div>';
        } else {
            echo '<div class="alert alert-warning">Pending updates: <b>' . InstallerSecurity::e((string) count($pending)) . '</b></div>';
            echo '<ul class="installer-list">';
            foreach ($pending as $u) {
                echo '<li>' . InstallerSecurity::e($u['filename']) . '</li>';
            }
            echo '</ul>';
        }

        echo '<form method="post" style="margin-top: 10px;">';
        echo '<input type="hidden" name="action" value="apply_updates">';
        echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
        echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=database">';
        echo '<button type="submit">Apply Pending Updates</button>';
        echo '</form>';
    }

    echo '</main>';
    installer_render_footer();
    exit;
}

// =========================================================
// INSTALLER PAGES
// =========================================================

if (!isset($subNav[$page])) {
    $page = 'overview';
}

if ($page === 'overview') {
    echo '<h1>Installer</h1>';
    echo '<p>Use the steps below to set up the site. Each step has its own page to keep things clean and easy to follow.</p>';

    echo '<div class="installer-card-grid">';

    echo '<div class="installer-card">';
    echo '<h2>Requirements</h2>';
    echo '<p>Verify PHP extensions and version requirements.</p>';
    echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=requirements">Open Requirements</a>';
    echo '</div>';

    echo '<div class="installer-card">';
    echo '<h2>Filesystem</h2>';
    echo '<p>Check folder permissions and create required directories.</p>';
    echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=filesystem">Open Filesystem</a>';
    echo '</div>';

    echo '<div class="installer-card">';
    echo '<h2>Configuration</h2>';
    echo '<p>Edit and save <b>config/config.php</b> (loaded from <b>config.php.dist</b>).</p>';
    echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=config">Open Configuration</a>';
    echo '</div>';

    echo '<div class="installer-card">';
    echo '<h2>Database Install</h2>';
    echo '<p>Install the base database schema using <b>install/base_database.sql</b>.</p>';
    echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=database">Open Database Install</a>';
    echo '</div>';

    echo '</div>';

    echo '</main>';
    installer_render_footer();
    exit;
}

if ($page === 'requirements') {
    echo '<h1>Requirements</h1>';

    $reqs = [];
    $reqs[] = ['PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>=')];
    $reqs[] = ['PDO MySQL', extension_loaded('pdo_mysql')];
    $reqs[] = ['Fileinfo', extension_loaded('fileinfo')];
    $reqs[] = ['GD', extension_loaded('gd')];
    $reqs[] = ['Imagick', (extension_loaded('imagick') && class_exists('Imagick'))];

    echo '<ul class="installer-list">';
    foreach ($reqs as $r) {
        $ok = (bool) $r[1];
        echo '<li class="' . ($ok ? 'is-ok' : 'is-bad') . '">' . ($ok ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-circle-xmark"></i> ');
        echo InstallerSecurity::e((string) $r[0]);
        echo '</li>';
    }
    echo '</ul>';

    echo '</main>';
    installer_render_footer();
    exit;
}

if ($page === 'filesystem') {
    echo '<h1>Filesystem</h1>';
    echo '<p>Checks required folders and ensures they are writable.</p>';

    $dirChecks = installer_check_dirs();

    echo '<table class="installer-table">';
    echo '<tr><th>Path</th><th>Exists</th><th>Writable</th></tr>';
    foreach ($dirChecks as $d) {
        echo '<tr>';
        echo '<td>' . InstallerSecurity::e($d['path']) . '</td>';
        echo '<td>' . ($d['exists'] ? 'Yes' : 'No') . '</td>';
        echo '<td>' . ($d['writable'] ? 'Yes' : 'No') . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    echo '<form method="post" style="margin-top: 10px;">';
    echo '<input type="hidden" name="action" value="fix_dirs">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=filesystem">';
    echo '<button type="submit">Create / Fix Required Folders</button>';
    echo '</form>';

    echo '</main>';
    installer_render_footer();
    exit;
}

if ($page === 'config') {
    echo '<h1>Configuration</h1>';
    echo '<p>Settings are loaded from <b>config/config.php.dist</b>. Save to write <b>config/config.php</b>.</p>';

    echo '<form method="post">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=config">';

    installer_render_config_cards($mergedConfig, $distConfig);

    echo '<div class="installer-button-row">';
    echo '<button type="submit" name="action" value="save_config">Save config.php</button>';
    echo '<button type="submit" name="action" value="db_test" class="installer-button--secondary">Test Database</button>';
    echo '</div>';

    echo '</form>';

    $fallbackCfg = $_SESSION['config_write_fallback'] ?? '';
    unset($_SESSION['config_write_fallback']);

    if (is_string($fallbackCfg) && trim($fallbackCfg) !== '') {
        echo '<h2 style="margin-top: 18px;">Manual Config Copy/Paste</h2>';
        echo '<div class="alert alert-danger">The installer could not write config/config.php. Copy/paste the content below into <b>config/config.php</b>.</div>';
        echo '<textarea class="installer-textarea-code">' . InstallerSecurity::e($fallbackCfg) . '</textarea>';
    }

    echo '</main>';
    installer_render_footer();
    exit;
}

// page === database
echo '<h1>Database Install</h1>';
echo '<p>Installs <b>install/base_database.sql</b> into the configured database.</p>';

echo '<form method="post">';
echo '<input type="hidden" name="action" value="install_db">';
echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=database">';
echo '<button type="submit">Install Base Database</button>';
echo '</form>';

echo '</main>';

installer_render_footer();
