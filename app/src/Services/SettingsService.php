<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class SettingsService
{
    /** @return array<string,string> */
    public function all(): array
    {
        $rows = Db::fetchAll('SELECT `key`, `value` FROM settings');
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = (string) $row['value'];
        }

        return $result;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = Db::fetchValue('SELECT `value` FROM settings WHERE `key` = ?', [$key]);

        return $value === null ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        Db::execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    /** @param array<string,string> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, (string) $value);
        }
    }
}
