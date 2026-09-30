<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,string> */
    private static array $values = [];

    /** @param array<string,string> $env */
    public static function load(array $env): void
    {
        foreach ($env as $key => $value) {
            if (is_string($value)) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::$values[$key] ?? null;
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::$values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
