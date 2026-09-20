<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use Throwable;

/**
 * Helper utilities for summarising exception stack traces.
 */
class TraceHelper
{
    /**
     * How many frames a summarised trace keeps, counted from the throw site outwards.
     */
    public const MAX_FRAMES = 20;

    /**
     * Summarise a throwable's stack trace as a structured, argument-free frame list.
     *
     * Named for what it keeps rather than what it strips. PHP hands over each frame as
     * array{function: string, line?: int, file?: string, class?: class-string,
     * type?: '->'|'::', args?: list<mixed>, object?: object}, and two of those keys
     * carry caller data:
     *
     * - 'args' holds every argument the frame was called with. Rendering it is what
     *   getTraceAsString() does - truncating strings at 15 characters, so anything
     *   shorter survives in full - and it is the whole of #64.
     * - 'object' holds the live receiver. getTraceAsString() never rendered it, so it
     *   is easy to forget it is there, but json_encode() would serialise its public
     *   properties straight into the uploaded report.
     *
     * Building each frame from four named keys drops both by construction. Reaching
     * for unset($frame['args']) instead would have left 'object' behind - the reason
     * this is an allowlist and not a denylist.
     *
     * File paths are relativised against $basePath, matching the treatment Issue
     * locations already get. A frame outside the base path keeps its original
     * spelling, which is PathHelper::relativeTo()'s documented behaviour.
     *
     * @param  string  $basePath  Root to relativise frame paths against; '' leaves them absolute
     * @param  int  $maxFrames  How many frames to keep; caps an otherwise unbounded payload
     * @return list<array{file: string|null, line: int|null, function: string, class: string|null}>
     */
    public static function frames(Throwable $e, string $basePath = '', int $maxFrames = self::MAX_FRAMES): array
    {
        $frames = [];

        // max(0, ...) is load-bearing: array_slice() reads a negative length as
        // "stop this many from the end", which would return almost the whole
        // trace rather than capping it.
        foreach (array_slice($e->getTrace(), 0, max(0, $maxFrames)) as $frame) {
            $file = $frame['file'] ?? null;

            $frames[] = [
                'file' => $file === null ? null : PathHelper::relativeTo($file, $basePath),
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'],
                'class' => $frame['class'] ?? null,
            ];
        }

        return $frames;
    }
}
