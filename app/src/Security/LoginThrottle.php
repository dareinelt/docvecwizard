<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Db;

/**
 * Brute-force protection for the login endpoint. Failed attempts are stored in
 * MySQL (shared by all PHP-FPM workers) and limited per client IP and per
 * username within a sliding window.
 */
final class LoginThrottle
{
    public function windowSeconds(): int
    {
        return max(60, Config::int('LOGIN_THROTTLE_WINDOW', 900));
    }

    public function maxPerUser(): int
    {
        return max(1, Config::int('LOGIN_MAX_ATTEMPTS_USER', 5));
    }

    public function maxPerIp(): int
    {
        return max(1, Config::int('LOGIN_MAX_ATTEMPTS_IP', 20));
    }

    /** Seconds until the next attempt is allowed (0 = allowed now). */
    public function retryAfter(string $ip, string $username): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - $this->windowSeconds());
        $row = Db::fetchOne(
            'SELECT
                SUM(username = ?) AS user_failures,
                SUM(ip = ?) AS ip_failures,
                MIN(CASE WHEN username = ? THEN attempted_at END) AS user_oldest,
                MIN(CASE WHEN ip = ? THEN attempted_at END) AS ip_oldest
             FROM login_attempts
             WHERE attempted_at >= ? AND (username = ? OR ip = ?)',
            [$username, $ip, $username, $ip, $since, $username, $ip]
        ) ?? [];
        $retry = 0;
        if ((int) ($row['user_failures'] ?? 0) >= $this->maxPerUser() && $row['user_oldest'] !== null) {
            $retry = max($retry, $this->secondsUntilExpiry((string) $row['user_oldest']));
        }
        if ((int) ($row['ip_failures'] ?? 0) >= $this->maxPerIp() && $row['ip_oldest'] !== null) {
            $retry = max($retry, $this->secondsUntilExpiry((string) $row['ip_oldest']));
        }

        return $retry;
    }

    public function recordFailure(string $ip, string $username): void
    {
        Db::execute(
            'INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)',
            [substr($ip, 0, 45), mb_substr($username, 0, 191), gmdate('Y-m-d H:i:s')]
        );
        // Opportunistic cleanup keeps the table small without a cron job.
        Db::execute('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    public function clear(string $username): void
    {
        Db::execute('DELETE FROM login_attempts WHERE username = ?', [$username]);
    }

    private function secondsUntilExpiry(string $oldest): int
    {
        $ts = strtotime($oldest . ' UTC');

        return $ts === false ? $this->windowSeconds() : max(1, $ts + $this->windowSeconds() - time());
    }
}
