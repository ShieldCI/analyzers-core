<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Formatters;

use ShieldCI\AnalyzersCore\Contracts\{ReporterInterface, ResultInterface};
use ShieldCI\AnalyzersCore\Results\ResultCollection;

/**
 * Formats analysis results as JSON.
 */
class JsonFormatter implements ReporterInterface
{
    public function __construct(
        private bool $prettyPrint = false
    ) {
    }

    /**
     * @param array<ResultInterface> $results
     */
    public function format(array $results): string
    {
        $data = [
            'summary' => $this->generateSummary($results),
            'results' => array_map(fn (ResultInterface $r) => $r->toArray(), $results),
        ];

        // Issue data quotes scanned files, which may hold bytes that are not UTF-8. Substitute
        // U+FFFD for them rather than let one bad byte throw away the whole report.
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    public function getFormat(): string
    {
        return 'json';
    }

    /**
     * Generate summary statistics.
     *
     * @param array<ResultInterface> $results
     * @return array<string, int|float>
     */
    private function generateSummary(array $results): array
    {
        $collection = new ResultCollection($results);

        return [
            'total' => $collection->count(),
            'passed' => count($collection->passed()),
            'failed' => count($collection->failed()),
            'warnings' => count($collection->warnings()),
            'skipped' => count($collection->skipped()),
            'errors' => count($collection->errors()),
            'total_issues' => $collection->totalIssues(),
            'score' => $collection->score(),
            'execution_time' => round($collection->totalExecutionTime(), 4),
        ];
    }
}
