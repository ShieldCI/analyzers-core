<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\{Severity, Status};
use ShieldCI\AnalyzersCore\Results\AnalysisResult;
use ShieldCI\AnalyzersCore\ValueObjects\{Issue, Location};

class AnalysisResultTest extends TestCase
{
    public function testCanCreatePassedResult(): void
    {
        $result = AnalysisResult::passed('test-analyzer', 'All checks passed');

        $this->assertEquals('test-analyzer', $result->getAnalyzerId());
        $this->assertEquals(Status::Passed, $result->getStatus());
        $this->assertEquals('All checks passed', $result->getMessage());
        $this->assertEmpty($result->getIssues());
        $this->assertTrue($result->isSuccess());
    }

    public function testCanCreateFailedResult(): void
    {
        $issues = [
            new Issue(
                message: 'Security issue found',
                location: new Location('/path/to/file.php', 10),
                severity: Severity::High,
                recommendation: 'Fix the issue'
            ),
        ];

        $result = AnalysisResult::failed('test-analyzer', 'Issues found', $issues);

        $this->assertEquals(Status::Failed, $result->getStatus());
        $this->assertCount(1, $result->getIssues());
        $this->assertFalse($result->isSuccess());
    }

    public function testCanCreateWarningResult(): void
    {
        $issues = [
            new Issue(
                message: 'Warning message',
                location: new Location('/path/to/file.php', 20),
                severity: Severity::Medium,
                recommendation: 'Consider fixing'
            ),
        ];

        $result = AnalysisResult::warning('test-analyzer', 'Warnings found', $issues);

        $this->assertEquals(Status::Warning, $result->getStatus());
        $this->assertCount(1, $result->getIssues());
    }

    public function testCanCreateSkippedResult(): void
    {
        $result = AnalysisResult::skipped('test-analyzer', 'Not applicable');

        $this->assertEquals(Status::Skipped, $result->getStatus());
        $this->assertTrue($result->isSuccess());
    }

    public function testCanCreateErrorResult(): void
    {
        $result = AnalysisResult::error('test-analyzer', 'Internal error');

        $this->assertEquals(Status::Error, $result->getStatus());
        $this->assertFalse($result->isSuccess());
    }

    public function testStoresExecutionTime(): void
    {
        $result = AnalysisResult::passed('test-analyzer', 'Success', 1.234);

        $this->assertEquals(1.234, $result->getExecutionTime());
    }

    public function testStoresMetadata(): void
    {
        $metadata = ['files_scanned' => 10];
        $result = AnalysisResult::passed('test-analyzer', 'Success', 0.0, $metadata);

        $this->assertEquals($metadata, $result->getMetadata());
    }

    public function testCanConvertToArray(): void
    {
        $result = AnalysisResult::passed('test-analyzer', 'Success');
        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test-analyzer', $array['analyzer_id']);
        $this->assertEquals('passed', $array['status']);
        $this->assertEquals('Success', $array['message']);
    }
}
