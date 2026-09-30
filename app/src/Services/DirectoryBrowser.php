<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Security\PathGuard;

/**
 * Browser for the document input directory. Every path returned to the frontend
 * is validated against INPUT_ROOT and expressed relative to it.
 */
final class DirectoryBrowser
{
    public function root(): string
    {
        return Config::string('INPUT_ROOT', '/srv/data/input');
    }

    /**
     * List a directory relative to INPUT_ROOT.
     *
     * @return array{path:string,entries:list<array<string,mixed>>}
     */
    public function list(string $relative): array
    {
        $root = $this->root();
        $absolute = PathGuard::resolve($root, $relative);
        if (!is_dir($absolute)) {
            throw new \InvalidArgumentException('Not a directory: ' . $relative);
        }
        $entries = [];
        $items = scandir($absolute);
        if ($items === false) {
            throw new \RuntimeException('Cannot read directory');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $absolute . '/' . $item;
            $rel = $relative === '' || $relative === '.' ? $item : ltrim($relative, '/') . '/' . $item;
            $stat = @stat($full);
            $entries[] = [
                'name' => $item,
                'path' => $rel,
                'type' => is_dir($full) ? 'dir' : 'file',
                'size' => $stat['size'] ?? 0,
                'modified_at' => isset($stat['mtime']) ? gmdate('Y-m-d\TH:i:s\Z', $stat['mtime']) : null,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return [
            'path' => ltrim($relative, '/'),
            'entries' => $entries,
        ];
    }
}
