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

// -------------------------
// Error reporting (based on config)
// -------------------------
if (!empty($config['debugging']['allow_error_outputs']) || $config['debugging']['allow_error_outputs'] === true)
{
    // Development mode - show errors
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
else
{
    // Production mode - hide errors but log them
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);

    // Ensure logs directory exists
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir))
    {
        mkdir($logDir, 0755, true);
    }

    // Log all errors to file
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/logs/errors.log');
}

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

// Session
SessionManager::init($config['session']);
SessionManager::cleanExpired();

// Security
Security::init($config['security']);

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
    $config = require __DIR__ . '/config/config.php';
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

// Gallery upload routes
$router->add('/upload-image', [UploadController::class, 'upload'], ['GET', 'POST']);

// Gallery main routes
$router->add('/page/(\d+)', [GalleryController::class, 'index'], ['GET']);

// Gallery image routes
$router->add('/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    [GalleryController::class, 'view'], ['GET']
);

// Gallery image direct serving (display original)
$router->add('/image/original/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { GalleryController::serveImage($hash); }, ['GET']
);

// Session routes
$router->add('/user/login', [AuthController::class, 'login'], ['GET', 'POST']);
$router->add('/user/register', [AuthController::class, 'register'], ['GET', 'POST']);
$router->add('/user/logout', [AuthController::class, 'logout'], ['GET']);

// Profile routes
$router->add('/profile/overview', [ProfileController::class, 'index'], ['GET']);
$router->add('/profile/avatar', [ProfileController::class, 'avatar'], ['GET', 'POST']);
$router->add('/profile/email', [ProfileController::class, 'email'], ['GET', 'POST']);
$router->add('/profile/dob', [ProfileController::class, 'dob'], ['GET', 'POST']);
$router->add('/profile/change-password', [ProfileController::class, 'change_password'], ['GET', 'POST']);

// Moderation routes
$router->add('/moderation', [ModerationController::class, 'dashboard'], ['GET']);
$router->add('/moderation/image-comparison', [ModerationController::class, 'comparison'], ['GET', 'POST']);
$router->add('/moderation/image-rehash', [ModerationController::class, 'rehash'], ['GET', 'POST']);

$router->add(
    '/moderation/image-pending/approve/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { ModerationController::approveImage($hash); }, ['POST']
);

$router->add(
    '/moderation/image-pending/approve/sensitive/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { ModerationController::approveImageSensitive($hash); }, ['POST']
);

$router->add(
    '/moderation/image-pending/reject/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { ModerationController::rejectImage($hash); }, ['POST']
);

// Pending images moderation page with optional page number
$router->add('/moderation/image-pending', [ModerationController::class, 'pending'], ['GET']);
$router->add('/moderation/image-pending/page/(\d+)', [ModerationController::class, 'pending'], ['GET']);
$router->add('/moderation/image-pending/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { ModerationController::servePendingImage($hash); }, ['GET']
);

// Edit image
$router->add(
    '/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})/edit',
    function ($hash) { GalleryController::edit($hash); }, ['POST']
);

// Image Upvote route (POST only)
$router->add(
    '/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})/upvote',
    function ($hash) { GalleryController::upvote($hash); }, ['POST']
);

// Favorite image route (POST only)
$router->add(
    '/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})/favorite',
    function ($hash) { GalleryController::favorite($hash); }, ['POST']
);

// Comment on image
$router->add(
    '/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})/comment',
    function ($hash) { GalleryController::comment($hash); }, ['POST']
);

// -------------------------
// Dispatch request
// -------------------------
$router->dispatch();
