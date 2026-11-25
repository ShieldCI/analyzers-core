<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\{Error, ErrorHandler, NameContext, Node, NodeFinder, NodeTraverser, Parser, ParserFactory, PhpVersion};
use PhpParser\Node\{Expr, Stmt};
use PhpParser\NodeVisitor\NameResolver;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\ValueObjects\{ParseFailure, ParserCompatibility};

/**
 * AST parser using nikic/php-parser.
 */
class AstParser implements RecordingParserInterface
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
     * The last parse failure: the key it was recorded under and the code that failed.
     *
     * The *Recovering() methods clear this, parse, and take it back. They cannot read the log
     * instead: record() keeps the first sighting, so a file that failed earlier in the run
     * leaves the log unchanged when it fails again, and a record from contents since fixed
     * would claim a clean parse had failed. Left null by a parseFile() served from the cache,
     * by an unreadable path that never reached parseCode(), and by a subclass that stubs
     * either method -- none of which produced a failed parse to recover from.
     *
     * The code is kept so recovery works on the bytes that actually failed: a second read can
     * differ, and a subclass may have parsed something other than the file. The key is kept so
     * the recovery lands where the failure did, even if a subclass respelled the path. Set
     * only after a line translator has run, so a translator that parses something itself
     * cannot leave its own failure here in place of the outer one.
     *
     * Set only while a recovering call is in progress, and released when it ends, so a plain
     * parse never pins a broken file's source in memory for the rest of the run.
     *
     * @var array{key: string, code: string}|null
     */
    private ?array $lastFailure = null;

    /**
     * How many *Recovering() calls are in progress: more than one when a line translator
     * makes a recovering call of its own.
     */
    private int $recoveringDepth = 0;

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
     * Whether the parser this class builds by default can read the PHP it is running on.
     *
     * Static and derived, because it describes the installed library rather than any
     * instance: __construct() pins createForNewestSupportedVersion(), so the answer is the
     * same for every AstParser that did not have one injected, and the three throwaway
     * parsers built elsewhere in this package would surface an instance flag to nobody.
     *
     * It therefore says nothing about a parser a caller injected. That is deliberate --
     * injection exists so a caller can pin a version, which is the only way to exercise
     * UnsupportedSyntax -- so a pinned parser older than the runtime is a choice, not a
     * misconfiguration, and reporting it would make every such test emit a false alarm.
     *
     * Read "injected" to include a subclass that pins in its own constructor: this class is
     * not final, and a subclass calling parent::__construct() with a version-pinned parser
     * inherits a compatibility() that describes the default parser it never builds. That is
     * outside the guarantee, not a bug in it. Finality would state the same thing in the type
     * system, but consumers already subclass this to stub parseFile()/parseCode() in their own
     * suites, so the scope is documented here instead of enforced.
     *
     * The two arguments below are passed by name. They are both int and mutually assignable,
     * no CI leg where the parser has caught up to the runtime can tell a transposition from a
     * correct call, and a swap silently inverts every verdict this object produces.
     *
     * No message and no warning: what to say about this belongs to the consuming package,
     * for the same reason ParseFailureCause carries no label(). E_USER_WARNING in
     * particular would collide with failOnWarning in consumers' own suites.
     */
    public static function compatibility(): ParserCompatibility
    {
        return new ParserCompatibility(
            parserVersionId: PhpVersion::getNewestSupported()->id,
            runtimeVersionId: PhpVersion::getHostVersion()->id,
        );
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
     *
     * The two can be reset in either order, or independently: a failed parse is never
     * cached, so the next parse of a file that still does not parse records it again.
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
     * @phpstan-impure Writes the recovery log.
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
     * @phpstan-impure Writes the AST cache and the failure log, so two calls with the same
     *                 path need not agree, and the *Recovering() methods read state back.
     *
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

        $ast = $this->parseCode($code, $filePath);

        // A failed parse is not cached, so every parse of a broken file reaches parseCode()
        // and the failure log can be rebuilt after resetFailures() without clearCache()
        // first. Gated on the log rather than on an empty AST: parseFileRecovering() returns
        // a partial AST after this parse fails, and that must never be cached -- it would be
        // served to plain parseFile() callers as if the file had parsed -- while an empty
        // file parses cleanly and is.
        if (! isset($this->failures[$filePath])) {
            $this->astCache[$cacheKey] = $ast;
        }

        return $ast;
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
     *
     * @phpstan-impure Writes the failure log.
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

            $key = $origin ?? 'code:' . md5($code);
            if ($this->recoveringDepth > 0) {
                $this->lastFailure = ['key' => $key, 'code' => $code];
            }

            $this->record($key, new ParseFailure(
                $origin,
                $line > 0 ? $line : null,
                $e->getRawMessage(),
                $this->classify($code),
            ));

            return [];
        }
    }

    /**
     * parseFile(), falling back to an error-recovering parse when the file will not parse.
     *
     * For an analyzer that would rather see most of a broken file than none of it. Recovery
     * runs on this instance, with the parser it already holds, so the failure lands in the
     * same run-wide log as every other caller's and a reporter sees one record per file.
     *
     * A file that parses takes exactly the parseFile() path, cache included, and a subclass
     * that stubs parseFile() is honoured. Only when this call's parse failed is the code that
     * failed -- not a second read of the file -- parsed again with error recovery. The failure
     * is recorded exactly as parseFile() records it, and a result that rebuilt any PHP is
     * annotated with recordRecovery() under the key the failure was recorded under.
     *
     * A recovered AST is partial: php-parser drops the region it could not rebuild and may
     * leave Expr\Error nodes where an expression was missing. It is never cached, so each
     * call recovers afresh and plain parseFile() callers never receive it. The return value
     * does not say which kind it is, and hasFailure() and recoveries() cannot either: they
     * describe the run and keep the first sighting, so a file fixed mid-run, or code recorded
     * earlier under the same key, reports a failure next to a complete AST.
     *
     * Returns [] for an unreadable path, and for a file recovery rebuilt no PHP from: nothing,
     * or only inline HTML (a byte-order mark before <?php is one), empty statements and file
     * headers -- declare, namespace and use statements and __halt_compiler() -- with nothing
     * inside or after them. Those leave only the failure; handing them back would look like a
     * salvaged file.
     *
     * @phpstan-impure Writes the failure and recovery logs, like parseFile().
     *
     * @return array<Node>
     */
    public function parseFileRecovering(string $filePath): array
    {
        return $this->recovering(fn (): array => $this->parseFile($filePath));
    }

    /**
     * parseCode(), falling back to an error-recovering parse when the code will not parse.
     *
     * The twin of parseFileRecovering() for code that never was a file, such as a compiled
     * template, with the same semantics. The failure is keyed and recorded exactly as
     * parseCode() would, and so is the recovery: under $origin, or under 'code:' plus an md5
     * of $code when there is none.
     *
     * @phpstan-impure Writes the failure and recovery logs, like parseCode().
     *
     * @param  (callable(int): int)|null  $translateLine  As for parseCode().
     * @return array<Node>
     */
    public function parseCodeRecovering(string $code, ?string $origin = null, ?callable $translateLine = null): array
    {
        return $this->recovering(fn (): array => $this->parseCode($code, $origin, $translateLine));
    }

    /**
     * Run a strict parse with failure retention on, then recover the code if it failed.
     *
     * @param  callable(): array<Node>  $parse
     * @return array<Node>
     */
    private function recovering(callable $parse): array
    {
        $this->lastFailure = null;
        $this->recoveringDepth++;

        try {
            $ast = $parse();
        } catch (\Throwable $e) {
            $this->lastFailure = null;

            throw $e;
        } finally {
            $this->recoveringDepth--;
        }

        return $this->recoverIfFailed($ast);
    }

    /**
     * Hand back $ast if the parse that produced it did not fail, or recover the code that did.
     *
     * The collected errors are discarded: the failure is already recorded from the strict
     * parse, which reports the first error just as a reader of the file would meet it.
     *
     * @param  array<Node>  $ast
     * @return array<Node>
     */
    private function recoverIfFailed(array $ast): array
    {
        $failure = $this->lastFailure;
        $this->lastFailure = null;

        if ($failure === null) {
            return $ast;
        }

        $recovered = $this->parser->parse($failure['code'], new ErrorHandler\Collecting()) ?? [];

        if (! $this->rebuiltPhp($recovered)) {
            return [];
        }

        $this->recordRecovery($failure['key']);

        return $recovered;
    }

    /**
     * Whether $stmts hold anything to analyse beyond inline HTML, empty statements and file
     * headers. A header counts only for the code inside it: a namespace or declare block is
     * searched, an import or __halt_compiler() never counts on its own.
     *
     * @param  array<Node>  $stmts
     */
    private function rebuiltPhp(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_ || $stmt instanceof Stmt\Declare_) {
                if ($this->rebuiltPhp($stmt->stmts ?? [])) {
                    return true;
                }

                continue;
            }

            if (! $stmt instanceof Stmt\InlineHTML
                && ! $stmt instanceof Stmt\Nop
                && ! $stmt instanceof Stmt\Use_
                && ! $stmt instanceof Stmt\GroupUse
                && ! $stmt instanceof Stmt\HaltCompiler
            ) {
                return true;
            }
        }

        return false;
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
     * Never throws. php-parser reports two things while resolving: an import whose alias is
     * already taken (`use A\Clock; use B\Clock;`, compared case-insensitively except for
     * `use const` aliases, which are case-sensitive) and a qualified special class name such
     * as `\self`. Both are compile errors, not parse errors, so the file has a usable AST and
     * every other name in it can be resolved. Each is collected and dropped: a colliding import
     * keeps its first spelling, as PHP itself would read it, and resolution carries on past it.
     *
     * Catching a throw would not be equivalent. Traversal stops at the offending node, so every
     * name after it would be left unresolved. Nothing is written to failures() either: that log
     * is for files that produced no AST, and this one did.
     *
     * @param  array<Node>  $ast
     * @param  array<string, bool>  $options  Options passed to NameResolver (e.g., ['replaceNodes' => false])
     * @return array<Node>
     */
    public function resolveNames(array $ast, array $options = []): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(new ErrorHandler\Collecting(), $options));

        return $traverser->traverse($ast);
    }

    /**
     * The fully qualified class name (no leading `\`) that $name means at $at, or at $name
     * itself when $at is null, following PHP's rules for the file $ast.
     *
     * PHP applies an import from the point the file declares it, not across the enclosing
     * block, so given `namespace A; class C { D::class } use B\D; class E { D::class }` the
     * first reference is `A\D` and the second `B\D`. Each namespace statement starts afresh
     * with no imports. Aliases are compared case-insensitively, `use function` and `use const`
     * never affect a class name, and of two imports claiming one alias the first is kept, as
     * resolveNames() keeps it. A fully qualified name is returned as written and
     * `namespace\Foo` is resolved against the current namespace.
     *
     * A name that is itself part of a declaration is already fully qualified, so it is
     * returned as written rather than resolved against the namespace or the imports: a
     * namespace statement's name, the name a `use` imports, and a group use's prefix. A
     * group use item comes back joined to its prefix.
     *
     * $name is always read as a class reference. A function or constant name, such as a
     * FuncCall's, follows other rules (an unqualified one falls back to the global namespace
     * at run time), so passing one gives an answer PHP would not.
     *
     * Null for `self`, `static` and `parent` in any case, which need the enclosing class to
     * mean anything, and when $at (or $name) is not a node of $ast. In that case there is no
     * position to resolve at, and the imports in effect at the end of the file would be a
     * guess.
     *
     * Nothing is written to $ast: parseFile() hands back a shared, cached tree. Imports are
     * only legal at the top level and directly inside a namespace, so only those two levels
     * are walked for them, but finding the statement that holds the target searches its
     * subtree, so each call is O(file). A caller resolving many names in one file is better
     * served by resolveNames() on a tree of its own.
     *
     * @param  array<Node>  $ast
     */
    public function resolveClassName(array $ast, Node\Name $name, ?Node $at = null): ?string
    {
        if ($name->isSpecialClassName()) {
            return null;
        }

        // The collecting handler keeps the first of two colliding aliases instead of throwing.
        $context = new NameContext(new ErrorHandler\Collecting());
        $context->startNamespace();

        if (! $this->trackImportsUntil($ast, $at ?? $name, $context)) {
            return null;
        }

        return $this->declaredName($ast, $name)
            ?? $context->getResolvedClassName($name)->toString();
    }

    /**
     * Feed $context the namespace and imports in effect at $target, in file order, stopping
     * at the statement that is $target or contains it.
     *
     * The statement holding the target is not itself recorded, so a target inside a `use`
     * does not see that `use`'s own import.
     *
     * @param  array<Node>  $stmts
     * @return bool Whether $target was reached
     */
    private function trackImportsUntil(array $stmts, Node $target, NameContext $context): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $context->startNamespace($stmt->name);

                if ($stmt === $target || $stmt->name === $target
                    || $this->trackImportsUntil($stmt->stmts, $target, $context)) {
                    return true;
                }

                continue;
            }

            if ($this->nodeFinder->findFirst($stmt, static fn (Node $node): bool => $node === $target) !== null) {
                return true;
            }

            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $context->addAlias($use->name, $use->getAlias()->toString(), $stmt->type | $use->type, $use->getAttributes());
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                foreach ($stmt->uses as $use) {
                    $context->addAlias(
                        new Node\Name($stmt->prefix->toString().'\\'.$use->name->toString()),
                        $use->getAlias()->toString(),
                        $stmt->type | $use->type,
                        $use->getAttributes(),
                    );
                }
            }
        }

        return false;
    }

    /**
     * The name as declared, when $name is a namespace statement's name, the name a `use`
     * imports, or a group use's prefix or item (joined to the prefix).
     *
     * @param  array<Node>  $stmts
     */
    private function declaredName(array $stmts, Node\Name $name): ?string
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                if ($stmt->name === $name) {
                    return $name->toString();
                }

                $declared = $this->declaredName($stmt->stmts, $name);

                if ($declared !== null) {
                    return $declared;
                }
            } elseif ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    if ($use->name === $name) {
                        return $name->toString();
                    }
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                if ($stmt->prefix === $name) {
                    return $name->toString();
                }

                foreach ($stmt->uses as $use) {
                    if ($use->name === $name) {
                        return $stmt->prefix->toString().'\\'.$name->toString();
                    }
                }
            }
        }

        return null;
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
     * Matches both `->` and nullsafe `?->` calls.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findMethodCalls(array $ast, string $methodName): array
    {
        return $this->nodeFinder->find($ast, function (Node $node) use ($methodName) {
            if (! $node instanceof Expr\MethodCall && ! $node instanceof Expr\NullsafeMethodCall) {
                return false;
            }

            if (! $node->name instanceof Node\Identifier) {
                return false;
            }

            return $node->name->name === $methodName;
        });
    }

    /**
     * Find static calls by class and method name.
     *
     * Both names are matched case-insensitively, as PHP resolves them (ASCII case folding
     * only, like PHP's own). The class is compared by its name as written, ignoring a leading
     * `\`: `Hash::make()` and `\Hash::make()` match 'Hash', `Foo\Hash::make()` does not.
     *
     * A namespace-relative `namespace\Hash::make()` names `\Hash` only in the global namespace,
     * so it is not matched inside a named namespace (`namespace App;` / `namespace App { }`) and
     * is matched elsewhere. A sub-AST passed without its enclosing namespace statement has no
     * namespace context, so its namespace-relative names are treated as global.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findStaticCalls(array $ast, string $className, string $methodName): array
    {
        // Computed only when a namespace-relative class name matches, so a file without one
        // is walked once.
        $scoped = null;

        return $this->nodeFinder->find($ast, function (Node $node) use ($ast, $className, $methodName, &$scoped) {
            if (! $node instanceof Expr\StaticCall) {
                return false;
            }

            if (! $node->name instanceof Node\Identifier) {
                return false;
            }

            if (strcasecmp($node->name->name, $methodName) !== 0) {
                return false;
            }

            if (! $node->class instanceof Node\Name) {
                return false;
            }

            if (strcasecmp($node->class->toString(), $className) !== 0) {
                return false;
            }

            // A namespace-relative name inside a named namespace is not the global class
            // written after the prefix, so it is not matched.
            if ($node->class instanceof Node\Name\Relative) {
                $scoped ??= $this->namespaceScopedRelativeNames($ast);

                return ! isset($scoped[spl_object_id($node->class)]);
            }

            return true;
        });
    }

    /**
     * Find function calls by name.
     *
     * The name is matched case-insensitively, as PHP resolves function names (ASCII case
     * folding only, like PHP's own), as written ignoring a leading `\`. Calls through a variable
     * (`$f()`) are not matched.
     *
     * A namespace-relative `namespace\system()` calls `\system()` only in the global namespace,
     * so it is not matched inside a named namespace (`namespace App;` / `namespace App { }`) and
     * is matched elsewhere. A sub-AST passed without its enclosing namespace statement has no
     * namespace context, so its namespace-relative calls are treated as global.
     *
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function findFunctionCalls(array $ast, string $functionName): array
    {
        // Computed only when a namespace-relative name matches, so a file without one is
        // walked once.
        $scoped = null;

        return $this->nodeFinder->find($ast, function (Node $node) use ($ast, $functionName, &$scoped) {
            if (! $node instanceof Expr\FuncCall) {
                return false;
            }

            if (! $node->name instanceof Node\Name) {
                return false;
            }

            if (strcasecmp($node->name->toString(), $functionName) !== 0) {
                return false;
            }

            if ($node->name instanceof Node\Name\Relative) {
                $scoped ??= $this->namespaceScopedRelativeNames($ast);

                return ! isset($scoped[spl_object_id($node->name)]);
            }

            return true;
        });
    }

    /**
     * Object ids of the namespace-relative names (`namespace\Foo`) that sit inside a named
     * namespace, where they do not refer to the global `\Foo`.
     *
     * @param array<Node> $ast
     * @return array<int, true>
     */
    private function namespaceScopedRelativeNames(array $ast): array
    {
        $ids = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, Stmt\Namespace_::class) as $namespace) {
            if ($namespace->name === null) {
                continue;
            }

            foreach ($this->nodeFinder->findInstanceOf($namespace->stmts, Node\Name\Relative::class) as $name) {
                $ids[spl_object_id($name)] = true;
            }
        }

        return $ids;
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
     * Only double-quoted strings and heredocs that embed a variable or
     * expression parse to InterpolatedString nodes. Single-quoted strings,
     * nowdocs and escaped dollar signs parse to String_ literals, so text like
     * '$name' in them is never interpolated and is not reported.
     *
     * @param array<Node> $ast
     */
    public function hasVariableInterpolation(array $ast): bool
    {
        $interpolated = $this->nodeFinder->findFirst(
            $ast,
            fn (Node $node) => $node instanceof Node\Scalar\InterpolatedString
        );

        return $interpolated !== null;
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
