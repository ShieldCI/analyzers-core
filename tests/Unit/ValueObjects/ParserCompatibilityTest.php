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

    public function testIsImmutable(): void
    {
        $compatibility = new ParserCompatibility(80500, 80500);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $compatibility->parserVersionId = 80600;
    }
}
