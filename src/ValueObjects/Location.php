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
        // Note: We accept any line/column values (including 0 or negative)
        // Invalid values are handled gracefully in __toString() and toArray()
        // This prevents crashes when line number detection fails
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
     * Invalid line/column numbers (< 1) are omitted from the output.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $array = ['file' => $this->file];

        // Only include line if it's valid (>= 1)
        if ($this->line !== null && $this->line >= 1) {
            $array['line'] = $this->line;
        }

        // Only include column if it's valid (>= 1)
        if ($this->column !== null && $this->column >= 1) {
            $array['column'] = $this->column;
        }

        return $array;
    }

    /**
     * Get string representation.
     *
     * Formats as:
     * - "file" when line is null or invalid (< 1)
     * - "file:line" when line is valid
     * - "file:line:column" when both line and column are valid
     *
     * Invalid line/column numbers (< 1) are treated as null for graceful degradation.
     */
    public function __toString(): string
    {
        // Treat invalid line numbers (< 1) as null
        if ($this->line === null || $this->line < 1) {
            return $this->file;
        }

        $location = "{$this->file}:{$this->line}";

        // Treat invalid column numbers (< 1) as null
        if ($this->column !== null && $this->column >= 1) {
            $location .= ":{$this->column}";
        }

        return $location;
    }
}
