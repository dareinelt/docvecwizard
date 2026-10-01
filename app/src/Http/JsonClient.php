<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Logger;

/**
 * Minimal JSON HTTP client using PHP stream contexts (no curl extension required
 * at runtime; curl ext is present anyway). Used to talk to embedding, converter
 * and Milvus (REST) services on the internal network.
 */
final class JsonClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function get(string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        if ($data !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
        }

        return $this->request('GET', $url, null);
    }

    /** @param array<string,mixed>|\stdClass $data @return array<string,mixed> */
    public function post(string $path, array|\stdClass $data): array
    {
        return $this->request('POST', $this->baseUrl . $path, $data);
    }

    /**
     * Raw request returning [status, body] without JSON parsing.
     *
     * @param array<string,mixed>|null $data
     * @return array{status:int,body:string}
     */
    public function raw(string $method, string $path, ?array $data = null): array
    {
        $context = [
            'http' => [
                'method' => $method,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ];
        if ($data !== null) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $context['http']['content'] = $json;
            $context['http']['header'] .= "Content-Type: application/json\r\n";
        }
        $body = @file_get_contents($this->baseUrl . $path, false, stream_context_create($context));
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body === false ? '' : $body];
    }

    /** @param array<string,mixed>|\stdClass|null $data @return array<string,mixed> */
    private function request(string $method, string $url, array|\stdClass|null $data): array
    {
        $context = [
            'http' => [
                'method' => $method,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ];
        if ($data !== null) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $context['http']['content'] = $json;
            $context['http']['header'] .= "Content-Type: application/json\r\n";
        }
        $body = @file_get_contents($url, false, stream_context_create($context));
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($body === false) {
            // UpstreamException: details are logged, the API client only sees a generic 502.
            throw new UpstreamException(sprintf('%s %s failed (network error)', $method, $url));
        }
        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['detail'])
                ? (is_string($decoded['detail']) ? $decoded['detail'] : (string) json_encode($decoded['detail']))
                : (is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : 'HTTP ' . $status);
            throw new UpstreamException(sprintf('%s %s -> %d: %s', $method, $url, $status, $message), $status);
        }

        Logger::channel('http')->debug('request', ['method' => $method, 'url' => $url, 'status' => $status]);

        return is_array($decoded) ? $decoded : [];
    }
}
