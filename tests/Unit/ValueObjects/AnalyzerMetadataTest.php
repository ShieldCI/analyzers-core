<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\{Category, Severity};
use ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata;

class AnalyzerMetadataTest extends TestCase
{
    public function testCanBeCreated(): void
    {
        $metadata = new AnalyzerMetadata(
            id: 'test-analyzer',
            name: 'Test Analyzer',
            description: 'Test description',
            category: Category::Security,
            severity: Severity::High
        );

        $this->assertEquals('test-analyzer', $metadata->id);
        $this->assertEquals('Test Analyzer', $metadata->name);
        $this->assertEquals('Test description', $metadata->description);
        $this->assertEquals(Category::Security, $metadata->category);
        $this->assertEquals(Severity::High, $metadata->severity);
        $this->assertEquals([], $metadata->tags);
        $this->assertNull($metadata->docsUrl);
    }

    public function testCanBeCreatedWithTagsAndDocsUrl(): void
    {
        $tags = ['sql', 'database', 'injection'];
        $docsUrl = 'https://docs.example.com/sql-injection';

        $metadata = new AnalyzerMetadata(
            id: 'sql-injection',
            name: 'SQL Injection Detector',
            description: 'Detects SQL injection vulnerabilities',
            category: Category::Security,
            severity: Severity::Critical,
            tags: $tags,
            docsUrl: $docsUrl
        );

        $this->assertEquals($tags, $metadata->tags);
        $this->assertEquals($docsUrl, $metadata->docsUrl);
    }

    public function testCanBeCreatedFromArray(): void
    {
        $data = [
            'id' => 'test-analyzer',
            'name' => 'Test Analyzer',
            'description' => 'Test description',
            'category' => 'security',
            'severity' => 'high',
            'tags' => ['tag1', 'tag2'],
            'docs_url' => 'https://docs.example.com',
        ];

        $metadata = AnalyzerMetadata::fromArray($data);

        $this->assertEquals('test-analyzer', $metadata->id);
        $this->assertEquals('Test Analyzer', $metadata->name);
        $this->assertEquals('Test description', $metadata->description);
        $this->assertEquals(Category::Security, $metadata->category);
        $this->assertEquals(Severity::High, $metadata->severity);
        $this->assertEquals(['tag1', 'tag2'], $metadata->tags);
        $this->assertEquals('https://docs.example.com', $metadata->docsUrl);
    }

    public function testCanBeCreatedFromArrayWithoutOptionalFields(): void
    {
        $data = [
            'id' => 'test-analyzer',
            'name' => 'Test Analyzer',
            'description' => 'Test description',
            'category' => 'security',
            'severity' => 'high',
        ];

        $metadata = AnalyzerMetadata::fromArray($data);

        $this->assertEquals([], $metadata->tags);
        $this->assertNull($metadata->docsUrl);
    }

    public function testToArray(): void
    {
        $metadata = new AnalyzerMetadata(
            id: 'test-analyzer',
            name: 'Test Analyzer',
            description: 'Test description',
            category: Category::Security,
            severity: Severity::High,
            tags: ['tag1', 'tag2'],
            docsUrl: 'https://docs.example.com'
        );

        $array = $metadata->toArray();

        $this->assertEquals([
            'id' => 'test-analyzer',
            'name' => 'Test Analyzer',
            'description' => 'Test description',
            'category' => 'security',
            'severity' => 'high',
            'tags' => ['tag1', 'tag2'],
            'docs_url' => 'https://docs.example.com',
        ], $array);
    }

    public function testToArrayFiltersEmptyArrays(): void
    {
        $metadata = new AnalyzerMetadata(
            id: 'test-analyzer',
            name: 'Test Analyzer',
            description: 'Test description',
            category: Category::Security,
            severity: Severity::High
        );

        $array = $metadata->toArray();

        $this->assertArrayNotHasKey('tags', $array);
        $this->assertArrayNotHasKey('docs_url', $array);
    }

    public function testIsReadonly(): void
    {
        $metadata = new AnalyzerMetadata(
            id: 'test-analyzer',
            name: 'Test Analyzer',
            description: 'Test description',
            category: Category::Security,
            severity: Severity::High
        );

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $metadata->id = 'new-id';
    }
}
