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

    public function testSetBasePathRemovesTrailingBackslash(): void
    {
        // PathHelper::relativeTo() rtrims '/\\', this rtrimmed '/' alone - so a
        // Windows base_path() with a trailing separator relativised correctly
        // but still double-separated on every join built from it (#62).
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath('C:\\proj\\app\\');

        $reflection = new \ReflectionClass($analyzer);
        $property = $reflection->getProperty('basePath');
        $property->setAccessible(true);

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
        $pathsProperty->setAccessible(true);

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

    public function testTheDotPathIdiomStillResolvesToTheBaseDirectory(): void
    {
        // setPaths(['.']) is the dominant idiom in the downstream suites, and
        // consumers strip the resulting './' segment back off reported
        // locations - so join() must leave it in place, not collapse it.
        $analyzer = new ConcreteFileAnalyzer();
        $analyzer->setBasePath($this->testDir);
        $analyzer->setPaths(['.']);

        $files = $analyzer->getPhpFilesPublic();
        sort($files);

        $this->assertSame(
            [
                $this->testDir . '/./src/File1.php',
                $this->testDir . '/./src/File2.php',
                $this->testDir . '/./tests/Test1.php',
                $this->testDir . '/./vendor/Package.php',
            ],
            $files
        );
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
}
