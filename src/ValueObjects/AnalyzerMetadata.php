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
     * Static resolver for documentation base URL.
     *
     * This allows frameworks (like Laravel) to inject their config() resolver
     * while keeping analyzers-core framework-agnostic.
     *
     * @var (callable(): string)|null
     */
    private static $docsBaseUrlResolver = null;

    /**
     * Default documentation base URL.
     */
    private const DEFAULT_DOCS_BASE_URL = 'https://docs.shieldci.com';

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
        public readonly ?int $timeToFix = null,
    ) {
    }

    /**
     * Set the documentation base URL resolver.
     *
     * This allows frameworks to inject their configuration mechanism.
     * Example (Laravel): AnalyzerMetadata::setDocsBaseUrlResolver(fn () => config('shieldci.docs_base_url'));
     *
     * @param callable(): string $resolver
     */
    public static function setDocsBaseUrlResolver(callable $resolver): void
    {
        self::$docsBaseUrlResolver = $resolver;
    }

    /**
     * Reset the documentation base URL resolver (useful for testing).
     */
    public static function resetDocsBaseUrlResolver(): void
    {
        self::$docsBaseUrlResolver = null;
    }

    /**
     * Get the documentation URL for this analyzer.
     *
     * If an explicit docsUrl was provided, returns that.
     * Otherwise, auto-generates from base URL + category + id.
     *
     * Example: https://docs.shieldci.com/analyzers/security/sql-injection
     */
    public function getDocsUrl(): string
    {
        // If explicit URL was provided, use it
        if ($this->docsUrl !== null) {
            return $this->docsUrl;
        }

        // Get base URL from resolver or use default
        if (self::$docsBaseUrlResolver !== null) {
            /** @var mixed $resolvedUrl */
            $resolvedUrl = (self::$docsBaseUrlResolver)();
            $baseUrl = is_string($resolvedUrl) ? $resolvedUrl : self::DEFAULT_DOCS_BASE_URL;
        } else {
            $baseUrl = self::DEFAULT_DOCS_BASE_URL;
        }

        // Remove trailing slash
        $baseUrl = rtrim($baseUrl, '/');

        return "{$baseUrl}/analyzers/{$this->category->value}/{$this->id}";
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
            'docs_url' => $this->getDocsUrl(),
            'time_to_fix' => $this->timeToFix,
        ], fn ($value) => $value !== null);
    }
}
