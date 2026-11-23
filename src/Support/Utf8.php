<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Byte-level UTF-8 helpers that need no mbstring.
 *
 * @internal Shared by CodeSnippet and MessageHelper; not part of the public API.
 */
final class Utf8
{
    /**
     * Cut a string to at most $bytes bytes without splitting a UTF-8 character.
     *
     * A cut that lands inside a multibyte sequence (a lead byte followed by the continuation
     * bytes it announces) backs off to the start of that sequence, so valid UTF-8 stays valid
     * and the JSON report can encode it. Only the byte structure is checked, so an overlong or
     * surrogate sequence straddling the cut is dropped as well; a stray continuation byte or a
     * truncated sequence is cut where it falls. A negative byte count means 0.
     */
    public static function cut(string $string, int $bytes): string
    {
        $bytes = max(0, $bytes);

        // A cut at 0 splits nothing, and the walk below would read $string[-1] from the end.
        if ($bytes === 0 || strlen($string) <= $bytes || ! self::isContinuationByte($string[$bytes])) {
            return substr($string, 0, $bytes);
        }

        // Walk back over at most three continuation bytes to the lead byte, never past 0.
        $floor = max(0, $bytes - 4);
        $lead = $bytes - 1;

        while ($lead > $floor && self::isContinuationByte($string[$lead])) {
            $lead--;
        }

        $length = self::sequenceLength(ord($string[$lead]));

        if ($length > $bytes - $lead && self::isWellFormedSequence($string, $lead, $length)) {
            return substr($string, 0, $lead);
        }

        return substr($string, 0, $bytes);
    }

    private static function isContinuationByte(string $byte): bool
    {
        return (ord($byte) & 0xC0) === 0x80;
    }

    /**
     * The byte length of the UTF-8 sequence a lead byte starts, or 0 if it starts none.
     */
    private static function sequenceLength(int $byte): int
    {
        return match (true) {
            $byte >= 0xC2 && $byte <= 0xDF => 2,
            $byte >= 0xE0 && $byte <= 0xEF => 3,
            $byte >= 0xF0 && $byte <= 0xF4 => 4,
            default => 0,
        };
    }

    private static function isWellFormedSequence(string $string, int $lead, int $length): bool
    {
        for ($i = $lead + 1; $i < $lead + $length; $i++) {
            if (! isset($string[$i]) || ! self::isContinuationByte($string[$i])) {
                return false;
            }
        }

        return true;
    }
}
