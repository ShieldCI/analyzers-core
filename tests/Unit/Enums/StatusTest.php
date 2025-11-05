<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\Status;

class StatusTest extends TestCase
{
    public function testHasAllExpectedCases(): void
    {
        $cases = Status::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(Status::Passed, $cases);
        $this->assertContains(Status::Failed, $cases);
        $this->assertContains(Status::Warning, $cases);
        $this->assertContains(Status::Skipped, $cases);
        $this->assertContains(Status::Error, $cases);
    }

    public function testCaseValues(): void
    {
        $this->assertEquals('passed', Status::Passed->value);
        $this->assertEquals('failed', Status::Failed->value);
        $this->assertEquals('warning', Status::Warning->value);
        $this->assertEquals('skipped', Status::Skipped->value);
        $this->assertEquals('error', Status::Error->value);
    }

    public function testIsSuccessForPassedStatus(): void
    {
        $this->assertTrue(Status::Passed->isSuccess());
    }

    public function testIsSuccessForSkippedStatus(): void
    {
        $this->assertTrue(Status::Skipped->isSuccess());
    }

    public function testIsSuccessForFailedStatus(): void
    {
        $this->assertFalse(Status::Failed->isSuccess());
    }

    public function testIsSuccessForWarningStatus(): void
    {
        $this->assertFalse(Status::Warning->isSuccess());
    }

    public function testIsSuccessForErrorStatus(): void
    {
        $this->assertFalse(Status::Error->isSuccess());
    }

    public function testEmojis(): void
    {
        $this->assertEquals('✓', Status::Passed->emoji());
        $this->assertEquals('✗', Status::Failed->emoji());
        $this->assertEquals('⚠', Status::Warning->emoji());
        $this->assertEquals('⊝', Status::Skipped->emoji());
        $this->assertEquals('⚡', Status::Error->emoji());
    }

    public function testColors(): void
    {
        $this->assertEquals('green', Status::Passed->color());
        $this->assertEquals('red', Status::Failed->color());
        $this->assertEquals('yellow', Status::Warning->color());
        $this->assertEquals('gray', Status::Skipped->color());
        $this->assertEquals('red', Status::Error->color());
    }
}
