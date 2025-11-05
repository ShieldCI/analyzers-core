<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Enums;

/**
 * Issue severity level.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * Get numeric level (higher = more severe).
     */
    public function level(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
    }

    /**
     * Get color representation.
     */
    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'orange',
            self::Medium => 'yellow',
            self::Low => 'blue',
            self::Info => 'gray',
        };
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Info => 'Info',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::Critical => 'Critical security or stability issue requiring immediate attention',
            self::High => 'High priority issue that should be addressed soon',
            self::Medium => 'Medium priority issue that should be considered',
            self::Low => 'Low priority issue or minor improvement',
            self::Info => 'Informational message or suggestion',
        };
    }
}
