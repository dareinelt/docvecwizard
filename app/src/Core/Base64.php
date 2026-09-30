<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Strict, memory-bounded Base64 helpers used to store and verify original
 * document bytes. Decoding always uses `strict = true` so corrupted payloads
 * are rejected instead of being silently mangled.
 */
final class Base64
{
    /**
     * Decode a Base64 payload strictly. Returns null when the input contains
     * invalid characters or non-zero padding bits (i.e. is not valid Base64).
     */
    public static function decode(string $base64): ?string
    {
        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Stream-decode a (potentially huge) Base64 payload and compute its
     * SHA-256 without buffering the full decoded bytes in memory. Returns the
     * hex digest, or null when the payload is not valid Base64.
     */
    public static function sha256(string $base64): ?string
    {
        $ctx = hash_init('sha256');
        // Chunk size must be divisible by 4 so a Base64 group is never split
        // across two iterations.
        $chunk = 8192;
        $len = strlen($base64);
        for ($i = 0; $i < $len; $i += $chunk) {
            $part = base64_decode(substr($base64, $i, $chunk), true);
            if ($part === false) {
                return null;
            }
            hash_update($ctx, $part);
        }

        return hash_final($ctx);
    }

    /**
     * Verify that a Base64 payload decodes to bytes whose SHA-256 equals the
     * expected hex digest. Constant-time comparison guards against timing
     * attacks when the hash is used as an integrity/authenticity check.
     */
    public static function verifySha256(string $base64, string $expected): bool
    {
        $actual = self::sha256($base64);
        if ($actual === null) {
            return false;
        }

        return hash_equals($expected, $actual);
    }
}
