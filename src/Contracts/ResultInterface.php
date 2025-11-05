<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Contracts;

use ShieldCI\AnalyzersCore\Enums\Status;
use ShieldCI\AnalyzersCore\ValueObjects\Issue;

/**
 * Interface for analysis results.
 */
interface ResultInterface
{
    /**
     * Get the analyzer ID.
     */
    public function getAnalyzerId(): string;

    /**
     * Get the result status.
     */
    public function getStatus(): Status;

    /**
     * Get the result message.
     */
    public function getMessage(): string;

    /**
     * Get all issues found.
     *
     * @return array<Issue>
     */
    public function getIssues(): array;

    /**
     * Get execution time in seconds.
     */
    public function getExecutionTime(): float;

    /**
     * Get metadata.
     */
    public function getMetadata(): array;

    /**
     * Check if the result is successful.
     */
    public function isSuccess(): bool;

    /**
     * Convert to array.
     */
    public function toArray(): array;
}
