<?php

class Router
{
    // Array of routes (regex => [callback, methods])
    private array $routes = [];

    // Not Found callback
    private $notFound = null;

    // Default route callback
    private $defaultRoute = null;

    /**
     * Add a route
     *
     * @param string $path URL path (supports {param} placeholders)
     * @param callable $callback Function to execute
     * @param array $methods Allowed HTTP methods (default: GET)
     */
    public function add(string $path, callable $callback, array $methods = ['GET']): void
    {
        // Convert placeholders {param} into regex capture groups
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '([^/]+)', $path);

        // Anchor the regex to match full path, case-sensitive
        $pattern = '#^' . $this->normalize($pattern) . '$#';

        $this->routes[$pattern] = [$callback, $methods];
    }

    /**
     * Set 404 callback
     *
     * @param callable $callback
     */
    public function setNotFound(callable $callback): void
    {
        $this->notFound = $callback;
    }

    /**
     * Set default route (e.g., / -> login page)
     *
     * @param callable $callback
     */
    public function setDefault(callable $callback): void
    {
        $this->defaultRoute = $callback;
    }

    /**
     * Dispatch the current request
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
     * Normalize URL path (trim trailing slash, ensure leading slash)
     *
     * @param string $path
     * @return string
     */
    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }
}
