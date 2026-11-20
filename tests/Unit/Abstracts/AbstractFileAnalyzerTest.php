<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Abstracts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\{Category, Severity, Status};
use ShieldCI\AnalyzersCore\Support\FileParser;
use ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata;

class AbstractFileAnalyzerTest extends TestCase
{
    private string $testDir = '';

    /**
     * Directories a test chmod'ed shut, reopened in tearDown() so cleanup can
     * descend into them.
     *
     * @var list<string>
     */
    private array $lockedDirs = [];

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

        foreach ($this->lockedDirs as $dir) {
            chmod($dir, 0755);
        }
        $this->lockedDirs = [];

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

        $this->assertEquals('/path/to/project', $property->getValue($analyzer));
    }

    public function testSetBasePathRemovesTrailingBackslash(): void
    {
        // PathHelper::relativeTo() rtrims '/\\', this rtrimmed '/' alone - so a
        // Windows base_path() with a trailing separator relativised correctly
        // but still double-separated on every join built from it (#62).
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app\\');

        $reflection = new \ReflectionClass($analyzer);
        $property = $reflection->getProperty('basePath');

        $this->assertEquals('C:\\proj\\app', $property->getValue($analyzer));
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

    public function testGetPhpFilesExcludesRelativePatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['']); // Set to scan the base directory
        $analyzer->setExcludePatterns(['vendor/*', 'tests/*']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(2, $files); // Only src/File1.php and src/File2.php
        foreach ($files as $file) {
            $this->assertStringContainsString('/src/', $file);
        }
    }

    public function testShouldAnalyzeFileExcludesRelativePatternAgainstBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setExcludePatterns(['vendor/*']);

        // Top-level vendor file and a nested one: the glob's .* crosses directories
        mkdir($this->testDir . '/vendor/sub');
        file_put_contents($this->testDir . '/vendor/sub/Deep.php', "<?php\nclass Deep {}\n");

        $this->assertFalse($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo($this->testDir . '/vendor/Package.php')));
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo($this->testDir . '/vendor/sub/Deep.php')));
        $this->assertTrue($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo($this->testDir . '/src/File1.php')));
    }

    public function testShouldAnalyzeFileStillExcludesAbsoluteStylePatternsWithBasePath(): void
    {
        // Backward compatibility: patterns like '*/vendor/*' matched the absolute
        // pathname before relative matching existed and must keep working.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setExcludePatterns(['*/vendor/*']);

        $this->assertFalse($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo($this->testDir . '/vendor/Package.php')));
        $this->assertTrue($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo($this->testDir . '/src/File1.php')));
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

    public function testGetCodeSnippetWithNegativeContextReturnsTheTargetLine(): void
    {
        $file = $this->testDir . '/negative-context.php';
        file_put_contents($file, "<?php\n// Line 2\n// Line 3\n// Line 4\n// Line 5\n// Line 6\n");

        // Was "" (#101): a negative count is clamped to 0, the target line alone.
        $this->assertSame("// Line 4\n", FileParser::getCodeSnippet($file, 4, -2));
        $this->assertSame("// Line 4\n", FileParser::getCodeSnippet($file, 4, 0));
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

    public function testGetRelativePathWithoutBasePathUsesTheHostBasePath(): void
    {
        // #58: with no setBasePath() call, resolution now falls through
        // getBasePath() to the host's base_path() - the same base every other
        // inherited helper already used. Before the override was dropped this
        // answered the absolute path, so shouldAnalyzeFile() and the inherited
        // createIssueWithSnippet() disagreed on the very same instance.
        $analyzer = new ConcreteFileAnalyzer();

        $GLOBALS['__shieldci_test_base_path'] = '/host/app';

        try {
            $this->assertSame('src/File.php', $analyzer->getRelativePathPublic('/host/app/src/File.php'));
        } finally {
            unset($GLOBALS['__shieldci_test_base_path']);
        }
    }

    public function testGetRelativePathWithoutBasePathLeavesPathsOutsideTheHostBaseAlone(): void
    {
        $analyzer = new ConcreteFileAnalyzer();

        $GLOBALS['__shieldci_test_base_path'] = '/host/app';

        try {
            $this->assertSame(
                '/absolute/path/File.php',
                $analyzer->getRelativePathPublic('/absolute/path/File.php')
            );
        } finally {
            unset($GLOBALS['__shieldci_test_base_path']);
        }
    }

    public function testGetRelativePathWhenFileNotInBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/project/root');

        $result = $analyzer->getRelativePathPublic('/other/path/File.php');

        $this->assertEquals('/other/path/File.php', $result);
    }

    public function testGetRelativePathNormalisesWindowsSeparators(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app');

        $this->assertSame('src/File.php', $analyzer->getRelativePathPublic('C:\\proj\\app\\src\\File.php'));
    }

    public function testGetRelativePathHandlesTheMixedSeparatorsSplFileInfoProduces(): void
    {
        // getFilesToAnalyze() joins the scan root with a literal '/', while
        // SplFileInfo::getPathname() joins with DIRECTORY_SEPARATOR - so this is
        // the shape a real Windows run actually hands to getRelativePath().
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app');

        $this->assertSame('vendor/a.php', $analyzer->getRelativePathPublic('C:/proj/app\\vendor\\a.php'));
    }

    public function testGetRelativePathHandlesATrailingBackslashInTheBasePath(): void
    {
        // setBasePath() rtrims '/' only, so a Windows trailing separator reaches
        // the relativiser intact.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app\\');

        $this->assertSame('src/File.php', $analyzer->getRelativePathPublic('C:\\proj\\app\\src\\File.php'));
    }

    public function testShouldAnalyzeFileExcludesARelativeGlobOnWindowsPaths(): void
    {
        // #58 regression. setExcludePatterns(['vendor/*']) compiles to
        // '#^vendor/.*$#', which matches neither 'vendor\a.php' nor the absolute
        // pathname - so vendor/ was scanned on Windows, defeating #56.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app');
        $analyzer->setExcludePatterns(['vendor/*']);

        $this->assertFalse(
            $analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('C:\\proj\\app\\vendor\\a.php'))
        );
    }

    public function testShouldAnalyzeFileExcludesARelativeGlobOnPosixPaths(): void
    {
        // The control for the case above: this one already passed.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/proj/app');
        $analyzer->setExcludePatterns(['vendor/*']);

        $this->assertFalse(
            $analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('/proj/app/vendor/a.php'))
        );
    }

    public function testShouldAnalyzeFileKeepsWindowsPathsOutsideAnExcludedGlob(): void
    {
        // Guards the other direction: normalising must not over-exclude.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app');
        $analyzer->setExcludePatterns(['vendor/*']);

        $this->assertTrue(
            $analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('C:\\proj\\app\\src\\a.php'))
        );
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

    public function testGetBasePathUsesExplicitlySetBasePath(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/custom/base/path');

        $basePath = $analyzer->exposedGetBasePath();

        $this->assertEquals('/custom/base/path', $basePath);
    }

    public function testGetBasePathFallsBackToParentWhenNotSet(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        // Don't set basePath

        $basePath = $analyzer->exposedGetBasePath();

        // Should fall back to parent implementation (getcwd or base_path helper)
        $this->assertNotEmpty($basePath);
        $this->assertIsString($basePath);
    }

    public function testGetEnvironmentReadsFromEnvFileWhenBasePathSet(): void
    {
        // Create .env file
        $envFile = $this->testDir . '/.env';
        file_put_contents($envFile, "APP_ENV=local\nAPP_DEBUG=true");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $environment = $analyzer->exposedGetEnvironment();

        $this->assertEquals('local', $environment);
    }

    public function testGetEnvironmentFallsBackToParentWhenNoEnvFile(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        // No .env file exists

        $environment = $analyzer->exposedGetEnvironment();

        // Should fall back to parent implementation (production by default)
        $this->assertIsString($environment);
        $this->assertEquals('production', $environment);
    }

    public function testGetEnvironmentHandlesInvalidEnvFile(): void
    {
        // Create .env file without APP_ENV
        $envFile = $this->testDir . '/.env';
        file_put_contents($envFile, "APP_DEBUG=true\nOTHER_VAR=value");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $environment = $analyzer->exposedGetEnvironment();

        // Should fall back to parent when APP_ENV not found
        $this->assertIsString($environment);
    }

    public function testGetEnvironmentAppliesMappingFromEnvFile(): void
    {
        $envFile = $this->testDir . '/.env';
        file_put_contents($envFile, "APP_ENV=local-test\nAPP_DEBUG=true");

        config(['shieldci.environment_mapping' => ['local-test' => 'local']]);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $environment = $analyzer->exposedGetEnvironment();

        config(['shieldci.environment_mapping' => []]);

        $this->assertEquals('local', $environment);
    }

    public function testGetEnvironmentReadsHyphenatedEnvFromEnvFile(): void
    {
        $envFile = $this->testDir . '/.env';
        file_put_contents($envFile, "APP_ENV=local-test\nAPP_DEBUG=true");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $environment = $analyzer->exposedGetEnvironment();

        $this->assertEquals('local-test', $environment);
    }

    public function testGetFilesToAnalyzeHandlesSingleFilePath(): void
    {
        $singleFile = $this->testDir . '/src/File1.php';

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['src/File1.php']);

        $files = iterator_to_array($analyzer->getFilesToAnalyzePublic());

        $this->assertCount(1, $files);
        $this->assertEquals($singleFile, $files[0]->getPathname());
    }

    public function testMatchesPatternEscapesRegexCharacters(): void
    {
        $analyzer = new ConcreteFileAnalyzer();

        // Test that regex special chars in pattern are escaped
        $this->assertTrue($analyzer->matchesPatternPublic('/path/[test].php', '/path/[test].php'));
        $this->assertFalse($analyzer->matchesPatternPublic('/path/test.php', '/path/[test].php'));
    }

    public function testShouldAnalyzeFileReturnsTrueWhenNoExcludePatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        // No exclude patterns set
        $file = new \SplFileInfo($this->testDir . '/src/File1.php');

        $result = $analyzer->shouldAnalyzeFilePublic($file);

        $this->assertTrue($result);
    }

    public function testSetExcludePatternsPreCompilesRegexPatterns(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setExcludePatterns(['*/vendor/*', '*/tests/*']);

        // Pattern matching should work correctly via compiled patterns
        $vendorFile = new \SplFileInfo($this->testDir . '/vendor/Package.php');
        $testsFile = new \SplFileInfo($this->testDir . '/tests/Test1.php');
        $srcFile = new \SplFileInfo($this->testDir . '/src/File1.php');

        $this->assertFalse($analyzer->shouldAnalyzeFilePublic($vendorFile));
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic($testsFile));
        $this->assertTrue($analyzer->shouldAnalyzeFilePublic($srcFile));
    }

    public function testSetExcludePatternsUpdatesCompiledPatternsOnReset(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setExcludePatterns(['*/vendor/*']);

        $vendorFile = new \SplFileInfo($this->testDir . '/vendor/Package.php');
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic($vendorFile));

        // Reset to empty — vendor file should now be included
        $analyzer->setExcludePatterns([]);
        $this->assertTrue($analyzer->shouldAnalyzeFilePublic($vendorFile));
    }

    public function testGetPhpFilesReturnsEmptyArrayWhenNoPhpFiles(): void
    {
        // Create directory with no PHP files
        $emptyDir = $this->testDir . '/empty';
        mkdir($emptyDir);
        file_put_contents($emptyDir . '/README.md', '# Empty');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['empty']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertEmpty($files);
    }

    // =========================================================================
    // The empty-$paths default - ShieldCI/analyzers-core#62
    //
    // The predecessor of these tests asserted only that $this->paths had been
    // mutated to [$basePath], then closed with assertIsArray($files). Its own
    // comment conceded that the resulting '/app//app' "won't exist" and called
    // that expected. It pinned the mechanism instead of the outcome, so it
    // certified an analyzer that scanned nothing and reported a pass.
    // =========================================================================

    public function testGetPhpFilesScansTheBasePathWhenSetPathsWasNeverCalled(): void
    {
        // AnalyzerManager always calls setBasePath() but only calls setPaths()
        // when shieldci.paths.analyze is non-empty, so this is the shape every
        // file analyzer takes in an app that leaves that config key empty.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertCount(4, $files);
    }

    public function testGetFilesToAnalyzeLeavesThePathsPropertyUntouched(): void
    {
        // The default used to be installed by writing it back to $this->paths.
        // AnalyzerManager caches analyzer instances, and FatModelAnalyzer and
        // friends branch on empty($this->paths) to install a narrower scan root,
        // so a getter that writes is one reordering away from stealing theirs.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        iterator_to_array($analyzer->getFilesToAnalyzePublic());

        $reflection = new \ReflectionClass($analyzer);
        $pathsProperty = $reflection->getProperty('paths');

        $this->assertSame([], $pathsProperty->getValue($analyzer));
    }

    public function testGetFilesToAnalyzeYieldsTheSameFilesOnEveryIteration(): void
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        $first = $this->pathnamesOf($analyzer->getFilesToAnalyzePublic());
        $second = $this->pathnamesOf($analyzer->getFilesToAnalyzePublic());

        $this->assertCount(5, $first); // 4 PHP files plus README.md
        $this->assertSame($first, $second);
    }

    public function testTheEmptyPathIdiomMatchesTheDefaultScanRoot(): void
    {
        // setPaths(['']) is how this suite spells "scan the base directory".
        // The default now produces the identical scan root.
        $explicit = new ConcreteFileAnalyzer();
        $explicit->setBasePath($this->testDir);
        $explicit->setPaths(['']);

        $default = new ConcreteFileAnalyzer();
        $default->setBasePath($this->testDir);

        $this->assertSame($this->sortedPhpFiles($explicit), $this->sortedPhpFiles($default));
    }

    // =========================================================================
    // '.' in the analyzed paths - ShieldCI/analyzers-core#84
    //
    // Excludes are anchored relative globs, so a scan root spelled '/project/.'
    // relativised to './vendor/...' and matched none of them. Every spelling of
    // a directory must now scan and report the same files.
    // =========================================================================

    public function testTheDotPathAppliesExcludePatterns(): void
    {
        $analyzer = $this->dotSegmentAnalyzer(['.']);

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testADotSlashPathReportsTheCleanLocation(): void
    {
        $analyzer = $this->dotSegmentAnalyzer(['./app']);

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testAnInnerDotSegmentReportsTheCleanLocation(): void
    {
        $analyzer = $this->dotSegmentAnalyzer(['app/./Http']);

        $this->assertSame(['app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testTheDotPathYieldsPathnamesWithoutADotSegment(): void
    {
        $analyzer = $this->dotSegmentAnalyzer(['.']);
        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame(
            [$this->testDir . '/project/app/A.php', $this->testDir . '/project/app/Http/H.php'],
            $files
        );
    }

    public function testTheDotPathMatchesTheDefaultAndEmptyPathScans(): void
    {
        $dot = $this->relativePhpFiles($this->dotSegmentAnalyzer(['.']));

        $this->assertSame($this->relativePhpFiles($this->dotSegmentAnalyzer([])), $dot);
        $this->assertSame($this->relativePhpFiles($this->dotSegmentAnalyzer([''])), $dot);
    }

    public function testNamedPathsStillScanAsBefore(): void
    {
        $this->assertSame(
            ['app/A.php', 'app/Http/H.php'],
            $this->relativePhpFiles($this->dotSegmentAnalyzer(['app']))
        );
        $this->assertSame(
            ['app/A.php', 'app/Http/H.php'],
            $this->relativePhpFiles($this->dotSegmentAnalyzer(['app', 'vendor']))
        );
    }

    /**
     * The project from the #84 report, under its own base path so the fixture
     * setUp() builds does not leak into the assertions.
     *
     * @param  array<string>  $paths
     */
    private function dotSegmentAnalyzer(array $paths): ConcreteFileAnalyzer
    {
        $project = $this->testDir . '/project';

        if (! is_dir($project)) {
            foreach (['app/Http', 'vendor/acme', 'storage/framework/views', 'tests'] as $dir) {
                mkdir($project . '/' . $dir, 0777, true);
            }

            file_put_contents($project . '/app/A.php', "<?php\n");
            file_put_contents($project . '/app/Http/H.php', "<?php\n");
            file_put_contents($project . '/vendor/acme/B.php', "<?php\n");
            file_put_contents($project . '/storage/framework/views/c.php', "<?php\n");
            file_put_contents($project . '/tests/DTest.php', "<?php\n");
        }

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['vendor/*', 'node_modules/*', 'storage/*', 'bootstrap/cache/*', 'tests/*']);

        if ($paths !== []) {
            $analyzer->setPaths($paths);
        }

        return $analyzer;
    }

    /**
     * @return array<string>
     */
    private function relativePhpFiles(ConcreteFileAnalyzer $analyzer): array
    {
        $files = array_map(
            fn (string $file): string => $analyzer->getRelativePathPublic($file),
            $analyzer->getPhpFilesPublic()
        );
        sort($files);

        return $files;
    }

    // =========================================================================
    // Unreadable directories - ShieldCI/analyzers-core#91
    //
    // One directory the scanner could not open used to throw out of the walk,
    // and AbstractAnalyzer::analyze() turned that into an Error result - the
    // readable files were never judged at all.
    // =========================================================================

    public function testAnUnreadableNestedDirectoryDoesNotFailTheAnalysis(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new WalkingFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $result = $analyzer->analyze();

        $this->assertSame('passed', $result->getStatus()->value, $result->getMessage());
        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
    }

    public function testAnUnreadableNestedDirectoryIsSkippedUnderTheDefaultScanRoot(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new WalkingFileAnalyzer();
        $analyzer->setBasePath($project);

        $result = $analyzer->analyze();

        $this->assertSame('passed', $result->getStatus()->value, $result->getMessage());
        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnUnreadableAnalyzedRootIsSkipped(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new WalkingFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers', 'app/Http']);

        $result = $analyzer->analyze();

        $this->assertSame('passed', $result->getStatus()->value, $result->getMessage());
        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testGetUnreadablePathsListsEachSkippedDirectoryOnce(): void
    {
        // 'app' finds app/Providers nested and 'app/Providers' names it as a
        // root; it is one directory and is reported once, absolute.
        $project = $this->unreadableProject();
        mkdir($project . '/app/Http/Locked');
        $this->lock($project . '/app/Http/Locked');
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers', 'app']);

        $analyzer->getPhpFilesPublic();
        $unreadable = $analyzer->getUnreadablePathsPublic();
        sort($unreadable);

        $this->assertSame([$project . '/app/Http/Locked', $project . '/app/Providers'], $unreadable);
    }

    public function testAFullyReadableTreeRecordsNoUnreadablePaths(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame(
            [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'],
            $files
        );
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testTheUnreadableRecordDescribesTheLatestWalkOnly(): void
    {
        // AnalyzerManager caches analyzer instances, so a directory fixed
        // between runs must not stay reported against the next one.
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $analyzer->getPhpFilesPublic();
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());

        chmod($project . '/app/Providers', 0755);

        $this->assertCount(2, $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testASearchlessNestedDirectoryIsRecorded(): void
    {
        // 0444 can be listed but not entered: its entries cannot be stat'ed, so
        // the walk used to drop them as non-files and record nothing.
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testASearchlessAnalyzedRootIsRecorded(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnUnreadableDirectoryInsideAnExcludedTreeIsNotRecorded(): void
    {
        // Every file under vendor/ would be excluded anyway, so reporting the
        // directory as missed coverage would be a false positive.
        $project = $this->unreadableProject();
        mkdir($project . '/vendor/pkg/locked', 0777, true);
        file_put_contents($project . '/vendor/pkg/locked/V.php', "<?php\n");
        $this->lock($project . '/vendor/pkg/locked');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['vendor/*']);

        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame(
            [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'],
            $files
        );
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnExcludedUnreadableAnalyzedRootIsNotRecorded(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers']);
        $analyzer->setExcludePatterns(['*/Providers/*']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnUnreadableDirectoryOutsideTheExcludesIsStillRecorded(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['vendor/*', '*.blade.php', 'app/Providers']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testADirectoryShapedExcludeDoesNotHideAnUnreadableDirectory(): void
    {
        // 'app/*/' matches the directory spelled with a trailing slash but no
        // file below it, so the files it hides from the walk stay unaccounted.
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['app/*/']);

        $analyzer->getPhpFilesPublic();
        $unreadable = $analyzer->getUnreadablePathsPublic();

        chmod($project . '/app/Providers', 0755);
        clearstatcache();

        $this->assertContains($project . '/app/Providers/P.php', $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $unreadable);
    }

    public function testATrailingSlashDirectoryExcludeDoesNotHideAnUnreadableDirectory(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['app/Providers/']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnExcludeOfEveryPhpFileHidesAnUnreadableDirectory(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setExcludePatterns(['*.php']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    /**
     * The tree from the #91 report, under its own base path so the fixture
     * setUp() builds does not leak into the assertions.
     */
    private function unreadableProject(): string
    {
        $project = $this->testDir . '/unreadable-project';

        mkdir($project . '/app/Http/Controllers', 0777, true);
        mkdir($project . '/app/Providers', 0777, true);
        file_put_contents($project . '/app/Http/Controllers/C.php', "<?php\n");
        file_put_contents($project . '/app/Providers/P.php', "<?php\n");

        return $project;
    }

    /**
     * Take read (0000) or search (0444) permission away from $dir, skipping the
     * test where that changes nothing (root, Windows, some ACL filesystems).
     */
    private function lock(string $dir, int $mode = 0000): void
    {
        chmod($dir, $mode);
        $this->lockedDirs[] = $dir;
        clearstatcache();

        if (is_readable($dir) && is_executable($dir)) {
            $this->markTestSkipped(sprintf('chmod %04o does not lock a directory here (running as root?)', $mode));
        }
    }

    // =========================================================================
    // Configured paths that name nothing - ShieldCI/analyzers-core#99
    //
    // An entry that was neither a directory nor a file was skipped with a bare
    // continue: a typo ('ap'), an absolute path outside the base, or a path
    // behind a directory the walk cannot enter all scanned nothing, recorded
    // nothing, and the analyzer passed.
    // =========================================================================

    public function testATypoedEntryIsRecordedAsMissing(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new WalkingFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['ap']);

        $result = $analyzer->analyze();

        $this->assertSame('passed', $result->getStatus()->value, $result->getMessage());
        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame(['ap'], $analyzer->getMissingPathsPublic());
    }

    public function testAnAbsoluteEntryOutsideTheBaseIsRecordedAsMissing(): void
    {
        // Still joined onto the base on purpose (#96), so it names nothing -
        // but now says so, spelled as configured.
        $project = $this->unreadableProject();
        $other = $this->testDir . '/other/app';
        mkdir($other, 0777, true);
        file_put_contents($other . '/O.php', "<?php\n");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths([$other]);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([$other], $analyzer->getMissingPathsPublic());
    }

    public function testAnEntryBehindASearchlessDirectoryRecordsThatDirectory(): void
    {
        // app/Providers exists, but 0444 on app stops it being stat'ed - it is
        // unreachable, not missing, and app is what to fix.
        $project = $this->unreadableProject();
        $this->lock($project . '/app', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app'], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAnEntryUnderAnUnreadableBaseRecordsTheBase(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingEntryUnderASearchOnlyDirectoryIsMissing(): void
    {
        // 0111 cannot be listed but can be searched, so app/Gone can still be
        // stat'ed: it is not there. A typo, not an unreachable path.
        $project = $this->unreadableProject();
        $this->lock($project . '/app', 0111);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Gone']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame(['app/Gone'], $analyzer->getMissingPathsPublic());
    }

    public function testAnEntryBehindAnExcludedUnreadableDirectoryIsNotRecorded(): void
    {
        // Same exclude rule as an unreadable directory met during the walk.
        $project = $this->unreadableProject();
        $this->lock($project . '/app', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers']);
        $analyzer->setExcludePatterns(['app/*']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingEntryIsRecordedOnce(): void
    {
        // Every spelling of one location - repeated, trailing separator,
        // './', absolute inside the base - is one missing entry, recorded in
        // the first spelling met after normalising.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['ap', 'ap', 'ap/', './ap', $project . '/ap']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame(['ap'], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingEntryIsRecordedWithItsSeparatorsNormalised(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app//Gone/', 'lib']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame(['app/Gone', 'lib'], $analyzer->getMissingPathsPublic());
    }

    public function testTheMissingRecordDescribesTheLatestWalkOnly(): void
    {
        // AnalyzerManager caches analyzer instances: a path created between
        // runs must not stay reported against the next one.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['lib']);

        $analyzer->getPhpFilesPublic();
        $this->assertSame(['lib'], $analyzer->getMissingPathsPublic());

        mkdir($project . '/lib');
        file_put_contents($project . '/lib/L.php', "<?php\n");

        $this->assertSame([$project . '/lib/L.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAValidEntryBesideAMissingOneStillYieldsItsFiles(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['ap', 'app/Http', 'lib/X.php']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame(['ap', 'lib/X.php'], $analyzer->getMissingPathsPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testValidEntriesRecordNothingMissing(): void
    {
        $project = $this->unreadableProject();

        foreach ([[], [''], ['app'], ['app/Providers/P.php']] as $paths) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($project);
            $analyzer->setPaths($paths);

            $this->assertNotSame([], $analyzer->getPhpFilesPublic(), json_encode($paths) ?: '');
            $this->assertSame([], $analyzer->getMissingPathsPublic(), json_encode($paths) ?: '');
        }
    }

    public function testAnUnreadableSubdirectoryIsStillRecordedAsUnreadableNotMissing(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingBaseRecordsTheDefaultEntryAsMissing(): void
    {
        // The default scan root is spelled '' - the same as setPaths(['']) -
        // and that is what is recorded when the base itself is gone.
        foreach ([[], ['']] as $paths) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($this->testDir . '/no-such-project');
            $analyzer->setPaths($paths);

            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame([''], $analyzer->getMissingPathsPublic(), json_encode($paths) ?: '');
            $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        }
    }

    public function testAMissingEntryUnderAnExcludedTreeIsNotRecorded(): void
    {
        // Every file it could hold would be excluded anyway.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['vendor/gone', 'lib']);
        $analyzer->setExcludePatterns(['vendor/*']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame(['lib'], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingFileEntryExcludedLiterallyIsNotRecorded(): void
    {
        // The NUL probe only matches wildcards; a literal exclude of the file
        // itself covers the entry just as fully.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['lib/X.php']);
        $analyzer->setExcludePatterns(['lib/X.php']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMissingFileEntryExcludedByItsAbsolutePathIsNotRecorded(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['lib/X.php']);
        $analyzer->setExcludePatterns([$project . '/lib/X.php']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testALiteralExcludeOfANonPhpEntryDoesNotHideIt(): void
    {
        // As with an unreadable directory, 'lib' excludes no file below it,
        // so a missing 'lib' is still reported.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['lib']);
        $analyzer->setExcludePatterns(['lib']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame(['lib'], $analyzer->getMissingPathsPublic());
    }

    public function testABlockedEntryTheExcludesCoverDoesNotReportItsBlocker(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers']);
        $analyzer->setExcludePatterns(['app/Providers/*']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testABlockedFileEntryExcludedLiterallyDoesNotReportItsBlocker(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app', 0444);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers/P.php']);
        $analyzer->setExcludePatterns(['app/Providers/P.php']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAnEntryUnderAFileIsRecordedAsMissing(): void
    {
        // The nearest existing ancestor is a file, not a directory it cannot
        // enter, so nothing is unreadable.
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers/P.php/x']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame(['app/Providers/P.php/x'], $analyzer->getMissingPathsPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnEntryNamingSomethingOtherThanAFileOrDirectoryIsNotMissing(): void
    {
        // A link to a device names something; it is not a PHP file, but it is
        // not a typo either.
        if (! @symlink('/dev/null', $this->testDir . '/null')) {
            $this->markTestSkipped('symlink() is not available here');
        }

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($this->testDir);
            $analyzer->setPaths(['null']);

            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame([], $analyzer->getMissingPathsPublic());
            $this->assertSame([], $analyzer->getUnreadablePathsPublic());
        } finally {
            unlink($this->testDir . '/null');
        }
    }

    public function testADanglingSymlinkEntryIsRecordedAsMissing(): void
    {
        if (! @symlink($this->testDir . '/gone', $this->testDir . '/dangling')) {
            $this->markTestSkipped('symlink() is not available here');
        }

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($this->testDir);
            $analyzer->setPaths(['dangling']);

            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame(['dangling'], $analyzer->getMissingPathsPublic());
        } finally {
            unlink($this->testDir . '/dangling');
        }
    }

    public function testAMissingEntryUnderARelativeBaseIsRecorded(): void
    {
        // dirname() of a relative path bottoms out at '.', not '/'.
        $cwd = getcwd();
        $this->assertIsString($cwd);
        chdir($this->testDir);

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath('src');
            $analyzer->setPaths(['Gone', 'File1.php']);

            $this->assertSame(['src/File1.php'], $analyzer->getPhpFilesPublic());
            $this->assertSame(['Gone'], $analyzer->getMissingPathsPublic());

            $analyzer->setBasePath('no-such-dir/deeper');
            $analyzer->setPaths(['x']);
            $analyzer->getPhpFilesPublic();
            $this->assertSame(['x'], $analyzer->getMissingPathsPublic());
        } finally {
            chdir($cwd);
        }
    }

    // =========================================================================
    // Absolute entries in the analyzed paths - ShieldCI/analyzers-core#96
    //
    // PathHelper::join() strips a segment's leading separators, so an absolute
    // entry such as base_path('app') was appended to the base path: the walk
    // looked for '/project/project/app', found nothing, and the analyzer
    // passed having read no file. An entry inside the base path is now
    // rewritten to its remainder and scanned like the relative spelling;
    // every other entry - '/', '/app', 'a:/b', a path outside the base - is
    // joined exactly as before, because a leading '/' is project-relative by
    // convention (base_path('/app')).
    // =========================================================================

    public function testAnAbsolutePathUnderTheBasePathIsScanned(): void
    {
        $analyzer = $this->dotSegmentAnalyzer([$this->testDir . '/project/app']);

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testAnAbsolutePathEqualToTheBasePathMatchesTheDefaultScan(): void
    {
        $absolute = $this->relativePhpFiles($this->dotSegmentAnalyzer([$this->testDir . '/project']));

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $absolute);
        $this->assertSame($this->relativePhpFiles($this->dotSegmentAnalyzer([])), $absolute);
    }

    public function testAnAbsolutePathCollapsesDotSegmentsLikeARelativeOne(): void
    {
        // '.' goes through the same PathHelper::join() collapse as a relative
        // entry (#84), so the reported locations come out clean.
        $analyzer = $this->dotSegmentAnalyzer([$this->testDir . '/project/./app/./Http']);

        $this->assertSame(['app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testAnAbsolutePathWithADotDotSegmentIsResolvedBeforeItIsClaimed(): void
    {
        // A '..' remainder is never trusted as text - '/p/..' starts with '/p'
        // too. Only the realpath() comparison can claim such an entry, so one
        // that stays inside the base reports the resolved locations rather
        // than the 'app/Http/../A.php' the relative spelling reports.
        $project = $this->testDir . '/project';

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer([$project . '/app/Http/..'])));
        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer([$project . '/../project/app'])));
    }

    public function testAnAbsolutePathThatLeavesTheBaseThroughDotDotScansNothing(): void
    {
        // Lexically these all start with the base, but they resolve outside
        // it, where the anchored excludes cannot reach ('../project/vendor/...'
        // escapes 'vendor/*'). Outside the base scans nothing, as before #96.
        $project = $this->testDir . '/project';
        $this->dotSegmentAnalyzer([]); // builds the fixture
        mkdir($this->testDir . '/project-other/app', 0777, true);
        file_put_contents($this->testDir . '/project-other/app/P.php', "<?php\n");
        mkdir($this->testDir . '/outside');
        file_put_contents($this->testDir . '/outside/O.php', "<?php\n");

        foreach ([$project . '/..', $project . '/../project-other/app', $project . '/app/../../outside'] as $path) {
            $this->assertSame([], $this->dotSegmentAnalyzer([$path])->getPhpFilesPublic(), "setPaths(['{$path}'])");
        }
    }

    public function testAProjectRelativeReadingThatExistsWinsOverTheAbsoluteOne(): void
    {
        // Under a top-level base such as Docker's WORKDIR /app, the entry
        // '/app' is both the base itself and the project-relative 'app' dir.
        // The string cannot decide, so the pre-#96 reading wins whenever it
        // names something: the fix only changes entries that scanned nothing.
        // Reproduced here by nesting a copy of the base's own path inside it.
        $base = $this->testDir . '/dock/app';
        mkdir($base . $base . '/Http', 0777, true);
        mkdir($base . '/Http');
        file_put_contents($base . '/Other.php', "<?php\n");
        file_put_contents($base . '/Http/X.php', "<?php\n");
        file_put_contents($base . $base . '/N.php', "<?php\n");
        file_put_contents($base . $base . '/Http/H.php', "<?php\n");
        $nested = ltrim($base, '/');

        $scan = function (string $path) use ($base): array {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($base);
            $analyzer->setPaths([$path]);

            return $this->relativePhpFiles($analyzer);
        };

        $this->assertSame([$nested . '/Http/H.php', $nested . '/N.php'], $scan($base));
        $this->assertSame([$nested . '/Http/H.php'], $scan($base . '/Http'));
    }

    public function testAnAbsoluteFilePathIsScanned(): void
    {
        $analyzer = $this->dotSegmentAnalyzer([$this->testDir . '/project/app/A.php']);

        $this->assertSame(['app/A.php'], $this->relativePhpFiles($analyzer));
    }

    public function testRelativeAndAbsoluteEntriesScanSideBySide(): void
    {
        $analyzer = $this->dotSegmentAnalyzer(['app/Http', $this->testDir . '/project/app/A.php']);

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer));
    }

    public function testAnAbsolutePathUnderTheBasePathHonoursRelativeExcludes(): void
    {
        $project = $this->testDir . '/project';
        $this->dotSegmentAnalyzer([]); // builds the fixture
        mkdir($project . '/app/Legacy');
        file_put_contents($project . '/app/Legacy/L.php', "<?php\n");

        $scan = function (array $paths) use ($project): array {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($project);
            $analyzer->setPaths($paths);
            $analyzer->setExcludePatterns(['app/Legacy/*']);

            return $this->relativePhpFiles($analyzer);
        };

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $scan([$project . '/app']));
        $this->assertSame($scan(['app']), $scan([$project . '/app']));
    }

    public function testAnAbsolutePathOutsideTheBasePathStillScansNothing(): void
    {
        // The pre-#96 behaviour, kept on purpose for now: only an entry inside the
        // base path is read as absolute, so one outside it is joined onto the
        // base like any other entry and names nothing. 'project-other' also
        // pins the segment boundary - base '/project' must not claim it.
        $outside = $this->testDir . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/O.php', "<?php\n");
        mkdir($this->testDir . '/project-other/app', 0777, true);
        file_put_contents($this->testDir . '/project-other/app/P.php', "<?php\n");

        $this->assertSame([], $this->dotSegmentAnalyzer([$outside])->getPhpFilesPublic());
        $this->assertSame([], $this->dotSegmentAnalyzer([$this->testDir . '/project-other/app'])->getPhpFilesPublic());
    }

    public function testARootEntryStillScansTheBasePath(): void
    {
        // '/' is not inside the base path, so it is joined as on master and
        // names the base itself - it must never walk the whole filesystem.
        foreach (['/', '/.', '/./'] as $path) {
            $analyzer = $this->dotSegmentAnalyzer([$path]);

            foreach ($analyzer->getFilesToAnalyzePublic() as $first) {
                $this->assertStringStartsWith($this->testDir . '/project/', $first->getPathname(), "setPaths(['{$path}'])");

                break;
            }

            $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer), "setPaths(['{$path}'])");
        }
    }

    public function testALeadingSeparatorStillMeansProjectRelative(): void
    {
        // base_path('/app') is the Laravel spelling of 'app', and join() keeps
        // '/src' inside the base on purpose. Not inside the base, so joined.
        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer(['/app'])));
        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer(['\\app'])));
    }

    public function testADriveShapedPosixDirectoryIsStillRelative(): void
    {
        $this->dotSegmentAnalyzer([]); // builds the fixture
        mkdir($this->testDir . '/project/a:/b', 0777, true);
        file_put_contents($this->testDir . '/project/a:/b/X.php', "<?php\n");

        $this->assertSame(['a:/b/X.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer(['a:/b'])));
    }

    public function testAnAbsolutePathThroughASymlinkResolvesToTheBaseSpelling(): void
    {
        // /var vs /private/var on macOS is the common case. Matching on the
        // realpaths means the files are reported - and excluded - exactly as
        // the relative spelling would be; vendor/ must stay out.
        $project = $this->testDir . '/project';
        $this->dotSegmentAnalyzer([]); // builds the fixture
        $link = $this->testDir . '/link';
        symlink($project, $link);

        try {
            $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer([$link . '/'])));
            $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer([$link . '/app'])));

            $viaLink = new ConcreteFileAnalyzer();
            $viaLink->setBasePath($link);
            $viaLink->setPaths([$project]);
            $viaLink->setExcludePatterns(['vendor/*', 'storage/*', 'tests/*']);

            $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($viaLink));
        } finally {
            unlink($link);
        }
    }

    public function testASymlinkInsideTheBaseKeepsItsSpellingWhenTheBaseIsSymlinked(): void
    {
        // Base 'link' -> 'proj', and 'proj/applink' -> 'proj/app'. The entry is
        // matched against realpath(base) as text before realpath(entry) is
        // tried, so 'applink' is not resolved away: the same files and
        // locations as the relative 'applink', and its excludes still apply.
        $real = $this->testDir . '/proj';
        mkdir($real . '/app/Legacy', 0777, true);
        file_put_contents($real . '/app/A.php', "<?php\n");
        file_put_contents($real . '/app/Legacy/L.php', "<?php\n");
        symlink($real . '/app', $real . '/applink');
        $link = $this->testDir . '/link';
        symlink($real, $link);

        $scan = function (string $path) use ($link): array {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($link);
            $analyzer->setPaths([$path]);
            $analyzer->setExcludePatterns(['applink/Legacy/*']);

            return $this->relativePhpFiles($analyzer);
        };

        try {
            $this->assertSame(['applink/A.php'], $scan('applink'));
            $this->assertSame(['applink/A.php'], $scan((string) realpath($real) . '/applink'));
        } finally {
            unlink($link);
            unlink($real . '/applink');
        }
    }

    public function testAnEntryThroughAnotherLinkToTheBaseKeepsItsInBaseSymlink(): void
    {
        // The c1 class in other spellings: the entry reaches the base through
        // a link the base itself is not spelled with. The prefix walk finds the
        // shortest prefix that resolves to the base and keeps the rest as
        // typed, so 'applink' is not resolved away and its excludes apply.
        $t = $this->linkFixture();

        try {
            $expected = ['applink/A.php', 'applink/Http/Controllers/C.php'];

            $this->assertSame($expected, $this->linkScan($t . '/proj', 'applink'));
            $this->assertSame($expected, $this->linkScan($t . '/proj', $t . '/link/applink'));
            $this->assertSame($expected, $this->linkScan($t . '/link', $t . '/link2/applink'));
            // /var vs /private/var on macOS; a no-op spelling elsewhere.
            $this->assertSame($expected, $this->linkScan($t . '/proj', (string) realpath($t) . '/link/applink'));
            $this->assertSame($expected, $this->linkScan((string) realpath($t) . '/link', $t . '/link2/applink'));
        } finally {
            $this->removeLinkFixture($t);
        }
    }

    public function testAnEntryThroughASymlinkFromOutsideTheBaseStillResolves(): void
    {
        // No prefix of 'elsewhere/inlink' resolves to the base, so the last
        // resort - realpath() of the whole entry - still claims it.
        $t = $this->linkFixture();

        try {
            $this->assertSame(
                ['app/A.php', 'app/Http/Controllers/C.php', 'app/Legacy/L.php'],
                $this->linkScan($t . '/proj', $t . '/elsewhere/inlink')
            );
            $this->assertSame([], $this->linkScan($t . '/proj', $t . '/missing/applink'));
            $this->assertSame([], $this->linkScan($t . '/link', $t . '/link2/missing'));
        } finally {
            $this->removeLinkFixture($t);
        }
    }

    // =========================================================================
    // A miscased absolute entry on a case-insensitive filesystem
    //
    // realpath() keeps the case it is given, so on macOS APFS (and Windows)
    // the entry '<T>/PROJ/app' under base '<T>/proj' resolved to '<T>/PROJ',
    // never matched realpath(base), and was scanned as nothing and recorded
    // missing although the directory exists. A prefix that is the same
    // directory as the base now counts as the base; the remainder is kept as
    // typed and joined onto the base as stored.
    // =========================================================================

    public function testAMiscasedAbsoluteEntryIsScannedOnACaseInsensitiveFilesystem(): void
    {
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);

        foreach ([$t, (string) realpath($t)] as $root) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($root . '/proj');
            $analyzer->setPaths([$root . '/PROJ/app']);

            $files = $analyzer->getPhpFilesPublic();
            sort($files);

            $this->assertSame([$root . '/proj/app/Http/Controllers/C.php', $root . '/proj/app/Providers/P.php'], $files, "base {$root}/proj");
            $this->assertSame([], $analyzer->getMissingPathsPublic(), "base {$root}/proj");
        }
    }

    public function testAMiscasedRemainderIsRespelledToItsOnDiskNames(): void
    {
        // Only the base portion is matched by identity; what follows it is
        // joined on and then respelled to its on-disk names, as for a
        // relative entry (#104).
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($t . '/proj');
        $analyzer->setPaths([$t . '/PROJ/APP/Providers']);

        $this->assertSame([$t . '/proj/app/Providers/P.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMiscasedAbsoluteEntryBesideItsRelativeSpellingYieldsEachFileOnce(): void
    {
        // On master this also gave each file once - but only because the
        // miscased entry scanned nothing and was recorded missing.
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($t . '/proj');
        $analyzer->setPaths(['app', $t . '/PROJ/app']);
        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame([$t . '/proj/app/Http/Controllers/C.php', $t . '/proj/app/Providers/P.php'], $files);
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAMiscasedAbsoluteFileEntryIsScanned(): void
    {
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($t . '/proj');
        $analyzer->setPaths([$t . '/PROJ/app/Providers/P.php']);

        $this->assertSame([$t . '/proj/app/Providers/P.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testADifferentlyCasedDirectoryIsNotTheBaseOnACaseSensitiveFilesystem(): void
    {
        // '<T>/PROJ' here is a directory of its own, with its own file: it
        // must stay outside the base, as any absolute path outside it does.
        $t = $this->caseFixture();

        if (is_dir($t . '/PROJ')) {
            $this->markTestSkipped('The filesystem is case-insensitive: PROJ and proj are one directory.');
        }

        mkdir($t . '/PROJ/app', 0777, true);
        file_put_contents($t . '/PROJ/app/Other.php', "<?php\n");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($t . '/proj');
        $analyzer->setPaths([$t . '/PROJ/app']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame([$t . '/PROJ/app'], $analyzer->getMissingPathsPublic());
    }

    public function testSameDirectoryComparesByIdentityNotSpelling(): void
    {
        // Reachable on any filesystem: a symlink is a second spelling of one
        // directory, which is all a miscased spelling is on APFS. The caller
        // only ever passes resolved paths; this pins the identity check alone.
        $t = $this->caseFixture();
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'sameDirectory');
        symlink($t . '/proj', $t . '/link');

        try {
            $this->assertTrue($method->invoke(null, $t . '/proj', $t . '/link'));
            $this->assertTrue($method->invoke(null, $t . '/proj', $t . '/proj'));
            $this->assertFalse($method->invoke(null, $t . '/proj/app', $t . '/proj'));
            $this->assertFalse($method->invoke(null, $t . '/gone', $t . '/proj'));
            $this->assertFalse($method->invoke(null, $t . '/proj', $t . '/gone'));
        } finally {
            unlink($t . '/link');
        }
    }

    public function testRelativeRemainderFoldsTheDriveLetterOnWindowsOnly(): void
    {
        // Windows never tells 'c:' from 'C:', and a drive-letter entry never
        // reaches the realpath() steps, so the letter is folded in the text
        // comparison there. Nothing else is: the rest is the filesystem's
        // business. On POSIX 'c:' and 'C:' are two directories, so nothing is.
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'relativeRemainder');

        $cases = [
            ['c:\\proj\\app', 'C:\\proj', 'app', null],
            ['C:\\proj\\app', 'c:\\proj', 'app', null],
            ['c:/proj/app', 'c:\\proj', 'app', 'app'],
            ['c:\\', 'C:\\', '', null],
            ['d:\\proj\\app', 'C:\\proj', null, null],
            ['c:\\PROJ\\app', 'C:\\proj', null, null],
        ];

        foreach ($cases as [$path, $base, $windows, $posix]) {
            $this->assertSame($windows, $method->invoke(null, $path, $base, true), "Windows: relativeRemainder('{$path}', '{$base}')");
            $this->assertSame($posix, $method->invoke(null, $path, $base, false), "POSIX: relativeRemainder('{$path}', '{$base}')");
        }
    }

    public function testADriveShapedPosixEntryIsNotFoldedOntoADifferentlyCasedBase(): void
    {
        // On POSIX 'c:' and 'C:' are two directories. Folding the letter there
        // walked 'C:/proj/app' for the entry 'c:/proj/app'.
        $t = $this->testDir;
        $cwd = getcwd();
        chdir($t);

        try {
            mkdir('c:/proj/app', 0777, true);

            if (is_dir('C:')) {
                $this->markTestSkipped('The filesystem is case-insensitive: c: and C: are one directory.');
            }

            mkdir('C:/proj/app', 0777, true);
            file_put_contents('c:/proj/app/X.php', "<?php\n");
            file_put_contents('C:/proj/app/Y.php', "<?php\n");

            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath('C:/proj');
            $analyzer->setPaths(['c:/proj/app']);

            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame(['c:/proj/app'], $analyzer->getMissingPathsPublic());
        } finally {
            chdir($cwd === false ? $t : $cwd);
        }
    }

    public function testAMiscasedBaseFollowedByDotDotBackIntoTheBaseIsClaimed(): void
    {
        // '<T>/PROJ' is the base by identity, but its remainder
        // '../proj/outlink' is refused. The walk must go on to
        // '<T>/PROJ/../proj', which realpath() matches, rather than stop.
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);
        mkdir($t . '/outside');
        file_put_contents($t . '/outside/O.php', "<?php\n");
        symlink($t . '/outside', $t . '/proj/outlink');
        symlink($t . '/proj/app', $t . '/proj/applink');

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($t . '/proj');
            $analyzer->setPaths([$t . '/PROJ/../proj/outlink']);

            $this->assertSame([$t . '/proj/outlink/O.php'], $analyzer->getPhpFilesPublic());
            $this->assertSame([], $analyzer->getMissingPathsPublic());

            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($t . '/proj');
            $analyzer->setPaths([$t . '/PROJ/../proj/applink']);
            $analyzer->setExcludePatterns(['applink/*']);

            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame([], $analyzer->getMissingPathsPublic());
        } finally {
            unlink($t . '/proj/outlink');
            unlink($t . '/proj/applink');
        }
    }

    public function testDotDotSpellingsThroughAMiscasedBaseAreClaimed(): void
    {
        // The lower-case '<T>/proj/app/../app' was already claimed; these
        // spellings of the same directories through '<T>/PROJ' were not.
        $t = $this->caseFixture();
        $this->requireCaseInsensitive($t);
        $appFiles = [$t . '/proj/app/Http/Controllers/C.php', $t . '/proj/app/Providers/P.php'];

        foreach (['/PROJ/app/../app', '/PROJ/app/..', '/proj/../PROJ/app', '/PROJ/../PROJ/app'] as $entry) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($t . '/proj');
            $analyzer->setPaths([$t . $entry]);
            $files = $analyzer->getPhpFilesPublic();
            sort($files);

            $this->assertSame($appFiles, $files, "setPaths(['<T>{$entry}'])");
            $this->assertSame([], $analyzer->getMissingPathsPublic(), "setPaths(['<T>{$entry}'])");
        }
    }

    public function testADotDotEntryLeavingTheBaseIsNeverClaimed(): void
    {
        // A prefix only ever matches the base itself, and a remainder with
        // '..' is refused, so trying longer prefixes cannot let an entry out.
        $t = $this->caseFixture();
        mkdir($t . '/outside');
        file_put_contents($t . '/outside/O.php', "<?php\n");

        foreach (['/proj/../outside', '/PROJ/../outside', '/proj/app/../../outside'] as $entry) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($t . '/proj');
            $analyzer->setPaths([$t . $entry]);

            $this->assertSame([], $analyzer->getPhpFilesPublic(), "setPaths(['<T>{$entry}'])");
            $this->assertSame([$t . $entry], $analyzer->getMissingPathsPublic(), "setPaths(['<T>{$entry}'])");
        }

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($t . '/proj');
        $analyzer->setPaths([$t . '/proj/app/../app']);
        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame([$t . '/proj/app/Http/Controllers/C.php', $t . '/proj/app/Providers/P.php'], $files);
    }

    public function testRemainderUnderBaseClaimsAPrefixTheFilesystemCallsTheBase(): void
    {
        // Runs on any filesystem: the identity check is injected, standing in
        // for one that folds case, so a case-sensitive CI still fails if the
        // identity branch is removed. '<T>/PROJ' is made a real directory
        // where it is not already the base, since realpath() must resolve it.
        $t = $this->caseFixture();
        mkdir($t . '/outside');

        if (! is_dir($t . '/PROJ')) {
            mkdir($t . '/PROJ/app', 0777, true);
        }

        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'remainderUnderBase');
        $foldsCase = static fn (string $path, string $base): bool => strtolower($path) === strtolower($base);
        $never = static fn (string $path, string $base): bool => false;
        $base = $t . '/proj';

        $cases = [
            ['/PROJ/app', 'app'],
            ['/PROJ', ''],
            ['/PROJ/../proj/app', 'app'],
            ['/PROJ/app/../app', 'app'],
            ['/proj/../PROJ/app', 'app'],
            ['/PROJ/../outside', null],
            ['/PROJ/app/../../outside', null],
        ];

        foreach ($cases as [$entry, $expected]) {
            $this->assertSame($expected, $method->invoke(null, $t . $entry, $base, $foldsCase), "remainderUnderBase('<T>{$entry}')");
        }

        if (! is_dir($t . '/PROJ') || realpath($t . '/PROJ') !== realpath($base)) {
            $this->assertNull($method->invoke(null, $t . '/PROJ/app', $base, $never));
        }
    }

    /**
     * Under open_basedir, realpath() warns for every prefix outside the
     * allowed list, and '@' does not stop a handler that ignores
     * error_reporting(). The identity check must not add a warning to those:
     * it only stat()s paths realpath() has already resolved. Run in a plain
     * subprocess (not @runInSeparateProcess, whose child trips open_basedir
     * in tearDown) with a handler that records every warning, whatever
     * error_reporting() says. A handler that throws would stop at the first
     * prefix outside the allowed list ('/usr', '/private') and never reach
     * the identity check at all.
     *
     * The walk now resolves every entry under a handler that swallows those
     * warnings (#106), so the host sees none ('host'); the realpath()
     * warnings are recorded from scanRoot() called directly ('warnings').
     */
    public function testTheIdentityCheckAddsNoWarningUnderOpenBasedir(): void
    {
        $t = $this->caseFixture();
        $probe = $t . '/probe.php';
        file_put_contents($probe, <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';

            $probe = new class () extends ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer {
                protected function metadata(): ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata
                {
                    return new ShieldCI\AnalyzersCore\ValueObjects\AnalyzerMetadata('probe', 'Probe', 'Probe', ShieldCI\AnalyzersCore\Enums\Category::Security, ShieldCI\AnalyzersCore\Enums\Severity::Low);
                }

                protected function runAnalysis(): ShieldCI\AnalyzersCore\Contracts\ResultInterface
                {
                    return $this->passed('ok');
                }

                public function scan(string $base, string $entry): array
                {
                    $this->setBasePath($base);
                    $this->setPaths([$entry]);
                    $files = $this->getPhpFiles();
                    sort($files);

                    return ['files' => $files, 'missing' => $this->getMissingPaths()];
                }
            };

            $warnings = [];
            $out = [];

            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });

            $scanRoot = new ReflectionMethod(ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer::class, 'scanRoot');

            foreach (array_slice($argv, 3) as $entry) {
                $warnings = [];
                $result = $probe->scan($argv[2], $entry) + ['host' => $warnings];
                $warnings = [];
                $scanRoot->invoke(null, $argv[2], $entry);
                $out[] = $result + ['warnings' => $warnings];
            }

            echo 'SHIELDCI-PROBE ' . json_encode($out) . "\n";
            PHP);

        $repo = dirname(__DIR__, 3);
        $tmp = sys_get_temp_dir();
        $base = $t . '/proj';
        $miscased = $t . '/PROJ/app';
        $control = $t . '/nope/app';
        $entries = ['app', $base . '/app', '/usr/lib', $miscased, $control];
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d', 'open_basedir=' . implode(PATH_SEPARATOR, [$repo, $tmp, (string) realpath($tmp)]),
            '-d', 'xdebug.mode=off',
            '-d', 'display_startup_errors=0',
            '-d', 'error_reporting=' . E_ALL,
            $probe, $repo, $base, ...$entries,
        ])) . ' 2>&1';

        exec($command, $lines, $status);
        $marked = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'SHIELDCI-PROBE ')));
        $this->assertSame(0, $status, implode("\n", $lines));
        $this->assertCount(1, $marked, implode("\n", $lines));
        /** @var list<array{files: list<string>, missing: list<string>, host: list<string>, warnings: list<string>}> $record */
        $record = json_decode(substr($marked[0], strlen('SHIELDCI-PROBE ')), true);
        $appFiles = [$base . '/app/Http/Controllers/C.php', $base . '/app/Providers/P.php'];

        // The walk lets none of them reach the host's handler.
        foreach ($record as $i => $result) {
            $this->assertSame([], $result['host'], "entry {$entries[$i]}");
            unset($record[$i]['host']);
        }

        // Entries that never reach realpath() raise nothing.
        $this->assertSame(['files' => $appFiles, 'missing' => [], 'warnings' => []], $record[0]);
        $this->assertSame(['files' => $appFiles, 'missing' => [], 'warnings' => []], $record[1]);
        $this->assertNotSame([], $record[2]['warnings']);

        // The miscased entry raises the same warnings as a missing one with
        // the same prefixes, and every warning is realpath()'s own: a stat()
        // of a prefix realpath() refused would add one here.
        $this->assertSame($record[4]['warnings'], $record[3]['warnings']);

        foreach ($record as $i => $result) {
            foreach ($result['warnings'] as $warning) {
                $this->assertStringStartsWith('realpath(): open_basedir restriction in effect', $warning, "entry {$entries[$i]}");
            }
        }

        $this->assertSame(['files' => [], 'missing' => [$control]], array_diff_key($record[4], ['warnings' => true]));
        $this->assertSame(
            is_dir($miscased) ? ['files' => $appFiles, 'missing' => []] : ['files' => [], 'missing' => [$miscased]],
            array_diff_key($record[3], ['warnings' => true])
        );
    }

    /**
     * <T>/proj/app/{Http/Controllers/C.php, Providers/P.php}.
     */
    private function caseFixture(): string
    {
        $t = $this->testDir;
        mkdir($t . '/proj/app/Http/Controllers', 0777, true);
        mkdir($t . '/proj/app/Providers');
        file_put_contents($t . '/proj/app/Http/Controllers/C.php', "<?php\n");
        file_put_contents($t . '/proj/app/Providers/P.php', "<?php\n");

        return $t;
    }

    private function requireCaseInsensitive(string $t): void
    {
        if (! is_dir($t . '/PROJ')) {
            $this->markTestSkipped('The filesystem is case-sensitive: PROJ is not proj.');
        }
    }

    /**
     * open_basedir makes realpath() warn for a path outside it, and Laravel's
     * HandleExceptions turns that warning into an exception that fails the
     * analyzer. The pre-#96 walk never called realpath(), so '/usr/lib' next
     * to 'app' used to pass quietly - it must still.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRealpathStaysQuietUnderOpenBasedir(): void
    {
        $project = $this->testDir . '/project';
        $this->dotSegmentAnalyzer([]); // builds the fixture
        // The temp dir must stay reachable: PHPUnit hands the child's result
        // back through a file there. /usr/lib is outside either way.
        $tmp = sys_get_temp_dir();
        ini_set('open_basedir', implode(PATH_SEPARATOR, [dirname(__DIR__, 3), $tmp, (string) realpath($tmp)]));

        // The isolated child starts with E_WARNING masked out of
        // error_reporting(); a Laravel app runs with E_ALL.
        error_reporting(E_ALL);

        // Shaped like Laravel's HandleExceptions::handleError(): it throws for
        // any warning that error_reporting() still covers, so only an '@'d
        // call - which masks error_reporting() for its duration - gets past.
        set_error_handler(static function (int $severity, string $message): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $analyzer = $this->dotSegmentAnalyzer(['app/Http', '/usr/lib', '/usr/lib/missing', $project . '/app/A.php']);

            $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($analyzer));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * proj/app/{A.php, Http/Controllers/C.php, Legacy/L.php}, with
     * proj/applink -> proj/app, link and link2 -> proj, and
     * elsewhere/inlink -> proj/app.
     */
    private function linkFixture(): string
    {
        $t = $this->testDir;
        mkdir($t . '/proj/app/Http/Controllers', 0777, true);
        mkdir($t . '/proj/app/Legacy');
        mkdir($t . '/elsewhere');
        file_put_contents($t . '/proj/app/A.php', "<?php\n");
        file_put_contents($t . '/proj/app/Http/Controllers/C.php', "<?php\n");
        file_put_contents($t . '/proj/app/Legacy/L.php', "<?php\n");
        symlink($t . '/proj/app', $t . '/proj/applink');
        symlink($t . '/proj', $t . '/link');
        symlink($t . '/proj', $t . '/link2');
        symlink($t . '/proj/app', $t . '/elsewhere/inlink');

        return $t;
    }

    /**
     * Symlinks must go before tearDown(): its recursive delete follows a
     * directory link and then cannot rmdir() it.
     */
    private function removeLinkFixture(string $t): void
    {
        foreach (['/proj/applink', '/link', '/link2', '/elsewhere/inlink'] as $link) {
            unlink($t . $link);
        }
    }

    /**
     * @return array<string>
     */
    private function linkScan(string $base, string $path): array
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths([$path]);
        $analyzer->setExcludePatterns(['applink/Legacy/*']);

        return $this->relativePhpFiles($analyzer);
    }

    public function testRepeatedSeparatorsInAnAbsolutePathReportCleanLocations(): void
    {
        $project = $this->testDir . '/project';

        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer(['/' . $project . '/app'])));
        $this->assertSame(['app/A.php', 'app/Http/H.php'], $this->relativePhpFiles($this->dotSegmentAnalyzer([$project . '//app'])));
    }

    public function testRemainderUnderBaseComparesOnSegmentBoundaries(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'remainderUnderBase');

        $cases = [
            ['/p/app', '/p', 'app'],
            ['/p', '/p', ''],
            ['/p/', '/p', ''],
            ['/p/./a//b/.', '/p', 'a/b'],
            // '..' is only claimed through realpath(), which these do not resolve.
            ['/p/app/../x', '/p', null],
            ['/p/..', '/p', null],
            ['/proj/x', '/p', null],
            ['/app', '/p', null],
            ['/', '/p', null],
            // A root base claims nothing: joining onto it already answers the path.
            ['/shieldci-missing-96/x', '/', null],
            ['/shieldci-missing-96/x', '/shieldci-missing-base-96', null],
            // A relative base is left alone, or base '0' would rewrite '0/x' to 'x'.
            ['0/x', '0', null],
            // Windows shapes: the same comparison once separators are normalised.
            ['C:\\app\\src', 'C:\\app', 'src'],
            ['C:/app/src', 'C:\\app', 'src'],
            ['C:\\app', 'C:\\app\\', ''],
            ['C:\\application\\src', 'C:\\app', null],
            ['C:app\\src', 'C:\\app', null],
            ['\\\\server\\share\\app', '\\\\server\\share', 'app'],
        ];

        foreach ($cases as [$path, $base, $expected]) {
            $this->assertSame($expected, $method->invoke(null, $path, $base), "remainderUnderBase('{$path}', '{$base}')");
        }
    }

    public function testTreatsZeroAsARealBasePath(): void
    {
        // '0' is falsy, so getFilesToAnalyze()'s `$this->basePath ?` and
        // getEnvironment()'s empty() both read it as "no base path at all".
        $zeroDir = $this->testDir . '/0';
        mkdir($zeroDir);
        file_put_contents($zeroDir . '/Zero.php', "<?php\nclass Zero {}\n");
        file_put_contents($zeroDir . '/.env', "APP_ENV=staging\n");

        $cwd = getcwd();
        chdir($this->testDir);
        $GLOBALS['__shieldci_test_config'] = [];

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath('0');

            $this->assertSame(['0/Zero.php'], $analyzer->getPhpFilesPublic());
            $this->assertSame('staging', $analyzer->exposedGetEnvironment());
        } finally {
            unset($GLOBALS['__shieldci_test_config']);
            chdir($cwd === false ? $this->testDir : $cwd);
        }
    }

    // =========================================================================
    // Overlapping analyzed paths - ShieldCI/analyzers-core#97
    //
    // Each setPaths() entry used to be walked on its own, so two entries that
    // reach the same file yielded it twice and every issue in it was reported
    // twice. The walk now yields each pathname once, first-seen order.
    // =========================================================================

    public function testANestedEntryDoesNotYieldItsFilesTwice(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', 'app/Http']);

        $expected = [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'];

        $this->assertSame($expected, $this->sortedPhpFiles($analyzer));
        $this->assertSame($expected, $this->pathnamesOf($analyzer->getFilesToAnalyzePublic()));
    }

    public function testRelativeAndAbsoluteSpellingsOfOneEntryYieldEachFileOnce(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', $project . '/app']);

        $this->assertSame(
            [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'],
            $this->sortedPhpFiles($analyzer)
        );
    }

    public function testARepeatedEntryYieldsEachFileOnce(): void
    {
        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->overlapAnalyzer(['app', 'app'])));
    }

    public function testATrailingSlashEntryYieldsEachFileOnce(): void
    {
        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->overlapAnalyzer(['app/', 'app'])));
    }

    public function testADotSlashEntryYieldsEachFileOnce(): void
    {
        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->overlapAnalyzer(['app', './app'])));
    }

    public function testAFileEntryInsideADirectoryEntryYieldsTheFileOnce(): void
    {
        $analyzer = $this->overlapAnalyzer(['app/Http/Controllers/C.php', 'app']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
    }

    public function testOverlappingEntriesKeepFirstSeenOrder(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers', 'app']);

        $this->assertSame(
            [$project . '/app/Providers/P.php', $project . '/app/Http/Controllers/C.php'],
            $analyzer->getPhpFilesPublic()
        );
    }

    public function testAnUnreadableRootGivenWithATrailingSlashIsRecordedOnce(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Providers/', 'app']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testASingleEntryStillYieldsEveryFileOnce(): void
    {
        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->overlapAnalyzer(['app'])));
    }

    public function testNonOverlappingEntriesStillYieldBothTrees(): void
    {
        $analyzer = $this->overlapAnalyzer(['app/Http', 'app/Providers']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
    }

    public function testOverlappingEntriesStillHonourExcludes(): void
    {
        $project = $this->unreadableProject();

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', 'app/Http']);
        $analyzer->setExcludePatterns(['app/Http/*']);

        $this->assertSame([$project . '/app/Providers/P.php'], $analyzer->getPhpFilesPublic());
    }

    public function testAnInteriorDoubleSlashEntryYieldsEachFileOnce(): void
    {
        $analyzer = $this->overlapAnalyzer(['app', 'app//Http']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
    }

    public function testAnInteriorDoubleSlashFileEntryYieldsTheFileOnce(): void
    {
        $analyzer = $this->overlapAnalyzer(['app', 'app/Providers//P.php']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
    }

    public function testAnUnreadableRootGivenWithAnInteriorDoubleSlashIsRecordedOnce(): void
    {
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', 'app//Providers']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnInteriorDoubleSlashEntryHonoursRelativeExcludes(): void
    {
        // Whichever entry comes first, its spelling is the one yielded, so the
        // root itself must be clean: 'app//Http/...' slipped past 'app/Http/*'.
        $project = $this->testDir . '/unreadable-project';

        foreach ([['app', 'app//Http'], ['app//Http', 'app']] as $paths) {
            $analyzer = $this->overlapAnalyzer($paths);
            $analyzer->setExcludePatterns(['app/Http/*']);

            $this->assertSame([$project . '/app/Providers/P.php'], $analyzer->getPhpFilesPublic(), implode(', ', $paths));

            $this->recursiveDelete($project);
        }
    }

    public function testABaseWithAnInteriorDoubleSlashKeepsExcludesAndRelativePaths(): void
    {
        // setBasePath() stores the base as given. Cleaning the joined root
        // folded the base too, so yielded paths stopped starting with it and
        // relative excludes and locations silently stopped applying.
        $project = $this->unreadableProject();
        $base = dirname($project) . '//' . basename($project);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths(['app']);
        $analyzer->setExcludePatterns(['app/Http/*']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertSame([$base . '/app/Providers/P.php'], $files);
        $this->assertSame('app/Providers/P.php', $analyzer->getRelativePathPublic($files[0]));
    }

    public function testABaseWithAnInteriorDoubleSlashDedupsOverlappingEntries(): void
    {
        $project = $this->unreadableProject();
        $base = dirname($project) . '//' . basename($project);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths(['app', 'app//Http', $base . '/app']);

        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame([$base . '/app/Http/Controllers/C.php', $base . '/app/Providers/P.php'], $files);
    }

    public function testABaseWithLeadingSeparatorsKeepsExcludesAndRelativePaths(): void
    {
        $project = $this->unreadableProject();
        $base = '//' . $project;

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths(['app']);
        $analyzer->setExcludePatterns(['app/Http/*']);

        $files = $analyzer->getPhpFilesPublic();

        $this->assertSame([$base . '/app/Providers/P.php'], $files);
        $this->assertSame('app/Providers/P.php', $analyzer->getRelativePathPublic($files[0]));
    }

    public function testABaseWithAnInteriorDoubleSlashStillHidesAnExcludedUnreadableDirectory(): void
    {
        // recordUnreadablePath() probes the excludes with the relative path,
        // which only exists while the root still starts with the stored base.
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');
        $base = dirname($project) . '//' . basename($project);

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths(['app']);
        $analyzer->setExcludePatterns(['app/Providers/*']);

        $this->assertSame([$base . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testAPosixBackslashDirectoryNameIsStillScanned(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('A backslash is a separator on Windows, not a filename byte.');
        }

        $project = $this->testDir . '/backslash-project';
        mkdir($project . '/weird\\', 0777, true);
        mkdir($project . '/weird');
        file_put_contents($project . '/weird\\/W.php', "<?php\n");
        file_put_contents($project . '/weird/Other.php', "<?php\n");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['weird\\']);

        $this->assertSame([$project . '/weird\\/W.php'], $analyzer->getPhpFilesPublic());
    }

    public function testAbsoluteEntriesWithStraySeparatorsYieldEachFileOnce(): void
    {
        $project = $this->testDir . '/unreadable-project';

        foreach ([$project . '//app', $project . '/app/', $project . '//app//'] as $entry) {
            $analyzer = $this->overlapAnalyzer(['app', $entry]);

            $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer), $entry);

            $this->recursiveDelete($project);
        }
    }

    public function testAnEntryEndingInSlashNewlineScansTheNewlineDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('A newline is not allowed in a Windows file name.');
        }

        // "app/\n" names the directory "\n" inside app/. A trim anchored with
        // a bare '$' also matched before that newline and walked "app\n".
        $project = $this->testDir . '/newline-project';
        mkdir($project . "/app/\n", 0777, true);
        file_put_contents($project . "/app/\n/N.php", "<?php\n");
        file_put_contents($project . "/app\n", "<?php\n");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(["app/\n"]);

        $this->assertSame([$project . "/app/\n/N.php"], $this->pathnamesOf($analyzer->getFilesToAnalyzePublic()));
    }

    public function testADriveShapedPosixEntryWithATrailingSlashIsRecordedOnce(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('C:/ is a drive root on Windows.');
        }

        // On POSIX 'C:' is an ordinary directory name, so 'C:/' is trimmed
        // like any other trailing slash.
        $project = $this->unreadableProject();
        mkdir($project . '/C:');
        $this->lock($project . '/C:');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['C:/', 'C:']);

        $analyzer->getPhpFilesPublic();

        $this->assertSame([$project . '/C:'], $analyzer->getUnreadablePathsPublic());
    }

    public function testNormaliseEntryCleansSeparatorsWithoutEmptyingARoot(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'normaliseEntry');

        // [entry, windows, expected]
        $cases = [
            ['app/', false, 'app'],
            ['app', false, 'app'],
            ['/p/app//', false, '/p/app'],
            ['/p//app///Http', false, '/p/app/Http'],
            ['/', false, '/'],
            ['//', false, '//'],
            ['', false, ''],
            // A backslash is a filename byte on POSIX: neither interior nor
            // trailing ones are separators there.
            ['/p/a\\\\b', false, '/p/a\\\\b'],
            ['/p/x\\', false, '/p/x\\'],
            ['weird\\', false, 'weird\\'],
            ['app/\\', false, 'app/\\'],
            // A leading pair is kept (a UNC root on Windows), extra ones folded.
            ['//server//share/', false, '//server/share'],
            ['///p', false, '//p'],
            ['app\\', true, 'app'],
            ['C:\\proj\\app\\', true, 'C:\\proj\\app'],
            ['C:\\proj/\\app//Http', true, 'C:\\proj/app/Http'],
            ['C:/proj/app/\\', true, 'C:/proj/app'],
            ['\\\\server\\\\share\\', true, '\\\\server\\share'],
            ['\\', true, '\\'],
            ['C:\\', true, 'C:\\'],
            ['C:/', true, 'C:/'],
            // A drive root only exists on Windows: on POSIX 'C:' is a name.
            ['C:/', false, 'C:'],
            ['app/C:/', false, 'app/C:'],
            // '$' would also match before a final newline, which is a
            // filename byte on POSIX.
            ["app/\n", false, "app/\n"],
            ["app//\n", false, "app/\n"],
        ];

        foreach ($cases as [$path, $windows, $expected]) {
            $this->assertSame(
                $expected,
                $method->invoke(null, $path, $windows),
                sprintf("normaliseEntry('%s', %s)", $path, $windows ? 'windows' : 'posix')
            );
        }
    }

    public function testPathKeyFoldsBackslashesOnWindowsOnly(): void
    {
        // RecursiveDirectoryIterator joins with DIRECTORY_SEPARATOR, scan roots
        // are joined with '/': on Windows one file reached from 'app' and from
        // 'app/Http' arrives spelled two ways.
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'pathKey');

        $this->assertSame(
            $method->invoke(null, 'C:\\proj/app\\Http\\Controllers\\C.php', true),
            $method->invoke(null, 'C:\\proj/app/Http\\Controllers\\C.php', true)
        );
        $this->assertSame('C:/proj/app/Http/C.php', $method->invoke(null, 'C:\\proj/app\\Http\\C.php', true));

        // On POSIX 'a\b.php' is one file name and must not collide with 'a/b.php'.
        $this->assertSame('/p/a\\b.php', $method->invoke(null, '/p/a\\b.php', false));
        $this->assertNotSame(
            $method->invoke(null, '/p/a/b.php', false),
            $method->invoke(null, '/p/a\\b.php', false)
        );
    }

    // =========================================================================
    // Entries differing only in case - ShieldCI/analyzers-core#104
    //
    // On a case-insensitive filesystem (APFS, NTFS) 'app' and 'App' name one
    // directory, but each entry was walked under its typed spelling, so every
    // file came out twice and an 'app/Http/*' exclude missed the 'App/...'
    // copies. The cases that need such a filesystem skip elsewhere; CI runs
    // on a case-sensitive one, where the rest still pin the helpers down.
    // =========================================================================

    public function testCaseVariantEntriesYieldEachFileOnce(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['app', 'App']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
        $this->assertSame($this->overlapExpected(), $this->pathnamesOf($analyzer->getFilesToAnalyzePublic()));
    }

    public function testCaseVariantEntriesYieldEachFileOnceWhicheverComesFirst(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->caseAnalyzer(['App', 'app'])));
    }

    public function testANestedCaseVariantEntryYieldsEachFileOnce(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->caseAnalyzer(['app', 'App/Http'])));
    }

    public function testAMiscasedEntryAloneYieldsTheOnDiskSpelling(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->caseAnalyzer(['App'])));
    }

    public function testRelativeExcludesApplyToMiscasedEntries(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $provider = [$this->testDir . '/unreadable-project/app/Providers/P.php'];

        foreach ([['app', 'App'], ['App', 'app'], ['App'], ['APP/http', 'app/providers']] as $paths) {
            $analyzer = $this->caseAnalyzer($paths);
            $analyzer->setExcludePatterns(['app/Http/*']);

            $this->assertSame($provider, $analyzer->getPhpFilesPublic(), implode(', ', $paths));
        }
    }

    public function testAMiscasedFileEntryYieldsTheFileOnce(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['app/Providers/p.php', 'app']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
        $this->assertSame($this->overlapExpected()[1], $analyzer->getPhpFilesPublic()[0]);
    }

    public function testAMiscasedAbsoluteEntryUnderTheBaseYieldsTheOnDiskSpelling(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->testDir . '/unreadable-project';

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->caseAnalyzer([$project . '/App'])));
        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($this->caseAnalyzer([$project . '/App', 'app'])));
    }

    public function testAMiscasedBaseIsKeptAsStored(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $this->unreadableProject();
        // Yielded paths must keep starting with the base as stored, or
        // relativeTo() misses and relative excludes stop applying.
        $base = $this->testDir . '/UNREADABLE-project';

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($base);
        $analyzer->setPaths(['app', 'App']);
        $analyzer->setExcludePatterns(['app/Http/*']);

        $this->assertSame([$base . '/app/Providers/P.php'], $analyzer->getPhpFilesPublic());
    }

    public function testAnUnreadableDirectoryReachedThroughCaseVariantsIsRecordedOnce(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', 'App/providers']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([$project . '/app/Providers'], $analyzer->getUnreadablePathsPublic());
    }

    public function testCaseVariantsOfAMissingEntryAreRecordedOnceAsFirstConfigured(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['App/Missing', 'app/Missing']);
        $analyzer->getPhpFilesPublic();

        $this->assertSame(['App/Missing'], $analyzer->getMissingPathsPublic());
    }

    public function testAMiscasedFileEntryWithAHardLinkBesideItPicksTheCaseVariant(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $analyzer = $this->caseAnalyzer(['app/Providers/p.php', 'app']);
        $project = $this->testDir . '/unreadable-project';
        // Q.php is the same file as P.php: matching by identity alone finds
        // two names, and only P.php is the typed name in another case.
        link($project . '/app/Providers/P.php', $project . '/app/Providers/Q.php');

        // The file entry is walked first, so its pick is the first path
        // yielded: 'Q.php' here would mean the hard link was chosen.
        $files = $analyzer->getPhpFilesPublic();
        $this->assertSame($project . '/app/Providers/P.php', $files[0]);
        $this->assertSame(
            [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php', $project . '/app/Providers/Q.php'],
            $this->sortedPhpFiles($analyzer)
        );
    }

    public function testAMiscasedSymlinkEntryKeepsTheLinkSpelling(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->unreadableProject();
        symlink($project . '/app', $project . '/applink');

        try {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath($project);
            $analyzer->setPaths(['AppLink', 'applink']);
            $analyzer->setExcludePatterns(['applink/Http/*']);

            $this->assertSame([$project . '/applink/Providers/P.php'], $analyzer->getPhpFilesPublic());
        } finally {
            unlink($project . '/applink');
        }
    }

    public function testCaseVariantDirectoriesStayDistinctOnACaseSensitiveFilesystem(): void
    {
        if ($this->isCaseInsensitiveFilesystem()) {
            $this->markTestSkipped('needs a case-sensitive filesystem, where App and app can sit side by side');
        }

        $project = $this->unreadableProject();
        mkdir($project . '/App');
        file_put_contents($project . '/App/U.php', "<?php\n");

        $both = [$project . '/App/U.php', $project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'];

        $this->assertSame($both, $this->sortedPhpFiles($this->caseAnalyzer(['app', 'App'])));
        $this->assertSame([$project . '/App/U.php'], $this->caseAnalyzer(['App'])->getPhpFilesPublic());
    }

    public function testAMiscasedEntryNamesNothingOnACaseSensitiveFilesystem(): void
    {
        if ($this->isCaseInsensitiveFilesystem()) {
            $this->markTestSkipped('needs a case-sensitive filesystem');
        }

        $analyzer = $this->caseAnalyzer(['App', 'app/providers']);

        $this->assertSame([], $analyzer->getPhpFilesPublic());
        $this->assertSame(['App', 'app/providers'], $analyzer->getMissingPathsPublic());
    }

    public function testOnDiskSpellingLeavesWhatItCannotMatchAsTyped(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'onDiskSpelling');
        $project = $this->unreadableProject();

        // [base, full path, expected]
        $cases = [
            // The base itself, and anything not under it, is never touched.
            [$project, $project, $project],
            [$project, $this->testDir . '/src', $this->testDir . '/src'],
            // Already on-disk spelling.
            [$project, $project . '/app/Http/Controllers', $project . '/app/Http/Controllers'],
            [$project . '/', $project . '/app/Providers/P.php', $project . '/app/Providers/P.php'],
            // A segment that names nothing keeps itself and the rest as typed.
            [$project, $project . '/app/Nope/Deeper', $project . '/app/Nope/Deeper'],
            // A '..' segment stops the respelling: it and the rest stay as typed.
            [$project, $project . '/app/../app/Http', $project . '/app/../app/Http'],
            // A root base: every path is under it.
            ['/', $this->testDir, $this->testDir],
        ];

        foreach ($cases as [$base, $path, $expected]) {
            $this->assertSame($expected, $method->invoke(new ConcreteFileAnalyzer(), $base, $path, false), "onDiskSpelling('{$base}', '{$path}')");
        }
    }

    public function testOnDiskSpellingKeepsTheTypedRestBelowAnUnreadableDirectory(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'onDiskSpelling');
        $project = $this->unreadableProject();
        $this->lock($project . '/app');

        $this->assertSame($project . '/app/providers', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/app/providers', false));
    }

    public function testOnDiskSpellingRewritesMiscasedSegments(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'onDiskSpelling');
        $project = $this->unreadableProject();

        $this->assertSame($project . '/app/Http', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/APP/hTTP', false));
        $this->assertSame($project . '/app/Nope/x', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/App/Nope/x', false));
        // Nothing at or past a '.' or '..' is listed or respelled.
        $this->assertSame($project . '/app/../APP', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/App/../APP', false));
        // On Windows '\' separates too, and is kept where it was typed. On
        // POSIX 'App\Http' is one name, which does not exist.
        $this->assertSame($project . '/app\\Http', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/App\\HTTP', true));
        $this->assertSame($project . '/App\\HTTP', $method->invoke(new ConcreteFileAnalyzer(), $project, $project . '/App\\HTTP', false));
    }

    public function testListedSpellingPicksTheOneSameFileName(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'listedSpelling');
        // Stands in for lstat(): the name's file identity, null when it
        // cannot be stat'ed. 'APP' and 'app' are one file here, as on a
        // case-insensitive filesystem.
        $identities = ['app' => '1:10', 'APP' => '1:10', 'App' => '1:10', 'P.php' => '1:20', 'p.php' => '1:20', 'Q.php' => '1:20', 'x' => '1:30', 'É' => '1:40', 'é' => '1:40', 'y' => '1:40'];
        $identity = static fn (string $name): ?string => $identities[$name] ?? null;
        // A host whose lstat() leaves ino at 0 (older Windows builds): every
        // name looks like the same file, so the name has to decide.
        $blind = static fn (string $name): ?string => in_array(strtolower($name), ['.', '..', 'app', 'apple', 'p.php'], true) ? '1:0' : null;

        // [segment, listing, identity, expected]
        $cases = [
            ['app', ['.', '..', 'app', 'x'], $identity, 'app'],
            // On a case-sensitive filesystem a listed name is never folded.
            ['App', ['.', '..', 'app', 'App'], $identity, 'App'],
            ['APP', ['.', '..', 'app', 'x'], $identity, 'app'],
            // Same file under two names (a hard link): the case variant wins.
            ['p.php', ['.', '..', 'P.php', 'Q.php'], $identity, 'P.php'],
            // Same file under names that are no ASCII case variant: unsure.
            ['É', ['.', '..', 'é', 'y'], $identity, null],
            // Unique by identity even where ASCII folding cannot see it.
            ['É', ['.', '..', 'é', 'x'], $identity, 'é'],
            // The typed name cannot be stat'ed: missing, or behind a
            // directory that cannot be searched.
            ['nope', ['.', '..', 'app'], $identity, null],
            // It can, but no listed name is that file (an unreadable parent
            // lists nothing).
            ['x', [], $identity, null],
            ['APP', ['.', '..', 'P.php'], $identity, null],
            ['APP', ['.', '..', 'app', 'apple'], $blind, 'app'],
            ['p.php', ['.', '..', 'app', 'apple'], $blind, null],
            // An empty segment is never matched, though '.' is its directory.
            ['', ['.', '..', 'app'], static fn (string $name): string => in_array($name, ['', '.'], true) ? '1:1' : '1:2', null],
        ];

        foreach ($cases as $i => [$segment, $listing, $fn, $expected]) {
            $this->assertSame($expected, $method->invoke(null, $segment, $listing, $fn), "case {$i}: listedSpelling('{$segment}')");
        }
    }

    public function testTheIdentityFallbackNeverStatsDotOrDotDot(): void
    {
        // '<dir>/..' may be outside open_basedir, where even file_exists()
        // warns. A stub answering null for them would hide the call, so
        // record every name asked about instead.
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'listedSpelling');
        $calls = [];
        $identity = static function (string $name) use (&$calls): string {
            $calls[] = $name;

            return in_array($name, ['É', 'é'], true) ? '1:40' : '1:' . crc32($name);
        };

        $this->assertSame('é', $method->invoke(null, 'É', ['.', '..', 'é', 'x'], $identity));
        $this->assertSame(['É', 'é', 'x'], $calls);
    }

    public function testListedSpellingStatsOnlyTheCaseVariantsWhenOneIsTheFile(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'listedSpelling');
        $listing = ['.', '..', 'Target.php'];

        for ($i = 0; $i < 1000; $i++) {
            $listing[] = "f{$i}.php";
        }

        $calls = [];
        $identity = static function (string $name) use (&$calls): string {
            $calls[] = $name;

            return strcasecmp($name, 'target.php') === 0 ? '1:1' : '1:' . crc32($name);
        };

        // One lstat for the typed name, one for its one case variant - not
        // one per sibling.
        $this->assertSame('Target.php', $method->invoke(null, 'target.php', $listing, $identity));
        $this->assertSame(['target.php', 'Target.php'], $calls);
    }

    public function testFileIdentityAnswersNullOnlyForANameThatIsNotThere(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'fileIdentity');
        $project = $this->unreadableProject();
        symlink($project . '/nowhere', $project . '/dangling');

        try {
            $this->assertNull($method->invoke(null, $project . '/missing'));
            $this->assertNull($method->invoke(null, $project . '/missing/deeper'));

            $stat = lstat($project . '/app');
            $this->assertIsArray($stat);
            $this->assertSame($stat['dev'] . ':' . $stat['ino'], $method->invoke(null, $project . '/app'));
            // A dangling link names something: the link itself.
            $this->assertIsString($method->invoke(null, $project . '/dangling'));
            $this->assertNotSame($method->invoke(null, $project . '/app'), $method->invoke(null, $project . '/dangling'));
        } finally {
            unlink($project . '/dangling');
        }
    }

    public function testExcludesAlsoMatchTheTypedSpellingOfARespelledRoot(): void
    {
        // Set by hand so this runs on a case-sensitive filesystem too, where
        // no walk ever respells a root.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('/p');
        (new \ReflectionProperty(AbstractFileAnalyzer::class, 'typedRoots'))
            ->setValue($analyzer, ['/p/app' => ['/p/App' => true, '/p/APP' => true]]);
        $typedSpellings = new \ReflectionMethod(AbstractFileAnalyzer::class, 'typedSpellings');

        $this->assertSame(['/p/App/Http/C.php', '/p/APP/Http/C.php'], $typedSpellings->invoke($analyzer, '/p/app/Http/C.php'));
        $this->assertSame(['/p/App', '/p/APP'], $typedSpellings->invoke($analyzer, '/p/app'));
        // Only on a segment boundary: 'apple.php' is not under 'app'.
        $this->assertSame([], $typedSpellings->invoke($analyzer, '/p/apple.php'));
        $this->assertSame([], $typedSpellings->invoke($analyzer, '/p/src/A.php'));

        $analyzer->setExcludePatterns(['APP/Http/*']);
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('/p/app/Http/C.php')));
        $this->assertTrue($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('/p/app/Providers/P.php')));

        $analyzer->setExcludePatterns(['app/Http/*']);
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic(new \SplFileInfo('/p/app/Http/C.php')));
    }

    public function testAMissingEntryUnderAThrowingErrorHandlerIsStillRecorded(): void
    {
        $project = $this->unreadableProject();
        file_put_contents($project . '/app/A.php', "<?php\n");

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app', 'app/Repositories']);

        $this->withThrowingErrorHandler(function () use ($analyzer, $project): void {
            $this->assertContains($project . '/app/A.php', $analyzer->getPhpFilesPublic());
            $this->assertSame(['app/Repositories'], $analyzer->getMissingPathsPublic());
        });
    }

    public function testAnEntryBehindALockedDirectoryUnderAThrowingErrorHandlerIsStillRecorded(): void
    {
        $project = $this->unreadableProject();
        mkdir($project . '/app/Locked/Sub', 0777, true);
        $this->lock($project . '/app/Locked');

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['app/Locked/Sub']);

        $this->withThrowingErrorHandler(function () use ($analyzer, $project): void {
            $this->assertSame([], $analyzer->getPhpFilesPublic());
            $this->assertSame([$project . '/app/Locked'], $analyzer->getUnreadablePathsPublic());
        });
    }

    public function testAnExcludeSpelledLikeAMiscasedEntryStillApplies(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['App']);
        $analyzer->setExcludePatterns(['App/Http/*']);

        // Excluded under the typed spelling, yielded under the on-disk one.
        $this->assertSame([$this->overlapExpected()[1]], $analyzer->getPhpFilesPublic());
    }

    public function testAnOnDiskExcludeStillAppliesToAMiscasedEntry(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['APP/HTTP', 'App/providers']);
        $analyzer->setExcludePatterns(['app/Http/*']);

        $this->assertSame([$this->overlapExpected()[1]], $analyzer->getPhpFilesPublic());
    }

    public function testAMissingMiscasedEntryExcludedInItsOwnSpellingIsNotRecorded(): void
    {
        $this->requireCaseInsensitiveFilesystem();

        $analyzer = $this->caseAnalyzer(['App/Missing']);
        $analyzer->setExcludePatterns(['App/Missing/*']);
        $analyzer->getPhpFilesPublic();

        $this->assertSame([], $analyzer->getMissingPathsPublic());
    }

    public function testAnUnreadableDirectoryExcludedInTheEntrysSpellingIsNotRecorded(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->unreadableProject();
        $this->lock($project . '/app/Providers');

        $analyzer = $this->caseAnalyzer(['App']);
        $analyzer->setExcludePatterns(['App/Providers/*']);

        $this->assertSame([$project . '/app/Http/Controllers/C.php'], $analyzer->getPhpFilesPublic());
        $this->assertSame([], $analyzer->getUnreadablePathsPublic());
    }

    public function testAnExcludeTypedInTheEntrysUnicodeNormalisationStillApplies(): void
    {
        // Gated on normalisation folding below, not on case: case-sensitive
        // APFS still folds normalisation, so this respells there too.
        $project = $this->unreadableProject();
        $nfd = "cafe\u{0301}";
        $nfc = "caf\u{00e9}";
        mkdir($project . '/' . $nfd);
        file_put_contents($project . '/' . $nfd . '/X.php', "<?php\n");

        if (! is_dir($project . '/' . $nfc)) {
            $this->markTestSkipped('needs a normalisation-insensitive filesystem (APFS)');
        }

        $analyzer = $this->caseAnalyzer([$nfc]);
        $this->assertSame([$project . '/' . $nfd . '/X.php'], $analyzer->getPhpFilesPublic());

        $analyzer->setExcludePatterns([$nfc . '/*']);
        $this->assertSame([], $analyzer->getPhpFilesPublic());
    }

    public function testRespellingListsEachDirectoryOnce(): void
    {
        $listings = new \ReflectionProperty(AbstractFileAnalyzer::class, 'listings');
        $onDiskSpelling = new \ReflectionMethod(AbstractFileAnalyzer::class, 'onDiskSpelling');
        $project = $this->unreadableProject();
        $analyzer = new ConcreteFileAnalyzer();

        foreach (['app/Http', 'app/Providers', 'app/Http/Controllers'] as $entry) {
            $onDiskSpelling->invoke($analyzer, $project, $project . '/' . $entry, false);
        }

        $this->assertSame(
            [$project . '/', $project . '/app/', $project . '/app/Http/'],
            array_keys((array) $listings->getValue($analyzer))
        );
    }

    public function testTheWalkReleasesItsListings(): void
    {
        // AnalyzerManager caches analyzer instances: a listing held past the
        // walk is a whole directory kept in memory for the process's life.
        $listings = new \ReflectionProperty(AbstractFileAnalyzer::class, 'listings');
        $analyzer = $this->caseAnalyzer(['app/Http/Controllers/C.php', 'app/Providers/P.php']);

        $this->assertSame($this->overlapExpected(), $this->sortedPhpFiles($analyzer));
        $this->assertSame([], $listings->getValue($analyzer));
    }

    public function testNothingAtOrPastADotDotSegmentIsListed(): void
    {
        // '<base>/app/..' may lie outside open_basedir, where even is_dir()
        // warns; '..' stays as typed, and so does everything after it.
        $listings = new \ReflectionProperty(AbstractFileAnalyzer::class, 'listings');
        $onDiskSpelling = new \ReflectionMethod(AbstractFileAnalyzer::class, 'onDiskSpelling');
        $project = $this->unreadableProject();
        $analyzer = new ConcreteFileAnalyzer();

        $this->assertSame($project . '/app/../app/Http', $onDiskSpelling->invoke($analyzer, $project, $project . '/app/../app/Http', false));
        $this->assertSame($project . '/./app', $onDiskSpelling->invoke($analyzer, $project, $project . '/./app', false));
        // Only the base, to find 'app': the '..' stops it before 'app/' is read.
        $this->assertSame([$project . '/'], array_keys((array) $listings->getValue($analyzer)));
    }

    /**
     * A '..' entry reaching a sibling of the base that open_basedir allows,
     * under a handler that throws even for '@'-silenced warnings: the base's
     * parent is outside open_basedir, and is_dir() on it warns.
     */
    public function testADotDotEntryStaysQuietUnderOpenBasedir(): void
    {
        $project = $this->testDir . '/proj';
        $other = $this->testDir . '/other';
        mkdir($project . '/app/Http', 0777, true);
        mkdir($other);
        file_put_contents($project . '/app/A.php', "<?php\n");
        file_put_contents($project . '/app/Http/H.php', "<?php\n");
        file_put_contents($other . '/O.php', "<?php\n");

        $this->assertSame(
            'passed: 3 files analyzed',
            $this->analyzeUnderOpenBasedir($project, ['app', '../other'], [$project, $other])['result']
        );
    }

    /**
     * An entry typed in NFC for a directory stored in NFD has no ASCII case
     * variant on disk, so it is matched by file identity - which must not
     * stat '.' or '..': '<base>/..' is outside open_basedir here.
     */
    public function testANormalisationVariantEntryStaysQuietUnderOpenBasedir(): void
    {
        $project = $this->testDir . '/proj';
        mkdir($project . "/cafe\u{0301}", 0777, true);
        file_put_contents($project . "/cafe\u{0301}/X.php", "<?php\n");

        // Gated on normalisation folding, not on case: case-sensitive APFS
        // still folds it.
        if (! is_dir($project . "/caf\u{00e9}")) {
            $this->markTestSkipped('needs a normalisation-insensitive filesystem (APFS)');
        }

        foreach (["caf\u{00e9}", "CAF\u{00C9}"] as $entry) {
            if (! is_dir($project . '/' . $entry)) {
                continue; // 'CAFÉ' needs case folding as well
            }

            $this->assertSame('passed: 1 files analyzed', $this->analyzeUnderOpenBasedir($project, [$entry], [$project])['result'], $entry);
        }
    }

    /**
     * Run $analyzer's analyze() over $paths in a php subprocess whose
     * open_basedir allows only the repository and $allowed. Answers the
     * result as '<status>: <message>', the entries that walk recorded
     * missing, the files a second walk under the same handler yields,
     * relative to $base, and the child's whole output.
     *
     * $handler picks the error handler analyze() runs under: 'strict'
     * throws for every warning, '@'-silenced or not; 'laravel' throws only
     * for one error_reporting() lets through, as Laravel's HandleExceptions
     * does; 'none' installs none, so a warning is printed and an SPL method
     * that converts it throws instead.
     *
     * Every run also checks that the walk left nothing behind: no directory
     * listings held, and the child's own handler back in place.
     *
     * A subprocess rather than @runInSeparateProcess: that child also runs
     * tearDown(), which cannot reach the test directory once open_basedir
     * shuts it out, and would leave the fixture behind. Here the child only
     * reads; tearDown() runs in this process as usual. Xdebug is turned off
     * and startup errors kept quiet in the child (both flags are harmless
     * when no such extension is loaded), and each answer is picked out by a
     * marker, so a stray notice cannot break the comparison.
     *
     * @param  array<string>  $paths
     * @param  array<string>  $allowed
     * @param  'strict'|'laravel'|'none'  $handler
     * @param  class-string<ConcreteFileAnalyzer>  $analyzer
     * @return array{result: string, missing: list<string>, files: list<string>, output: string}
     */
    private function analyzeUnderOpenBasedir(
        string $base,
        array $paths,
        array $allowed,
        string $handler = 'strict',
        string $analyzer = WalkingFileAnalyzer::class,
    ): array {
        $repo = dirname(__DIR__, 3);
        $basedirs = [$repo];

        // The repository is allowed wholesale for the autoloader: a fixture
        // inside it (TMPDIR set under the repo) would make the test a no-op.
        $this->assertFalse(
            str_starts_with((string) realpath($base) . '/', (string) realpath($repo) . '/'),
            'the fixture must not sit inside the repository, which open_basedir allows'
        );

        foreach ($allowed as $dir) {
            $basedirs[] = $dir;
            $basedirs[] = (string) realpath($dir);
        }

        $script = <<<'PHP'
            [, $repo, $base, $paths, $mode, $class] = $argv;
            require $repo . '/vendor/autoload.php';
            require $repo . '/tests/Unit/Abstracts/AbstractFileAnalyzerTest.php';

            $analyzer = new $class();
            $analyzer->setBasePath($base);
            $analyzer->setPaths(json_decode($paths, true));

            error_reporting(E_ALL);
            $handler = match ($mode) {
                'strict' => static function (int $severity, string $message): bool {
                    throw new ErrorException($message, 0, $severity);
                },
                'laravel' => static function (int $severity, string $message): bool {
                    if (error_reporting() & $severity) {
                        throw new ErrorException($message, 0, $severity);
                    }

                    return false;
                },
                'none' => null,
            };

            if ($handler !== null) {
                set_error_handler($handler);
            }

            $result = $analyzer->analyze();
            $listings = (new ReflectionProperty(ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer::class, 'listings'))->getValue($analyzer);
            echo "\nRESULT> ", $result->getStatus()->value, ': ', $result->getMessage(), "\n";
            echo "MISSING> ", json_encode($analyzer->getMissingPathsPublic()), "\n";
            echo "LISTINGS> ", count($listings), "\n";
            echo "HANDLER> ", set_error_handler(null) === $handler ? 'kept' : 'changed', "\n";
            restore_error_handler();

            try {
                $files = array_map(static fn (string $file): string => substr($file, strlen($base) + 1), $analyzer->getPhpFilesPublic());
                sort($files);
                echo "FILES> ", json_encode($files), "\n";
            } catch (Throwable $e) {
                echo "FILES> ", json_encode(['threw: ' . $e->getMessage()]), "\n";
            }
            PHP;

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d', 'open_basedir=' . implode(PATH_SEPARATOR, $basedirs),
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=0',
            '-d', 'xdebug.mode=off',
            '-r', $script, $repo, $base, (string) json_encode($paths), $handler, $analyzer,
        ])) . ' 2>&1';

        exec($command, $output, $status);
        $all = implode("\n", $output);
        $this->assertSame(0, $status, $all);

        $answers = [];

        foreach (['RESULT', 'MISSING', 'LISTINGS', 'HANDLER', 'FILES'] as $marker) {
            $lines = array_values(preg_grep('/^' . $marker . '> /', $output) ?: []);
            $this->assertCount(1, $lines, $marker . "\n" . $all);
            $answers[$marker] = substr($lines[0], strlen($marker) + 2);
        }

        $this->assertSame('0', $answers['LISTINGS'], 'directory listings held after the walk: ' . $all);
        $this->assertSame('kept', $answers['HANDLER'], 'the walk replaced the caller\'s error handler');

        /** @var list<string> $files */
        $files = json_decode($answers['FILES'], true);
        /** @var list<string> $missing */
        $missing = json_decode($answers['MISSING'], true);

        return ['result' => $answers['RESULT'], 'missing' => $missing, 'files' => $files, 'output' => $all];
    }

    /**
     * A sibling the respelling compares by identity is a link out of
     * open_basedir: even stat()ing it warns (round 4, R1).
     */
    public function testARespelledEntryBesideALinkOutOfOpenBasedirStaysQuiet(): void
    {
        $project = $this->testDir . '/proj';
        $outside = $this->testDir . '/outside';
        mkdir($project . "/cafe\u{0301}", 0777, true);
        mkdir($outside);
        file_put_contents($project . "/cafe\u{0301}/X.php", "<?php\n");
        symlink($outside, $project . '/ext');

        try {
            if (! is_dir($project . "/caf\u{00e9}")) {
                $this->markTestSkipped('needs a normalisation-insensitive filesystem (APFS)');
            }

            $answer = $this->analyzeUnderOpenBasedir($project, ["caf\u{00e9}"], [$project]);

            $this->assertSame('passed: 1 files analyzed', $answer['result']);
            // The one file, under the on-disk (NFD) name.
            $this->assertSame(["cafe\u{0301}/X.php"], $answer['files']);
        } finally {
            unlink($project . '/ext');
        }
    }

    /**
     * Only the analyzed directory is inside open_basedir, not the base: the
     * base cannot even be listed to respell 'app' (R2a). Runs anywhere.
     */
    public function testEntriesUnderABaseOutsideOpenBasedirStayQuiet(): void
    {
        $project = $this->unreadableProject();

        foreach ([['app'], ['app/Http', 'app/Providers']] as $paths) {
            $answer = $this->analyzeUnderOpenBasedir($project, $paths, [$project . '/app']);

            $this->assertSame('passed: 2 files analyzed', $answer['result'], implode(', ', $paths));
            $this->assertSame(['app/Http/Controllers/C.php', 'app/Providers/P.php'], $answer['files']);
        }
    }

    /**
     * An entry through a symlink whose target is only partly inside
     * open_basedir: listing the link's directory warns (R2b). Runs anywhere.
     */
    public function testAnEntryThroughALinkOutOfOpenBasedirStaysQuiet(): void
    {
        $project = $this->testDir . '/proj';
        $outside = $this->testDir . '/outside';
        mkdir($project);
        mkdir($outside . '/lib', 0777, true);
        file_put_contents($outside . '/lib/L.php', "<?php\n");
        symlink($outside, $project . '/shared');

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['shared/lib'], [$project, $outside . '/lib']);

            $this->assertSame('passed: 1 files analyzed', $answer['result']);
            $this->assertSame(['shared/lib/L.php'], $answer['files']);
        } finally {
            unlink($project . '/shared');
        }
    }

    // =========================================================================
    // Paths open_basedir refuses - ShieldCI/analyzers-core#106
    // =========================================================================

    /**
     * An entry outside open_basedir that exists. file_exists(), is_dir() and
     * is_file() all warn for it, and under Laravel's handler the first one
     * ended the analysis in an Error result, so 'app' was never analysed.
     * The entry is recorded missing, as file_exists() answers with no handler.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testAnExistingEntryOutsideOpenBasedirIsRecordedMissing(string $handler): void
    {
        $project = $this->openBasedirProject();
        mkdir($this->testDir . '/outside');
        file_put_contents($this->testDir . '/outside/O.php', "<?php\n");

        $answer = $this->analyzeUnderOpenBasedir($project, ['app', '../outside'], [$project], $handler, MarkerFileAnalyzer::class);

        $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
        $this->assertSame(['../outside'], $answer['missing']);
        $this->assertSame(['app/A.php'], $answer['files']);
        $this->assertStringNotContainsString('open_basedir', $answer['output']);
    }

    /**
     * The same for an entry outside open_basedir that does not exist:
     * open_basedir refuses it before the filesystem is asked.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testAMissingEntryOutsideOpenBasedirIsRecordedMissing(string $handler): void
    {
        $project = $this->openBasedirProject();

        $answer = $this->analyzeUnderOpenBasedir($project, ['app', '../nope'], [$project], $handler, MarkerFileAnalyzer::class);

        $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
        $this->assertSame(['../nope'], $answer['missing']);
        $this->assertSame(['app/A.php'], $answer['files']);
        $this->assertStringNotContainsString('open_basedir', $answer['output']);
    }

    /**
     * A symlink to a directory outside open_basedir, inside a walked
     * directory. hasChildren() warned for it, which Laravel's handler turned
     * into an Error result; with no handler, SplFileInfo::isFile() threw.
     * It is skipped quietly, as a link to a directory is never followed.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testALinkToADirectoryOutsideOpenBasedirIsSkipped(string $handler): void
    {
        $project = $this->openBasedirProject();
        mkdir($this->testDir . '/outside');
        mkdir($project . '/lnk');
        file_put_contents($project . '/lnk/L.php', "<?php\n");
        symlink($this->testDir . '/outside', $project . '/lnk/link');

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['lnk', 'app'], [$project], $handler, MarkerFileAnalyzer::class);

            $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
            $this->assertSame([], $answer['missing']);
            $this->assertSame(['app/A.php', 'lnk/L.php'], $answer['files']);
            $this->assertStringNotContainsString('open_basedir', $answer['output']);
        } finally {
            unlink($project . '/lnk/link');
        }
    }

    /**
     * A symlink to a PHP file outside open_basedir is skipped the same way:
     * it cannot be read, so yielding it would only move the failure into
     * the analyzer.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testALinkToAFileOutsideOpenBasedirIsSkipped(string $handler): void
    {
        $project = $this->openBasedirProject();
        mkdir($this->testDir . '/outside');
        file_put_contents($this->testDir . '/outside/O.php', "<?php\n// MARKER\n");
        mkdir($project . '/lnk');
        symlink($this->testDir . '/outside/O.php', $project . '/lnk/O.php');

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['lnk', 'app'], [$project], $handler, MarkerFileAnalyzer::class);

            $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
            $this->assertSame([], $answer['missing']);
            $this->assertSame(['app/A.php'], $answer['files']);
            $this->assertStringNotContainsString('open_basedir', $answer['output']);
        } finally {
            unlink($project . '/lnk/O.php');
        }
    }

    /**
     * The base path itself outside open_basedir: every entry under it is
     * refused, and recorded missing rather than failing the analysis.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testEntriesUnderABaseWhollyOutsideOpenBasedirAreRecordedMissing(string $handler): void
    {
        $project = $this->openBasedirProject();
        $elsewhere = $this->testDir . '/elsewhere';
        mkdir($elsewhere);

        $answer = $this->analyzeUnderOpenBasedir($project, ['app'], [$elsewhere], $handler, MarkerFileAnalyzer::class);

        $this->assertSame('passed: no MARKER', $answer['result'], $answer['output']);
        $this->assertSame(['app'], $answer['missing']);
        $this->assertSame([], $answer['files']);
        $this->assertStringNotContainsString('open_basedir', $answer['output']);
    }

    /**
     * Under a handler that throws even for an '@'-silenced warning, a
     * refused link inside a walked directory must still be dropped, never
     * yielded. Thrown from the filter during next(), the exception was
     * cleared by CATCH_GET_CHILD (which covers the iterator's whole move,
     * not just getChildren()), so the link was taken for a leaf and yielded
     * as a file; met first, at rewind(), it ended the analysis instead.
     * Which one happened depended on readdir order, so both are pinned:
     * 'app' meets its links after zero.php, 'only' holds nothing else.
     */
    public function testRefusedLinksInAWalkAreDroppedUnderAHandlerThatIgnoresSilencing(): void
    {
        $project = $this->openBasedirProject();
        $outside = $this->testDir . '/outside';
        mkdir($outside . '/od', 0777, true);
        file_put_contents($outside . '/O.php', "<?php\n");
        file_put_contents($project . '/app/zero.php', "<?php\n");
        mkdir($project . '/only');
        $links = [
            $project . '/app/linkdir' => $outside . '/od',
            $project . '/app/linkfile.php' => $outside . '/O.php',
            $project . '/only/linkfile.php' => $outside . '/O.php',
        ];

        foreach ($links as $link => $target) {
            symlink($target, $link);
        }

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['app'], [$project]);

            $this->assertSame('passed: 2 files analyzed', $answer['result'], $answer['output']);
            $this->assertSame(['app/A.php', 'app/zero.php'], $answer['files']);

            $answer = $this->analyzeUnderOpenBasedir($project, ['only'], [$project]);

            $this->assertSame('passed: 0 files analyzed', $answer['result'], $answer['output']);
            $this->assertSame([], $answer['files']);
        } finally {
            array_map('unlink', array_keys($links));
        }
    }

    /**
     * A handler that throws even for an '@'-silenced warning used to get an
     * Error result for every refused root, since the root stats were only
     * silenced. They run under a swallow-all handler now, as the respelling
     * and the walk's filter do.
     */
    public function testARefusedRootUnderAHandlerThatIgnoresSilencingIsRecordedMissing(): void
    {
        $project = $this->openBasedirProject();
        mkdir($this->testDir . '/outside');

        $answer = $this->analyzeUnderOpenBasedir($project, ['app', '../outside'], [$project], 'strict', MarkerFileAnalyzer::class);

        $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
        $this->assertSame(['../outside'], $answer['missing']);
    }

    /**
     * A configured entry that is a link to a directory outside open_basedir.
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testARootLinkOutOfOpenBasedirIsRecordedMissing(string $handler): void
    {
        $project = $this->openBasedirProject();
        mkdir($this->testDir . '/outside');
        file_put_contents($this->testDir . '/outside/O.php', "<?php\n// MARKER\n");
        symlink($this->testDir . '/outside', $project . '/ext');

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['app', 'ext'], [$project], $handler, MarkerFileAnalyzer::class);

            $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
            $this->assertSame(['ext'], $answer['missing']);
            $this->assertSame(['app/A.php'], $answer['files']);
            $this->assertStringNotContainsString('open_basedir', $answer['output']);
        } finally {
            unlink($project . '/ext');
        }
    }

    /**
     * An absolute entry outside the base, which open_basedir also refuses:
     * remainderUnderBase()'s realpath() warns for it. Recorded missing, as
     * it is with no open_basedir at all (#99).
     *
     * @param  'strict'|'laravel'|'none'  $handler
     */
    #[DataProvider('openBasedirHandlers')]
    public function testAnAbsoluteEntryOutsideOpenBasedirIsRecordedMissing(string $handler): void
    {
        if (! is_dir('/usr/lib')) {
            $this->markTestSkipped('needs /usr/lib');
        }

        $project = $this->openBasedirProject();

        $answer = $this->analyzeUnderOpenBasedir($project, ['app', '/usr/lib'], [$project], $handler, MarkerFileAnalyzer::class);

        $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
        $this->assertSame(['/usr/lib'], $answer['missing']);
        $this->assertSame(['app/A.php'], $answer['files']);
        $this->assertStringNotContainsString('open_basedir', $answer['output']);
    }

    /**
     * The fences round the walk's filesystem calls (#106) swallow every
     * warning raised inside them, so nothing a subclass can override may run
     * inside one: getBasePath() before the walk, isExcludedPath() through
     * excludesEntry() for a missing entry ('ap') and through
     * recordUnreadablePath() for an unreadable root ('top') and an
     * unreadable nested directory ('app/locked'). Each warning the subclass
     * raises must reach the host's handler.
     */
    public function testEverySubclassWarningDuringTheWalkReachesTheHostHandler(): void
    {
        mkdir($this->testDir . '/app/locked', 0777, true);
        mkdir($this->testDir . '/top');
        file_put_contents($this->testDir . '/app/A.php', "<?php\n");
        $this->lock($this->testDir . '/app/locked');
        $this->lock($this->testDir . '/top');

        $analyzer = new WarningFileAnalyzer();
        $analyzer->setBasePath($this->testDir)->setPaths(['app', 'ap', 'top']);

        /** @var array<string, int> $seen */
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[$message] = ($seen[$message] ?? 0) + 1;

            return true;
        });

        try {
            $files = $this->pathnamesOf($analyzer->getFilesToAnalyzePublic());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([$this->testDir . '/app/A.php'], $files);
        $this->assertSame(['ap'], $analyzer->getMissingPathsPublic());
        $this->assertSame([$this->testDir . '/app/locked', $this->testDir . '/top'], $analyzer->getUnreadablePathsPublic());
        $this->assertGreaterThan(0, $analyzer->raised['excluded'] ?? 0);
        $this->assertGreaterThan(0, $analyzer->raised['base'] ?? 0);
        $this->assertSame($analyzer->raised['excluded'], $seen['SUBCLASS:excluded'] ?? 0);
        $this->assertSame($analyzer->raised['base'], $seen['SUBCLASS:base'] ?? 0);
    }

    /**
     * @return array<string, array{'strict'|'laravel'|'none'}>
     */
    public static function openBasedirHandlers(): array
    {
        return [
            'handler that ignores silencing' => ['strict'],
            'Laravel-style handler' => ['laravel'],
            'no handler' => ['none'],
        ];
    }

    /**
     * The fixture the #106 tests share, and their control: with nothing
     * refused, the finding in app/A.php fires and nothing is missing.
     */
    public function testTheOpenBasedirFixtureFindsTheMarkerWhenNothingIsRefused(): void
    {
        $project = $this->openBasedirProject();
        mkdir($project . '/lnk');
        mkdir($this->testDir . '/outside');
        symlink($this->testDir . '/outside', $project . '/lnk/link');

        try {
            $answer = $this->analyzeUnderOpenBasedir($project, ['lnk', 'app'], [$this->testDir], 'laravel', MarkerFileAnalyzer::class);

            $this->assertSame('failed: MARKER in app/A.php', $answer['result'], $answer['output']);
            $this->assertSame([], $answer['missing']);
            $this->assertSame(['app/A.php'], $answer['files']);
        } finally {
            unlink($project . '/lnk/link');
        }
    }

    /**
     * <testDir>/proj/app/A.php, holding MARKER.
     */
    private function openBasedirProject(): string
    {
        $project = $this->testDir . '/proj';
        mkdir($project . '/app', 0777, true);
        file_put_contents($project . '/app/A.php', "<?php\n// MARKER\n");

        return $project;
    }

    public function testANulByteInTheBasePathStillPasses(): void
    {
        // scandir() and lstat() throw a ValueError for a path holding a NUL
        // byte, which '@' and an error handler cannot stop; master never
        // called either, and passed.
        $listings = new \ReflectionProperty(AbstractFileAnalyzer::class, 'listings');
        $handler = static fn (): bool => false;
        set_error_handler($handler);

        try {
            $analyzer = new WalkingFileAnalyzer();
            $analyzer->setBasePath($this->testDir . "/p\0x");
            $analyzer->setPaths(['app']);

            $result = $analyzer->analyze();
            $this->assertSame(Status::Passed, $result->getStatus(), $result->getMessage());
            $this->assertSame([], $listings->getValue($analyzer));

            // The handler stack is as it was: ours on top.
            $this->assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    public function testTheRespellHelpersAnswerNothingForANulBytePath(): void
    {
        $listing = new \ReflectionMethod(AbstractFileAnalyzer::class, 'listing');
        $fileIdentity = new \ReflectionMethod(AbstractFileAnalyzer::class, 'fileIdentity');

        $this->assertSame([], $listing->invoke(null, $this->testDir . "/p\0x/"));
        $this->assertNull($fileIdentity->invoke(null, $this->testDir . "/p\0x/app"));
    }

    /**
     * realpath() throws a ValueError for a path holding a NUL byte, as
     * scandir() and lstat() do, so an absolute entry or a base with one used
     * to end the analysis in an Error result (#105).
     */
    private function nulByteAnalyzer(): WalkingFileAnalyzer
    {
        mkdir($this->testDir . '/app');
        file_put_contents($this->testDir . '/app/A.php', "<?php\n");

        $analyzer = new WalkingFileAnalyzer();
        $analyzer->setBasePath($this->testDir);

        return $analyzer;
    }

    public function testAnAbsoluteEntryHoldingANulByteIsRecordedMissing(): void
    {
        $analyzer = $this->nulByteAnalyzer();
        $analyzer->setPaths(["/no\0where"]);

        $result = $analyzer->analyze();

        $this->assertSame(Status::Passed, $result->getStatus(), $result->getMessage());
        $this->assertSame('0 files analyzed', $result->getMessage());
        $this->assertSame(["/no\0where"], $analyzer->getMissingPathsPublic());
    }

    public function testAnAbsoluteEntryHoldingANulByteLeavesTheOtherEntriesWalked(): void
    {
        $analyzer = $this->nulByteAnalyzer();
        $analyzer->setPaths(['app', "/no\0where"]);

        $result = $analyzer->analyze();

        $this->assertSame(Status::Passed, $result->getStatus(), $result->getMessage());
        $this->assertSame('1 files analyzed', $result->getMessage());
        $this->assertSame(['app/A.php'], $this->relativePhpFiles($analyzer));
        $this->assertSame(["/no\0where"], $analyzer->getMissingPathsPublic());
    }

    public function testAnAbsoluteEntryUnderABasePathHoldingANulByteStillPasses(): void
    {
        $analyzer = $this->nulByteAnalyzer();
        $analyzer->setBasePath($this->testDir . "\0x");
        $analyzer->setPaths(['/app']);

        $result = $analyzer->analyze();

        $this->assertSame(Status::Passed, $result->getStatus(), $result->getMessage());
        $this->assertSame('0 files analyzed', $result->getMessage());
    }

    public function testRemainderUnderBaseAnswersNullForANulBytePath(): void
    {
        $method = new \ReflectionMethod(AbstractFileAnalyzer::class, 'remainderUnderBase');
        mkdir($this->testDir . '/app');

        $this->assertNull($method->invoke(null, "/no\0where", $this->testDir));
        $this->assertNull($method->invoke(null, '/app', $this->testDir . "\0x"));
        // The lexical step answers first, and still claims such an entry.
        $this->assertSame("ap\0p", $method->invoke(null, $this->testDir . "/ap\0p", $this->testDir));
        // Control: an entry free of NUL bytes is still claimed through realpath().
        $this->assertSame('app', $method->invoke(null, '/' . $this->testDir . '/app', $this->testDir));
    }

    public function testRespellingLeavesTheCallersHandlerInChargeOfTheWalk(): void
    {
        $listings = new \ReflectionProperty(AbstractFileAnalyzer::class, 'listings');
        $project = $this->unreadableProject();
        $seen = [];
        $handler = static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        };

        // 'app/Missing' makes the respelling stat a name that is not there.
        $analyzer = $this->caseAnalyzer(['app', 'app/Missing']);
        set_error_handler($handler);

        try {
            $during = [];

            foreach ($analyzer->getFilesToAnalyzePublic() as $file) {
                // A subclass warning while it reads the yielded files.
                trigger_error('reading ' . $file->getFilename(), E_USER_WARNING);
                $during[] = set_error_handler(null) === $handler;
                restore_error_handler();
            }

            $after = set_error_handler(null);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        sort($seen);
        // The consumer's handler saw its own warnings, and nothing from the
        // respelling.
        $this->assertSame(['reading C.php', 'reading P.php'], $seen);
        $this->assertSame([true, true], $during);
        $this->assertSame($handler, $after);
        $this->assertSame([], $listings->getValue($analyzer));
        $this->assertSame(['app/Missing'], $analyzer->getMissingPathsPublic());
    }

    public function testANormalisationVariantEntryIsRespelledEndToEnd(): void
    {
        // Runs wherever names fold Unicode normalisation - case-sensitive
        // APFS included - but not on ext4, which folds nothing: there no
        // real path can be respelled, and the seams (listedSpelling()'s
        // identity callable, typedRoots) carry the coverage.
        $project = $this->unreadableProject();
        mkdir($project . "/cafe\u{0301}");
        file_put_contents($project . "/cafe\u{0301}/X.php", "<?php\n");

        if (! is_dir($project . "/caf\u{00e9}")) {
            $this->markTestSkipped('needs a normalisation-insensitive filesystem (APFS)');
        }

        $analyzer = $this->caseAnalyzer(["caf\u{00e9}", "cafe\u{0301}"]);
        $analyzer->setExcludePatterns(["caf\u{00e9}/Y*"]);

        $this->assertSame([$project . "/cafe\u{0301}/X.php"], $analyzer->getPhpFilesPublic());
    }

    public function testSubclassesCanAskWhetherAPathIsExcluded(): void
    {
        $analyzer = new JavaScriptFileAnalyzer();
        $analyzer->setBasePath('/p');
        $analyzer->setExcludePatterns(['resources/js/vendor/*', '*/legacy/*']);

        $this->assertSame(
            ['/p/resources/js/app.js'],
            $analyzer->keptJavaScript(['/p/resources/js/app.js', '/p/resources/js/vendor/lib.js', '/p/resources/js/legacy/old.js'])
        );

        // A respelled root: its typed spelling is honoured too (#104).
        (new \ReflectionProperty(AbstractFileAnalyzer::class, 'typedRoots'))
            ->setValue($analyzer, ['/p/resources/js' => ['/p/Resources/js' => true]]);
        $analyzer->setExcludePatterns(['Resources/js/vendor/*']);

        $this->assertSame(['/p/resources/js/app.js'], $analyzer->keptJavaScript(['/p/resources/js/app.js', '/p/resources/js/vendor/lib.js']));
    }

    public function testSubclassesSeeTheTypedSpellingOfAMiscasedEntry(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->unreadableProject();
        mkdir($project . '/resources/js/vendor', 0777, true);
        file_put_contents($project . '/resources/js/app.js', '');
        file_put_contents($project . '/resources/js/vendor/lib.js', '');

        $analyzer = new JavaScriptFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths(['Resources/js']);
        $analyzer->setExcludePatterns(['Resources/js/vendor/*']);

        $this->assertSame([$project . '/resources/js/app.js'], $analyzer->walkedJavaScript());
    }

    public function testReconfiguringForgetsTheTypedSpellingsOfTheLastWalk(): void
    {
        $typedRoots = new \ReflectionProperty(AbstractFileAnalyzer::class, 'typedRoots');
        $file = new \SplFileInfo('/p/app/Http/C.php');

        foreach (['setPaths' => ['app'], 'setBasePath' => '/p'] as $setter => $value) {
            $analyzer = new ConcreteFileAnalyzer();
            $analyzer->setBasePath('/p');
            $analyzer->setExcludePatterns(['App/Http/*']);
            $typedRoots->setValue($analyzer, ['/p/app' => ['/p/App' => true]]);
            $this->assertFalse($analyzer->shouldAnalyzeFilePublic($file), $setter);

            $analyzer->{$setter}($value);

            $this->assertTrue($analyzer->shouldAnalyzeFilePublic($file), $setter);
        }
    }

    public function testTypedSpellingsOutliveTheWalkUntilReconfigured(): void
    {
        $this->requireCaseInsensitiveFilesystem();
        $project = $this->testDir . '/unreadable-project';
        $file = new \SplFileInfo($project . '/app/Http/Controllers/C.php');

        $analyzer = $this->caseAnalyzer(['App']);
        $analyzer->setExcludePatterns(['App/Http/*']);
        iterator_to_array($analyzer->getFilesToAnalyzePublic(), false);

        // Subclasses collect the walk and filter afterwards.
        $this->assertFalse($analyzer->shouldAnalyzeFilePublic($file));

        $analyzer->setPaths(['app']);

        $this->assertTrue($analyzer->shouldAnalyzeFilePublic($file));
    }

    /**
     * Run $test under a handler that throws for every warning, whatever
     * error_reporting() says - so an '@' does not hide one.
     */
    private function withThrowingErrorHandler(callable $test): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $test();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * overlapAnalyzer() that builds the fixture only once, so a test can make
     * several analyzers over one tree.
     *
     * @param  array<string>  $paths
     */
    private function caseAnalyzer(array $paths): ConcreteFileAnalyzer
    {
        $project = $this->testDir . '/unreadable-project';

        if (! is_dir($project)) {
            $this->unreadableProject();
        }

        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($project);
        $analyzer->setPaths($paths);

        return $analyzer;
    }

    private function requireCaseInsensitiveFilesystem(): void
    {
        if (! $this->isCaseInsensitiveFilesystem()) {
            $this->markTestSkipped('needs a case-insensitive filesystem (APFS, NTFS), where App and app are one directory');
        }
    }

    /**
     * Whether the temp directory folds case: a file created under one
     * spelling is found under the other.
     */
    private function isCaseInsensitiveFilesystem(): bool
    {
        $probe = $this->testDir . '/case-probe';
        touch($probe);

        try {
            return file_exists($this->testDir . '/CASE-PROBE');
        } finally {
            unlink($probe);
        }
    }

    /**
     * @param  array<string>  $paths
     */
    private function overlapAnalyzer(array $paths): ConcreteFileAnalyzer
    {
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->unreadableProject());
        $analyzer->setPaths($paths);

        return $analyzer;
    }

    /**
     * @return array<string>
     */
    private function overlapExpected(): array
    {
        $project = $this->testDir . '/unreadable-project';

        return [$project . '/app/Http/Controllers/C.php', $project . '/app/Providers/P.php'];
    }

    /**
     * @param  iterable<\SplFileInfo>  $files
     * @return array<string>
     */
    private function pathnamesOf(iterable $files): array
    {
        $pathnames = [];

        foreach ($files as $file) {
            $pathnames[] = $file->getPathname();
        }

        sort($pathnames);

        return $pathnames;
    }

    /**
     * @return array<string>
     */
    private function sortedPhpFiles(ConcreteFileAnalyzer $analyzer): array
    {
        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        return $files;
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

    public function exposedGetBasePath(): string
    {
        return $this->getBasePath();
    }

    public function exposedGetEnvironment(): string
    {
        return $this->getEnvironment();
    }

    /**
     * @return list<string>
     */
    public function getUnreadablePathsPublic(): array
    {
        return $this->getUnreadablePaths();
    }

    /**
     * @return list<string>
     */
    public function getMissingPathsPublic(): array
    {
        return $this->getMissingPaths();
    }
}

// Filters files of its own through isExcludedPath(), as laravel's
// CsrfAnalyzer does for JavaScript, which shouldAnalyzeFile() declines.
class JavaScriptFileAnalyzer extends ConcreteFileAnalyzer
{
    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    public function keptJavaScript(array $files): array
    {
        return array_values(array_filter($files, fn (string $file): bool => ! $this->isExcludedPath($file)));
    }

    /**
     * @return list<string>
     */
    public function walkedJavaScript(): array
    {
        $files = [];

        foreach ($this->getFilesToAnalyze() as $file) {
            if ($file->getExtension() === 'js' && ! $this->isExcludedPath($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

// Walks its files inside analyze(), as real file analyzers do, so a walk that
// throws surfaces as the Error result AbstractAnalyzer::analyze() produces.
class WalkingFileAnalyzer extends ConcreteFileAnalyzer
{
    protected function runAnalysis(): ResultInterface
    {
        return $this->passed(count($this->getPhpFiles()) . ' files analyzed');
    }
}

// Fails on every walked file that holds MARKER, as a real analyzer reports a
// finding, so a test can tell the walk reached the file and analysed it.
class MarkerFileAnalyzer extends ConcreteFileAnalyzer
{
    protected function runAnalysis(): ResultInterface
    {
        $found = [];

        foreach ($this->getPhpFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'MARKER')) {
                $found[] = $this->getRelativePath($file);
            }
        }

        return $found === []
            ? $this->passed('no MARKER')
            : $this->failed('MARKER in ' . implode(', ', $found));
    }
}

// Raises a warning from each method a subclass can override on the walk's
// way, counting them, so a test can tell whether every one reached the host's
// handler or a fence swallowed some.
class WarningFileAnalyzer extends ConcreteFileAnalyzer
{
    /** @var array<string, int> */
    public array $raised = [];

    protected function isExcludedPath(string $path): bool
    {
        $this->raise('excluded');

        return parent::isExcludedPath($path);
    }

    protected function getBasePath(): string
    {
        $this->raise('base');

        return parent::getBasePath();
    }

    private function raise(string $what): void
    {
        $this->raised[$what] = ($this->raised[$what] ?? 0) + 1;
        trigger_error('SUBCLASS:' . $what, E_USER_WARNING);
    }
}
