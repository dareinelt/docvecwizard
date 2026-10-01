<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Central defence-in-depth path safety. Every filesystem path that is derived
 * from user input must pass through resolve() before any file operation.
 */
final class PathGuard
{
    /**
     * Resolve a user-supplied relative path against a trusted root and guarantee
     * the result stays inside the root (no `..`, no absolute paths, no null bytes).
     *
     * @throws \InvalidArgumentException when the path escapes the root
     */
    public static function resolve(string $root, string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Invalid path (null byte)');
        }
        if ($relative === '' || $relative === '.' || $relative === '/') {
            $relative = '.';
        }
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $base = realpath($root);
        if ($base === false) {
            throw new \InvalidArgumentException('Root path does not exist');
        }
        $candidate = $base . '/' . $relative;
        $resolved = realpath($candidate);
        // If the path does not exist yet, resolve its deepest existing ancestor.
        if ($resolved === false) {
            $resolved = realpath(dirname($candidate));
            if ($resolved === false) {
                throw new \InvalidArgumentException('Path is outside the allowed root');
            }
            $resolved .= '/' . basename($candidate);
        }
        $baseWithSep = $base . '/';
        if ($resolved !== $base && !str_starts_with($resolved, $baseWithSep)) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        return $resolved;
    }

    /** Validate a plain file/folder name (single path segment, no separators). */
    public static function validateName(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        // SECURITY FIX: "D" modifier - without it "$" also matched before a
        // trailing newline, so names like "file.txt\n" passed the check.
        return preg_match('#^[A-Za-z0-9._\-+ ()äöüÄÖÜß]+$#uD', $name) === 1
            && !str_contains($name, '/')
            && !str_contains($name, '\\');
    }

    /** Normalise a relative path for storage (forward slashes, no leading slash). */
    public static function normalise(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
