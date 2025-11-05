<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Enums;

/**
 * Analysis result status.
 */
enum Status: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Warning = 'warning';
    case Skipped = 'skipped';
    case Error = 'error';

    /**
     * Check if status represents success.
     */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::Passed, self::Skipped => true,
            default => false,
        };
    }

    /**
     * Get emoji representation.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::Passed => '✓',
            self::Failed => '✗',
            self::Warning => '⚠',
            self::Skipped => '⊝',
            self::Error => '⚡',
        };
    }

    /**
     * Get color representation.
     */
    public function color(): string
    {
        return match ($this) {
            self::Passed => 'green',
            self::Failed => 'red',
            self::Warning => 'yellow',
            self::Skipped => 'gray',
            self::Error => 'red',
        };
    }
}
