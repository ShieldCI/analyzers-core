<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Formatters;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\{Severity, Status};
use ShieldCI\AnalyzersCore\Formatters\ConsoleFormatter;
use ShieldCI\AnalyzersCore\Results\AnalysisResult;
use ShieldCI\AnalyzersCore\ValueObjects\{Issue, Location};

class ConsoleFormatterTest extends TestCase
{
    public function testFormatReturnsString(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('test-analyzer', Status::Passed, 'All checks passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testFormatIncludesHeader(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [];

        $output = $formatter->format($results);

        $this->assertStringContainsString('ShieldCI Analysis Report', $output);
    }

    public function testFormatIncludesSummary(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Score:', $output);
        $this->assertStringContainsString('Total Analyzers:', $output);
        $this->assertStringContainsString('Passed:', $output);
        $this->assertStringContainsString('Failed:', $output);
    }

    public function testFormatIncludesFailedSection(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Failed Analyzers:', $output);
        $this->assertStringContainsString('analyzer-1', $output);
    }

    public function testFormatIncludesWarningsSection(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Warning, 'Warning', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Warnings:', $output);
        $this->assertStringContainsString('analyzer-1', $output);
    }

    public function testFormatIncludesErrorsSection(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Error, 'Error occurred', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Errors:', $output);
        $this->assertStringContainsString('analyzer-1', $output);
    }

    public function testFormatDoesNotIncludePassedSectionByDefault(): void
    {
        $formatter = new ConsoleFormatter(verbose: false);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringNotContainsString('Passed Analyzers:', $output);
    }

    public function testFormatIncludesPassedSectionInVerboseMode(): void
    {
        $formatter = new ConsoleFormatter(verbose: true);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Passed Analyzers:', $output);
    }

    public function testFormatIncludesIssueDetails(): void
    {
        $formatter = new ConsoleFormatter();
        $issue = new Issue(
            message: 'Test issue',
            location: new Location('/test.php', 42),
            severity: Severity::High,
            recommendation: 'Fix it now'
        );

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Test issue', $output);
        $this->assertStringContainsString('/test.php:42', $output);
        $this->assertStringContainsString('High', $output);
        $this->assertStringContainsString('Fix it now', $output);
    }

    public function testFormatIncludesCodeInVerboseMode(): void
    {
        $formatter = new ConsoleFormatter(verbose: true);
        $issue = new Issue(
            message: 'Issue',
            location: new Location('/test.php', 1),
            severity: Severity::High,
            recommendation: 'Fix it',
            code: '$x = $_GET["id"];'
        );

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Code:', $output);
        $this->assertStringContainsString('$x = $_GET["id"];', $output);
    }

    public function testFormatDoesNotIncludeCodeInNonVerboseMode(): void
    {
        $formatter = new ConsoleFormatter(verbose: false);
        $issue = new Issue(
            message: 'Issue',
            location: new Location('/test.php', 1),
            severity: Severity::High,
            recommendation: 'Fix it',
            code: '$x = $_GET["id"];'
        );

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringNotContainsString('$x = $_GET["id"];', $output);
    }

    public function testFormatIncludesSuccessFooter(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Analysis completed successfully', $output);
    }

    public function testFormatIncludesWarningFooter(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Warning, 'Warning', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Analysis completed with warnings', $output);
    }

    public function testFormatIncludesFailureFooter(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Analysis completed with issues', $output);
    }

    public function testFormatIncludesErrorFooter(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Error, 'Error', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Analysis completed with issues', $output);
    }

    public function testFormatCalculatesScoreCorrectly(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, '', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Passed, '', [], 0.1),
            new AnalysisResult('analyzer-3', Status::Failed, '', [], 0.1),
            new AnalysisResult('analyzer-4', Status::Failed, '', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Score: 50', $output);
    }

    public function testFormatCountsTotalIssues(): void
    {
        $formatter = new ConsoleFormatter();
        $issue1 = new Issue('Issue 1', new Location('/test.php', 1), Severity::High, 'Fix 1');
        $issue2 = new Issue('Issue 2', new Location('/test.php', 2), Severity::Medium, 'Fix 2');

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue1], 0.1),
            new AnalysisResult('analyzer-2', Status::Failed, 'Failed', [$issue2], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Total Issues Found: 2', $output);
    }

    public function testFormatShowsExecutionTime(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.123),
            new AnalysisResult('analyzer-2', Status::Passed, 'Passed', [], 0.456),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Execution Time:', $output);
        $this->assertStringContainsString('0.58s', $output);
    }

    public function testGetFormatReturnsConsole(): void
    {
        $formatter = new ConsoleFormatter();

        $this->assertEquals('console', $formatter->getFormat());
    }

    public function testFormatWithoutColorsDoesNotIncludeAnsiCodes(): void
    {
        $formatter = new ConsoleFormatter(useColors: false);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringNotContainsString("\033[", $output);
    }

    public function testFormatWithColorsIncludesAnsiCodes(): void
    {
        $formatter = new ConsoleFormatter(useColors: true);
        $results = [
            new AnalysisResult('analyzer-1', Status::Passed, 'Passed', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString("\033[", $output);
    }

    public function testFormatShowsSkippedAnalyzers(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Skipped, 'Skipped', [], 0.0),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Skipped: 1', $output);
    }

    public function testFormatShowsErrorCount(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [
            new AnalysisResult('analyzer-1', Status::Error, 'Error occurred', [], 0.1),
            new AnalysisResult('analyzer-2', Status::Error, 'Another error', [], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Errors: 2', $output);
    }

    public function testFormatHandlesEmptyResults(): void
    {
        $formatter = new ConsoleFormatter();
        $results = [];

        $output = $formatter->format($results);

        $this->assertStringContainsString('Total Analyzers: 0', $output);
        $this->assertStringContainsString('Score: 100', $output);
        $this->assertStringContainsString('Analysis completed successfully', $output);
    }

    public function testFormatHandlesMultilineCodeInVerboseMode(): void
    {
        $formatter = new ConsoleFormatter(verbose: true);
        $code = "<?php\n\$x = \$_GET['id'];\necho \$x;";
        $issue = new Issue(
            message: 'XSS vulnerability',
            location: new Location('/test.php', 2),
            severity: Severity::Critical,
            recommendation: 'Sanitize input',
            code: $code
        );

        $results = [
            new AnalysisResult('analyzer-1', Status::Failed, 'Failed', [$issue], 0.1),
        ];

        $output = $formatter->format($results);

        $this->assertStringContainsString('<?php', $output);
        $this->assertStringContainsString('$x = $_GET', $output);
        $this->assertStringContainsString('echo $x', $output);
    }

    public function test_format_issues_with_null_location(): void
    {
        $issue = new Issue(
            message: 'Application is in maintenance mode',
            location: null,
            severity: Severity::High,
            recommendation: 'Disable maintenance mode'
        );

        $result = AnalysisResult::failed(
            'test-analyzer',
            'Application issue found',
            [$issue]
        );

        $formatter = new ConsoleFormatter(useColors: false, verbose: true);
        $output = $formatter->format([$result]);

        // Should contain the issue message
        $this->assertStringContainsString('Application is in maintenance mode', $output);

        // Should NOT show "Location:" when location is null
        $this->assertStringNotContainsString('Location:', $output);

        // Should contain severity and recommendation
        $this->assertStringContainsString('Severity:', $output);
        $this->assertStringContainsString('Recommendation:', $output);
    }
}
