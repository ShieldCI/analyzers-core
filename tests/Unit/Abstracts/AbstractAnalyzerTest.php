<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Abstracts;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Abstracts\AbstractAnalyzer;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\{Category, Severity, Status};
use ShieldCI\AnalyzersCore\ValueObjects\{AnalyzerMetadata, Issue, Location};

class AbstractAnalyzerTest extends TestCase
{
    public function testAnalyzeCallsRunAnalysisAndTracksTime(): void
    {
        $analyzer = new ConcreteAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Passed, $result->getStatus());
        $this->assertGreaterThan(0, $result->getExecutionTime());
    }

    public function testAnalyzeReturnsSkippedWhenShouldRunReturnsFalse(): void
    {
        $analyzer = new DisabledAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Skipped, $result->getStatus());
        $this->assertStringContainsString('Not applicable', $result->getMessage());
    }

    public function testAnalyzeCatchesExceptionsAndReturnsError(): void
    {
        $analyzer = new FailingAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Error, $result->getStatus());
        $this->assertStringContainsString('Analysis failed', $result->getMessage());
        $this->assertStringContainsString('Something went wrong', $result->getMessage());
    }

    public function testGetMetadataReturnsAnalyzerMetadata(): void
    {
        $analyzer = new ConcreteAnalyzer();
        $metadata = $analyzer->getMetadata();

        $this->assertInstanceOf(AnalyzerMetadata::class, $metadata);
        $this->assertEquals('concrete-analyzer', $metadata->id);
        $this->assertEquals('Concrete Analyzer', $metadata->name);
    }

    public function testGetIdReturnsMetadataId(): void
    {
        $analyzer = new ConcreteAnalyzer();

        $this->assertEquals('concrete-analyzer', $analyzer->getId());
    }

    public function testShouldRunDefaultsToTrue(): void
    {
        $analyzer = new ConcreteAnalyzer();

        $this->assertTrue($analyzer->shouldRun());
    }

    public function testPassedHelperCreatesPassedResult(): void
    {
        $analyzer = new PassedAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Passed, $result->getStatus());
        $this->assertEquals('All checks passed', $result->getMessage());
        $this->assertEmpty($result->getIssues());
    }

    public function testFailedHelperCreatesFailedResult(): void
    {
        $analyzer = new FailedAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Failed, $result->getStatus());
        $this->assertEquals('Issues found', $result->getMessage());
        $this->assertCount(1, $result->getIssues());
    }

    public function testWarningHelperCreatesWarningResult(): void
    {
        $analyzer = new WarningAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Warning, $result->getStatus());
        $this->assertEquals('Warnings found', $result->getMessage());
    }

    public function testCreateIssueHelper(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $result = $analyzer->analyze();

        $issues = $result->getIssues();
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        $this->assertEquals('Test issue', $issue->message);
        $this->assertEquals(Severity::High, $issue->severity);
        $this->assertEquals('Fix it', $issue->recommendation);
    }

    public function testGetBasePathReturnsNonEmptyString(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $basePath = $analyzer->exposedGetBasePath();

        // The critical fix: should never be empty
        $this->assertNotEquals('', $basePath);
        $this->assertIsString($basePath);
    }

    public function testBuildPathWithNoSegments(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $path = $analyzer->exposedBuildPath();

        $basePath = $analyzer->exposedGetBasePath();
        $this->assertEquals($basePath, $path);
    }

    public function testBuildPathWithSingleSegment(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $path = $analyzer->exposedBuildPath('vendor');

        $basePath = $analyzer->exposedGetBasePath();
        $expected = $basePath . DIRECTORY_SEPARATOR . 'vendor';

        $this->assertEquals($expected, $path);
    }

    public function testBuildPathWithMultipleSegments(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $path = $analyzer->exposedBuildPath('vendor', 'composer', 'autoload.php');

        $basePath = $analyzer->exposedGetBasePath();
        $expected = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload.php';

        $this->assertEquals($expected, $path);
    }

    public function testBuildPathUsesDirectorySeparator(): void
    {
        $analyzer = new IssueCreatingAnalyzer();
        $path = $analyzer->exposedBuildPath('config', 'app.php');

        $this->assertStringContainsString(DIRECTORY_SEPARATOR, $path);
    }

    public function testSkippedHelperCreatesSkippedResult(): void
    {
        $analyzer = new SkippedAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Skipped, $result->getStatus());
        $this->assertEquals('Skipping this analyzer', $result->getMessage());
        $this->assertEmpty($result->getIssues());
    }

    public function testErrorHelperCreatesErrorResult(): void
    {
        $analyzer = new ErrorAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Error, $result->getStatus());
        $this->assertEquals('An error occurred', $result->getMessage());
        $this->assertArrayHasKey('error_code', $result->getMetadata());
        $this->assertEquals(500, $result->getMetadata()['error_code']);
    }

    public function testResultBySeverityReturnsPassedWhenNoIssues(): void
    {
        $analyzer = new ResultBySeverityAnalyzer([], 'No issues found');
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Passed, $result->getStatus());
        $this->assertEquals('No issues found', $result->getMessage());
    }

    public function testResultBySeverityReturnsFailedForCriticalIssues(): void
    {
        $criticalIssue = new Issue(
            message: 'Critical security issue',
            location: new Location('/test.php', 10),
            severity: Severity::Critical,
            recommendation: 'Fix immediately'
        );

        $analyzer = new ResultBySeverityAnalyzer([$criticalIssue], 'Critical issues found');
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Failed, $result->getStatus());
        $this->assertEquals('Critical issues found', $result->getMessage());
    }

    public function testResultBySeverityReturnsFailedForHighSeverityIssues(): void
    {
        $highIssue = new Issue(
            message: 'High severity issue',
            location: new Location('/test.php', 10),
            severity: Severity::High,
            recommendation: 'Fix soon'
        );

        $analyzer = new ResultBySeverityAnalyzer([$highIssue], 'High severity issues found');
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Failed, $result->getStatus());
    }

    public function testResultBySeverityReturnsWarningForMediumIssues(): void
    {
        $mediumIssue = new Issue(
            message: 'Medium severity issue',
            location: new Location('/test.php', 10),
            severity: Severity::Medium,
            recommendation: 'Consider fixing'
        );

        $analyzer = new ResultBySeverityAnalyzer([$mediumIssue], 'Medium issues found');
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Warning, $result->getStatus());
        $this->assertEquals('Medium issues found', $result->getMessage());
    }

    public function testResultBySeverityReturnsWarningForLowIssues(): void
    {
        $lowIssue = new Issue(
            message: 'Low severity issue',
            location: new Location('/test.php', 10),
            severity: Severity::Low,
            recommendation: 'Minor improvement'
        );

        $analyzer = new ResultBySeverityAnalyzer([$lowIssue], 'Low priority issues found');
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Warning, $result->getStatus());
    }

    public function testGetEnvironmentReturnsProductionByDefault(): void
    {
        $analyzer = new EnvironmentAnalyzer();
        $environment = $analyzer->exposedGetEnvironment();

        $this->assertIsString($environment);
        // Default should be production when no config available
        $this->assertEquals('production', $environment);
    }

    public function testIsRelevantForCurrentEnvironmentReturnsTrueWhenNoFiltering(): void
    {
        $analyzer = new NoEnvironmentFilteringAnalyzer();
        $result = $analyzer->exposedIsRelevantForCurrentEnvironment();

        $this->assertTrue($result);
    }

    public function testIsRelevantForCurrentEnvironmentReturnsTrueWhenMatches(): void
    {
        $analyzer = new ProductionOnlyAnalyzer();
        // Since getEnvironment returns 'production' by default, this should match
        $result = $analyzer->exposedIsRelevantForCurrentEnvironment();

        $this->assertTrue($result);
    }

    public function testIsRelevantForCurrentEnvironmentReturnsFalseWhenNoMatch(): void
    {
        $analyzer = new LocalOnlyAnalyzer();
        // Since getEnvironment returns 'production' by default, this should not match 'local'
        $result = $analyzer->exposedIsRelevantForCurrentEnvironment();

        $this->assertFalse($result);
    }

    public function testGetSkipReasonReturnsDefaultMessage(): void
    {
        $analyzer = new ConcreteAnalyzer();
        $reason = $analyzer->getSkipReason();

        $this->assertEquals('Not applicable in current environment or configuration', $reason);
    }

    public function testPassedHelperIncludesMetadata(): void
    {
        $analyzer = new PassedWithMetadataAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Passed, $result->getStatus());
        $metadata = $result->getMetadata();
        $this->assertArrayHasKey('files_checked', $metadata);
        $this->assertEquals(10, $metadata['files_checked']);
    }

    public function testFailedHelperIncludesMetadata(): void
    {
        $analyzer = new FailedWithMetadataAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Failed, $result->getStatus());
        $metadata = $result->getMetadata();
        $this->assertArrayHasKey('total_violations', $metadata);
        $this->assertEquals(5, $metadata['total_violations']);
    }

    public function testWarningHelperIncludesMetadata(): void
    {
        $analyzer = new WarningWithMetadataAnalyzer();
        $result = $analyzer->analyze();

        $this->assertEquals(Status::Warning, $result->getStatus());
        $metadata = $result->getMetadata();
        $this->assertArrayHasKey('warning_count', $metadata);
        $this->assertEquals(3, $metadata['warning_count']);
    }
}

// Test implementations

class ConcreteAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'concrete-analyzer',
            name: 'Concrete Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        usleep(1000); // Sleep 1ms to ensure execution time > 0

        return $this->passed('Analysis completed');
    }
}

class DisabledAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'disabled-analyzer',
            name: 'Disabled Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    public function shouldRun(): bool
    {
        return false;
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Should not be called');
    }
}

class FailingAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'failing-analyzer',
            name: 'Failing Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        throw new \Exception('Something went wrong');
    }
}

class PassedAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'passed-analyzer',
            name: 'Passed Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('All checks passed');
    }
}

class FailedAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'failed-analyzer',
            name: 'Failed Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issue = new Issue(
            message: 'Found an issue',
            location: new Location('/test.php', 10),
            severity: Severity::High,
            recommendation: 'Fix it'
        );

        return $this->failed('Issues found', [$issue]);
    }
}

class WarningAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'warning-analyzer',
            name: 'Warning Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::Medium
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->warning('Warnings found');
    }
}

class IssueCreatingAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'issue-creating-analyzer',
            name: 'Issue Creating Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issue = $this->createIssue(
            message: 'Test issue',
            location: new Location('/test.php', 42),
            severity: Severity::High,
            recommendation: 'Fix it',
            code: '$x = $_GET["id"];',
            metadata: ['type' => 'xss']
        );

        return $this->failed('Issue found', [$issue]);
    }

    public function exposedGetBasePath(): string
    {
        return $this->getBasePath();
    }

    public function exposedBuildPath(string ...$segments): string
    {
        return $this->buildPath(...$segments);
    }
}

class SkippedAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'skipped-analyzer',
            name: 'Skipped Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->skipped('Skipping this analyzer');
    }
}

class ErrorAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'error-analyzer',
            name: 'Error Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->error('An error occurred', ['error_code' => 500]);
    }
}

class ResultBySeverityAnalyzer extends AbstractAnalyzer
{
    public function __construct(
        private array $issues,
        private string $message
    ) {}

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'result-by-severity-analyzer',
            name: 'Result By Severity Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->resultBySeverity($this->message, $this->issues);
    }
}

class EnvironmentAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'environment-analyzer',
            name: 'Environment Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    public function exposedGetEnvironment(): string
    {
        return $this->getEnvironment();
    }
}

class NoEnvironmentFilteringAnalyzer extends AbstractAnalyzer
{
    protected ?array $relevantEnvironments = null;

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'no-env-filter-analyzer',
            name: 'No Environment Filter Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    public function exposedIsRelevantForCurrentEnvironment(): bool
    {
        return $this->isRelevantForCurrentEnvironment();
    }
}

class ProductionOnlyAnalyzer extends AbstractAnalyzer
{
    protected ?array $relevantEnvironments = ['production', 'staging'];

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'production-only-analyzer',
            name: 'Production Only Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    public function exposedIsRelevantForCurrentEnvironment(): bool
    {
        return $this->isRelevantForCurrentEnvironment();
    }
}

class LocalOnlyAnalyzer extends AbstractAnalyzer
{
    protected ?array $relevantEnvironments = ['local', 'development'];

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'local-only-analyzer',
            name: 'Local Only Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    public function exposedIsRelevantForCurrentEnvironment(): bool
    {
        return $this->isRelevantForCurrentEnvironment();
    }
}

class PassedWithMetadataAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'passed-with-metadata-analyzer',
            name: 'Passed With Metadata Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('All checks passed', ['files_checked' => 10]);
    }
}

class FailedWithMetadataAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'failed-with-metadata-analyzer',
            name: 'Failed With Metadata Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issue = new Issue(
            message: 'Found an issue',
            location: new Location('/test.php', 10),
            severity: Severity::High,
            recommendation: 'Fix it'
        );

        return $this->failed('Issues found', [$issue], ['total_violations' => 5]);
    }
}

class WarningWithMetadataAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'warning-with-metadata-analyzer',
            name: 'Warning With Metadata Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::Medium
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->warning('Warnings found', [], ['warning_count' => 3]);
    }
}
