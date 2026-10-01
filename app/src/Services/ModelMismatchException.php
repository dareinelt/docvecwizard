<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The embedding service has a different model loaded than the caller expects
 * (HTTP 409 from /embed). Carries a user-safe German message; callers decide
 * whether to re-activate the expected model (worker) or report it (search).
 */
final class ModelMismatchException extends \RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $loaded)
    {
        parent::__construct(sprintf(
            'Das Embedding-Modell "%s" ist derzeit nicht geladen (geladen: %s).',
            $expected,
            $loaded !== '' ? $loaded : 'keines'
        ));
    }
}
