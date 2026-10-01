<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @var array<string,string> */
    public array $headers;

    /** Absolute path of a file to stream instead of $body (large downloads). */
    private ?string $filePath = null;

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        array $headers = [],
    ) {
        $this->headers = $headers;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self($status, $body === false ? '{}' : $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            // API responses carry user data and must never be cached by the browser/proxies.
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(array_merge(['error' => $message], $extra), $status);
    }

    /** In-memory binary download with a safe, RFC 6266 encoded filename. */
    public static function download(string $bytes, string $filename, string $mimeType): self
    {
        return new self(200, $bytes, self::downloadHeaders($filename, $mimeType, strlen($bytes)));
    }

    /** Streamed file download; avoids loading multi-GB exports into memory. */
    public static function file(string $path, string $filename, string $mimeType): self
    {
        $size = filesize($path);
        $response = new self(200, '', self::downloadHeaders($filename, $mimeType, $size === false ? null : $size));
        $response->filePath = $path;

        return $response;
    }

    /** @return array<string,string> */
    private static function downloadHeaders(string $filename, string $mimeType, ?int $length): array
    {
        // Previously `addslashes($filename)` was interpolated into the header;
        // that neither handled non-ASCII names nor stripped control characters.
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? 'download';
        $fallback = trim($fallback) === '' ? 'download' : $fallback;
        $headers = [
            'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($filename)),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Never let a downloaded original (e.g. HTML) execute in our origin.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        if ($length !== null) {
            $headers['Content-Length'] = (string) $length;
        }

        return $headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->filePath !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->filePath);

            return;
        }
        echo $this->body;
    }
}
