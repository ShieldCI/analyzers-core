<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PhpParser\Node\{Expr, Stmt};
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\AstParser;

class AstParserTest extends TestCase
{
    private AstParser $parser;
    private string $testDir;

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
            $files = array_diff(scandir($this->testDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($this->testDir . '/' . $file);
            }
            rmdir($this->testDir);
        }
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
}
