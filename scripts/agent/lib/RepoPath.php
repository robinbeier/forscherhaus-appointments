<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

final class RepoPath
{
    public static function isNormalized(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return false;
        }

        if (
            str_contains($path, '\\') ||
            preg_match('/[*?\[\]]/', $path) === 1 ||
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
