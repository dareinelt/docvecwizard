<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> */
    public array $query;
    /** @var array<string,mixed> */
    public array $headers;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,mixed> */
    public array $files;
    public string $rawBody;

    /** @param array<string,mixed> $server @param array<string,mixed> $files */
    public function __construct(array $server = [], array $files = [])
    {
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $this->path = $path === false || $path === null ? '/' : rtrim($path, '/');
        if ($this->path === '') {
            $this->path = '/';
        }
        $query = [];
        parse_str((string) ($server['QUERY_STRING'] ?? ''), $query);
        $this->query = $query;

        $this->headers = self::parseHeaders($server);
        $this->rawBody = (string) file_get_contents('php://input');
        $this->body = self::parseBody($this->rawBody, $this->headers['content-type'] ?? '');
        $this->files = $files;
    }

    /** @return array<string,string> */
    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_FILES);
    }

    /** @param array<string,mixed> $server @return array<string,string> */
    private static function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = (string) $value;
            }
        }

        return $headers;
    }

    /** @return array<string,mixed> */
    private static function parseBody(string $raw, string $contentType): array
    {
        if ($raw === '') {
            return [];
        }
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $result);

            return is_array($result) ? $result : [];
        }
        if (str_contains($contentType, 'multipart/form-data')) {
            // PHP has already parsed multipart fields into $_POST by the time
            // the request reaches this constructor.
            return is_array($_POST) ? $_POST : [];
        }

        return [];
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function query(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function bodyField(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
