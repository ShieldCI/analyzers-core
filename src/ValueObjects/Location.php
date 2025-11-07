<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use Stringable;

/**
 * Represents a location in code (file, line, column).
 */
final class Location implements Stringable
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?int $column = null,
    ) {
    }

    /**
     * Create from array.
     *
     * @param array{file: string, line: int, column?: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: $data['file'],
            line: $data['line'],
            column: $data['column'] ?? null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'file' => $this->file,
            'line' => $this->line,
            'column' => $this->column,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get string representation.
     */
    public function __toString(): string
    {
        $location = "{$this->file}:{$this->line}";

        if ($this->column !== null) {
            $location .= ":{$this->column}";
        }

        return $location;
    }
}
