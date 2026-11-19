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

// Walks its files inside analyze(), as real file analyzers do, so a walk that
// throws surfaces as the Error result AbstractAnalyzer::analyze() produces.
class WalkingFileAnalyzer extends ConcreteFileAnalyzer
{
    protected function runAnalysis(): ResultInterface
    {
        return $this->passed(count($this->getPhpFiles()) . ' files analyzed');
    }
}
