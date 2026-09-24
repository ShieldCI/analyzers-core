<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PhpParser\Node\{Expr, Stmt};
use PhpParser\{ParserFactory, PhpVersion};
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\Support\AstParser;

class AstParserTest extends TestCase
{
    private AstParser $parser;
    private string $testDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AstParser();
        $this->testDir = sys_get_temp_dir() . '/shield-ci-ast-test-' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testParseCodeReturnsAstNodes(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $this->assertIsArray($ast);
        $this->assertNotEmpty($ast);
        $this->assertContainsOnlyInstancesOf(\PhpParser\Node::class, $ast);
    }

    public function testParseCodeReturnsEmptyArrayForInvalidCode(): void
    {
        $code = '<?php this is not valid php code {{{';
        $ast = $this->parser->parseCode($code);

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
        $this->assertCount(1, $this->parser->failures());
    }

    public function testParseCodeReturnsEmptyArrayForEmptyCode(): void
    {
        $ast = $this->parser->parseCode('');

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
        $this->assertSame([], $this->parser->failures());
    }

    public function testParseFileReturnsAstFromFile(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, '<?php $x = 42;');

        $ast = $this->parser->parseFile($file);

        $this->assertIsArray($ast);
        $this->assertNotEmpty($ast);
    }

    public function testParseFileReturnsEmptyArrayForNonExistentFile(): void
    {
        $ast = $this->parser->parseFile('/non/existent/file.php');

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
    }

    public function testParseFileReturnsEmptyArrayForUnreadableFile(): void
    {
        $file = $this->testDir . '/unreadable.php';
        file_put_contents($file, '<?php $x = 42;');
        chmod($file, 0000);

        $ast = $this->parser->parseFile($file);

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);

        chmod($file, 0644);
    }

    public function testFindNodesFindsSpecificNodeType(): void
    {
        $code = '<?php $x = 42; $y = "hello";';
        $ast = $this->parser->parseCode($code);

        $variables = $this->parser->findNodes($ast, Expr\Variable::class);

        $this->assertCount(2, $variables);
        $this->assertContainsOnlyInstancesOf(Expr\Variable::class, $variables);
    }

    public function testFindNodesReturnsEmptyArrayWhenNoMatches(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $methodCalls = $this->parser->findNodes($ast, Expr\MethodCall::class);

        $this->assertIsArray($methodCalls);
        $this->assertEmpty($methodCalls);
    }

    public function testFindMethodCallsFindsMethodByName(): void
    {
        $code = '<?php $obj->execute(); $obj->run(); $obj->execute();';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findMethodCalls($ast, 'execute');

        $this->assertCount(2, $calls);
    }

    public function testFindMethodCallsReturnsEmptyArrayWhenNoMatches(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findMethodCalls($ast, 'nonExistent');

        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindStaticCallsFindsStaticMethodCalls(): void
    {
        $code = '<?php MyClass::doSomething(); OtherClass::doSomething(); MyClass::doSomething();';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findStaticCalls($ast, 'MyClass', 'doSomething');

        $this->assertCount(2, $calls);
    }

    public function testFindStaticCallsReturnsEmptyArrayWhenNoMatches(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findStaticCalls($ast, 'MyClass', 'method');

        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindFunctionCallsFindsFunctionsByName(): void
    {
        $code = '<?php strlen("test"); print_r($x); strlen("again");';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findFunctionCalls($ast, 'strlen');

        $this->assertCount(2, $calls);
    }

    public function testFindFunctionCallsReturnsEmptyArrayWhenNoMatches(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findFunctionCalls($ast, 'nonExistentFunction');

        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindClassesFindsClassDefinitions(): void
    {
        $code = '<?php class Foo {} class Bar {}';
        $ast = $this->parser->parseCode($code);

        $classes = $this->parser->findClasses($ast);

        $this->assertCount(2, $classes);
        $this->assertContainsOnlyInstancesOf(Stmt\Class_::class, $classes);
    }

    public function testFindClassesReturnsEmptyArrayWhenNoClasses(): void
    {
        $code = '<?php $x = 42;';
        $ast = $this->parser->parseCode($code);

        $classes = $this->parser->findClasses($ast);

        $this->assertIsArray($classes);
        $this->assertEmpty($classes);
    }

    public function testFindMethodsFindsAllMethods(): void
    {
        $code = '<?php class Foo { public function bar() {} public function baz() {} }';
        $ast = $this->parser->parseCode($code);

        $methods = $this->parser->findMethods($ast);

        $this->assertCount(2, $methods);
        $this->assertContainsOnlyInstancesOf(Stmt\ClassMethod::class, $methods);
    }

    public function testFindMethodsFindsMethodsInSpecificClass(): void
    {
        $code = '<?php class Foo { public function bar() {} } class Baz { public function qux() {} }';
        $ast = $this->parser->parseCode($code);

        $methods = $this->parser->findMethods($ast, 'Foo');

        $this->assertCount(1, $methods);
        $this->assertEquals('bar', $methods[0]->name->name);
    }

    public function testFindMethodsReturnsEmptyArrayForNonExistentClass(): void
    {
        $code = '<?php class Foo { public function bar() {} }';
        $ast = $this->parser->parseCode($code);

        $methods = $this->parser->findMethods($ast, 'NonExistent');

        $this->assertIsArray($methods);
        $this->assertEmpty($methods);
    }

    public function testHasStringConcatenationReturnsTrueWhenPresent(): void
    {
        $code = '<?php $x = "Hello " . "World";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasStringConcatenation($ast);

        $this->assertTrue($result);
    }

    public function testHasStringConcatenationReturnsFalseWhenAbsent(): void
    {
        $code = '<?php $x = "Hello World";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasStringConcatenation($ast);

        $this->assertFalse($result);
    }

    public function testHasVariableInterpolationReturnsTrueForCurlyBraceSyntax(): void
    {
        $code = '<?php $x = "Hello {$name}";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationReturnsTrueForDirectVariableSyntax(): void
    {
        $code = '<?php $x = "Hello $name";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationReturnsFalseWhenAbsent(): void
    {
        $code = '<?php $x = "Hello World";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertFalse($result);
    }

    public function testFindVariablesFindsAllVariables(): void
    {
        $code = '<?php $x = 42; $y = $x + 10; $z = $y;';
        $ast = $this->parser->parseCode($code);

        $variables = $this->parser->findVariables($ast);

        $this->assertCount(3, $variables);
        $this->assertContains('x', $variables);
        $this->assertContains('y', $variables);
        $this->assertContains('z', $variables);
    }

    public function testFindVariablesReturnsUniqueNames(): void
    {
        $code = '<?php $x = 42; $x = $x + 1;';
        $ast = $this->parser->parseCode($code);

        $variables = $this->parser->findVariables($ast);

        $this->assertCount(1, $variables);
        $this->assertContains('x', $variables);
    }

    public function testFindVariablesReturnsEmptyArrayWhenNoVariables(): void
    {
        $code = '<?php echo "Hello";';
        $ast = $this->parser->parseCode($code);

        $variables = $this->parser->findVariables($ast);

        $this->assertIsArray($variables);
        $this->assertEmpty($variables);
    }

    public function testParseComplexClassStructure(): void
    {
        $code = '<?php
        namespace App;

        class MyClass {
            private $property;

            public function __construct() {
                $this->property = "value";
            }

            public function method() {
                $this->property->call();
                static::staticCall();
                parent::parentCall();
                return functionCall();
            }
        }';

        $ast = $this->parser->parseCode($code);

        $this->assertNotEmpty($ast);
        $classes = $this->parser->findClasses($ast);
        $this->assertCount(1, $classes);

        $methods = $this->parser->findMethods($ast);
        $this->assertCount(2, $methods);

        $methodCalls = $this->parser->findMethodCalls($ast, 'call');
        $this->assertNotEmpty($methodCalls);
    }

    public function testParseFileReturnsEmptyArrayWhenFileGetContentsFails(): void
    {
        // A directory never reaches file_get_contents(): is_file() rejects it
        // first, so this exercises the not-a-file arm of the guard.
        $dir = $this->testDir . '/subdir';
        mkdir($dir);

        $ast = $this->parser->parseFile($dir);

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
        $this->assertSame(
            ParseFailureCause::Unreadable,
            $this->parser->failures()[0]->cause
        );
        $this->assertSame('Path is not a file.', $this->parser->failures()[0]->message);
    }

    public function testFindMethodCallsHandlesNonIdentifierMethodNames(): void
    {
        // Test line 78: Method calls with non-Identifier names (dynamic method calls)
        // e.g., $obj->{$method}() or $obj->$method()
        $code = '<?php $obj->{"dynamic"}(); $obj->$method();';
        $ast = $this->parser->parseCode($code);

        // These dynamic calls won't match because name is not Identifier
        $calls = $this->parser->findMethodCalls($ast, 'dynamic');

        // Should return empty because name is not Identifier (line 78 check)
        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindStaticCallsHandlesNonIdentifierMethodNames(): void
    {
        // Test line 97: Static calls with non-Identifier names
        $code = '<?php MyClass::{"dynamic"}();';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findStaticCalls($ast, 'MyClass', 'dynamic');

        // Should return empty because name is not Identifier (line 97 check)
        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindStaticCallsFiltersByMethodName(): void
    {
        // Test line 101: When method name doesn't match
        $code = '<?php MyClass::method1(); MyClass::method2();';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findStaticCalls($ast, 'MyClass', 'method1');

        // Should only find method1, not method2 (line 101 filters by name)
        $this->assertCount(1, $calls);
    }

    public function testFindStaticCallsReturnsFalseWhenClassNotNameNode(): void
    {
        // Test line 109: When class is not a Node\Name (e.g., variable class name)
        // e.g., $className::method() or "ClassName"::method()
        $code = '<?php $className::method();';
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findStaticCalls($ast, 'SomeClass', 'method');

        // Should return empty because class is not a Name node (line 109)
        $this->assertIsArray($calls);
        $this->assertEmpty($calls);
    }

    public function testFindFunctionCallsReturnsFalseWhenNameNotNameNode(): void
    {
        // Test line 130: Variable function calls have Expr\Variable name, not Node\Name
        // $func("test") produces a FuncCall where name is Expr\Variable, not Node\Name
        $code = '<?php $func("test"); strlen("test");';
        $ast = $this->parser->parseCode($code);

        // $func("test") is a FuncCall but name is Expr\Variable, not Node\Name
        // This exercises line 130 (return false) for the variable call
        $calls = $this->parser->findFunctionCalls($ast, 'func');

        $this->assertIsArray($calls);
        $this->assertEmpty($calls);

        // Confirm strlen is still found (normal path)
        $strlenCalls = $this->parser->findFunctionCalls($ast, 'strlen');
        $this->assertCount(1, $strlenCalls);
    }

    public function testHasVariableInterpolationDetectsVariablesInStringValues(): void
    {
        // Test line 204: Check for variable interpolation in string values
        // This checks String_ nodes (not InterpolatedString) for variable patterns
        $code = '<?php $x = "Hello {$name}"; $y = "Hello $name";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        // Should detect variables in strings (line 204 checks string->value)
        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationDetectsVarPatternInSingleQuotedString(): void
    {
        // Test line 204: Single-quoted strings preserve literal $ signs in the value
        // The regex /\$\w+/ should match the literal $name in the node's value
        $code = "<?php \$x = 'Hello \$name';";
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        // Single-quoted strings contain literal $name which matches the regex
        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationDetectsDollarSignPattern(): void
    {
        // Test line 204: preg_match('/\$\w+/', $string->value)
        $code = '<?php $x = "Price is $100"; $y = "Total: $total";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        // Should detect $total pattern (line 204)
        $this->assertTrue($result);
    }

    // --- resolveNames ---

    public function testResolveNamesResolvesFullyQualifiedClassNames(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model {}
PHP;
        $ast = $this->parser->parseCode($code);
        $resolved = $this->parser->resolveNames($ast);

        // Find the Class_ node and check extends is resolved
        $classes = $this->parser->findNodes($resolved, \PhpParser\Node\Stmt\Class_::class);
        $this->assertCount(1, $classes);

        /** @var \PhpParser\Node\Stmt\Class_ $classNode */
        $classNode = $classes[0];
        $this->assertNotNull($classNode->extends);

        // By default NameResolver replaces Name nodes with FullyQualified nodes
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $classNode->extends);
        $this->assertSame('Illuminate\Database\Eloquent\Model', $classNode->extends->toString());
    }

    public function testResolveNamesWithEmptyAst(): void
    {
        $resolved = $this->parser->resolveNames([]);

        $this->assertIsArray($resolved);
        $this->assertEmpty($resolved);
    }

    public function testResolveNamesWithReplaceNodesFalseOption(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use App\Models\User;

new User();
PHP;
        $ast = $this->parser->parseCode($code);
        $resolved = $this->parser->resolveNames($ast, ['replaceNodes' => false]);

        // With replaceNodes => false, original Name nodes are preserved
        // but 'resolvedName' attribute is still set
        $newNodes = $this->parser->findNodes($resolved, \PhpParser\Node\Expr\New_::class);
        $this->assertCount(1, $newNodes);

        /** @var \PhpParser\Node\Expr\New_ $newNode */
        $newNode = $newNodes[0];
        $this->assertInstanceOf(\PhpParser\Node\Name::class, $newNode->class);

        // Original short name preserved
        $this->assertSame('User', $newNode->class->toString());

        // But resolvedName attribute has the FQCN
        $resolvedName = $newNode->class->getAttribute('resolvedName');
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $resolvedName);
        $this->assertSame('App\Models\User', $resolvedName->toString());
    }

    public function testCollectStringLinesReturnsEmptyForCodeWithNoStrings(): void
    {
        $ast = $this->parser->parseCode('<?php $x = Auth::user()->name;');
        $result = $this->parser->collectStringLines($ast);
        $this->assertEmpty($result);
    }

    public function testCollectStringLinesIncludesSingleLineString(): void
    {
        $ast = $this->parser->parseCode('<?php $x = "hello world";');
        $result = $this->parser->collectStringLines($ast);
        $this->assertArrayHasKey(1, $result);
    }

    public function testCollectStringLinesCoversAllLinesOfHeredoc(): void
    {
        // Line 1: <?php
        // Line 2: $x = <<<'DOC'
        // Line 3: line one
        // Line 4: line two
        // Line 5: DOC;
        $code = "<?php\n\$x = <<<'DOC'\nline one\nline two\nDOC;\n";
        $ast = $this->parser->parseCode($code);
        $result = $this->parser->collectStringLines($ast);

        $this->assertArrayHasKey(3, $result, 'First content line of heredoc must be in set');
        $this->assertArrayHasKey(4, $result, 'Second content line of heredoc must be in set');
    }

    public function testCollectStringLinesCoversInterpolatedString(): void
    {
        // Line 1: <?php
        // Line 2: $name = 'user';     <- single-quoted string, line 2
        // Line 3: $msg = "Hello $name";  <- interpolated string, line 3
        $code = "<?php\n\$name = 'user';\n\$msg = \"Hello \$name\";";
        $ast = $this->parser->parseCode($code);
        $result = $this->parser->collectStringLines($ast);

        $this->assertArrayHasKey(2, $result, 'Single-quoted string line must be in set');
        $this->assertArrayHasKey(3, $result, 'Interpolated string line must be in set');
    }

    // --- AST cache ---

    public function testParseFileReturnsSameResultOnSecondCall(): void
    {
        $file = $this->testDir . '/cached.php';
        file_put_contents($file, '<?php class Foo {}');

        $first = $this->parser->parseFile($file);
        $second = $this->parser->parseFile($file);

        $this->assertCount(count($first), $second);
        $this->assertSame($first, $second);
    }

    public function testClearCacheAllowsReparsing(): void
    {
        $file = $this->testDir . '/cached2.php';
        file_put_contents($file, '<?php $x = 1;');

        $first = $this->parser->parseFile($file);
        $this->assertNotEmpty($first);

        $this->parser->clearCache();

        $second = $this->parser->parseFile($file);
        $this->assertNotEmpty($second);
        $this->assertCount(count($first), $second);
    }

    public function testParseFileUpdatesWhenFileChangesAfterClearCache(): void
    {
        $file = $this->testDir . '/changed.php';
        file_put_contents($file, '<?php $x = 1;');

        $first = $this->parser->parseFile($file);
        $this->assertNotEmpty($first);

        // Rewrite the file with different content and clear cache
        file_put_contents($file, '<?php $x = 1; $y = 2; $z = 3;');
        $this->parser->clearCache();

        $second = $this->parser->parseFile($file);
        // New content has 3 statements; old had 1
        $this->assertGreaterThan(count($first), count($second));
    }

    // --- Recorded parse failures ---

    public function testFailuresIsEmptyForAFreshParser(): void
    {
        $this->assertSame([], $this->parser->failures());
    }

    public function testASuccessfulParseRecordsNothing(): void
    {
        $file = $this->testDir . '/good.php';
        file_put_contents($file, '<?php class Good {}');

        $this->assertNotEmpty($this->parser->parseFile($file));
        $this->assertSame([], $this->parser->failures());
    }

    public function testEmptySourceIsNotAFailure(): void
    {
        $file = $this->testDir . '/empty.php';
        file_put_contents($file, '');

        $this->assertSame([], $this->parser->parseFile($file));
        $this->assertSame([], $this->parser->failures());
    }

    public function testReportsAGenuineSyntaxErrorWithTheParsersOwnMessageAndLine(): void
    {
        $file = $this->testDir . '/BrokenController.php';
        file_put_contents($file, "<?php\n\nclass BrokenController\n{\n    public function index(\n}\n");

        $this->parser->parseFile($file);

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame($file, $failures[0]->path);
        $this->assertSame(6, $failures[0]->line);
        $this->assertStringContainsString('Syntax error', $failures[0]->message);
        $this->assertSame(ParseFailureCause::SyntaxError, $failures[0]->cause);
    }

    public function testClassifiesSyntaxThePinnedParserCannotUnderstandSeparately(): void
    {
        // An enum is valid PHP on every runtime this package supports (8.1 is the
        // floor) and invalid to a parser pinned to 8.0, which is exactly the shape
        // of "the pinned parser is older than the runtime".
        //
        // Do NOT swap this for a newer construct such as `readonly class`: an 8.2
        // feature is rejected by the 8.1 runtime too, so both opinions would agree
        // and this would classify as SyntaxError on the 8.1 CI leg only.
        $parser = new AstParser((new ParserFactory())->createForVersion(PhpVersion::fromString('8.0')));

        $parser->parseCode(
            "<?php\n\nnamespace App\\Enums;\n\nenum Suit: string\n{\n    case Hearts = 'H';\n}\n",
            '/app/Enums/Suit.php'
        );

        $failures = $parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame(ParseFailureCause::UnsupportedSyntax, $failures[0]->cause);
    }

    public function testTheSameFileIsAGenuineSyntaxErrorToEveryParserVersion(): void
    {
        // Guards the discriminator against simply echoing the pinned parser's
        // opinion: with the same 8.0 parser as the test above, code no PHP runtime
        // accepts is still reported as a genuine syntax error.
        $parser = new AstParser((new ParserFactory())->createForVersion(PhpVersion::fromString('8.0')));

        $parser->parseCode("<?php\n\nclass Broken\n{\n    public function index(\n}\n", '/app/Broken.php');

        $failures = $parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame(ParseFailureCause::SyntaxError, $failures[0]->cause);
    }

    public function testRecordsAFailureWithNoPathWhenParseCodeIsGivenNoOrigin(): void
    {
        $this->parser->parseCode('<?php class Broken {');

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertNull($failures[0]->path);
        $this->assertSame(ParseFailureCause::SyntaxError, $failures[0]->cause);
    }

    public function testRecordsTheOriginItWasGiven(): void
    {
        $this->parser->parseCode('<?php class Broken {', '/app/Origin.php');

        $this->assertSame('/app/Origin.php', $this->parser->failures()[0]->path);
    }

    public function testRecordsAMissingFileAsUnreadable(): void
    {
        $this->parser->parseFile($this->testDir . '/absent.php');

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame($this->testDir . '/absent.php', $failures[0]->path);
        $this->assertNull($failures[0]->line);
        $this->assertSame('File does not exist.', $failures[0]->message);
        $this->assertSame(ParseFailureCause::Unreadable, $failures[0]->cause);
    }

    public function testRecordsAFileItIsNotAllowedToReadAsUnreadable(): void
    {
        $file = $this->testDir . '/locked.php';
        file_put_contents($file, '<?php $x = 42;');
        chmod($file, 0000);

        $this->parser->parseFile($file);

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('File is not readable.', $failures[0]->message);
        $this->assertSame(ParseFailureCause::Unreadable, $failures[0]->cause);

        chmod($file, 0644);
    }

    public function testRecordsTheSameFileOnlyOnceAcrossRepeatedParses(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        $this->parser->parseFile($file);
        $this->parser->parseFile($file);

        $this->assertCount(1, $this->parser->failures());
    }

    public function testRecordsTheSameFileOnlyOnceAcrossClearCache(): void
    {
        // Consumers call clearCache() once per analyzer, so the same broken file is
        // re-parsed dozens of times in a single run. Without dedup one file would
        // produce one record per analyzer.
        $file = $this->testDir . '/Broken2.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        for ($i = 0; $i < 5; $i++) {
            $this->parser->parseFile($file);
            $this->parser->clearCache();
        }

        $this->assertCount(1, $this->parser->failures());
    }

    public function testDistinctFilesAreRecordedSeparately(): void
    {
        $first = $this->testDir . '/One.php';
        $second = $this->testDir . '/Two.php';
        file_put_contents($first, '<?php class One {');
        file_put_contents($second, '<?php class Two {');

        $this->parser->parseFile($first);
        $this->parser->parseFile($second);

        $failures = $this->parser->failures();
        $this->assertCount(2, $failures);
        $this->assertSame([$first, $second], array_column($failures, 'path'));
    }

    public function testResetFailuresEmptiesTheLog(): void
    {
        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');
        $this->assertCount(1, $this->parser->failures());

        $this->parser->resetFailures();

        $this->assertSame([], $this->parser->failures());
    }

    public function testClearCacheLeavesRecordedFailuresAlone(): void
    {
        // The failure log is run-scoped; the AST cache is per-analyzer. Draining one
        // must not drain the other.
        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');

        $this->parser->clearCache();

        $this->assertCount(1, $this->parser->failures());
    }

    public function testTranslatesTheReportedLineWhenATranslatorIsGiven(): void
    {
        // A caller parsing generated code (a compiled template) knows the real source
        // path but cannot know which line will fail until the parse happens.
        $this->parser->parseCode(
            "<?php\nclass B { public function i( }\n",
            '/resources/views/x.blade.php',
            fn (int $line) => $line * 100
        );

        $this->assertSame(200, $this->parser->failures()[0]->line);
    }

    public function testReportsNoLineWhenTheTranslatorCannotMapIt(): void
    {
        // A translator is caller-supplied code; a lineMap miss returning 0 must not
        // surface as "line 0" in a report.
        $this->parser->parseCode(
            "<?php\nclass B { public function i( }\n",
            '/resources/views/x.blade.php',
            fn (int $line) => 0
        );

        $this->assertNull($this->parser->failures()[0]->line);
    }

    public function testDoesNotCallTheLineTranslatorOnASuccessfulParse(): void
    {
        $called = false;

        $this->parser->parseCode(
            "<?php\n\$a = 1;\n",
            '/resources/views/ok.blade.php',
            function (int $line) use (&$called) {
                $called = true;

                return $line;
            }
        );

        $this->assertFalse($called);
        $this->assertSame([], $this->parser->failures());
    }

    public function testAcceptsAnInjectedParser(): void
    {
        $parser = new AstParser((new ParserFactory())->createForVersion(PhpVersion::fromString('8.0')));

        $this->assertNotEmpty($parser->parseCode('<?php $x = 1;'));
    }

    // --- Recording that a caller recovered from a failure ---

    public function testHasFailureReportsWhetherAPathWasRecorded(): void
    {
        $this->assertFalse($this->parser->hasFailure('/app/Broken.php'));

        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');

        $this->assertTrue($this->parser->hasFailure('/app/Broken.php'));
    }

    public function testHasFailureIsFalseForAFileThatParsedToNoStatements(): void
    {
        // The precondition callers need: an empty file parses successfully and records
        // nothing, so an empty AST does not mean the parse failed.
        $this->assertSame([], $this->parser->parseCode('<?php', '/app/Empty.php'));

        $this->assertFalse($this->parser->hasFailure('/app/Empty.php'));
    }

    public function testHasFailureMatchesThePathSpellingItWasGiven(): void
    {
        $file = $this->testDir . '/Broken1.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        $this->parser->parseFile($file);

        // Both directions: normalising on one side alone would silently miss every key
        // the other side wrote, so the mismatch must report false and the match true.
        $this->assertFalse($this->parser->hasFailure($this->testDir . '/sub/../Broken1.php'));
        $this->assertTrue($this->parser->hasFailure($file));
    }

    public function testRecordRecoveryAnnotatesTheFailureWithoutRemovingIt(): void
    {
        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');

        $this->assertTrue($this->parser->recordRecovery('/app/Broken.php'));

        // The file still would not parse. That fact is not the recovering caller's to erase.
        $this->assertSame(['/app/Broken.php'], array_column($this->parser->failures(), 'path'));
        $this->assertSame(['/app/Broken.php'], $this->parser->recoveries());
    }

    public function testRecordRecoveryReportsWhetherThereWasAFailureToAnnotate(): void
    {
        $this->assertFalse($this->parser->recordRecovery('/app/NeverParsed.php'));
        $this->assertSame([], $this->parser->recoveries());

        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');

        $this->assertTrue($this->parser->recordRecovery('/app/Broken.php'));
    }

    public function testRecordRecoveryIsIdempotent(): void
    {
        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');

        $this->parser->recordRecovery('/app/Broken.php');
        $this->parser->recordRecovery('/app/Broken.php');

        $this->assertSame(['/app/Broken.php'], $this->parser->recoveries());
    }

    public function testOneCallersRecoveryDoesNotEraseAnothersSkip(): void
    {
        // The reason this is additive. The log is keyed per file and shared by every
        // caller, and record() keeps the first sighting, so the entry under a path may
        // belong to a caller that genuinely skipped the file. A later caller recovering
        // part of it must not be able to delete that.
        $file = $this->testDir . '/Shared.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        // Caller A: parses, fails, skips the file.
        $this->parser->parseFile($file);

        // Caller B: re-parses after the cache is drained, then recovers part of it.
        $this->parser->clearCache();
        $this->parser->parseFile($file);
        $this->parser->recordRecovery($file);

        $this->assertSame(
            [$file],
            array_column($this->parser->failures(), 'path'),
            'A recovery must not remove the record of a caller that skipped the file.'
        );
    }

    public function testRecoveriesAreAlwaysASubsetOfFailures(): void
    {
        $this->parser->parseCode('<?php class One {', '/app/One.php');
        $this->parser->parseCode('<?php class Two {', '/app/Two.php');

        $this->parser->recordRecovery('/app/Two.php');
        $this->parser->recordRecovery('/app/NeverParsed.php');

        $this->assertSame(
            [],
            array_diff($this->parser->recoveries(), array_column($this->parser->failures(), 'path'))
        );
    }

    public function testResetFailuresClearsRecoveriesToo(): void
    {
        // Both logs are run-scoped; a recovery outliving its failure would leave
        // recoveries() naming a path failures() no longer knows about.
        $this->parser->parseCode('<?php class Broken {', '/app/Broken.php');
        $this->parser->recordRecovery('/app/Broken.php');

        $this->parser->resetFailures();

        $this->assertSame([], $this->parser->failures());
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testAnOriginLessFailureIsReachableByItsHashedKey(): void
    {
        // With no origin the record is keyed on a hash of the code and names no path. The
        // key namespace is shared with real paths, so that key is an ordinary string a
        // caller holding the code can reconstruct - worth pinning rather than implying
        // the entry is unreachable.
        $code = '<?php class Broken {';
        $this->parser->parseCode($code);

        $this->assertNull($this->parser->failures()[0]->path);
        $this->assertTrue($this->parser->hasFailure('code:' . md5($code)));
    }

    public function testAnUnreadablePathCanBeAnnotatedButKeepsItsRecord(): void
    {
        // Nothing read the file, so no caller can have recovered from it in the usual
        // sense. Annotating is still harmless because the failure survives either way.
        $absent = $this->testDir . '/absent.php';

        $this->parser->parseFile($absent);
        $this->parser->recordRecovery($absent);

        $this->assertSame([$absent], array_column($this->parser->failures(), 'path'));
    }
}
