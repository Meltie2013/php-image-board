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
        if ($userId === null)
        {
            return '';
        }

        $result = Database::fetch("SELECT username FROM app_users WHERE id = :id LIMIT 1", [':id' => $userId]);
        return TypeHelper::toString($result['username'] ?? '') ?? '';
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
        $template->render('errors/error_page.html');
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
