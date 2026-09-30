<?php

declare(strict_types=1);

/**
 * Bootstrap: error handling, autoloading, config, database, logger.
 * Used by both the HTTP front controller and the CLI worker.
 */

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;

// --- Error handling ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    Logger::channel('bootstrap')->critical('unhandled exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, sprintf("[CRITICAL] %s\n", $e->getMessage()));
        return;
    }
    // Web request that escaped the front controller: emit a proper 500 JSON
    // response instead of PHP's default empty 200.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_SLASHES);
});

// --- Autoloader -------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Configuration ----------------------------------------------------------
Config::load(getenv());

// --- Database ---------------------------------------------------------------
Db::connect(Config::string('MYSQL_HOST', 'db'), [
    'database' => Config::string('MYSQL_DATABASE', 'docvec'),
    'username' => Config::string('MYSQL_USER', 'docvec'),
    'password' => Config::string('MYSQL_PASSWORD', ''),
]);

// --- Timezone ---------------------------------------------------------------
date_default_timezone_set(Config::string('APP_TIMEZONE', 'UTC'));

Logger::channel('bootstrap')->info('bootstrap complete', [
    'version' => Config::string('APP_VERSION', '0.0.0'),
]);
