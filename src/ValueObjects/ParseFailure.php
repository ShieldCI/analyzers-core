<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;

/**
 * A file the analyzer suite was handed but could not turn into an AST.
 *
 * Every AST-based analyzer reads an empty AST as "nothing to report", so without
 * this record a file that could not be read is indistinguishable from one that was
 * read and found clean. This is the evidence that the difference existed.
 */
final class ParseFailure
{
    /**
     * @param  string|null  $path  Null when the caller parsed a string and supplied no origin.
     * @param  int|null  $line  Null when the parser reported no usable line, and for
     *                          causes that never reached a parser.
     * @param  string  $message  The parser's own raw message, or the reason the bytes
     *                            could not be read.
     */
    public function __construct(
        public readonly ?string $path,
        public readonly ?int $line,
        public readonly string $message,
        public readonly ParseFailureCause $cause,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'path' => $this->path,
            'line' => $this->line,
            'message' => $this->message,
            'cause' => $this->cause->value,
        ], fn ($value) => $value !== null);
    }
}
