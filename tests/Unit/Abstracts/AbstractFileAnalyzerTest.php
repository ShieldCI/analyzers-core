<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Abstracts;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\{Category, Severity};
use ShieldCI\AnalyzersCore\Support\FileParser;
use ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata;

class AbstractFileAnalyzerTest extends TestCase
{
    private string $testDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary test directory structure
        $this->testDir = sys_get_temp_dir() . '/shield-ci-test-' . uniqid();
        mkdir($this->testDir);
        mkdir($this->testDir . '/src');
        mkdir($this->testDir . '/tests');
        mkdir($this->testDir . '/vendor');

        // Create test files
        file_put_contents($this->testDir . '/src/File1.php', "<?php\nclass File1 {}\n");
        file_put_contents($this->testDir . '/src/File2.php', "<?php\nclass File2 {}\n");
        file_put_contents($this->testDir . '/tests/Test1.php', "<?php\nclass Test1 {}\n");
        file_put_contents($this->testDir . '/vendor/Package.php', "<?php\nclass Package {}\n");
        file_put_contents($this->testDir . '/README.md', "# README");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSetBasePathRemovesTrailingSlash(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/path/to/project/');

        $reflection = new \ReflectionClass($analyzer);
        $property = $reflection->getProperty('basePath');
        $property->setAccessible(true);

        $this->assertEquals('/path/to/project', $property->getValue($analyzer));
    }

    public function testSetBasePathReturnsFluentInterface(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $result = $analyzer->setBasePath('/path/to/project');

        $this->assertSame($analyzer, $result);
    }

    public function testSetPathsStoresPaths(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $paths = ['src', 'app'];
        $analyzer->setPaths($paths);

        $reflection = new \ReflectionClass($analyzer);
        $property = $reflection->getProperty('paths');
        $property->setAccessible(true);

        $this->assertEquals($paths, $property->getValue($analyzer));
    }

    public function testSetPathsReturnsFluentInterface(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $result = $analyzer->setPaths(['src']);

        $this->assertSame($analyzer, $result);
    }

    public function testSetExcludePatternsStoresPatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $patterns = ['*/vendor/*', '*/tests/*'];
        $analyzer->setExcludePatterns($patterns);

        $reflection = new \ReflectionClass($analyzer);
        $property = $reflection->getProperty('excludePatterns');
        $property->setAccessible(true);

        $this->assertEquals($patterns, $property->getValue($analyzer));
    }

    public function testSetExcludePatternsReturnsFluentInterface(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $result = $analyzer->setExcludePatterns(['*/vendor/*']);

        $this->assertSame($analyzer, $result);
    }

    public function testGetPhpFilesReturnsOnlyPhpFiles(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['']); // Set to scan the base directory

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(4, $files); // src/File1.php, src/File2.php, tests/Test1.php, vendor/Package.php
        foreach ($files as $file) {
            $this->assertStringEndsWith('.php', $file);
        }
    }

    public function testGetPhpFilesExcludesBasedOnPatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['']); // Set to scan the base directory
        $analyzer->setExcludePatterns(['*/vendor/*', '*/tests/*']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(2, $files); // Only src/File1.php and src/File2.php
        foreach ($files as $file) {
            $this->assertStringContainsString('/src/', $file);
        }
    }

    public function testGetPhpFilesWithSpecificPaths(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['src']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(2, $files); // Only src/File1.php and src/File2.php
    }

    public function testGetPhpFilesWithSingleFile(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['src/File1.php']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('src/File1.php', $files[0]);
    }

    public function testShouldAnalyzeFileReturnsFalseForNonPhpFiles(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $file = new \SplFileInfo($this->testDir . '/README.md');

        $result = $analyzer->shouldAnalyzeFilePublic($file);

        $this->assertFalse($result);
    }

    public function testShouldAnalyzeFileReturnsTrueForPhpFiles(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $file = new \SplFileInfo($this->testDir . '/src/File1.php');

        $result = $analyzer->shouldAnalyzeFilePublic($file);

        $this->assertTrue($result);
    }

    public function testShouldAnalyzeFileReturnsFalseForExcludedPatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setExcludePatterns(['*/vendor/*']);
        $file = new \SplFileInfo($this->testDir . '/vendor/Package.php');

        $result = $analyzer->shouldAnalyzeFilePublic($file);

        $this->assertFalse($result);
    }

    public function testMatchesPatternWithSimpleGlob(): void
    {
        $analyzer = new ConcreteFileAnalyzer();

        $this->assertTrue($analyzer->matchesPatternPublic('/path/to/vendor/file.php', '*/vendor/*'));
        $this->assertFalse($analyzer->matchesPatternPublic('/path/to/src/file.php', '*/vendor/*'));
    }

    public function testMatchesPatternWithQuestionMark(): void
    {
        $analyzer = new ConcreteFileAnalyzer();

        $this->assertTrue($analyzer->matchesPatternPublic('/path/test1.php', '/path/test?.php'));
        $this->assertFalse($analyzer->matchesPatternPublic('/path/test12.php', '/path/test?.php'));
    }

    public function testGetCodeSnippetReturnsContextLines(): void
    {
        $file = $this->testDir . '/multi-line.php';
        file_put_contents($file, "<?php\n// Line 2\n// Line 3\n// Line 4\n// Line 5\n// Line 6\n");

        $snippet = FileParser::getCodeSnippet($file, 4, 2);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('// Line 2', $snippet);
        $this->assertStringContainsString('// Line 3', $snippet);
        $this->assertStringContainsString('// Line 4', $snippet);
        $this->assertStringContainsString('// Line 5', $snippet);
        $this->assertStringContainsString('// Line 6', $snippet);
    }

    public function testGetCodeSnippetReturnsNullForNonExistentFile(): void
    {
        $snippet = FileParser::getCodeSnippet('/non/existent/file.php', 1);

        $this->assertNull($snippet);
    }

    public function testGetCodeSnippetHandlesFileStart(): void
    {
        $file = $this->testDir . '/start.php';
        file_put_contents($file, "<?php\n// Line 2\n// Line 3\n");

        $snippet = FileParser::getCodeSnippet($file, 1, 2);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('<?php', $snippet);
    }

    public function testGetCodeSnippetHandlesFileEnd(): void
    {
        $file = $this->testDir . '/end.php';
        file_put_contents($file, "<?php\n// Line 2\n// Line 3\n");

        $snippet = FileParser::getCodeSnippet($file, 3, 2);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('// Line 3', $snippet);
    }

    public function testReadFileReturnsContents(): void
    {
        $file = $this->testDir . '/test.php';
        $content = "<?php\nclass Test {}";
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
        file_put_contents($file, "<?php");
        chmod($file, 0000);

        $result = FileParser::readFile($file);

        $this->assertNull($result);

        // Clean up
        chmod($file, 0644);
    }

    public function testGetRelativePathWithBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/project/root');

        $result = $analyzer->getRelativePathPublic('/project/root/src/File.php');

        $this->assertEquals('src/File.php', $result);
    }

    public function testGetRelativePathWithoutBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();

        $result = $analyzer->getRelativePathPublic('/absolute/path/File.php');

        $this->assertEquals('/absolute/path/File.php', $result);
    }

    public function testGetRelativePathWhenFileNotInBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/project/root');

        $result = $analyzer->getRelativePathPublic('/other/path/File.php');

        $this->assertEquals('/other/path/File.php', $result);
    }

    public function testGetFilesToAnalyzeReturnsIterableOfFiles(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['src']); // Specific directory

        $files = $analyzer->getFilesToAnalyzePublic();

        $this->assertIsIterable($files);
        $fileArray = iterator_to_array($files);
        $this->assertNotEmpty($fileArray);
        $this->assertContainsOnlyInstancesOf(\SplFileInfo::class, $fileArray);
    }

    public function testGetFilesToAnalyzeWithNonExistentDirectory(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['non-existent-dir']);

        $files = iterator_to_array($analyzer->getFilesToAnalyzePublic());

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }
}

// Concrete implementation for testing
class ConcreteFileAnalyzer extends AbstractFileAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'concrete-file-analyzer',
            name: 'Concrete File Analyzer',
            description: 'Test analyzer',
            category: Category::Security,
            severity: Severity::High
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        return $this->passed('Analysis completed');
    }

    // Public wrappers for testing protected methods
    /**
     * @return array<string>
     */
    public function getPhpFilesPublic(): array
    {
        return $this->getPhpFiles();
    }

    public function shouldAnalyzeFilePublic(\SplFileInfo $file): bool
    {
        return $this->shouldAnalyzeFile($file);
    }

    public function matchesPatternPublic(string $path, string $pattern): bool
    {
        return $this->matchesPattern($path, $pattern);
    }


    public function getRelativePathPublic(string $file): string
    {
        return $this->getRelativePath($file);
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    public function getFilesToAnalyzePublic(): iterable
    {
        return $this->getFilesToAnalyze();
    }
}
