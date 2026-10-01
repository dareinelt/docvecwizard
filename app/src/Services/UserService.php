<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;

/**
 * Persistence and password policy for (administrator) user accounts.
 * Passwords are hashed with Argon2id (bcrypt fallback) via password_hash().
 */
final class UserService
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const MAX_PASSWORD_LENGTH = 1024;
    // "D": "$" must not match before a trailing newline.
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{3,64}$/D';

    public static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    /** @return list<string> human-readable policy violations (empty = ok) */
    public static function passwordPolicyViolations(string $password, string $username = ''): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH) {
            $errors[] = sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_PASSWORD_LENGTH);
        }
        if ($length > self::MAX_PASSWORD_LENGTH) {
            $errors[] = 'Das Passwort ist zu lang.';
        }
        if ($username !== '' && strcasecmp($password, $username) === 0) {
            $errors[] = 'Das Passwort darf nicht dem Benutzernamen entsprechen.';
        }
        if (str_starts_with(strtolower($password), 'change-me')) {
            $errors[] = 'Bitte kein Beispiel-/Platzhalterpasswort verwenden.';
        }

        return $errors;
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return Db::fetchOne('SELECT id, username, password_hash, last_login_at FROM users WHERE username = ? LIMIT 1', [$username]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Db::fetchOne('SELECT id, username, password_hash, last_login_at FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    public function count(): int
    {
        return (int) Db::fetchValue('SELECT COUNT(*) FROM users', [], 0);
    }

    public function create(string $username, string $password): int
    {
        if (!self::isValidUsername($username)) {
            throw new \InvalidArgumentException('Ungültiger Benutzername (3–64 Zeichen: A–Z, 0–9, . _ @ -).');
        }
        $violations = self::passwordPolicyViolations($password, $username);
        if ($violations !== []) {
            throw new \InvalidArgumentException(implode(' ', $violations));
        }
        Db::execute('INSERT INTO users (username, password_hash) VALUES (?, ?)', [$username, self::hash($password)]);

        return Db::insertId();
    }

    public function setPassword(int $id, string $password): void
    {
        Db::execute('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $id]);
    }

    public function touchLogin(int $id): void
    {
        Db::execute('UPDATE users SET last_login_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * Create the initial administrator from ADMIN_USERNAME / ADMIN_PASSWORD
     * when no user exists yet. Never overwrites existing accounts.
     */
    public function ensureInitialAdmin(): void
    {
        if ($this->count() > 0) {
            return;
        }
        $log = Logger::channel('auth');
        $username = Config::string('ADMIN_USERNAME', 'admin');
        $password = Config::string('ADMIN_PASSWORD', '');
        if (!self::isValidUsername($username)) {
            $log->warning('ADMIN_USERNAME is invalid (3-64 chars: A-Z a-z 0-9 . _ @ -); no administrator created');

            return;
        }
        if ($password === '' || self::passwordPolicyViolations($password, $username) !== []) {
            $log->warning('no user account exists and ADMIN_PASSWORD is missing or too weak; '
                . 'set ADMIN_PASSWORD (min. 12 chars) in .env or run "php bin/set-password.php <user>"');

            return;
        }
        $this->create($username, $password);
        $log->info('initial administrator created', ['username' => $username]);
    }
}
