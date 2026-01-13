<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use ShieldCI\AnalyzersCore\Enums\Severity;

/**
 * Represents a specific issue found during analysis.
 *
 * The location can be null for application-wide issues that are not
 * tied to a specific file (e.g., maintenance mode, missing dependencies).
 */
final class Issue
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $message,
        public readonly ?Location $location,
        public readonly Severity $severity,
        public readonly string $recommendation,
        public readonly ?string $code = null,
        public readonly array $metadata = [],
        public readonly ?CodeSnippet $codeSnippet = null,
    ) {
    }

    /**
     * Create from array.
     *
     * @param array{message: string, location?: array{file: string, line?: int|null, column?: int|null}|null, severity: string, recommendation: string, code?: string, metadata?: array<string, mixed>, code_snippet?: array<string, mixed>|null} $data
     */
    public static function fromArray(array $data): self
    {
        $codeSnippet = null;
        if (isset($data['code_snippet'])) {
            $snippetData = $data['code_snippet'];
            if (! is_array($snippetData)) {
                $snippetData = [];
            }
            $filePath = $snippetData['file'] ?? '';
            $targetLine = $snippetData['target_line'] ?? 0;
            $lines = $snippetData['lines'] ?? [];
            $contextLines = $snippetData['context_lines'] ?? 5;

            // Type assertions for PHPStan
            assert(is_string($filePath));
            assert(is_int($targetLine));
            assert(is_array($lines));
            assert(is_int($contextLines));

            $codeSnippet = new CodeSnippet(
                filePath: $filePath,
                targetLine: $targetLine,
                lines: $lines,
                contextLines: $contextLines
            );
        }

        return new self(
            message: $data['message'],
            location: isset($data['location']) ? Location::fromArray($data['location']) : null,
            severity: Severity::from($data['severity']),
            recommendation: $data['recommendation'],
            code: $data['code'] ?? null,
            metadata: $data['metadata'] ?? [],
            codeSnippet: $codeSnippet,
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
            'location' => $this->location?->toArray(),
            'severity' => $this->severity->value,
            'recommendation' => $this->recommendation,
            'code' => $this->code,
            'metadata' => $this->metadata ?: null,
            'code_snippet' => $this->codeSnippet?->toArray(),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
