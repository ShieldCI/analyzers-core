<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\Utf8;

class Utf8Test extends TestCase
{
    /**
     * @dataProvider validCuts
     */
    public function testCutKeepsValidUtf8Valid(string $string, int $bytes, string $expected): void
    {
        $result = Utf8::cut($string, $bytes);

        $this->assertSame($expected, $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function validCuts(): array
    {
        return [
            'ascii shorter than the cut' => ['abc', 5, 'abc'],
            'ascii exactly the cut' => ['abc', 3, 'abc'],
            'ascii longer than the cut' => ['abcdef', 3, 'abc'],
            'empty string' => ['', 3, ''],
            'negative cut means zero' => ['abc', -1, ''],
            'negative cut on multibyte' => ['aé', -1, ''],
            'cut 0 before a lead byte' => ['é', 0, ''],
            'cut 1 inside a 2-byte char' => ['é', 1, ''],
            'cut 1 inside a 4-byte char' => ['😀', 1, ''],
            'cut 2 inside a 4-byte char' => ['😀', 2, ''],
            'cut 3 inside a 4-byte char' => ['😀', 3, ''],
            'cut 2 after a whole 2-byte char' => ['éx', 2, 'é'],
            'cut 3 after a whole 3-byte char' => ['€x', 3, '€'],
            'cut 3 inside a 4-byte char after a prefix' => ['a😀', 3, 'a'],
            '2-byte char split after its lead' => [str_repeat('a', 199).'étail', 200, str_repeat('a', 199)],
            '3-byte char split after 1 byte' => [str_repeat('a', 199).'€tail', 200, str_repeat('a', 199)],
            '3-byte char split after 2 bytes' => [str_repeat('a', 198).'€tail', 200, str_repeat('a', 198)],
            '4-byte char split after 1 byte' => [str_repeat('a', 199).'😀tail', 200, str_repeat('a', 199)],
            '4-byte char split after 2 bytes' => [str_repeat('a', 198).'😀tail', 200, str_repeat('a', 198)],
            '4-byte char split after 3 bytes' => [str_repeat('a', 197).'😀tail', 200, str_repeat('a', 197)],
            '2-byte char ending at the cut' => [str_repeat('a', 198).'étail', 200, str_repeat('a', 198).'é'],
            '4-byte char ending at the cut' => [str_repeat('a', 196).'😀tail', 200, str_repeat('a', 196).'😀'],
        ];
    }

    /**
     * @dataProvider invalidCuts
     */
    public function testCutLeavesInvalidBytesWhereTheyFall(string $string, int $bytes, string $expected): void
    {
        $this->assertSame($expected, Utf8::cut($string, $bytes));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function invalidCuts(): array
    {
        $emoji = chr(0xF0).chr(0x9F).chr(0x98).chr(0x80);
        $c = chr(0x80);

        return [
            // A whole character before a stray continuation byte at the cut is kept whole.
            'emoji then stray byte, cut 4' => [$emoji.$c.'tail', 4, $emoji],
            'prefix, emoji then stray byte, cut 5' => ['a'.$emoji.$c.'tail', 5, 'a'.$emoji],
            'e-acute then stray byte, cut 2' => ['é'.$c.'x', 2, 'é'],
            'ascii then stray bytes, cut 3' => ['ab'.$c.$c, 3, 'ab'.$c],
            'run of stray bytes after ascii' => [str_repeat('a', 10).str_repeat($c, 10), 15, str_repeat('a', 10).str_repeat($c, 5)],
            // Small cuts on a string of stray bytes never read before offset 0.
            'stray bytes, cut 0' => [str_repeat($c, 4), 0, ''],
            // Unguarded, a cut at 0 would read the lead byte at the end as $string[-1].
            'stray byte, cut 0, lead byte at the end' => [$c.chr(0xC3), 0, ''],
            'stray bytes, cut 1' => [str_repeat($c, 4), 1, $c],
            'stray bytes, cut 2' => [str_repeat($c, 4), 2, $c.$c],
            'stray bytes, cut 3' => [str_repeat($c, 5), 3, $c.$c.$c],
            // A lead byte whose sequence is broken before the cut is not a character to protect.
            'broken sequence before the cut' => ['xx'.chr(0xE2).'a'.$c.$c, 4, 'xx'.chr(0xE2).'a'],
            // A lead byte with too few continuation bytes for its own length.
            'truncated sequence then text' => [chr(0xF0).chr(0x9F).chr(0x98).'tail', 2, chr(0xF0).chr(0x9F)],
            'truncated sequence at the end' => [chr(0xF0).chr(0x9F).chr(0x98), 2, chr(0xF0).chr(0x9F)],
            // An overlong lead byte starts no sequence.
            'overlong lead byte' => [chr(0xC0).$c.'x', 1, chr(0xC0)],
        ];
    }
}
