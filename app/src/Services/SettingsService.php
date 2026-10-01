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

    /**
     * Validate and persist user-submitted settings. Keys are restricted to a
     * conservative identifier pattern and values to scalars of bounded size
     * (previously any key/value - including arrays cast to "Array" - was stored).
     *
     * @param array<mixed,mixed> $values
     * @throws \InvalidArgumentException
     */
    public function update(array $values): void
    {
        if (count($values) > 100) {
            throw new \InvalidArgumentException('Zu viele Einstellungen in einer Anfrage.');
        }
        $clean = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/iD', $key) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Einstellungsschlüssel: ' . mb_substr($key, 0, 64));
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException('Ungültiger Wert für Einstellung: ' . $key);
            }
            $value = (string) $value;
            if (mb_strlen($value) > 4096) {
                throw new \InvalidArgumentException('Wert zu lang für Einstellung: ' . $key);
            }
            $clean[$key] = $value;
        }
        Db::transaction(fn () => $this->setMany($clean));
    }
}
