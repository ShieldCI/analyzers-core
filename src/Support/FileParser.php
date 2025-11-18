<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Utility for parsing and analyzing file contents.
 */
class FileParser
{
    /**
     * Extract namespace from PHP file.
     */
    public static function extractNamespace(string $filePath): ?string
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract class name from PHP file.
     */
    public static function extractClassName(string $filePath): ?string
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return null;
        }

        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get fully qualified class name from file.
     */
    public static function extractFullyQualifiedClassName(string $filePath): ?string
    {
        $namespace = self::extractNamespace($filePath);
        $className = self::extractClassName($filePath);

        if ($className === null) {
            return null;
        }

        if ($namespace === null) {
            return $className;
        }

        return "{$namespace}\\{$className}";
    }

    /**
     * Extract use statements from file.
     *
     * @return array<string>
     */
    public static function extractUseStatements(string $filePath): array
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return [];
        }

        preg_match_all('/use\s+([^;]+);/', $content, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Count lines in file.
     */
    public static function countLines(string $filePath): int
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return 0;
        }

        $lines = file($filePath);

        return $lines !== false ? count($lines) : 0;
    }

    /**
     * Get file size in bytes.
     */
    public static function getFileSize(string $filePath): int
    {
        if (! is_file($filePath)) {
            return 0;
        }

        $size = filesize($filePath);

        return $size !== false ? $size : 0;
    }

    /**
     * Read file contents.
     */
    public static function readFile(string $filePath): ?string
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);

        return $contents !== false ? $contents : null;
    }

    /**
     * Get lines from file.
     *
     * @return array<int, string>
     */
    public static function getLines(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $lines = file($filePath);

        return $lines !== false ? $lines : [];
    }

    /**
     * Get specific line from file (1-indexed).
     */
    public static function getLine(string $filePath, int $lineNumber): ?string
    {
        $lines = self::getLines($filePath);

        if (! isset($lines[$lineNumber - 1])) {
            return null;
        }

        return $lines[$lineNumber - 1];
    }

    /**
     * Get line range from file (1-indexed, inclusive).
     *
     * @return array<int, string>
     */
    public static function getLineRange(string $filePath, int $startLine, int $endLine): array
    {
        $lines = self::getLines($filePath);
        $result = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            if (isset($lines[$i - 1])) {
                $result[$i] = $lines[$i - 1];
            }
        }

        return $result;
    }

    /**
     * Check if file contains string.
     */
    public static function contains(string $filePath, string $needle): bool
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return false;
        }

        return str_contains($content, $needle);
    }

    /**
     * Check if file matches regex pattern.
     */
    public static function matches(string $filePath, string $pattern): bool
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return false;
        }

        return (bool) preg_match($pattern, $content);
    }

    /**
     * Count occurrences of string in file.
     */
    public static function countOccurrences(string $filePath, string $needle): int
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return 0;
        }

        return substr_count($content, $needle);
    }

    /**
     * Strip single-line comments from a line of code.
     * Removes // and # style comments.
     *
     * @param  string  $line
     * @return string
     */
    public static function stripComments(string $line): string
    {
        $lineWithoutComments = preg_replace('/\/\/.*$|#.*$/', '', $line);

        return is_string($lineWithoutComments) ? $lineWithoutComments : $line;
    }

    /**
     * Get code snippet from file with context lines.
     *
     * @param  string  $filePath  Path to the file
     * @param  int  $line  Target line number (1-indexed)
     * @param  int  $contextLines  Number of context lines before and after
     * @return string|null  Code snippet as string, or null if file cannot be read
     */
    public static function getCodeSnippet(string $filePath, int $line, int $contextLines = 2): ?string
    {
        $lines = self::getLines($filePath);

        if (empty($lines)) {
            return null;
        }

        $start = max(0, $line - $contextLines - 1);
        $end = min(count($lines), $line + $contextLines);

        $snippet = [];
        for ($i = $start; $i < $end; $i++) {
            if (isset($lines[$i])) {
                $snippet[] = $lines[$i];
            }
        }

        return implode('', $snippet);
    }
}
