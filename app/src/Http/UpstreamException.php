<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Failure while talking to an internal service (Milvus, embedding, converter).
 * The detailed message (URLs, upstream errors) is logged server-side only; the
 * client receives a generic 502.
 */
final class UpstreamException extends \RuntimeException
{
    /** HTTP status returned by the upstream service (0 = network error / unknown). */
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
