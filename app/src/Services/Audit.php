<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Security\Auth;

final class Audit
{
    /** @param array<string,mixed> $details */
    public static function record(string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        // Accountability: record which administrator triggered the action.
        if (PHP_SAPI !== 'cli' && !isset($details['actor'])) {
            $user = Auth::user();
            if ($user !== null) {
                $details['actor'] = $user['username'];
            }
        }
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
