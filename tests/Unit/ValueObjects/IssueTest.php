<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\Severity;
use ShieldCI\AnalyzersCore\ValueObjects\{Issue, Location};

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
}
