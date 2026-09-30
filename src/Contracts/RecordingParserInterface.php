<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Contracts;

use ShieldCI\AnalyzersCore\ValueObjects\ParseFailure;

/**
 * A parser that keeps a log of the files it could not read.
 *
 * ParserInterface says nothing about failure, so a helper that takes one has no way to tell a
 * file that parsed to nothing from a file that never parsed at all -- and every helper in this
 * package that reads an AST has to answer exactly that question before it can report anything
 * honest. This is the capability that makes the answer reachable.
 *
 * It extends ParserInterface rather than standing beside it as an intersection because the
 * package floor is PHP 8.1: `?(ParserInterface&RecordingParserInterface)` needs DNF types,
 * which arrived in 8.2, and every consumer of this contract needs it in a nullable or
 * defaulted position somewhere.
 *
 * All five methods are one capability, not three plus two. resetFailures() clears the recovery
 * log as well as the failure log, and recoveries() is defined as a subset of failures(), so a
 * contract carrying only the first three would declare a method whose documented effect lands
 * on state the contract does not admit exists -- and two conforming implementations could
 * disagree about it without either being wrong.
 */
interface RecordingParserInterface extends ParserInterface
{
    /**
     * Files handed to this parser that never produced an AST.
     *
     * @return list<ParseFailure>
     */
    public function failures(): array;

    /**
     * Whether a failure is recorded under $path.
     *
     * Spell $path as parseFile() was given it, or as parseCode() was given its origin:
     * implementations key records on that string and none are required to normalise.
     */
    public function hasFailure(string $path): bool;

    /**
     * Empty the failure log, and the recovery log with it.
     *
     * Run-scoped, and deliberately separate from any AST cache the implementation keeps: a log
     * tied to a per-analyzer cache would survive only until the next analyzer started.
     *
     * Separate in the other direction too: a cache must not hide a failure from a log that has
     * since been reset. Parsing a file that still does not parse records it again, whether or
     * not any cache was cleared first.
     */
    public function resetFailures(): void;

    /**
     * Note that a caller recovered usable syntax from a file that would not parse.
     *
     * @return bool Whether the path was recorded -- false when no failure is on file for it.
     */
    public function recordRecovery(string $path): bool;

    /**
     * Paths some caller recovered usable syntax from. Always a subset of failures().
     *
     * @return list<string>
     */
    public function recoveries(): array;
}
