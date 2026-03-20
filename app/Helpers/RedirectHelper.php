<?php

/**
 * RedirectHelper
 *
 * Centralized helper for safe, internal-only redirect handling.
 *
 * Responsibilities:
 * - Normalize current request / referer paths for redirect use
 * - Reject external or malformed redirect targets
 * - Persist intended login destinations in the session
 * - Persist the last visited gallery page for image-view back navigation
 *
 * Security notes:
 * - Only same-origin relative paths are allowed
 * - Authentication routes are rejected as redirect targets to avoid loops
 * - CR/LF characters are rejected to prevent header injection
 */
class RedirectHelper
{
    private const LOGIN_REDIRECT_SESSION_KEY = 'login_redirect_to';
    private const LAST_GALLERY_PAGE_SESSION_KEY = 'gallery_last_page';

    /**
     * Get the current request URI as an internal path with optional query string.
     *
     * @return string Current request URI, or "/" when unavailable.
     */
    public static function getCurrentRequestUri(): string
    {
        $requestUri = TypeHelper::toString($_SERVER['REQUEST_URI'] ?? '/', allowEmpty: true) ?? '/';
        $normalized = self::sanitizeInternalPath($requestUri, true);

        return $normalized ?? '/';
    }

    /**
     * Get the current request path without the query string.
     *
     * @return string Current request path, or "/" when unavailable.
     */
    public static function getCurrentRequestPath(): string
    {
        $requestUri = self::getCurrentRequestUri();
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return $path !== '' ? $path : '/';
    }

    /**
     * Get the current request referer when it resolves to a safe internal path.
     *
     * @return string|null Safe internal referer path, or null when unavailable.
     */
    public static function getSafeRefererPath(): ?string
    {
        return self::sanitizeInternalPath($_SERVER['HTTP_REFERER'] ?? null);
    }

    /**
     * Remove one query-string parameter from a safe internal path.
     *
     * @param string|null $target Target path or URL.
     * @param string $parameter Query-string parameter to remove.
     * @param bool $allowAuthRoutes Whether auth routes may be returned.
     * @return string|null Normalized internal path without the parameter, or null when invalid.
     */
    public static function removeQueryParameter(?string $target, string $parameter, bool $allowAuthRoutes = false): ?string
    {
        $normalized = self::sanitizeInternalPath($target, $allowAuthRoutes);
        if ($normalized === null)
        {
            return null;
        }

        $parts = parse_url($normalized);
        if ($parts === false)
        {
            return null;
        }

        $path = TypeHelper::toString($parts['path'] ?? '/', allowEmpty: false) ?? '/';
        $query = TypeHelper::toString($parts['query'] ?? '', allowEmpty: true) ?? '';
        if ($query === '')
        {
            return $path;
        }

        parse_str($query, $queryValues);
        unset($queryValues[$parameter]);

        if (empty($queryValues))
        {
            return $path;
        }

        return $path . '?' . http_build_query($queryValues);
    }

    /**
     * Normalize one redirect target to an internal path with optional query string.
     *
     * Accepts:
     * - Relative paths beginning with "/"
     * - Absolute same-origin URLs (converted back to relative paths)
     *
     * Rejects:
     * - External URLs
     * - Empty values
     * - Authentication routes (unless explicitly allowed)
     * - Values containing CR/LF characters
     *
     * @param string|null $target Target path or URL.
     * @param bool $allowAuthRoutes Whether auth routes may be returned.
     * @return string|null Safe normalized internal target, or null when invalid.
     */
    public static function sanitizeInternalPath(?string $target, bool $allowAuthRoutes = false): ?string
    {
        $target = TypeHelper::toString($target ?? '', allowEmpty: true) ?? '';
        if ($target === '' || preg_match('/[\r\n]/', $target) === 1)
        {
            return null;
        }

        $parts = parse_url($target);
        if ($parts === false)
        {
            return null;
        }

        $host = strtolower(TypeHelper::toString($parts['host'] ?? '', allowEmpty: true) ?? '');
        if ($host !== '')
        {
            $requestHost = strtolower(TypeHelper::toString($_SERVER['HTTP_HOST'] ?? '', allowEmpty: true) ?? '');
            $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: '';

            if ($requestHost === '' || $host !== $requestHost)
            {
                return null;
            }
        }

        $path = TypeHelper::toString($parts['path'] ?? '', allowEmpty: true) ?? '';
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//'))
        {
            return null;
        }

        if (!$allowAuthRoutes && self::isDisallowedAuthPath($path))
        {
            return null;
        }

        $normalized = $path;
        $query = TypeHelper::toString($parts['query'] ?? '', allowEmpty: true) ?? '';
        if ($query !== '')
        {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * Remember where the user should be sent after login.
     *
     * When no explicit target is provided, the helper falls back to the current
     * request URI for GET/HEAD requests, or the internal referer for other
     * request methods.
     *
     * @param string|null $target Optional redirect target.
     * @return void
     */
    public static function rememberLoginDestination(?string $target = null): void
    {
        $destination = self::sanitizeInternalPath($target);
        if ($destination === null)
        {
            $destination = self::detectRequestLoginDestination();
        }

        if ($destination !== null)
        {
            SessionManager::set(self::LOGIN_REDIRECT_SESSION_KEY, $destination);
        }
    }

    /**
     * Get the currently remembered login destination without consuming it.
     *
     * @return string Remembered destination, or an empty string when none exists.
     */
    public static function getRememberedLoginDestination(): string
    {
        $destination = self::sanitizeInternalPath(SessionManager::get(self::LOGIN_REDIRECT_SESSION_KEY), true);

        return $destination ?? '';
    }

    /**
     * Resolve and consume the post-login destination.
     *
     * Priority:
     * - Explicit submitted target
     * - Remembered session target
     * - Provided default
     *
     * @param string|null $target Explicit redirect target.
     * @param string $default Fallback destination.
     * @return string Final safe redirect destination.
     */
    public static function takeLoginDestination(?string $target = null, string $default = '/profile/overview'): string
    {
        $destination = self::sanitizeInternalPath($target);
        if ($destination === null)
        {
            $destination = self::sanitizeInternalPath(SessionManager::get(self::LOGIN_REDIRECT_SESSION_KEY), true);
        }

        SessionManager::remove(self::LOGIN_REDIRECT_SESSION_KEY);

        return $destination ?? $default;
    }

    /**
     * Remember the last gallery page visited by the user.
     *
     * @param string|null $target Gallery page target.
     * @return void
     */
    public static function rememberGalleryPage(?string $target = null): void
    {
        $galleryPath = self::sanitizeInternalPath($target);
        if ($galleryPath !== null)
        {
            SessionManager::set(self::LAST_GALLERY_PAGE_SESSION_KEY, $galleryPath);
        }
    }

    /**
     * Resolve one gallery back destination.
     *
     * Priority:
     * - Explicit provided gallery page
     * - Remembered last gallery page
     * - Provided default
     *
     * @param string|null $target Explicit gallery page target.
     * @param string $default Fallback gallery destination.
     * @return string Safe gallery destination.
     */
    public static function resolveGalleryPage(?string $target = null, string $default = '/gallery'): string
    {
        $galleryPath = self::sanitizeInternalPath($target);
        if ($galleryPath !== null)
        {
            self::rememberGalleryPage($galleryPath);
            return $galleryPath;
        }

        $remembered = self::sanitizeInternalPath(SessionManager::get(self::LAST_GALLERY_PAGE_SESSION_KEY), true);
        return $remembered ?? $default;
    }

    /**
     * Detect the best login destination for the current request.
     *
     * GET/HEAD requests use the current request URI. Non-GET requests fall back
     * to the internal referer so state-changing endpoints do not redirect back
     * to a POST-only route after authentication.
     *
     * @return string|null Safe detected destination, or null when none exists.
     */
    private static function detectRequestLoginDestination(): ?string
    {
        $method = strtoupper(TypeHelper::toString($_SERVER['REQUEST_METHOD'] ?? 'GET', allowEmpty: true) ?? 'GET');
        if (in_array($method, ['GET', 'HEAD'], true))
        {
            return self::sanitizeInternalPath(self::getCurrentRequestUri());
        }

        return self::getSafeRefererPath();
    }

    /**
     * Determine whether a path should be rejected as an auth redirect target.
     *
     * @param string $path Request path.
     * @return bool True when the path should not be used as a redirect target.
     */
    private static function isDisallowedAuthPath(string $path): bool
    {
        return in_array($path, ['/user/login', '/user/logout', '/user/register'], true);
    }
}
