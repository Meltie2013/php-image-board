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

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

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

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
{
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// -------------------------------------------------
// Paths / constants
// -------------------------------------------------

define('APP_ROOT', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
define('CONFIG_DIR', APP_ROOT . '/config');
define('CONFIG_DIST', CONFIG_DIR . '/config.php.dist');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');

define('INSTALLER_AUTH_FILE', CONFIG_DIR . '/installer_auth.php');

define('INSTALL_SQL_FILE', __DIR__ . '/base_database.sql');
define('UPDATES_DIR', APP_ROOT . '/database/updates');

define('INSTALLER_CSS', '/assets/css/installer.css');
define('INSTALLER_LOCK_FILE', __DIR__ . '/installer.lock');
define('INSTALLER_RATE_LIMIT_FILE', rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_image_board_installer_rate_limit_' . hash('sha256', APP_ROOT) . '.json');
define('INSTALLER_PACKAGE_DIR', APP_ROOT . '/storage/packages/updater');

if (is_file(APP_ROOT . '/app/Core/SettingsRegistry.php'))
{
    require_once APP_ROOT . '/app/Core/SettingsRegistry.php';
}

// -------------------------------------------------
// Minimal security helpers (standalone)
// -------------------------------------------------

/**
 * Standalone security helpers used by the installer and updater.
 */
final class InstallerSecurity
{
    /**
     * Initialize the installer CSRF token.
     * @return void
     */
    public static function initCsrf(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Get the active installer CSRF token.
     * @return string
     */
    public static function csrfToken(): string
    {
        self::initCsrf();
        return (string) $_SESSION['csrf_token'];
    }

    /**
     * Verify an installer CSRF token.
     * @param ?string $token
     * @return bool
     */
    public static function verifyCsrf(?string $token): bool
    {
        self::initCsrf();
        return is_string($token) && hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    /**
     * Hash an installer password using the best available algorithm.
     * @param string $password
     * @return string
     */
    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }

        return $hash ?: '';
    }

    /**
     * Verify password.
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Escape a string for safe HTML output.
     * @param string $value
     * @return string
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// -------------------------------------------------
// Utility helpers
// -------------------------------------------------

/**
 * Redirect to another installer page and stop execution.
 * @param string $to
 * @return void
 */
function installer_redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/**
 * Build the current installer login rate-limit key.
 *
 * @return string
 */
function installer_rate_limit_key(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', (string) $ip);
}

/**
 * Read installer login limiter state from disk.
 *
 * @return array<string, array<string, int>>
 */
function installer_rate_limit_read(): array
{
    if (!is_file(INSTALLER_RATE_LIMIT_FILE)) {
        return [];
    }

    $json = @file_get_contents(INSTALLER_RATE_LIMIT_FILE);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Persist installer login limiter state to disk.
 *
 * @param array<string, array<string, int>> $state
 * @return void
 */
function installer_rate_limit_write(array $state): void
{
    if (@file_put_contents(INSTALLER_RATE_LIMIT_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod(INSTALLER_RATE_LIMIT_FILE, 0600);
    }
}

/**
 * Determine whether the installer login form is currently rate limited.
 *
 * @return bool
 */
function installer_is_rate_limited(): bool
{
    $key = installer_rate_limit_key();
    $state = installer_rate_limit_read();
    $now = time();
    $window = 900;
    $maxAttempts = 5;
    $changed = false;

    foreach ($state as $entryKey => $entry) {
        $first = (int) ($entry['first'] ?? 0);
        if ($first < 1 || ($now - $first) > $window) {
            unset($state[$entryKey]);
            $changed = true;
        }
    }

    if ($changed) {
        installer_rate_limit_write($state);
    }

    return (int) ($state[$key]['count'] ?? 0) >= $maxAttempts;
}

/**
 * Record a failed installer login attempt.
 *
 * @return void
 */
function installer_record_failed_login(): void
{
    $key = installer_rate_limit_key();
    $state = installer_rate_limit_read();
    $now = time();

    if (!isset($state[$key]) || !is_array($state[$key])) {
        $state[$key] = [
            'count' => 0,
            'first' => $now,
        ];
    }

    if (($now - (int) ($state[$key]['first'] ?? $now)) > 900) {
        $state[$key] = [
            'count' => 0,
            'first' => $now,
        ];
    }

    $state[$key]['count'] = (int) ($state[$key]['count'] ?? 0) + 1;
    installer_rate_limit_write($state);
}

/**
 * Clear failed-login counters for the current installer client.
 *
 * @return void
 */
function installer_clear_failed_login(): void
{
    $key = installer_rate_limit_key();
    $state = installer_rate_limit_read();
    if (isset($state[$key])) {
        unset($state[$key]);
        installer_rate_limit_write($state);
    }
}

/**
 * Determine whether the installer should expose install pages.
 *
 * @return bool
 */
function installer_is_locked(): bool
{
    return is_file(CONFIG_FILE) && is_file(INSTALLER_LOCK_FILE);
}

/**
 * Persist the installer lock file.
 *
 * @return bool
 */
function installer_write_lock_file(): bool
{
    if (is_file(INSTALLER_LOCK_FILE)) {
        return true;
    }

    $content = "Installer locked on " . date('Y-m-d H:i:s') . PHP_EOL;
    $written = @file_put_contents(INSTALLER_LOCK_FILE, $content, LOCK_EX) !== false;

    if ($written) {
        @chmod(INSTALLER_LOCK_FILE, 0640);
    }

    return $written;
}

/**
 * Determine whether the current request uses POST.
 *
 * @return bool
 */
function installer_is_post_request(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

/**
 * Restrict a query-string tab value to known installer areas.
 *
 * @param mixed $tab
 * @return string
 */
function installer_normalize_tab(mixed $tab): string
{
    $tab = is_string($tab) ? trim($tab) : '';

    return in_array($tab, ['install', 'update'], true) ? $tab : 'install';
}

/**
 * Restrict a query-string page value to a safe slug-like token.
 *
 * @param mixed $page
 * @return string
 */
function installer_normalize_page(mixed $page): string
{
    $page = is_string($page) ? trim($page) : '';

    if ($page === '' || !preg_match('/^[a-z0-9_-]+$/', $page)) {
        return 'overview';
    }

    return $page;
}

/**
 * Normalize a return target to a safe local installer path.
 * @param string $candidate
 * @param string $fallback
 * @return string
 */
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


/**
 * Add one flash message to the installer session queue.
 * @param string $type
 * @param string $message
 * @return void
 */
function installer_flash_add(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear all queued installer flash messages.
 * @return array
 */
function installer_flash_get_all(): array
{
    $flash = $_SESSION['flash'] ?? [];
    $_SESSION['flash'] = [];
    return is_array($flash) ? $flash : [];
}

/**
 * Determine whether the installer session is authenticated.
 * @return bool
 */
function installer_is_logged_in(): bool
{
    return !empty($_SESSION['installer_authed']);
}

/**
 * Require an authenticated installer session before continuing.
 * @return void
 */
function installer_require_login(): void
{
    if (!installer_is_logged_in()) {
        installer_redirect('index.php');
    }
}

/**
 * Read the stored installer authentication configuration.
 * @return ?array
 */
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

/**
 * Write installer authentication credentials to disk.
 * @param string $username
 * @param string $password
 * @return array
 */
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
        if ($ok) {
            @chmod(INSTALLER_AUTH_FILE, 0640);
        } else {
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


/**
 * Store installer form values for the initial administrator account.
 *
 * Password fields are intentionally excluded so failed validation can preserve
 * the visible form values without keeping secrets in the session.
 *
 * @param array<string, mixed> $payload
 * @return void
 */
function installer_store_initial_admin_form(array $payload): void
{
    $_SESSION['initial_admin_form'] = [
        'username' => trim((string) ($payload['username'] ?? '')),
        'display_name' => trim((string) ($payload['display_name'] ?? '')),
        'email' => trim((string) ($payload['email'] ?? '')),
    ];
}

/**
 * Read the remembered initial administrator form values.
 *
 * @return array<string, string>
 */
function installer_get_initial_admin_form(): array
{
    $payload = $_SESSION['initial_admin_form'] ?? [];

    if (!is_array($payload)) {
        $payload = [];
    }

    return [
        'username' => trim((string) ($payload['username'] ?? '')),
        'display_name' => trim((string) ($payload['display_name'] ?? '')),
        'email' => trim((string) ($payload['email'] ?? '')),
    ];
}

/**
 * Clear any remembered initial administrator form values.
 *
 * @return void
 */
function installer_clear_initial_admin_form(): void
{
    unset($_SESSION['initial_admin_form']);
}

/**
 * Load the distributed configuration template array.
 * @return array
 */
function installer_load_config_dist(): array
{
    if (!is_file(CONFIG_DIST)) {
        return [];
    }

    $arr = require CONFIG_DIST;
    return is_array($arr) ? $arr : [];
}

/**
 * Load the current live configuration array when available.
 * @return array
 */
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

/**
 * Installer write config from dist.
 * @param array $finalConfig
 * @return array
 */
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

/**
 * Return the list of directories required by the application.
 * @return array
 */
function installer_required_dirs(): array
{
    return [
        APP_ROOT . '/storage/cache',
        APP_ROOT . '/storage/cache/images',
        APP_ROOT . '/storage/cache/templates',
        APP_ROOT . '/storage/packages',
        INSTALLER_PACKAGE_DIR,
        APP_ROOT . '/images',
        APP_ROOT . '/images/original',
        APP_ROOT . '/json',
    ];
}

/**
 * Ensure a protective index.html file exists in a directory.
 * @param string $dir
 * @return void
 */
function installer_ensure_index_html(string $dir): void
{
    $idx = rtrim($dir, '/') . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '', LOCK_EX);
        @chmod($idx, 0640);
    }
}

/**
 * Ensure a directory has a deny-all .htaccess file when it should never be
 * browsed directly from the web.
 *
 * @param string $dir
 * @return void
 */
function installer_ensure_deny_htaccess(string $dir): void
{
    $path = rtrim($dir, '/') . '/.htaccess';

    if (is_file($path)) {
        return;
    }

    $content = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    if (@file_put_contents($path, $content, LOCK_EX) !== false) {
        @chmod($path, 0640);
    }
}

/**
 * Apply minimal hardening markers to a runtime directory.
 *
 * @param string $dir
 * @return void
 */
function installer_harden_runtime_dir(string $dir): void
{
    installer_ensure_index_html($dir);

    if (in_array($dir, [APP_ROOT . '/storage/packages', INSTALLER_PACKAGE_DIR], true)) {
        installer_ensure_deny_htaccess($dir);
    }
}

/**
 * Inspect required directories for existence and writability.
 * @return array
 */
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

    return $results;
}

/**
 * Create any missing runtime directories and apply hardening markers.
 * @return array
 */
function installer_create_dirs(): array
{
    $created = [];
    $errors = [];

    foreach (installer_required_dirs() as $dir) {
        if (!is_dir($dir)) {
            $ok = @mkdir($dir, 0755, true);
            if ($ok) {
                $created[] = $dir;
                installer_harden_runtime_dir($dir);
            } else {
                $errors[] = 'Unable to create directory: ' . $dir;
            }
        } else {
            installer_harden_runtime_dir($dir);
        }
    }

    // Ensure cache subfolders also have an index
    installer_harden_runtime_dir(APP_ROOT . '/storage/cache');
    installer_harden_runtime_dir(APP_ROOT . '/storage/cache/images');
    installer_harden_runtime_dir(APP_ROOT . '/storage/cache/templates');
    installer_harden_runtime_dir(APP_ROOT . '/storage/packages');
    installer_harden_runtime_dir(INSTALLER_PACKAGE_DIR);
    installer_harden_runtime_dir(APP_ROOT . '/json');

    return ['created' => $created, 'errors' => $errors];
}

/**
 * Collect installer requirement checks.
 *
 * Required checks block the install sequence when they fail.
 * Optional checks are informational only.
 *
 * @return array<int, array<string, mixed>>
 */
function installer_collect_requirements(): array
{
    return [
        [
            'label' => 'PHP 8.1+',
            'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'required' => true,
            'detail' => 'Current version: ' . PHP_VERSION,
        ],
        [
            'label' => 'PDO MySQL',
            'ok' => extension_loaded('pdo_mysql'),
            'required' => true,
            'detail' => 'Required for database installation and updater access.',
        ],
        [
            'label' => 'Fileinfo',
            'ok' => extension_loaded('fileinfo'),
            'required' => true,
            'detail' => 'Required for upload MIME detection.',
        ],
        [
            'label' => 'GD',
            'ok' => extension_loaded('gd'),
            'required' => true,
            'detail' => 'Required for image processing features.',
        ],
        [
            'label' => 'Imagick',
            'ok' => (extension_loaded('imagick') && class_exists('Imagick')),
            'required' => false,
            'detail' => 'Optional but recommended for stronger image tooling.',
        ],
    ];
}

/**
 * Determine whether the installer requirements screen has passed.
 * @return bool
 */
function installer_requirements_passed(): bool
{
    foreach (installer_collect_requirements() as $requirement) {
        if (!empty($requirement['required']) && empty($requirement['ok'])) {
            return false;
        }
    }

    return true;
}

/**
 * Determine whether config.php exists and contains enough DB data to continue.
 */
function installer_config_ready(): bool
{
    $config = installer_load_config_existing();
    if (empty($config['db']) || !is_array($config['db'])) {
        return false;
    }

    $db = $config['db'];

    return trim((string) ($db['host'] ?? '')) !== ''
        && trim((string) ($db['dbname'] ?? '')) !== ''
        && trim((string) ($db['user'] ?? '')) !== '';
}

/**
 * Determine whether the base schema appears to be installed.
 */
function installer_database_ready(): bool
{
    $config = installer_load_config_existing();
    if (empty($config)) {
        return false;
    }

    $pdo = installer_pdo_from_config($config);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'app_groups'");
        $row = $stmt ? $stmt->fetch() : false;
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Collect the current installer step state.
 *
 * @return array<string, array<string, mixed>>
 */
function installer_get_install_state(): array
{
    $dirChecks = installer_check_dirs();
    $filesystemReady = true;

    foreach ($dirChecks as $check) {
        if (empty($check['exists']) || empty($check['writable'])) {
            $filesystemReady = false;
            break;
        }
    }

    return [
        'requirements' => [
            'label' => 'Requirements',
            'ok' => installer_requirements_passed(),
            'detail' => 'Validate PHP version and required extensions.',
        ],
        'filesystem' => [
            'label' => 'Filesystem',
            'ok' => $filesystemReady,
            'detail' => 'Create and verify writable runtime directories.',
        ],
        'config' => [
            'label' => 'Configuration',
            'ok' => installer_config_ready(),
            'detail' => 'Write config/config.php with valid database settings.',
        ],
        'database' => [
            'label' => 'Database Install',
            'ok' => installer_database_ready(),
            'detail' => 'Install the base schema into the configured database.',
        ],
        'lock' => [
            'label' => 'Installer Lock',
            'ok' => is_file(INSTALLER_LOCK_FILE),
            'detail' => 'Lock first-run install pages after the base install is complete.',
        ],
    ];
}

/**
 * @return string[]
 */
function installer_install_step_order(): array
{
    return ['requirements', 'filesystem', 'config', 'database'];
}

/**
 * Determine the next incomplete installer step.
 */
function installer_next_install_step(array $state): string
{
    foreach (installer_install_step_order() as $step) {
        if (empty($state[$step]['ok'])) {
            return $step;
        }
    }

    return 'overview';
}

/**
 * Enforce installer step order.
 */
function installer_resolve_install_page(string $page, array $state): string
{
    if ($page === 'overview') {
        return $page;
    }

    $requestedIndex = array_search($page, installer_install_step_order(), true);
    if ($requestedIndex === false) {
        return 'overview';
    }

    foreach (installer_install_step_order() as $index => $step) {
        if ($index >= $requestedIndex) {
            break;
        }

        if (empty($state[$step]['ok'])) {
            return $step;
        }
    }

    return $page;
}

/**
 * Validate whether a filename is an allowed updater package archive.
 */
function installer_is_allowed_package_name(string $filename): bool
{
    $filename = strtolower(trim($filename));
    if ($filename === '') {
        return false;
    }

    $allowed = [
        '.zip',
        '.tar',
        '.tar.gz',
        '.tgz',
    ];

    foreach ($allowed as $extension) {
        if (str_ends_with($filename, $extension)) {
            return true;
        }
    }

    return false;
}

/**
 * Validate the detected MIME type for an updater package upload.
 * @param string $filename
 * @param string $tmpPath
 * @return bool
 */
function installer_is_allowed_package_mime(string $filename, string $tmpPath): bool
{
    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $allowedByExtension = [
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'tar' => ['application/x-tar'],
        'gz'  => ['application/gzip', 'application/x-gzip'],
        'tgz' => ['application/gzip', 'application/x-gzip'],
    ];

    if (!isset($allowedByExtension[$extension]) || !is_file($tmpPath)) {
        return false;
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = @finfo_file($finfo, $tmpPath);
            if (is_string($detected)) {
                $mimeType = strtolower(trim($detected));
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType === '') {
        return true;
    }

    return in_array($mimeType, $allowedByExtension[$extension], true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function installer_list_package_files(): array
{
    if (!is_dir(INSTALLER_PACKAGE_DIR)) {
        return [];
    }

    $paths = glob(INSTALLER_PACKAGE_DIR . '/*') ?: [];
    usort($paths, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });

    $files = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $filename = basename($path);
        if (in_array($filename, ['index.html', '.htaccess'], true) || !installer_is_allowed_package_name($filename)) {
            continue;
        }

        $files[] = [
            'filename' => $filename,
            'size_bytes' => (int) filesize($path),
            'modified_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
        ];
    }

    return $files;
}

/**
 * Stage an uploaded updater package on disk.
 */
function installer_store_uploaded_package(array $upload): array
{
    if (!is_dir(INSTALLER_PACKAGE_DIR)) {
        $created = installer_create_dirs();
        if (!empty($created['errors']) || !is_dir(INSTALLER_PACKAGE_DIR)) {
            return ['ok' => false, 'error' => 'Updater package storage directory could not be created.'];
        }
    }

    installer_harden_runtime_dir(APP_ROOT . '/storage/packages');
    installer_harden_runtime_dir(INSTALLER_PACKAGE_DIR);

    $name = (string) ($upload['name'] ?? '');
    $tmp = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);

    if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'No valid package upload was received.'];
    }

    if (!installer_is_allowed_package_name($name)) {
        return ['ok' => false, 'error' => 'Allowed package formats are zip, tar, tar.gz, and tgz.'];
    }

    if (!installer_is_allowed_package_mime($name, $tmp)) {
        return ['ok' => false, 'error' => 'The uploaded package type did not match the expected archive format.'];
    }

    if ($size < 1) {
        return ['ok' => false, 'error' => 'Uploaded package is empty.'];
    }

    if ($size > (25 * 1024 * 1024)) {
        return ['ok' => false, 'error' => 'Uploaded package exceeds the 25 MB staging limit.'];
    }

    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name));
    $clean = trim((string) $clean, '-');
    if ($clean === '') {
        $clean = 'update-package';
    }

    $target = INSTALLER_PACKAGE_DIR . '/' . date('Ymd_His') . '__' . $clean;

    if (!@move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'error' => 'Failed to move the uploaded package into staging.'];
    }

    @chmod($target, 0640);

    return ['ok' => true, 'error' => '', 'filename' => basename($target)];
}

/**
 * Format bytes into a small human-readable string.
 */
function installer_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, $bytes);
    $unit = 0;

    while ($value >= 1024 && $unit < (count($units) - 1)) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, ($unit === 0 ? 0 : 2)) . ' ' . $units[$unit];
}
/**
 * Create a PDO connection from installer database settings.
 * @param array $config
 * @return ?PDO
 */
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

/**
 * Test the installer database connection and required privileges.
 * @param array $config
 * @return array
 */
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


/**
 * Read and sanitize the initial administrator account payload.
 *
 * @return array<string, string>
 */
function installer_read_initial_admin_payload(): array
{
    return [
        'username' => trim((string) ($_POST['admin_username'] ?? '')),
        'display_name' => trim((string) ($_POST['admin_display_name'] ?? '')),
        'email' => trim((string) ($_POST['admin_email'] ?? '')),
        'password' => (string) ($_POST['admin_password'] ?? ''),
        'password_confirm' => (string) ($_POST['admin_password_confirm'] ?? ''),
    ];
}

/**
 * Validate the initial administrator account payload before the base schema is
 * installed so the installer can stop early when required fields are missing.
 *
 * @param array<string, string> $payload
 * @return array{ok: bool, errors: array<int, string>}
 */
function installer_validate_initial_admin_payload(array $payload): array
{
    $errors = [];

    if ($payload['username'] === '') {
        $errors[] = 'Initial administrator username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,15}$/', $payload['username'])) {
        $errors[] = 'Initial administrator username must be 3-15 characters long and use only letters, numbers, or underscores.';
    }

    if ($payload['display_name'] !== '' && strlen($payload['display_name']) > 100) {
        $errors[] = 'Initial administrator display name must be 100 characters or fewer.';
    }

    if ($payload['email'] === '') {
        $errors[] = 'Initial administrator email is required.';
    } elseif (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Initial administrator email format is invalid.';
    }

    if ($payload['password'] === '') {
        $errors[] = 'Initial administrator password is required.';
    }

    if ($payload['password'] !== $payload['password_confirm']) {
        $errors[] = 'Initial administrator password confirmation does not match.';
    }

    if (strlen($payload['password']) < 12) {
        $errors[] = 'Initial administrator password must be at least 12 characters.';
    }

    if (strlen($payload['password']) > 4096) {
        $errors[] = 'Initial administrator password is too long.';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * Determine whether the application already has at least one administrator.
 *
 * @param PDO $pdo
 * @return bool
 */
function installer_has_administrator_account(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query(
            "SELECT u.id
             FROM app_users u
             INNER JOIN app_groups g ON g.id = u.group_id
             WHERE g.slug IN ('site-administrator', 'administrator')
               AND u.status != 'deleted'
             LIMIT 1"
        );

        $row = $stmt ? $stmt->fetch() : false;
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check whether the submitted administrator username or email is already in use.
 *
 * @param PDO $pdo
 * @param string $username
 * @param string $email
 * @return bool
 */
function installer_initial_admin_username_or_email_exists(PDO $pdo, string $username, string $email): bool
{
    $stmt = $pdo->prepare(
        "SELECT id
         FROM app_users
         WHERE username = :username
            OR email = :email
         LIMIT 1"
    );

    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
    ]);

    return (bool) $stmt->fetch();
}

/**
 * Find the strongest built-in administrative group available for first-run
 * account creation.
 *
 * @param PDO $pdo
 * @return int
 */
function installer_find_initial_admin_group_id(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT id
         FROM app_groups
         WHERE slug IN ('site-administrator', 'administrator')
         ORDER BY FIELD(slug, 'site-administrator', 'administrator')
         LIMIT 1"
    );

    $groupId = (int) ($stmt ? $stmt->fetchColumn() : 0);
    return ($groupId > 0) ? $groupId : 1;
}

/**
 * Create the first administrator account for the board.
 *
 * @param PDO $pdo
 * @param array<string, string> $payload
 * @return array{ok: bool, error: string}
 */
function installer_create_initial_admin(PDO $pdo, array $payload): array
{
    try {
        if (installer_initial_admin_username_or_email_exists($pdo, $payload['username'], $payload['email'])) {
            return ['ok' => false, 'error' => 'That administrator username or email address is already in use.'];
        }

        $groupId = installer_find_initial_admin_group_id($pdo);
        $displayName = ($payload['display_name'] !== '') ? $payload['display_name'] : null;
        $passwordHash = InstallerSecurity::hashPassword($payload['password']);

        if ($passwordHash === '') {
            return ['ok' => false, 'error' => 'Unable to hash the administrator password.'];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO app_users (group_id, username, display_name, email, password_hash, status, created_at, updated_at)
             VALUES (:group_id, :username, :display_name, :email, :password_hash, 'active', NOW(), NOW())"
        );

        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue(':username', $payload['username'], PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, ($displayName === null) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':email', $payload['email'], PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->execute();

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Ensure the updater bookkeeping table exists.
 * @param PDO $pdo
 * @return void
 */
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

/**
 * Fetch the set of update files already applied to the database.
 * @param PDO $pdo
 * @return array
 */
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

/**
 * Record one applied database update file.
 * @param PDO $pdo
 * @param string $filename
 * @return void
 */
function installer_db_mark_update_applied(PDO $pdo, string $filename): void
{
    installer_db_ensure_updates_table($pdo);

    $stmt = $pdo->prepare("INSERT IGNORE INTO app_updates (filename, applied_at) VALUES (:f, NOW())");
    $stmt->execute([':f' => $filename]);
}

/**
 * Determine whether a SQL file should manage its own transaction boundaries.
 *
 * Files that contain explicit transaction control or DDL statements should not
 * be wrapped in an outer PDO transaction because MySQL can implicitly commit
 * those statements.
 *
 * @param string $sql
 * @return bool
 */
function installer_sql_file_manages_transactions(string $sql): bool
{
    if ($sql === '') {
        return false;
    }

    if (preg_match('/\b(START\s+TRANSACTION|BEGIN\s*(?:;|$)|COMMIT\s*;|ROLLBACK\s*;)\b/i', $sql)) {
        return true;
    }

    return (bool) preg_match('/\b(CREATE|ALTER|DROP|RENAME|TRUNCATE)\s+(?:TABLE|DATABASE|INDEX|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', $sql);
}

/**
 * Apply a SQL update file through the installer database connection.
 * @param PDO $pdo
 * @param string $sqlFilePath
 * @return array
 */
function installer_apply_sql_file(PDO $pdo, string $sqlFilePath): array
{
    if (!is_file($sqlFilePath)) {
        return ['ok' => false, 'error' => 'SQL file not found: ' . $sqlFilePath];
    }

    $sql = (string) file_get_contents($sqlFilePath);
    if (trim($sql) === '') {
        return ['ok' => true, 'error' => ''];
    }

    $manageTransactionsInFile = installer_sql_file_manages_transactions($sql);

    try {
        if ($manageTransactionsInFile) {
            $pdo->exec($sql);
        } else {
            $pdo->beginTransaction();
            $pdo->exec($sql);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        }

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * List available SQL update files in execution order.
 * @return array
 */
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

$action = is_string($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
$tab = installer_normalize_tab($_GET['tab'] ?? 'install');
$page = installer_normalize_page($_GET['page'] ?? 'overview');
$lockedInstaller = installer_is_locked();

if ($action !== '' && !installer_is_post_request()) {
    installer_flash_add('danger', 'Invalid request method.');
    installer_redirect('index.php');
}

if ($lockedInstaller && $tab !== 'update' && $action !== 'logout' && !in_array($action, ['login', 'apply_updates', 'merge_config'], true)) {
    installer_redirect('index.php?tab=update');
}

// Logout
if ($action === 'logout') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        installer_redirect('index.php');
    }

    $_SESSION = [];
    session_regenerate_id(true);
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

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    $errors = [];

    if ($username === '') $errors[] = 'Username is required.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) $errors[] = 'Username must be 3-64 characters and use only letters, numbers, dots, underscores, or dashes.';
    if ($password === '') $errors[] = 'Password is required.';
    if ($password !== $password2) $errors[] = 'Password confirmation does not match.';
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters.';
    if (strlen($password) > 4096) $errors[] = 'Password is too long.';

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

    if (installer_is_rate_limited()) {
        installer_flash_add('danger', 'Too many login attempts. Please wait and try again.');
        installer_redirect('index.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $authConfig = installer_read_auth_config();

    if (!$authConfig) {
        installer_flash_add('danger', 'Installer credentials are not configured yet.');
        installer_redirect('index.php');
    }

    if ($username === '' || strlen($username) > 64 || strlen($password) > 4096) {
        installer_record_failed_login();
        installer_flash_add('danger', 'Invalid credentials.');
        installer_redirect('index.php');
    }

    if (!hash_equals((string) $authConfig['username'], $username)) {
        installer_record_failed_login();
        installer_flash_add('danger', 'Invalid credentials.');
        installer_redirect('index.php');
    }

    if (!InstallerSecurity::verifyPassword($password, (string) $authConfig['password_hash'])) {
        installer_record_failed_login();
        installer_flash_add('danger', 'Invalid credentials.');
        installer_redirect('index.php');
    }

    installer_clear_failed_login();
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

/**
 * Top-level configuration sections required for the initial installer flow.
 *
 * @return string[]
 */
function installer_install_config_sections(): array
{
    return ['timezone', 'db'];
}

/**
 * Top-level configuration sections edited from the updater portal.
 *
 * @param array $dist
 * @return string[]
 */
function installer_updater_config_sections(array $dist): array
{
    $installSections = array_fill_keys(installer_install_config_sections(), true);
    $sections = [];

    foreach (array_keys($dist) as $key) {
        $key = (string) $key;
        if (!isset($installSections[$key])) {
            $sections[] = $key;
        }
    }

    return $sections;
}

/**
 * Top-level configuration sections that still exist only as placeholder values
 * in config.php.dist.
 *
 * The settings registry revamp removed board-facing sections such as site,
 * gallery, profile, template, debugging, and upload from config.php.dist.
 *
 * @return string[]
 */
function installer_placeholder_config_sections(): array
{
    return [];
}

/**
 * Top-level updater configuration sections that reflect the live board state.
 *
 * @param array $dist
 * @return string[]
 */
function installer_live_config_sections(array $dist): array
{
    $excluded = array_fill_keys(installer_install_config_sections(), true);

    foreach (installer_placeholder_config_sections() as $section) {
        $excluded[(string) $section] = true;
    }

    $sections = [];

    foreach (array_keys($dist) as $key) {
        $key = (string) $key;
        if (!isset($excluded[$key])) {
            $sections[] = $key;
        }
    }

    return $sections;
}

/**
 * Top-level updater configuration sections that only exist as config.php
 * placeholders when the live Control Panel settings are unavailable.
 *
 * @param array $dist
 * @return string[]
 */
function installer_placeholder_config_sections_for_dist(array $dist): array
{
    $sections = [];
    $allowed = array_fill_keys(installer_placeholder_config_sections(), true);

    foreach (array_keys($dist) as $key) {
        $key = (string) $key;
        if (isset($allowed[$key])) {
            $sections[] = $key;
        }
    }

    return $sections;
}

/**
 * Filter a list of dot-notation key paths by their top-level section.
 *
 * @param string[] $paths
 * @param string[] $sections
 * @return string[]
 */
function installer_filter_key_paths_by_sections(array $paths, array $sections): array
{
    $allowed = array_fill_keys(array_map('strval', $sections), true);
    $filtered = [];

    foreach ($paths as $path) {
        $path = (string) $path;
        if ($path === '') {
            continue;
        }

        $topLevel = explode('.', $path, 2)[0] ?? '';
        if ($topLevel !== '' && isset($allowed[$topLevel])) {
            $filtered[] = $path;
        }
    }

    return $filtered;
}

/**
 * Filter a configuration array down to the requested top-level sections.
 *
 * @param array $config
 * @param string[] $sections
 * @return array
 */
function installer_filter_config_sections(array $config, array $sections): array
{
    $filtered = [];

    foreach ($sections as $section) {
        $section = (string) $section;
        if ($section === '' || !array_key_exists($section, $config)) {
            continue;
        }

        $filtered[$section] = $config[$section];
    }

    return $filtered;
}

/**
 * Cast only the submitted config keys using the matching config.php.dist defaults.
 *
 * This avoids wiping unrelated sections when the updater edits a partial slice of
 * the full configuration tree (for example, the Live Configuration page).
 *
 * @param array $defaults
 * @param array $submitted
 * @return array
 */
function installer_cast_submitted_config(array $defaults, array $submitted): array
{
    $casted = [];

    foreach ($submitted as $key => $value) {
        if (!array_key_exists($key, $defaults)) {
            $casted[$key] = $value;
            continue;
        }

        $default = $defaults[$key];

        if (is_array($default)) {
            if (is_array($value)) {
                $casted[$key] = installer_cast_submitted_config($default, $value);
            }
            continue;
        }

        if (is_bool($default)) {
            $casted[$key] = ($value === '1' || $value === 1 || $value === true || $value === 'true');
            continue;
        }

        if (is_int($default)) {
            $casted[$key] = (int) $value;
            continue;
        }

        if (is_float($default)) {
            $casted[$key] = (float) $value;
            continue;
        }

        if ($default === null && $value === '') {
            $casted[$key] = null;
            continue;
        }

        $casted[$key] = (string) $value;
    }

    return $casted;
}

/**
 * Convert config keys into cleaner display labels.
 *
 * @param string[] $path
 * @return string
 */
function installer_humanize_config_key(array $path): string
{
    $joined = implode('.', $path);

    $map = [
        'timezone' => 'Timezone',
        'db' => 'Database',
        'db.host' => 'Database Host',
        'db.dbname' => 'Database Name',
        'db.user' => 'Database Username',
        'db.pass' => 'Database Password',
        'db.charset' => 'Database Charset',
        'site' => 'Site',
        'debugging' => 'Debugging',
        'control_server' => 'Control Server',
        'request_guard' => 'Request Guard',
        'security' => 'Security',
        'csrf_token_name' => 'CSRF Token Name',
        'password_algo' => 'Password Algorithm',
        'password_options' => 'Password Options',
        'device_policy' => 'Device Policy',
        'allowed_ips' => 'Allowed IP Addresses',
        'auth_token' => 'Auth Token',
        'public_host' => 'Public Host',
        'public_scheme' => 'Public Scheme',
        'public_path' => 'Public Path',
        'bind_address' => 'Bind Address',
        'allow_remote_clients' => 'Allow Remote Clients',
        'allow_remote_control' => 'Allow Remote Control',
        'heartbeat_file' => 'Heartbeat File',
        'state_file' => 'State File',
        'live_events_file' => 'Live Events File',
        'heartbeat_timeout_seconds' => 'Heartbeat Timeout Seconds',
        'tick_interval_seconds' => 'Tick Interval Seconds',
        'verbose_logging' => 'Verbose Logging',
        'log_retention_days' => 'Log Retention Days',
        'comments_per_page' => 'Comments Per Page',
        'images_displayed' => 'Images Displayed Per Page',
        'pagination_range' => 'Pagination Range',
        'upload_max_image_size' => 'Upload Max Image Size (MB)',
        'upload_max_storage' => 'Upload Max Storage',
        'timeout_minutes' => 'Session Timeout Minutes',
        'device_cookie_name' => 'Device Cookie Name',
        'avatar_size' => 'Avatar Size',
    ];

    if (isset($map[$joined])) {
        return $map[$joined];
    }

    $last = (string) end($path);
    if (isset($map[$last])) {
        return $map[$last];
    }

    return ucwords(str_replace('_', ' ', $last));
}

/**
 * Helper copy for common configuration fields.
 *
 * @param string[] $path
 * @return string
 */
function installer_config_field_help(array $path): string
{
    $joined = implode('.', $path);

    $map = [
        'timezone' => 'Used for logs, timestamps, and default application date handling.',
        'db.host' => 'Hostname or IP address for your MySQL / MariaDB server.',
        'db.dbname' => 'Schema name that will receive the base install and future updates.',
        'db.user' => 'Database account with permission to create tables, indexes, and update records.',
        'db.pass' => 'Leave blank only when the database user truly has no password.',
        'db.charset' => 'utf8mb4 is recommended for full Unicode and emoji support.',
    ];

    return $map[$joined] ?? '';
}

/**
 * Describe each top-level config card.
 */
function installer_config_section_description(string $sectionKey): string
{
    $map = [
        'timezone' => 'Set the default application timezone used for logs, dates, and scheduled tasks.',
        'db' => 'Connection details used to install the schema and power the main application.',
        'site' => 'Branding and version information displayed around the board.',
        'session' => 'Browser session naming, timeout handling, and stable guest-device cookies.',
        'debugging' => 'Developer-oriented flags for troubleshooting and test workflows.',
        'template' => 'Template cache behavior and any explicitly allowed helper functions.',
        'profile' => 'Profile-related defaults such as age gating and avatar presentation.',
        'gallery' => 'Gallery pagination, upload, and storage defaults for the public site.',
        'security' => 'Core CSRF, password, device, and audit policies.',
        'control_server' => 'Background server heartbeat, runtime jobs, WebSocket, and control-socket settings.',
        'request_guard' => 'Rate-limit and abuse-prevention rules for gallery, auth, and interactive actions.',
    ];

    return $map[$sectionKey] ?? '';
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

    // Start from: dist structure + existing values
    $final = $mergedConfig;

    // Apply user-submitted values on top (only submitted keys are changed)
    $submitted = installer_cast_submitted_config($distConfig, $incoming);

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

    // config.php.dist now only contains runtime / boot configuration.
    // Board-facing settings are seeded into the database-backed settings registry
    // during the database install step.

    $result = installer_write_config_from_dist($final);

    if ($result['ok']) {
        installer_flash_add('success', 'config/config.php written successfully.');
    } else {
        installer_flash_add('danger', 'Unable to write config/config.php. You can copy/paste it manually below.');
        $_SESSION['config_write_fallback'] = $result['content'];
    }

    $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=install&page=config');
    installer_redirect($rt);
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

    $nextStep = installer_next_install_step(installer_get_install_state());
    $targetPage = ($nextStep === 'overview') ? 'overview' : $nextStep;
    installer_redirect('index.php?tab=install&page=' . $targetPage);
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

    installer_redirect('index.php?tab=install&page=config');
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
        installer_redirect('index.php?tab=install&page=config');
    }

    $pdo = installer_pdo_from_config($config);
    if (!$pdo) {
        installer_flash_add('danger', 'Unable to connect using config/config.php database settings.');
        installer_redirect('index.php?tab=install&page=config');
    }

    $adminPayload = installer_read_initial_admin_payload();
    installer_store_initial_admin_form($adminPayload);

    $databaseReady = installer_database_ready();
    $adminAlreadyExists = false;

    if ($databaseReady) {
        $adminAlreadyExists = installer_has_administrator_account($pdo);
    }

    if (!$adminAlreadyExists) {
        $validation = installer_validate_initial_admin_payload($adminPayload);

        if (!$validation['ok']) {
            foreach ($validation['errors'] as $error) {
                installer_flash_add('danger', $error);
            }

            installer_redirect('index.php?tab=install&page=database');
        }
    }

    if (!$databaseReady) {
        $res = installer_apply_sql_file($pdo, INSTALL_SQL_FILE);

        if (!$res['ok']) {
            installer_flash_add('danger', 'Database install failed: ' . $res['error']);
            installer_redirect('index.php?tab=install&page=database');
        }

        installer_flash_add('success', 'Base database installed successfully.');
    } else {
        installer_flash_add('info', 'Base database already appears to be installed. Skipping schema import and continuing with final setup.');
    }

    // -------------------------------------------------
    // Seed the database-backed settings registry
    // -------------------------------------------------

    $seed = installer_seed_settings_registry($pdo);

    if (!$seed['ok']) {
        installer_flash_add('danger', 'Base schema is available, but the settings registry seed failed. The installer remains unlocked so the issue can be corrected: ' . $seed['error']);
        installer_redirect('index.php?tab=install&page=database');
    }

    installer_flash_add('success', 'Settings registry seeded successfully.');

    if (installer_has_administrator_account($pdo)) {
        installer_clear_initial_admin_form();
        installer_flash_add('info', 'An administrator account already exists. Initial administrator creation was skipped.');
    } else {
        $createAdmin = installer_create_initial_admin($pdo, $adminPayload);

        if (!$createAdmin['ok']) {
            installer_flash_add('danger', 'Initial administrator account creation failed: ' . $createAdmin['error']);
            installer_redirect('index.php?tab=install&page=database');
        }

        installer_clear_initial_admin_form();
        installer_flash_add('success', 'Initial administrator account created successfully.');
    }

    if (installer_write_lock_file()) {
        installer_flash_add('success', 'Installer locked. Use the update tab for future maintenance.');
        installer_redirect('index.php?tab=update&page=overview');
    }

    installer_flash_add('warning', 'Base install finished, but the installer lock file could not be written. Lock /install manually before going live.');
    installer_redirect('index.php?tab=install&page=database');
}

// Stage updater package
if ($action === 'upload_package') {
    if (!InstallerSecurity::verifyCsrf($_POST['csrf_token'] ?? null)) {
        installer_flash_add('danger', 'Invalid CSRF token.');
        $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=update&page=packages');
        installer_redirect($rt);
    }

    $result = installer_store_uploaded_package($_FILES['package_file'] ?? []);

    if ($result['ok']) {
        installer_flash_add('success', 'Update package staged successfully: ' . (string) ($result['filename'] ?? ''));
    } else {
        installer_flash_add('danger', 'Package upload failed: ' . (string) ($result['error'] ?? 'Unknown error.'));
    }

    $rt = installer_safe_return_to((string) ($_POST['return_to'] ?? ''), 'index.php?tab=update&page=packages');
    installer_redirect($rt);
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

/**
 * Render the shared installer page header.
 * @param string $title
 * @return void
 */
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

<?php if (installer_is_logged_in()) { ?>
            <form class="installer-nav-form" method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo InstallerSecurity::e(InstallerSecurity::csrfToken()); ?>">
                <button class="nav-icon installer-nav-button" type="submit" aria-label="Logout" data-tooltip="Logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </button>
            </form>
<?php } ?>
        </nav>
    </header>
<?php
}

/**
 * Build database-backed settings category seed rows.
 *
 * @return array
 */
function installer_build_settings_category_rows(): array
{
    if (!class_exists('SettingsRegistry')) {
        return [];
    }

    return SettingsRegistry::buildCategorySeedRows();
}

/**
 * Build database-backed settings entry seed rows.
 *
 * @return array
 */
function installer_build_settings_data_rows(): array
{
    if (!class_exists('SettingsRegistry')) {
        return [];
    }

    return SettingsRegistry::buildSettingSeedRows();
}

/**
 * Seed app_settings_categories + app_settings_data from the built-in registry.
 *
 * @param PDO $pdo
 * @return array
 */
function installer_seed_settings_registry(PDO $pdo): array
{
    $categoryRows = installer_build_settings_category_rows();
    $settingRows = installer_build_settings_data_rows();

    if (empty($categoryRows) || empty($settingRows)) {
        return ['ok' => false, 'error' => 'Settings registry metadata could not be loaded.'];
    }

    $categorySql = "INSERT INTO app_settings_categories (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
                    VALUES (:slug, :title, :description, :icon, :sort_order, :is_system)
                    ON DUPLICATE KEY UPDATE
                        `title` = VALUES(`title`),
                        `description` = VALUES(`description`),
                        `icon` = VALUES(`icon`),
                        `sort_order` = VALUES(`sort_order`),
                        `is_system` = VALUES(`is_system`)";

    $categoryIdSql = "SELECT id FROM app_settings_categories WHERE slug = :slug LIMIT 1";

    $settingSql = "INSERT INTO app_settings_data (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
                   VALUES (:category_id, :key_name, :title, :description, :value_data, :type_name, :input_type, :sort_order, :is_system)
                   ON DUPLICATE KEY UPDATE
                        `category_id` = VALUES(`category_id`),
                        `title` = VALUES(`title`),
                        `description` = VALUES(`description`),
                        `value` = VALUES(`value`),
                        `type` = VALUES(`type`),
                        `input_type` = VALUES(`input_type`),
                        `sort_order` = VALUES(`sort_order`),
                        `is_system` = VALUES(`is_system`)";

    try {
        $pdo->beginTransaction();

        $categoryStmt = $pdo->prepare($categorySql);
        $categoryLookupStmt = $pdo->prepare($categoryIdSql);
        $settingStmt = $pdo->prepare($settingSql);

        $categoryIdMap = [];

        foreach ($categoryRows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $categoryStmt->execute([
                ':slug' => $slug,
                ':title' => (string) ($row['title'] ?? $slug),
                ':description' => (string) ($row['description'] ?? ''),
                ':icon' => (string) ($row['icon'] ?? 'fa-sliders'),
                ':sort_order' => (int) ($row['sort_order'] ?? 0),
                ':is_system' => !empty($row['is_system']) ? 1 : 0,
            ]);

            $categoryLookupStmt->execute([':slug' => $slug]);
            $categoryId = (int) ($categoryLookupStmt->fetchColumn() ?: 0);
            if ($categoryId > 0) {
                $categoryIdMap[$slug] = $categoryId;
            }
        }

        foreach ($settingRows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $categorySlug = trim((string) ($row['category_slug'] ?? ''));
            if ($key === '' || $categorySlug === '' || empty($categoryIdMap[$categorySlug])) {
                continue;
            }

            $settingStmt->execute([
                ':category_id' => $categoryIdMap[$categorySlug],
                ':key_name' => $key,
                ':title' => (string) ($row['title'] ?? $key),
                ':description' => (string) ($row['description'] ?? ''),
                ':value_data' => (string) ($row['value'] ?? ''),
                ':type_name' => (string) ($row['type'] ?? 'string'),
                ':input_type' => (string) ($row['input_type'] ?? 'text'),
                ':sort_order' => (int) ($row['sort_order'] ?? 0),
                ':is_system' => !empty($row['is_system']) ? 1 : 0,
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


/**
 * Render the shared installer page footer.
 * @return void
 */
function installer_render_footer(): void
{
    ?>
</body>
</html>
<?php
}

/**
 * Render queued installer flash messages.
 * @return void
 */
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
    $openedScalarGroup = false;

    foreach ($dist as $key => $default) {
        $current = $config[$key] ?? $default;
        $newPath = array_merge($path, [$key]);

        if (is_array($default)) {
            if ($openedScalarGroup) {
                echo '</div>';
                $openedScalarGroup = false;
            }

            $title = installer_humanize_config_key($newPath);
            echo '<div class="installer-config-subsection">';
            echo '<h3 class="installer-config-subheading">' . InstallerSecurity::e($title) . '</h3>';
            echo '<div class="installer-config-subgroup">';
            installer_render_config_form_fields((is_array($current) ? $current : []), $default, $newPath, $depth + 1);
            echo '</div>';
            echo '</div>';
            continue;
        }

        if ($depth === 0 && !$openedScalarGroup) {
            echo '<div class="installer-config-subgroup installer-config-subgroup--flat">';
            $openedScalarGroup = true;
        }

        $name = 'config';
        foreach ($newPath as $p) {
            $name .= '[' . $p . ']';
        }

        $label = installer_humanize_config_key($newPath);
        $help = installer_config_field_help($newPath);

        $type = 'text';
        if (is_int($default) || is_float($default)) {
            $type = 'number';
        }
        if (is_bool($default)) {
            $type = 'checkbox';
        }

        $isPassword = (count($newPath) >= 2 && $newPath[count($newPath) - 2] === 'db' && $key === 'pass');
        $isTimezone = ($key === 'timezone' && (is_string($default) || $default === null));

        echo '<div class="installer-field">';
        echo '<label class="installer-label"><b>' . InstallerSecurity::e($label) . '</b></label>';

        if ($type === 'checkbox') {
            $checked = ($current === true) ? 'checked' : '';
            echo '<input type="hidden" name="' . InstallerSecurity::e($name) . '" value="0">';
            echo '<label class="installer-checkbox">';
            echo '<input type="checkbox" name="' . InstallerSecurity::e($name) . '" value="1" ' . $checked . '>';
            echo '<span>Enabled</span>';
            echo '</label>';
        } elseif ($isTimezone) {
            $value = is_string($current) ? $current : (is_string($default) ? $default : 'UTC');
            $groups = [];
            foreach (DateTimeZone::listIdentifiers() as $tz) {
                $parts = explode('/', $tz, 2);
                $region = $parts[0] ?? 'Other';
                if (!isset($groups[$region])) {
                    $groups[$region] = [];
                }
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

        if ($help !== '') {
            echo '<div class="installer-helper-text">' . InstallerSecurity::e($help) . '</div>';
        }

        echo '</div>';
    }

    if ($openedScalarGroup) {
        echo '</div>';
    }
}

/**
 * Render configuration fields grouped into installer cards.
 * @param array $config
 * @param array $dist
 * @return void
 */
function installer_render_config_cards(array $config, array $dist): void
{
    echo '<div class="installer-config-grid">';

    foreach ($dist as $sectionKey => $sectionDefault) {
        $sectionTitle = installer_humanize_config_key([(string) $sectionKey]);
        $sectionCurrent = $config[$sectionKey] ?? $sectionDefault;
        $description = installer_config_section_description((string) $sectionKey);

        echo '<section class="installer-card installer-card--padded installer-card--config">';
        echo '<h2 class="installer-card-title">' . InstallerSecurity::e($sectionTitle) . '</h2>';
        if ($description !== '') {
            echo '<p class="installer-helper-text" style="margin-top: 0;">' . InstallerSecurity::e($description) . '</p>';
        }

        echo '<div class="installer-config-card-body">';
        if (is_array($sectionDefault)) {
            installer_render_config_form_fields((is_array($sectionCurrent) ? $sectionCurrent : []), $sectionDefault, [$sectionKey], 0);
        } else {
            installer_render_config_form_fields([$sectionKey => $sectionCurrent], [$sectionKey => $sectionDefault], [], 0);
        }
        echo '</div>';

        echo '</section>';
    }

    echo '</div>';
}

/**
 * Render the post-install security reminder panel.
 * @return void
 */
function installer_render_security_reminder(): void
{
    echo '<div class="alert alert-warning installer-security-reminder">';
    echo '<div class="installer-security-reminder__icon"><i class="fa-solid fa-shield-halved"></i></div>';
    echo '<div class="installer-security-reminder__content">';
    echo '<div class="installer-security-reminder__title">Security Reminder</div>';
    echo '<p>After setup, the first-run installer is locked with <b>install/installer.lock</b>. Keep the updater login private and still restrict direct access to <b>/install</b> where possible.</p>';
    echo '</div>';
    echo '</div>';
}


/**
 * Render a status badge for installer progress indicators.
 * @param bool $ok
 * @param string $success
 * @param string $pending
 * @return string
 */
function installer_render_status_badge(bool $ok, string $success = 'Complete', string $pending = 'Pending'): string
{
    $class = $ok ? 'installer-status-badge is-complete' : 'installer-status-badge is-pending';
    $label = $ok ? $success : $pending;

    return '<span class="' . InstallerSecurity::e($class) . '">' . InstallerSecurity::e($label) . '</span>';
}

/**
 * Render the installer progress navigation for the current page.
 * @param array $state
 * @param string $currentPage
 * @return void
 */
function installer_render_install_progress(array $state, string $currentPage): void
{
    echo '<section class="installer-progress-grid">';

    foreach (installer_install_step_order() as $step) {
        $item = $state[$step] ?? ['label' => ucfirst($step), 'ok' => false, 'detail' => ''];
        $active = ($currentPage === $step) ? ' is-active' : '';

        echo '<div class="installer-progress-card' . $active . '">';
        echo '<div class="installer-progress-card__top">';
        echo '<div class="installer-progress-card__title">' . InstallerSecurity::e((string) $item['label']) . '</div>';
        echo installer_render_status_badge(!empty($item['ok']));
        echo '</div>';
        echo '<p>' . InstallerSecurity::e((string) ($item['detail'] ?? '')) . '</p>';
        echo '</div>';
    }

    echo '</section>';
}

/**
 * Render the shared brand-side panel for installer auth pages.
 *
 * @param bool $isSetup True for first-run credential setup, false for login
 * @return void
 */
function installer_render_auth_brand_panel(bool $isSetup): void
{
    echo '<aside class="installer-auth-brand-panel">';
    echo '<div class="installer-auth-brand-eyebrow">' . ($isSetup ? 'First-Time Setup' : 'Protected Access') . '</div>';
    echo '<h1>' . ($isSetup ? 'Secure the Installer' : 'Welcome Back') . '</h1>';

    if ($isSetup) {
        echo '<p class="installer-auth-brand-copy">Create the dedicated installer credentials used to protect installation and update actions. This account is separate from the main gallery account system.</p>';
    } else {
        echo '<p class="installer-auth-brand-copy">Sign in to continue into the installer and updater workspace for database installation, maintenance, and controlled update operations.</p>';
    }

    echo '<div class="installer-auth-feature-list">';

    echo '<div class="installer-auth-feature-item">';
    echo '<span class="installer-auth-feature-icon"><i class="fa-solid fa-shield-halved"></i></span>';
    echo '<div>';
    echo '<strong>Dedicated Protection</strong>';
    echo '<p>The installer uses its own authentication layer so setup and update actions stay isolated from the public site.</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="installer-auth-feature-item">';
    echo '<span class="installer-auth-feature-icon"><i class="fa-solid fa-database"></i></span>';
    echo '<div>';
    echo '<strong>Database-Aware Workflow</strong>';
    echo '<p>Walk through requirements, configure timezone and database access, then run the base schema in a guided sequence.</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="installer-auth-feature-item">';
    echo '<span class="installer-auth-feature-icon"><i class="fa-solid fa-rotate"></i></span>';
    echo '<div>';
    echo '<strong>Updater Ready</strong>';
    echo '<p>After installation is locked, the updater remains available for SQL migrations, config merges, and future package workflows.</p>';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</aside>';
}

/**
 * Render the first-run installer credential setup form.
 *
 * @param array<string, mixed>|null $fallback Fallback file write payload when auth config cannot be written
 * @return void
 */
function installer_render_auth_setup_page(?array $fallback = null): void
{
    echo '<main class="installer-auth">';
    echo '<section class="installer-auth-shell installer-auth-shell-setup">';

    installer_render_auth_brand_panel(true);

    echo '<section class="installer-auth-form-panel">';
    echo '<div class="installer-auth-panel-header">';
    echo '<div class="installer-auth-panel-eyebrow">Installer Registration</div>';
    echo '<h2>Create Installer Login</h2>';
    echo '<p>Set a strong username and password for the installer/updater area before continuing with the first-time setup flow.</p>';
    echo '</div>';

    echo '<div class="installer-auth-alert-stack">';
    installer_render_flash();
    echo '</div>';

    if (is_array($fallback) && !empty($fallback['content'])) {
        echo '<div class="alert alert-danger installer-auth-alert-stack">Unable to write the credentials file automatically. Copy the generated file below into <b>' . InstallerSecurity::e((string) ($fallback['path'] ?? INSTALLER_AUTH_FILE)) . '</b>.</div>';
        echo '<div class="installer-auth-field-group">';
        echo '<label for="installer-auth-fallback">Generated Credentials File</label>';
        echo '<textarea id="installer-auth-fallback" rows="12" readonly>' . InstallerSecurity::e((string) $fallback['content']) . '</textarea>';
        echo '</div>';
    }

    echo '<form method="post" class="installer-auth-form">';
    echo '<input type="hidden" name="action" value="auth_setup">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';

    echo '<div class="installer-auth-field-grid installer-auth-field-grid-two">';

    echo '<div class="installer-auth-field-group">';
    echo '<label for="installer-username">Username</label>';
    echo '<input class="installer-input" id="installer-username" type="text" name="username" autocomplete="username" required>';
    echo '</div>';

    echo '<div class="installer-auth-field-group">';
    echo '<div class="installer-auth-label-row">';
    echo '<label for="installer-password">Password</label>';
    echo '<span class="installer-auth-label-helper">Minimum 12 characters</span>';
    echo '</div>';
    echo '<input class="installer-input" id="installer-password" type="password" name="password" autocomplete="new-password" required>';
    echo '</div>';

    echo '</div>';

    echo '<div class="installer-auth-field-group">';
    echo '<label for="installer-password-confirm">Confirm Password</label>';
    echo '<input class="installer-input" id="installer-password-confirm" type="password" name="password_confirm" autocomplete="new-password" required>';
    echo '</div>';

    echo '<button type="submit" class="installer-auth-submit-button">';
    echo '<i class="fa-solid fa-lock"></i>';
    echo 'Save Installer Credentials';
    echo '</button>';
    echo '</form>';

    echo '<div class="installer-auth-links">';
    echo '<p>This login only protects the installer and updater area. It does not create a public gallery member account.</p>';
    echo '</div>';

    echo '</section>';
    echo '</section>';
    echo '</main>';
}

/**
 * Render the installer login page.
 *
 * @return void
 */
function installer_render_auth_login_page(): void
{
    echo '<main class="installer-auth">';
    echo '<section class="installer-auth-shell installer-auth-shell-login">';

    installer_render_auth_brand_panel(false);

    echo '<section class="installer-auth-form-panel">';
    echo '<div class="installer-auth-panel-header">';
    echo '<div class="installer-auth-panel-eyebrow">Installer Sign In</div>';
    echo '<h2>Login</h2>';
    echo '<p>Enter the installer credentials created during first-time setup to continue into the install or update workspace.</p>';
    echo '</div>';

    echo '<div class="installer-auth-alert-stack">';
    installer_render_flash();
    echo '</div>';

    echo '<form method="post" class="installer-auth-form">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';

    echo '<div class="installer-auth-field-group">';
    echo '<label for="installer-login-username">Username</label>';
    echo '<input class="installer-input" id="installer-login-username" type="text" name="username" autocomplete="username" required>';
    echo '</div>';

    echo '<div class="installer-auth-field-group">';
    echo '<div class="installer-auth-label-row">';
    echo '<label for="installer-login-password">Password</label>';
    echo '<span class="installer-auth-label-helper">Case-sensitive</span>';
    echo '</div>';
    echo '<input class="installer-input" id="installer-login-password" type="password" name="password" autocomplete="current-password" required>';
    echo '</div>';

    echo '<button type="submit" class="installer-auth-submit-button">';
    echo '<i class="fa-solid fa-right-to-bracket"></i>';
    echo 'Login';
    echo '</button>';
    echo '</form>';

    echo '<div class="installer-auth-links">';
    echo '<p>Need to return to the main site? <a href="/gallery">Go back to the gallery</a></p>';
    echo '</div>';

    echo '</section>';
    echo '</section>';
    echo '</main>';
}

// -------------------------------------------------
// Page rendering
// -------------------------------------------------

if (!installer_is_logged_in()) {
    installer_render_header('Login');

    if (!$authConfig) {
        $fallback = $_SESSION['auth_setup_fallback'] ?? null;
        unset($_SESSION['auth_setup_fallback']);

        installer_render_auth_setup_page(is_array($fallback) ? $fallback : null);
        installer_render_footer();
        exit;
    }

    installer_render_auth_login_page();
    installer_render_footer();
    exit;
}

installer_render_header('Installer');

$installState = installer_get_install_state();
if ($tab !== 'update') {
    $resolvedPage = installer_resolve_install_page($page, $installState);
    if ($resolvedPage !== $page && $page !== 'overview') {
        installer_flash_add('info', 'Complete the installer steps in order.');
        installer_redirect('index.php?tab=install&page=' . $resolvedPage);
    }
    $page = $resolvedPage;
}

$nextInstallStep = installer_next_install_step($installState);

$topNavItems = [
    'install' => ['Installer', 'fa-solid fa-screwdriver-wrench', 'index.php?tab=install&page=overview'],
    'update'  => ['Updater', 'fa-solid fa-rotate', 'index.php?tab=update&page=overview'],
];

$subNav = [];
if ($tab === 'update') {
    if ($page === 'placeholders') {
        installer_flash_add('info', 'Fallback default editing has been removed from the updater. Use Live Configuration for runtime config changes and Config Merge to add any missing keys from config.php.dist.');
        installer_redirect('index.php?tab=update&page=config');
    }

    $subNav = [
        'overview' => ['Updater Overview', 'fa-solid fa-circle-info'],
        'config'   => ['Live Configuration', 'fa-solid fa-sliders'],
        'database' => ['Database Updates', 'fa-solid fa-database'],
        'packages' => ['Package Staging', 'fa-solid fa-box-open'],
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

if (!isset($subNav[$page])) {
    $page = 'overview';
}

echo '<main class="installer-authenticated">';
installer_render_flash();

echo '<section class="installer-hero">';
echo '<div class="installer-hero__content">';
echo '<div class="installer-hero__eyebrow">PHP Image Board</div>';
if ($tab === 'update') {
    echo '<h1>Updater Workspace</h1>';
    echo '<p>Keep the deployment current after first-run setup. SQL updates stay available even when the installer is locked, and package archives can be staged here for future file-based upgrade flows.</p>';
} else {
    echo '<h1>Installer Workspace</h1>';
    echo '<p>Run the installation in a guided sequence: requirements, filesystem, configuration, and finally the base database install. Each step stays focused so the initial setup remains clean and predictable.</p>';
}
echo '</div>';
echo '<div class="installer-hero__actions">';
foreach ($topNavItems as $navKey => $meta) {
    $active = ($tab === $navKey) ? ' is-active' : '';
    echo '<a class="installer-topnav__item' . $active . '" href="' . InstallerSecurity::e($meta[2]) . '"><i class="' . InstallerSecurity::e($meta[1]) . '"></i> ' . InstallerSecurity::e($meta[0]) . '</a>';
}
echo '</div>';
echo '</section>';

echo '<div class="installer-shell">';
echo '<aside class="installer-sidebar">';
echo '<div class="installer-sidebar__label">' . InstallerSecurity::e($tab === 'update' ? 'Updater Navigation' : 'Installer Navigation') . '</div>';
echo '<div class="installer-subnav installer-subnav--stacked">';
foreach ($subNav as $k => $meta) {
    $active = ($page === $k) ? ' is-active' : '';
    echo '<a class="installer-subnav__item' . $active . '" href="index.php?tab=' . InstallerSecurity::e($tab) . '&page=' . InstallerSecurity::e($k) . '">';
    echo '<i class="' . InstallerSecurity::e($meta[1]) . '"></i>';
    echo '<span>' . InstallerSecurity::e($meta[0]) . '</span>';
    if ($tab === 'install' && isset($installState[$k])) {
        echo installer_render_status_badge(!empty($installState[$k]['ok']), 'Ready', 'Required');
    }
    echo '</a>';
}
if ($tab === 'install') {
    echo '<div class="installer-sidebar__helper">Next recommended step: <b>' . InstallerSecurity::e((string) ($installState[$nextInstallStep]['label'] ?? 'Overview')) . '</b></div>';
} else {
    echo '<div class="installer-sidebar__helper">The installer lock keeps first-run pages closed after deployment, while the updater remains available behind its separate login.</div>';
}
echo '</div>';
echo '</aside>';

echo '<section class="installer-content">';
installer_render_security_reminder();

if ($tab === 'update') {
    if ($page === 'overview') {
        echo '<h2>Updater Overview</h2>';
        echo '<p>Use this area for ongoing maintenance after the first install. Database SQL files remain the primary update mechanism, runtime configuration can be adjusted here, and package staging is available for future archive-based deployments.</p>';

        echo '<div class="installer-stat-grid">';
        echo '<div class="installer-stat-card"><div class="installer-stat-card__eyebrow">Installer Lock</div><div class="installer-stat-card__value">' . (!empty($installState['lock']['ok']) ? 'Enabled' : 'Missing') . '</div><p>' . InstallerSecurity::e((string) $installState['lock']['detail']) . '</p></div>';
        $updateFiles = installer_list_update_files();
        echo '<div class="installer-stat-card"><div class="installer-stat-card__eyebrow">SQL Update Files</div><div class="installer-stat-card__value">' . count($updateFiles) . '</div><p>Files detected inside /database/updates.</p></div>';
        $stagedPackages = installer_list_package_files();
        echo '<div class="installer-stat-card"><div class="installer-stat-card__eyebrow">Staged Packages</div><div class="installer-stat-card__value">' . count($stagedPackages) . '</div><p>Archive packages uploaded to secure updater storage.</p></div>';
        echo '</div>';

        echo '<div class="installer-card-grid">';
        echo '<div class="installer-card installer-card--padded"><h3>Live Configuration</h3><p>Edit runtime configuration that still reflects the live board after the first install is complete, then use Config Merge to add any new keys from future config.php.dist updates without overwriting your existing values.</p><a class="installer-button installer-button--secondary" href="index.php?tab=update&page=config">Open Live Configuration</a></div>';
        echo '<div class="installer-card installer-card--padded"><h3>Database Updates</h3><p>Apply pending SQL files and track them through the app_updates table.</p><a class="installer-button installer-button--secondary" href="index.php?tab=update&page=database">Open Database Updates</a></div>';
        echo '<div class="installer-card installer-card--padded"><h3>Package Staging</h3><p>Upload zip or tar archives into the updater staging area. This keeps future file-package installs organized without exposing uploads publicly.</p><a class="installer-button installer-button--secondary" href="index.php?tab=update&page=packages">Open Package Staging</a></div>';
        echo '</div>';

        echo '</section></div></main>';
        installer_render_footer();
        exit;
    }

    if ($page === 'config') {
        echo '<h2>Live Configuration</h2>';
        echo '<p>This page is limited to configuration that still reflects the live board directly. Control Panel-managed settings such as site, template, gallery, profile, and debugging values are intentionally kept out of this editor. Use Config Merge below whenever a future release adds new keys to <b>config.php.dist</b> and you want to add them into <b>config/config.php</b> without overwriting the values you already use.</p>';

        if (empty($existingConfig)) {
            echo '<div class="alert alert-danger">Missing <b>config/config.php</b>. Run the installer first.</div>';
        } else {
            $liveSections = installer_live_config_sections($distConfig);
            $liveDistConfig = installer_filter_config_sections($distConfig, $liveSections);
            $liveMergedConfig = installer_filter_config_sections($mergedConfig, $liveSections);

            if (!empty($liveDistConfig)) {
                echo '<form method="post">';
                echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
                echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=config">';
                installer_render_config_cards($liveMergedConfig, $liveDistConfig);
                echo '<div class="installer-button-row">';
                echo '<button type="submit" name="action" value="save_config">Save Live Configuration</button>';
                echo '</div>';
                echo '</form>';
            } else {
                echo '<div class="alert alert-info">No live runtime sections were detected in <b>config.php.dist</b> for this updater page.</div>';
            }

            $missingKeys = installer_collect_missing_config_keys($distConfig, $existingConfig);

            if (empty($missingKeys)) {
                echo '<div class="alert alert-info" style="margin-top: 14px;">No new configuration keys were found to merge from <b>config.php.dist</b>. Your <b>config/config.php</b> file is already up to date.</div>';
            } else {
                echo '<div class="alert alert-warning" style="margin-top: 14px;">Missing <b>' . count($missingKeys) . '</b> configuration key(s) that can be merged from <b>config.php.dist</b>.</div>';
                echo '<div class="installer-card installer-card--padded" style="margin-top: 12px;">';
                echo '<div class="installer-card-title">Config Merge</div>';
                echo '<p>Config Merge reads <b>config.php.dist</b>, adds any keys that are missing from <b>config/config.php</b>, and keeps the values you already have for existing keys.</p>';
                echo '<ul class="installer-key-list">';
                foreach ($missingKeys as $k) {
                    echo '<li><code>' . InstallerSecurity::e($k) . '</code></li>';
                }
                echo '</ul>';
                echo '<form method="post" style="margin-top: 14px;">';
                echo '<input type="hidden" name="action" value="merge_config">';
                echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
                echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=config">';
                echo '<button type="submit">Merge Missing Configuration Keys</button>';
                echo '</form>';
                echo '</div>';
            }
        }

        $fallbackCfg = $_SESSION['config_write_fallback'] ?? '';
        unset($_SESSION['config_write_fallback']);
        if (is_string($fallbackCfg) && trim($fallbackCfg) !== '') {
            echo '<h3 style="margin-top: 18px;">Manual Config Copy/Paste</h3>';
            echo '<div class="alert alert-danger">The updater could not write config/config.php. Copy/paste the content below into <b>config/config.php</b>.</div>';
            echo '<textarea class="installer-textarea-code">' . InstallerSecurity::e($fallbackCfg) . '</textarea>';
        }

        echo '</section></div></main>';
        installer_render_footer();
        exit;
    }

    if ($page === 'placeholders') {
        echo '<h2>Fallback Defaults</h2>';
        echo '<p>These sections stay in <b>config/config.php</b> as placeholders only. They do <b>not</b> reflect the live board while the Control Panel settings registry is available. Use this page only to maintain emergency or fallback defaults.</p>';
        echo '<div class="alert alert-warning">Changes saved here do not update the live board settings managed from the Control Panel. They only change the config.php fallback values used when database-backed settings are unavailable.</div>';

        if (empty($existingConfig)) {
            echo '<div class="alert alert-danger">Missing <b>config/config.php</b>. Run the installer first.</div>';
        } else {
            $placeholderSections = installer_placeholder_config_sections_for_dist($distConfig);
            $placeholderDistConfig = installer_filter_config_sections($distConfig, $placeholderSections);
            $placeholderMergedConfig = installer_filter_config_sections($mergedConfig, $placeholderSections);

            if (!empty($placeholderDistConfig)) {
                echo '<form method="post">';
                echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
                echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=placeholders">';
                installer_render_config_cards($placeholderMergedConfig, $placeholderDistConfig);
                echo '<div class="installer-button-row">';
                echo '<button type="submit" name="action" value="save_config">Save Fallback Defaults</button>';
                echo '</div>';
                echo '</form>';
            } else {
                echo '<div class="alert alert-info">No placeholder sections were detected in <b>config.php.dist</b> for this updater page.</div>';
            }

            $missingKeys = installer_collect_missing_config_keys($distConfig, $existingConfig);
            $placeholderMissingKeys = installer_filter_key_paths_by_sections($missingKeys, $placeholderSections);

            if (empty($placeholderMissingKeys)) {
                echo '<div class="alert alert-info" style="margin-top: 14px;">No new placeholder-only keys were found for this page.</div>';
            } else {
                echo '<div class="alert alert-warning" style="margin-top: 14px;">Missing <b>' . count($placeholderMissingKeys) . '</b> fallback placeholder key(s) that can be merged from <b>config.php.dist</b>.</div>';
                echo '<div class="installer-card installer-card--padded" style="margin-top: 12px;">';
                echo '<div class="installer-card-title">Config Merge</div>';
                echo '<p>Use config merge when a future release adds new fallback placeholder keys to <b>config.php.dist</b> and you want to pull them in without overwriting the values you already keep in <b>config.php</b>.</p>';
                echo '<ul class="installer-key-list">';
                foreach ($placeholderMissingKeys as $k) {
                    echo '<li><code>' . InstallerSecurity::e($k) . '</code></li>';
                }
                echo '</ul>';
                echo '<form method="post" style="margin-top: 14px;">';
                echo '<input type="hidden" name="action" value="merge_config">';
                echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
                echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=placeholders">';
                echo '<button type="submit">Merge Missing Fallback Keys</button>';
                echo '</form>';
                echo '</div>';
            }
        }

        $fallbackCfg = $_SESSION['config_write_fallback'] ?? '';
        unset($_SESSION['config_write_fallback']);
        if (is_string($fallbackCfg) && trim($fallbackCfg) !== '') {
            echo '<h3 style="margin-top: 18px;">Manual Config Copy/Paste</h3>';
            echo '<div class="alert alert-danger">The updater could not write config/config.php. Copy/paste the content below into <b>config/config.php</b>.</div>';
            echo '<textarea class="installer-textarea-code">' . InstallerSecurity::e($fallbackCfg) . '</textarea>';
        }

        echo '</section></div></main>';
        installer_render_footer();
        exit;
    }

    if ($page === 'database') {
        echo '<h2>Database Updates</h2>';
        echo '<p>Applies missing SQL files in <b>/database/updates</b> and records successful runs in <b>app_updates</b>.</p>';

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
                if (empty($applied[$u['filename']])) {
                    $pending[] = $u;
                }
            }

            if (empty($updates)) {
                echo '<div class="alert alert-warning">No update files found in /database/updates.</div>';
            } elseif (empty($pending)) {
                echo '<div class="alert alert-success">No pending updates.</div>';
            } else {
                echo '<div class="alert alert-warning">Pending updates: <b>' . InstallerSecurity::e((string) count($pending)) . '</b></div>';
                echo '<table class="installer-table"><tr><th>Filename</th><th>Status</th></tr>';
                foreach ($updates as $u) {
                    $isPending = empty($applied[$u['filename']]);
                    echo '<tr><td>' . InstallerSecurity::e($u['filename']) . '</td><td>' . ($isPending ? 'Pending' : 'Applied') . '</td></tr>';
                }
                echo '</table>';
            }

            echo '<form method="post" style="margin-top: 14px;">';
            echo '<input type="hidden" name="action" value="apply_updates">';
            echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
            echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=database">';
            echo '<button type="submit">Apply Pending Updates</button>';
            echo '</form>';
        }

        echo '</section></div></main>';
        installer_render_footer();
        exit;
    }

    echo '<h2>Package Staging</h2>';
    echo '<p>Upload future update archives into a protected staging area. Archive extraction and file deployment are intentionally not automated yet, but this workspace prepares the updater for that package flow.</p>';
    echo '<div class="alert alert-info">Package installs are currently a managed staging feature only. Uploaded archives are stored in <b>storage/packages/updater</b> until you decide how deployment rules should work.</div>';

    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="upload_package">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=update&page=packages">';
    echo '<div class="installer-card installer-card--padded">';
    echo '<div class="installer-card-title">Upload Archive</div>';
    echo '<div class="installer-field">';
    echo '<label class="installer-label"><b>Package File</b></label>';
    echo '<input class="installer-input" type="file" name="package_file" accept=".zip,.tar,.gz,.tgz">';
    echo '</div>';
    echo '<div class="installer-helper-text">Accepted formats: zip, tar, tar.gz, tgz. Staging limit: 25 MB.</div>';
    echo '<div class="installer-button-row"><button type="submit">Stage Package</button></div>';
    echo '</div>';
    echo '</form>';

    $packages = installer_list_package_files();
    echo '<div class="installer-card installer-card--padded" style="margin-top: 14px;">';
    echo '<div class="installer-card-title">Staged Packages</div>';
    if (empty($packages)) {
        echo '<p>No archives have been staged yet.</p>';
    } else {
        echo '<table class="installer-table"><tr><th>Filename</th><th>Size</th><th>Modified</th></tr>';
        foreach ($packages as $package) {
            echo '<tr>';
            echo '<td>' . InstallerSecurity::e((string) $package['filename']) . '</td>';
            echo '<td>' . InstallerSecurity::e(installer_format_bytes((int) ($package['size_bytes'] ?? 0))) . '</td>';
            echo '<td>' . InstallerSecurity::e((string) ($package['modified_at'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '</section></div></main>';
    installer_render_footer();
    exit;
}

if ($page === 'overview') {
    echo '<h2>Installer Overview</h2>';
    echo '<p>Follow the steps in order. The installer keeps the flow guided so the database step cannot run before the runtime requirements, folder setup, and timezone/database configuration are in place.</p>';
    installer_render_install_progress($installState, $page);
    //installer_render_overview_summary($installState);

    echo '<div class="installer-card-grid">';
    foreach (installer_install_step_order() as $step) {
        $item = $installState[$step] ?? ['label' => ucfirst($step), 'detail' => ''];
        echo '<div class="installer-card installer-card--padded">';
        echo '<div class="installer-card-title">' . InstallerSecurity::e((string) $item['label']) . '</div>';
        echo '<p>' . InstallerSecurity::e((string) ($item['detail'] ?? '')) . '</p>';
        echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=' . InstallerSecurity::e($step) . '">Open Step</a>';
        echo '</div>';
    }
    echo '</div>';

    echo '</section></div></main>';
    installer_render_footer();
    exit;
}

if ($page === 'requirements') {
    echo '<h2>Requirements</h2>';
    echo '<p>Start here before touching the database or configuration. Required checks must pass to continue through the guided install sequence.</p>';
    installer_render_install_progress($installState, $page);

    echo '<table class="installer-table"><tr><th>Requirement</th><th>Status</th><th>Type</th><th>Details</th></tr>';
    foreach (installer_collect_requirements() as $requirement) {
        $ok = !empty($requirement['ok']);
        echo '<tr>';
        echo '<td>' . InstallerSecurity::e((string) $requirement['label']) . '</td>';
        echo '<td>' . ($ok ? 'Pass' : 'Fail') . '</td>';
        echo '<td>' . (!empty($requirement['required']) ? 'Required' : 'Optional') . '</td>';
        echo '<td>' . InstallerSecurity::e((string) ($requirement['detail'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if (!empty($installState['requirements']['ok'])) {
        echo '<div class="installer-button-row"><a class="installer-button" href="index.php?tab=install&page=filesystem">Continue to Filesystem</a></div>';
    }

    echo '</section></div></main>';
    installer_render_footer();
    exit;
}

if ($page === 'filesystem') {
    echo '<h2>Filesystem</h2>';
    echo '<p>Create the runtime folders before saving the final configuration. The updater package staging directory is also prepared here so future archive uploads have a secure home.</p>';
    installer_render_install_progress($installState, $page);

    $dirChecks = installer_check_dirs();
    echo '<table class="installer-table"><tr><th>Path</th><th>Exists</th><th>Writable</th></tr>';
    foreach ($dirChecks as $d) {
        echo '<tr>';
        echo '<td>' . InstallerSecurity::e($d['path']) . '</td>';
        echo '<td>' . ($d['exists'] ? 'Yes' : 'No') . '</td>';
        echo '<td>' . ($d['writable'] ? 'Yes' : 'No') . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    echo '<form method="post" style="margin-top: 14px;">';
    echo '<input type="hidden" name="action" value="fix_dirs">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=filesystem">';
    echo '<div class="installer-button-row">';
    echo '<button type="submit">Create / Fix Required Folders</button>';
    if (!empty($installState['filesystem']['ok'])) {
        echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=config">Continue to Configuration</a>';
    }
    echo '</div></form>';

    echo '</section></div></main>';
    installer_render_footer();
    exit;
}

if ($page === 'config') {
    echo '<h2>Configuration</h2>';
    echo '<p>Only the timezone and database connection are needed for first-time installation. The remaining runtime configuration is maintained from the updater workspace after setup.</p>';
    installer_render_install_progress($installState, $page);

    $installSections = installer_install_config_sections();
    $installMergedConfig = installer_filter_config_sections($mergedConfig, $installSections);
    $installDistConfig = installer_filter_config_sections($distConfig, $installSections);

    echo '<form method="post">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=config">';
    installer_render_config_cards($installMergedConfig, $installDistConfig);
    echo '<div class="installer-button-row">';
    echo '<button type="submit" name="action" value="save_config">Save config.php</button>';
    echo '<button type="submit" name="action" value="db_test" class="installer-button--secondary">Test Database</button>';
    if (!empty($installState['config']['ok'])) {
        echo '<a class="installer-button installer-button--secondary" href="index.php?tab=install&page=database">Continue to Database Install</a>';
    }
    echo '</div>';
    echo '</form>';

    $fallbackCfg = $_SESSION['config_write_fallback'] ?? '';
    unset($_SESSION['config_write_fallback']);
    if (is_string($fallbackCfg) && trim($fallbackCfg) !== '') {
        echo '<h3 style="margin-top: 18px;">Manual Config Copy/Paste</h3>';
        echo '<div class="alert alert-danger">The installer could not write config/config.php. Copy/paste the content below into <b>config/config.php</b>.</div>';
        echo '<textarea class="installer-textarea-code">' . InstallerSecurity::e($fallbackCfg) . '</textarea>';
    }

    echo '</section></div></main>';
    installer_render_footer();
    exit;
}

echo '<h2>Database Install</h2>';
echo '<p>This is the final first-run step. The installer will make sure the base schema exists, create the initial administrator account, and then write the installer lock file so future maintenance shifts into the updater workspace.</p>';
installer_render_install_progress($installState, $page);

$config = installer_load_config_existing();
$pdo = $config ? installer_pdo_from_config($config) : null;
$initialAdminForm = installer_get_initial_admin_form();
if (!$config) {
    echo '<div class="alert alert-danger">Missing config/config.php. Complete the configuration step first.</div>';
} elseif (!$pdo) {
    echo '<div class="alert alert-danger">Unable to connect using the current config/config.php database settings. Re-test the connection on the configuration step before installing the base schema.</div>';
} else {
    $databaseReady = installer_database_ready();
    $adminExists = $databaseReady ? installer_has_administrator_account($pdo) : false;

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="install_db">';
    echo '<input type="hidden" name="csrf_token" value="' . InstallerSecurity::e(InstallerSecurity::csrfToken()) . '">';
    echo '<input type="hidden" name="return_to" value="index.php?tab=install&page=database">';

    echo '<div class="installer-card installer-card--padded">';
    echo '<div class="installer-card-title">Base Database Install</div>';

    if ($databaseReady) {
        echo '<p>The base schema already appears to be present. Submitting this step will skip the schema import, make sure the settings registry is seeded, and finalize the first-run install.</p>';
    } else {
        echo '<p>Installs <b>install/base_database.sql</b> into the configured database, creates the first administrator account, and then writes the installer lock file so the updater remains available without exposing the initial setup flow.</p>';
    }

    echo '</div>';

    echo '<div class="installer-card installer-card--padded" style="margin-top: 14px;">';
    echo '<div class="installer-card-title">Initial Administrator Account</div>';
    echo '<p>This account is for the board itself and is separate from the installer/updater login. The first account is created with administrative access during the initial install.</p>';

    if ($adminExists) {
        echo '<div class="alert alert-success">An administrator account already exists in the current database. Initial administrator creation will be skipped and this step will only finalize the install.</div>';
    } else {
        echo '<div class="installer-auth-field-grid installer-auth-field-grid-two">';

        echo '<div class="installer-auth-field-group">';
        echo '<label for="admin-username">Username</label>';
        echo '<input class="installer-input" id="admin-username" type="text" name="admin_username" maxlength="15" value="' . InstallerSecurity::e($initialAdminForm['username']) . '" required>';
        echo '</div>';

        echo '<div class="installer-auth-field-group">';
        echo '<label for="admin-display-name">Display Name <span class="installer-auth-label-helper">Optional</span></label>';
        echo '<input class="installer-input" id="admin-display-name" type="text" name="admin_display_name" maxlength="100" value="' . InstallerSecurity::e($initialAdminForm['display_name']) . '">';
        echo '</div>';

        echo '</div>';

        echo '<div class="installer-auth-field-grid installer-auth-field-grid-two">';

        echo '<div class="installer-auth-field-group">';
        echo '<label for="admin-email">Email Address</label>';
        echo '<input class="installer-input" id="admin-email" type="email" name="admin_email" maxlength="191" value="' . InstallerSecurity::e($initialAdminForm['email']) . '" required>';
        echo '</div>';

        echo '<div class="installer-auth-field-group">';
        echo '<div class="installer-auth-label-row">';
        echo '<label for="admin-password">Password</label>';
        echo '<span class="installer-auth-label-helper">Minimum 12 characters</span>';
        echo '</div>';
        echo '<input class="installer-input" id="admin-password" type="password" name="admin_password" autocomplete="new-password" required>';
        echo '</div>';

        echo '</div>';

        echo '<div class="installer-auth-field-group">';
        echo '<label for="admin-password-confirm">Confirm Password</label>';
        echo '<input class="installer-input" id="admin-password-confirm" type="password" name="admin_password_confirm" autocomplete="new-password" required>';
        echo '</div>';
    }

    echo '<div class="installer-button-row">';
    if ($databaseReady) {
        echo '<button type="submit">Finalize Initial Install</button>';
    } else {
        echo '<button type="submit">Install Base Database</button>';
    }
    echo '</div>';

    echo '</div>';
    echo '</form>';
}

echo '</section></div></main>';
installer_render_footer();
