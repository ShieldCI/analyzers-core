<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Formatters;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\{Severity, Status};
use ShieldCI\AnalyzersCore\Formatters\JsonFormatter;
use ShieldCI\AnalyzersCore\Results\AnalysisResult;
use ShieldCI\AnalyzersCore\ValueObjects\{Issue, Location};

class JsonFormatterTest extends TestCase
{
    public function testFormatReturnsValidJson(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult(
                analyzerId: 'test-analyzer',
                status: Status::Passed,
                message: 'All checks passed',
                issues: [],
                executionTime: 0.1
            ),
        ];

        $output = $formatter->format($results);

        $this->assertJson($output);
    }

    public function testFormatIncludesSummary(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult(
                analyzerId: 'test-analyzer',
                status: Status::Passed,
                message: 'All checks passed',
                issues: [],
                executionTime: 0.1
            ),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testSummaryIncludesAllStatistics(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult(
                analyzerId: 'analyzer-1',
                status: Status::Passed,
                message: 'Passed',
                issues: [],
                executionTime: 0.1
            ),
            new AnalysisResult(
                analyzerId: 'analyzer-2',
                status: Status::Failed,
                message: 'Failed',
                issues: [
                    new Issue(
                        message: 'Issue found',
                        location: new Location('/test.php', 10),
                        severity: Severity::High,
                        recommendation: 'Fix it'
                    ),
                ],
                executionTime: 0.2
            ),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);
        $summary = $data['summary'];

        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(1, $summary['passed']);
        $this->assertEquals(1, $summary['failed']);
        $this->assertEquals(0, $summary['warnings']);
        $this->assertEquals(0, $summary['skipped']);
        $this->assertEquals(0, $summary['errors']);
        $this->assertEquals(1, $summary['total_issues']);
        $this->assertEquals(50.0, $summary['score']);
        $this->assertEquals(0.3, $summary['execution_time']);
    }

    public function testSummaryCalculatesScoreCorrectly(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, '', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Passed, '', [], 0.1),
            new AnalysisResult('analyzer-3', Status::Skipped, '', [], 0.1),
            new AnalysisResult('analyzer-4', Status::Failed, '', [], 0.1),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        // Score = (passed + skipped) / total * 100 = (2 + 1) / 4 * 100 = 75%
        $this->assertEquals(75.0, $data['summary']['score']);
    }

    public function testSummaryScoreIs100ForEmptyResults(): void
    {
        $formatter = new JsonFormatter();
        $results = [];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertEquals(100.0, $data['summary']['score']);
    }

    public function testFormatIncludesAllResults(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Message 1', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Failed, 'Message 2', [], 0.2),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertCount(2, $data['results']);
        $this->assertEquals('analyzer-1', $data['results'][0]['analyzer_id']);
        $this->assertEquals('analyzer-2', $data['results'][1]['analyzer_id']);
    }

    public function testFormatIncludesIssues(): void
    {
        $formatter = new JsonFormatter();
        $issue = new Issue(
            message: 'Test issue',
            location: new Location('/test.php', 42),
            severity: Severity::High,
            recommendation: 'Fix it',
            code: '$x = $_GET["id"];'
        );

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue], 0.1),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertCount(1, $data['results'][0]['issues']);
        $this->assertEquals('Test issue', $data['results'][0]['issues'][0]['message']);
        $this->assertEquals('/test.php', $data['results'][0]['issues'][0]['location']['file']);
        $this->assertEquals(42, $data['results'][0]['issues'][0]['location']['line']);
        $this->assertEquals('high', $data['results'][0]['issues'][0]['severity']);
    }

    public function testPrettyPrintFormatsJson(): void
    {
        $formatter = new JsonFormatter(prettyPrint: true);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        // Pretty printed JSON should contain newlines and indentation
        $this->assertStringContainsString("\n", $output);
        $this->assertStringContainsString('    ', $output);
    }

    public function testNonPrettyPrintFormatsCompactJson(): void
    {
        $formatter = new JsonFormatter(prettyPrint: false);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        // Compact JSON should not have excessive whitespace
        $this->assertStringNotContainsString("\n    ", $output);
    }

    public function testGetFormatReturnsJson(): void
    {
        $formatter = new JsonFormatter();

        $this->assertEquals('json', $formatter->getFormat());
    }

    public function testFormatHandlesMultipleWarnings(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Warning, 'Warning 1', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Warning, 'Warning 2', [], 0.1),
            new AnalysisResult('analyzer-3', Status::Warning, 'Warning 3', [], 0.1),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertEquals(3, $data['summary']['warnings']);
        $this->assertEquals(0, $data['summary']['passed']);
    }

    public function testFormatHandlesMultipleErrors(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Error, 'Error 1', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Error, 'Error 2', [], 0.1),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertEquals(2, $data['summary']['errors']);
        $this->assertEquals(0, $data['summary']['score']);
    }

    public function testFormatCountsIssuesFromMultipleAnalyzers(): void
    {
        $formatter = new JsonFormatter();
        $issue1 = new Issue('Issue 1', new Location('/test1.php', 1), Severity::High, 'Fix 1');
        $issue2 = new Issue('Issue 2', new Location('/test2.php', 2), Severity::Medium, 'Fix 2');
        $issue3 = new Issue('Issue 3', new Location('/test3.php', 3), Severity::Low, 'Fix 3');

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue1, $issue2], 0.1),
            new AnalysisResult('analyzer-2', Status::Failed, 'Failed', [$issue3], 0.1),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        $this->assertEquals(3, $data['summary']['total_issues']);
    }

    public function testFormatRoundsExecutionTime(): void
    {
        $formatter = new JsonFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.123456789),
        ];

        $output = $formatter->format($results);
        $data = json_decode($output, true);

        // Should be rounded to 4 decimal places
        $this->assertEquals(0.1235, $data['summary']['execution_time']);
    }
}
