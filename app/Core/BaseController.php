<?php

/**
 * BaseController
 *
 * Shared controller utilities for configuration loading, template creation,
 * common user lookups, and standardized error rendering.
 */
abstract class BaseController
{
    /**
     * Static template variables automatically assigned for the controller.
     *
     * @var array
     */
    protected static array $templateAssignments = [];

    /**
     * Whether the controller's standard template bootstrapping should inject a
     * CSRF token.
     *
     * @var bool
     */
    protected static bool $templateUsesCsrf = false;

    /**
     * Load runtime configuration.
     *
     * @return array
     */
    protected static function getConfig(): array
    {
        return AppConfig::get();
    }

    /**
     * Initialize the template engine for the current controller.
     *
     * @return TemplateEngine
     */
    protected static function initTemplate(): TemplateEngine
    {
        $config = static::getConfig();
        $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);

        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        foreach (static::$templateAssignments as $key => $value)
        {
            $template->assign($key, $value);
        }

        if (static::$templateUsesCsrf)
        {
            $template->assign('csrf_token', Security::generateCsrfToken());
        }

        return $template;
    }


    /**
     * Retrieve the currently authenticated application user id from session.
     *
     * Normalizes missing, empty, or malformed session values to 0 so
     * controller code can avoid passing null into strictly typed model calls.
     *
     * @return int Current user id when present, otherwise 0.
     */
    protected static function getCurrentUserId(): int
    {
        return TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
    }

    /**
     * Require a valid authenticated user id for the current request.
     *
     * This builds on RoleHelper::requireLogin() by also validating that the
     * current session still contains a usable numeric user id. When the
     * session is stale or partially cleared, the request is redirected back to
     * the login page instead of letting null values reach typed model methods.
     *
     * @param bool $rememberDestination Whether the current request should be stored for post-login redirect.
     * @param string $loginPath Login route used when the session is invalid.
     * @return int Authenticated user id.
     */
    protected static function requireAuthenticatedUserId(bool $rememberDestination = false, string $loginPath = '/user/login'): int
    {
        RoleHelper::requireLogin();

        $userId = static::getCurrentUserId();
        if ($userId > 0)
        {
            return $userId;
        }

        SessionManager::destroy();
        if ($rememberDestination)
        {
            RedirectHelper::rememberLoginDestination();
        }

        header('Location: ' . $loginPath);
        exit();
    }

    /**
     * Retrieve a username by user ID.
     *
     * Used for display purposes in templates and administrative lists.
     * Returns an empty string when the user ID is missing or no matching user
     * exists.
     *
     * @param int|null $userId ID of the user, or null if not available.
     * @return string Username if found, otherwise empty string.
     */
    protected static function getUsernameById(?int $userId): string
    {
        return UserModel::getUsernameById($userId);
    }

    /**
     * Render a standard error page.
     *
     * @param int $statusCode HTTP status code to send.
     * @param string $title Error page title.
     * @param string $message User-facing error description.
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    protected static function renderErrorPage(int $statusCode, string $title, string $message, ?TemplateEngine $template = null): void
    {
        http_response_code($statusCode);
        $template = $template ?: static::initTemplate();
        $template->assign('title', $title);
        $template->assign('message', $message);
        $template->assign('status_code', $statusCode);
        $template->assign('status_label', self::resolveStatusLabel($statusCode));
        if (!isset($_POST['back_to']) && !isset($_GET['back_to']) && RedirectHelper::getSafeRefererPath())
        {
            $template->assign('link', RedirectHelper::getSafeRefererPath());
        }
        $template->render('errors/error_page.html');
    }


    /**
     * Resolve a short human-readable label for one HTTP status.
     *
     * @param int $statusCode
     * @return string
     */
    protected static function resolveStatusLabel(int $statusCode): string
    {
        return match ($statusCode)
        {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            423 => 'Locked',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'Request Error',
        };
    }

    /**
     * Render the standard image-not-found page.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @param string $message Optional override message.
     * @return void
     */
    protected static function renderImageNotFound(?TemplateEngine $template = null, string $message = 'The requested image could not be found.'): void
    {
        static::renderErrorPage(404, 'Image Not Found', $message, $template);
    }

    /**
     * Render the standard invalid-request page.
     *
     * @param TemplateEngine|null $template Optional prepared template instance.
     * @return void
     */
    protected static function renderInvalidRequest(?TemplateEngine $template = null): void
    {
        static::renderErrorPage(403, 'Access Denied', 'Invalid request.', $template);
    }
}
