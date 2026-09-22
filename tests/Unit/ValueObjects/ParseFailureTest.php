<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\ValueObjects\ParseFailure;

class ParseFailureTest extends TestCase
{
    public function testExposesEveryFieldItWasGiven(): void
    {
        $failure = new ParseFailure(
            '/app/Broken.php',
            6,
            "Syntax error, unexpected '}'",
            ParseFailureCause::SyntaxError,
        );

        $this->assertSame('/app/Broken.php', $failure->path);
        $this->assertSame(6, $failure->line);
        $this->assertSame("Syntax error, unexpected '}'", $failure->message);
        $this->assertSame(ParseFailureCause::SyntaxError, $failure->cause);
    }

    public function testAcceptsAnUnknownPathAndLine(): void
    {
        $failure = new ParseFailure(null, null, 'Syntax error', ParseFailureCause::SyntaxError);

        $this->assertNull($failure->path);
        $this->assertNull($failure->line);
    }

    public function testIsImmutable(): void
    {
        $failure = new ParseFailure('/app/a.php', 1, 'x', ParseFailureCause::Unreadable);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $failure->path = '/app/b.php';
    }

    public function testToArrayCarriesEveryField(): void
    {
        $failure = new ParseFailure(
            '/app/Broken.php',
            6,
            'Syntax error',
            ParseFailureCause::UnsupportedSyntax,
        );

        $this->assertSame([
            'path' => '/app/Broken.php',
            'line' => 6,
            'message' => 'Syntax error',
            'cause' => 'unsupported-syntax',
        ], $failure->toArray());
    }

    public function testToArrayOmitsAnUnknownPathAndLine(): void
    {
        $failure = new ParseFailure(null, null, 'Syntax error', ParseFailureCause::SyntaxError);

        $this->assertSame([
            'message' => 'Syntax error',
            'cause' => 'syntax-error',
        ], $failure->toArray());
    }
}
