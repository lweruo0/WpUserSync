<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class ApiRouter
{
    private string $method;
    private string $path;
    private string $requestUri;
    private array $pathParts = [];
    private array $pathParams = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $this->path = (string) parse_url($this->requestUri, PHP_URL_PATH);
        $this->parsePath();
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPathParams(): array
    {
        return $this->pathParams;
    }

    public function getPathParam(string $name): ?string
    {
        return $this->pathParams[$name] ?? null;
    }

    /**
     * Matches route pattern (e.g. /core/users/{userId}/fields/{name})
     * Returns true and populates $this->pathParams if matched.
     */
    public function match(string $method, string $pattern): bool
    {
        if ($this->method !== $method) {
            return false;
        }

        return $this->matchPattern($pattern);
    }

    /**
     * Converts pattern like "/core/users/{userId}/fields/{name}" to regex and matches path.
     */
    private function matchPattern(string $pattern): bool
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, preg_quote($pattern, '#'));

        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $this->path, $matches)) {
            return false;
        }

        $this->pathParams = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        return true;
    }

    private function parsePath(): void
    {
        $path = trim($this->path, '/');
        $this->pathParts = array_filter(explode('/', $path));
    }
}
