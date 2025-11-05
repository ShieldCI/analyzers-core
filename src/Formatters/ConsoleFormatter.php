<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Formatters;

use ShieldCI\AnalyzersCore\Contracts\{ReporterInterface, ResultInterface};
use ShieldCI\AnalyzersCore\Enums\Status;

/**
 * Formats analysis results for console output.
 */
class ConsoleFormatter implements ReporterInterface
{
    private const COLORS = [
        'reset' => "\033[0m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'gray' => "\033[90m",
        'bold' => "\033[1m",
    ];

    public function __construct(
        private bool $useColors = true,
        private bool $verbose = false
    ) {
    }

    /**
     * @param array<ResultInterface> $results
     */
    public function format(array $results): string
    {
        $output = [];

        $output[] = $this->formatHeader();
        $output[] = $this->formatSummary($results);
        $output[] = '';

        // Group results by status
        $failed = array_filter($results, fn ($r) => $r->getStatus() === Status::Failed);
        $warnings = array_filter($results, fn ($r) => $r->getStatus() === Status::Warning);
        $errors = array_filter($results, fn ($r) => $r->getStatus() === Status::Error);

        if (! empty($failed)) {
            $output[] = $this->formatSection('Failed Analyzers', $failed, 'red');
            $output[] = '';
        }

        if (! empty($warnings)) {
            $output[] = $this->formatSection('Warnings', $warnings, 'yellow');
            $output[] = '';
        }

        if (! empty($errors)) {
            $output[] = $this->formatSection('Errors', $errors, 'red');
            $output[] = '';
        }

        if ($this->verbose) {
            $passed = array_filter($results, fn ($r) => $r->getStatus() === Status::Passed);
            if (! empty($passed)) {
                $output[] = $this->formatSection('Passed Analyzers', $passed, 'green');
                $output[] = '';
            }
        }

        $output[] = $this->formatFooter($results);

        return implode(PHP_EOL, $output);
    }

    public function getFormat(): string
    {
        return 'console';
    }

    private function formatHeader(): string
    {
        $header = '┌' . str_repeat('─', 58) . '┐' . PHP_EOL;
        $header .= '│' . str_pad('  ShieldCI Analysis Report', 59) . '│' . PHP_EOL;
        $header .= '└' . str_repeat('─', 58) . '┘';

        return $this->color($header, 'bold');
    }

    /**
     * @param array<ResultInterface> $results
     */
    private function formatSummary(array $results): string
    {
        $total = count($results);
        $passed = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Passed));
        $failed = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Failed));
        $warnings = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Warning));
        $skipped = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Skipped));
        $errors = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Error));

        $score = $total > 0 ? round((($passed + $skipped) / $total) * 100, 1) : 100.0;
        $scoreColor = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');

        $totalIssues = array_reduce(
            $results,
            fn (int $carry, ResultInterface $r) => $carry + count($r->getIssues()),
            0
        );

        $totalTime = array_reduce(
            $results,
            fn (float $carry, ResultInterface $r) => $carry + $r->getExecutionTime(),
            0.0
        );

        $summary = [];
        $summary[] = $this->color("Score: {$score}%", $scoreColor);
        $summary[] = '';
        $summary[] = "Total Analyzers: {$total}";
        $summary[] = $this->color("  ✓ Passed: {$passed}", 'green');
        $summary[] = $this->color("  ✗ Failed: {$failed}", 'red');
        $summary[] = $this->color("  ⚠ Warnings: {$warnings}", 'yellow');
        $summary[] = $this->color("  ⊝ Skipped: {$skipped}", 'gray');

        if ($errors > 0) {
            $summary[] = $this->color("  ⚡ Errors: {$errors}", 'red');
        }

        $summary[] = '';
        $summary[] = "Total Issues Found: {$totalIssues}";
        $summary[] = "Execution Time: " . round($totalTime, 2) . 's';

        return implode(PHP_EOL, $summary);
    }

    /**
     * @param array<ResultInterface> $results
     */
    private function formatSection(string $title, array $results, string $color): string
    {
        $output = [];
        $output[] = $this->color($title . ':', $color);
        $output[] = str_repeat('─', 60);

        foreach ($results as $result) {
            $output[] = '';
            $output[] = $this->color(
                $result->getStatus()->emoji() . ' ' . $result->getAnalyzerId(),
                $color
            );
            $output[] = '  ' . $result->getMessage();

            if (! empty($result->getIssues())) {
                $output[] = $this->color('  Issues:', $color);

                foreach ($result->getIssues() as $issue) {
                    $output[] = '    • ' . $issue->message;
                    $output[] = $this->color(
                        '      Location: ' . $issue->location,
                        'gray'
                    );
                    $output[] = $this->color(
                        '      Severity: ' . $issue->severity->label(),
                        $issue->severity->color()
                    );
                    $output[] = '      Recommendation: ' . $issue->recommendation;

                    if ($issue->code !== null && $this->verbose) {
                        $output[] = $this->color('      Code:', 'gray');
                        $lines = explode(PHP_EOL, $issue->code);
                        foreach ($lines as $line) {
                            $output[] = $this->color('        ' . $line, 'gray');
                        }
                    }
                }
            }
        }

        return implode(PHP_EOL, $output);
    }

    /**
     * @param array<ResultInterface> $results
     */
    private function formatFooter(array $results): string
    {
        $failed = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Failed));
        $errors = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Error));

        if ($failed > 0 || $errors > 0) {
            return $this->color('✗ Analysis completed with issues', 'red');
        }

        $warnings = count(array_filter($results, fn ($r) => $r->getStatus() === Status::Warning));
        if ($warnings > 0) {
            return $this->color('⚠ Analysis completed with warnings', 'yellow');
        }

        return $this->color('✓ Analysis completed successfully', 'green');
    }

    private function color(string $text, string $color): string
    {
        if (! $this->useColors || ! isset(self::COLORS[$color])) {
            return $text;
        }

        return self::COLORS[$color] . $text . self::COLORS['reset'];
    }
}
