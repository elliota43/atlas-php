<?php

namespace SchemaOps\Schema\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ClassFinder
{
    /**
     * Find all classes with the Table attribute
     */
    public function findInDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];

        foreach ($this->phpFiles($directory) as $file) {
            if (! $this->hasTableAttribute($file)) {
                continue;
            }

            if ($className = $this->extractClassNameFromFile($file)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Get all PHP files in the directory recursively.
     */
    protected function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($this->isPhpFile($file)) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Check if the file is a PHP file.
     */
    protected function isPhpFile(SplFileInfo $file): bool
    {
        return $file->isFile() && $file->getExtension() === 'php';
    }

    /**
     * Check if the file contains a Table attribute.
     */
    protected function hasTableAttribute(SplFileInfo $file): bool
    {
        $content = file_get_contents($file->getPathname());

        return str_contains($content, '#[Table]')
            || str_contains($content, 'SchemaOps\Attributes\Table');
    }

    /**
     * Extract the fully qualified class name from a file.
     */
    protected function extractClassNameFromFile(SplFileInfo $file): ?string
    {
        $content = file_get_contents($file->getPathname());

        return $this->extractClassName($content);
    }

    /**
     * Extract the fully qualified class name from PHP content.
     */
    protected function extractClassName(string $content): ?string
    {
        $tokens = token_get_all($content);

        $namespace = $this->extractNamespace($tokens);
        $class = $this->extractClass($tokens);

        if (! $class) {
            return null;
        }

        return $namespace ? "{$namespace}\\{$class}" : $class;
    }

    /**
     * Extract namespace from tokens.
     */
    protected function extractNamespace(array $tokens): string
    {
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            // Look ahead for the namespace name
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (! is_array($tokens[$j])) {
                    break;
                }
                if ($tokens[$j][0] === T_NAME_QUALIFIED || $tokens[$j][0] === T_STRING) {
                    return $tokens[$j][1];
                }
            }
        }

        return '';
    }

    /**
     * Extract class name from tokens
     */
    protected function extractClass(array $tokens): string
    {
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            // look ahead for the class name
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $nextToken = $tokens[$j];

                // skip whitespace
                if (is_array($nextToken) && $nextToken[0] === T_WHITESPACE) {
                    continue;
                }

                // Anonymous classes - skip
                if ($nextToken === '{') {
                    break;
                }

                // found class name
                if (is_array($nextToken) && $nextToken[0] === T_STRING) {
                    return $nextToken[1];
                }
            }
        }

        return '';
    }
}