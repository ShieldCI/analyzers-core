<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\ValueObjects\Location;

class LocationTest extends TestCase
{
    public function testCanBeCreated(): void
    {
        $location = new Location('/path/to/file.php', 42);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertEquals(42, $location->line);
        $this->assertNull($location->column);
    }

    public function testCanBeCreatedWithColumn(): void
    {
        $location = new Location('/path/to/file.php', 42, 10);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertEquals(42, $location->line);
        $this->assertEquals(10, $location->column);
    }

    public function testCanBeCreatedFromArray(): void
    {
        $data = [
            'file' => '/path/to/file.php',
            'line' => 42,
            'column' => 10,
        ];

        $location = Location::fromArray($data);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertEquals(42, $location->line);
        $this->assertEquals(10, $location->column);
    }

    public function testCanBeCreatedFromArrayWithoutColumn(): void
    {
        $data = [
            'file' => '/path/to/file.php',
            'line' => 42,
        ];

        $location = Location::fromArray($data);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertEquals(42, $location->line);
        $this->assertNull($location->column);
    }

    public function testToArray(): void
    {
        $location = new Location('/path/to/file.php', 42, 10);
        $array = $location->toArray();

        $this->assertEquals([
            'file' => '/path/to/file.php',
            'line' => 42,
            'column' => 10,
        ], $array);
    }

    public function testToArrayWithoutColumn(): void
    {
        $location = new Location('/path/to/file.php', 42);
        $array = $location->toArray();

        $this->assertEquals([
            'file' => '/path/to/file.php',
            'line' => 42,
        ], $array);
    }

    public function testToStringWithoutColumn(): void
    {
        $location = new Location('/path/to/file.php', 42);

        $this->assertEquals('/path/to/file.php:42', (string) $location);
    }

    public function testToStringWithColumn(): void
    {
        $location = new Location('/path/to/file.php', 42, 10);

        $this->assertEquals('/path/to/file.php:42:10', (string) $location);
    }

    public function testIsReadonly(): void
    {
        $location = new Location('/path/to/file.php', 42);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line - Testing that readonly property throws error
        $location->file = '/new/path.php';
    }

    public function testCanBeCreatedWithNullLine(): void
    {
        $location = new Location('/path/to/file.php', null);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertNull($location->line);
        $this->assertNull($location->column);
    }

    public function testToStringWithNullLine(): void
    {
        $location = new Location('/path/to/file.php', null);

        $this->assertEquals('/path/to/file.php', (string) $location);
    }

    public function testToStringWithNullLineAndColumn(): void
    {
        $location = new Location('/path/to/file.php', null, 10);

        // Column is ignored when line is null
        $this->assertEquals('/path/to/file.php', (string) $location);
    }

    public function testToArrayWithNullLine(): void
    {
        $location = new Location('/path/to/file.php', null);
        $array = $location->toArray();

        $this->assertEquals([
            'file' => '/path/to/file.php',
        ], $array);
    }

    public function testFromArrayWithNullLine(): void
    {
        $data = [
            'file' => '/path/to/file.php',
            'line' => null,
        ];

        $location = Location::fromArray($data);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertNull($location->line);
    }

    public function testFromArrayWithoutLine(): void
    {
        $data = [
            'file' => '/path/to/file.php',
        ];

        $location = Location::fromArray($data);

        $this->assertEquals('/path/to/file.php', $location->file);
        $this->assertNull($location->line);
    }
}
