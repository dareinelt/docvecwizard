<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Synchronizer-token CSRF protection bound to the server-side session.
 *
 * The previous implementation derived the token as HMAC(session-cookie,
 * SESSION_SECRET) — with an unset/placeholder secret the token was fully
 * predictable from the (non-secret) cookie value. The token is now 256 bits of
 * CSPRNG output stored in $_SESSION and rotated on login/logout.
 * Clients send it in the `X-CSRF-Token` header.
 */
final class Csrf
{
    public const HEADER = 'x-csrf-token';
    private const KEY = 'csrf_token';

    /** Requires an active session. */
    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY]) || strlen($_SESSION[self::KEY]) !== 64) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function rotate(): string
    {
        unset($_SESSION[self::KEY]);

        return self::token();
    }

    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
