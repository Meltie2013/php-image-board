<?php

// Enable full error reporting for development
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// -------------------------
// Load configuration
// -------------------------
$config = require __DIR__ . '/config/config.php';

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
$router->setDefault(function () {
    GalleryController::index();
});

// 404 page
$router->setNotFound(function () {
    $config = require __DIR__ . '/config/config.php';
    $template = new TemplateEngine(__DIR__ . '/templates', __DIR__ . '/cache/templates', $config);
    if (!empty($config['template']['disable_cache'])) {
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
$router->add('/upload-image', [UploadController::class, 'upload']);
$router->add('/upload-image/submit', [UploadController::class, 'upload']);

// Gallery main routes
$router->add('/page/(\d+)', [GalleryController::class, 'index']);

// Gallery image routes
$router->add(
    '/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    [GalleryController::class, 'view']
);

// Gallery image direct serving (display original)
$router->add(
    '/image/original/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) { GalleryController::serveImage($hash); }
);

// Session routes
$router->add('/user/login', [AuthController::class, 'login']);
$router->add('/user/register', [AuthController::class, 'register']);
$router->add('/user/logout', [AuthController::class, 'logout']);

// Profile routes
$router->add('/profile/overview', [ProfileController::class, 'index']);
$router->add('/profile/avatar', [ProfileController::class, 'avatar']);
$router->add('/profile/email', [ProfileController::class, 'email']);
$router->add('/profile/dob', [ProfileController::class, 'dob']);
$router->add('/profile/change-password', [ProfileController::class, 'change_password']);

// Delete image route (POST only)
$router->add(
    '/moderation/delete/image/([0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5}-[0-9a-zA-Z]{5})',
    function ($hash) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            http_response_code(405);
            echo "Method Not Allowed";
            exit;
        }
        GalleryController::delete($hash);
    }
);

// -------------------------
// Dispatch request
// -------------------------
$router->dispatch();
