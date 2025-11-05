<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\Category;

class CategoryTest extends TestCase
{
    public function testHasAllExpectedCases(): void
    {
        $cases = Category::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(Category::Security, $cases);
        $this->assertContains(Category::Performance, $cases);
        $this->assertContains(Category::CodeQuality, $cases);
        $this->assertContains(Category::BestPractices, $cases);
        $this->assertContains(Category::Reliability, $cases);
    }

    public function testCaseValues(): void
    {
        $this->assertEquals('security', Category::Security->value);
        $this->assertEquals('performance', Category::Performance->value);
        $this->assertEquals('code_quality', Category::CodeQuality->value);
        $this->assertEquals('best_practices', Category::BestPractices->value);
        $this->assertEquals('reliability', Category::Reliability->value);
    }

    public function testLabels(): void
    {
        $this->assertEquals('Security', Category::Security->label());
        $this->assertEquals('Performance', Category::Performance->label());
        $this->assertEquals('Code Quality', Category::CodeQuality->label());
        $this->assertEquals('Best Practices', Category::BestPractices->label());
        $this->assertEquals('Reliability', Category::Reliability->label());
    }

    public function testIcons(): void
    {
        $this->assertEquals('🔒', Category::Security->icon());
        $this->assertEquals('⚡', Category::Performance->icon());
        $this->assertEquals('📊', Category::CodeQuality->icon());
        $this->assertEquals('✨', Category::BestPractices->icon());
        $this->assertEquals('🛡️', Category::Reliability->icon());
    }

    public function testDescriptions(): void
    {
        $this->assertEquals('Security vulnerabilities and risks', Category::Security->description());
        $this->assertEquals('Performance issues and optimizations', Category::Performance->description());
        $this->assertEquals('Code quality and maintainability', Category::CodeQuality->description());
        $this->assertEquals('Best practices and conventions', Category::BestPractices->description());
        $this->assertEquals('Reliability and stability issues', Category::Reliability->description());
    }
}
