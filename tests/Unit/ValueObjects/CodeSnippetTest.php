<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\ValueObjects\CodeSnippet;

class CodeSnippetTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary test file
        $this->testFile = sys_get_temp_dir().'/test_code_snippet_'.uniqid().'.php';
        $content = <<<'PHP'
<?php

namespace App\Example;

class TestClass
{
    public function method1()
    {
        $variable = 'test';
        return $variable; // Line 10 - target line
    }

    public function method2()
    {
        return 'another method';
    }
}
PHP;
        file_put_contents($this->testFile, $content);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }

        parent::tearDown();
    }

    public function test_from_file_creates_snippet_with_context(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 10, 3);

        $this->assertInstanceOf(CodeSnippet::class, $snippet);
        $this->assertEquals(10, $snippet->getTargetLine());
        $this->assertEquals($this->testFile, $snippet->getFilePath());
        $this->assertEquals(3, $snippet->getContextLines());

        $lines = $snippet->getLines();
        $this->assertIsArray($lines);
        $this->assertArrayHasKey(10, $lines); // Target line
        $this->assertArrayHasKey(7, $lines);  // 3 lines before
        $this->assertArrayHasKey(13, $lines); // 3 lines after
    }

    public function test_from_file_handles_start_of_file(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 2, 5);

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();

        // Should start at line 1, not negative
        $this->assertArrayHasKey(1, $lines);
        $this->assertArrayNotHasKey(0, $lines);
        $this->assertArrayNotHasKey(-1, $lines);
    }

    public function test_from_file_handles_end_of_file(): void
    {
        // Get total lines
        $fileLines = file($this->testFile);
        $this->assertIsArray($fileLines);
        $totalLines = count($fileLines);

        $snippet = CodeSnippet::fromFile($this->testFile, $totalLines, 5);

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();

        // Should not exceed total lines
        $this->assertArrayHasKey($totalLines, $lines);
        $this->assertArrayNotHasKey($totalLines + 1, $lines);
    }

    public function test_from_file_returns_null_for_nonexistent_file(): void
    {
        $snippet = CodeSnippet::fromFile('/nonexistent/file.php', 10, 5);

        $this->assertNull($snippet);
    }

    public function test_from_file_truncates_long_lines(): void
    {
        // Create a file with a very long line
        $longFile = sys_get_temp_dir().'/long_line_'.uniqid().'.php';
        $longLine = str_repeat('x', 300);
        file_put_contents($longFile, "<?php\n{$longLine}\n");

        $snippet = CodeSnippet::fromFile($longFile, 2, 1);

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();

        // Line should be truncated to 250 characters
        $this->assertLessThanOrEqual(250, strlen($lines[2]));

        unlink($longFile);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 10, 2);
        $this->assertNotNull($snippet);

        $array = $snippet->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('target_line', $array);
        $this->assertArrayHasKey('lines', $array);
        $this->assertArrayHasKey('context_lines', $array);

        $this->assertEquals($this->testFile, $array['file']);
        $this->assertEquals(10, $array['target_line']);
        $this->assertEquals(2, $array['context_lines']);
        $this->assertIsArray($array['lines']);
    }

    public function test_get_lines_returns_array_with_line_numbers_as_keys(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 10, 2);
        $this->assertNotNull($snippet);

        $lines = $snippet->getLines();

        // Verify line numbers are keys
        foreach ($lines as $lineNum => $content) {
            $this->assertIsInt($lineNum);
            $this->assertIsString($content);
            $this->assertGreaterThan(0, $lineNum);
        }
    }

    public function test_snippet_with_zero_context_lines(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 10, 0);

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();

        // Should only have the target line
        $this->assertCount(1, $lines);
        $this->assertArrayHasKey(10, $lines);
    }

    public function test_snippet_with_large_context_lines(): void
    {
        $snippet = CodeSnippet::fromFile($this->testFile, 10, 100);

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();

        // Should be limited by file size
        $fileLines = file($this->testFile);
        $this->assertIsArray($fileLines);
        $totalLines = count($fileLines);
        $this->assertLessThanOrEqual($totalLines, count($lines));
    }
}
