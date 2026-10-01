<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable HTTP request value object built from PHP superglobals.
 */
final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,mixed> */
    public readonly array $query;
    /** @var array<string,string> */
    public readonly array $headers;
    /** @var array<string,mixed> */
    public readonly array $body;
    /** @var list<UploadedFile> */
    public readonly array $files;
    public readonly string $rawBody;
    /** True when a JSON body was sent but could not be decoded. */
    public readonly bool $malformedJson;
    public readonly string $remoteAddr;
    /** True when the request reached PHP over TLS (nginx sets the HTTPS FastCGI param). */
    public readonly bool $secure;

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files raw $_FILES structure
     * @param array<string,mixed> $post  already-parsed multipart fields ($_POST)
     */
    public function __construct(array $server = [], array $files = [], array $post = [], ?string $rawBody = null)
    {
        $this->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = !is_string($path) ? '/' : rtrim($path, '/');
        $this->path = $path === '' ? '/' : $path;

        $query = [];
        parse_str((string) ($server['QUERY_STRING'] ?? ''), $query);
        $this->query = $query;

        $this->headers = self::parseHeaders($server);
        $this->remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $this->secure = $https !== '' && $https !== 'off';
        $this->rawBody = $rawBody ?? (string) file_get_contents('php://input');

        $contentType = $this->headers['content-type'] ?? '';
        $malformed = false;
        $this->body = self::parseBody($this->rawBody, $contentType, $post, $malformed);
        $this->malformedJson = $malformed;
        $this->files = UploadedFile::normalise($files);
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_FILES, $_POST);
    }

    /** @param array<string,mixed> $server @return array<string,string> */
    private static function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function parseBody(string $raw, string $contentType, array $post, bool &$malformed): array
    {
        if (str_contains($contentType, 'multipart/form-data')) {
            // PHP has already parsed multipart fields into $_POST; php://input is empty.
            return $post;
        }
        if ($raw === '') {
            return [];
        }
        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $malformed = true;

                return [];
            }
            if (!is_array($decoded)) {
                $malformed = true;

                return [];
            }

            return $decoded;
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $result = [];
            parse_str($raw, $result);

            return $result;
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

    /** Integer query parameter clamped to [$min, $max]; invalid input yields $default. */
    public function queryInt(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = filter_var($this->query($name, ''), FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, $value));
    }

    public function bodyField(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $default;
    }

    /** String body field; arrays/objects are rejected instead of being cast to "Array". */
    public function bodyString(string $name, string $default = '', int $maxLength = 0): string
    {
        $value = $this->body[$name] ?? $default;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw HttpException::badRequest(sprintf('Field "%s" must be a string', $name));
        }
        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            throw HttpException::badRequest(sprintf('Field "%s" exceeds %d characters', $name, $maxLength));
        }

        return $value;
    }

    public function bodyBool(string $name, bool $default = false): bool
    {
        if (!array_key_exists($name, $this->body)) {
            return $default;
        }
        $value = filter_var($this->body[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }
}
