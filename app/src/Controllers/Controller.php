<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Uuid;
use App\Http\HttpException;

/**
 * Shared helpers for the API controllers. Controllers are thin: they read and
 * validate request input, delegate to services and shape the JSON response.
 * Error handling is centralised in the front controller (public/index.php):
 *   HttpException            -> its status + message
 *   InvalidArgumentException -> 400 + message (validation errors from services)
 *   UpstreamException        -> 502 generic message (details logged)
 *   anything else            -> 500 generic message (details logged)
 * Previously every action caught \Throwable and returned getMessage() to the
 * client, leaking SQL errors, internal URLs and file paths.
 */
abstract class Controller
{
    /**
     * Validate a UUID route parameter. Invalid IDs are answered with 404
     * before they reach the database or a Milvus filter expression.
     *
     * @param array<string,string> $params
     */
    protected function uuidParam(array $params, string $name = 'id', string $notFound = 'Not found'): string
    {
        $value = strtolower((string) ($params[$name] ?? ''));
        if (!Uuid::isValid($value)) {
            throw HttpException::notFound($notFound);
        }

        return $value;
    }

    /** @param array<string,string> $params */
    protected function intParam(array $params, string $name = 'id', string $notFound = 'Not found'): int
    {
        $value = filter_var($params[$name] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw HttpException::notFound($notFound);
        }

        return $value;
    }
}
