<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

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

        // If basePath is empty, try fallback
        if (empty($basePath) && $fallback !== null) {
            $result = $fallback($file.'.php');
            if (is_string($result)) {
                return $result;
            }
        }

        // Construct path from basePath
        if (! empty($basePath)) {
            return rtrim($basePath, '/').'/config/'.$file.'.php';
        }

        // Last resort: relative path
        return 'config/'.$file.'.php';
    }

    /**
     * Find the line number where a specific key is defined in a config file.
     * Uses precise patterns to avoid matches in comments.
     *
     * @param  string  $configFile  Full path to the config file
     * @param  string  $key  The key to find (e.g., 'default', 'prefix')
     * @param  string|null  $parentKey  Optional parent key to search within (e.g., 'connections', 'stores')
     * @return int  Line number (1-indexed), or 1 if not found
     */
    public static function findKeyLine(string $configFile, string $key, ?string $parentKey = null): int
    {
        $lines = FileParser::getLines($configFile);

        if (empty($lines)) {
            return 1;
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

        return 1;
    }

    /**
     * Find the line number where a nested key is defined within a parent array.
     * For example, find 'driver' within a specific 'store' in the 'stores' array.
     *
     * @param  string  $configFile  Full path to the config file
     * @param  string  $parentKey  Parent array key (e.g., 'stores', 'connections')
     * @param  string  $nestedKey  Nested key to find (e.g., 'driver')
     * @param  string  $nestedValue  Value of the parent item to search within (e.g., 'redis', 'mysql')
     * @return int  Line number (1-indexed), or 1 if not found
     */
    public static function findNestedKeyLine(string $configFile, string $parentKey, string $nestedKey, string $nestedValue): int
    {
        $lines = FileParser::getLines($configFile);

        if (empty($lines)) {
            return 1;
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
        return self::findKeyLine($configFile, $parentKey);
    }
}
