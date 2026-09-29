<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Support\ConfigFileParser;
use ShieldCI\AnalyzersCore\Tests\Support\FakeRecordingParser;

class ConfigFileParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/shieldci_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir.'/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        if ($scanned === false) {
            return;
        }

        foreach (array_diff($scanned, ['.', '..']) as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testFindNestedArrayKeyLineReturnsLineForAuthoredKey(): void
    {
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'default' => env('LOG_CHANNEL', 'stack'),\n    'channels' => [\n        'stack' => [\n            'driver' => 'stack',\n            'channels' => ['single'],\n        ],\n        'single' => [\n            'driver' => 'single',\n            'level' => env('LOG_LEVEL', 'debug'),\n        ],\n    ],\n];\n");

        $this->assertSame(6, (new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'stack'));
        $this->assertSame(10, (new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'single'));
    }

    public function testFindNestedArrayKeyLineReturnsNullForMissingKey(): void
    {
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'channels' => [\n        'single' => [\n            'driver' => 'single',\n        ],\n    ],\n];\n");

        $this->assertNull((new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'laravel-cloud-socket'));
    }

    public function testFindNestedArrayKeyLineIgnoresStringValuesWithSameName(): void
    {
        // 'single' appears as a string value inside the stack channel list, but is
        // NOT authored as its own channel sub-array — must not be treated as authored.
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'channels' => [\n        'stack' => [\n            'driver' => 'stack',\n            'channels' => ['single'],\n        ],\n    ],\n];\n");

        $this->assertNull((new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'single'));
    }

    public function testFindNestedArrayKeyLineReturnsNullWhenParentMissing(): void
    {
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'default' => 'stack',\n];\n");

        $this->assertNull((new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'single'));
    }

    public function testFindNestedArrayKeyLineReturnsNullWhenFileNotFound(): void
    {
        $this->assertNull((new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine('/nonexistent/file.php', 'channels', 'single'));
    }

    public function testFindNestedArrayKeyLineReturnsNullWhenReturnIsNotArray(): void
    {
        // File parses, but the returned expression is not an array.
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn 'not-an-array';\n");

        $this->assertNull((new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'single'));
    }

    public function testFindNestedArrayKeyLineSkipsNonStringKeyedItems(): void
    {
        // Both the top-level array and the parent array contain list (non-keyed) items
        // before the targeted keys, exercising the skip branches in both loops.
        $configFile = $this->tempDir.'/config/logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'first-value',\n    'channels' => [\n        'list-item',\n        'single' => [\n            'driver' => 'single',\n        ],\n    ],\n];\n");

        $this->assertSame(7, (new ConfigFileParser(new AstParser()))->findNestedArrayKeyLine($configFile, 'channels', 'single'));
    }

    public function testParseConfigArrayExtractsStringValues(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'name' => 'MyApp',
    'url' => 'https://example.com',
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('MyApp', $result['name']['value']);
        $this->assertFalse($result['name']['isEnvCall']);
        $this->assertSame('https://example.com', $result['url']['value']);
    }

    public function testParseConfigArrayExtractsBoolAndNull(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'debug' => false,
    'maintenance' => true,
    'secret' => null,
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertFalse($result['debug']['value']);
        $this->assertTrue($result['maintenance']['value']);
        $this->assertNull($result['secret']['value']);
    }

    public function testParseConfigArrayDetectsEnvCallWithoutDefault(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'key' => env('APP_KEY'),
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertTrue($result['key']['isEnvCall']);
        $this->assertNull($result['key']['value']);
        $this->assertFalse($result['key']['envHasDefault']);
        $this->assertNull($result['key']['envDefault']);
    }

    public function testParseConfigArrayDetectsEnvCallWithDefault(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'debug' => env('APP_DEBUG', false),
    'name' => env('APP_NAME', 'Laravel'),
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertTrue($result['debug']['isEnvCall']);
        $this->assertTrue($result['debug']['envHasDefault']);
        $this->assertFalse($result['debug']['envDefault']);
        $this->assertTrue($result['name']['isEnvCall']);
        $this->assertTrue($result['name']['envHasDefault']);
        $this->assertSame('Laravel', $result['name']['envDefault']);
    }

    public function testParseConfigArrayRecordsLineNumbers(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'name' => 'App',
    'debug' => false,
    'key' => env('APP_KEY'),
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertSame(3, $result['name']['line']);
        $this->assertSame(4, $result['debug']['line']);
        $this->assertSame(5, $result['key']['line']);
    }

    public function testParseConfigArrayReturnsEmptyForNonExistentFile(): void
    {
        $result = (new ConfigFileParser(new AstParser()))->parseArray('/non/existent/config.php');

        $this->assertSame([], $result);
    }

    public function testParseConfigArrayReturnsEmptyForFileWithoutReturn(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, '<?php echo "no return";');

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertSame([], $result);
    }

    public function testParseConfigArraySkipsNonStringKeys(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'valid' => 'yes',
    0 => 'numeric key skipped',
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertArrayHasKey('valid', $result);
        $this->assertCount(1, $result);
    }

    public function testParseConfigArrayExtractsPhpConstantAsString(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'max' => PHP_INT_MAX,
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertArrayHasKey('max', $result);
        $this->assertSame('PHP_INT_MAX', $result['max']['value']);
        $this->assertFalse($result['max']['isEnvCall']);
    }

    public function testParseConfigArrayReturnsNullForComplexValues(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'connections' => ['sqlite', 'mysql'],
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertArrayHasKey('connections', $result);
        $this->assertNull($result['connections']['value']);
        $this->assertFalse($result['connections']['isEnvCall']);
    }

    public function testParseConfigArrayExtractsIntegerValues(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'port' => 3306,
    'timeout' => 30.5,
];
PHP);

        $result = (new ConfigFileParser(new AstParser()))->parseArray($file);

        $this->assertSame(3306, $result['port']['value']);
        $this->assertSame(30.5, $result['timeout']['value']);
    }

    /**
     * Without an injected parser both methods build a throwaway one, so the ParseFailure
     * they record dies with it and an unparseable config is indistinguishable from a config
     * that simply does not define the key. These tests pin the parameter that lets a caller
     * keep the evidence. They do not move the coverage number -- `$parser ?? new AstParser()`
     * is a single line, and phpunit.xml enables line coverage, not path coverage -- so do
     * not delete them as redundant with the null-parser tests above.
     */
    public function test_parse_config_array_records_the_failure_on_an_injected_parser(): void
    {
        $configFile = $this->tempDir.'/config/broken.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'driver' => 'single',\n");

        $parser = new AstParser();

        $this->assertSame([], (new ConfigFileParser($parser))->parseArray($configFile));

        $failures = $parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame($configFile, $failures[0]->path);
        $this->assertSame(ParseFailureCause::SyntaxError, $failures[0]->cause);
        $this->assertTrue($parser->hasFailure($configFile));
    }

    public function test_find_nested_array_key_line_records_the_failure_on_an_injected_parser(): void
    {
        $configFile = $this->tempDir.'/config/broken_logging.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'channels' => [\n        'single' => [\n");

        $parser = new AstParser();

        $this->assertNull(
            (new ConfigFileParser($parser))->findNestedArrayKeyLine($configFile, 'channels', 'single')
        );

        $this->assertTrue($parser->hasFailure($configFile));
        $this->assertSame(ParseFailureCause::SyntaxError, $parser->failures()[0]->cause);
    }

    /**
     * A path that was never readable never reaches a parser, but it is still a file the
     * caller was pointed at and got nothing back for, so it is still recorded.
     */
    public function test_parse_config_array_records_an_unreadable_path_on_an_injected_parser(): void
    {
        $missing = $this->tempDir.'/config/absent.php';
        $parser = new AstParser();

        $this->assertSame([], (new ConfigFileParser($parser))->parseArray($missing));

        $failures = $parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame(ParseFailureCause::Unreadable, $failures[0]->cause);
    }

    /**
     * A config that parses cleanly leaves the injected parser's log empty. Guards against
     * the parameter turning every call into a reported failure.
     */
    public function test_an_injected_parser_records_nothing_for_a_config_that_parses(): void
    {
        $configFile = $this->tempDir.'/config/fine.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'driver' => 'single',\n];\n");

        $parser = new AstParser();
        $config = (new ConfigFileParser($parser))->parseArray($configFile);

        $this->assertArrayHasKey('driver', $config);
        $this->assertSame([], $parser->failures());
    }

    /**
     * The class asks for a contract, not for AstParser. A double that is plainly not an
     * AstParser must still drive it, and must be the thing that gets asked -- otherwise the
     * parameter is decoration and a consumer passing its own parser would silently get a
     * throwaway's results and an empty failure log.
     */
    public function test_reads_through_whatever_recording_parser_it_was_given(): void
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, "<?php\n\nreturn [\n    'name' => 'Laravel',\n];\n");

        $parser = new FakeRecordingParser();

        $this->assertSame([], (new ConfigFileParser($parser))->parseArray($file));
        $this->assertSame([$file], $parser->parsedPaths);
    }

    public function test_find_nested_array_key_line_reads_through_the_injected_parser_too(): void
    {
        $file = $this->tempDir.'/config/logging.php';
        file_put_contents($file, "<?php\n\nreturn [\n    'channels' => [\n        'single' => [],\n    ],\n];\n");

        $parser = new FakeRecordingParser();

        $this->assertNull((new ConfigFileParser($parser))->findNestedArrayKeyLine($file, 'channels', 'single'));
        $this->assertSame([$file], $parser->parsedPaths);
    }

}
