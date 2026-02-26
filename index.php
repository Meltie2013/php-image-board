<?php

// -------------------------
// Load configuration
// -------------------------
$config = require __DIR__ . '/config/config.php';

// -------------------------
// Security headers
// -------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Ensure logs directory exists
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir))
{
    mkdir($logDir, 0755, true);
}

// Log all errors to file
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/logs/errors.log');

// -------------------------
// Autoloader for core classes
// -------------------------
spl_autoload_register(function ($class)
{
    $paths = [
        __DIR__ . '/core/' . $class . '.php',
        __DIR__ . '/controllers/' . $class . '.php',
        __DIR__ . '/helpers/' . $class . '.php',
    ];

    foreach ($paths as $file)
    {
        if (file_exists($file))
        {
            require $file;
            return;
        }
    }
});

// -------------------------
// Initialize Core Systems
// -------------------------

// Database
Database::init($config['db']);

// Settings (DB overrides)
SettingsManager::init($config);
$config = SettingsManager::getConfig();

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

// Session
SessionManager::init($config['session']);
SessionManager::cleanExpired();

// Security
Security::init($config['security']);

// Request guard (rate limits, jail / block decisions)
RequestGuard::init($config);

// Set timezone globally
DateHelper::init($config['timezone']);

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
        : (require __DIR__ . '/config/config.php');
    $template = new TemplateEngine(__DIR__ . '/templates', __DIR__ . '/cache/templates', $config);
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

$HASH = '([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})';
$router->add([
    ['/gallery', [GalleryController::class, 'index'], ['GET']],
    ['/gallery/upload-image', [UploadController::class, 'upload'], ['GET', 'POST']],
    ['/gallery/page/(\d+)', [GalleryController::class, 'index'], ['GET']],
    ["/gallery/$HASH", [GalleryController::class, 'view'], ['GET']],
    ["/gallery/original/$HASH", function ($hash) { GalleryController::serveImage($hash); }, ['GET']],

    ['/user/login', [AuthController::class, 'login'], ['GET', 'POST']],
    ['/user/register', [AuthController::class, 'register'], ['GET', 'POST']],
    ['/user/logout', [AuthController::class, 'logout'], ['GET']],

    ['/profile/overview', [ProfileController::class, 'index'], ['GET']],
    ['/profile/avatar', [ProfileController::class, 'avatar'], ['GET', 'POST']],
    ['/profile/email', [ProfileController::class, 'email'], ['GET', 'POST']],
    ['/profile/dob', [ProfileController::class, 'dob'], ['GET', 'POST']],
    ['/profile/change-password', [ProfileController::class, 'change_password'], ['GET', 'POST']],

    ['/moderation/dashboard', [ModerationController::class, 'dashboard'], ['GET']],
    ['/moderation/image-comparison', [ModerationController::class, 'comparison'], ['GET', 'POST']],
    ['/moderation/image-rehash', [ModerationController::class, 'rehash'], ['GET', 'POST']],

    ["/moderation/image-pending/approve/$HASH", function ($hash) { ModerationController::approveImage($hash); }, ['POST']],
    ["/moderation/image-pending/approve/sensitive/$HASH", function ($hash) { ModerationController::approveImageSensitive($hash); }, ['POST']],
    ["/moderation/image-pending/reject/$HASH", function ($hash) { ModerationController::rejectImage($hash); }, ['POST']],

    ['/moderation/image-pending', [ModerationController::class, 'pending'], ['GET']],
    ['/moderation/image-pending/page/(\d+)', [ModerationController::class, 'pending'], ['GET']],
    ["/moderation/image-pending/$HASH", function ($hash) { ModerationController::servePendingImage($hash); }, ['GET']],

    ["/gallery/$HASH/edit", function ($hash) { GalleryController::edit($hash); }, ['POST']],
    ["/gallery/$HASH/upvote", function ($hash) { GalleryController::upvote($hash); }, ['POST']],
    ["/gallery/$HASH/favorite", function ($hash) { GalleryController::favorite($hash); }, ['POST']],
    ["/gallery/$HASH/comment", function ($hash) { GalleryController::comment($hash); }, ['POST']],
]);

// -------------------------
// Dispatch request
// -------------------------
$router->dispatch();
