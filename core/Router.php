<?php

/**
 * Minimal HTTP router for mapping URL paths to callbacks.
 *
 * Supports:
 * - Static routes (e.g., "/login")
 * - Simple parameter placeholders via "{param}" (e.g., "/image/{id}")
 * - Per-route HTTP method restrictions (default: GET)
 * - Optional default route for "/" and a configurable 404 handler
 *
 * This router is intentionally lightweight and framework-free. It matches the
 * current request path (REQUEST_URI) against registered route regex patterns
 * and invokes the first match.
 */
class Router
{
    // Array of routes keyed by regex pattern: pattern => [callback, allowedMethods]
    private array $routes = [];

    // Callback executed when no route matches (404 handler)
    private $notFound = null;

    // Callback executed for "/" when no explicit route match is found
    private $defaultRoute = null;

    /**
     * Register a route pattern and its handler.
     *
     * Supports two modes:
     * 1) Single route:
     *      add('/login', [AuthController::class, 'login'], ['GET', 'POST'])
     *
     * 2) Route table (bulk registration):
     *      add([
     *          ['/login', [AuthController::class, 'login'], ['GET', 'POST']],
     *          ['/logout', [AuthController::class, 'logout'], ['GET']],
     *      ])
     *
     * The $path may include simple placeholders in the form "{name}". Each placeholder
     * becomes a single regex capture group matching any non-slash segment. Captured
     * values are passed to the callback in placeholder order.
     *
     * @param mixed $path URL path pattern string, or an array route table
     * @param callable|null $callback Handler to execute when the route matches
     * @param array $methods Allowed HTTP methods for this route (default: ['GET'])
     */
    public function add($path, $callback = null, array $methods = ['GET']): void
    {
        // Bulk registration: add([ [path, callback, methods], ... ])
        if (is_array($path) && $callback === null)
        {
            foreach ($path as $route)
            {
                if (!is_array($route))
                {
                    continue;
                }

                $routePath = $route[0] ?? null;
                $routeCallback = $route[1] ?? null;
                $routeMethods = $route[2] ?? ['GET'];

                if (!is_string($routePath) || !is_callable($routeCallback))
                {
                    continue;
                }

                if (!is_array($routeMethods) || empty($routeMethods))
                {
                    $routeMethods = ['GET'];
                }

                $this->add($routePath, $routeCallback, $routeMethods);
            }

            return;
        }

        // Single route registration
        if (!is_string($path) || !is_callable($callback))
        {
            return;
        }

        // Convert placeholders {param} into regex capture groups
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '([^/]+)', $path);

        // Anchor the regex to require a full-path match
        $pattern = '#^' . $this->normalize($pattern) . '$#';

        $this->routes[$pattern] = [$callback, $methods];
    }

    /**
     * Set the callback executed when no routes match (404 handler).
     *
     * @param callable $callback
     */
    public function setNotFound(callable $callback): void
    {
        $this->notFound = $callback;
    }

    /**
     * Set the callback executed for the root path "/" when no explicit route matches.
     *
     * Commonly used to route "/" to a login or landing page without requiring a
     * dedicated route definition.
     *
     * @param callable $callback
     */
    public function setDefault(callable $callback): void
    {
        $this->defaultRoute = $callback;
    }

    /**
     * Dispatch the current HTTP request to the first matching route.
     *
     * Process:
     * - Reads the request path from REQUEST_URI (path only, no query string)
     * - Normalizes the path for consistent matching
     * - Iterates through registered routes in insertion order
     * - Validates HTTP method before executing the callback
     *
     * If no route matches:
     * - Calls the default route for "/" when configured
     * - Otherwise calls the notFound handler when configured
     * - Falls back to a basic 404 response if no handler is set
     */
    public function dispatch(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestUri = $this->normalize($requestUri);
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Treat "/index.php" the same as "/"
        if ($requestUri === '/index.php')
        {
            $requestUri = '/';
        }

        foreach ($this->routes as $pattern => [$callback, $methods])
        {
            if (preg_match($pattern, $requestUri, $matches))
            {
                // Method check
                if (!in_array($requestMethod, $methods, true))
                {
                    http_response_code(405);
                    header('Allow: ' . implode(', ', $methods));
                    echo "This request cannot be processed: invalid HTTP method.";
                    return;
                }

                array_shift($matches); // remove full match
                call_user_func_array($callback, $matches);
                return; // stop after matching route
            }
        }

        // If no match and a default route exists, call it
        if ($requestUri === '/' && $this->defaultRoute)
        {
            call_user_func($this->defaultRoute);
            return;
        }

        if ($this->notFound)
        {
            call_user_func($this->notFound);
        }
        else
        {
            http_response_code(404);
            echo "404 - Page Not Found";
        }
    }

    /**
     * Normalize a URL path for consistent route matching.
     *
     * - Ensures a single leading slash
     * - Trims any trailing slashes
     * - Guarantees "/" is returned for empty paths
     *
     * @param string $path Raw or partially-normalized path
     * @return string Normalized path beginning with "/"
     */
    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }
}
