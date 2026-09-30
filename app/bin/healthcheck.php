<?php

declare(strict_types=1);

/**
 * CLI healthcheck for the app container. Verifies PHP runs and the database is
 * reachable. (FPM reachability is covered end-to-end by the web service's
 * HTTPS healthcheck.)
 */

use App\Core\Db;

require __DIR__ . '/../config/bootstrap.php';

try {
    if ((int) Db::fetchValue('SELECT 1') !== 1) {
        fwrite(STDERR, "database check failed\n");
        exit(1);
    }
    echo "ok\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
