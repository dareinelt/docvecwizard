<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public int $status;
    /** @var array<string,string> */
    public array $headers;
    public string $body;

    /** @param array<string,string> $headers */
    public function __construct(int $status = 200, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($status, $body === false ? '{}' : $body, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(array_merge(['error' => $message], $extra), $status);
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
        echo $this->body;
    }
}
