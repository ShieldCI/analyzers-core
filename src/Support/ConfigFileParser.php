<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
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
     * A value is read only when the file alone decides it: string, int and float literals,
     * true/false/null, concatenation of literal strings, a sign on a literal number, and a
     * (bool)/(int)/(float)/(string) cast of any of those. isLiteral says whether that happened.
     * Anything else -- a constant, a class constant, __DIR__, an interpolated string, a call --
     * gives value null with isLiteral false, so `'key' => null` (isLiteral true) and "not
     * statically known" are different entries. A constant is never read as its own name.
     *
     * An env() call, also one wrapped in casts as Laravel 11+ ships `(bool) env('APP_DEBUG',
     * false)`, sets isEnvCall and is never literal. Its default argument, with the casts
     * applied, is read by the same rules into envDefault, and envDefaultIsLiteral separates
     * `env('X', null)` from a default that could not be read.
     *
     * An empty array means both "defines no string keys" and "would not parse". Ask the parser
     * you constructed this with which one happened.
     *
     * @return array<string, array{value: mixed, line: int, isEnvCall: bool, envDefault: mixed, envHasDefault: bool, isLiteral: bool, envDefaultIsLiteral: bool}>
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

            $isEnvCall = false;
            $envDefault = null;
            $envHasDefault = false;
            $envDefaultIsLiteral = false;
            [$isLiteral, $value] = self::readLiteral($item->value);

            // Peel the casts off first: `(bool) env(...)` is a Cast, and the env() call under it
            // is what decides the value at runtime.
            $casts = [];
            $node = $item->value;

            while ($node instanceof Cast) {
                $casts[] = $node;
                $node = $node->expr;
            }

            if ($node instanceof FuncCall
                && $node->name instanceof Name
                && $node->name->toString() === 'env'
            ) {
                $isEnvCall = true;
                $isLiteral = false;
                $value = null;

                if (isset($node->args[1])) {
                    $arg = $node->args[1];
                    if ($arg instanceof Node\Arg) {
                        $envHasDefault = true;
                        $default = self::readLiteral($arg->value);

                        foreach (array_reverse($casts) as $cast) {
                            $default = self::applyCast($cast, $default);
                        }

                        [$envDefaultIsLiteral, $envDefault] = $default;
                    }
                }
            }

            $result[$item->key->value] = [
                'value' => $value,
                'line' => $item->getStartLine(),
                'isEnvCall' => $isEnvCall,
                'envDefault' => $envDefault,
                'envHasDefault' => $envHasDefault,
                'isLiteral' => $isLiteral,
                'envDefaultIsLiteral' => $envDefaultIsLiteral,
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
     * Read the value an expression has from the file alone, as [isLiteral, value].
     *
     * The flag is the answer and the value only means something when it is true: a literal
     * null and an unread expression both carry null, so a caller that drops the flag is back
     * to not being able to tell them apart.
     *
     * @return array{bool, mixed}
     */
    private static function readLiteral(Node $node): array
    {
        if ($node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\LNumber
            || $node instanceof Node\Scalar\DNumber
        ) {
            return [true, $node->value];
        }

        if ($node instanceof ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => [true, true],
                'false' => [true, false],
                'null' => [true, null],
                default => self::unread(),
            };
        }

        if ($node instanceof Concat) {
            [$leftIsLiteral, $left] = self::readLiteral($node->left);
            [$rightIsLiteral, $right] = self::readLiteral($node->right);

            return $leftIsLiteral && $rightIsLiteral && is_string($left) && is_string($right)
                ? [true, $left.$right]
                : self::unread();
        }

        if ($node instanceof UnaryMinus || $node instanceof UnaryPlus) {
            [$isLiteral, $number] = self::readLiteral($node->expr);

            if (! $isLiteral || ! (is_int($number) || is_float($number))) {
                return self::unread();
            }

            return [true, $node instanceof UnaryMinus ? -$number : $number];
        }

        if ($node instanceof Cast) {
            return self::applyCast($node, self::readLiteral($node->expr));
        }

        return self::unread();
    }

    /**
     * Apply a scalar cast to an already-read value, as PHP would at runtime.
     *
     * (array), (object) and (unset) give no scalar, and a cast cannot make an unread value
     * read. An (int) cast of a float with no int value is left unread too: PHP 8.5 warns on
     * it, and an analyzer must not trip a warning over a config file.
     *
     * @param  array{bool, mixed}  $read
     * @return array{bool, mixed}
     */
    private static function applyCast(Cast $cast, array $read): array
    {
        [$isLiteral, $value] = $read;

        if (! $isLiteral || ! ($value === null || is_scalar($value))) {
            return self::unread();
        }

        if ($cast instanceof Cast\Int_
            && is_float($value)
            && ! ($value >= (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX)
        ) {
            return self::unread();
        }

        return match (true) {
            $cast instanceof Cast\Bool_ => [true, (bool) $value],
            $cast instanceof Cast\Int_ => [true, (int) $value],
            $cast instanceof Cast\Double => [true, (float) $value],
            $cast instanceof Cast\String_ => [true, (string) $value],
            default => self::unread(),
        };
    }

    /**
     * @return array{false, null}
     */
    private static function unread(): array
    {
        return [false, null];
    }
}
