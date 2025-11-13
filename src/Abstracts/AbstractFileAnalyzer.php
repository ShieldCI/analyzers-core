<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Abstracts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Base class for analyzers that scan files.
 * Provides file filtering and iteration utilities.
 */
abstract class AbstractFileAnalyzer extends AbstractAnalyzer
{
    /**
     * Base path for analysis.
     */
    protected string $basePath = '';

    /**
     * Paths to analyze.
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * Patterns to exclude.
     *
     * @var array<string>
     */
    protected array $excludePatterns = [];

    /**
     * Set the base path.
     */
    public function setBasePath(string $path): static
    {
        $this->basePath = rtrim($path, '/');

        return $this;
    }

    /**
     * Set paths to analyze.
     *
     * @param array<string> $paths
     */
    public function setPaths(array $paths): static
    {
        $this->paths = $paths;

        return $this;
    }

    /**
     * Set exclude patterns.
     *
     * @param array<string> $patterns
     */
    public function setExcludePatterns(array $patterns): static
    {
        $this->excludePatterns = $patterns;

        return $this;
    }

    /**
     * Get all PHP files to analyze.
     *
     * @return array<string>
     */
    protected function getPhpFiles(): array
    {
        $files = [];

        foreach ($this->getFilesToAnalyze() as $file) {
            if ($this->shouldAnalyzeFile($file)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get files to analyze based on configured paths.
     *
     * @return iterable<SplFileInfo>
     */
    protected function getFilesToAnalyze(): iterable
    {
        if (empty($this->paths)) {
            $this->paths = [$this->basePath];
        }

        foreach ($this->paths as $path) {
            $fullPath = $this->basePath ? "{$this->basePath}/{$path}" : $path;

            if (! is_dir($fullPath)) {
                if (is_file($fullPath)) {
                    yield new SplFileInfo($fullPath);
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Check if a file should be analyzed.
     */
    protected function shouldAnalyzeFile(SplFileInfo $file): bool
    {
        // Only PHP files
        if ($file->getExtension() !== 'php') {
            return false;
        }

        $path = $file->getPathname();

        // Check against exclude patterns
        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if path matches a glob pattern.
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Convert glob pattern to regex
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);

        return (bool) preg_match("#^{$pattern}$#", $path);
    }

    /**
     * Get code snippet from file.
     */
    protected function getCodeSnippet(string $file, int $line, int $contextLines = 2): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $start = max(0, $line - $contextLines - 1);
        $end = min(count($lines), $line + $contextLines);

        return implode('', array_slice($lines, $start, $end - $start));
    }

    /**
     * Read file contents safely.
     */
    protected function readFile(string $file): ?string
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents !== false ? $contents : null;
    }

    /**
     * Get relative path from base path.
     */
    protected function getRelativePath(string $file): string
    {
        if (empty($this->basePath)) {
            return $file;
        }

        $basePath = $this->basePath . '/';
        if (str_starts_with($file, $basePath)) {
            return substr($file, strlen($basePath));
        }

        return $file;
    }

    /**
     * Get the current environment using runtime config (Laravel).
     * Falls back to reading .env file if config is not available.
     *
     * This method prioritizes .env file when basePath is set (test scenarios),
     * otherwise uses Laravel's config() helper (more accurate in production).
     *
     * @return string The environment name (e.g., 'local', 'production', 'staging')
     */
    protected function getEnvironment(): string
    {
        // Priority 1: Read from .env file if basePath is set (test scenarios or explicit path)
        if (! empty($this->basePath)) {
            $envFile = $this->basePath.'/.env';
            if (file_exists($envFile)) {
                $content = file_get_contents($envFile);
                if ($content !== false && preg_match('/^APP_ENV\s*=\s*(\w+)/m', $content, $matches)) {
                    return $matches[1];
                }
            }
        }

        // Priority 2: Use runtime config (standard way, more accurate) - Laravel context
        if (function_exists('config')) {
            $env = config('app.env');
            if (is_string($env) && $env !== '') {
                return $env;
            }
        }

        // Fallback: Default to production
        return 'production';
    }

    /**
     * Determine whether the analyzer should skip if the environment is local.
     *
     * This method checks both the environment and user configuration,
     * allowing users to control whether to skip environment-specific checks.
     *
     * Returns true (skip analyzer) when BOTH conditions are met:
     * 1. Environment is 'local'
     * 2. User has enabled skipping via config('shieldci.skip_env_specific', false)
     *
     * @return bool True if analyzer should be skipped in local environment
     */
    protected function isLocalAndShouldSkip(): bool
    {
        // Check if environment is local
        $isLocal = $this->getEnvironment() === 'local';

        // Check if user has enabled skipping (default: false = don't skip)
        $skipEnabled = false;
        if (function_exists('config')) {
            $skipEnabled = config('shieldci.skip_env_specific', false);
            $skipEnabled = is_bool($skipEnabled) ? $skipEnabled : false;
        }

        return $isLocal && $skipEnabled;
    }
}
