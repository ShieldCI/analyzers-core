<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\FileParser;

class FileParserTest extends TestCase
{
    private string $testDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/shield-ci-fileparser-test-' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            $files = array_diff(scandir($this->testDir) ?: [], ['.', '..']);
            foreach ($files as $file) {
                unlink($this->testDir . '/' . $file);
            }
            rmdir($this->testDir);
        }
    }

    public function testExtractNamespaceReturnsNamespace(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nnamespace App\\Services;\n\nclass MyClass {}");

        $namespace = FileParser::extractNamespace($file);

        $this->assertEquals('App\\Services', $namespace);
    }

    public function testExtractNamespaceReturnsNullWhenNoNamespace(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass MyClass {}");

        $namespace = FileParser::extractNamespace($file);

        $this->assertNull($namespace);
    }

    public function testExtractNamespaceReturnsNullForNonExistentFile(): void
    {
        $namespace = FileParser::extractNamespace('/non/existent/file.php');

        $this->assertNull($namespace);
    }

    public function testExtractClassNameReturnsClassName(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass MyClass {}");

        $className = FileParser::extractClassName($file);

        $this->assertEquals('MyClass', $className);
    }

    public function testExtractClassNameReturnsNullWhenNoClass(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\n\$x = 42;");

        $className = FileParser::extractClassName($file);

        $this->assertNull($className);
    }

    public function testExtractClassNameReturnsNullForNonExistentFile(): void
    {
        $className = FileParser::extractClassName('/non/existent/file.php');

        $this->assertNull($className);
    }

    public function testExtractFullyQualifiedClassNameWithNamespace(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nnamespace App\\Models;\n\nclass User {}");

        $fqcn = FileParser::extractFullyQualifiedClassName($file);

        $this->assertEquals('App\\Models\\User', $fqcn);
    }

    public function testExtractFullyQualifiedClassNameWithoutNamespace(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass User {}");

        $fqcn = FileParser::extractFullyQualifiedClassName($file);

        $this->assertEquals('User', $fqcn);
    }

    public function testExtractFullyQualifiedClassNameReturnsNullWhenNoClass(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nnamespace App;\n\n\$x = 42;");

        $fqcn = FileParser::extractFullyQualifiedClassName($file);

        $this->assertNull($fqcn);
    }

    public function testExtractUseStatementsReturnsAllUseStatements(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nuse App\\Models\\User;\nuse Illuminate\\Support\\Collection;\n\nclass Test {}");

        $uses = FileParser::extractUseStatements($file);

        $this->assertCount(2, $uses);
        $this->assertContains('App\\Models\\User', $uses);
        $this->assertContains('Illuminate\\Support\\Collection', $uses);
    }

    public function testExtractUseStatementsReturnsEmptyArrayWhenNoUses(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass Test {}");

        $uses = FileParser::extractUseStatements($file);

        $this->assertIsArray($uses);
        $this->assertEmpty($uses);
    }

    public function testExtractUseStatementsReturnsEmptyArrayForNonExistentFile(): void
    {
        $uses = FileParser::extractUseStatements('/non/existent/file.php');

        $this->assertIsArray($uses);
        $this->assertEmpty($uses);
    }

    public function testCountLinesReturnsCorrectCount(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nline 2\nline 3\nline 4");

        $count = FileParser::countLines($file);

        $this->assertEquals(4, $count);
    }

    public function testCountLinesReturnsZeroForNonExistentFile(): void
    {
        $count = FileParser::countLines('/non/existent/file.php');

        $this->assertEquals(0, $count);
    }

    public function testCountLinesReturnsZeroForUnreadableFile(): void
    {
        $file = $this->testDir . '/unreadable.php';
        file_put_contents($file, "<?php\ntest");
        chmod($file, 0000);

        $count = FileParser::countLines($file);

        $this->assertEquals(0, $count);

        chmod($file, 0644);
    }

    public function testGetFileSizeReturnsCorrectSize(): void
    {
        $file = $this->testDir . '/test.php';
        $content = "<?php\ntest content";
        file_put_contents($file, $content);

        $size = FileParser::getFileSize($file);

        $this->assertEquals(strlen($content), $size);
    }

    public function testGetFileSizeReturnsZeroForNonExistentFile(): void
    {
        $size = FileParser::getFileSize('/non/existent/file.php');

        $this->assertEquals(0, $size);
    }

    public function testReadFileReturnsContents(): void
    {
        $file = $this->testDir . '/test.php';
        $content = "<?php\ntest content";
        file_put_contents($file, $content);

        $result = FileParser::readFile($file);

        $this->assertEquals($content, $result);
    }

    public function testReadFileReturnsNullForNonExistentFile(): void
    {
        $result = FileParser::readFile('/non/existent/file.php');

        $this->assertNull($result);
    }

    public function testReadFileReturnsNullForUnreadableFile(): void
    {
        $file = $this->testDir . '/unreadable.php';
        file_put_contents($file, "<?php\ntest");
        chmod($file, 0000);

        $result = FileParser::readFile($file);

        $this->assertNull($result);

        chmod($file, 0644);
    }

    public function testGetLinesReturnsAllLines(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "line 1\nline 2\nline 3");

        $lines = FileParser::getLines($file);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('line 1', $lines[0]);
        $this->assertStringContainsString('line 2', $lines[1]);
        $this->assertStringContainsString('line 3', $lines[2]);
    }

    public function testGetLinesReturnsEmptyArrayForNonExistentFile(): void
    {
        $lines = FileParser::getLines('/non/existent/file.php');

        $this->assertIsArray($lines);
        $this->assertEmpty($lines);
    }

    public function testGetLineReturnsSpecificLine(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "line 1\nline 2\nline 3");

        $line = FileParser::getLine($file, 2);

        $this->assertNotNull($line);
        $this->assertStringContainsString('line 2', $line);
    }

    public function testGetLineReturnsNullForInvalidLineNumber(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "line 1\nline 2");

        $line = FileParser::getLine($file, 10);

        $this->assertNull($line);
    }

    public function testGetLineReturnsNullForNonExistentFile(): void
    {
        $line = FileParser::getLine('/non/existent/file.php', 1);

        $this->assertNull($line);
    }

    public function testGetLineRangeReturnsLinesInRange(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "line 1\nline 2\nline 3\nline 4\nline 5");

        $lines = FileParser::getLineRange($file, 2, 4);

        $this->assertCount(3, $lines);
        $this->assertArrayHasKey(2, $lines);
        $this->assertArrayHasKey(3, $lines);
        $this->assertArrayHasKey(4, $lines);
        $this->assertStringContainsString('line 2', $lines[2]);
        $this->assertStringContainsString('line 3', $lines[3]);
        $this->assertStringContainsString('line 4', $lines[4]);
    }

    public function testGetLineRangeHandlesOutOfBoundsRange(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "line 1\nline 2");

        $lines = FileParser::getLineRange($file, 1, 10);

        $this->assertCount(2, $lines);
        $this->assertArrayHasKey(1, $lines);
        $this->assertArrayHasKey(2, $lines);
    }

    public function testGetLineRangeReturnsEmptyArrayForNonExistentFile(): void
    {
        $lines = FileParser::getLineRange('/non/existent/file.php', 1, 3);

        $this->assertIsArray($lines);
        $this->assertEmpty($lines);
    }

    public function testContainsReturnsTrueWhenStringIsPresent(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass MyClass {}");

        $result = FileParser::contains($file, 'MyClass');

        $this->assertTrue($result);
    }

    public function testContainsReturnsFalseWhenStringIsAbsent(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass MyClass {}");

        $result = FileParser::contains($file, 'NonExistent');

        $this->assertFalse($result);
    }

    public function testContainsReturnsFalseForNonExistentFile(): void
    {
        $result = FileParser::contains('/non/existent/file.php', 'test');

        $this->assertFalse($result);
    }

    public function testMatchesReturnsTrueWhenPatternMatches(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nclass MyClass {}");

        $result = FileParser::matches($file, '/class\s+\w+/');

        $this->assertTrue($result);
    }

    public function testMatchesReturnsFalseWhenPatternDoesNotMatch(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\n\$x = 42;");

        $result = FileParser::matches($file, '/class\s+\w+/');

        $this->assertFalse($result);
    }

    public function testMatchesReturnsFalseForNonExistentFile(): void
    {
        $result = FileParser::matches('/non/existent/file.php', '/test/');

        $this->assertFalse($result);
    }

    public function testCountOccurrencesReturnsCorrectCount(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\ntest test test other test");

        $count = FileParser::countOccurrences($file, 'test');

        $this->assertEquals(4, $count);
    }

    public function testCountOccurrencesReturnsZeroWhenStringNotFound(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, "<?php\nsome content");

        $count = FileParser::countOccurrences($file, 'nonexistent');

        $this->assertEquals(0, $count);
    }

    public function testCountOccurrencesReturnsZeroForNonExistentFile(): void
    {
        $count = FileParser::countOccurrences('/non/existent/file.php', 'test');

        $this->assertEquals(0, $count);
    }
}
