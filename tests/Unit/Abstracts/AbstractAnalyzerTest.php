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
        $this->assertStringContainsString('not enabled', $result->getMessage());
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
}
