<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string,array<string,callable>> [METHOD][pattern] => handler */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $routes = $this->routes[$method] ?? [];
        foreach ($routes as $pattern => $handler) {
            $params = $this->match($pattern, $request->path);
            if ($params !== null) {
                return $handler($request, $params);
            }
        }

        return Response::error('Not found', 404);
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }

        return $params;
    }
}
