<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal router with built-in access control. Every route is private
 * (authenticated) unless explicitly registered as public, and every
 * state-changing method is CSRF-checked centrally — controllers can no longer
 * "forget" to call a guard, which was the previous opt-in design.
 */
final class Router
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<array{method:string,regex:string,handler:callable,public:bool}> */
    private array $routes = [];

    /** @var (callable(Request, bool):void)|null */
    private $guard;

    /**
     * @param callable(Request $request, bool $isPublic):void $guard throws HttpException to reject
     */
    public function setGuard(callable $guard): void
    {
        $this->guard = $guard;
    }

    public function get(string $path, callable $handler, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $public);
    }

    public function post(string $path, callable $handler, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $public);
    }

    public function put(string $path, callable $handler, bool $public = false): void
    {
        $this->add('PUT', $path, $handler, $public);
    }

    public function delete(string $path, callable $handler, bool $public = false): void
    {
        $this->add('DELETE', $path, $handler, $public);
    }

    private function add(string $method, string $path, callable $handler, bool $public): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'public' => $public,
        ];
    }

    public static function isSafeMethod(string $method): bool
    {
        return in_array($method, self::SAFE_METHODS, true);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $this->match($route['regex'], $request->path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            if ($this->guard !== null) {
                ($this->guard)($request, $route['public']);
            }
            if (!self::isSafeMethod($request->method) && $request->malformedJson) {
                throw HttpException::badRequest('Malformed JSON body');
            }

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            return Response::error('Method not allowed', 405)
                ->withHeader('Allow', implode(', ', array_unique($allowed)));
        }

        return Response::error('Not found', 404);
    }

    /** @return array<string,string>|null */
    private function match(string $regex, string $path): ?array
    {
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
