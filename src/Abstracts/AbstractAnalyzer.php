<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Abstracts;

use ShieldCI\AnalyzersCore\Contracts\{AnalyzerInterface, ResultInterface};
use ShieldCI\AnalyzersCore\Enums\Severity;
use ShieldCI\AnalyzersCore\Results\AnalysisResult;
use ShieldCI\AnalyzersCore\ValueObjects\{AnalyzerMetadata, Issue, Location};
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
            return $this->skipped('Analyzer is not enabled or not applicable in current context');
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
     * Create an issue.
     *
     * @param array<string, mixed> $metadata
     */
    protected function createIssue(
        string $message,
        Location $location,
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
     * Get the current environment using Laravel's config helper.
     *
     * This method uses the config() helper function to get the environment,
     * which works for both runtime (production) and testing scenarios.
     *
     * Subclasses can override this method to provide environment detection
     * through other means (e.g., reading .env files directly).
     *
     * @return string The environment name (e.g., 'local', 'production', 'staging')
     */
    protected function getEnvironment(): string
    {
        // Use Laravel's config helper (works in both production and tests)
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
