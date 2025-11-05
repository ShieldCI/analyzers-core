<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Enums;

/**
 * Analyzer category.
 */
enum Category: string
{
    case Security = 'security';
    case Performance = 'performance';
    case CodeQuality = 'code_quality';
    case BestPractices = 'best_practices';
    case Reliability = 'reliability';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Performance => 'Performance',
            self::CodeQuality => 'Code Quality',
            self::BestPractices => 'Best Practices',
            self::Reliability => 'Reliability',
        };
    }

    /**
     * Get icon representation.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Security => '🔒',
            self::Performance => '⚡',
            self::CodeQuality => '📊',
            self::BestPractices => '✨',
            self::Reliability => '🛡️',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::Security => 'Security vulnerabilities and risks',
            self::Performance => 'Performance issues and optimizations',
            self::CodeQuality => 'Code quality and maintainability',
            self::BestPractices => 'Best practices and conventions',
            self::Reliability => 'Reliability and stability issues',
        };
    }
}
