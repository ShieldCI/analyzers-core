<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use ShieldCI\AnalyzersCore\Enums\Severity;

/**
 * Represents a specific issue found during analysis.
 */
final class Issue
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $message,
        public readonly Location $location,
        public readonly Severity $severity,
        public readonly string $recommendation,
        public readonly ?string $code = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Create from array.
     *
     * @param array{message: string, location: array{file: string, line: int, column?: int}, severity: string, recommendation: string, code?: string, metadata?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            message: $data['message'],
            location: Location::fromArray($data['location']),
            severity: Severity::from($data['severity']),
            recommendation: $data['recommendation'],
            code: $data['code'] ?? null,
            metadata: $data['metadata'] ?? [],
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
            'message' => $this->message,
            'location' => $this->location->toArray(),
            'severity' => $this->severity->value,
            'recommendation' => $this->recommendation,
            'code' => $this->code,
            'metadata' => $this->metadata ?: null,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
