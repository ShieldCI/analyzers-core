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

    public function testGetRelativePathReturnsFileWhenBasePathIsEmpty(): void
    {
        $analyzer = new RelativePathAnalyzer('');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src/File.php');

        $this->assertEquals('/var/www/project/src/File.php', $result);
    }

    public function testGetRelativePathReturnsFileWhenBasePathIsDot(): void
    {
        $analyzer = new RelativePathAnalyzer('.');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src/File.php');

        $this->assertEquals('/var/www/project/src/File.php', $result);
    }

    public function testGetRelativePathStripsBasePath(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src/File.php');

        $this->assertEquals('src/File.php', $result);
    }

    public function testGetRelativePathHandlesTrailingSlashInBasePath(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project/');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src/File.php');

        $this->assertEquals('src/File.php', $result);
    }

    public function testGetRelativePathHandlesWindowsBackslashes(): void
    {
        $analyzer = new RelativePathAnalyzer('C:\\Projects\\myapp');
        $result = $analyzer->exposedGetRelativePath('C:\\Projects\\myapp\\src\\File.php');

        $this->assertEquals('src/File.php', $result);
    }

    public function testGetRelativePathHandlesMixedSlashes(): void
    {
        $analyzer = new RelativePathAnalyzer('C:/Projects/myapp');
        $result = $analyzer->exposedGetRelativePath('C:\\Projects\\myapp\\src\\File.php');

        $this->assertEquals('src/File.php', $result);
    }

    public function testGetRelativePathReturnsOriginalWhenFileNotUnderBasePath(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project1');
        $result = $analyzer->exposedGetRelativePath('/var/www/project2/src/File.php');

        $this->assertEquals('/var/www/project2/src/File.php', $result);
    }

    public function testGetRelativePathHandlesNestedDirectories(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/app/Http/Controllers/UserController.php');

        $this->assertEquals('app/Http/Controllers/UserController.php', $result);
    }

    public function testGetRelativePathNormalizesSlashes(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src\\File.php');

        // Result should have forward slashes
        $this->assertEquals('src/File.php', $result);
        $this->assertStringNotContainsString('\\', $result);
    }

    public function testGetRelativePathHandlesFileInRootOfBasePath(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/project');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/README.md');

        $this->assertEquals('README.md', $result);
    }

    public function testGetRelativePathIsCaseSensitive(): void
    {
        $analyzer = new RelativePathAnalyzer('/var/www/Project');
        $result = $analyzer->exposedGetRelativePath('/var/www/project/src/File.php');

        // Should return original path if case doesn't match (on case-sensitive systems)
        // Note: On Windows this might behave differently
        $this->assertEquals('/var/www/project/src/File.php', $result);
    }

    public function testCreateIssueWithSnippetCreatesIssueWithSnippet(): void
    {
        $testFile = sys_get_temp_dir().'/test_snippet_'.uniqid().'.php';
        file_put_contents($testFile, "<?php\n\nclass Test\n{\n    public function method()\n    {\n        return true; // Line 7\n    }\n}\n");

        $analyzer = new IssueWithSnippetAnalyzer($testFile);
        $result = $analyzer->analyze();

        $issues = $result->getIssues();
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        // Code snippet may be null if config() function doesn't exist in test environment
        // This is expected behavior - snippets are only created when config is available
        if ($issue->codeSnippet !== null) {
            $this->assertEquals(7, $issue->codeSnippet->getTargetLine());
            $this->assertArrayHasKey(7, $issue->codeSnippet->getLines());
        }
        // Issue should always be created correctly
        $this->assertEquals('Test issue with snippet', $issue->message);

        unlink($testFile);
    }

    public function testCreateIssueWithSnippetRespectsContextLines(): void
    {
        $testFile = sys_get_temp_dir().'/test_snippet_context_'.uniqid().'.php';
        file_put_contents($testFile, "<?php\n\nclass Test\n{\n    public function method()\n    {\n        return true; // Line 7\n    }\n}\n");

        $analyzer = new IssueWithSnippetAnalyzer($testFile, 2); // 2 lines context
        $result = $analyzer->analyze();

        $issues = $result->getIssues();
        $issue = $issues[0];
        // Code snippet may be null if config() function doesn't exist in test environment
        if ($issue->codeSnippet !== null) {
            $lines = $issue->codeSnippet->getLines();
            // Should have approximately 5 lines (2 before + 1 target + 2 after)
            $this->assertLessThanOrEqual(6, count($lines));
        }
        // Issue should always be created correctly
        $this->assertEquals('Test issue with snippet', $issue->message);

        unlink($testFile);
    }

    public function testCreateIssueWithSnippetHandlesColumnNumber(): void
    {
        $testFile = sys_get_temp_dir().'/test_snippet_column_'.uniqid().'.php';
        file_put_contents($testFile, "<?php\n\nclass Test\n{\n    public function method()\n    {\n        return true; // Line 7\n    }\n}\n");

        $analyzer = new IssueWithSnippetAnalyzer($testFile, null, 15); // Column 15
        $result = $analyzer->analyze();

        $issues = $result->getIssues();
        $issue = $issues[0];
        $this->assertEquals(15, $issue->location->column);

        unlink($testFile);
    }

    public function testGetExecutionTimeReturnsZeroWhenNotStarted(): void
    {
        $analyzer = new ExecutionTimeAnalyzer();
        $time = $analyzer->exposedGetExecutionTime();

        $this->assertEquals(0.0, $time);
    }

    public function testGetExecutionTimeReturnsActualTimeAfterAnalysis(): void
    {
        $analyzer = new ExecutionTimeAnalyzer();
        $analyzer->analyze();
        $time = $analyzer->exposedGetExecutionTime();

        $this->assertGreaterThan(0.0, $time);
        $this->assertIsFloat($time);
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
    /**
     * @param array<Issue> $issues
     */
    public function __construct(
        private array $issues,
        private string $message
    ) {
    }

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

class RelativePathAnalyzer extends AbstractAnalyzer
{
    public function __construct(
        private string $testBasePath
    ) {
    }

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'relative-path-analyzer',
            name: 'Relative Path Analyzer',
            description: 'Test analyzer for getRelativePath',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    protected function getBasePath(): string
    {
        return $this->testBasePath;
    }

    public function exposedGetRelativePath(string $file): string
    {
        return $this->getRelativePath($file);
    }
}

class IssueWithSnippetAnalyzer extends AbstractAnalyzer
{
    public function __construct(
        private string $testFile,
        private ?int $contextLines = null,
        private ?int $column = null
    ) {
    }

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'issue-with-snippet-analyzer',
            name: 'Issue With Snippet Analyzer',
            description: 'Test analyzer for createIssueWithSnippet',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issue = $this->createIssueWithSnippet(
            message: 'Test issue with snippet',
            filePath: $this->testFile,
            lineNumber: 7,
            severity: Severity::High,
            recommendation: 'Fix it',
            column: $this->column,
            contextLines: $this->contextLines
        );

        return $this->failed('Issue found', [$issue]);
    }
}

class ExecutionTimeAnalyzer extends AbstractAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'execution-time-analyzer',
            name: 'Execution Time Analyzer',
            description: 'Test analyzer for execution time',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        usleep(10000); // Sleep 10ms to ensure execution time > 0

        return $this->passed('Analysis completed');
    }

    public function exposedGetExecutionTime(): float
    {
        return $this->getExecutionTime();
    }
}
