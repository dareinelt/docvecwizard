<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /** @param array{database:string,username:string,password:string} $creds */
    public static function connect(string $host, array $creds): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $host,
            $creds['database']
        );
        self::$pdo = new PDO($dsn, $creds['username'], $creds['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Pin the session time zone to UTC so that DEFAULT CURRENT_TIMESTAMP
            // / ON UPDATE CURRENT_TIMESTAMP / NOW() agree with the UTC values
            // written by the application via gmdate().
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not connected');
        }

        return self::$pdo;
    }

    /** @param list<mixed> $params */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param list<mixed> $params */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = self::fetchAll($sql, $params);

        return $rows[0] ?? null;
    }

    /** @param list<mixed> $params */
    public static function fetchValue(string $sql, array $params = [], mixed $default = null): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : $value;
    }

    /** @param list<mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Acquire a named MySQL advisory lock (used by worker/migrate). */
    public static function lock(string $name, int $timeoutSeconds = 10): bool
    {
        return (bool) self::fetchValue('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds], 0);
    }

    public static function unlock(string $name): void
    {
        self::fetchValue('SELECT RELEASE_LOCK(?)', [$name], 0);
    }
}
