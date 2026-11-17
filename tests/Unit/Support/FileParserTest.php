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

    // --- extractNamespace / extractClassName / extractUseStatements read declarations, not text ---
    //
    // Each extractor used to run a regex over the raw file, so a word after "class", "namespace"
    // or "use" in a comment, a string or a closure was taken for a declaration.

    public function testExtractClassNameIgnoresWordsInDocblock(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace App;\n\n/**\n * This class handles payments.\n */\nfinal class PaymentService {}\n");

        $this->assertSame('PaymentService', FileParser::extractClassName($file));
        $this->assertSame('App\\PaymentService', FileParser::extractFullyQualifiedClassName($file));
    }

    public function testExtractNamespaceIgnoresLineComment(): void
    {
        $file = $this->writeFixture("<?php\n\n// The namespace Legacy; was dropped\nnamespace App\\Models;\n\nclass User {}\n");

        $this->assertSame('App\\Models', FileParser::extractNamespace($file));
    }

    public function testExtractUseStatementsIgnoresCommentsAndClosureUse(): void
    {
        $file = $this->writeFixture("<?php\n\nuse App\\Models\\User;\n\n// We use caching; it is fast\n\$f = function () use (\$x) { return 1; };\n\$g = fn (\$y) => \$y;\n");

        $this->assertSame(['App\\Models\\User'], FileParser::extractUseStatements($file));
    }

    public function testExtractorsIgnoreDeclarationsInDocblock(): void
    {
        $file = $this->writeFixture("<?php\n\n/**\n * Lives in namespace Wrong; and you should use Nothing; here.\n */\nnamespace App;\n\nuse App\\Real;\n\nclass Thing {}\n");

        $this->assertSame('App', FileParser::extractNamespace($file));
        $this->assertSame(['App\\Real'], FileParser::extractUseStatements($file));
        $this->assertSame('Thing', FileParser::extractClassName($file));
    }

    public function testExtractorsIgnoreHashComment(): void
    {
        $file = $this->writeFixture("<?php\n\n# use Legacy\\Thing; namespace Old; class Gone\nnamespace App;\n\nclass Real {}\n");

        $this->assertSame('App', FileParser::extractNamespace($file));
        $this->assertSame([], FileParser::extractUseStatements($file));
        $this->assertSame('Real', FileParser::extractClassName($file));
    }

    public function testExtractorsIgnoreStringAndHeredocContents(): void
    {
        $file = $this->writeFixture("<?php\n\n\$a = 'class Foo';\n\$b = \"use Bar;\";\n\$c = <<<TXT\nnamespace Heredoc;\nuse Heredoc\\Thing;\nclass Heredoc {}\nTXT;\n\nclass Real {}\n");

        $this->assertNull(FileParser::extractNamespace($file));
        $this->assertSame([], FileParser::extractUseStatements($file));
        $this->assertSame('Real', FileParser::extractClassName($file));
    }

    public function testExtractClassNameIgnoresClassConstant(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace App;\n\n\$ok = \$name === Foo::class and \$strict;\n\nclass Real {}\n");

        $this->assertSame('Real', FileParser::extractClassName($file));
    }

    public function testExtractClassNameIgnoresAnonymousClass(): void
    {
        $file = $this->writeFixture("<?php\n\n\$x = new class extends Base {};\n\nclass Real {}\n");

        $this->assertSame('Real', FileParser::extractClassName($file));
    }

    public function testExtractClassNameReturnsNullForOnlyAnonymousClassAndClassConstant(): void
    {
        $file = $this->writeFixture("<?php\n\n\$x = new class {};\n\$y = Foo::class;\n");

        $this->assertNull(FileParser::extractClassName($file));
    }

    public function testExtractorsReadBracedNamespace(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace App\\Http {\n    use Foo\\Bar;\n\n    class Kernel {}\n}\n");

        $this->assertSame('App\\Http', FileParser::extractNamespace($file));
        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($file));
        $this->assertSame('App\\Http\\Kernel', FileParser::extractFullyQualifiedClassName($file));
    }

    public function testExtractNamespaceSkipsGlobalBracedNamespace(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace {\n    class Kernel {}\n}\n");

        $this->assertNull(FileParser::extractNamespace($file));
        $this->assertSame('Kernel', FileParser::extractClassName($file));
    }

    // Controls: genuine declarations come back exactly as the old regexes returned them.

    public function testExtractNamespaceIgnoresRelativeNameInFileWithoutNamespace(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace\\helper();\n\nclass Real {}\n");

        $this->assertNull(FileParser::extractNamespace($file));
    }

    public function testExtractUseStatementsKeepsAliasesFunctionsAndConstants(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace App;\n\nuse Foo\\Bar\\Baz as ShortName;\nuse function Illuminate\\Support\\tap;\nuse const App\\VERSION;\n\nclass Real {}\n");

        $this->assertSame(
            ['Foo\\Bar\\Baz as ShortName', 'function Illuminate\\Support\\tap', 'const App\\VERSION'],
            FileParser::extractUseStatements($file)
        );
    }

    public function testExtractUseStatementsKeepsGroupUseBodyVerbatim(): void
    {
        $file = $this->writeFixture("<?php\n\nuse App\\{Models\\User, Models\\Post as P};\nuse App\\{\n    Http\\Kernel,\n    Console\\Kernel as ConsoleKernel,\n};\n");

        $this->assertSame(
            ['App\\{Models\\User, Models\\Post as P}', "App\\{\n    Http\\Kernel,\n    Console\\Kernel as ConsoleKernel,\n}"],
            FileParser::extractUseStatements($file)
        );
    }

    public function testExtractUseStatementsKeepsTraitUseInsideClass(): void
    {
        $file = $this->writeFixture("<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n\nabstract class User\n{\n    use HasFactory, Notifiable;\n}\n");

        $this->assertSame(
            ['Illuminate\\Database\\Eloquent\\Factories\\HasFactory', 'HasFactory, Notifiable'],
            FileParser::extractUseStatements($file)
        );
        $this->assertSame('User', FileParser::extractClassName($file));
    }

    public function testExtractUseStatementsReadsStatementOnOpenTagLine(): void
    {
        $file = $this->writeFixture("<?php use Foo\\Bar;\n\nclass Real {}\n");

        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($file));
    }

    public function testExtractUseStatementsDropsCommentsInsideStatement(): void
    {
        $file = $this->writeFixture("<?php\n\nuse Foo\\Bar /* legacy; keep */;\nuse App\\{\n    User, // primary\n    Post,\n};\n");

        $this->assertSame(
            ['Foo\\Bar', "App\\{\n    User,  \n    Post,\n}"],
            FileParser::extractUseStatements($file)
        );
    }

    public function testExtractUseStatementsIgnoresUnterminatedStatement(): void
    {
        $file = $this->writeFixture("<?php\n\nuse Foo\\Bar");

        $this->assertSame([], FileParser::extractUseStatements($file));
    }

    // Only a `use` that starts a statement is an import or trait use. Keywords are
    // case-insensitive and also lex as T_USE when used as a name.

    public function testExtractUseStatementsIgnoresUppercaseClassConstantNamedUse(): void
    {
        $this->assertSame([], FileParser::extractUseStatements($this->writeFixture("<?php\nclass K { const USE = 'x'; }\n")));
    }

    public function testExtractUseStatementsIgnoresLowercaseClassConstantNamedUse(): void
    {
        $this->assertSame([], FileParser::extractUseStatements($this->writeFixture("<?php\nclass K { const use = 'x'; }\n")));
    }

    public function testExtractUseStatementsIgnoresStaticFetchOfConstantNamedUse(): void
    {
        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($this->writeFixture("<?php\nuse Foo\\Bar;\n\$a = [K::USE, K::OTHER];\n")));
    }

    public function testExtractUseStatementsIgnoresNamedArgumentUse(): void
    {
        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($this->writeFixture("<?php\nuse Foo\\Bar;\n\$r = foo(use: true);\n")));
    }

    public function testExtractUseStatementsIgnoresEnumCaseNamedUse(): void
    {
        $this->assertSame([], FileParser::extractUseStatements($this->writeFixture("<?php\nenum E { case USE; case OTHER; }\n")));
    }

    public function testExtractUseStatementsReadsStatementAfterInlineHtml(): void
    {
        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($this->writeFixture("<?php\n?>\n<p>x</p>\n<?php use Foo\\Bar;\n")));
    }

    public function testExtractUseStatementsIgnoresUseAsLastToken(): void
    {
        $this->assertSame([], FileParser::extractUseStatements($this->writeFixture("<?php\nclass X {}\nuse")));
    }

    public function testExtractUseStatementsReplacesCommentWithSpace(): void
    {
        $this->assertSame(['Foo\\Bar as Baz'], FileParser::extractUseStatements($this->writeFixture("<?php\nuse Foo\\Bar/**/as Baz;\n")));
    }

    public function testExtractUseStatementsStopsAtTraitConflictBlock(): void
    {
        $this->assertSame(['A, B'], FileParser::extractUseStatements($this->writeFixture("<?php\nclass X { use A, B { A::foo insteadof B; B::foo as bar; } }\n")));
    }

    public function testExtractUseStatementsStopsAtEmptyTraitBlock(): void
    {
        $this->assertSame(['A'], FileParser::extractUseStatements($this->writeFixture("<?php\nclass X { use A {} public function y() { return 1; } }\n")));
    }

    public function testExtractUseStatementsKeepsGroupUseAfterFullyQualifiedPrefix(): void
    {
        $this->assertSame(['\\App\\ {C, D}'], FileParser::extractUseStatements($this->writeFixture("<?php\nuse \\App\\ {C, D};\n")));
    }

    // A namespace segment may be a reserved word, which lexes as that keyword's token.

    public function testExtractNamespaceReadsKeywordNamespaces(): void
    {
        foreach (['Match', 'Fn', 'Readonly', 'List', 'Class', 'Function', 'Use'] as $name) {
            $file = $this->writeFixture("<?php\nnamespace {$name};\nclass X {}\n");

            $this->assertSame($name, FileParser::extractNamespace($file), $name);
            $this->assertSame("{$name}\\X", FileParser::extractFullyQualifiedClassName($file), $name);
        }
    }

    public function testExtractNamespaceReadsBracedKeywordNamespace(): void
    {
        $this->assertSame('Match', FileParser::extractNamespace($this->writeFixture("<?php\nnamespace Match {\n    class X {}\n}\n")));
    }

    public function testExtractUseStatementsIgnoresNamespaceNamedUse(): void
    {
        $this->assertSame(['Foo\\Bar'], FileParser::extractUseStatements($this->writeFixture("<?php\nnamespace Use;\nuse Foo\\Bar;\n")));
    }

    public function testExtractorsSkipFilesThatNeverMentionTheKeyword(): void
    {
        $file = $this->writeFixture("<?php\n\$x = 42;\n");

        $this->assertNull(FileParser::extractNamespace($file));
        $this->assertNull(FileParser::extractClassName($file));
        $this->assertNull(FileParser::extractFullyQualifiedClassName($file));
        $this->assertSame([], FileParser::extractUseStatements($file));
    }

    // A close tag ends a statement, like `;`.

    public function testExtractUseStatementsEndsStatementAtCloseTag(): void
    {
        $file = $this->writeFixture("<?php use Foo\\Bar ?>\n<p>hi</p>\n<?php use Baz\\Qux; ?>\n");

        $this->assertSame(['Foo\\Bar', 'Baz\\Qux'], FileParser::extractUseStatements($file));
    }

    public function testExtractUseStatementsReadsManyCloseTagTerminatedStatements(): void
    {
        $file = $this->writeFixture(str_repeat("<?php use A\\B ?>x", 2000) . "<?php ;\n");

        $this->assertSame(array_fill(0, 2000, 'A\\B'), FileParser::extractUseStatements($file));
    }

    /**
     * The walk resumes after each statement it consumed instead of rescanning from the next
     * `use`, which kept it linear: on this invalid file a rescan would yield 3000 entries
     * (and take seconds), one per `use`, each running to the final `;`.
     */
    public function testExtractUseStatementsDoesNotRescanConsumedTokens(): void
    {
        $file = $this->writeFixture("<?php\n" . str_repeat("use A\\B }\n", 3000) . ";\n");

        $uses = FileParser::extractUseStatements($file);

        $this->assertCount(1, $uses);
        $this->assertStringStartsWith("A\\B }\nuse A\\B }", $uses[0]);
    }

    public function testExtractNamespaceEndsAtCloseTag(): void
    {
        $file = $this->writeFixture("<?php namespace App ?>\n<?php class X {}\n");

        $this->assertSame('App', FileParser::extractNamespace($file));
        $this->assertSame('App\\X', FileParser::extractFullyQualifiedClassName($file));
    }

    // The class is qualified by the namespace that encloses it, not the file's first one.

    public function testExtractFullyQualifiedClassNameUsesGlobalBlockEnclosingClass(): void
    {
        $file = $this->writeFixture("<?php\nnamespace {\n    class G {}\n}\nnamespace App {\n    class X {}\n}\n");

        $this->assertSame('G', FileParser::extractFullyQualifiedClassName($file));
        $this->assertSame('App', FileParser::extractNamespace($file));
    }

    public function testExtractFullyQualifiedClassNameUsesNamespaceEnclosingClass(): void
    {
        $file = $this->writeFixture("<?php\nnamespace A;\ninterface I {}\nnamespace B;\nclass C {}\n");

        $this->assertSame('B\\C', FileParser::extractFullyQualifiedClassName($file));
        $this->assertSame('A', FileParser::extractNamespace($file));
    }

    // `namespace` also lexes as T_NAMESPACE when used as a name; only a declaration counts.

    public function testExtractFullyQualifiedClassNameSkipsNamespaceUsedAsName(): void
    {
        foreach ([
            "<?php\nnamespace App;\n\ninterface HasNs\n{\n    const NAMESPACE = 'x';\n}\n\nclass Foo implements HasNs {}\n",
            "<?php\nnamespace App;\n\nfunction prefix(): string { return Config::NAMESPACE; }\n\nclass Foo {}\n",
            "<?php\nnamespace App;\n\ntrait T { public function namespace(): string { return ''; } }\n\nclass Foo { use T; }\n",
            "<?php\nnamespace App;\n\nfoo(namespace: 1);\n\nclass Foo {}\n",
        ] as $code) {
            $this->assertSame('App\\Foo', FileParser::extractFullyQualifiedClassName($this->writeFixture($code)), $code);
        }
    }

    private function writeFixture(string $code): string
    {
        $file = $this->testDir . '/fixture.php';
        file_put_contents($file, $code);

        return $file;
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

    // --- stripAllComments ---

    public function testStripAllCommentsRemovesSingleLineDoubleSlash(): void
    {
        $code = "<?php\n\$a = 1; // this is a comment\n\$b = 2;";
        $result = FileParser::stripAllComments($code);

        $this->assertStringNotContainsString('this is a comment', $result);
        $this->assertStringContainsString('$a = 1;', $result);
        $this->assertStringContainsString('$b = 2;', $result);
    }

    public function testStripAllCommentsRemovesHashComment(): void
    {
        $code = "<?php\n# hash comment\n\$a = 1;";
        $result = FileParser::stripAllComments($code);

        $this->assertStringNotContainsString('hash comment', $result);
        $this->assertStringContainsString('$a = 1;', $result);
    }

    public function testStripAllCommentsRemovesMultilineComment(): void
    {
        $code = "<?php\n/* multi\n   line\n   comment */\n\$a = 1;";
        $result = FileParser::stripAllComments($code);

        $this->assertStringNotContainsString('multi', $result);
        $this->assertStringContainsString('$a = 1;', $result);
    }

    public function testStripAllCommentsRemovesDocblock(): void
    {
        $code = "<?php\n/** @param string \$x */\nfunction foo(\$x) {}";
        $result = FileParser::stripAllComments($code);

        $this->assertStringNotContainsString('@param', $result);
        $this->assertStringContainsString('function foo', $result);
    }

    public function testStripAllCommentsPreservesUrlsInsideStrings(): void
    {
        $code = '<?php $url = "https://example.com/path";';
        $result = FileParser::stripAllComments($code);

        $this->assertStringContainsString('https://example.com/path', $result);
    }

    public function testStripAllCommentsPreservesLineCount(): void
    {
        $code = "<?php\n// line 2 comment\n\$a = 1;\n// line 4 comment\n\$b = 2;";
        $result = FileParser::stripAllComments($code);

        $this->assertSame(substr_count($code, "\n"), substr_count($result, "\n"));
    }

    public function testStripAllCommentsWorksWithoutPhpTag(): void
    {
        $code = "\$a = 1; // comment\n\$b = 2;";
        $result = FileParser::stripAllComments($code);

        $this->assertStringNotContainsString('comment', $result);
        $this->assertStringContainsString('$a = 1;', $result);
        $this->assertStringNotContainsString('<?php', $result);
    }

    public function testStripAllCommentsPreservesCodeWithNoComments(): void
    {
        $code = "<?php\n\$a = 1;\n\$b = 2;";
        $result = FileParser::stripAllComments($code);

        $this->assertStringContainsString('$a = 1;', $result);
        $this->assertStringContainsString('$b = 2;', $result);
    }

    public function testStripAllCommentsStillPreservesStringContent(): void
    {
        // Regression lock-in: stripAllComments() MUST preserve string literal content
        // (consumers like SessionTimeoutAnalyzer parse config array keys from strings).
        $code = "<?php Log::info('User::all()');";
        $result = FileParser::stripAllComments($code);

        $this->assertStringContainsString('User::all()', $result);
    }

    // --- stripCommentsAndStrings ---

    public function testStripCommentsAndStringsRemovesPatternsFromSingleQuotedStrings(): void
    {
        $code = "<?php Log::info('Avoid using User::all() in production');";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('Avoid using', $result);
        $this->assertStringContainsString('Log::info', $result);
    }

    public function testStripCommentsAndStringsRemovesPatternsFromDoubleQuotedStrings(): void
    {
        $code = '<?php throw new \Exception("Do not use ->get()->count()");';
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('->get()->count()', $result);
        $this->assertStringNotContainsString('Do not use', $result);
        $this->assertStringContainsString('throw new', $result);
    }

    public function testStripCommentsAndStringsRemovesPatternsFromHeredocs(): void
    {
        $code = "<?php\n\$sql = <<<SQL\nSELECT * FROM users WHERE User::all()\nSQL;\n";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('SELECT *', $result);
        $this->assertStringContainsString('$sql', $result);
    }

    public function testStripCommentsAndStringsRemovesPatternsFromNowdocs(): void
    {
        $code = "<?php\n\$doc = <<<'EOT'\nUser::all() inside nowdoc\nEOT;\n";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('inside nowdoc', $result);
        $this->assertStringContainsString('$doc', $result);
    }

    public function testStripCommentsAndStringsRemovesPatternsFromBacktickExec(): void
    {
        $code = '<?php $output = `echo User::all()`;';
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('echo', $result);
        $this->assertStringContainsString('$output', $result);
    }

    public function testStripCommentsAndStringsPreservesRealCodeReferences(): void
    {
        $code = "<?php User::all();";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringContainsString('User::all()', $result);
    }

    public function testStripCommentsAndStringsPreservesInterpolatedVariables(): void
    {
        // Interpolated variables like $x->foo are real code references and must survive.
        // The surrounding literal text ("hello ") should be stripped.
        $code = '<?php echo "hello $x->foo world";';
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('hello', $result);
        $this->assertStringNotContainsString('world', $result);
        $this->assertStringContainsString('$x', $result);
        $this->assertStringContainsString('foo', $result);
    }

    public function testStripCommentsAndStringsPreservesLineNumbering(): void
    {
        $code = "<?php\n\$sql = <<<SQL\nline1\nline2\nline3\nSQL;\n\$x = 1;\n";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertSame(substr_count($code, "\n"), substr_count($result, "\n"));
    }

    public function testStripCommentsAndStringsHandlesMultiLineSingleQuotedString(): void
    {
        $code = "<?php \$x = 'line1\nline2\nUser::all()';";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('line1', $result);
        $this->assertSame(substr_count($code, "\n"), substr_count($result, "\n"));
    }

    public function testStripCommentsAndStringsHandlesEmptyStrings(): void
    {
        $code = "<?php \$a = ''; \$b = \"\";";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringContainsString('$a', $result);
        $this->assertStringContainsString('$b', $result);
    }

    public function testStripCommentsAndStringsStripsInlineHtml(): void
    {
        $code = "<p>Avoid User::all() in loops</p>\n<?php \$x = 1; ?>";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
        $this->assertStringNotContainsString('Avoid', $result);
        $this->assertStringContainsString('$x = 1;', $result);
    }

    public function testStripCommentsAndStringsStripsCommentsAlongsideStrings(): void
    {
        $code = "<?php\n// User::all() in comment\n\$x = 'User::all() in string';\nUser::all();";
        $result = FileParser::stripCommentsAndStrings($code);

        // Only the real call should survive — one occurrence total.
        $this->assertSame(1, substr_count($result, 'User::all()'));
    }

    public function testStripCommentsAndStringsWorksWithoutPhpTag(): void
    {
        $code = "\$x = 'User::all()'; User::all();";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertSame(1, substr_count($result, 'User::all()'));
        $this->assertStringNotContainsString('<?php', $result);
    }

    public function testStripCommentsAndStringsWorksWithShortEchoTag(): void
    {
        $code = "<?= 'User::all()' ?>";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertStringNotContainsString('User::all()', $result);
    }

    public function testStripCommentsAndStringsHandlesMalformedInput(): void
    {
        // Unterminated string literal — token_get_all is called with @ suppression,
        // so this should return a string without throwing.
        $code = "<?php \$x = 'unterminated";
        $result = FileParser::stripCommentsAndStrings($code);

        $this->assertIsString($result);
    }
}
