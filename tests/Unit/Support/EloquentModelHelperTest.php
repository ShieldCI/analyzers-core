<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Support\EloquentModelHelper;

class EloquentModelHelperTest extends TestCase
{
    private AstParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AstParser();
    }

    private function classFrom(string $code): Node\Stmt\Class_
    {
        $ast = $this->parser->parseCode($code);
        $classes = $this->parser->findClasses($ast);
        $this->assertNotEmpty($classes, 'Expected fixture code to contain a class');

        return $classes[0];
    }

    public function testHasFillableDetectsProperty(): void
    {
        $class = $this->classFrom('<?php class User { protected $fillable = ["name", "email"]; }');

        $this->assertTrue(EloquentModelHelper::hasFillable($class));
        $this->assertTrue(EloquentModelHelper::hasFillableConfig($class));
        $this->assertFalse(EloquentModelHelper::hasGuarded($class));
    }

    public function testHasGuardedDetectsProperty(): void
    {
        $class = $this->classFrom('<?php class User { protected $guarded = ["id"]; }');

        $this->assertTrue(EloquentModelHelper::hasGuarded($class));
        $this->assertTrue(EloquentModelHelper::hasFillableConfig($class));
        $this->assertFalse(EloquentModelHelper::hasFillable($class));
    }

    public function testHasFillableDetectsAttribute(): void
    {
        $class = $this->classFrom('<?php use Illuminate\Database\Eloquent\Attributes\Fillable; #[Fillable(["name", "email"])] class User {}');

        $this->assertTrue(EloquentModelHelper::hasFillable($class));
        $this->assertTrue(EloquentModelHelper::hasFillableConfig($class));
    }

    public function testHasGuardedDetectsGuardedAttribute(): void
    {
        $class = $this->classFrom('<?php #[Guarded(["id"])] class User {}');

        $this->assertTrue(EloquentModelHelper::hasGuarded($class));
        $this->assertTrue(EloquentModelHelper::hasFillableConfig($class));
    }

    public function testHasGuardedDetectsUnguardedAttribute(): void
    {
        $class = $this->classFrom('<?php #[Unguarded] class User {}');

        $this->assertTrue(EloquentModelHelper::hasGuarded($class));
        $this->assertTrue(EloquentModelHelper::hasFillableConfig($class));
    }

    public function testDetectsFullyQualifiedAttribute(): void
    {
        $class = $this->classFrom('<?php #[\Illuminate\Database\Eloquent\Attributes\Fillable(["name"])] class User {}');

        $this->assertTrue(EloquentModelHelper::hasFillable($class));
    }

    public function testNoConfigWhenNeitherPropertyNorAttribute(): void
    {
        $class = $this->classFrom('<?php class User { protected $table = "users"; }');

        $this->assertFalse(EloquentModelHelper::hasFillable($class));
        $this->assertFalse(EloquentModelHelper::hasGuarded($class));
        $this->assertFalse(EloquentModelHelper::hasFillableConfig($class));
    }

    public function testExtractFillableFieldsFromArrayAttribute(): void
    {
        $class = $this->classFrom('<?php #[Fillable(["name", "company_id"])] class User {}');

        $this->assertSame(['name', 'company_id'], EloquentModelHelper::extractFillableFieldsFromAttribute($class));
    }

    public function testExtractFillableFieldsFromVariadicAttribute(): void
    {
        $class = $this->classFrom('<?php #[Fillable("title", "user_id")] class Post {}');

        $this->assertSame(['title', 'user_id'], EloquentModelHelper::extractFillableFieldsFromAttribute($class));
    }

    public function testExtractFillableFieldsReturnsEmptyWithoutAttribute(): void
    {
        $class = $this->classFrom('<?php class User { protected $fillable = ["name"]; }');

        $this->assertSame([], EloquentModelHelper::extractFillableFieldsFromAttribute($class));
    }

    public function testHasHiddenDetectsPropertyAndAttribute(): void
    {
        $property = $this->classFrom('<?php class User { protected $hidden = ["password"]; }');
        $attribute = $this->classFrom('<?php #[Hidden(["password"])] class User {}');
        $neither = $this->classFrom('<?php class User {}');

        $this->assertTrue(EloquentModelHelper::hasHidden($property));
        $this->assertTrue(EloquentModelHelper::hasHidden($attribute));
        $this->assertFalse(EloquentModelHelper::hasHidden($neither));
    }

    public function testExtractFillableFieldsReadsPropertyOrAttribute(): void
    {
        $property = $this->classFrom('<?php class User { protected $fillable = ["name", "email"]; }');
        $attribute = $this->classFrom('<?php #[Fillable("name", "email")] class User {}');

        $this->assertSame(['name', 'email'], EloquentModelHelper::extractFillableFields($property));
        $this->assertSame(['name', 'email'], EloquentModelHelper::extractFillableFields($attribute));
    }

    public function testExtractHiddenFieldsReadsPropertyOrAttribute(): void
    {
        $property = $this->classFrom('<?php class User { protected $hidden = ["password", "remember_token"]; }');
        $attribute = $this->classFrom('<?php #[Hidden(["password", "remember_token"])] class User {}');

        $this->assertSame(['password', 'remember_token'], EloquentModelHelper::extractHiddenFields($property));
        $this->assertSame(['password', 'remember_token'], EloquentModelHelper::extractHiddenFields($attribute));
    }

    public function testExtractGuardedFieldsReadsPropertyOrAttribute(): void
    {
        $property = $this->classFrom('<?php class User { protected $guarded = ["id"]; }');
        $attribute = $this->classFrom('<?php #[Guarded(["id"])] class User {}');

        $this->assertSame(['id'], EloquentModelHelper::extractGuardedFields($property));
        $this->assertSame(['id'], EloquentModelHelper::extractGuardedFields($attribute));
    }
}
