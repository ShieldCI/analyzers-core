<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;

/**
 * Reads what a `return [...]` config file actually says, over a parser you keep.
 *
 * Both answers this class gives are absences: no such key, no such nested key. An unparseable
 * file produces the same absence, and a caller that cannot tell the two apart reports a broken
 * config as a config that simply does not set the thing -- which is the quiet version of the
 * bug, because it looks like a finding rather than an error.
 *
 * The parser is a constructor dependency rather than a trailing argument for exactly that
 * reason. An optional parameter makes the evidence opt-in, so the default stays silent and
 * every new method has to remember to offer it again; a constructor makes it the only mode.
 * After a call, ask the parser you passed:
 *
 *     $parser = new AstParser();
 *     $config = (new ConfigFileParser($parser))->parseArray($path);
 *
 *     if ($parser->hasFailure($path)) {
 *         // would not parse -- not "defines no keys"
 *     }
 *
 * Typed to RecordingParserInterface rather than AstParser so a consumer can pass the parser it
 * already holds. It needs parseFile() and a readable failure log, and nothing else.
 *
 * Separate from ConfigFileHelper by subject, not by accident: that class answers path, line and
 * text questions and touches no AST, and this one only ever reads one. Keeping the split means
 * ConfigFileHelper imports no php-parser at all.
 */
final class ConfigFileParser
{
    private readonly NodeFinder $nodeFinder;

    public function __construct(private readonly RecordingParserInterface $parser)
    {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Extract top-level string key-value pairs from a config file that returns an array.
     *
     * Handles value types: String_, LNumber, DNumber, ConstFetch (true/false/null), FuncCall
     * (env()). When a value is an env() call, isEnvCall is set to true and the default argument
     * (if any) is captured in envDefault.
     *
     * An empty array means both "defines no string keys" and "would not parse". Ask the parser
     * you constructed this with which one happened.
     *
     * @return array<string, array{value: mixed, line: int, isEnvCall: bool, envDefault: mixed, envHasDefault: bool}>
     */
    public function parseArray(string $filePath): array
    {
        $array = $this->returnedArray($filePath);

        if (! $array instanceof Array_) {
            return [];
        }

        $result = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = $item->key->value;
            $line = $item->getStartLine();
            $isEnvCall = false;
            $envDefault = null;
            $envHasDefault = false;
            $value = self::extractNodeValue($item->value);

            if ($item->value instanceof FuncCall
                && $item->value->name instanceof Name
                && $item->value->name->toString() === 'env'
            ) {
                $isEnvCall = true;
                $value = null;

                if (isset($item->value->args[1])) {
                    $arg = $item->value->args[1];
                    if ($arg instanceof Node\Arg) {
                        $envHasDefault = true;
                        $envDefault = self::extractNodeValue($arg->value);
                    }
                }
            }

            $result[$key] = [
                'value' => $value,
                'line' => $line,
                'isEnvCall' => $isEnvCall,
                'envDefault' => $envDefault,
                'envHasDefault' => $envHasDefault,
            ];
        }

        return $result;
    }

    /**
     * Line of a direct child key inside a top-level array key, or null.
     *
     * Only authored entries are found -- the key has to appear in the file, not merely be
     * present in the merged runtime config -- so callers can tell an entry somebody wrote from
     * one a package injects at runtime and that never appears in the file.
     *
     * Example: findNestedArrayKeyLine('config/logging.php', 'channels', 'single') returns the
     * line of `'single' => [` within `'channels' => [...]`.
     *
     * Null means both "no such authored key" and "would not parse". Ask the parser you
     * constructed this with which one happened.
     *
     * The full name is deliberate. ConfigFileHelper::findNestedKeyLine() is a different
     * question answered by scanning text, and the two must not read as variants of each other.
     *
     * @return int|null 1-indexed line number
     */
    public function findNestedArrayKeyLine(string $filePath, string $parentKey, string $childKey): ?int
    {
        $array = $this->returnedArray($filePath);

        if (! $array instanceof Array_) {
            return null;
        }

        $parentArray = self::findArrayItemValue($array, $parentKey);

        if (! $parentArray instanceof Array_) {
            return null;
        }

        foreach ($parentArray->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            if ($item->key->value === $childKey) {
                return $item->getStartLine();
            }
        }

        return null;
    }

    /**
     * The array literal a config file returns, or null when there is not one to read.
     *
     * Null covers three different situations on purpose -- the file did not parse, it has no
     * return statement, or it returns something other than an array -- because no caller here
     * can act differently on any of them. The one distinction that does matter, "did not
     * parse" versus "parsed and had nothing", is not made here: it is on the parser's failure
     * log, which outlives this call.
     */
    private function returnedArray(string $filePath): ?Array_
    {
        $ast = $this->parser->parseFile($filePath);

        if ($ast === []) {
            return null;
        }

        $returnNode = $this->nodeFinder->findFirstInstanceOf($ast, Return_::class);

        if (! $returnNode instanceof Return_ || ! $returnNode->expr instanceof Array_) {
            return null;
        }

        return $returnNode->expr;
    }

    /**
     * Return the value node of the array item with the given string key, or null.
     */
    private static function findArrayItemValue(Array_ $array, string $key): ?Node
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            if ($item->key->value === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * Extract a typed PHP value from an AST node.
     *
     * Returns actual PHP scalars (string, int, float, bool, null) for simple
     * literal nodes. Returns null for complex expressions.
     */
    private static function extractNodeValue(Node $node): mixed
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\LNumber || $node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $node->name->toString(),
            };
        }

        return null;
    }
}
