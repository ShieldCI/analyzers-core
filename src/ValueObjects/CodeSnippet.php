<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use ShieldCI\AnalyzersCore\Support\LineIndexedFile;
use ShieldCI\AnalyzersCore\Support\Utf8;

/**
 * Value object representing a code snippet with context lines around a target line.
 */
class CodeSnippet
{
    /**
     * @param string $filePath The absolute path to the file
     * @param int $targetLine The line number where the issue occurs
     * @param array<int, string> $lines The code lines with line numbers as keys
     * @param int $contextLines Number of lines before/after the target line; a negative count means 0
     */
    public function __construct(
        private string $filePath,
        private int $targetLine,
        private array $lines = [],
        private int $contextLines = 8
    ) {
        // Covers snippets built directly and by Issue::fromArray(), as fromIndexedFile() does.
        $this->contextLines = max($contextLines, 0);
    }

    /**
     * Longest line, in bytes, kept in a snippet. Longer lines are cut to prevent wrapping.
     */
    private const MAX_LINE_BYTES = 250;

    /**
     * Create a CodeSnippet from a file.
     *
     * The file is streamed once to index its lines and then only the lines the snippet needs
     * are read, so memory does not grow with the file size.
     *
     * @param string $filePath Absolute path to the file
     * @param int $targetLine The line number where the issue occurs
     * @param int $contextLines Number of lines to show before and after (default: 8); a negative count means 0
     * @return self|null Returns null if the path is missing, not a regular file, or can't be read
     */
    public static function fromFile(
        string $filePath,
        int $targetLine,
        int $contextLines = 8
    ): ?self {
        $file = LineIndexedFile::open($filePath);

        if ($file === null) {
            return null;
        }

        try {
            return self::fromIndexedFile($filePath, $file, $targetLine, $contextLines);
        } finally {
            $file->close();
        }
    }

    /**
     * Create a CodeSnippet from a file that is already indexed.
     *
     * Gives exactly what fromFile() gives for the same file. It lets a caller producing many
     * snippets from one file index it once.
     *
     * @internal For AbstractAnalyzer's per-run snippet cache; use fromFile().
     *
     * @param string $filePath The path to record on the snippet
     * @param LineIndexedFile $file The indexed file
     * @param int $targetLine The line number where the issue occurs
     * @param int $contextLines Number of lines to show before and after (default: 8); a negative count means 0
     */
    public static function fromIndexedFile(
        string $filePath,
        LineIndexedFile $file,
        int $targetLine,
        int $contextLines = 8
    ): self {
        // A negative count would put the window's start after its end and leave no lines,
        // so it means 0: the target line alone. fromFile() comes through here too.
        $contextLines = max($contextLines, 0);

        [$startLine, $endLine] = self::calculateBoundsWithSmartExpansion(
            $file,
            $targetLine,
            $file->lineCount(),
            $contextLines
        );

        $lines = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            $lines[$i] = rtrim(Utf8::cut($file->head($i) ?? '', self::MAX_LINE_BYTES));
        }

        return new self($filePath, $targetLine, $lines, $contextLines);
    }

    /**
     * Calculate bounds with smart context expansion to include method/class signatures.
     *
     * Uses edge compensation to redistribute unused context lines when the target is
     * near the start or end of a file. Only expands the window upward when a signature
     * is found OUTSIDE the current window — signatures already visible within the
     * centered window are left alone to avoid shrinking above-context.
     *
     * @param LineIndexedFile $file The indexed file
     * @param int $targetLine The target line number
     * @param int $totalLines Total lines in the file
     * @param int $contextLines Lines before/after to include
     * @return array{int, int} [startLine, endLine]
     */
    private static function calculateBoundsWithSmartExpansion(
        LineIndexedFile $file,
        int $targetLine,
        int $totalLines,
        int $contextLines
    ): array {
        // Start with basic centered bounds
        $startLine = max($targetLine - $contextLines, 1);
        $endLine = min($targetLine + $contextLines, $totalLines);

        // Edge compensation: when near file boundaries, redistribute unused lines
        $unusedAbove = ($targetLine - $contextLines) < 1 ? 1 - ($targetLine - $contextLines) : 0;
        $endLine = min($endLine + $unusedAbove, $totalLines);

        $unusedBelow = ($targetLine + $contextLines) > $totalLines ? ($targetLine + $contextLines) - $totalLines : 0;
        $startLine = max($startLine - $unusedBelow, 1);

        // Search for signatures up to 15 lines above target (beyond naive window)
        $searchMin = max($targetLine - 15, 1);
        $signatureLine = self::findSignatureLine($file, $targetLine, $searchMin);

        // Only expand if signature is OUTSIDE the current window (above startLine).
        // Signatures already within the window are visible without adjustment.
        if ($signatureLine !== null && $signatureLine < $startLine) {
            $totalBudget = 2 * $contextLines;
            $linesAbove = $targetLine - $signatureLine;
            $linesBelow = $totalBudget - $linesAbove;

            // Only expand if we can maintain minimum context below target
            $minBelow = min(3, $contextLines);
            if ($linesBelow >= $minBelow) {
                $startLine = $signatureLine;
                $endLine = min($targetLine + $linesBelow, $totalLines);
            }
            // else: signature too far away to include within budget, keep centered
        }

        return [$startLine, $endLine];
    }

    /**
     * Find the line number of a method or class signature above the target line.
     *
     * @param LineIndexedFile $file The indexed file
     * @param int $targetLine The target line number
     * @param int $minLine The minimum line to search from
     * @return int|null The line number of the signature, or null if not found
     */
    private static function findSignatureLine(
        LineIndexedFile $file,
        int $targetLine,
        int $minLine
    ): ?int {
        // Search backwards from target line
        for ($lineNum = $targetLine - 1; $lineNum >= $minLine; $lineNum--) {
            // A bounded stand-in for trim($line): exact for '}' and '};', and enough of the
            // line for these start-anchored patterns to match exactly when they would on it.
            // Null for the lines past the end of the file a target beyond it searches.
            $trimmed = $file->probe($lineNum);

            if ($trimmed === null) {
                continue;
            }

            // Check for class/interface/trait signature
            if (preg_match('/^(abstract\s+)?(final\s+)?(class|interface|trait|enum)\s+\w+/i', $trimmed)) {
                return $lineNum;
            }

            // Check for method signature (public/protected/private function)
            if (preg_match('/^(public|protected|private|static)\s+(static\s+)?function\s+\w+/i', $trimmed)) {
                return $lineNum;
            }

            // Check for standalone function
            if (preg_match('/^function\s+\w+/i', $trimmed)) {
                return $lineNum;
            }

            // Stop if we hit a closing brace at the beginning (end of previous method/class)
            if ($trimmed === '}' || $trimmed === '};') {
                break;
            }
        }

        return null;
    }

    /**
     * Get the code lines with line numbers as keys.
     *
     * @return array<int, string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Get the target line number where the issue occurs.
     */
    public function getTargetLine(): int
    {
        return $this->targetLine;
    }

    /**
     * Get the file path.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get the number of context lines.
     */
    public function getContextLines(): int
    {
        return $this->contextLines;
    }

    /**
     * Convert the code snippet to an array for JSON serialization.
     *
     * @return array{file: string, target_line: int, lines: array<int, string>, context_lines: int}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->filePath,
            'target_line' => $this->targetLine,
            'lines' => $this->lines,
            'context_lines' => $this->contextLines,
        ];
    }
}
