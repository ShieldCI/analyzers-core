<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

use RuntimeException;
use SplFileObject;

/**
 * Value object representing a code snippet with context lines around a target line.
 */
class CodeSnippet
{
    /**
     * @param string $filePath The absolute path to the file
     * @param int $targetLine The line number where the issue occurs
     * @param array<int, string> $lines The code lines with line numbers as keys
     * @param int $contextLines Number of lines before/after the target line
     */
    public function __construct(
        private string $filePath,
        private int $targetLine,
        private array $lines = [],
        private int $contextLines = 8
    ) {
    }

    /**
     * Create a CodeSnippet from a file.
     *
     * @param string $filePath Absolute path to the file
     * @param int $targetLine The line number where the issue occurs
     * @param int $contextLines Number of lines to show before and after (default: 8)
     * @return self|null Returns null if file doesn't exist or can't be read
     */
    public static function fromFile(
        string $filePath,
        int $targetLine,
        int $contextLines = 8
    ): ?self {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return null;
        }

        try {
            $file = new SplFileObject($filePath);

            // Get total number of lines
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key() + 1;

            // Calculate bounds with smart context expansion
            [$startLine, $endLine] = self::calculateBoundsWithSmartExpansion(
                $file,
                $targetLine,
                $totalLines,
                $contextLines
            );

            // Read lines
            $lines = [];
            $file->seek($startLine - 1);

            for ($i = $startLine; $i <= $endLine; $i++) {
                // Truncate long lines to prevent wrapping (max 250 chars)
                $line = $file->current();
                $lines[$i] = rtrim(is_string($line) ? substr($line, 0, 250) : '');
                $file->next();
            }

            return new self($filePath, $targetLine, $lines, $contextLines);
        } catch (RuntimeException $e) { // @codeCoverageIgnoreStart
            // File reading error, return null
            return null;
        } // @codeCoverageIgnoreEnd
    }

    /**
     * Calculate bounds with smart context expansion to include method/class signatures.
     *
     * Uses edge compensation to redistribute unused context lines when the target is
     * near the start or end of a file. Only expands the window upward when a signature
     * is found OUTSIDE the current window — signatures already visible within the
     * centered window are left alone to avoid shrinking above-context.
     *
     * @param SplFileObject $file The file object
     * @param int $targetLine The target line number
     * @param int $totalLines Total lines in the file
     * @param int $contextLines Lines before/after to include
     * @return array{int, int} [startLine, endLine]
     */
    private static function calculateBoundsWithSmartExpansion(
        SplFileObject $file,
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
     * @param SplFileObject $file The file object
     * @param int $targetLine The target line number
     * @param int $minLine The minimum line to search from
     * @return int|null The line number of the signature, or null if not found
     */
    private static function findSignatureLine(
        SplFileObject $file,
        int $targetLine,
        int $minLine
    ): ?int {
        // Search backwards from target line
        for ($lineNum = $targetLine - 1; $lineNum >= $minLine; $lineNum--) {
            $file->seek($lineNum - 1);
            $line = $file->current();

            if (! is_string($line)) {
                continue; // @codeCoverageIgnore
            }

            $trimmed = trim($line);

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
