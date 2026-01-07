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

    public function testHandlesZeroLineNumberGracefully(): void
    {
        $location = new Location('/path/to/file.php', 0);

        // Line 0 is stored but treated as invalid
        $this->assertEquals(0, $location->line);

        // toString treats it as null (no line number)
        $this->assertEquals('/path/to/file.php', (string) $location);

        // toArray omits invalid line number
        $this->assertEquals(['file' => '/path/to/file.php'], $location->toArray());
    }

    public function testHandlesNegativeLineNumberGracefully(): void
    {
        $location = new Location('/path/to/file.php', -1);

        // Negative line is stored but treated as invalid
        $this->assertEquals(-1, $location->line);

        // toString treats it as null
        $this->assertEquals('/path/to/file.php', (string) $location);

        // toArray omits invalid line number
        $this->assertEquals(['file' => '/path/to/file.php'], $location->toArray());
    }

    public function testHandlesZeroColumnNumberGracefully(): void
    {
        $location = new Location('/path/to/file.php', 42, 0);

        // Column 0 is stored but treated as invalid
        $this->assertEquals(42, $location->line);
        $this->assertEquals(0, $location->column);

        // toString includes line but omits invalid column
        $this->assertEquals('/path/to/file.php:42', (string) $location);

        // toArray includes line but omits invalid column
        $this->assertEquals([
            'file' => '/path/to/file.php',
            'line' => 42,
        ], $location->toArray());
    }

    public function testHandlesNegativeColumnNumberGracefully(): void
    {
        $location = new Location('/path/to/file.php', 42, -5);

        // Negative column is stored but treated as invalid
        $this->assertEquals(42, $location->line);
        $this->assertEquals(-5, $location->column);

        // toString includes line but omits invalid column
        $this->assertEquals('/path/to/file.php:42', (string) $location);

        // toArray includes line but omits invalid column
        $this->assertEquals([
            'file' => '/path/to/file.php',
            'line' => 42,
        ], $location->toArray());
    }

    public function testHandlesBothInvalidLineAndColumnGracefully(): void
    {
        $location = new Location('/path/to/file.php', 0, 0);

        // Both stored but treated as invalid
        $this->assertEquals(0, $location->line);
        $this->assertEquals(0, $location->column);

        // toString shows only file
        $this->assertEquals('/path/to/file.php', (string) $location);

        // toArray shows only file
        $this->assertEquals(['file' => '/path/to/file.php'], $location->toArray());
    }

    public function testAcceptsValidLineAndColumn(): void
    {
        $location = new Location('/path/to/file.php', 1, 1);

        $this->assertEquals(1, $location->line);
        $this->assertEquals(1, $location->column);

        // toString shows full location
        $this->assertEquals('/path/to/file.php:1:1', (string) $location);

        // toArray includes all values
        $this->assertEquals([
            'file' => '/path/to/file.php',
            'line' => 1,
            'column' => 1,
        ], $location->toArray());
    }
}
