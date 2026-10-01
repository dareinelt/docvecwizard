<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Http\Request;

/**
 * Hardened native PHP session.
 *
 * - strict mode (uninitialised IDs supplied by an attacker are rejected)
 * - cookie only, HttpOnly, SameSite=Strict, Secure when served over TLS
 * - idle and absolute timeouts enforced server-side
 *
 * The session is started lazily: anonymous requests to public endpoints such
 * as /healthz never create session files.
 */
final class Session
{
    public const NAME = 'docvec_sid';

    public static function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cache_limiter', '');
        ini_set('session.gc_maxlifetime', (string) self::absoluteTimeout());

        $savePath = Config::string('SESSION_SAVE_PATH', '/app/storage/sessions');
        if ($savePath !== '' && (is_dir($savePath) || @mkdir($savePath, 0700, true)) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $request->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        $now = time();
        $lastSeen = (int) ($_SESSION['last_seen'] ?? 0);
        $createdAt = (int) ($_SESSION['created_at'] ?? 0);
        if (($lastSeen > 0 && $now - $lastSeen > self::idleTimeout())
            || ($createdAt > 0 && $now - $createdAt > self::absoluteTimeout())) {
            // Expired: drop all state and continue with a fresh, empty session.
            $_SESSION = [];
            session_regenerate_id(true);
            $createdAt = 0;
        }
        if ($createdAt === 0) {
            $_SESSION['created_at'] = $now;
        }
        $_SESSION['last_seen'] = $now;
    }

    /** New session ID after a privilege change (login) to prevent session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        }
    }

    /**
     * Write and unlock the session early. PHP's file handler holds an exclusive
     * lock for the whole request; long requests (export, search) would
     * otherwise block the UI's parallel polling requests.
     */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Re-open a released session (for endpoints that must write to it). */
    public static function resume(Request $request): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start($request);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(self::NAME, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
        // Never reuse the old ID if a new (anonymous) session is started in
        // the same request, e.g. right after logout.
        $fresh = session_create_id();
        if (is_string($fresh)) {
            session_id($fresh);
        }
    }

    public static function idleTimeout(): int
    {
        return max(60, Config::int('SESSION_IDLE_TIMEOUT', 1800));
    }

    public static function absoluteTimeout(): int
    {
        return max(self::idleTimeout(), Config::int('SESSION_ABSOLUTE_TIMEOUT', 43200));
    }
}
