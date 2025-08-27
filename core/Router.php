<?php

class Router
{
    // Array of routes (regex => callback)
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
     */
    public function add(string $path, callable $callback): void
    {
        // Convert placeholders {param} into regex capture groups
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '([^/]+)', $path);

        // Anchor the regex to match full path, case-insensitive
        $pattern = '#^' . $this->normalize($pattern) . '$#i';

        $this->routes[$pattern] = $callback;
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

        // Treat "/index.php" the same as "/"
        if ($requestUri === '/index.php')
        {
            $requestUri = '/';
        }

        foreach ($this->routes as $pattern => $callback)
        {
            if (preg_match($pattern, $requestUri, $matches))
            {
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
