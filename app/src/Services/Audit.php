<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class Audit
{
    /** @param array<string,mixed> $details */
    public static function record(string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        Db::execute(
            'INSERT INTO audit_log (action, entity_type, entity_id, details) VALUES (?, ?, ?, ?)',
            [
                $action,
                $entityType,
                $entityId,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
