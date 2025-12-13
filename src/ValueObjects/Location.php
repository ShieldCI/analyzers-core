<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use Stringable;

/**
 * Represents a location in code (file, line, column).
 *
 * Line number can be null when the file is missing or unreadable,
 * allowing location reporting without a specific line number.
 */
final class Location implements Stringable
{
    public function __construct(
        public readonly string $file,
        public readonly ?int $line = null,
        public readonly ?int $column = null,
    ) {
    }

    /**
     * Create from array.
     *
     * @param array{file: string, line?: int|null, column?: int|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: $data['file'],
            line: $data['line'] ?? null,
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
     *
     * Formats as:
     * - "file" when line is null
     * - "file:line" when line is set
     * - "file:line:column" when both line and column are set
     */
    public function __toString(): string
    {
        if ($this->line === null) {
            return $this->file;
        }

        $location = "{$this->file}:{$this->line}";

        if ($this->column !== null) {
            $location .= ":{$this->column}";
        }

        return $location;
    }
}
