<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\ValueObjects;

/**
 * Whether the parser this package reaches for by default understands the PHP it is running on.
 *
 * AstParser defaults to the newest version the installed nikic/php-parser supports, and
 * classify() asks the running PHP for a second opinion, so a parser older than the runtime
 * never throws and never returns an error. Every file using current syntax quietly becomes a
 * ParseFailure with cause UnsupportedSyntax instead of an AST, and a whole project can pass
 * through the suite and be reported clean because nothing in it was ever read. This is that
 * condition as a fact, before anyone decides what to say about it.
 *
 * UnsupportedSyntax does not make this redundant. It is a per-file, retrospective signal: it
 * says nothing until some file happens to use syntax the parser lacks, it then fans one
 * toolchain problem out into one entry per file, and each entry reads like the user's own
 * broken code. This is the run-level statement the enum cannot make.
 *
 * Carries no message, no severity and no recommendation, for the same reason
 * ParseFailureCause carries no label(): rendering a sentence is a reporting concern, and
 * reporting lives in the consuming package.
 *
 * It also deliberately validates nothing. A parser newer than the runtime, level with it, or
 * older than it are all real states this has to hold -- and the older one is exactly the case
 * no CI leg of this package can produce, so refusing to construct it would make the condition
 * untestable as well as unobservable.
 */
final class ParserCompatibility
{
    /** The newest PHP the installed parser understands, patch component discarded. */
    public readonly int $parserVersionId;

    /** The PHP actually executing, patch component discarded. */
    public readonly int $runtimeVersionId;

    /**
     * Both ids are in PHP_VERSION_ID form (major * 10000 + minor * 100), patch always zero.
     *
     * That is load-bearing rather than incidental, and it is enforced here rather than merely
     * expected of callers. A true PHP_VERSION_ID carries the patch: PHP 8.4.26 is 80426, which
     * compares as newer than a parser's 80400, so every patch release would report a false
     * mismatch and render as "php-parser understands 8.4, but this is running on 8.4".
     *
     * AstParser::compatibility() cannot trip that -- PhpVersion::fromComponents() feeds it a
     * major and a minor only -- but this is public API, and issue #74 asked for PHP_VERSION_ID
     * by name, so it is the first thing a caller building this object by hand reaches for.
     * Normalising costs one intdiv and removes the whole class of bug.
     *
     * @param  int  $parserVersionId  The newest PHP the installed parser understands.
     * @param  int  $runtimeVersionId  The PHP actually executing.
     */
    public function __construct(int $parserVersionId, int $runtimeVersionId)
    {
        $this->parserVersionId = self::discardPatch($parserVersionId);
        $this->runtimeVersionId = self::discardPatch($runtimeVersionId);
    }

    /**
     * Drop the patch component of a PHP_VERSION_ID, leaving major and minor.
     *
     * Arithmetic rather than a string operation for the same reason render() is: the minor
     * occupies two digits once it reaches ten, so 81003 (PHP 8.10.3) must become 81000 and
     * not 81003 truncated at some fixed offset.
     */
    private static function discardPatch(int $versionId): int
    {
        return intdiv($versionId, 100) * 100;
    }

    /**
     * Whether the parser can read everything this runtime can run.
     *
     * Equal versions are supported: the parser having caught up exactly is the normal healthy
     * state, not a near miss. A parser *newer* than the runtime is supported too -- it
     * understands syntax this PHP would reject, which costs nothing, because a file using that
     * syntax is rejected by the runtime's own second opinion in AstParser::classify() and
     * reported as the genuine syntax error it is for this user.
     *
     * Necessary but not sufficient, and the gap is worth naming. php-parser documents
     * getNewestSupported() as "the newest PHP version supported by this library. Support for
     * this version may be partial, if it is still under development." So while a release is
     * landing support for the PHP it claims, a runtime on that version reads as level here and
     * answers true, and files using the parts not yet implemented still become ParseFailure
     * with cause UnsupportedSyntax. This catches a parser that is behind by a whole version,
     * which is the #72 condition and the one no other signal in the package can see. It does
     * not catch one that is nominally level and incomplete; nothing available to us can.
     */
    public function isSupported(): bool
    {
        return $this->parserVersionId >= $this->runtimeVersionId;
    }

    /**
     * The newest PHP the parser understands, rendered as "8.5".
     */
    public function parserVersion(): string
    {
        return self::render($this->parserVersionId);
    }

    /**
     * The running PHP, rendered as "8.5".
     */
    public function runtimeVersion(): string
    {
        return self::render($this->runtimeVersionId);
    }

    /**
     * The two versions and the verdict, ready to sit beside a failure list in a report.
     *
     * Unlike ParseFailure::toArray() there is nothing to array_filter: every field is always
     * present, because "no parser version" is not a state this object can be in.
     *
     * The verdict ships rather than leaving a consumer to compare the two rendered strings,
     * and the reason is the second digit: "8.10" is older than "8.5" to a string comparison
     * and newer to a human, so a consumer re-deriving the answer from these two values would
     * get PHP 8.10 exactly backwards. Do not drop 'supported' as redundant with the other
     * two keys -- it is the only one of the three that is safe to compare.
     *
     * @return array{supported: bool, parser_version: string, runtime_version: string}
     */
    public function toArray(): array
    {
        return [
            'supported' => $this->isSupported(),
            'parser_version' => $this->parserVersion(),
            'runtime_version' => $this->runtimeVersion(),
        ];
    }

    /**
     * Render a PHP_VERSION_ID as "8.5".
     *
     * The minor is (id % 10000) / 100, not the second digit, because a minor above nine
     * occupies two of them: 81000 is PHP 8.10 and 100100 is PHP 10.1. Neither exists yet,
     * which is precisely why a "simplification" to substr() or a string split would read as
     * correct, survive review, and then be wrong in the one release where it matters.
     */
    private static function render(int $versionId): string
    {
        return sprintf('%d.%d', intdiv($versionId, 10000), intdiv($versionId % 10000, 100));
    }
}
