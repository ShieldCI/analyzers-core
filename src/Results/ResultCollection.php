<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Results;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\Status;
use Traversable;

/**
 * Collection of analysis results.
 *
 * @implements IteratorAggregate<int, ResultInterface>
 */
final class ResultCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<ResultInterface> $results
     */
    public function __construct(
        private array $results = []
    ) {
    }

    /**
     * Add a result to the collection.
     */
    public function add(ResultInterface $result): self
    {
        $this->results[] = $result;

        return $this;
    }

    /**
     * Get all results.
     *
     * @return array<ResultInterface>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * Filter results by status.
     *
     * @return array<ResultInterface>
     */
    public function byStatus(Status $status): array
    {
        return array_filter(
            $this->results,
            fn (ResultInterface $result) => $result->getStatus() === $status
        );
    }

    /**
     * Get passed results.
     *
     * @return array<ResultInterface>
     */
    public function passed(): array
    {
        return $this->byStatus(Status::Passed);
    }

    /**
     * Get failed results.
     *
     * @return array<ResultInterface>
     */
    public function failed(): array
    {
        return $this->byStatus(Status::Failed);
    }

    /**
     * Get warning results.
     *
     * @return array<ResultInterface>
     */
    public function warnings(): array
    {
        return $this->byStatus(Status::Warning);
    }

    /**
     * Get skipped results.
     *
     * @return array<ResultInterface>
     */
    public function skipped(): array
    {
        return $this->byStatus(Status::Skipped);
    }

    /**
     * Get error results.
     *
     * @return array<ResultInterface>
     */
    public function errors(): array
    {
        return $this->byStatus(Status::Error);
    }

    /**
     * Calculate overall score (0-100).
     */
    public function score(): float
    {
        if ($this->count() === 0) {
            return 100.0;
        }

        $passed = count($this->passed()) + count($this->skipped());

        return round(($passed / $this->count()) * 100, 2);
    }

    /**
     * Get total number of issues.
     */
    public function totalIssues(): int
    {
        return array_reduce(
            $this->results,
            fn (int $carry, ResultInterface $result) => $carry + count($result->getIssues()),
            0
        );
    }

    /**
     * Get total execution time.
     */
    public function totalExecutionTime(): float
    {
        return array_reduce(
            $this->results,
            fn (float $carry, ResultInterface $result) => $carry + $result->getExecutionTime(),
            0.0
        );
    }

    /**
     * Check if all results are successful.
     */
    public function isSuccess(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->isSuccess()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return array_map(
            fn (ResultInterface $result) => $result->toArray(),
            $this->results
        );
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }
}
