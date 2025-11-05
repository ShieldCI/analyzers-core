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
}
