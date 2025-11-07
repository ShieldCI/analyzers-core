<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Formatters;

use ShieldCI\AnalyzersCore\Contracts\{ReporterInterface, ResultInterface};

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

        $flags = JSON_THROW_ON_ERROR;
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
        $passed = 0;
        $failed = 0;
        $warnings = 0;
        $skipped = 0;
        $errors = 0;
        $totalIssues = 0;
        $totalExecutionTime = 0.0;

        foreach ($results as $result) {
            match ($result->getStatus()->value) {
                'passed' => $passed++,
                'failed' => $failed++,
                'warning' => $warnings++,
                'skipped' => $skipped++,
                'error' => $errors++,
            };

            $totalIssues += count($result->getIssues());
            $totalExecutionTime += $result->getExecutionTime();
        }

        $total = count($results);
        $score = $total > 0 ? round((($passed + $skipped) / $total) * 100, 2) : 100.0;

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_issues' => $totalIssues,
            'score' => $score,
            'execution_time' => round($totalExecutionTime, 4),
        ];
    }
}
