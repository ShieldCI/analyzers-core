<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\Severity;

class SeverityTest extends TestCase
{
    public function testHasAllExpectedCases(): void
    {
        $cases = Severity::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(Severity::Critical, $cases);
        $this->assertContains(Severity::High, $cases);
        $this->assertContains(Severity::Medium, $cases);
        $this->assertContains(Severity::Low, $cases);
        $this->assertContains(Severity::Info, $cases);
    }

    public function testCaseValues(): void
    {
        $this->assertEquals('critical', Severity::Critical->value);
        $this->assertEquals('high', Severity::High->value);
        $this->assertEquals('medium', Severity::Medium->value);
        $this->assertEquals('low', Severity::Low->value);
        $this->assertEquals('info', Severity::Info->value);
    }

    public function testLevels(): void
    {
        $this->assertEquals(5, Severity::Critical->level());
        $this->assertEquals(4, Severity::High->level());
        $this->assertEquals(3, Severity::Medium->level());
        $this->assertEquals(2, Severity::Low->level());
        $this->assertEquals(1, Severity::Info->level());
    }

    public function testColors(): void
    {
        $this->assertEquals('red', Severity::Critical->color());
        $this->assertEquals('orange', Severity::High->color());
        $this->assertEquals('yellow', Severity::Medium->color());
        $this->assertEquals('blue', Severity::Low->color());
        $this->assertEquals('gray', Severity::Info->color());
    }

    public function testLabels(): void
    {
        $this->assertEquals('Critical', Severity::Critical->label());
        $this->assertEquals('High', Severity::High->label());
        $this->assertEquals('Medium', Severity::Medium->label());
        $this->assertEquals('Low', Severity::Low->label());
        $this->assertEquals('Info', Severity::Info->label());
    }

    public function testDescriptions(): void
    {
        $this->assertStringContainsString('Critical', Severity::Critical->description());
        $this->assertStringContainsString('High priority', Severity::High->description());
        $this->assertStringContainsString('Medium priority', Severity::Medium->description());
        $this->assertStringContainsString('Low priority', Severity::Low->description());
        $this->assertStringContainsString('Informational', Severity::Info->description());
    }
}
