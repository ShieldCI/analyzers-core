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
        $this->assertFalse($result['key']['isLiteral']);
        $this->assertFalse($result['key']['envHasDefault']);
        $this->assertNull($result['key']['envDefault']);
        $this->assertFalse($result['key']['envDefaultIsLiteral']);
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

    /**
     * A constant's value is not in the file, so it is not read. Its name is not its value:
     * 'cipher' => APP_CIPHER used to come back as the string 'APP_CIPHER'.
     */
    public function test_a_constant_is_unread_rather_than_its_own_name(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'max' => PHP_INT_MAX,
    'cipher' => APP_CIPHER,
    'same_site' => Cookie::SAMESITE_LAX,
PHP);

        foreach (['max', 'cipher', 'same_site'] as $key) {
            $this->assertNull($result[$key]['value'], $key);
            $this->assertFalse($result[$key]['isLiteral'], $key);
            $this->assertFalse($result[$key]['isEnvCall'], $key);
        }
    }

    public function test_literal_values_are_flagged_as_literal_including_null(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'name' => 'App',
    'port' => 3306,
    'ratio' => 0.5,
    'debug' => FALSE,
    'secret' => null,
PHP);

        foreach (['name', 'port', 'ratio', 'debug', 'secret'] as $key) {
            $this->assertTrue($result[$key]['isLiteral'], $key);
        }

        $this->assertFalse($result['debug']['value']);
        $this->assertNull($result['secret']['value']);
    }

    public function test_unreadable_expressions_are_not_literal(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'dir' => __DIR__,
    'interpolated' => "{$prefix}-app",
    'call' => storage_path('app'),
    'mixed_concat' => 'base64:' . APP_KEY_CONST,
    'negated_const' => -SOME_CONST,
    'negated_string' => -'1',
PHP);

        foreach (['dir', 'interpolated', 'call', 'mixed_concat', 'negated_const', 'negated_string'] as $key) {
            $this->assertNull($result[$key]['value'], $key);
            $this->assertFalse($result[$key]['isLiteral'], $key);
        }
    }

    public function test_concatenated_literal_strings_are_folded(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'key' => 'base64:' . 'c2Vj' . 'cmV0',
PHP);

        $this->assertSame('base64:c2VjcmV0', $result['key']['value']);
        $this->assertTrue($result['key']['isLiteral']);
    }

    public function test_signed_numbers_are_folded(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'minus' => -1,
    'plus' => +1.5,
PHP);

        $this->assertSame(-1, $result['minus']['value']);
        $this->assertTrue($result['minus']['isLiteral']);
        $this->assertSame(1.5, $result['plus']['value']);
        $this->assertTrue($result['plus']['isLiteral']);
    }

    public function test_scalar_casts_of_literals_are_applied(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'bool' => (bool) true,
    'int' => (int) '30',
    'float' => (float) '1.5',
    'string' => (string) false,
    'nested' => (string) (int) '5',
    'cast_null' => (bool) null,
PHP);

        $this->assertTrue($result['bool']['value']);
        $this->assertSame(30, $result['int']['value']);
        $this->assertSame(1.5, $result['float']['value']);
        $this->assertSame('', $result['string']['value']);
        $this->assertSame('5', $result['nested']['value']);
        $this->assertFalse($result['cast_null']['value']);

        foreach (['bool', 'int', 'float', 'string', 'nested', 'cast_null'] as $key) {
            $this->assertTrue($result[$key]['isLiteral'], $key);
        }
    }

    /**
     * (array) and (object) do not produce a scalar, a cast cannot make an unread value read,
     * and a float that has no int value would warn on PHP 8.5 rather than cast.
     */
    public function test_casts_that_give_no_scalar_literal_are_unread(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'array' => (array) 'x',
    'object' => (object) 'x',
    'const' => (bool) SOME_CONST,
    'overflow' => (int) 1e1000,
    'too_big' => (int) 1e20,
PHP);

        foreach (['array', 'object', 'const', 'overflow', 'too_big'] as $key) {
            $this->assertNull($result[$key]['value'], $key);
            $this->assertFalse($result[$key]['isLiteral'], $key);
        }
    }

    /**
     * The config/app.php and config/session.php that ship with Laravel 11+ wrap env() in a
     * cast. The cast used to hide the call, so the entry read as an unread non-env value.
     */
    public function test_a_cast_around_env_is_still_an_env_call(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'debug' => (bool) env('APP_DEBUG', false),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'truthy' => (bool) env('X', 'yes'),
    'stringified' => (string) env('SESSION_SAME_SITE', 'lax'),
PHP);

        $this->assertTrue($result['debug']['isEnvCall']);
        $this->assertTrue($result['debug']['envHasDefault']);
        $this->assertFalse($result['debug']['envDefault']);
        $this->assertSame(120, $result['lifetime']['envDefault']);
        $this->assertTrue($result['truthy']['envDefault']);
        $this->assertSame('lax', $result['stringified']['envDefault']);

        foreach (['debug', 'lifetime', 'truthy', 'stringified'] as $key) {
            $this->assertNull($result[$key]['value'], $key);
            $this->assertFalse($result[$key]['isLiteral'], $key);
            $this->assertTrue($result[$key]['envDefaultIsLiteral'], $key);
        }
    }

    public function test_a_cast_env_call_without_a_default_has_no_default(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'debug' => (bool) env('APP_DEBUG'),
PHP);

        $this->assertTrue($result['debug']['isEnvCall']);
        $this->assertFalse($result['debug']['envHasDefault']);
        $this->assertNull($result['debug']['envDefault']);
        $this->assertFalse($result['debug']['envDefaultIsLiteral']);
    }

    /**
     * The default is env()'s `default` parameter, not whatever sits second: with named
     * arguments the second argument can be the key.
     */
    public function test_a_named_env_default_is_read_by_name_not_position(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'reordered' => (bool) env(default: false, key: 'APP_DEBUG'),
    'named' => env('SESSION_SAME_SITE', default: 'lax'),
    'key_only' => env(key: 'APP_DEBUG'),
PHP);

        $this->assertTrue($result['reordered']['envHasDefault']);
        $this->assertFalse($result['reordered']['envDefault']);
        $this->assertTrue($result['reordered']['envDefaultIsLiteral']);

        $this->assertSame('lax', $result['named']['envDefault']);

        $this->assertTrue($result['key_only']['isEnvCall']);
        $this->assertFalse($result['key_only']['envHasDefault']);
    }

    /**
     * PHP function names are case-insensitive, so Env() and ENV() are env() calls.
     */
    public function test_env_is_matched_case_insensitively(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'title' => Env('APP_DEBUG', true),
    'upper' => (bool) ENV('APP_DEBUG', false),
    'qualified' => \env('APP_DEBUG', true),
PHP);

        foreach (['title', 'upper', 'qualified'] as $key) {
            $this->assertTrue($result[$key]['isEnvCall'], $key);
            $this->assertTrue($result[$key]['envHasDefault'], $key);
        }

        $this->assertTrue($result['title']['envDefault']);
        $this->assertFalse($result['upper']['envDefault']);
    }

    /**
     * env(...) makes a Closure; it does not call env().
     */
    public function test_a_first_class_callable_env_is_not_an_env_call(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'resolver' => env(...),
PHP);

        $this->assertFalse($result['resolver']['isEnvCall']);
        $this->assertFalse($result['resolver']['envHasDefault']);
        $this->assertFalse($result['resolver']['isLiteral']);
        $this->assertNull($result['resolver']['value']);
    }

    /**
     * envDefault null is two readings -- a literal null default, or a default that could not
     * be read -- and envDefaultIsLiteral is what separates them.
     */
    public function test_env_default_literalness_separates_null_from_unread(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'unread' => env('SESSION_SAME_SITE', Cookie::SAMESITE_STRICT),
    'null' => env('SESSION_SAME_SITE', null),
    'array_cast' => (array) env('X', 'a'),
PHP);

        $this->assertTrue($result['unread']['envHasDefault']);
        $this->assertNull($result['unread']['envDefault']);
        $this->assertFalse($result['unread']['envDefaultIsLiteral']);

        $this->assertTrue($result['null']['envHasDefault']);
        $this->assertNull($result['null']['envDefault']);
        $this->assertTrue($result['null']['envDefaultIsLiteral']);

        $this->assertTrue($result['array_cast']['isEnvCall']);
        $this->assertNull($result['array_cast']['envDefault']);
        $this->assertFalse($result['array_cast']['envDefaultIsLiteral']);
    }

    public function test_entries_that_are_not_env_calls_have_no_env_default(): void
    {
        $result = $this->parseBody(<<<'PHP'
    'name' => 'App',
    'cast' => (bool) true,
PHP);

        foreach (['name', 'cast'] as $key) {
            $this->assertFalse($result[$key]['isEnvCall'], $key);
            $this->assertFalse($result[$key]['envHasDefault'], $key);
            $this->assertFalse($result[$key]['envDefaultIsLiteral'], $key);
        }
    }

    /**
     * @return array<string, array{value: mixed, line: int, isEnvCall: bool, envDefault: mixed, envHasDefault: bool, isLiteral: bool, envDefaultIsLiteral: bool}>
     */
    private function parseBody(string $items): array
    {
        $file = $this->tempDir.'/config/app.php';
        file_put_contents($file, "<?php\nreturn [\n".$items."\n];\n");

        return (new ConfigFileParser(new AstParser()))->parseArray($file);
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
        $this->assertFalse($result['connections']['isLiteral']);
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
