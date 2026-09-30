<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Uuid;

/**
 * Session-bound CSRF protection. The token is derived from the session secret and
 * a per-session random value delivered via an HttpOnly cookie and mirrored in the
 * page as a meta tag / header for fetch() requests.
 */
final class Csrf
{
    public const COOKIE = 'docvec_csrf';
    private const SESSION_COOKIE = 'docvec_session';

    private static string $secret;

    private static function secret(): string
    {
        return self::$secret ??= hash('sha256', Config::string('SESSION_SECRET', ''), false);
    }

    public static function ensureSessionCookie(): void
    {
        if (!isset($_COOKIE[self::SESSION_COOKIE])) {
            $value = Uuid::v4() . Uuid::v4();
            setcookie(self::SESSION_COOKIE, $value, [
                'expires' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $_COOKIE[self::SESSION_COOKIE] = $value;
        }
    }

    public static function token(): string
    {
        self::ensureSessionCookie();
        if (isset($_COOKIE[self::COOKIE])) {
            return (string) $_COOKIE[self::COOKIE];
        }
        $session = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        $token = hash_hmac('sha256', $session, self::secret());
        setcookie(self::COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $token;

        return $token;
    }

    public static function verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $session = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        $expected = hash_hmac('sha256', $session, self::secret());

        return hash_equals($expected, $token);
    }

    public static function currentToken(): string
    {
        return (string) ($_COOKIE[self::COOKIE] ?? self::token());
    }
}
