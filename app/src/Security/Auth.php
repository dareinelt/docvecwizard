<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\HttpException;
use App\Services\Audit;
use App\Services\UserService;

/**
 * Session-based authentication (single administrator role).
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    /** @var array<string,mixed>|null|false false = not yet resolved */
    private static array|null|false $user = false;

    private static ?string $dummyHash = null;

    /** @return array{id:int,username:string}|null */
    public static function user(): ?array
    {
        if (self::$user === false) {
            self::$user = null;
            $id = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
            if ($id > 0) {
                $row = (new UserService())->findById($id);
                // A deleted account invalidates every session that references it.
                self::$user = $row === null ? null : ['id' => (int) $row['id'], 'username' => (string) $row['username']];
            }
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Verify credentials and log the user in.
     *
     * @return array{id:int,username:string}
     * @throws HttpException 401 on bad credentials, 429 when throttled
     */
    public static function attempt(string $username, string $password, string $ip, ?LoginThrottle $throttle = null): array
    {
        $throttle ??= new LoginThrottle();
        $retryAfter = $throttle->retryAfter($ip, $username);
        if ($retryAfter > 0) {
            throw HttpException::tooManyRequests(
                sprintf('Zu viele fehlgeschlagene Anmeldeversuche. Bitte in %d Minute(n) erneut versuchen.', (int) ceil($retryAfter / 60)),
                $retryAfter
            );
        }

        $users = new UserService();
        $row = $username === '' ? null : $users->findByUsername($username);
        // Always run password_verify so response timing does not reveal whether the user exists.
        $hash = $row !== null ? (string) $row['password_hash'] : self::dummyHash();
        $valid = password_verify($password, $hash) && $row !== null;

        if (!$valid) {
            $throttle->recordFailure($ip, $username);
            Audit::record('auth.login_failed', 'user', mb_substr($username, 0, 64), ['ip' => $ip]);
            throw HttpException::unauthorized('Benutzername oder Passwort ist falsch.');
        }

        $id = (int) $row['id'];
        if (password_needs_rehash($hash, UserService::algorithm())) {
            $users->setPassword($id, $password);
        }
        $throttle->clear($username);
        $users->touchLogin($id);

        // Prevent session fixation and rotate the CSRF token on privilege change.
        Session::regenerate();
        $_SESSION[self::SESSION_KEY] = $id;
        Csrf::rotate();
        self::$user = ['id' => $id, 'username' => (string) $row['username']];
        Audit::record('auth.login', 'user', (string) $id, ['ip' => $ip]);

        return self::$user;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            Audit::record('auth.logout', 'user', (string) $user['id']);
        }
        Session::destroy();
        self::$user = null;
    }

    /** @throws HttpException */
    public static function changePassword(string $current, string $new): void
    {
        $user = self::user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        $users = new UserService();
        $row = $users->findById($user['id']);
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            throw HttpException::badRequest('Das aktuelle Passwort ist falsch.');
        }
        $violations = UserService::passwordPolicyViolations($new, $user['username']);
        if ($violations !== []) {
            throw HttpException::badRequest(implode(' ', $violations));
        }
        $users->setPassword($user['id'], $new);
        Session::regenerate();
        Csrf::rotate();
        Audit::record('auth.password_changed', 'user', (string) $user['id']);
    }

    /** Test seam: reset the per-request cache. */
    public static function reset(): void
    {
        self::$user = false;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= UserService::hash(bin2hex(random_bytes(16)));
    }
}
