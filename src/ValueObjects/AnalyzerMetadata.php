<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use ShieldCI\AnalyzersCore\Enums\{Category, Severity};

/**
 * Metadata about an analyzer.
 */
final class AnalyzerMetadata
{
    /**
     * @param array<string> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly Category $category,
        public readonly Severity $severity,
        public readonly array $tags = [],
        public readonly ?string $docsUrl = null,
    ) {
    }

    /**
     * Create from array.
     *
     * @param array{id: string, name: string, description: string, category: string, severity: string, tags?: array<string>, docs_url?: string, time_to_fix?: int|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            description: $data['description'],
            category: Category::from($data['category']),
            severity: Severity::from($data['severity']),
            tags: $data['tags'] ?? [],
            docsUrl: $data['docs_url'] ?? null,
            timeToFix: $data['time_to_fix'] ?? null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'tags' => $this->tags ?: null,
            'docs_url' => $this->docsUrl,
            'time_to_fix' => $this->timeToFix,
        ], fn ($value) => $value !== null);
    }
}
