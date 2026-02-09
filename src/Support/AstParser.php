<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\{Error, Node, NodeFinder, ParserFactory};
use PhpParser\Node\{Expr, Stmt};
use ShieldCI\AnalyzersCore\Contracts\ParserInterface;

/**
 * AST parser using nikic/php-parser.
 */
class AstParser implements ParserInterface
{
    private \PhpParser\Parser $parser;
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @return array<Node>
     */
    public function parseFile(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            return []; // @codeCoverageIgnore
        }

        return $this->parseCode($code);
    }

    /**
     * @return array<Node>
     */
    public function parseCode(string $code): array
    {
        try {
            $ast = $this->parser->parse($code);

            return $ast ?? [];
        } catch (Error $e) {
            return [];
        }
    }

    /**
     * @param array<Node> $ast
     * @param class-string<Node> $nodeType
     * @return array<Node>
     */
    public function findNodes(array $ast, string $nodeType): array
    {
        return $this->nodeFinder->findInstanceOf($ast, $nodeType);
    }

    /**
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findMethodCalls(array $ast, string $methodName): array
    {
        return $this->nodeFinder->find($ast, function (Node $node) use ($methodName) {
            if (! $node instanceof Expr\MethodCall) {
                return false;
            }

            if (! $node->name instanceof Node\Identifier) {
                return false;
            }

            return $node->name->name === $methodName;
        });
    }

    /**
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findStaticCalls(array $ast, string $className, string $methodName): array
    {
        return $this->nodeFinder->find($ast, function (Node $node) use ($className, $methodName) {
            if (! $node instanceof Expr\StaticCall) {
                return false;
            }

            if (! $node->name instanceof Node\Identifier) {
                return false;
            }

            if ($node->name->name !== $methodName) {
                return false;
            }

            // Check class name
            if ($node->class instanceof Node\Name) {
                return $node->class->toString() === $className;
            }

            return false;
        });
    }

    /**
     * Find function calls by name.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findFunctionCalls(array $ast, string $functionName): array
    {
        return $this->nodeFinder->find($ast, function (Node $node) use ($functionName) {
            if (! $node instanceof Expr\FuncCall) {
                return false;
            }

            if ($node->name instanceof Node\Name) {
                return $node->name->toString() === $functionName;
            }

            return false;
        });
    }

    /**
     * Find class definitions.
     *
     * @param array<Node> $ast
     * @return array<Stmt\Class_>
     */
    public function findClasses(array $ast): array
    {
        /** @var array<Stmt\Class_> */
        return $this->nodeFinder->findInstanceOf($ast, Stmt\Class_::class);
    }

    /**
     * Find method definitions in a class.
     *
     * @param array<Node> $ast
     * @return array<Stmt\ClassMethod>
     */
    public function findMethods(array $ast, ?string $className = null): array
    {
        if ($className === null) {
            /** @var array<Stmt\ClassMethod> */
            return $this->nodeFinder->findInstanceOf($ast, Stmt\ClassMethod::class);
        }

        $classes = $this->findClasses($ast);
        foreach ($classes as $class) {
            if ($class->name && $class->name->name === $className) {
                /** @var array<Stmt\ClassMethod> */
                return $this->nodeFinder->findInstanceOf([$class], Stmt\ClassMethod::class);
            }
        }

        return [];
    }

    /**
     * Check if code contains string concatenation.
     *
     * @param array<Node> $ast
     */
    public function hasStringConcatenation(array $ast): bool
    {
        $concat = $this->nodeFinder->findFirst($ast, fn (Node $node) => $node instanceof Expr\BinaryOp\Concat);

        return $concat !== null;
    }

    /**
     * Check if code contains variable interpolation in strings.
     *
     * @param array<Node> $ast
     */
    public function hasVariableInterpolation(array $ast): bool
    {
        // Check for InterpolatedString nodes (double-quoted strings with variables)
        $interpolated = $this->nodeFinder->findFirst(
            $ast,
            fn (Node $node) => $node instanceof Node\Scalar\InterpolatedString
        );

        if ($interpolated !== null) {
            return true;
        }

        // Also check regular strings that might contain interpolation syntax
        $strings = $this->nodeFinder->findInstanceOf($ast, Node\Scalar\String_::class);

        foreach ($strings as $string) {
            if (str_contains($string->value, '{$') || preg_match('/\$\w+/', $string->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find all variables used in the AST.
     *
     * @param array<Node> $ast
     * @return array<string>
     */
    public function findVariables(array $ast): array
    {
        $variables = $this->nodeFinder->findInstanceOf($ast, Expr\Variable::class);
        $names = [];

        foreach ($variables as $variable) {
            if (is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }

        return array_unique($names);
    }
}
