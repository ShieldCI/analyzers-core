<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\CodeHelper;

class CodeHelperTest extends TestCase
{
    public function testCalculatesComplexity(): void
    {
        $simpleCode = 'function test() { return true; }';
        $complexCode = '
            function test($x) {
                if ($x > 0) {
                    while ($x > 10) {
                        for ($i = 0; $i < 5; $i++) {
                            echo $i;
                        }
                    }
                } else if ($x < 0) {
                    return false;
                }
                return true;
            }
        ';

        $this->assertEquals(1, CodeHelper::calculateComplexity($simpleCode));
        $this->assertGreaterThan(1, CodeHelper::calculateComplexity($complexCode));
    }

    public function testFindsDangerousFunctions(): void
    {
        $code = '
            $result = eval($input);
            exec("ls -la");
            $data = unserialize($_POST["data"]);
        ';

        $dangerous = CodeHelper::findDangerousFunctions($code);

        $this->assertContains('eval', $dangerous);
        $this->assertContains('exec', $dangerous);
        $this->assertContains('unserialize', $dangerous);
    }

    public function testDetectsSqlQueries(): void
    {
        $this->assertTrue(CodeHelper::looksLikeSql('SELECT * FROM users'));
        $this->assertTrue(CodeHelper::looksLikeSql('INSERT INTO table VALUES'));
        $this->assertFalse(CodeHelper::looksLikeSql('Just a normal string'));
    }

    public function testValidatesVariableNames(): void
    {
        $this->assertTrue(CodeHelper::isValidVariableName('camelCase', 'camelCase'));
        $this->assertTrue(CodeHelper::isValidVariableName('snake_case', 'snake_case'));
        $this->assertTrue(CodeHelper::isValidVariableName('PascalCase', 'PascalCase'));

        $this->assertFalse(CodeHelper::isValidVariableName('PascalCase', 'camelCase'));
        $this->assertFalse(CodeHelper::isValidVariableName('123invalid', 'camelCase'));
    }

    public function testValidatesClassNames(): void
    {
        $this->assertTrue(CodeHelper::isValidClassName('MyClass'));
        $this->assertTrue(CodeHelper::isValidClassName('UserController'));

        $this->assertFalse(CodeHelper::isValidClassName('myClass'));
        $this->assertFalse(CodeHelper::isValidClassName('my_class'));
    }

    public function testValidatesMethodNames(): void
    {
        $this->assertTrue(CodeHelper::isValidMethodName('myMethod'));
        $this->assertTrue(CodeHelper::isValidMethodName('__construct'));

        $this->assertFalse(CodeHelper::isValidMethodName('MyMethod'));
        $this->assertFalse(CodeHelper::isValidMethodName('my_method'));
    }

    public function testCountsTodoComments(): void
    {
        $code = '
            // TODO: Fix this
            /* FIXME: Broken code */
            // NOTE: Important
        ';

        $count = CodeHelper::countTodoComments($code);
        $this->assertEquals(3, $count);
    }

    public function testExtractsPhpDocComments(): void
    {
        $code = '
            /**
             * This is a class.
             */
            class MyClass {
                /**
                 * This is a method.
                 */
                public function myMethod() {}
            }
        ';

        $docs = CodeHelper::extractPhpDocComments($code);
        $this->assertCount(2, $docs);
    }

    public function testExtractStringLiterals(): void
    {
        $code = "<?php \$x = 'single'; \$y = \"double\"; \$z = 'escaped\\'quote';";

        $strings = CodeHelper::extractStringLiterals($code);

        $this->assertCount(3, $strings);
        $this->assertContains('single', $strings);
        $this->assertContains('double', $strings);
    }

    public function testExtractStringLiteralsReturnsEmptyArrayWhenNoStrings(): void
    {
        $code = '<?php $x = 42;';

        $strings = CodeHelper::extractStringLiterals($code);

        $this->assertIsArray($strings);
        $this->assertEmpty($strings);
    }

    public function testIsValidVariableNameWithInvalidConvention(): void
    {
        $this->assertFalse(CodeHelper::isValidVariableName('anything', 'invalid_convention'));
    }

    public function testCalculateSimilarityForIdenticalCode(): void
    {
        $code = 'function test() { return true; }';

        $similarity = CodeHelper::calculateSimilarity($code, $code);

        $this->assertEquals(100.0, $similarity);
    }

    public function testCalculateSimilarityForDifferentCode(): void
    {
        $code1 = 'function test() { return true; }';
        $code2 = 'function demo() { return false; }';

        $similarity = CodeHelper::calculateSimilarity($code1, $code2);

        $this->assertLessThan(100.0, $similarity);
        $this->assertGreaterThan(0.0, $similarity);
    }

    public function testCalculateSimilarityForEmptyStrings(): void
    {
        $similarity = CodeHelper::calculateSimilarity('', '');

        $this->assertEquals(100.0, $similarity);
    }

    public function testNormalizeCodeRemovesComments(): void
    {
        $code = '<?php
            // Single line comment
            /* Multi-line
               comment */
            $x = 42;
        ';

        $normalized = CodeHelper::normalizeCode($code);

        $this->assertStringNotContainsString('//', $normalized);
        $this->assertStringNotContainsString('/*', $normalized);
        $this->assertStringContainsString('$x = 42', $normalized);
    }

    public function testNormalizeCodeRemovesExtraWhitespace(): void
    {
        $code = "<?php\n\n\n    \$x    =     42;    ";

        $normalized = CodeHelper::normalizeCode($code);

        $this->assertStringNotContainsString('    ', $normalized);
        $this->assertEquals('<?php $x = 42;', $normalized);
    }

    public function testHasPhpDocForClass(): void
    {
        $codeWithDoc = '
            /**
             * Class description
             */
            class MyClass {}
        ';

        $codeWithoutDoc = '
            class MyClass {}
        ';

        $this->assertTrue(CodeHelper::hasPhpDoc($codeWithDoc, 'class'));
        $this->assertFalse(CodeHelper::hasPhpDoc($codeWithoutDoc, 'class'));
    }

    public function testHasPhpDocForMethod(): void
    {
        $codeWithDoc = '
            /**
             * Method description
             */
            public function myMethod() {}
        ';

        $codeWithoutDoc = '
            public function myMethod() {}
        ';

        $this->assertTrue(CodeHelper::hasPhpDoc($codeWithDoc, 'method'));
        $this->assertFalse(CodeHelper::hasPhpDoc($codeWithoutDoc, 'method'));
    }

    public function testHasPhpDocForProperty(): void
    {
        $codeWithDoc = '
            /**
             * Property description
             */
            private $myProperty;
        ';

        $codeWithoutDoc = '
            private $myProperty;
        ';

        $this->assertTrue(CodeHelper::hasPhpDoc($codeWithDoc, 'property'));
        $this->assertFalse(CodeHelper::hasPhpDoc($codeWithoutDoc, 'property'));
    }

    public function testExtractMethodParameters(): void
    {
        $signature = 'function test($param1, $param2, $param3)';

        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertCount(3, $params);
        $this->assertEquals('$param1', $params[0]);
        $this->assertEquals('$param2', $params[1]);
        $this->assertEquals('$param3', $params[2]);
    }

    public function testExtractMethodParametersWithTypes(): void
    {
        $signature = 'function test(string $name, int $age, bool $active)';

        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertCount(3, $params);
    }

    public function testExtractMethodParametersReturnsEmptyArrayForNoParams(): void
    {
        $signature = 'function test()';

        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

    public function testExtractMethodParametersReturnsEmptyArrayForInvalidSignature(): void
    {
        $signature = 'not a valid signature';

        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

    public function testCountMethodParameters(): void
    {
        $signature1 = 'function test($a, $b, $c)';
        $signature2 = 'function test()';

        $this->assertEquals(3, CodeHelper::countMethodParameters($signature1));
        $this->assertEquals(0, CodeHelper::countMethodParameters($signature2));
    }
}
