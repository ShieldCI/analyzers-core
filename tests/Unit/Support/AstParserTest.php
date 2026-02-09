<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PhpParser\Node\{Expr, Stmt};
use PHPUnit\Framework\TestCase;
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
    }

    public function testParseCodeReturnsEmptyArrayForEmptyCode(): void
    {
        $ast = $this->parser->parseCode('');

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
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
        // Test line 36: return [] when file_get_contents returns false
        // This is hard to simulate directly, but we can test with a directory
        // (file_get_contents on a directory returns false)
        $dir = $this->testDir . '/subdir';
        mkdir($dir);

        $ast = $this->parser->parseFile($dir);

        $this->assertIsArray($ast);
        $this->assertEmpty($ast);
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
}
