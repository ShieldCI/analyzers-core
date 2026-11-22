<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PhpParser\Node\{Expr, Stmt};
use PhpParser\{ParserFactory, PhpVersion};
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Tests\Support\CountingStreamWrapper;

class AstParserTest extends TestCase
{
    /**
     * Parses, but PHP rejects it at compile time: the second import reuses the alias Clock.
     */
    private const COLLIDING_IMPORTS = <<<'PHP'
<?php
namespace App;

use App\Support\Clock;
use App\Legacy\Clock;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => new Clock());
PHP;

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
        CountingStreamWrapper::unregister();
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

    public function testFindMethodCallsFindsNullsafeMethodCall(): void
    {
        $ast = $this->parser->parseCode('<?php $a?->render();');

        $calls = $this->parser->findMethodCalls($ast, 'render');

        $this->assertCount(1, $calls);
        $this->assertInstanceOf(Expr\NullsafeMethodCall::class, $calls[0]);
    }

    public function testFindMethodCallsFindsNullsafeCallAtTheEndOfAChain(): void
    {
        $ast = $this->parser->parseCode('<?php $c->d()?->render();');

        $calls = $this->parser->findMethodCalls($ast, 'render');

        $this->assertCount(1, $calls);
        $this->assertInstanceOf(Expr\NullsafeMethodCall::class, $calls[0]);
    }

    public function testFindMethodCallsFindsNullsafeCallsInsideAndAfterAChain(): void
    {
        $ast = $this->parser->parseCode('<?php $a?->b()->render(); $c->d()?->render();');

        $calls = $this->parser->findMethodCalls($ast, 'render');

        $this->assertCount(2, $calls);
        $this->assertInstanceOf(Expr\MethodCall::class, $calls[0]);
        $this->assertInstanceOf(Expr\NullsafeMethodCall::class, $calls[1]);
    }

    public function testFindMethodCallsReturnsPlainAndNullsafeCallsInSourceOrder(): void
    {
        $code = <<<'PHP'
<?php
$a->render();
$b?->render();
$c->other();
$d?->other();
$e->render();
PHP;
        $ast = $this->parser->parseCode($code);

        $calls = $this->parser->findMethodCalls($ast, 'render');

        $this->assertCount(3, $calls);
        $this->assertInstanceOf(Expr\MethodCall::class, $calls[0]);
        $this->assertInstanceOf(Expr\NullsafeMethodCall::class, $calls[1]);
        $this->assertInstanceOf(Expr\MethodCall::class, $calls[2]);
        $this->assertSame([2, 3, 6], array_map(static fn ($call) => $call->getStartLine(), $calls));
    }

    public function testFindMethodCallsIgnoresNullsafeCallWithADifferentName(): void
    {
        $ast = $this->parser->parseCode('<?php $a?->other();');

        $this->assertSame([], $this->parser->findMethodCalls($ast, 'render'));
    }

    public function testFindMethodCallsIgnoresNullsafeCallWithADynamicName(): void
    {
        $ast = $this->parser->parseCode('<?php $m = "render"; $a?->$m(); $a?->{"render"}();');

        $this->assertSame([], $this->parser->findMethodCalls($ast, 'render'));
    }

    public function testFindMethodCallsStillFindsPlainMethodCall(): void
    {
        $ast = $this->parser->parseCode('<?php $a->render();');

        $calls = $this->parser->findMethodCalls($ast, 'render');

        $this->assertCount(1, $calls);
        $this->assertInstanceOf(Expr\MethodCall::class, $calls[0]);
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
        // Both strings are double-quoted with real variables, so both parse to
        // InterpolatedString nodes
        $code = '<?php $x = "Hello {$name}"; $y = "Hello $name";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationIgnoresVarPatternInSingleQuotedString(): void
    {
        // Single-quoted strings never interpolate: $name is literal text
        $code = '<?php $x = ' . "'" . 'Hello $name' . "'" . ';';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertFalse($result);
    }

    public function testHasVariableInterpolationDetectsDollarSignPattern(): void
    {
        // "$100" is not a variable, but "$total" is, so the second string is an
        // InterpolatedString node
        $code = '<?php $x = "Price is $100"; $y = "Total: $total";';
        $ast = $this->parser->parseCode($code);

        $result = $this->parser->hasVariableInterpolation($ast);

        $this->assertTrue($result);
    }

    public function testHasVariableInterpolationIgnoresDollarDigitsInDoubleQuotedString(): void
    {
        // A variable name cannot start with a digit, so "$100" is literal text
        $code = '<?php $x = "Price is $100";';
        $ast = $this->parser->parseCode($code);

        $this->assertFalse($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationIgnoresDollarAmountInSingleQuotedString(): void
    {
        $code = '<?php $x = ' . "'" . 'costs $5' . "'" . ';';
        $ast = $this->parser->parseCode($code);

        $this->assertFalse($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationIgnoresCurlySyntaxInSingleQuotedString(): void
    {
        $code = '<?php $x = ' . "'" . '{$name}' . "'" . ';';
        $ast = $this->parser->parseCode($code);

        $this->assertFalse($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationIgnoresVariableInNowdoc(): void
    {
        $code = '<?php $x = <<<' . "'" . 'EOT' . "'" . PHP_EOL . '$name' . PHP_EOL . 'EOT;' . PHP_EOL;
        $ast = $this->parser->parseCode($code);

        $this->assertFalse($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationIgnoresEscapedDollarInDoubleQuotedString(): void
    {
        // A backslash-escaped dollar sign is a literal "$", not a variable
        $code = '<?php $x = "' . chr(92) . '$name";';
        $ast = $this->parser->parseCode($code);

        $this->assertFalse($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationDetectsVariableInHeredoc(): void
    {
        $code = '<?php $x = <<<EOT' . PHP_EOL . '$name' . PHP_EOL . 'EOT;' . PHP_EOL;
        $ast = $this->parser->parseCode($code);

        $this->assertTrue($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationDetectsPropertyFetchInCurlySyntax(): void
    {
        $code = '<?php $x = "{$a->b}";';
        $ast = $this->parser->parseCode($code);

        $this->assertTrue($this->parser->hasVariableInterpolation($ast));
    }

    public function testHasVariableInterpolationDetectsDollarBraceSyntax(): void
    {
        $code = '<?php $x = "${name}";';
        $ast = $this->parser->parseCode($code);

        $this->assertTrue($this->parser->hasVariableInterpolation($ast));
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

    public function testResolveNamesKeepsFirstImportWhenAliasesCollide(): void
    {
        $ast = $this->parser->parseCode(self::COLLIDING_IMPORTS);
        $resolved = $this->parser->resolveNames($ast, ['replaceNodes' => false]);

        $newNodes = $this->parser->findNodes($resolved, \PhpParser\Node\Expr\New_::class);
        $this->assertCount(1, $newNodes);

        /** @var \PhpParser\Node\Expr\New_ $newNode */
        $newNode = $newNodes[0];
        $this->assertInstanceOf(\PhpParser\Node\Name::class, $newNode->class);
        $this->assertNotInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $newNode->class);
        $this->assertSame('Clock', $newNode->class->toString());

        $resolvedName = $newNode->class->getAttribute('resolvedName');
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $resolvedName);
        $this->assertSame('App\Support\Clock', $resolvedName->toString());
    }

    public function testResolveNamesResolvesImportsAfterACollision(): void
    {
        // Catching the resolver's throw and keeping the AST fails here: traversal stops
        // at the second import, so Route is never resolved.
        $ast = $this->parser->parseCode(self::COLLIDING_IMPORTS);
        $resolved = $this->parser->resolveNames($ast, ['replaceNodes' => false]);

        $staticCalls = $this->parser->findNodes($resolved, \PhpParser\Node\Expr\StaticCall::class);
        $this->assertCount(1, $staticCalls);

        /** @var \PhpParser\Node\Expr\StaticCall $call */
        $call = $staticCalls[0];
        $this->assertInstanceOf(\PhpParser\Node\Name::class, $call->class);

        $resolvedName = $call->class->getAttribute('resolvedName');
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $resolvedName);
        $this->assertSame('Illuminate\Support\Facades\Route', $resolvedName->toString());
    }

    public function testResolveNamesCollisionIsCaseInsensitiveAndReplacesNodes(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use A\Clock;
use B\clock;

new Clock();
PHP;
        $ast = $this->parser->parseCode($code);
        $resolved = $this->parser->resolveNames($ast);

        $newNodes = $this->parser->findNodes($resolved, \PhpParser\Node\Expr\New_::class);
        $this->assertCount(1, $newNodes);

        /** @var \PhpParser\Node\Expr\New_ $newNode */
        $newNode = $newNodes[0];
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $newNode->class);
        $this->assertSame('A\Clock', $newNode->class->toString());
    }

    public function testResolveNamesDoesNotThrowOnQualifiedSpecialClassName(): void
    {
        // '\self' parses but does not compile, and php-parser reports it while resolving.
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

new \self();
new Bar();
PHP;
        $ast = $this->parser->parseCode($code);
        $this->assertSame([], $this->parser->failures());

        $resolved = $this->parser->resolveNames($ast, ['replaceNodes' => false]);

        $newNodes = $this->parser->findNodes($resolved, \PhpParser\Node\Expr\New_::class);
        $this->assertCount(2, $newNodes);

        /** @var \PhpParser\Node\Expr\New_ $barNode */
        $barNode = $newNodes[1];
        $this->assertInstanceOf(\PhpParser\Node\Name::class, $barNode->class);

        $resolvedName = $barNode->class->getAttribute('resolvedName');
        $this->assertInstanceOf(\PhpParser\Node\Name\FullyQualified::class, $resolvedName);
        $this->assertSame('Foo\Bar', $resolvedName->toString());
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

    // --- Parser version contract ---

    public function testCompatibilityReportsTheDefaultParserAgainstTheRunningRuntime(): void
    {
        $compatibility = AstParser::compatibility();

        $this->assertSame(PhpVersion::getNewestSupported()->id, $compatibility->parserVersionId);
        $this->assertSame(PhpVersion::getHostVersion()->id, $compatibility->runtimeVersionId);
    }

    /**
     * compatibility() describes the parser the package would build for itself, not the one any
     * particular instance holds. Injecting an older parser is how a caller deliberately
     * exercises UnsupportedSyntax, so it must not register as a broken toolchain -- otherwise
     * every UnsupportedSyntax test here would start reporting one.
     *
     * Asserted structurally rather than behaviourally, and the distinction matters. Building an
     * AstParser with a pinned parser and then asserting compatibility() is unchanged proves
     * nothing: the method is static and reads no instance state, so no constructed object could
     * reach it whatever it held, and the assertion would be a copy of the one above. Staticness
     * IS the property, so pin staticness. Make this method non-static and consult $this->parser
     * and this test fails, which is exactly when it should.
     */
    public function testCompatibilityIsStaticSoNoInstanceCanColourIt(): void
    {
        $this->assertTrue(
            (new \ReflectionMethod(AstParser::class, 'compatibility'))->isStatic()
        );
    }

    /**
     * The behavioural half of the same guarantee: a pinned parser is a deliberate choice, and
     * the parser it pins must not leak into the package-level verdict.
     */
    public function testCompatibilityIgnoresAParserSomebodyInjected(): void
    {
        $pinned = new AstParser((new ParserFactory())->createForVersion(PhpVersion::fromString('8.0')));

        $this->assertNotSame(
            PhpVersion::getNewestSupported()->id,
            PhpVersion::fromString('8.0')->id,
            'The pinned version must differ from the default, or this test cannot fail.'
        );
        $this->assertSame(
            PhpVersion::getNewestSupported()->id,
            $pinned::compatibility()->parserVersionId
        );
    }

    /**
     * The installed nikic/php-parser must understand the PHP this suite runs on.
     *
     * Nothing else here can see this. AstParser defaults to the parser's newest
     * supported version, and classify() asks the running PHP for a second opinion,
     * so a parser older than the runtime never fails loudly — every file using
     * current syntax quietly becomes a ParseFailure with cause UnsupportedSyntax
     * instead of an AST, and every analyzer built on this package stops seeing
     * those files without saying so. The UnsupportedSyntax tests below pin their
     * own parser version deliberately, and nothing here hands the default parser
     * syntax newer than PHP 8.1, so a floor set too low goes unnoticed without
     * this check.
     *
     * This is the regression guard for #72, where composer.json declared a parser
     * range the package could not run on, and no CI leg ever installed the floor
     * to find out. Do not delete it, and do not pin a version here — comparing the
     * installed parser against the live runtime is the whole point.
     *
     * Two situations fail this, and they have different fixes. On the php-parser
     * floor CI leg the parser is pinned low deliberately, so a failure means the
     * declared floor is too low for a PHP row the matrix runs: raise it. Anywhere
     * the parser resolved at newest — the test leg, a laptop — it means no released
     * php-parser understands this runtime yet, and no floor helps until one ships.
     *
     * Its comparison now lives in ParserCompatibility::isSupported() rather than inline
     * here, which is the point of #74 -- one definition, asserted on by the package that
     * ships it. That does mean this test can no longer catch a bug in the comparison
     * itself, so two others hold the pieces it gave up: ParserCompatibilityTest pins
     * isSupported() against literal version ids, including the older-parser case no CI leg
     * here can produce, and testCompatibilityReportsTheDefaultParserAgainstTheRunningRuntime
     * pins which version lands in which constructor slot. Swap those two arguments and this
     * guard still passes while asserting nothing. Do not delete either as trivial.
     */
    public function testTheInstalledParserUnderstandsTheRunningPhpVersion(): void
    {
        $compatibility = AstParser::compatibility();

        $this->assertTrue(
            $compatibility->isSupported(),
            sprintf(
                'nikic/php-parser understands PHP %s at newest, but this suite is running on PHP %s. '
                . 'Files using syntax the parser does not know are recorded as a ParseFailure with '
                . 'cause UnsupportedSyntax rather than parsed. If a released nikic/php-parser '
                . 'supports that runtime, raise the floor in composer.json to it; if none does '
                . 'yet, the parser has not caught up with this PHP and no floor helps until one '
                . 'ships.',
                $compatibility->parserVersion(),
                $compatibility->runtimeVersion()
            )
        );
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

    public function testReparsingAfterResetFailuresRecordsTheFailureAgain(): void
    {
        // #78: the reverse of the test above. Resetting the log must not leave the cache
        // holding a failed parse, or the re-parse would return the same empty AST while
        // recording nothing - hasFailure() false for a file that still does not parse.
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        $this->parser->parseFile($file);
        $this->parser->resetFailures();

        $ast = $this->parser->parseFile($file);

        $this->assertSame([], $ast);
        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertCount(1, $this->parser->failures());
    }

    public function testRecoveryCanBeRecordedAfterResetFailuresWithoutClearCache(): void
    {
        // What a hasFailure()-gated fallback does on a second run in the same process.
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        $this->parser->parseFile($file);
        $this->parser->resetFailures();
        $this->parser->parseFile($file);

        $this->assertTrue($this->parser->recordRecovery($file));
        $this->assertSame([$file], $this->parser->recoveries());
    }

    public function testAParsedFileIsStillServedFromTheCacheAfterResetFailures(): void
    {
        // Only failed parses skip the cache: a file that parsed returns the same nodes
        // and records nothing, however the log was reset in between.
        $file = $this->testDir . '/Good.php';
        file_put_contents($file, '<?php class Good {}');

        $first = $this->parser->parseFile($file);
        $this->parser->resetFailures();
        $second = $this->parser->parseFile($file);

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second);
        $this->assertSame([], $this->parser->failures());
    }

    public function testAnEmptyFileParsedRepeatedlyRecordsNothing(): void
    {
        // An empty file parses cleanly to no statements. Whether or not it is served from
        // the cache, it must keep being told apart from a failure.
        $file = $this->testDir . '/Empty.php';
        file_put_contents($file, "<?php\n");

        $this->assertSame([], $this->parser->parseFile($file));
        $this->assertSame([], $this->parser->parseFile($file));
        $this->assertFalse($this->parser->hasFailure($file));
        $this->assertSame([], $this->parser->failures());
    }

    public function testAPartialAstRecoveredByASubclassIsNotCached(): void
    {
        // The cache is gated on the failure log, not on an empty AST. A subclass that falls
        // back to its own recovery after parent::parseCode() fails hands back nodes, and a
        // cached copy of those would hide the failure after resetFailures() just as #78 did.
        $parser = new class () extends AstParser {
            public int $parses = 0;

            public function parseCode(string $code, ?string $origin = null, ?callable $translateLine = null): array
            {
                $this->parses++;
                $ast = parent::parseCode($code, $origin, $translateLine);

                return $origin !== null && $this->hasFailure($origin) ? [new Stmt\Nop()] : $ast;
            }
        };

        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i(\n}\n");

        $this->assertNotEmpty($parser->parseFile($file));
        $parser->resetFailures();
        $this->assertNotEmpty($parser->parseFile($file));

        $this->assertSame(2, $parser->parses);
        $this->assertTrue($parser->hasFailure($file));
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

    // --- Recovering a partial AST from a file that would not parse ---

    /**
     * A missing semicolon: php-parser's error recovery rebuilds the whole class, including
     * the statement after the broken line.
     */
    private const MISSING_SEMICOLON = "<?php\nclass Broken\n{\n    public function index()\n    {\n        \$a = 1\n        \$this->render();\n    }\n}\n";

    /**
     * A method left unclosed: recovery has nothing it can rebuild and returns null.
     */
    private const MISSING_BRACE = "<?php\nclass B\n{\n    public function i(\n}\n";

    /**
     * Pin a file's mtime so a rewrite keeps the same AST cache key. Without this a rewrite
     * that happens to cross a second boundary would miss the cache by accident, and a test
     * asserting "not served from the cache" would pass for the wrong reason.
     */
    private function writeWithPinnedMtime(string $file, string $code): void
    {
        file_put_contents($file, $code);
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);
    }

    public function testParseFileRecoveringRebuildsAFileWithAMissingSemicolon(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $ast = $this->parser->parseFileRecovering($file);

        $this->assertNotEmpty($ast);
        $this->assertCount(1, $this->parser->findClasses($ast));
        $this->assertCount(1, $this->parser->findMethodCalls($ast, 'render'));
        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertSame([$file], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecordsExactlyWhatParseFileRecords(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $plain = new AstParser();
        $plain->parseFile($file);

        $this->parser->parseFileRecovering($file);

        $this->assertEquals($plain->failures(), $this->parser->failures());
        $this->assertSame(ParseFailureCause::SyntaxError, $this->parser->failures()[0]->cause);
    }

    public function testParseFileRecoveringDoesNotCacheARecoveredAst(): void
    {
        // Same mtime both times, so a cached copy would be served. A recovered AST is
        // partial, and caching it would hand the fix's author the broken file's AST.
        $file = $this->testDir . '/Broken.php';
        $this->writeWithPinnedMtime($file, self::MISSING_SEMICOLON);

        $this->assertCount(0, $this->parser->findMethodCalls($this->parser->parseFileRecovering($file), 'flush'));

        $this->writeWithPinnedMtime($file, str_replace('$a = 1', '$a = 1; $this->flush();', self::MISSING_SEMICOLON));

        $this->assertCount(1, $this->parser->findMethodCalls($this->parser->parseFileRecovering($file), 'flush'));
    }

    public function testParseFileRecoveringRecoversAgainOnEveryCall(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $first = $this->parser->parseFileRecovering($file);
        $second = $this->parser->parseFileRecovering($file);

        $this->assertNotEmpty($second);
        $this->assertEquals($first, $second);
        $this->assertCount(1, $this->parser->failures());
        $this->assertSame([$file], $this->parser->recoveries());
    }

    public function testParseFileRecoveringAnnotatesAFailureParseFileRecordedFirst(): void
    {
        // The failure is already in the log, so record() adds nothing this time. Recovery
        // must key on this parse having failed, not on the log having grown.
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $this->parser->parseFile($file);
        $this->assertSame([], $this->parser->recoveries());

        $this->assertNotEmpty($this->parser->parseFileRecovering($file));
        $this->assertCount(1, $this->parser->failures());
        $this->assertSame([$file], $this->parser->recoveries());
    }

    public function testParseFileRecoveringReturnsNothingWhenNothingCanBeRebuilt(): void
    {
        $file = $this->testDir . '/Unclosed.php';
        file_put_contents($file, self::MISSING_BRACE);

        $this->assertSame([], $this->parser->parseFileRecovering($file));
        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertSame(5, $this->parser->failures()[0]->line);
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseFileRecoveringTakesTheOrdinaryPathForACleanFile(): void
    {
        $file = $this->testDir . '/Good.php';
        $this->writeWithPinnedMtime($file, '<?php class Good { public function a() {} }');

        $plain = new AstParser();
        $ast = $this->parser->parseFileRecovering($file);

        $this->assertEquals($plain->parseFile($file), $ast);
        $this->assertSame([], $this->parser->failures());
        $this->assertSame([], $this->parser->recoveries());

        // Cached exactly as parseFile() caches it: same mtime, so the rewrite is not seen.
        $this->writeWithPinnedMtime($file, '<?php class Other {}');
        $this->assertSame($ast, $this->parser->parseFileRecovering($file));
    }

    public function testParseFileRecoveringTreatsAnEmptyFileAsAParse(): void
    {
        $file = $this->testDir . '/Empty.php';
        file_put_contents($file, '');

        $this->assertSame([], $this->parser->parseFileRecovering($file));
        $this->assertSame([], $this->parser->failures());
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecordsAMissingFileAsUnreadableOnce(): void
    {
        $absent = $this->testDir . '/absent.php';

        $this->assertSame([], $this->parser->parseFileRecovering($absent));
        $this->assertSame([], $this->parser->parseFileRecovering($absent));

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('File does not exist.', $failures[0]->message);
        $this->assertSame(ParseFailureCause::Unreadable, $failures[0]->cause);
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecordsADirectoryAsUnreadable(): void
    {
        $this->assertSame([], $this->parser->parseFileRecovering($this->testDir));

        $failures = $this->parser->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('Path is not a file.', $failures[0]->message);
        $this->assertSame(ParseFailureCause::Unreadable, $failures[0]->cause);
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseFileRecoveringIgnoresAStaleFailureForAFileThatNowParses(): void
    {
        // The log is run-scoped and keeps the first sighting, so the record from the broken
        // contents outlives the fix. A clean parse must not be annotated as a recovery.
        $file = $this->testDir . '/Fixed.php';
        $this->writeWithPinnedMtime($file, self::MISSING_BRACE);
        $this->parser->parseFile($file);

        $this->writeWithPinnedMtime($file, "<?php\nclass B\n{\n    public function i() { \$this->render(); }\n}\n");
        $this->parser->clearCache();

        $ast = $this->parser->parseFileRecovering($file);

        $this->assertCount(1, $this->parser->findMethodCalls($ast, 'render'));
        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecordsBothAgainAfterResetFailures(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $this->parser->parseFileRecovering($file);
        $this->parser->resetFailures();

        $this->assertNotEmpty($this->parser->parseFileRecovering($file));
        $this->assertCount(1, $this->parser->failures());
        $this->assertSame([$file], $this->parser->recoveries());
    }

    public function testParseFileRecoveringHonoursASubclassThatStubsParseFile(): void
    {
        // Consumers stub parseFile() in their own suites. The stub's answer is the answer:
        // nothing it did failed, so there is nothing to recover from.
        $parser = new class () extends AstParser {
            public function parseFile(string $filePath): array
            {
                return [new Stmt\Nop()];
            }
        };

        $file = $this->testDir . '/Unclosed.php';
        file_put_contents($file, self::MISSING_BRACE);

        $this->assertEquals([new Stmt\Nop()], $parser->parseFileRecovering($file));
        $this->assertSame([], $parser->failures());
        $this->assertSame([], $parser->recoveries());
    }

    public function testParseCodeRecoveringRebuildsCodeUnderItsOrigin(): void
    {
        $ast = $this->parser->parseCodeRecovering(self::MISSING_SEMICOLON, '/app/Broken.php');

        $this->assertCount(1, $this->parser->findMethodCalls($ast, 'render'));
        $this->assertSame(['/app/Broken.php'], array_column($this->parser->failures(), 'path'));
        $this->assertSame(['/app/Broken.php'], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringKeysAnOriginLessRecoveryByTheCodeHash(): void
    {
        $this->assertNotEmpty($this->parser->parseCodeRecovering(self::MISSING_SEMICOLON));

        $this->assertNull($this->parser->failures()[0]->path);
        $this->assertSame(['code:' . md5(self::MISSING_SEMICOLON)], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringRecordsExactlyWhatParseCodeRecordsWithATranslatedLine(): void
    {
        $calls = 0;
        $translate = function (int $line) use (&$calls): int {
            $calls++;

            return $line * 100;
        };

        $plain = new AstParser();
        $plain->parseCode(self::MISSING_SEMICOLON, '/resources/views/x.blade.php', fn (int $line) => $line * 100);

        $this->assertNotEmpty($this->parser->parseCodeRecovering(self::MISSING_SEMICOLON, '/resources/views/x.blade.php', $translate));

        $this->assertEquals($plain->failures(), $this->parser->failures());
        $this->assertSame(700, $this->parser->failures()[0]->line);
        $this->assertSame(1, $calls);
    }

    public function testParseCodeRecoveringDoesNotCallTheTranslatorOnASuccessfulParse(): void
    {
        $called = false;

        $ast = $this->parser->parseCodeRecovering("<?php\n\$a = 1;\n", '/resources/views/ok.blade.php', function (int $line) use (&$called) {
            $called = true;

            return $line;
        });

        $this->assertNotEmpty($ast);
        $this->assertFalse($called);
        $this->assertSame([], $this->parser->failures());
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringReturnsNothingWhenNothingCanBeRebuilt(): void
    {
        $this->assertSame([], $this->parser->parseCodeRecovering(self::MISSING_BRACE, '/app/Unclosed.php'));

        $this->assertTrue($this->parser->hasFailure('/app/Unclosed.php'));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringDoesNotAnnotateARecoveryThatRebuiltNoStatements(): void
    {
        // Recovery can succeed with nothing in it, as it does for a stray parenthesis.
        // Annotating that would tell a reporter something was salvaged when nothing was.
        $this->assertSame([], $this->parser->parseCodeRecovering('<?php )', '/app/Stray.php'));

        $this->assertTrue($this->parser->hasFailure('/app/Stray.php'));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringIgnoresAStaleFailureUnderItsOrigin(): void
    {
        $this->parser->parseCode(self::MISSING_BRACE, '/app/Fixed.php');

        $this->assertNotEmpty($this->parser->parseCodeRecovering('<?php class B {}', '/app/Fixed.php'));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testParseCodeRecoveringKeepsOneFailurePerKeyAcrossRepeatedCalls(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->parser->parseCodeRecovering(self::MISSING_SEMICOLON, '/app/Broken.php');
        }

        $this->assertCount(1, $this->parser->failures());
        $this->assertSame(['/app/Broken.php'], $this->parser->recoveries());
    }

    // --- Review round 1: recovery regressions ---

    /**
     * Analysed by PHPStan as well as run. Without @phpstan-impure on the recovering methods,
     * level 9 remembers recoveries() and hasFailure() across the call and reports the inner
     * branches as dead, so a consumer writing exactly this gets errors on correct code.
     */
    public function testStaticAnalysisSeesTheRecoveringMethodsChangeTheLogs(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);
        $reached = [];

        if ($this->parser->recoveries() === []) {
            $this->parser->parseFileRecovering($file);

            if ($this->parser->recoveries() !== []) {
                $reached[] = 'file';
            }
        }

        if (! $this->parser->hasFailure('/app/Code.php')) {
            $this->parser->parseCodeRecovering(self::MISSING_SEMICOLON, '/app/Code.php');

            if ($this->parser->hasFailure('/app/Code.php')) {
                $reached[] = 'code';
            }
        }

        $this->assertSame(['file', 'code'], $reached);
    }

    public function testStaticAnalysisSeesParsingThroughTheContractChangeTheLog(): void
    {
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_BRACE);

        $this->assertTrue(self::parsingRecordsAFailure($this->parser, $file));
        $this->assertTrue(self::parsingCodeRecordsAFailure(new AstParser(), self::MISSING_BRACE));
        $this->assertTrue(self::annotatingRecordsARecovery($this->parser, $file));
    }

    private static function annotatingRecordsARecovery(RecordingParserInterface $parser, string $file): bool
    {
        if ($parser->recoveries() === []) {
            $parser->recordRecovery($file);

            if ($parser->recoveries() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Typed against the contract, not the class: @phpstan-impure on AstParser alone does not
     * reach a caller holding a RecordingParserInterface.
     */
    private static function parsingRecordsAFailure(RecordingParserInterface $parser, string $file): bool
    {
        if (! $parser->hasFailure($file)) {
            $parser->parseFile($file);

            if ($parser->hasFailure($file)) {
                return true;
            }
        }

        return false;
    }

    private static function parsingCodeRecordsAFailure(RecordingParserInterface $parser, string $code): bool
    {
        if ($parser->failures() === []) {
            $parser->parseCode($code);

            if ($parser->failures() !== []) {
                return true;
            }
        }

        return false;
    }

    public function testARecoveryThatRebuiltOnlyAByteOrderMarkIsNotARecovery(): void
    {
        // The BOM before <?php is inline HTML. Rebuilding it salvages no PHP at all.
        $this->assertSame([], $this->parser->parseCodeRecovering("\xEF\xBB\xBF<?php )", '/app/Bom.php'));

        $this->assertTrue($this->parser->hasFailure('/app/Bom.php'));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testARecoveryThatRebuiltOnlyInlineHtmlIsNotARecovery(): void
    {
        $file = $this->testDir . '/view.php';
        file_put_contents($file, "<html>\n<?php )");

        $this->assertSame([], $this->parser->parseFileRecovering($file));

        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertSame([], $this->parser->recoveries());
    }

    public function testARecoveryThatRebuiltOnlyAnEmptyStatementIsNotARecovery(): void
    {
        $this->assertSame([], $this->parser->parseCodeRecovering('<?php /* note */ ; )', '/app/Nop.php'));

        $this->assertSame([], $this->parser->recoveries());
    }

    public function testInlineHtmlAlongsideRebuiltPhpIsKept(): void
    {
        $ast = $this->parser->parseCodeRecovering("<html>\n" . self::MISSING_SEMICOLON, '/app/Mixed.php');

        $this->assertInstanceOf(Stmt\InlineHTML::class, $ast[0]);
        $this->assertCount(1, $this->parser->findMethodCalls($ast, 'render'));
        $this->assertSame(['/app/Mixed.php'], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecoversTheCodeThatFailedNotTheFileOnDisk(): void
    {
        // Consumers substitute fixtures in parseCode() in their own suites. Recovery has to
        // work on the bytes that failed, or it analyses something nobody parsed.
        $parser = new class () extends AstParser {
            public function parseCode(string $code, ?string $origin = null, ?callable $translateLine = null): array
            {
                return parent::parseCode("<?php\n\$fixture = 1\n\$this->fromFixture();\n", $origin, $translateLine);
            }
        };

        $file = $this->testDir . '/Disk.php';
        file_put_contents($file, "<?php\n\$disk = 1\n\$this->fromDisk();\n");

        $ast = $parser->parseFileRecovering($file);

        $this->assertCount(1, $parser->findMethodCalls($ast, 'fromFixture'));
        $this->assertCount(0, $parser->findMethodCalls($ast, 'fromDisk'));
        $this->assertSame([$file], $parser->recoveries());
    }

    public function testParseFileRecoveringReadsTheFileOnce(): void
    {
        // A second read can return different bytes (a file rewritten mid-run, a stream that
        // is not a plain file), and then a clean parse would be recorded as a recovery.
        CountingStreamWrapper::register();
        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);

        $this->assertNotEmpty($this->parser->parseFileRecovering(CountingStreamWrapper::url($file)));

        $this->assertSame([$file => 1], CountingStreamWrapper::$opens);
        $this->assertSame([CountingStreamWrapper::url($file)], $this->parser->recoveries());
    }

    public function testParseFileRecoveringFollowsAPathTheSubclassNormalised(): void
    {
        // The failure is recorded under the spelling parseFile() actually parsed, so the
        // recovery has to be recorded under that same key rather than the caller's spelling.
        $parser = new class () extends AstParser {
            public function parseFile(string $filePath): array
            {
                return parent::parseFile(realpath($filePath) ?: $filePath);
            }
        };

        $file = $this->testDir . '/N.php';
        file_put_contents($file, self::MISSING_SEMICOLON);
        $real = (string) realpath($file);

        $ast = $parser->parseFileRecovering($this->testDir . '/./N.php');

        $this->assertCount(1, $parser->findClasses($ast));
        $this->assertTrue($parser->hasFailure($real));
        $this->assertSame([$real], $parser->recoveries());
    }

    public function testATranslatorThatParsesSomethingElseDoesNotDerailTheOuterRecovery(): void
    {
        // The translator runs inside the outer parse, before its failure is noted, so the
        // outer failure is the one the outer call recovers. A nested recovering call is
        // recovered and recorded on its own.
        $inner = "<?php\n\$inner = 1\n\$this->fromInner();\n";
        $parser = $this->parser;

        $ast = $parser->parseCodeRecovering(
            self::MISSING_SEMICOLON,
            '/app/Outer.php',
            function (int $line) use ($parser, $inner): int {
                $parser->parseCodeRecovering($inner, '/app/Inner.php');
                $parser->parseCode('<?php class Unclosed {', '/app/Unclosed.php');

                return $line;
            }
        );

        $this->assertCount(1, $parser->findMethodCalls($ast, 'render'));
        $this->assertCount(0, $parser->findMethodCalls($ast, 'fromInner'));
        $this->assertSame(['/app/Inner.php', '/app/Outer.php'], $parser->recoveries());
    }

    // --- Review round 2 ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerOnlyRecoveries(): iterable
    {
        yield 'declare' => ['<?php declare(strict_types=1); )'];
        yield 'empty declare block' => ['<?php declare(ticks=1) { } )'];
        yield 'namespace' => ['<?php namespace A; )'];
        yield 'braced namespace' => ['<?php namespace A { } )'];
        yield 'import' => ['<?php use A\B; )'];
        yield 'group import' => ['<?php use A\{B, C}; )'];
        yield 'halt compiler' => ['<?php ) __halt_compiler(); junk'];
        yield 'namespace holding only an import' => ['<?php namespace A; use B\C; )'];
    }

    /**
     * @dataProvider headerOnlyRecoveries
     */
    public function testARecoveryThatRebuiltOnlyFileHeadersIsNotARecovery(string $code): void
    {
        // Headers describe how the rest of the file is read; with the rest gone there is
        // nothing to analyse, and recording a recovery would claim otherwise.
        $this->assertSame([], $this->parser->parseCodeRecovering($code, '/app/Header.php'));

        $this->assertTrue($this->parser->hasFailure('/app/Header.php'));
        $this->assertSame([], $this->parser->recoveries());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codeInsideHeaders(): iterable
    {
        yield 'class in a namespace' => [
            "<?php namespace A; class K { public function a() { \$x = 1\n \$this->r(); } }",
            'r',
        ];
        yield 'statements in a declare block' => ["<?php declare(ticks=1) { \$x = 1\n \$this->r(); }", 'r'];
        yield 'statements after an import' => ["<?php use A\\B;\n\$x = 1\n\$this->r();\n", 'r'];
    }

    /**
     * @dataProvider codeInsideHeaders
     */
    public function testCodeRebuiltInsideAHeaderIsARecovery(string $code, string $method): void
    {
        $ast = $this->parser->parseCodeRecovering($code, '/app/Body.php');

        $this->assertCount(1, $this->parser->findMethodCalls($ast, $method));
        $this->assertSame(['/app/Body.php'], $this->parser->recoveries());
    }

    public function testParseFileRecoveringRecoversNothingFromAnUnclosedMethodBody(): void
    {
        $file = $this->testDir . '/UnclosedBody.php';
        file_put_contents($file, "<?php\nclass B\n{\n    public function i()\n    {\n        \$x = 1;\n}\n");

        $this->assertSame([], $this->parser->parseFileRecovering($file));
        $this->assertTrue($this->parser->hasFailure($file));
        $this->assertSame([], $this->parser->recoveries());
    }

    private function retainedFailure(AstParser $parser): mixed
    {
        return (new \ReflectionProperty(AstParser::class, 'lastFailure'))->getValue($parser);
    }

    public function testAPlainParseDoesNotRetainTheCodeThatFailed(): void
    {
        // Only a recovering call needs the failed bytes. A plain parse keeping them would pin
        // the last broken file's source in memory for the rest of the run.
        $this->parser->parseCode(self::MISSING_SEMICOLON, '/app/Big.php');
        $this->assertNull($this->retainedFailure($this->parser));

        $file = $this->testDir . '/Broken.php';
        file_put_contents($file, self::MISSING_SEMICOLON);
        $this->parser->parseFile($file);
        $this->assertNull($this->retainedFailure($this->parser));
    }

    public function testARecoveringCallLeavesNothingRetained(): void
    {
        $this->parser->parseCodeRecovering(self::MISSING_SEMICOLON, '/app/Broken.php');

        $this->assertNull($this->retainedFailure($this->parser));
    }

    public function testARecoveringCallThatThrowsLeavesNothingRetained(): void
    {
        // A subclass's parseFile() may throw after the parse failed. The failed bytes must not
        // outlive the call, and the next plain parse must not start retaining them either.
        $parser = new class () extends AstParser {
            public function parseFile(string $filePath): array
            {
                parent::parseCode("<?php\n\$a = 1\n\$this->x();\n", $filePath);

                throw new \RuntimeException('stubbed');
            }
        };

        try {
            $parser->parseFileRecovering('/app/Throws.php');
            $this->fail('The subclass exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('stubbed', $e->getMessage());
        }

        $this->assertNull($this->retainedFailure($parser));

        $parser->parseCode(self::MISSING_SEMICOLON, '/app/Later.php');
        $this->assertNull($this->retainedFailure($parser));
    }
}
