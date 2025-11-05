<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Helper utilities for code analysis.
 */
class CodeHelper
{
    /**
     * Calculate cyclomatic complexity of code.
     */
    public static function calculateComplexity(string $code): int
    {
        $complexity = 1; // Base complexity

        // Count decision points
        $patterns = [
            '/\bif\s*\(/i',
            '/\belse\s*if\s*\(/i',
            '/\belseif\s*\(/i',
            '/\bwhile\s*\(/i',
            '/\bfor\s*\(/i',
            '/\bforeach\s*\(/i',
            '/\bcase\s+/i',
            '/\bcatch\s*\(/i',
            '/\?\?/',  // Null coalescing
            '/\?.*:/', // Ternary
            '/&&/',
            '/\|\|/',
        ];

        foreach ($patterns as $pattern) {
            $complexity += preg_match_all($pattern, $code);
        }

        return $complexity;
    }

    /**
     * Check if code contains dangerous functions.
     *
     * @return array<string>
     */
    public static function findDangerousFunctions(string $code): array
    {
        $dangerous = [
            'eval', 'exec', 'system', 'passthru', 'shell_exec',
            'assert', 'create_function', 'unserialize',
            'file_get_contents', 'file_put_contents', 'fopen',
            'curl_exec', 'popen', 'proc_open',
        ];

        $found = [];

        foreach ($dangerous as $function) {
            $pattern = '/\b' . preg_quote($function, '/') . '\s*\(/i';
            if (preg_match($pattern, $code)) {
                $found[] = $function;
            }
        }

        return $found;
    }

    /**
     * Check if string is potentially a SQL query.
     */
    public static function looksLikeSql(string $string): bool
    {
        $sqlKeywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FROM', 'WHERE', 'JOIN'];

        $upperString = strtoupper($string);

        foreach ($sqlKeywords as $keyword) {
            if (str_contains($upperString, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract string literals from code.
     *
     * @return array<string>
     */
    public static function extractStringLiterals(string $code): array
    {
        $strings = [];

        // Single quoted strings
        preg_match_all("/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", $code, $matches);
        $strings = array_merge($strings, $matches[1]);

        // Double quoted strings
        preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/', $code, $matches);
        $strings = array_merge($strings, $matches[1]);

        return $strings;
    }

    /**
     * Check if variable name follows naming convention.
     */
    public static function isValidVariableName(string $name, string $convention = 'camelCase'): bool
    {
        return match ($convention) {
            'camelCase' => (bool) preg_match('/^[a-z][a-zA-Z0-9]*$/', $name),
            'snake_case' => (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name),
            'PascalCase' => (bool) preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name),
            default => false,
        };
    }

    /**
     * Check if class name follows naming convention (PascalCase).
     */
    public static function isValidClassName(string $name): bool
    {
        return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name);
    }

    /**
     * Check if method name follows naming convention (camelCase).
     */
    public static function isValidMethodName(string $name): bool
    {
        // Allow magic methods
        if (str_starts_with($name, '__')) {
            return true;
        }

        return (bool) preg_match('/^[a-z][a-zA-Z0-9]*$/', $name);
    }

    /**
     * Calculate code similarity using Levenshtein distance.
     */
    public static function calculateSimilarity(string $code1, string $code2): float
    {
        // Normalize code
        $code1 = self::normalizeCode($code1);
        $code2 = self::normalizeCode($code2);

        $maxLength = max(strlen($code1), strlen($code2));
        if ($maxLength === 0) {
            return 100.0;
        }

        $distance = levenshtein($code1, $code2);

        return round((1 - ($distance / $maxLength)) * 100, 2);
    }

    /**
     * Normalize code for comparison.
     */
    public static function normalizeCode(string $code): string
    {
        // Remove comments
        $code = preg_replace('/\/\*[\s\S]*?\*\//', '', $code) ?? $code;
        $code = preg_replace('/\/\/.*$/m', '', $code) ?? $code;

        // Remove extra whitespace
        $code = preg_replace('/\s+/', ' ', $code) ?? $code;

        return trim($code);
    }

    /**
     * Count TODO/FIXME comments.
     */
    public static function countTodoComments(string $code): int
    {
        return preg_match_all('/@?(TODO|FIXME|HACK|XXX|NOTE)/i', $code);
    }

    /**
     * Extract PHPDoc comments.
     *
     * @return array<string>
     */
    public static function extractPhpDocComments(string $code): array
    {
        preg_match_all('/\/\*\*(.*?)\*\//s', $code, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Check if code has proper PHPDoc.
     */
    public static function hasPhpDoc(string $code, string $elementType = 'method'): bool
    {
        $pattern = match ($elementType) {
            'class' => '/\/\*\*.*?\*\/\s*class\s+\w+/s',
            'method' => '/\/\*\*.*?\*\/\s*(?:public|protected|private)?\s*function\s+\w+/s',
            'property' => '/\/\*\*.*?\*\/\s*(?:public|protected|private)?\s*\$\w+/s',
            default => '/\/\*\*.*?\*\//s',
        };

        return (bool) preg_match($pattern, $code);
    }

    /**
     * Extract method parameters.
     *
     * @return array<string>
     */
    public static function extractMethodParameters(string $methodSignature): array
    {
        if (! preg_match('/\((.*?)\)/', $methodSignature, $matches)) {
            return [];
        }

        $params = $matches[1];
        if (empty($params)) {
            return [];
        }

        $params = explode(',', $params);

        return array_map('trim', $params);
    }

    /**
     * Count method parameters.
     */
    public static function countMethodParameters(string $methodSignature): int
    {
        return count(self::extractMethodParameters($methodSignature));
    }
}
