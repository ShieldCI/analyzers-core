<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\ValueObjects\ParserCompatibility;

class ParserCompatibilityTest extends TestCase
{
    public function testIsNotSupportedWhenTheRuntimeIsNewerThanTheParser(): void
    {
        $compatibility = new ParserCompatibility(80500, 80600);

        $this->assertFalse($compatibility->isSupported());
    }

    public function testRendersBothVersionsAsMajorMinor(): void
    {
        $compatibility = new ParserCompatibility(80500, 80600);

        $this->assertSame('8.5', $compatibility->parserVersion());
        $this->assertSame('8.6', $compatibility->runtimeVersion());
    }

    public function testToArrayCarriesBothVersionsAndTheVerdict(): void
    {
        $compatibility = new ParserCompatibility(80500, 80600);

        $this->assertSame([
            'supported' => false,
            'parser_version' => '8.5',
            'runtime_version' => '8.6',
        ], $compatibility->toArray());
    }

    public function testIsSupportedWhenTheParserIsNewerThanTheRuntime(): void
    {
        $compatibility = new ParserCompatibility(80500, 80400);

        $this->assertTrue($compatibility->isSupported());
    }

    public function testIsSupportedWhenBothAreTheSameVersion(): void
    {
        $compatibility = new ParserCompatibility(80500, 80500);

        $this->assertTrue($compatibility->isSupported());
    }

    /**
     * The reason this object stores ids and derives the strings, rather than storing
     * the strings. PHP 8.10 is newer than 8.9, and only the integer comparison agrees:
     * '8.10' < '8.9' as strings, so a version object holding rendered strings would
     * invert the verdict on the first double-digit minor and report a healthy
     * toolchain as broken. Do not "simplify" the ids away.
     */
    public function testOrdersADoubleDigitMinorAboveASingleDigitOne(): void
    {
        $compatibility = new ParserCompatibility(81000, 80900);

        $this->assertTrue($compatibility->isSupported());
        $this->assertSame('8.10', $compatibility->parserVersion());
        $this->assertSame('8.9', $compatibility->runtimeVersion());
    }

    public function testRendersAMajorVersionOfTenOrMore(): void
    {
        $compatibility = new ParserCompatibility(100100, 90000);

        $this->assertSame('10.1', $compatibility->parserVersion());
        $this->assertSame('9.0', $compatibility->runtimeVersion());
    }

    /**
     * A real PHP_VERSION_ID carries the patch, and issue #74 asked for exactly that, so this is
     * the argument a caller is most likely to hand over. Without normalisation 80500 vs 80503
     * is a mismatch, and both sides render as "8.5", so the report would be unreadable as well
     * as wrong.
     */
    public function testDiscardsThePatchComponentOfARealPhpVersionId(): void
    {
        $compatibility = new ParserCompatibility(80500, 80503);

        $this->assertSame(80500, $compatibility->runtimeVersionId);
        $this->assertTrue($compatibility->isSupported());
    }

    public function testDiscardsThePatchComponentOnBothSides(): void
    {
        $compatibility = new ParserCompatibility(80417, 80426);

        $this->assertSame(80400, $compatibility->parserVersionId);
        $this->assertSame(80400, $compatibility->runtimeVersionId);
        $this->assertSame('8.4', $compatibility->parserVersion());
    }

    /**
     * Normalising must not borrow from the minor once it reaches two digits: 81003 is PHP
     * 8.10.3 and has to land on 81000, not on 81000's neighbour via a fixed-offset truncation.
     */
    public function testDiscardsThePatchComponentOfADoubleDigitMinor(): void
    {
        $compatibility = new ParserCompatibility(81003, 81000);

        $this->assertSame(81000, $compatibility->parserVersionId);
        $this->assertSame('8.10', $compatibility->parserVersion());
        $this->assertTrue($compatibility->isSupported());
    }

    /**
     * A genuine mismatch must survive normalisation -- discarding the patch must not round a
     * real version gap away.
     */
    public function testAGenuineMismatchSurvivesNormalisation(): void
    {
        $compatibility = new ParserCompatibility(80417, 80503);

        $this->assertFalse($compatibility->isSupported());
        $this->assertSame('8.4', $compatibility->parserVersion());
        $this->assertSame('8.5', $compatibility->runtimeVersion());
    }

    public function testIsImmutable(): void
    {
        $compatibility = new ParserCompatibility(80500, 80500);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $compatibility->parserVersionId = 80600;
    }
}
