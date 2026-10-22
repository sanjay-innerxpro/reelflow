<?php

namespace App\Services\Sync;

/**
 * The agent sends only folder labels and paths relative to a watched folder.
 * This rejects anything that looks like an absolute path or tries to escape.
 */
class PathSanitizer
{
    public static function relative(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) || str_contains($path, "\0")) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $parts[] = $segment;
        }

        return $parts ? implode('/', $parts) : null;
    }

    public static function label(string $label): string
    {
        $label = trim(preg_replace('/[^\pL\pN _\-\.]/u', '', $label) ?? '');

        return $label !== '' ? mb_substr($label, 0, 120) : 'Default';
    }

    public static function folderFor(string $label, string $relativePath): string
    {
        $dir = dirname($relativePath);

        return mb_substr(self::label($label).($dir !== '.' ? '/'.$dir : ''), 0, 500);
    }
}
