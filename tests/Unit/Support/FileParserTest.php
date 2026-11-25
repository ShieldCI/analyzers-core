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

    // --- A label is a statement boundary (`foo: use A\B;`) ---

    public function testExtractUseStatementsReadsImportAfterLabel(): void
    {
        $this->assertSame(['A\\B'], FileParser::extractUseStatements($this->writeFixture("<?php\nfoo:\nuse A\\B;\n")));
    }

    public function testExtractUseStatementsReadsImportAfterChainedLabels(): void
    {
        $this->assertSame(['A\\B'], FileParser::extractUseStatements($this->writeFixture("<?php\na: /* x */ b:\nuse A\\B;\n")));
    }

    /**
     * Only a colon ending a label is a boundary: one after a named argument, a ternary's
     * middle operand or a `case` value is not, since that word does not itself start a
     * statement; nor is a return type's or an alternative-syntax colon, which follows `)`.
     * These inputs are invalid PHP; they pin the rule, not real code.
     */
    public function testExtractUseStatementsIgnoresColonThatDoesNotEndLabel(): void
    {
        foreach ([
            "<?php\nfoo(bar: use A\\B);\n",
            "<?php\n\$x = \$a ? B : use A\\B;\n",
            "<?php\nswitch (\$x) { case B: use A\\B; }\n",
            "<?php\nfunction f(): use A\\B;\n",
            "<?php\nif (\$x): use A\\B; endif;\n",
        ] as $code) {
            $this->assertSame([], FileParser::extractUseStatementsFromSource($code), $code);
        }
    }

    // --- A trait conflict block is skipped whole ---

    public function testExtractUseStatementsSkipsTraitConflictBlockWithMethodNamedUse(): void
    {
        $this->assertSame(['T'], FileParser::extractUseStatements($this->writeFixture("<?php\nclass X { use T { use as protected foo; } }\n")));
    }

    public function testExtractUseStatementsResumesAfterTraitConflictBlock(): void
    {
        $this->assertSame(
            ['T', 'U'],
            FileParser::extractUseStatementsFromSource("<?php\nclass X { use T { T::use as protected foo; use as bar; } use U; }\n")
        );
    }

    /**
     * Regression (review of #152): an import missing its `;` runs into the class header. At
     * namespace level that `{` is no conflict block, so the class body is still read.
     */
    public function testExtractUseStatementsReadsClassBodyAfterImportMissingSemicolon(): void
    {
        $code = "<?php\nnamespace App;\nuse Foo\\Bar\nclass X { use HasFactory; }\n";

        $this->assertSame(["Foo\\Bar\nclass X", 'HasFactory'], FileParser::extractUseStatements($this->writeFixture($code)));
        $this->assertSame(["Foo\\Bar\nclass X", 'HasFactory'], FileParser::extractUseStatementsFromSource($code));
        // The swallowed header is no import, and the trait use is not one either.
        $this->assertSame([], FileParser::extractImportsFromSource($code));
    }

    public function testExtractUseStatementsSkipsConflictBlockInBracedNamespace(): void
    {
        $this->assertSame(
            ['A\\B', 'T', 'C\\D'],
            FileParser::extractUseStatementsFromSource("<?php\nnamespace N {\n    use A\\B;\n    class X { use T { use as protected foo; } }\n    use C\\D;\n}\n")
        );
    }

    /**
     * Regressions (review of #152): a `{` that is no conflict block, or a conflict block that
     * never closes, must not hide the statements after it. Each expected value is master's.
     */
    public function testExtractUseStatementsReadsPastUnclosedConflictBlock(): void
    {
        $code = "<?php\nclass V {\n    use CodeGenerator;\n    use TestGenerator {\n        handle as protected handleFromTrait;\n\n    use UsesGeneratorOverrides;\n\n    protected function configure(): void\n    {\n        parent::configure();\n    }\n}\n";

        $this->assertSame(['CodeGenerator', 'TestGenerator', 'UsesGeneratorOverrides'], FileParser::extractUseStatementsFromSource($code));
    }

    public function testExtractUseStatementsReadsPastTraitUseMissingSemicolon(): void
    {
        $code = "<?php\nclass X {\n use A\n public function f() { return new class { use B; }; }\n}\n";

        $this->assertSame(["A\n public function f()", 'B'], FileParser::extractUseStatementsFromSource($code));
    }

    public function testExtractUseStatementsReadsPastConflictBlockRunningIntoMethods(): void
    {
        $code = "<?php\nclass X {\n use A { a as b;\n public function f() { return new class { use B; }; }\n}\n";

        $this->assertSame(['A', 'B'], FileParser::extractUseStatementsFromSource($code));
    }

    public function testExtractUseStatementsStopsAtUnclosedTraitConflictBlock(): void
    {
        $this->assertSame(['T'], FileParser::extractUseStatementsFromSource("<?php\nclass X { use T { use as foo;\n"));
    }

    // --- Source-string variants ---

    public function testFromSourceVariantsReadTaggedSource(): void
    {
        $code = "<?php\nnamespace App\\Models;\n\nuse Foo\\Bar;\n\nfinal class User { use HasFactory; }\n";

        $this->assertSame('App\\Models', FileParser::extractNamespaceFromSource($code));
        $this->assertSame('User', FileParser::extractClassNameFromSource($code));
        $this->assertSame('App\\Models\\User', FileParser::extractFullyQualifiedClassNameFromSource($code));
        $this->assertSame(['Foo\\Bar', 'HasFactory'], FileParser::extractUseStatementsFromSource($code));
        $this->assertSame(['Bar' => 'Foo\\Bar'], FileParser::extractImportsFromSource($code));
        $this->assertSame([['name' => 'App\\Models\\User', 'kind' => 'class']], FileParser::extractClassLikesFromSource($code));
    }

    public function testFromSourceVariantsReadTaglessFragment(): void
    {
        $code = "namespace App;\nuse Foo\\Bar;\nclass X { use T; }\n";

        $this->assertSame('App', FileParser::extractNamespaceFromSource($code));
        $this->assertSame('X', FileParser::extractClassNameFromSource($code));
        $this->assertSame('App\\X', FileParser::extractFullyQualifiedClassNameFromSource($code));
        $this->assertSame(['Foo\\Bar', 'T'], FileParser::extractUseStatementsFromSource($code));
        $this->assertSame(['Bar' => 'Foo\\Bar'], FileParser::extractImportsFromSource($code));
        $this->assertSame([['name' => 'App\\X', 'kind' => 'class']], FileParser::extractClassLikesFromSource($code));
    }

    /**
     * stripAllComments() decides by `<?php` appearing anywhere, so this fragment would be read
     * as inline HTML. The extractors look for a real open-tag token instead.
     */
    public function testFromSourceReadsTaglessFragmentMentioningOpenTagInString(): void
    {
        $code = "\$tag = '<?php';\nclass X {}\n";

        $this->assertSame('X', FileParser::extractClassNameFromSource($code));
        $this->assertSame([['name' => 'X', 'kind' => 'class']], FileParser::extractClassLikesFromSource($code));
    }

    public function testFromSourceReadsTemplateWithOpenTagAsIs(): void
    {
        $code = "<p>class Html</p>\n<?php class X {} ?>\n<p>use Nope;</p>\n";

        $this->assertSame('X', FileParser::extractClassNameFromSource($code));
        $this->assertSame([], FileParser::extractUseStatementsFromSource($code));
    }

    public function testFromSourceVariantsReturnNothingWithoutKeyword(): void
    {
        $this->assertNull(FileParser::extractNamespaceFromSource('$a = 1;'));
        $this->assertNull(FileParser::extractClassNameFromSource('$a = 1;'));
        $this->assertNull(FileParser::extractFullyQualifiedClassNameFromSource('$a = 1;'));
        $this->assertSame([], FileParser::extractUseStatementsFromSource('$a = 1;'));
        $this->assertSame([], FileParser::extractImportsFromSource('$a = 1;'));
        $this->assertSame([], FileParser::extractClassLikesFromSource('$a = 1;'));
        $this->assertSame([], FileParser::extractClassLikesFromSource(''));
    }

    /**
     * The file-path extractors tokenize the file as it is: a file with no open tag is all
     * inline HTML and declares nothing, exactly as before the source variants existed.
     */
    public function testFilePathVariantsDoNotTreatTaglessFileAsFragment(): void
    {
        $file = $this->writeFixture("namespace App;\nuse Foo\\Bar;\nclass X {}\n");

        $this->assertNull(FileParser::extractNamespace($file));
        $this->assertNull(FileParser::extractClassName($file));
        $this->assertNull(FileParser::extractFullyQualifiedClassName($file));
        $this->assertSame([], FileParser::extractUseStatements($file));
        $this->assertSame([], FileParser::extractImports($file));
        $this->assertSame([], FileParser::extractClassLikes($file));
    }

    public function testNewFilePathVariantsReturnEmptyForNonExistentFile(): void
    {
        $this->assertSame([], FileParser::extractImports('/non/existent/file.php'));
        $this->assertSame([], FileParser::extractClassLikes('/non/existent/file.php'));
    }

    // --- extractImports(): namespace-level class imports only ---

    public function testExtractImportsExcludesTraitUse(): void
    {
        $file = $this->writeFixture("<?php\nnamespace App;\nuse Foo\\Bar;\nclass X { use HasFactory; }\n");

        $this->assertSame(['Bar' => 'Foo\\Bar'], FileParser::extractImports($file));
        // extractUseStatements() still reports the trait use alongside the import.
        $this->assertSame(['Foo\\Bar', 'HasFactory'], FileParser::extractUseStatements($file));
    }

    public function testExtractImportsExpandsAliasesListsAndGroups(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            use \Foo\Bar, Baz\Qux as Q;
            USE App\{Models\User, Http\Kernel AS K,};
            use Lone;
            class X {}
            PHP;

        $this->assertSame(
            ['Bar' => 'Foo\\Bar', 'Q' => 'Baz\\Qux', 'User' => 'App\\Models\\User', 'K' => 'App\\Http\\Kernel', 'Lone' => 'Lone'],
            FileParser::extractImportsFromSource($code)
        );
    }

    public function testExtractImportsSkipsFunctionAndConstImports(): void
    {
        $code = <<<'PHP'
            <?php
            use function Foo\bar;
            use const Foo\BAZ;
            use Function Foo\{a, b};
            use App\{Models\User, function helper, const VERSION, Http\Kernel};
            PHP;

        $this->assertSame(
            ['User' => 'App\\Models\\User', 'Kernel' => 'App\\Http\\Kernel'],
            FileParser::extractImportsFromSource($code)
        );
    }

    public function testExtractImportsIgnoresCommentsInsideStatement(): void
    {
        $code = "<?php\nuse Foo\\Bar /* legacy; keep */ as /** doc */ B;\nuse App\\{\n    User, // primary\n    # old\n    Post,\n};\n";

        $this->assertSame(['B' => 'Foo\\Bar', 'User' => 'App\\User', 'Post' => 'App\\Post'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsKeepsFirstOfCaseInsensitiveDuplicateAlias(): void
    {
        $code = "<?php\nuse A\\User;\nuse B\\user;\nuse C\\Thing as USER;\nuse D\\Other;\n";

        $this->assertSame(['User' => 'A\\User', 'Other' => 'D\\Other'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsReadsBracedNamespacesAndExcludesTraitUses(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Http {
                use Foo\Bar;
                class Kernel { use Macroable; }
                trait T { use Other; }
                enum E { use Third; }
                interface I {}
            }
            namespace {
                use Global\Thing;
                class G { use Fourth; }
            }
            PHP;

        $this->assertSame(['Bar' => 'Foo\\Bar', 'Thing' => 'Global\\Thing'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsReadsPastNamespaceStatementThatDeclaresNothing(): void
    {
        $this->assertSame(['B' => 'A\\B'], FileParser::extractImportsFromSource("<?php\nnamespace 1;\nuse A\\B;\n"));
    }

    /**
     * Regression (review of #152): a namespace declaration is top level, so a brace left open
     * in an earlier namespace does not hide the imports of a later one.
     */
    public function testExtractImportsResetsDepthAtNamespaceDeclaration(): void
    {
        $this->assertSame(
            ['Z' => 'Y\\Z'],
            FileParser::extractImportsFromSource("<?php\nnamespace A;\nclass X { function f() {\n}\nnamespace B;\nuse Y\\Z;\n")
        );
        $this->assertSame(
            ['Z' => 'Y\\Z'],
            FileParser::extractImportsFromSource("<?php\nnamespace A {\nclass X { function f() {\n}\nnamespace B {\nuse Y\\Z;\nclass W { use T; }\n}\n")
        );
    }

    /**
     * Regression (review of #152): `as` and `insteadof` are reserved and never a name, so
     * these are neither imports nor statements (extractUseStatements() agrees).
     */
    public function testExtractImportsRejectsReservedWordAsName(): void
    {
        foreach ([
            "<?php\nuse as;\n",
            "<?php\nuse insteadof;\n",
            "<?php\nuse A\\{AS, B};\n",
            "<?php\nuse Foo as InsteadOf;\n",
        ] as $code) {
            $this->assertSame([], FileParser::extractImportsFromSource($code), $code);
        }

        // A reserved word is fine as a segment of a qualified name.
        $this->assertSame(['Foo' => 'As\\Foo'], FileParser::extractImportsFromSource("<?php\nuse As\\Foo;\n"));
    }

    /**
     * Regression (review of #152): an import missing its `;` ends at a namespace declaration,
     * so a braced namespace it runs into still opens the namespace level.
     */
    public function testExtractImportsReadsBracedNamespaceAfterImportMissingSemicolon(): void
    {
        $code = "<?php\nuse A\\B\nnamespace C {\nuse X\\Y;\n}\n";

        $this->assertSame(['Y' => 'X\\Y'], FileParser::extractImportsFromSource($code));
        // extractUseStatements() reads it as before: the statement runs to the next `;`.
        $this->assertSame(["A\\B\nnamespace C", 'X\\Y'], FileParser::extractUseStatementsFromSource($code));
    }

    public function testExtractImportsMergesNamespacesFirstWins(): void
    {
        $code = "<?php\nnamespace A;\nuse X\\User;\nnamespace B;\nuse Y\\User;\nuse Y\\Post;\n";

        $this->assertSame(['User' => 'X\\User', 'Post' => 'Y\\Post'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsExcludesClosureUseAndUseInsideFunctions(): void
    {
        $code = "<?php\nuse Foo\\Bar;\n\$f = function () use (\$x) { return \$x; };\nfunction g() { use Nope; }\n";

        $this->assertSame(['Bar' => 'Foo\\Bar'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsTracksBracesInsideInterpolatedStringsAndHeredocs(): void
    {
        $code = <<<'PHP'
            <?php
            namespace A {
                $s = "{$a} ${b} {$c['}']}";
                $h = <<<EOT
                {$d}
                use Not\Import;
                EOT;
                class X { use T; }
                use B\C;
            }
            PHP;

        $this->assertSame(['C' => 'B\\C'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsIgnoresCommentsAndStrings(): void
    {
        $code = "<?php\n// use Fake\\One;\n/* use Fake\\Two; */\n\$s = 'use Fake\\Three;';\nuse Real\\Four;\n";

        $this->assertSame(['Four' => 'Real\\Four'], FileParser::extractImportsFromSource($code));
    }

    public function testExtractImportsReadsImportAfterLabelAndCloseTag(): void
    {
        $this->assertSame(
            ['B' => 'A\\B', 'D' => 'C\\D'],
            FileParser::extractImportsFromSource("<?php\nfoo:\nuse A\\B ?>\n<p></p>\n<?php use C\\D;\n")
        );
    }

    public function testExtractImportsSkipsMalformedStatements(): void
    {
        $code = <<<'PHP'
            <?php
            use ;
            use A B;
            use 1;
            use A\{B;
            use C\{D}};
            use E\{F, G as};
            use H as I as J;
            use K\{L} M;
            use N\{O, P\{Q}};
            use Ok\One;
            class X {}
            use R\S }
            use Ok\Two;
            use T\U { }
            use Ok\Three;
            use Trailing
            PHP;

        $this->assertSame(
            ['One' => 'Ok\\One', 'Two' => 'Ok\\Two', 'Three' => 'Ok\\Three'],
            FileParser::extractImportsFromSource($code)
        );
    }

    /**
     * Each `use` is read once: a rescan from every `use` to the next terminator would make this
     * input quadratic.
     */
    public function testExtractImportsStaysLinearOnMalformedInput(): void
    {
        $code = "<?php\n" . str_repeat("use A\\{B, \n", 3000) . "};\n" . str_repeat("use C\\D }\n", 3000);

        $this->assertSame([], FileParser::extractImportsFromSource($code));
    }

    // --- extractClassLikes(): every named class-like declaration ---

    public function testExtractClassLikesReturnsEveryDeclarationWithKind(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            interface I {}
            #[Attr]
            final class A implements I {}
            abstract class B {}
            readonly class C {}
            final readonly class D {}
            trait T {}
            enum E: string { case X = 'x'; }
            enum F {}
            PHP;

        $this->assertSame([
            ['name' => 'App\\I', 'kind' => 'interface'],
            ['name' => 'App\\A', 'kind' => 'class'],
            ['name' => 'App\\B', 'kind' => 'class'],
            ['name' => 'App\\C', 'kind' => 'class'],
            ['name' => 'App\\D', 'kind' => 'class'],
            ['name' => 'App\\T', 'kind' => 'trait'],
            ['name' => 'App\\E', 'kind' => 'enum'],
            ['name' => 'App\\F', 'kind' => 'enum'],
        ], FileParser::extractClassLikesFromSource($code));
    }

    public function testExtractClassLikesQualifiesEachWithEnclosingNamespace(): void
    {
        $code = <<<'PHP'
            <?php
            namespace {
                class G {}
            }
            namespace App\Http {
                class Kernel {}
            }
            namespace Other {
                interface Contract {}
            }
            PHP;

        $file = $this->writeFixture($code);

        $this->assertSame([
            ['name' => 'G', 'kind' => 'class'],
            ['name' => 'App\\Http\\Kernel', 'kind' => 'class'],
            ['name' => 'Other\\Contract', 'kind' => 'interface'],
        ], FileParser::extractClassLikes($file));
        // extractClassName() still returns only the first class.
        $this->assertSame('G', FileParser::extractClassName($file));
    }

    public function testExtractClassLikesReadsUnbracedNamespacesAndGlobalCode(): void
    {
        $this->assertSame(
            [['name' => 'Top', 'kind' => 'class'], ['name' => 'A\\X', 'kind' => 'class'], ['name' => 'B\\Y', 'kind' => 'trait']],
            FileParser::extractClassLikesFromSource("<?php\nclass Top {}\nnamespace A;\nclass X {}\nnamespace B;\ntrait Y {}\n")
        );
    }

    public function testExtractClassNameStillReturnsFirstClassNotFirstClassLike(): void
    {
        $file = $this->writeFixture("<?php\nnamespace App;\ninterface I {}\nenum E {}\nclass C {}\nclass D {}\n");

        $this->assertSame('C', FileParser::extractClassName($file));
        $this->assertSame('App\\C', FileParser::extractFullyQualifiedClassName($file));
        $this->assertCount(4, FileParser::extractClassLikes($file));
    }

    public function testExtractClassLikesSkipsNonDeclarations(): void
    {
        $code = <<<'PHP'
            <?php
            // class InComment {}
            /** interface InDoc */
            $a = 'trait InString';
            $b = Foo::class;
            $c = new class {};
            $d = new class(1) extends Base {};
            $e = new readonly class {};
            $f = $x->enum;
            $g = $x?->class;
            $h = Enum::X;
            $i = Foo::enum;
            function enum() {}
            $j = fn(): enum => 1;
            foo(enum: 1, class: 2);
            use enum as Alias;
            Foo::enum Bar;
            $k = <<<EOT
            class InHeredoc {}
            EOT;
            class Enum extends X {}
            PHP;

        $this->assertSame([['name' => 'Enum', 'kind' => 'class']], FileParser::extractClassLikesFromSource($code));
    }

    public function testExtractClassLikesIncludesConditionalDeclarations(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            if (! class_exists(A::class)) {
                class A {}
            }
            if (PHP_VERSION_ID > 80100):
                enum B {}
            else:
                class B {}
            endif;
            function make() {
                interface C {}
            }
            switch ($x) {
                case 1: trait D {}
                default: class E {}
            }
            label: class F {}
            PHP;

        $this->assertSame([
            ['name' => 'App\\A', 'kind' => 'class'],
            ['name' => 'App\\B', 'kind' => 'enum'],
            ['name' => 'App\\B', 'kind' => 'class'],
            ['name' => 'App\\C', 'kind' => 'interface'],
            ['name' => 'App\\D', 'kind' => 'trait'],
            ['name' => 'App\\E', 'kind' => 'class'],
            ['name' => 'App\\F', 'kind' => 'class'],
        ], FileParser::extractClassLikesFromSource($code));
    }

    public function testExtractClassLikesSkipsNamespaceUsedAsName(): void
    {
        $code = "<?php\nnamespace App;\ninterface HasNs { const NAMESPACE = 'x'; }\nfoo(namespace: 1);\nclass Foo {}\n";

        $this->assertSame(
            [['name' => 'App\\HasNs', 'kind' => 'interface'], ['name' => 'App\\Foo', 'kind' => 'class']],
            FileParser::extractClassLikesFromSource($code)
        );
    }

    public function testExtractClassLikesIgnoresKeywordAtEndOfInput(): void
    {
        $this->assertSame([], FileParser::extractClassLikesFromSource("<?php\nclass"));
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

    // --- stripComments ---

    public function testStripCommentsRemovesDoubleSlashAndHashComments(): void
    {
        $this->assertSame("    'a' => 1, ", FileParser::stripComments("    'a' => 1, // note"));
        $this->assertSame("    'b' => 2, ", FileParser::stripComments("    'b' => 2, # note"));
    }

    public function testStripCommentsLeavesALineWithoutCommentsUnchanged(): void
    {
        $this->assertSame("    'a' => 1,", FileParser::stripComments("    'a' => 1,"));
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
