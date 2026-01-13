<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\Severity;
use ShieldCI\AnalyzersCore\ValueObjects\{CodeSnippet, Issue, Location};

class IssueTest extends TestCase
{
    public function testCanBeCreated(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $issue = new Issue(
            message: 'Test message',
            location: $location,
            severity: Severity::High,
            recommendation: 'Fix it'
        );

        $this->assertEquals('Test message', $issue->message);
        $this->assertEquals($location, $issue->location);
        $this->assertEquals(Severity::High, $issue->severity);
        $this->assertEquals('Fix it', $issue->recommendation);
        $this->assertNull($issue->code);
        $this->assertEquals([], $issue->metadata);
    }

    public function testCanBeCreatedWithCodeAndMetadata(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $code = '$x = $_GET["id"];';
        $metadata = ['type' => 'xss'];

        $issue = new Issue(
            message: 'XSS vulnerability',
            location: $location,
            severity: Severity::Critical,
            recommendation: 'Sanitize input',
            code: $code,
            metadata: $metadata
        );

        $this->assertEquals('XSS vulnerability', $issue->message);
        $this->assertEquals($code, $issue->code);
        $this->assertEquals($metadata, $issue->metadata);
    }

    public function testCanBeCreatedFromArray(): void
    {
        $data = [
            'message' => 'Test message',
            'location' => [
                'file' => '/path/to/file.php',
                'line' => 42,
            ],
            'severity' => 'high',
            'recommendation' => 'Fix it',
            'code' => 'some code',
            'metadata' => ['key' => 'value'],
        ];

        $issue = Issue::fromArray($data);

        $this->assertEquals('Test message', $issue->message);
        $this->assertNotNull($issue->location);
        $this->assertEquals('/path/to/file.php', $issue->location->file);
        $this->assertEquals(42, $issue->location->line);
        $this->assertEquals(Severity::High, $issue->severity);
        $this->assertEquals('Fix it', $issue->recommendation);
        $this->assertEquals('some code', $issue->code);
        $this->assertEquals(['key' => 'value'], $issue->metadata);
    }

    public function testToArray(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $issue = new Issue(
            message: 'Test message',
            location: $location,
            severity: Severity::High,
            recommendation: 'Fix it',
            code: 'some code',
            metadata: ['key' => 'value']
        );

        $array = $issue->toArray();

        $this->assertEquals('Test message', $array['message']);
        /** @var array<string, mixed> $location */
        $location = $array['location'];
        $this->assertEquals('/path/to/file.php', $location['file']);
        $this->assertEquals(42, $location['line']);
        $this->assertEquals('high', $array['severity']);
        $this->assertEquals('Fix it', $array['recommendation']);
        $this->assertEquals('some code', $array['code']);
        $this->assertEquals(['key' => 'value'], $array['metadata']);
    }

    public function testToArrayFiltersNullValues(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $issue = new Issue(
            message: 'Test message',
            location: $location,
            severity: Severity::High,
            recommendation: 'Fix it'
        );

        $array = $issue->toArray();

        $this->assertArrayNotHasKey('code', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    public function testIsReadonly(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $issue = new Issue(
            message: 'Test message',
            location: $location,
            severity: Severity::High,
            recommendation: 'Fix it'
        );

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $issue->message = 'New message';
    }

    public function testFromArrayWithCodeSnippet(): void
    {
        // Test lines 37-44, 52-57: Code snippet creation in fromArray
        $data = [
            'message' => 'Test message',
            'location' => [
                'file' => '/path/to/file.php',
                'line' => 42,
            ],
            'severity' => 'high',
            'recommendation' => 'Fix it',
            'code_snippet' => [
                'file' => '/path/to/file.php',
                'target_line' => 42,
                'lines' => [
                    40 => '    $var = 1;',
                    41 => '    $var2 = 2;',
                    42 => '    return $var; // Target line',
                    43 => '    }',
                ],
                'context_lines' => 2,
            ],
        ];

        $issue = Issue::fromArray($data);

        $this->assertNotNull($issue->codeSnippet);
        $this->assertInstanceOf(CodeSnippet::class, $issue->codeSnippet);
        $this->assertEquals('/path/to/file.php', $issue->codeSnippet->getFilePath());
        $this->assertEquals(42, $issue->codeSnippet->getTargetLine());
        $this->assertEquals(2, $issue->codeSnippet->getContextLines());
        $lines = $issue->codeSnippet->getLines();
        $this->assertArrayHasKey(42, $lines);
    }

    public function testFromArrayWithCodeSnippetDefaults(): void
    {
        // Test lines 41-44: Default values when snippet data is missing fields
        $data = [
            'message' => 'Test message',
            'location' => [
                'file' => '/path/to/file.php',
                'line' => 42,
            ],
            'severity' => 'high',
            'recommendation' => 'Fix it',
            'code_snippet' => [
                // Missing some fields to test defaults
                'file' => '/path/to/file.php',
                // target_line missing - should default to 0
                // lines missing - should default to []
                // context_lines missing - should default to 5
            ],
        ];

        $issue = Issue::fromArray($data);

        $this->assertNotNull($issue->codeSnippet);
        $this->assertEquals('/path/to/file.php', $issue->codeSnippet->getFilePath());
        $this->assertEquals(0, $issue->codeSnippet->getTargetLine()); // Default from line 42
        $this->assertEquals(5, $issue->codeSnippet->getContextLines()); // Default from line 44
        $this->assertEquals([], $issue->codeSnippet->getLines()); // Default from line 43
    }

    public function testFromArrayWithNonArrayCodeSnippet(): void
    {
        // Test line 38-39: When code_snippet is not an array
        /**
         * @var array{message: string, location: array{file: string, line: int}, severity: string, recommendation: string, code_snippet: mixed}
         */
        $data = [
            'message' => 'Test message',
            'location' => [
                'file' => '/path/to/file.php',
                'line' => 42,
            ],
            'severity' => 'high',
            'recommendation' => 'Fix it',
            'code_snippet' => 'not an array', // Not an array - intentionally wrong type to test handling
        ];

        // @phpstan-ignore-next-line - We're intentionally testing with wrong type
        $issue = Issue::fromArray($data);

        // Should handle non-array gracefully (line 38-39 converts to empty array)
        // This will create a CodeSnippet with empty/default values
        $this->assertNotNull($issue->codeSnippet);
        $this->assertInstanceOf(CodeSnippet::class, $issue->codeSnippet);
    }

    public function testFromArrayWithEmptyCodeSnippetArray(): void
    {
        // Test line 38-39: When code_snippet is empty array
        $data = [
            'message' => 'Test message',
            'location' => [
                'file' => '/path/to/file.php',
                'line' => 42,
            ],
            'severity' => 'high',
            'recommendation' => 'Fix it',
            'code_snippet' => [], // Empty array
        ];

        $issue = Issue::fromArray($data);

        // Should create CodeSnippet with defaults
        $this->assertNotNull($issue->codeSnippet);
        $this->assertEquals('', $issue->codeSnippet->getFilePath()); // Default from line 41
        $this->assertEquals(0, $issue->codeSnippet->getTargetLine()); // Default from line 42
        $this->assertEquals([], $issue->codeSnippet->getLines()); // Default from line 43
        $this->assertEquals(5, $issue->codeSnippet->getContextLines()); // Default from line 44
    }

    public function testToArrayIncludesCodeSnippet(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $codeSnippet = new CodeSnippet(
            filePath: '/path/to/file.php',
            targetLine: 42,
            lines: [40 => 'line 40', 41 => 'line 41', 42 => 'line 42'],
            contextLines: 2
        );

        $issue = new Issue(
            message: 'Test message',
            location: $location,
            severity: Severity::High,
            recommendation: 'Fix it',
            codeSnippet: $codeSnippet
        );

        $array = $issue->toArray();

        $this->assertArrayHasKey('code_snippet', $array);
        $snippetArray = $array['code_snippet'];
        $this->assertIsArray($snippetArray);
        $this->assertEquals('/path/to/file.php', $snippetArray['file']);
        $this->assertEquals(42, $snippetArray['target_line']);
        $this->assertArrayHasKey('lines', $snippetArray);
    }

    public function test_creates_issue_with_null_location(): void
    {
        $issue = new Issue(
            message: 'Application is in maintenance mode',
            location: null,
            severity: Severity::High,
            recommendation: 'Disable maintenance mode with: php artisan up',
            code: 'MAINTENANCE_MODE',
            metadata: ['is_down' => true]
        );

        $this->assertNull($issue->location);
        $this->assertEquals('Application is in maintenance mode', $issue->message);
        $this->assertEquals(Severity::High, $issue->severity);
    }

    public function test_to_array_handles_null_location(): void
    {
        $issue = new Issue(
            message: 'Application-wide issue',
            location: null,
            severity: Severity::Medium,
            recommendation: 'Fix this'
        );

        $array = $issue->toArray();

        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('severity', $array);
        $this->assertArrayHasKey('recommendation', $array);

        // Location should not be in array when null (filtered by array_filter)
        $this->assertArrayNotHasKey('location', $array);
    }

    public function test_from_array_handles_missing_location(): void
    {
        /**
         * @var array{message: string, severity: string, recommendation: string}
         */
        $data = [
            'message' => 'Application issue',
            // 'location' key is missing
            'severity' => 'medium',
            'recommendation' => 'Fix it',
        ];

        // Testing with missing location key (valid for nullable location)
        $issue = Issue::fromArray($data);

        $this->assertNull($issue->location);
        $this->assertEquals('Application issue', $issue->message);
    }
}
