<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Results;

use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\Status;
use ShieldCI\AnalyzersCore\ValueObjects\Issue;

/**
 * Represents the result of running a single analyzer.
 */
final class AnalysisResult implements ResultInterface
{
    /**
     * @param array<Issue> $issues
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $analyzerId,
        private readonly Status $status,
        private readonly string $message,
        private readonly array $issues = [],
        private readonly float $executionTime = 0.0,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * Create a passed result.
     *
     * @param array<string, mixed> $metadata
     */
    public static function passed(
        string $analyzerId,
        string $message,
        float $executionTime = 0.0,
        array $metadata = []
    ): self {
        return new self($analyzerId, Status::Passed, $message, [], $executionTime, $metadata);
    }

    /**
     * Create a failed result.
     *
     * @param array<Issue> $issues
     * @param array<string, mixed> $metadata
     */
    public static function failed(
        string $analyzerId,
        string $message,
        array $issues = [],
        float $executionTime = 0.0,
        array $metadata = []
    ): self {
        return new self($analyzerId, Status::Failed, $message, $issues, $executionTime, $metadata);
    }

    /**
     * Create a warning result.
     *
     * @param array<Issue> $issues
     * @param array<string, mixed> $metadata
     */
    public static function warning(
        string $analyzerId,
        string $message,
        array $issues = [],
        float $executionTime = 0.0,
        array $metadata = []
    ): self {
        return new self($analyzerId, Status::Warning, $message, $issues, $executionTime, $metadata);
    }

    /**
     * Create a skipped result.
     *
     * @param array<string, mixed> $metadata
     */
    public static function skipped(
        string $analyzerId,
        string $message,
        float $executionTime = 0.0,
        array $metadata = []
    ): self {
        return new self($analyzerId, Status::Skipped, $message, [], $executionTime, $metadata);
    }

    /**
     * Create an error result.
     *
     * @param array<string, mixed> $metadata
     */
    public static function error(
        string $analyzerId,
        string $message,
        float $executionTime = 0.0,
        array $metadata = []
    ): self {
        return new self($analyzerId, Status::Error, $message, [], $executionTime, $metadata);
    }

    public function getAnalyzerId(): string
    {
        return $this->analyzerId;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    public function toArray(): array
    {
        return [
            'analyzer_id' => $this->analyzerId,
            'status' => $this->status->value,
            'message' => $this->message,
            'issues' => array_map(fn (Issue $issue) => $issue->toArray(), $this->issues),
            'execution_time' => $this->executionTime,
            'metadata' => $this->metadata,
        ];
    }
}
