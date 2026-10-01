<?php

declare(strict_types=1);

/**
 * Migration runner. Applies app/migrations/*.sql in lexicographic order,
 * recording applied versions in schema_migrations. Safe to run concurrently
 * (guarded by a MySQL advisory lock) and idempotent.
 */

use App\Core\Db;
use App\Core\Logger;
use App\Services\UserService;

require __DIR__ . '/../config/bootstrap.php';

$log = Logger::channel('migrate');

if (!Db::lock('docvec_migrate', 30)) {
    $log->warning('could not acquire migration lock (another migration running?)');
    exit(1);
}

try {
    Db::execute(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(64) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $dir = __DIR__ . '/../migrations';
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);

    $applied = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        $already = Db::fetchValue('SELECT 1 FROM schema_migrations WHERE version = ?', [$version]);
        if ($already !== null) {
            continue;
        }
        $sql = (string) file_get_contents($file);
        $statements = splitSql($sql);
        // DDL statements cause implicit commits in MySQL/MariaDB, so they cannot
        // be wrapped in a transaction. Apply them one-by-one; each is idempotent
        // (CREATE TABLE IF NOT EXISTS), so a partial run re-applies cleanly.
        foreach ($statements as $statement) {
            Db::pdo()->exec($statement);
        }
        Db::execute('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        $applied++;
        $log->info('migration applied', ['version' => $version]);
    }

    $log->info('migrations complete', ['applied' => $applied]);

    // Seed the first administrator from ADMIN_USERNAME / ADMIN_PASSWORD (only
    // when no account exists yet; existing passwords are never overwritten).
    (new UserService())->ensureInitialAdmin();
} finally {
    Db::unlock('docvec_migrate');
}

exit(0);

/** @return list<string> */
function splitSql(string $sql): array
{
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $filtered = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));
    $joined = implode("\n", $filtered);
    $statements = [];
    foreach (explode(';', $joined) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }

    return $statements;
}
