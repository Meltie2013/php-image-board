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

// Ensure built-in groups + RBAC defaults exist
GroupModel::ensureBuiltInData();
RulesModel::ensureBuiltInData();

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
        $template->assign('title', 'Service Temporarily Unavailable');
        $template->assign('message', 'The site is temporarily offline for maintenance and platform updates.');
        $template->assign('submessage', 'We’ll be back online as soon as this work is complete.');
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

$userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
if ($userId > 0)
{
    RulesHelper::enforceBlockingRedirectIfNeeded($userId);
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
    ["/image/$image_hash/token/$gallery_page_token", function ($hash, $token) { GalleryController::servePageImage($token, $hash); }, ['GET']],

    ['/gallery', [GalleryController::class, 'index'], ['GET']],
    ['/gallery/upload-image', [UploadController::class, 'upload'], ['GET', 'POST']],
    ['/gallery/page/(\d+)', [GalleryController::class, 'index'], ['GET']],
    ["/gallery/$image_hash", [GalleryController::class, 'view'], ['GET']],
    ["/gallery/original/$image_hash", function ($hash) { GalleryController::serveImage($hash); }, ['GET']],

    ['/user/login', [AuthController::class, 'login'], ['GET', 'POST']],
    ['/user/register', [AuthController::class, 'register'], ['GET', 'POST']],
    ['/user/logout', [AuthController::class, 'logout'], ['POST']],

    ['/community/rules', [RulesController::class, 'index'], ['GET']],
    ['/community/rules/accept', [RulesController::class, 'accept'], ['POST']],
    ['/community/notifications', [NotificationsController::class, 'index'], ['GET']],
    ['/community/notifications/page/(\d+)', function ($page) { NotificationsController::index((int) $page); }, ['GET']],

    ['/profile/overview', [ProfileController::class, 'index'], ['GET']],
    ['/profile/avatar', [ProfileController::class, 'avatar'], ['GET', 'POST']],
    ['/profile/email', [ProfileController::class, 'email'], ['GET', 'POST']],
    ['/profile/dob', [ProfileController::class, 'dob'], ['GET', 'POST']],
    ['/profile/change-password', [ProfileController::class, 'change_password'], ['GET', 'POST']],

    ['/panel', [ControlPanelController::class, 'dashboard'], ['GET']],
    ['/panel/dashboard', [ControlPanelController::class, 'dashboard'], ['GET']],
    ['/panel/users', [ControlPanelController::class, 'users'], ['GET']],
    ['/panel/users/create', [ControlPanelController::class, 'userCreate'], ['GET', 'POST']],
    ['/panel/users/edit/(\d+)', function ($id) { ControlPanelController::userEdit((int)$id); }, ['GET', 'POST']],
    ['/panel/groups', [ControlPanelController::class, 'groups'], ['GET', 'POST']],
    ['/panel/groups/edit/(\d+)', function ($id) { ControlPanelController::groupEdit((int)$id); }, ['GET', 'POST']],
    ['/panel/rules', [ControlPanelController::class, 'rules'], ['GET']],
    ['/panel/rules/create', [ControlPanelController::class, 'ruleCreate'], ['GET', 'POST']],
    ['/panel/rules/edit/(\d+)', function ($id) { ControlPanelController::ruleEdit((int)$id); }, ['GET', 'POST']],
    ['/panel/rules/categories', [ControlPanelController::class, 'rulesCategories'], ['GET']],
    ['/panel/rules/categories/create', [ControlPanelController::class, 'ruleCategoryCreate'], ['GET', 'POST']],
    ['/panel/rules/categories/edit/(\d+)', function ($id) { ControlPanelController::ruleCategoryEdit((int)$id); }, ['GET', 'POST']],
    ['/panel/settings', [ControlPanelController::class, 'settings'], ['GET']],
    ['/panel/settings/categories', [ControlPanelController::class, 'settingsCategories'], ['GET']],
    ['/panel/settings/save', [ControlPanelController::class, 'settingsSave'], ['POST']],
    ['/panel/settings/delete', [ControlPanelController::class, 'settingsDelete'], ['POST']],
    ['/panel/settings/categories/save', [ControlPanelController::class, 'settingsCategorySave'], ['POST']],
    ['/panel/settings/categories/delete', [ControlPanelController::class, 'settingsCategoryDelete'], ['POST']],
    ['/panel/settings/categories/([a-z0-9_.-]+)', function ($category) { ControlPanelController::settingsCategory($category); }, ['GET']],
    ['/panel/security/logs', [ControlPanelController::class, 'securityLogs'], ['GET']],
    ['/panel/security/logs/view', [ControlPanelController::class, 'securityLogView'], ['GET']],
    ['/panel/security/blocks', [ControlPanelController::class, 'blockList'], ['GET']],
    ['/panel/security/blocks/create', [ControlPanelController::class, 'blockCreate'], ['POST']],
    ['/panel/security/blocks/edit/(\d+)', function ($id) { ControlPanelController::blockEdit((int)$id); }, ['GET', 'POST']],
    ['/panel/security/blocks/remove/(\d+)', function ($id) { ControlPanelController::blockRemove((int)$id); }, ['POST']],
    ['/panel/security/blocks/remove-match', [ControlPanelController::class, 'blockRemoveMatch'], ['POST']],
    ['/panel/image-reports', [ControlPanelController::class, 'imageReports'], ['GET']],
    ['/panel/image-reports/page/(\d+)', [ControlPanelController::class, 'imageReports'], ['GET']],
    ['/panel/image-reports/view', [ControlPanelController::class, 'imageReportView'], ['GET']],
    ['/panel/image-reports/update/(\d+)', function ($id) { ControlPanelController::updateImageReport((int)$id); }, ['POST']],
    ['/panel/image-reports/assign/(\d+)', function ($id) { ControlPanelController::assignImageReport((int)$id); }, ['POST']],
    ['/panel/image-reports/release/(\d+)', function ($id) { ControlPanelController::releaseImageReport((int)$id); }, ['POST']],
    ['/panel/image-reports/close/(\d+)', function ($id) { ControlPanelController::closeImageReport((int)$id); }, ['POST']],
    ['/panel/image-reports/reopen/(\d+)', function ($id) { ControlPanelController::reopenImageReport((int)$id); }, ['POST']],
    ['/panel/image-pending', [ControlPanelController::class, 'pending'], ['GET']],
    ['/panel/image-pending/page/(\d+)', [ControlPanelController::class, 'pending'], ['GET']],
    ["/panel/image-pending/review/$image_hash", function ($hash) { ControlPanelController::pendingImageReview($hash); }, ['GET', 'POST']],
    ["/panel/image-pending/$image_hash", function ($hash) { ControlPanelController::servePendingImage($hash); }, ['GET']],
    ['/panel/image-comparison', [ControlPanelController::class, 'comparison'], ['GET', 'POST']],
    ['/panel/image-rehash', [ControlPanelController::class, 'rehash'], ['GET', 'POST']],

    ["/gallery/$image_hash/edit", function ($hash) { GalleryController::edit($hash); }, ['POST']],
    ["/gallery/$image_hash/upvote", function ($hash) { GalleryController::upvote($hash); }, ['POST']],
    ["/gallery/$image_hash/favorite", function ($hash) { GalleryController::favorite($hash); }, ['POST']],
    ["/gallery/$image_hash/comment", function ($hash) { GalleryController::comment($hash); }, ['POST']],
    ["/gallery/$image_hash/report", function ($hash) { GalleryController::report($hash); }, ['POST']],
    ["/gallery/$image_hash/live", function ($hash) { GalleryController::live($hash); }, ['GET']],
]);

// -------------------------
// Dispatch request
// -------------------------
$router->dispatch();
