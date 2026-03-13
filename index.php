<?php

require __DIR__ . '/bootstrap/app.php';

// -------------------------
// Security headers
// -------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: blob:; connect-src 'self' ws: wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
{
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})/token/([a-f0-9]{32})$#', $requestPath, $matches))
{
    GalleryController::serveFastPageImageRequest($matches[2], $matches[1]);
}

// -------------------------
// Load configuration
// -------------------------
$config = require CONFIG_PATH . '/config.php';

// Ensure logs directory exists
$logDir = LOG_PATH;
if (!is_dir($logDir))
{
    if (!mkdir($logDir, 0755, true) && !is_dir($logDir))
    {
        throw new RuntimeException('Unable to create logs directory: ' . $logDir);
    }
}

// Log all errors to file
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/errors.log');

// -------------------------
// Initialize Core Systems
// -------------------------

// -------------------------
// Error reporting (based on merged config)
// -------------------------
if (!empty($config['debugging']) && !empty($config['debugging']['allow_error_outputs']))
{
    // Development mode - show errors
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
else
{
    // The full config tree may be completed after SettingsManager merges defaults + DB overrides.
    // Until then, fail closed and do not expose errors to the client.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

// Set timezone globally
DateHelper::init($config['timezone']);

// Database
Database::init($config['db']);

// Settings (DB overrides)
SettingsManager::init($config);
$config = SettingsManager::getConfig();

// Session
SessionManager::init($config['session']);

// Security
Security::init($config['security']);

// Request guard (rate limits, jail / block decisions)
RequestGuard::init($config);

$controlServerRequired = ControlServer::isRequired($config);
$controlServerAlive = ControlServer::isAlive($config);
$heartbeatState = ControlServer::readHeartbeat($config);
$runtimeState = ControlServer::loadRuntimeState($config);

$maintenanceModeEnabled = false;
$siteOnline = !empty($runtimeState['site_online']);

if ($controlServerAlive)
{
    $maintenanceModeEnabled = !empty($heartbeatState['maintenance_mode']);
    if (array_key_exists('site_online', $heartbeatState))
    {
        $siteOnline = !empty($heartbeatState['site_online']);
    }
}
else
{
    $maintenanceModeEnabled = !empty($runtimeState['maintenance_mode']);
}

$siteOffline = ($controlServerRequired && !$controlServerAlive) || !$siteOnline || !ControlServer::serviceEnabled($config, 'site', $runtimeState);

if ($siteOffline || $maintenanceModeEnabled)
{
    $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);
    if (!empty($config['template']['disable_cache']))
    {
        $template->clearCache();
    }

    http_response_code(503);
    header('Retry-After: 10');

    if ($siteOffline)
    {
        $template->assign('title', 'Site Offline');
        $template->assign('message', 'Oops, looks like our site is having some issues.');
        $template->assign('submessage', 'Please try again in a moment.');
        $template->assign('mode', 'offline');
    }
    else
    {
        $template->assign('title', 'Maintenance In Progress');
        $template->assign('message', 'The site is currently in maintenance mode. Please try again in a moment.');
        $template->assign('submessage', 'An administrator has temporarily placed the site into maintenance mode.');
        $template->assign('mode', 'maintenance');
    }

    echo $template->render('errors/maintenance.html');
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($requestPath, '/user/register') && !ControlServer::serviceEnabled($config, 'register', $runtimeState))
{
    $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);
    if (!empty($config['template']['disable_cache']))
    {
        $template->clearCache();
    }

    http_response_code(503);
    header('Retry-After: 60');
    $template->assign('title', 'Registration Temporarily Disabled');
    $template->assign('message', 'New account registration is temporarily unavailable.');
    $template->assign('submessage', 'Please try again later.');
    $template->assign('mode', 'maintenance');
    echo $template->render('errors/maintenance.html');
    exit;
}

// -------------------------
// Initialize Router
// -------------------------
$router = new Router();

// Default route
$router->setDefault(function ()
{
    GalleryController::index();
});

// 404 page
$router->setNotFound(function ()
{
    $config = (class_exists('SettingsManager') && SettingsManager::isInitialized())
        ? SettingsManager::getConfig()
        : (require CONFIG_PATH . '/config.php');
    $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);
    if (!empty($config['template']['disable_cache']))
    {
        $template->clearCache();
    }

    http_response_code(404);
    $template->assign('title', '404 Not Found');
    $template->assign('message', 'The requested page could not be found.');
    $template->render('errors/error_page.html');
});

// -------------------------
// Explicit route registrations
// -------------------------

$image_hash = '([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})';
$gallery_page_token = '([a-f0-9]{32})';
$router->add([
    ['/gallery', [GalleryController::class, 'index'], ['GET']],
    ['/gallery/upload-image', [UploadController::class, 'upload'], ['GET', 'POST']],
    ['/gallery/page/(\d+)', [GalleryController::class, 'index'], ['GET']],
    ["/image/$image_hash/token/$gallery_page_token", function ($hash, $token) { GalleryController::servePageImage($token, $hash); }, ['GET']],
    ["/gallery/$image_hash", [GalleryController::class, 'view'], ['GET']],
    ["/gallery/original/$image_hash", function ($hash) { GalleryController::serveImage($hash); }, ['GET']],

    ['/user/login', [AuthController::class, 'login'], ['GET', 'POST']],
    ['/user/register', [AuthController::class, 'register'], ['GET', 'POST']],
    ['/user/logout', [AuthController::class, 'logout'], ['POST']],

    ['/profile/overview', [ProfileController::class, 'index'], ['GET']],
    ['/profile/avatar', [ProfileController::class, 'avatar'], ['GET', 'POST']],
    ['/profile/email', [ProfileController::class, 'email'], ['GET', 'POST']],
    ['/profile/dob', [ProfileController::class, 'dob'], ['GET', 'POST']],
    ['/profile/change-password', [ProfileController::class, 'change_password'], ['GET', 'POST']],

    ['/moderation/dashboard', [ModerationController::class, 'dashboard'], ['GET']],
    ['/moderation/image-comparison', [ModerationController::class, 'comparison'], ['GET', 'POST']],
    ['/moderation/image-rehash', [ModerationController::class, 'rehash'], ['GET', 'POST']],

    ['/admin', [AdminController::class, 'dashboard'], ['GET']],
    ['/admin/dashboard', [AdminController::class, 'dashboard'], ['GET']],
    ['/admin/users', [AdminController::class, 'users'], ['GET']],
    ['/admin/users/create', [AdminController::class, 'userCreate'], ['GET', 'POST']],
    ['/admin/users/edit/(\d+)', function ($id) { AdminController::userEdit((int)$id); }, ['GET', 'POST']],

    ['/admin/settings', [AdminController::class, 'settings'], ['GET']],
    ['/admin/settings/save', [AdminController::class, 'settingsSave'], ['POST']],

    ['/admin/security/logs', [AdminController::class, 'securityLogs'], ['GET']],
    ['/admin/security/logs/view', [AdminController::class, 'securityLogView'], ['GET']],
    ['/admin/security/blocks', [AdminController::class, 'blockList'], ['GET']],
    ['/admin/security/blocks/create', [AdminController::class, 'blockCreate'], ['POST']],
    ['/admin/security/blocks/edit/(\d+)', function ($id) { AdminController::blockEdit((int)$id); }, ['GET', 'POST']],
    ['/admin/security/blocks/remove/(\d+)', function ($id) { AdminController::blockRemove((int)$id); }, ['POST']],
    ['/admin/security/blocks/remove-match', [AdminController::class, 'blockRemoveMatch'], ['POST']],

    ["/moderation/image-pending/approve/$image_hash", function ($hash) { ModerationController::approveImage($hash); }, ['POST']],
    ["/moderation/image-pending/approve/sensitive/$image_hash", function ($hash) { ModerationController::approveImageSensitive($hash); }, ['POST']],
    ["/moderation/image-pending/reject/$image_hash", function ($hash) { ModerationController::rejectImage($hash); }, ['POST']],

    ['/moderation/image-pending', [ModerationController::class, 'pending'], ['GET']],
    ['/moderation/image-pending/page/(\d+)', [ModerationController::class, 'pending'], ['GET']],
    ["/moderation/image-pending/$image_hash", function ($hash) { ModerationController::servePendingImage($hash); }, ['GET']],

    ["/gallery/$image_hash/edit", function ($hash) { GalleryController::edit($hash); }, ['POST']],
    ["/gallery/$image_hash/upvote", function ($hash) { GalleryController::upvote($hash); }, ['POST']],
    ["/gallery/$image_hash/favorite", function ($hash) { GalleryController::favorite($hash); }, ['POST']],
    ["/gallery/$image_hash/comment", function ($hash) { GalleryController::comment($hash); }, ['POST']],
    ["/gallery/$image_hash/live", function ($hash) { GalleryController::live($hash); }, ['GET']],
]);

// -------------------------
// Dispatch request
// -------------------------
$router->dispatch();
