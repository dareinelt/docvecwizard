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
}
