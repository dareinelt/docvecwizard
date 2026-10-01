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

    /**
     * Return a secret that must be configured with a strong, non-placeholder
     * value. Fails closed: an empty or example secret would make derived keys
     * (e.g. TLS private-key encryption) predictable.
     *
     * @throws \RuntimeException when the secret is missing or weak
     */
    public static function secret(string $key, int $minLength = 32): string
    {
        $value = self::$values[$key] ?? '';
        if (!self::isStrongSecret($value, $minLength)) {
            throw new \RuntimeException(sprintf('%s is not configured with a strong secret (min. %d chars, no placeholder)', $key, $minLength));
        }

        return $value;
    }

    public static function isStrongSecret(string $value, int $minLength = 32): bool
    {
        return strlen($value) >= $minLength && !str_starts_with(strtolower($value), 'change-me');
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
