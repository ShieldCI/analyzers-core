<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\{Error, Node, NodeFinder, NodeTraverser, Parser, ParserFactory};
use PhpParser\Node\{Expr, Stmt};
use PhpParser\NodeVisitor\NameResolver;
use ShieldCI\AnalyzersCore\Contracts\ParserInterface;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\ValueObjects\ParseFailure;

/**
 * AST parser using nikic/php-parser.
 */
class AstParser implements ParserInterface
{
    private Parser $parser;
    private NodeFinder $nodeFinder;

    /** @var array<string, array<Node>> */
    private array $astCache = [];

    /**
     * Keyed by path (or by a hash of the code when no origin was supplied) so a file
     * re-parsed many times in one run is reported once. Consumers call clearCache()
     * per analyzer, which re-opens every file to a second parse attempt.
     *
     * @var array<string, ParseFailure>
     */
    private array $failures = [];

    /**
     * Paths a caller recovered usable syntax from after the parse failed, keyed the same
     * way as $failures so the two line up. Run-scoped with the failure log.
     *
     * @var array<string, true>
     */
    private array $recoveries = [];

    /**
     * @param  Parser|null  $parser  Defaults to the newest version the installed parser
     *                                supports. Injectable so a caller can pin a version,
     *                                which is the only way to exercise UnsupportedSyntax.
     */
    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function clearCache(): void
    {
        $this->astCache = [];
    }

    /**
     * Files handed to this parser that never produced an AST.
     *
     * @return list<ParseFailure>
     */
    public function failures(): array
    {
        return array_values($this->failures);
    }

    /**
     * Empty the failure log.
     *
     * Deliberately separate from clearCache(): the AST cache is drained once per
     * analyzer to bound memory, so a log tied to it would survive only until the next
     * analyzer started and would end up holding whatever the last one happened to hit.
     * This is run-scoped, and belongs to whatever orchestrates a run.
     */
    public function resetFailures(): void
    {
        $this->failures = [];
        $this->recoveries = [];
    }

    /**
     * Whether a failure is recorded under $path.
     *
     * Spell $path as parseFile() was given it, or as parseCode() was given its origin:
     * records are keyed on that string and neither side normalises, so a path spelled a
     * second way reports false rather than matching.
     *
     * This is the precondition a caller with a fallback wants. An empty AST is not: an
     * empty file parses successfully to no statements and records nothing, so branching on
     * the AST alone runs the fallback over every empty file in a project.
     */
    public function hasFailure(string $path): bool
    {
        return isset($this->failures[$path]);
    }

    /**
     * Note that a caller recovered usable syntax from a file that would not parse.
     *
     * Deliberately additive: the failure stands. Removing it would destroy a true fact —
     * the file did not parse — and the log cannot support the claim that would replace it.
     * It is keyed per file and shared by every caller, while record() keeps the first
     * sighting, so the entry under $path may belong to a different caller that really did
     * skip the file. Recovery is partial in any case: an error-recovering parser returns
     * the statements it could rebuild and drops the broken region, so the file is not
     * fully analysed even for the caller that recovered it.
     *
     * Holding both lets a reporter say "would not parse, partially recovered" instead of
     * choosing between a false skip and a false pass.
     *
     * @return bool Whether there was a failure under $path to annotate.
     */
    public function recordRecovery(string $path): bool
    {
        if (! isset($this->failures[$path])) {
            return false;
        }

        $this->recoveries[$path] = true;

        return true;
    }

    /**
     * Paths some caller recovered usable syntax from. Always a subset of failures().
     *
     * @return list<string>
     */
    public function recoveries(): array
    {
        return array_keys($this->recoveries);
    }

    /**
     * @return array<Node>
     */
    public function parseFile(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            $this->record($filePath, new ParseFailure(
                $filePath,
                null,
                $this->unreadableReason($filePath),
                ParseFailureCause::Unreadable,
            ));

            return [];
        }

        $mtime = filemtime($filePath);
        $cacheKey = $filePath.':'.($mtime !== false ? $mtime : 0);

        if (isset($this->astCache[$cacheKey])) {
            return $this->astCache[$cacheKey];
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            // @codeCoverageIgnoreStart
            // Unreachable in practice: is_file() and is_readable() above already
            // rejected the cases that make this fail. Recorded anyway so that no
            // path out of this class can drop a file without leaving evidence.
            $this->record($filePath, new ParseFailure(
                $filePath,
                null,
                'File could not be read.',
                ParseFailureCause::Unreadable,
            ));

            return [];
            // @codeCoverageIgnoreEnd
        }

        return $this->astCache[$cacheKey] = $this->parseCode($code, $filePath);
    }

    /**
     * Parse PHP code and return its AST, recording a failure if it cannot be parsed.
     *
     * ParserInterface declares this with $code alone; the extra parameters are optional
     * so that contract is untouched and external implementations keep working.
     *
     * @param  string|null  $origin  The path this code came from, when the caller knows it.
     *                                Required for the failure to name a file.
     * @param  (callable(int): int)|null  $translateLine  Maps a line in $code back to a line
     *        in its real source. A caller parsing generated code (a compiled template) knows
     *        the source path up front but cannot know which line will fail until it does, so
     *        this is invoked lazily — only on failure, and only when a line was reported.
     * @return array<Node>
     */
    public function parseCode(string $code, ?string $origin = null, ?callable $translateLine = null): array
    {
        try {
            $ast = $this->parser->parse($code);

            // A null return means the parser declined without raising an Error, which
            // cannot happen with the default throwing handler used here. It carries no
            // error object, so it is indistinguishable from a legitimately empty file —
            // recording it would report a failure for every empty source file.
            return $ast ?? [];
        } catch (Error $e) {
            // getStartLine() is -1 when the parser has no line for the error, and a
            // translator is caller-supplied code whose return is a claim rather than a
            // fact, so the result is normalised once, after both have had their say.
            $line = $e->getStartLine();

            if ($translateLine !== null && $line > 0) {
                $line = $translateLine($line);
            }

            $this->record($origin ?? 'code:' . md5($code), new ParseFailure(
                $origin,
                $line > 0 ? $line : null,
                $e->getRawMessage(),
                $this->classify($code),
            ));

            return [];
        }
    }

    /**
     * Separate broken code from code the pinned parser has not caught up with.
     *
     * The running PHP is the second opinion. token_get_all() with TOKEN_PARSE runs the
     * real compiler front end and throws on invalid syntax, so code it accepts while the
     * pinned parser rejects it is, by elimination, valid syntax the parser does not
     * implement — which is what a PHP runtime newer than the pinned parser looks like.
     *
     * It tokenises and parses only: nothing here executes or autoloads the code, and the
     * token array is discarded immediately rather than retained.
     *
     * The classification is relative to the runtime doing the parsing. A file using syntax
     * *newer* than the running PHP is rejected by both and reported as a genuine syntax
     * error. That is the right answer for the user — if their own PHP cannot parse it,
     * their application cannot run it — but it means the two causes are not absolute
     * properties of the file.
     */
    private function classify(string $code): ParseFailureCause
    {
        try {
            // Only the throw matters here, not the tokens. The result is still compared
            // rather than discarded because PHPStan's function.resultUnused rejects a
            // bare call, and a suppression would be worse than a comparison. The
            // comparison itself is logically unreachable; do not "simplify" it away.
            $acceptedByRuntime = token_get_all($code, TOKEN_PARSE) !== [];
        } catch (\CompileError) {
            $acceptedByRuntime = false;
        }

        return $acceptedByRuntime
            ? ParseFailureCause::UnsupportedSyntax
            : ParseFailureCause::SyntaxError;
    }

    /**
     * Why a path could not be read.
     *
     * Kept specific because this string reaches someone debugging their own path
     * configuration, where a directory and a missing file are different mistakes.
     */
    private function unreadableReason(string $filePath): string
    {
        if (! file_exists($filePath)) {
            return 'File does not exist.';
        }

        return is_file($filePath) ? 'File is not readable.' : 'Path is not a file.';
    }

    /**
     * Record a failure, keeping the first sighting of each key.
     */
    private function record(string $key, ParseFailure $failure): void
    {
        if (! isset($this->failures[$key])) {
            $this->failures[$key] = $failure;
        }
    }

    /**
     * Traverse AST with NameResolver to resolve fully qualified class names.
     *
     * After this, use `$node->getAttribute('resolvedName')` to get FQCNs
     * on Name nodes (class references, function calls, etc.).
     *
     * @param  array<Node>  $ast
     * @param  array<string, bool>  $options  Options passed to NameResolver (e.g., ['replaceNodes' => false])
     * @return array<Node>
     */
    public function resolveNames(array $ast, array $options = []): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, $options));

        return $traverser->traverse($ast);
    }

    /**
     * Collect all 1-indexed line numbers that fall inside any string literal node.
     *
     * Returns a hashmap of [lineNumber => true] for O(1) lookup. Use with
     * isset($result[$lineNumber]) to test whether a line is inside a string.
     *
     * @param  array<Node>  $ast
     * @return array<int, true>
     */
    public function collectStringLines(array $ast): array
    {
        $lines = [];

        $nodes = [
            ...$this->nodeFinder->findInstanceOf($ast, Node\Scalar\String_::class),
            ...$this->nodeFinder->findInstanceOf($ast, Node\Scalar\InterpolatedString::class),
        ];

        foreach ($nodes as $node) {
            for ($line = $node->getStartLine(); $line <= $node->getEndLine(); $line++) {
                $lines[$line] = true;
            }
        }

        return $lines;
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
