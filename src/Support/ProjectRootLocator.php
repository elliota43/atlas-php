<?php

namespace Atlas\Support;

use RuntimeException;

class ProjectRootLocator
{

    /**
     * Finds the project root by looking for composer.json that's NOT in a vendor directory.
     *
     * @param string|null $startPath Starting directory (default: current working directory)
     * @return string|null Absolute path to project root, or null if not found.
     */
    public static function find(?string $startPath = null): ?string
    {
        $currentPath = $startPath ?? getcwd();

        if ($currentPath === false) return null;

        $currentPath = realpath($currentPath);

        while ($currentPath !== '/' && $currentPath !== false) {
            if (self::isProjectRoot($currentPath)) {
                return $currentPath;
            }

            $currentPath = self::moveUpOneDirectory($currentPath);
        }

        return null;
    }

    /**
     * Checks if the given path is the project root.
     *
     * @param string $path
     * @return bool
     */
    protected static function isProjectRoot(string $path): bool
    {
        if (! self::hasComposerJson($path)) {
            return false;
        }

        if (self::isInsideVendor($path)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if composer.json exists in the given path.
     *
     * @param string $path
     * @return bool
     */
    protected static function hasComposerJson(string $path): bool
    {
        return file_exists($path . '/composer.json');
    }

    /**
     * Checks if the path is inside a vendor directory.
     *
     * @param string $path
     * @return bool
     */
    protected static function isInsideVendor(string $path): bool
    {
        return str_contains($path, '/vendor/');
    }

    /**
     * Moves the path up one directory level to the parent dir.
     *
     * @param string $currentPath
     * @return string|false
     */
    protected static function moveUpOneDirectory(string $currentPath): string|false
    {
        $parentPath = dirname($currentPath);

        if ($parentPath === $currentPath) return false;

        return $parentPath;
    }
}