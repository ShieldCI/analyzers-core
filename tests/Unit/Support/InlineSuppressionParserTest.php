<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\InlineSuppressionParser;

class InlineSuppressionParserTest extends TestCase
{
    private InlineSuppressionParser $parser;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->parser = new InlineSuppressionParser();
        $this->tempDir = sys_get_temp_dir().'/shieldci-suppression-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir.'/*');
        if ($files !== false) {
            array_map('unlink', $files);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function createTempFile(string $content): string
    {
        $path = $this->tempDir.'/test_'.uniqid().'.php';
        file_put_contents($path, $content);

        return $path;
    }

    // --- Bare @shieldci-ignore ---

    public function testBareIgnoreOnPreviousLineSuppressesAnyAnalyzer(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @shieldci-ignore
Route::post('/webhook', [WebhookController::class, 'handle']);
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'authentication-authorization'));
        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testBareIgnoreOnSameLineSuppressesAnyAnalyzer(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
$result = DB::select("SELECT * FROM users"); // @shieldci-ignore
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 2, 'sql-injection'));
        $this->assertTrue($this->parser->isLineSuppressed($file, 2, 'xss-detection'));
    }

    // --- Specific analyzer ID ---

    public function testSpecificIdOnPreviousLineSuppressesOnlyThatAnalyzer(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @shieldci-ignore sql-injection
$result = DB::select("SELECT * FROM users WHERE id = $id");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 3, 'xss-detection'));
    }

    public function testSpecificIdOnSameLineSuppressesOnlyThatAnalyzer(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
echo $userInput; // @shieldci-ignore xss-detection
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 2, 'xss-detection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 2, 'sql-injection'));
    }

    // --- Comma-separated IDs ---

    public function testCommaSeparatedIdsSuppressAllListed(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @shieldci-ignore sql-injection,xss-detection
echo DB::select("SELECT * FROM users WHERE name = '$name'");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'xss-detection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 3, 'authentication-authorization'));
    }

    // --- No suppression ---

    public function testLineWithoutSuppressionIsNotSuppressed(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// This is a normal comment
$result = DB::select("SELECT * FROM users");
PHP);

        $this->assertFalse($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testSuppressionOnUnrelatedLineDoesNotAffectOtherLines(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @shieldci-ignore sql-injection
$safe = DB::select("SELECT 1");

$unsafe = DB::select("SELECT * FROM users WHERE id = $id");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 5, 'sql-injection'));
    }

    // --- Comment styles ---

    public function testHashCommentStyleWorks(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
# @shieldci-ignore sql-injection
$result = DB::select("SELECT 1");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testBlockCommentStyleWorks(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
/* @shieldci-ignore sql-injection */
$result = DB::select("SELECT 1");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testDocblockCommentStyleWorks(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
/** @shieldci-ignore sql-injection */
$result = DB::select("SELECT 1");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testMultilineDocblockBareSuppressWorks(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
/**
 * @shieldci-ignore
 */
public function boot(): void {}
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 5, 'sql-injection'));
        $this->assertTrue($this->parser->isLineSuppressed($file, 5, 'xss-detection'));
    }

    public function testMultilineDocblockSpecificIdSuppressesOnlyThatAnalyzer(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
/**
 * @param string $name
 * @return User
 * @shieldci-ignore sql-injection
 */
public function getUser(string $name): User {}
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 7, 'sql-injection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 7, 'xss-detection'));
    }

    public function testMultilineDocblockWithExtraLinesWorks(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
/**
 * Some description here.
 * @shieldci-ignore xss-detection
 */
echo $userInput;
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 6, 'xss-detection'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 6, 'sql-injection'));
    }

    // --- Edge cases ---

    public function testNonexistentFileReturnsNotSuppressed(): void
    {
        $this->assertFalse($this->parser->isLineSuppressed('/nonexistent/path.php', 1, 'sql-injection'));
    }

    public function testLineZeroReturnsNotSuppressed(): void
    {
        $file = $this->createTempFile("<?php\n// @shieldci-ignore\n\$x = 1;");

        $this->assertFalse($this->parser->isLineSuppressed($file, 0, 'sql-injection'));
    }

    public function testNegativeLineReturnsNotSuppressed(): void
    {
        $file = $this->createTempFile("<?php\n// @shieldci-ignore\n\$x = 1;");

        $this->assertFalse($this->parser->isLineSuppressed($file, -1, 'sql-injection'));
    }

    public function testFirstLineOfFileCanBeSuppressed(): void
    {
        $file = $this->createTempFile('<?php // @shieldci-ignore');

        $this->assertTrue($this->parser->isLineSuppressed($file, 1, 'any-analyzer'));
    }

    public function testCaseInsensitiveMatching(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @SHIELDCI-IGNORE sql-injection
$result = DB::select("SELECT 1");
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'sql-injection'));
    }

    public function testFileReadsAreCached(): void
    {
        $file = $this->createTempFile(<<<'PHP'
<?php
// @shieldci-ignore
$a = 1;
$b = 2;
PHP);

        $this->assertTrue($this->parser->isLineSuppressed($file, 3, 'x'));
        $this->assertFalse($this->parser->isLineSuppressed($file, 4, 'x'));
    }

    public function testEmptyFileReturnsNotSuppressed(): void
    {
        $file = $this->createTempFile('');

        $this->assertFalse($this->parser->isLineSuppressed($file, 1, 'sql-injection'));
    }

    public function testLineBeyondFileLengthReturnsNotSuppressed(): void
    {
        $file = $this->createTempFile("<?php\n\$a = 1;\n\$b = 2;");

        $this->assertFalse($this->parser->isLineSuppressed($file, 10, 'sql-injection'));
    }

    public function testUnreadableFileReturnsNotSuppressed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod not supported on Windows');
        }

        $file = $this->createTempFile("<?php\n// @shieldci-ignore\n\$x = 1;");
        chmod($file, 0000);

        $this->assertFalse($this->parser->isLineSuppressed($file, 3, 'sql-injection'));

        chmod($file, 0644);
    }
}
