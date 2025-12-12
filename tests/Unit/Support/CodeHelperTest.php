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

    public function testCalculateComplexityWithNullCoalescing(): void
    {
        $code = '$value = $data ?? $default;';
        $complexity = CodeHelper::calculateComplexity($code);

        $this->assertGreaterThan(1, $complexity); // Base + null coalescing
    }

    public function testCalculateComplexityWithTernary(): void
    {
        $code = '$result = $condition ? $true : $false;';
        $complexity = CodeHelper::calculateComplexity($code);

        $this->assertGreaterThan(1, $complexity); // Base + ternary
    }

    public function testCalculateComplexityWithLogicalOperators(): void
    {
        $code = 'if ($a && $b || $c) { return true; }';
        $complexity = CodeHelper::calculateComplexity($code);

        $this->assertGreaterThan(1, $complexity); // Base + if + && + ||
    }

    public function testCalculateComplexityWithCatch(): void
    {
        $code = 'try { } catch (Exception $e) { }';
        $complexity = CodeHelper::calculateComplexity($code);

        $this->assertGreaterThan(1, $complexity); // Base + catch
    }

    public function testCalculateComplexityWithCase(): void
    {
        $code = 'switch ($x) { case 1: break; case 2: break; }';
        $complexity = CodeHelper::calculateComplexity($code);

        $this->assertGreaterThan(1, $complexity); // Base + 2 cases
    }

    public function testFindsDangerousFunctionsWithCaseVariations(): void
    {
        $code = 'EVAL($input); Exec("ls"); SYSTEM("rm");';
        $dangerous = CodeHelper::findDangerousFunctions($code);

        $this->assertContains('eval', $dangerous);
        $this->assertContains('exec', $dangerous);
        $this->assertContains('system', $dangerous);
    }

    public function testFindsDangerousFunctionsWithWhitespace(): void
    {
        $code = 'eval  ($input); exec  ("ls");';
        $dangerous = CodeHelper::findDangerousFunctions($code);

        $this->assertContains('eval', $dangerous);
        $this->assertContains('exec', $dangerous);
    }

    public function testFindsDangerousFunctionsReturnsEmptyForSafeCode(): void
    {
        $code = 'function safeFunction() { return true; }';
        $dangerous = CodeHelper::findDangerousFunctions($code);

        $this->assertEmpty($dangerous);
    }

    public function testLooksLikeSqlWithLowercase(): void
    {
        $this->assertTrue(CodeHelper::looksLikeSql('select * from users'));
        $this->assertTrue(CodeHelper::looksLikeSql('insert into table'));
    }

    public function testLooksLikeSqlWithMixedCase(): void
    {
        $this->assertTrue(CodeHelper::looksLikeSql('Select * From Users'));
        $this->assertTrue(CodeHelper::looksLikeSql('INSERT INTO table'));
    }

    public function testLooksLikeSqlWithPartialMatch(): void
    {
        // SELECT is contained in SELECTION, so it should match
        $this->assertTrue(CodeHelper::looksLikeSql('SELECTION process'));
        // This is the same string, so it should also match
        $this->assertTrue(CodeHelper::looksLikeSql('SELECTION process'));
    }

    public function testExtractStringLiteralsWithEscapedQuotes(): void
    {
        $code = '$x = "He said \"Hello\""; $y = \'It\\\'s great\';';
        $strings = CodeHelper::extractStringLiterals($code);

        $this->assertCount(2, $strings);
    }

    public function testExtractStringLiteralsWithNestedQuotes(): void
    {
        $code = '$x = "String with \'nested\' quotes";';
        $strings = CodeHelper::extractStringLiterals($code);

        // The regex extracts the content between quotes, so nested quotes are part of the string
        $this->assertGreaterThanOrEqual(1, count($strings));
    }

    public function testExtractStringLiteralsWithEmptyStrings(): void
    {
        $code = '$x = ""; $y = \'\';';
        $strings = CodeHelper::extractStringLiterals($code);

        $this->assertCount(2, $strings);
        $this->assertContains('', $strings);
    }

    public function testIsValidVariableNameWithUnderscore(): void
    {
        $this->assertTrue(CodeHelper::isValidVariableName('my_variable', 'snake_case'));
        $this->assertFalse(CodeHelper::isValidVariableName('my_variable', 'camelCase'));
    }

    public function testIsValidVariableNameWithNumbers(): void
    {
        $this->assertTrue(CodeHelper::isValidVariableName('var123', 'camelCase'));
        $this->assertTrue(CodeHelper::isValidVariableName('var_123', 'snake_case'));
    }

    public function testIsValidVariableNameWithLeadingUnderscore(): void
    {
        $this->assertFalse(CodeHelper::isValidVariableName('_private', 'camelCase'));
        $this->assertFalse(CodeHelper::isValidVariableName('_private', 'snake_case'));
    }

    public function testIsValidClassNameWithNumbers(): void
    {
        $this->assertTrue(CodeHelper::isValidClassName('Class123'));
        $this->assertFalse(CodeHelper::isValidClassName('123Class'));
    }

    public function testIsValidMethodNameWithNumbers(): void
    {
        $this->assertTrue(CodeHelper::isValidMethodName('method123'));
        $this->assertFalse(CodeHelper::isValidMethodName('Method123'));
    }

    public function testIsValidMethodNameWithUnderscore(): void
    {
        $this->assertFalse(CodeHelper::isValidMethodName('my_method'));
    }

    public function testCalculateSimilarityWithOneEmptyString(): void
    {
        $similarity = CodeHelper::calculateSimilarity('', 'test');
        $this->assertLessThan(100.0, $similarity);
        $this->assertGreaterThanOrEqual(0.0, $similarity);
    }

    public function testCalculateSimilarityWithVeryDifferentCode(): void
    {
        $code1 = 'function a() { return 1; }';
        $code2 = 'class B { public function c() { return 2; } }';
        $similarity = CodeHelper::calculateSimilarity($code1, $code2);

        // After normalization, some similarity may exist (e.g., "function", "return")
        $this->assertLessThan(100.0, $similarity);
        $this->assertGreaterThanOrEqual(0.0, $similarity);
    }

    public function testNormalizeCodeHandlesNestedComments(): void
    {
        $code = '/* Outer /* Inner */ comment */ $x = 42;';
        $normalized = CodeHelper::normalizeCode($code);

        $this->assertStringContainsString('$x = 42', $normalized);
    }

    public function testNormalizeCodePreservesCodeStructure(): void
    {
        $code = 'function test($x) { return $x; }';
        $normalized = CodeHelper::normalizeCode($code);

        $this->assertStringContainsString('function', $normalized);
        $this->assertStringContainsString('test', $normalized);
    }

    public function testCountTodoCommentsWithDifferentFormats(): void
    {
        $code = '@TODO Fix this
                 // TODO: Another
                 /* FIXME: Broken */
                 # HACK: Workaround
                 // XXX: Temporary';
        $count = CodeHelper::countTodoComments($code);

        $this->assertEquals(5, $count);
    }

    public function testExtractPhpDocCommentsWithMultipleDocs(): void
    {
        $code = '/**
                  * Class doc
                  */
                 class A {
                     /**
                      * Method doc
                      */
                     function b() {}
                 }';
        $docs = CodeHelper::extractPhpDocComments($code);

        $this->assertCount(2, $docs);
    }

    public function testExtractPhpDocCommentsReturnsEmptyForNoDocs(): void
    {
        $code = 'class A { function b() {} }';
        $docs = CodeHelper::extractPhpDocComments($code);

        $this->assertEmpty($docs);
    }

    public function testHasPhpDocWithDefaultType(): void
    {
        $code = '/** Doc */ $x = 42;';
        // Default type pattern matches any PHPDoc, so this should be true
        $this->assertTrue(CodeHelper::hasPhpDoc($code, 'default'));
    }

    public function testExtractMethodParametersWithDefaultValues(): void
    {
        $signature = 'function test($a = 1, $b = "test", $c = null)';
        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertCount(3, $params);
        $this->assertEquals('$a = 1', trim($params[0]));
    }

    public function testExtractMethodParametersWithTypeHints(): void
    {
        $signature = 'function test(string $name, int $age, ?bool $active)';
        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertCount(3, $params);
    }

    public function testExtractMethodParametersWithVariadic(): void
    {
        $signature = 'function test(...$args)';
        $params = CodeHelper::extractMethodParameters($signature);

        $this->assertCount(1, $params);
        $this->assertEquals('...$args', $params[0]);
    }
}
