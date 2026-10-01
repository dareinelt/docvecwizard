<?php

declare(strict_types=1);

namespace App\Http;

/**
 * An exception whose message is safe to show to the client, carrying the HTTP
 * status it should map to. Anything else that escapes a controller is logged
 * and answered with a generic 500 so internals never leak.
 */
class HttpException extends \RuntimeException
{
    public int $retryAfter = 0;

    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function tooManyRequests(string $message, int $retryAfter = 0): self
    {
        $e = new self($message, 429);
        $e->retryAfter = $retryAfter;

        return $e;
    }
}
