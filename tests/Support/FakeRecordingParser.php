<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Support;

use PhpParser\Node;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;
use ShieldCI\AnalyzersCore\ValueObjects\ParseFailure;

/**
 * A RecordingParserInterface that is not an AstParser.
 *
 * Two jobs. It proves the helpers depend on the contract rather than the concrete class -- if
 * one of them reaches for AstParser directly, a test using this fails. And it can be told to
 * throw, which AstParser never does (it records instead), so it is the only way to reach the
 * defensive fallbacks a third-party implementation makes possible.
 */
final class FakeRecordingParser implements RecordingParserInterface
{
    /** @var list<string> */
    public array $parsedPaths = [];

    /** @param array<Node> $ast The AST to hand back when not throwing. */
    public function __construct(
        private readonly ?\Throwable $throwOnParse = null,
        private readonly array $ast = [],
    ) {
    }

    /** @return array<Node> */
    public function parseFile(string $filePath): array
    {
        $this->parsedPaths[] = $filePath;

        if ($this->throwOnParse !== null) {
            throw $this->throwOnParse;
        }

        return $this->ast;
    }

    /** @return array<Node> */
    public function parseCode(string $code, ?string $origin = null, ?callable $translateLine = null): array
    {
        if ($this->throwOnParse !== null) {
            throw $this->throwOnParse;
        }

        return $this->ast;
    }

    /**
     * @param  array<Node>  $ast
     * @param  class-string<Node>  $nodeType
     * @return array<Node>
     */
    public function findNodes(array $ast, string $nodeType): array
    {
        return array_values(array_filter($ast, static fn (Node $n): bool => $n instanceof $nodeType));
    }

    /**
     * @param  array<Node>  $ast
     * @return array<Node>
     */
    public function findMethodCalls(array $ast, string $methodName): array
    {
        return [];
    }

    /**
     * @param  array<Node>  $ast
     * @return array<Node>
     */
    public function findStaticCalls(array $ast, string $className, string $methodName): array
    {
        return [];
    }

    /**
     * @param  array<Node>  $ast
     * @param  array<string, bool>  $options
     * @return array<Node>
     */
    public function resolveNames(array $ast, array $options = []): array
    {
        return $ast;
    }

    /**
     * @param  array<Node>  $ast
     * @return array<int, true>
     */
    public function collectStringLines(array $ast): array
    {
        return [];
    }

    /** @return list<ParseFailure> */
    public function failures(): array
    {
        return [];
    }

    public function hasFailure(string $path): bool
    {
        return false;
    }

    public function resetFailures(): void
    {
    }

    public function recordRecovery(string $path): bool
    {
        return false;
    }

    /** @return list<string> */
    public function recoveries(): array
    {
        return [];
    }
}
