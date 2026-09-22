<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;

class ParseFailureCauseTest extends TestCase
{
    public function testHasAllExpectedCases(): void
    {
        $cases = ParseFailureCause::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(ParseFailureCause::SyntaxError, $cases);
        $this->assertContains(ParseFailureCause::UnsupportedSyntax, $cases);
        $this->assertContains(ParseFailureCause::Unreadable, $cases);
    }

    public function testCaseValues(): void
    {
        $this->assertSame('syntax-error', ParseFailureCause::SyntaxError->value);
        $this->assertSame('unsupported-syntax', ParseFailureCause::UnsupportedSyntax->value);
        $this->assertSame('unreadable', ParseFailureCause::Unreadable->value);
    }

    public function testCanBeCreatedFromValue(): void
    {
        $this->assertSame(ParseFailureCause::SyntaxError, ParseFailureCause::from('syntax-error'));
        $this->assertSame(
            ParseFailureCause::UnsupportedSyntax,
            ParseFailureCause::from('unsupported-syntax')
        );
        $this->assertSame(ParseFailureCause::Unreadable, ParseFailureCause::from('unreadable'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(ParseFailureCause::tryFrom('not-a-cause'));
    }
}
