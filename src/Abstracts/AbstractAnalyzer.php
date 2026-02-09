<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Abstracts;

use ShieldCI\AnalyzersCore\Contracts\{AnalyzerInterface, ResultInterface};
use ShieldCI\AnalyzersCore\Enums\Severity;
use ShieldCI\AnalyzersCore\Results\AnalysisResult;
use ShieldCI\AnalyzersCore\ValueObjects\{AnalyzerMetadata, CodeSnippet, Issue, Location};
use Throwable;

/**
 * Base class for all analyzers.
 * Provides common functionality like timing, error handling, and helper methods.
 */
abstract class AbstractAnalyzer implements AnalyzerInterface
{
    /**
     * Determine whether the analyzer should be run in CI mode.
     *
     * Analyzers that check runtime state (caching, database connections, etc.)
     * should set this to false since they're not applicable in CI environments.
     *
     * @var bool
     */
    public static bool $runInCI = true;

    /**
     * Environments where this analyzer is relevant.
     *
     * Override in child classes to specify which environments this analyzer should run in.
     * Default is all environments (null = no environment filtering).
     *
     * Use standard environment names only:
     * - 'local': Local development (developer machines)
     * - 'development': Development server environment
     * - 'staging': Pre-production/staging environment
     * - 'production': Live production environment
     * - 'testing': Automated testing environment (PHPUnit, CI/CD tests)
     *
     * Custom environment names are automatically mapped via config/shieldci.php:
     * - APP_ENV=production-us → Maps to 'production'
     * - APP_ENV=staging-preview → Maps to 'staging'
     *
     * Examples:
     * - ['production', 'staging'] - Production and staging environments only
     * - ['production'] - Production environments only
     * - ['local', 'development', 'testing'] - Development environments only
     * - null - Run in all environments (default)
     *
     * How environment mapping works:
     * 1. Analyzer declares: protected ?array $relevantEnvironments = ['production', 'staging'];
     * 2. User configures mapping: 'production-us' => 'production'
     * 3. When APP_ENV=production-us, it maps to 'production' and analyzer runs
     * 4. When APP_ENV=local, no mapping, analyzer skips
     *
     * @var array<string>|null
     */
    protected ?array $relevantEnvironments = null;

    /**
     * Optional ConfigRepository for testability.
     * Child classes that inject ConfigRepository should set this property
     * in their constructor to enable testable environment detection.
     *
     * This property is intentionally untyped to avoid Laravel dependencies
     * in the core package. In Laravel implementations, this will be an
     * instance of \Illuminate\Contracts\Config\Repository.
     *
     * @var mixed
     */
    protected $configRepository = null;

    private float $startTime = 0.0;

    /**
     * Run the actual analysis logic.
     * This method should be implemented by concrete analyzers.
     */
    abstract protected function runAnalysis(): ResultInterface;

    /**
     * Define analyzer metadata.
     * This method should be implemented by concrete analyzers.
     */
    abstract protected function metadata(): AnalyzerMetadata;

    /**
     * Run the analysis with timing and error handling.
     */
    final public function analyze(): ResultInterface
    {
        if (! $this->shouldRun()) {
            $reason = method_exists($this, 'getSkipReason')
                ? $this->getSkipReason()
                : 'Analyzer is not enabled or not applicable in current context'; // @codeCoverageIgnore

            return $this->skipped($reason);
        }

        $this->startTimer();

        try {
            $result = $this->runAnalysis();

            return new AnalysisResult(
                analyzerId: $result->getAnalyzerId(),
                status: $result->getStatus(),
                message: $result->getMessage(),
                issues: $result->getIssues(),
                executionTime: $this->getExecutionTime(),
                metadata: $result->getMetadata(),
            );
        } catch (Throwable $e) {
            return $this->error(
                "Analysis failed: {$e->getMessage()}",
                ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
            );
        }
    }

    public function getMetadata(): AnalyzerMetadata
    {
        return $this->metadata();
    }

    public function getId(): string
    {
        return $this->getMetadata()->id;
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function getSkipReason(): string
    {
        return 'Not applicable in current environment or configuration';
    }

    /**
     * Create a passed result.
     *
     * @param array<string, mixed> $metadata
     */
    protected function passed(string $message, array $metadata = []): ResultInterface
    {
        return AnalysisResult::passed(
            $this->getId(),
            $message,
            $this->getExecutionTime(),
            $metadata
        );
    }

    /**
     * Create a failed result.
     *
     * @param array<Issue> $issues
     * @param array<string, mixed> $metadata
     */
    protected function failed(string $message, array $issues = [], array $metadata = []): ResultInterface
    {
        return AnalysisResult::failed(
            $this->getId(),
            $message,
            $issues,
            $this->getExecutionTime(),
            $metadata
        );
    }

    /**
     * Create a warning result.
     *
     * @param array<Issue> $issues
     * @param array<string, mixed> $metadata
     */
    protected function warning(string $message, array $issues = [], array $metadata = []): ResultInterface
    {
        return AnalysisResult::warning(
            $this->getId(),
            $message,
            $issues,
            $this->getExecutionTime(),
            $metadata
        );
    }

    /**
     * Create a skipped result.
     *
     * @param array<string, mixed> $metadata
     */
    protected function skipped(string $message, array $metadata = []): ResultInterface
    {
        return AnalysisResult::skipped(
            $this->getId(),
            $message,
            $this->getExecutionTime(),
            $metadata
        );
    }

    /**
     * Create an error result.
     *
     * @param array<string, mixed> $metadata
     */
    protected function error(string $message, array $metadata = []): ResultInterface
    {
        return AnalysisResult::error(
            $this->getId(),
            $message,
            $this->getExecutionTime(),
            $metadata
        );
    }

    /**
     * Create a result based on issue severity levels.
     *
     * This helper method standardizes the pattern of returning different result types
     * based on the severity of issues found during analysis:
     * - Returns failed() for High or Critical severity issues
     * - Returns warning() for Medium or Low severity issues
     * - Returns passed() when no issues are found
     *
     * This ensures consistent severity handling across all analyzers and prevents
     * critical issues from being downgraded to warnings.
     *
     * @param string $message Summary message describing the issues found
     * @param array<Issue> $issues Array of issues found during analysis
     * @param array<string, mixed> $metadata Optional metadata to include in the result
     * @return ResultInterface The appropriate result type based on issue severity
     */
    protected function resultBySeverity(string $message, array $issues, array $metadata = []): ResultInterface
    {
        if (empty($issues)) {
            return $this->passed($message, $metadata);
        }

        // Check if any issue has High or Critical severity
        $hasHighSeverityIssue = false;
        foreach ($issues as $issue) {
            if ($issue->severity->level() >= Severity::High->level()) {
                $hasHighSeverityIssue = true;

                break;
            }
        }

        // Return failed for High/Critical issues, warning for Low/Medium issues
        if ($hasHighSeverityIssue) {
            return $this->failed($message, $issues, $metadata);
        }

        return $this->warning($message, $issues, $metadata);
    }

    /**
     * Create an issue.
     *
     * @param array<string, mixed> $metadata
     */
    protected function createIssue(
        string $message,
        ?Location $location,
        Severity $severity,
        string $recommendation,
        ?string $code = null,
        array $metadata = []
    ): Issue {
        return new Issue(
            message: $message,
            location: $location,
            severity: $severity,
            recommendation: $recommendation,
            code: $code,
            metadata: $metadata
        );
    }

    /**
     * Create an issue with code snippet.
     *
     * This helper method creates an issue and automatically generates a code snippet
     * from the file if code snippets are enabled in the configuration.
     *
     * Note: This method always creates issues WITH a location (file + line).
     * For application-wide issues without a file, use createIssue() with null location.
     *
     * @param string $message The issue message
     * @param string $filePath Absolute path to the file
     * @param int|null $lineNumber The line number where the issue occurs
     * @param Severity $severity The severity level
     * @param string $recommendation How to fix the issue
     * @param int|null $column Optional column number
     * @param int|null $contextLines Number of lines to show before/after (null = use config default)
     * @param string|null $code Optional error code
     * @param array<string, mixed> $metadata Additional metadata
     * @return Issue
     */
    protected function createIssueWithSnippet(
        string $message,
        string $filePath,
        ?int $lineNumber,
        Severity $severity,
        string $recommendation,
        ?int $column = null,
        ?int $contextLines = null,
        ?string $code = null,
        array $metadata = []
    ): Issue {
        $location = new Location(
            file: $this->getRelativePath($filePath),
            line: $lineNumber,
            column: $column
        );

        // Only generate code snippet if enabled in config
        $codeSnippet = null;
        if (function_exists('config') && ! is_null($lineNumber)) {
            try {
                $showSnippets = config('shieldci.report.show_code_snippets', true);
                if ($showSnippets) {
                    $configLines = config('shieldci.report.snippet_context_lines', 8);
                    $snippetLines = $contextLines ?? (is_numeric($configLines) ? (int) $configLines : 8);
                    $codeSnippet = CodeSnippet::fromFile($filePath, $lineNumber, $snippetLines);
                }
            } catch (\Throwable $e) { // @codeCoverageIgnore
                // Silently fail if code snippet generation fails
                // This prevents analyzer errors from breaking the analysis
                $codeSnippet = null; // @codeCoverageIgnore
            }
        }

        return new Issue(
            message: $message,
            location: $location,
            severity: $severity,
            recommendation: $recommendation,
            code: $code,
            metadata: $metadata,
            codeSnippet: $codeSnippet
        );
    }

    /**
     * Start the execution timer.
     */
    private function startTimer(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * Get execution time in seconds.
     */
    protected function getExecutionTime(): float
    {
        if ($this->startTime === 0.0) {
            return 0.0;
        }

        return round(microtime(true) - $this->startTime, 4);
    }

    /**
     * Get the base path of the application.
     * Used for constructing config file paths and other file operations.
     *
     * This method attempts to get the base path using Laravel's base_path()
     * helper function, falling back to getcwd() if the helper is not available.
     *
     * Changed from returning empty string to '.' (current directory) as final fallback
     * to prevent invalid paths like '/vendor/autoload.php' when path is concatenated.
     *
     * @return string The base path of the application, never empty (minimum '.')
     */
    protected function getBasePath(): string
    {
        if (function_exists('base_path')) {
            $basePathResult = base_path();
            if (is_string($basePathResult) && $basePathResult !== '') {
                return $basePathResult;
            }
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            return $cwd;
        }

        // Final fallback: current directory (safer than empty string)
        return '.'; // @codeCoverageIgnore
    }

    /**
     * Build a file path from segments using proper directory separator.
     *
     * This helper method constructs paths in a cross-platform compatible way
     * using DIRECTORY_SEPARATOR and ensures a valid base path.
     *
     * Examples:
     * - $this->buildPath('vendor', 'autoload.php') → '/path/to/project/vendor/autoload.php'
     * - $this->buildPath('config', 'app.php') → '/path/to/project/config/app.php'
     * - $this->buildPath() → '/path/to/project' (base path only)
     *
     * @param  string  ...$segments  Path segments to join
     * @return string The constructed absolute path
     */
    protected function buildPath(string ...$segments): string
    {
        $basePath = $this->getBasePath();

        if (empty($segments)) {
            return $basePath;
        }

        return $basePath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Get the current environment using Laravel's config helper or injected ConfigRepository.
     *
     * This method prioritizes an injected ConfigRepository (for testability) over the
     * global config() helper function. This allows analyzers to be properly tested
     * with mocked configuration while still working in production.
     *
     * The environment is automatically mapped to standard environment types
     * using the 'shieldci.environment_mapping' configuration.
     *
     * Standard environments: local, development, staging, production, testing
     *
     * Custom environments are mapped via config. For example:
     * - 'production-us' maps to 'production'
     * - 'staging-preview' maps to 'staging'
     *
     * Subclasses can override this method to provide environment detection
     * through other means (e.g., reading .env files directly).
     *
     * @return string The mapped environment name (e.g., 'local', 'production', 'staging')
     */
    protected function getEnvironment(): string
    {
        // Priority 1: use injected ConfigRepository when available (test-friendly)
        if ($this->configRepository !== null && is_object($this->configRepository)) {
            if (! method_exists($this->configRepository, 'get')) {
                $rawEnv = 'production';
            } else {
                $configRepo = $this->configRepository;
                /** @phpstan-var object $configRepo */
                $callback = [$configRepo, 'get'];
                if (! is_callable($callback)) {
                    $rawEnv = 'production';
                } else {
                    /** @var callable(string, mixed): mixed $callback */
                    $rawEnv = call_user_func($callback, 'app.env', 'production');
                }
            }

            if (! is_string($rawEnv) || $rawEnv === '') {
                $rawEnv = 'production';
            }

            // Apply environment mapping if configured (uses global config() helper for mapping config)
            if (function_exists('config')) {
                $mapping = config('shieldci.environment_mapping', []);
                if (is_array($mapping) && isset($mapping[$rawEnv])) {
                    return $mapping[$rawEnv];
                }
            }

            return $rawEnv;
        }

        // Priority 2: Fall back to global config() helper
        $rawEnv = 'production'; // Default fallback

        if (function_exists('config')) {
            $env = config('app.env');
            if (is_string($env) && $env !== '') {
                $rawEnv = $env;
            }
        }

        // Apply environment mapping if configured
        if (function_exists('config')) {
            $mapping = config('shieldci.environment_mapping', []);
            if (is_array($mapping) && isset($mapping[$rawEnv])) {
                return $mapping[$rawEnv];
            }
        }

        // Return raw environment (no mapping configured or not in mapping)
        return $rawEnv;
    }

    /**
     * Check if analyzer is relevant for the current environment.
     *
     * This method checks the $relevantEnvironments property to determine
     * if the analyzer should run in the current environment.
     *
     * The environment is automatically resolved using environment mapping,
     * so analyzers only need to specify standard environment names.
     *
     * Example:
     * - Analyzer declares: ['production', 'staging']
     * - APP_ENV=production-us → Maps to 'production' → Matches
     * - APP_ENV=local → No mapping → Doesn't match
     *
     * @return bool True if analyzer should run in current environment
     */
    protected function isRelevantForCurrentEnvironment(): bool
    {
        // If no environment filtering is specified, run in all environments
        if ($this->relevantEnvironments === null) {
            return true;
        }

        $currentEnv = $this->getEnvironment();

        // Check for exact match (case-insensitive)
        foreach ($this->relevantEnvironments as $relevantEnv) {
            if (strcasecmp($currentEnv, $relevantEnv) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get relative path from base path.
     */
    protected function getRelativePath(string $file): string
    {
        $basePath = $this->getBasePath();

        if ($basePath === '' || $basePath === '.') {
            return $file;
        }

        $basePath = rtrim($basePath, '/\\').'/';
        $normalizedFile = str_replace('\\', '/', $file);
        $normalizedBasePath = str_replace('\\', '/', $basePath);

        if (str_starts_with($normalizedFile, $normalizedBasePath)) {
            return substr($normalizedFile, strlen($normalizedBasePath));
        }

        return $file;
    }
}
