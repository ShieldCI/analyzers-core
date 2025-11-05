<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Contracts;

use PhpParser\Node;

/**
 * Interface for code parsing operations.
 */
interface ParserInterface
{
    /**
     * Parse a PHP file and return AST.
     *
     * @return array<Node>
     */
    public function parseFile(string $filePath): array;

    /**
     * Parse PHP code string and return AST.
     *
     * @return array<Node>
     */
    public function parseCode(string $code): array;

    /**
     * Find nodes of specific type.
     *
     * @param array<Node> $ast
     * @param class-string<Node> $nodeType
     * @return array<Node>
     */
    public function findNodes(array $ast, string $nodeType): array;

    /**
     * Find method calls by name.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findMethodCalls(array $ast, string $methodName): array;

    /**
     * Find static calls by class and method name.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findStaticCalls(array $ast, string $className, string $methodName): array;
}
