<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Contracts;

/**
 * Interface for result reporters/formatters.
 */
interface ReporterInterface
{
    /**
     * Format results to string.
     *
     * @param array<ResultInterface> $results
     */
    public function format(array $results): string;

    /**
     * Get the format name.
     */
    public function getFormat(): string;
}
