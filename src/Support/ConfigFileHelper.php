<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use ShieldCI\AnalyzersCore\ValueObjects\Location;

/**
 * Helper utilities for working with Laravel configuration files.
 */
class ConfigFileHelper
{
    /**
     * Get the path to a config file.
     *
     * @param  string  $basePath  Base path of the application
     * @param  string  $file  Config file name (with or without .php extension)
     * @param  callable|null  $fallback  Optional fallback function to get config path (e.g., config_path())
     * @return string
     */
    public static function getConfigPath(string $basePath, string $file, ?callable $fallback = null): string
    {
        // Remove .php extension if present
        $file = preg_replace('/\.php$/', '', $file);

        // If basePath is empty, try fallback. Compared against '' rather than
        // empty(), which reads a base path of '0' as no base path at all.
        if ($basePath === '' && $fallback !== null) {
            $result = $fallback($file.'.php');
            if (is_string($result)) {
                return $result;
            }
        }

        // Construct path from basePath. PathHelper::join() rather than a local
        // rtrim($basePath, '/'), which left a trailing backslash on a Windows
        // base path and answered 'C:\app\/config/cache.php'.
        if ($basePath !== '') {
            return PathHelper::join($basePath, 'config/'.$file.'.php');
        }

        // Last resort: relative path
        return 'config/'.$file.'.php';
    }

    /**
     * Locate a key inside a config file, or report no location when the file is not published.
     *
     * Laravel merges the framework's own config when an application has not published its
     * own copy, so a correct config() value never implies the file is on disk. This tells
     * apart the three cases findKeyLine() collapses onto line 1:
     *
     *  - the file is not there at all           -> null
     *  - the file is there and holds the key    -> relative path plus the real line
     *  - the file is there without the key      -> relative path, no line
     *
     * The config_path() fallback that getConfigPath() accepts is deliberately not exposed
     * here. It is only consulted for an empty base path, which the analyzer base classes
     * cannot produce, and calling it would put a framework global into this package.
     * Callers that genuinely need it should keep using getConfigPath() directly.
     *
     * @param  string  $basePath  Absolute path to the application root
     * @param  string  $file  Config file name, with or without the .php extension
     * @param  string  $key  The key to locate (e.g., 'default', 'mysql')
     * @param  string|null  $parentKey  Optional parent array to search within (e.g., 'connections')
     */
    public static function locateConfigKey(
        string $basePath,
        string $file,
        string $key,
        ?string $parentKey = null
    ): ?Location {
        $configFile = self::getConfigPath($basePath, $file);

        if (! is_file($configFile)) {
            return null;
        }

        // getConfigPath() builds "<basePath>/config/<file>.php", so the relative form is
        // the same call with no base path rather than something to strip back off.
        return new Location(
            self::getConfigPath('', $file),
            self::findKeyLineOrNull($configFile, $key, $parentKey)
        );
    }

    /**
     * Locate a key nested inside a named item of a config array, or report no location
     * when the file is not published.
     *
     * The locateConfigKey() of findNestedKeyLine(): same three-way answer, but for the
     * "find 'driver' inside stores.redis" search rather than "find 'driver' after the
     * first 'redis' key". The distinction matters because findKeyLine()'s parent scope
     * only ends at a top-level key, so on a store that does not carry the key it walks
     * into the next store and answers with its line.
     *
     * Argument order mirrors findNestedKeyLine() deliberately. A second ordering for the
     * same search is how a call gets written the wrong way round.
     *
     * @param  string  $basePath  Absolute path to the application root
     * @param  string  $file  Config file name, with or without the .php extension
     * @param  string  $parentKey  Parent array key (e.g., 'stores', 'connections')
     * @param  string  $nestedKey  Nested key to locate (e.g., 'driver')
     * @param  string  $nestedValue  Name of the parent item to search within (e.g., 'redis')
     */
    public static function locateNestedConfigKey(
        string $basePath,
        string $file,
        string $parentKey,
        string $nestedKey,
        string $nestedValue
    ): ?Location {
        $configFile = self::getConfigPath($basePath, $file);

        if (! is_file($configFile)) {
            return null;
        }

        return new Location(
            self::getConfigPath('', $file),
            self::findNestedKeyLineOrNull($configFile, $parentKey, $nestedKey, $nestedValue)
        );
    }

    /**
     * Find the line number where a specific key is defined in a config file.
     * Uses precise patterns to avoid matches in comments.
     *
     * Answers 1 both when the file cannot be read and when the key is absent.
     * Use locateConfigKey() when those two need telling apart.
     *
     * @param  string  $configFile  Full path to the config file
     * @param  string  $key  The key to find (e.g., 'default', 'prefix')
     * @param  string|null  $parentKey  Optional parent key to search within (e.g., 'connections', 'stores')
     * @return int  Line number (1-indexed), or 1 if not found
     */
    public static function findKeyLine(string $configFile, string $key, ?string $parentKey = null): int
    {
        return self::findKeyLineOrNull($configFile, $key, $parentKey) ?? 1;
    }

    /**
     * Find the line of a key, or null when the file cannot be read or the key is not there.
     *
     * findKeyLine() collapses both of those onto 1, which callers cannot tell from a key
     * genuinely on line 1. Keeping the honest answer here lets locateConfigKey() report a
     * file without a line instead of inventing one.
     */
    private static function findKeyLineOrNull(string $configFile, string $key, ?string $parentKey = null): ?int
    {
        $lines = FileParser::getLines($configFile);

        if (empty($lines)) {
            return null;
        }

        $inParentArray = $parentKey === null;

        foreach ($lines as $lineNumber => $line) {
            // Strip single-line comments (// and #)
            $lineWithoutComments = FileParser::stripComments($line);

            // If we have a parent key, detect when we enter that array
            if ($parentKey !== null && ! $inParentArray) {
                $parentPattern = '/[\'"](?:'.preg_quote($parentKey, '/').')[\'"]\s*=>/';
                $match = preg_match($parentPattern, $lineWithoutComments);
                if (is_int($match) && $match === 1) {
                    $inParentArray = true;

                    continue;
                }
            }

            // Only search within the parent array if specified
            if (! $inParentArray) {
                continue;
            }

            // Look for array key pattern: 'key' => or "key" => or 'key'=> (with optional spaces)
            // This ensures we match actual array keys, not strings in comments
            $pattern = '/[\'"](?:'.preg_quote($key, '/').')[\'"]\s*=>/';
            $match = preg_match($pattern, $lineWithoutComments);
            if (is_int($match) && $match === 1) {
                return $lineNumber + 1;
            }

            // If we have a parent key and hit another top-level key, we've left the parent array
            if ($parentKey !== null) {
                // Check if this is a top-level key (not nested)
                $topLevelPattern = '/^\s*[\'"][a-zA-Z_][a-zA-Z0-9_]*[\'"]\s*=>/';
                $match = preg_match($topLevelPattern, $lineWithoutComments);
                if (is_int($match) && $match === 1) {
                    // Verify it's not the parent key or the target key
                    if (! preg_match('/^\s*[\'"](?:'.preg_quote($parentKey, '/').'|'.preg_quote($key, '/').')[\'"]\s*=>/', $lineWithoutComments)) {
                        // Check indentation level (top-level keys are usually at column 0-4)
                        $indentLevel = strlen($line) - strlen(ltrim($line));
                        if ($indentLevel <= 4) {
                            break;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find the line number where a nested key is defined within a parent array.
     * For example, find 'driver' within a specific 'store' in the 'stores' array.
     *
     * Answers 1 both when the file cannot be read and when the parent key is absent.
     * Use locateNestedConfigKey() when those two need telling apart.
     *
     * @param  string  $configFile  Full path to the config file
     * @param  string  $parentKey  Parent array key (e.g., 'stores', 'connections')
     * @param  string  $nestedKey  Nested key to find (e.g., 'driver')
     * @param  string  $nestedValue  Value of the parent item to search within (e.g., 'redis', 'mysql')
     * @return int  Line number (1-indexed), or 1 if not found
     */
    public static function findNestedKeyLine(string $configFile, string $parentKey, string $nestedKey, string $nestedValue): int
    {
        return self::findNestedKeyLineOrNull($configFile, $parentKey, $nestedKey, $nestedValue) ?? 1;
    }

    /**
     * Find the line of a nested key, or null when the file cannot be read or the parent
     * key is not in it.
     *
     * The honest twin of findNestedKeyLine(), which collapses both onto 1. A parent array
     * that simply lacks the named item is not one of those cases: that still answers the
     * parent's own line, which is a real place to look.
     */
    private static function findNestedKeyLineOrNull(string $configFile, string $parentKey, string $nestedKey, string $nestedValue): ?int
    {
        $lines = FileParser::getLines($configFile);

        if (empty($lines)) {
            return null;
        }

        $inParentArray = false;
        $inNestedArray = false;
        $nestedArrayStartLine = 1; // Default to line 1 (never 0, as line numbers are 1-indexed)

        foreach ($lines as $lineNumber => $line) {
            // Strip single-line comments
            $lineWithoutComments = FileParser::stripComments($line);

            // Detect when we enter the parent array
            if (! $inParentArray) {
                $parentPattern = '/[\'"](?:'.preg_quote($parentKey, '/').')[\'"]\s*=>/';
                $match = preg_match($parentPattern, $lineWithoutComments);
                if (is_int($match) && $match === 1) {
                    $inParentArray = true;

                    continue;
                }
            }

            if (! $inParentArray) {
                continue;
            }

            // Look for the nested value as an array key (e.g., 'redis' => [)
            if (! $inNestedArray) {
                $nestedPattern = '/[\'"](?:'.preg_quote($nestedValue, '/').')[\'"]\s*=>\s*\[/';
                $match = preg_match($nestedPattern, $lineWithoutComments);
                if (is_int($match) && $match === 1) {
                    $inNestedArray = true;
                    $nestedArrayStartLine = $lineNumber + 1;

                    // Continue to search for the nested key within this array
                    continue;
                }
            }

            if ($inNestedArray) {
                // Stop if we hit the next nested array (different store) or closing bracket
                $nextNestedPattern = '/[\'"][a-zA-Z_][a-zA-Z0-9_]*[\'"]\s*=>\s*\[/';
                $nextNestedMatch = preg_match($nextNestedPattern, $lineWithoutComments);
                $closingBracketMatch = preg_match('/^\s*\]/', $lineWithoutComments);

                if (($nextNestedMatch === 1 && $lineNumber >= $nestedArrayStartLine) || $closingBracketMatch === 1) {
                    // If we haven't found the nested key yet, return the nested array start line
                    return $nestedArrayStartLine;
                }

                // Look for the nested key (e.g., 'driver' =>)
                $nestedKeyPattern = '/[\'"](?:'.preg_quote($nestedKey, '/').')[\'"]\s*=>/';
                $match = preg_match($nestedKeyPattern, $lineWithoutComments);
                if (is_int($match) && $match === 1) {
                    return $lineNumber + 1;
                }
            }

            // If we hit another top-level key, we've left the parent array
            $topLevelPattern = '/^\s*[\'"][a-zA-Z_][a-zA-Z0-9_]*[\'"]\s*=>/';
            $match = preg_match($topLevelPattern, $lineWithoutComments);
            if (is_int($match) && $match === 1) {
                $indentLevel = strlen($line) - strlen(ltrim($line));
                if ($indentLevel <= 4) {
                    break;
                }
            }
        }

        // Fallback: try to find the parent key
        return self::findKeyLineOrNull($configFile, $parentKey);
    }

    /**
     * Find the line of a direct child key within a named top-level array, using an AST parse.
     *
     * @deprecated 2.8.0 Builds a throwaway parser, so a file that would not parse is
     *                   indistinguishable from a key that is absent. Use
     *                   ConfigFileParser::findNestedArrayKeyLine() with a parser you keep.
     *
     * @param  string  $filePath  Full path to the config file
     * @param  string  $parentKey  Top-level array key to search within (e.g. 'channels')
     * @param  string  $childKey  Direct child key to locate (e.g. a channel name)
     * @return int|null 1-indexed line number, or null when absent / unparseable
     */
    public static function findNestedArrayKeyLine(string $filePath, string $parentKey, string $childKey): ?int
    {
        return (new ConfigFileParser(new AstParser()))
            ->findNestedArrayKeyLine($filePath, $parentKey, $childKey);
    }

    /**
     * Parse a PHP config file that returns an array and extract top-level string key-value pairs.
     *
     * @deprecated 2.8.0 Builds a throwaway parser, so a file that would not parse is
     *                   indistinguishable from a config that defines no keys. Use
     *                   ConfigFileParser::parseArray() with a parser you keep.
     *
     * @return array<string, array{value: mixed, line: int, isEnvCall: bool, envDefault: mixed, envHasDefault: bool}>
     */
    public static function parseConfigArray(string $filePath): array
    {
        return (new ConfigFileParser(new AstParser()))->parseArray($filePath);
    }
}
