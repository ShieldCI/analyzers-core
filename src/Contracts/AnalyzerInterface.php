<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Contracts;

use ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata;

/**
 * Core interface that all analyzers must implement.
 */
interface AnalyzerInterface
{
    /**
     * Run the analysis and return the result.
     */
    public function analyze(): ResultInterface;

    /**
     * Get analyzer metadata.
     */
    public function getMetadata(): AnalyzerMetadata;

    /**
     * Check if this analyzer should run in the current context.
     */
    public function shouldRun(): bool;

    /**
     * Get the unique identifier for this analyzer.
     */
    public function getId(): string;
}
