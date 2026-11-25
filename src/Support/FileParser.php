<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use ShieldCI\AnalyzersCore\ValueObjects\CodeSnippet;

/**
 * Utility for parsing and analyzing file contents.
 */
class FileParser
{
    /**
     * A namespace name: identifier segments joined by backslashes. Matched against token text
     * because a segment may be a reserved word (`namespace Match;`), which lexes as that
     * keyword's token rather than T_STRING.
     */
    private const NAMESPACE_NAME = '/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*$/';

    /**
     * One member of a normalized import body: an optionally fully qualified name and an
     * optional `as` alias. Group 1 is the name without its leading backslash, group 2 the alias.
     * Neither may be the bare reserved word `as` or `insteadof` (any case); a qualified name
     * may still contain one as a segment (`As\Foo`).
     */
    private const IMPORT_MEMBER = '/^\\\\?(?!(?:as|insteadof)(?: |$))([A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*)(?: as (?!(?:as|insteadof)$)([A-Za-z_\x80-\xff][\w\x80-\xff]*))?$/i';

    /**
     * A normalized group import body, `Prefix\{members}`. Group 1 is the prefix without its
     * leading backslash, group 2 the members.
     */
    private const IMPORT_GROUP = '/^\\\\?([A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*)\\\\\{(.*)\}$/';

    /**
     * Extract namespace from PHP file.
     *
     * Reads the first named `namespace` declaration from the tokenized source, so the word
     * "namespace" in a comment, string, heredoc or `namespace\relative` name is ignored.
     * Handles `namespace App;`, `namespace App ?>` and the braced `namespace App { ... }`
     * form, including reserved-word names such as `namespace Match;`; a global
     * `namespace { ... }` block declares no name and is skipped.
     */
    public static function extractNamespace(string $filePath): ?string
    {
        return self::findNamespace(self::tokenizeIfMentions(self::readFile($filePath), 'namespace'));
    }

    /**
     * extractNamespace() for source already in hand, which may be a fragment without an open
     * tag (see tokenizeSource()).
     */
    public static function extractNamespaceFromSource(string $code): ?string
    {
        return self::findNamespace(self::tokenizeSource($code, 'namespace'));
    }

    /**
     * Extract class name from PHP file.
     *
     * Returns the name of the first `class` declaration in the tokenized source. The word
     * "class" in a comment, string or heredoc is ignored, as are `Foo::class` and anonymous
     * `new class` expressions. Interfaces, traits and enums are not considered; for every
     * declaration of any kind use extractClassLikes().
     */
    public static function extractClassName(string $filePath): ?string
    {
        return self::className(self::tokenizeIfMentions(self::readFile($filePath), 'class'));
    }

    /**
     * extractClassName() for source already in hand, which may be a fragment without an open
     * tag (see tokenizeSource()).
     */
    public static function extractClassNameFromSource(string $code): ?string
    {
        return self::className(self::tokenizeSource($code, 'class'));
    }

    /**
     * Get fully qualified class name from file.
     *
     * Qualifies the class from extractClassName() with the namespace declaration that encloses
     * it, which in a multi-namespace file need not be the first one; a class inside a global
     * `namespace { ... }` block is returned unqualified.
     */
    public static function extractFullyQualifiedClassName(string $filePath): ?string
    {
        // Tokenize once for both lookups; any file declaring a class mentions "class".
        return self::fullyQualifiedClassName(self::tokenizeIfMentions(self::readFile($filePath), 'class'));
    }

    /**
     * extractFullyQualifiedClassName() for source already in hand, which may be a fragment
     * without an open tag (see tokenizeSource()).
     */
    public static function extractFullyQualifiedClassNameFromSource(string $code): ?string
    {
        return self::fullyQualifiedClassName(self::tokenizeSource($code, 'class'));
    }

    /**
     * Extract use statements from file.
     *
     * Returns the trimmed source between `use` and `;` (or `?>`) for every import and trait `use`
     * statement, e.g. `Foo\Bar as Baz`, `function Foo\bar`, `Foo\{Bar, Baz}` or
     * `HasFactory`. A trait `use` with a conflict-resolution block stops at its `{`
     * (`use A, B { ... }` gives `A, B`); a rule in the block that starts with a trait method
     * named `use` (`use as protected foo;`) is not read as a statement. Each comment inside a statement
     * becomes a single space. Only a `use` that starts a statement counts (one after a label,
     * `foo: use A\B;`, does), so the word "use" in a comment, string or heredoc, a closure's
     * `use (...)` clause, and `use` as a name (`const USE`, `K::USE`, `foo(use: 1)`,
     * `namespace Use;`) are all ignored.
     *
     * For namespace-level class imports only, resolved to alias => class, use extractImports().
     *
     * @return array<string>
     */
    public static function extractUseStatements(string $filePath): array
    {
        return self::useStatements(self::tokenizeIfMentions(self::readFile($filePath), 'use'));
    }

    /**
     * extractUseStatements() for source already in hand, which may be a fragment without an
     * open tag (see tokenizeSource()).
     *
     * @return array<string>
     */
    public static function extractUseStatementsFromSource(string $code): array
    {
        return self::useStatements(self::tokenizeSource($code, 'use'));
    }

    /**
     * Extract the namespace-level class imports of a file, as alias => fully qualified name.
     *
     * Reads every `use` statement outside any class, interface, trait, enum or function body,
     * so trait `use` and a closure's `use (...)` clause are excluded. Comma lists and group
     * imports are expanded (`use App\{Models\User, Http\Kernel as K};` gives
     * `User => App\Models\User` and `K => App\Http\Kernel`), an `as` alias is honoured and the
     * implicit alias is the last name segment. Names carry no leading backslash; comments
     * inside a statement are ignored. `use function` and `use const` statements are skipped,
     * as are the `function` / `const` members of a mixed group.
     *
     * Keys are aliases as written. When two imports claim the same alias case-insensitively,
     * the first one wins, as in AstParser::resolveClassName(). The imports of every namespace
     * in a multi-namespace file are merged in source order under the same first-wins rule, so
     * an alias is not resolved per namespace. A statement that cannot be read whole (a stray
     * token, an unclosed group, no terminating `;` or `?>`) is skipped; nothing throws.
     *
     * @return array<string, string>
     */
    public static function extractImports(string $filePath): array
    {
        return self::imports(self::tokenizeIfMentions(self::readFile($filePath), 'use'));
    }

    /**
     * extractImports() for source already in hand, which may be a fragment without an open
     * tag (see tokenizeSource()).
     *
     * @return array<string, string>
     */
    public static function extractImportsFromSource(string $code): array
    {
        return self::imports(self::tokenizeSource($code, 'use'));
    }

    /**
     * Extract every named class, interface, trait and enum declaration of a file, in source
     * order.
     *
     * Each name is qualified with the namespace declaration that encloses it (no leading
     * backslash); one in the global namespace or a global `namespace { ... }` block is
     * unqualified. Declarations nested in a function, `if`, `switch` or alternative-syntax
     * block are conditional declarations and are included. Modifiers and attributes
     * (`final`, `abstract`, `readonly`, `#[...]`) do not affect the result. Ignored: the
     * words in comments, strings and heredocs, `Foo::class`, anonymous `new class`, and
     * `enum` used as a name (`function enum()`, `$x->enum`, `Enum::X`), which PHP lexes as
     * T_STRING except before a name.
     *
     * @return list<array{name: string, kind: 'class'|'interface'|'trait'|'enum'}>
     */
    public static function extractClassLikes(string $filePath): array
    {
        return self::classLikes(self::tokenizeIfMentions(self::readFile($filePath), 'class', 'interface', 'trait', 'enum'));
    }

    /**
     * extractClassLikes() for source already in hand, which may be a fragment without an open
     * tag (see tokenizeSource()).
     *
     * @return list<array{name: string, kind: 'class'|'interface'|'trait'|'enum'}>
     */
    public static function extractClassLikesFromSource(string $code): array
    {
        return self::classLikes(self::tokenizeSource($code, 'class', 'interface', 'trait', 'enum'));
    }

    /**
     * Name of the first `class` declaration.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function className(array $tokens): ?string
    {
        $name = self::findClassNameIndex($tokens);

        return $name === null ? null : $tokens[$name]->text;
    }

    /**
     * Name of the first `class` declaration, qualified with its enclosing namespace.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function fullyQualifiedClassName(array $tokens): ?string
    {
        $name = self::findClassNameIndex($tokens);
        if ($name === null) {
            return null;
        }

        $className = $tokens[$name]->text;
        $namespace = self::enclosingNamespace($tokens, $name);
        if ($namespace === null) {
            return $className;
        }

        return "{$namespace}\\{$className}";
    }

    /**
     * @param  array<int, \PhpToken>  $tokens
     * @return array<string>
     */
    private static function useStatements(array $tokens): array
    {
        $statements = [];

        // Index-based so the walk resumes after each statement it consumed: rescanning those
        // tokens from the next `use` makes malformed input quadratic.
        for ($i = 0; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->id !== T_USE) {
                continue;
            }

            if (! self::startsStatement($tokens, $i) || self::startsConflictRule($tokens, $i)) {
                continue;
            }

            $body = '';
            $last = null;
            for ($j = $i + 1; ; $j++) {
                if (! isset($tokens[$j])) {
                    // Unterminated statement at end of file.
                    return $statements;
                }

                // The statement ends at `;` or a close tag, or at the `{` opening a trait conflict block.
                // The `{` of a group import (`use App\{A, B};`) follows a namespace separator.
                $token = $tokens[$j];
                if ($token->is([';', T_CLOSE_TAG]) || ($token->is('{') && $last?->is(T_NS_SEPARATOR) !== true)) {
                    break;
                }

                $body .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? ' ' : $token->text;
                $last = $token->isIgnorable() ? $last : $token;
            }

            $statements[] = trim($body);
            $i = $j;
        }

        return $statements;
    }

    /**
     * Whether the statement-starting `use` at position $i is the trait method named `use` at
     * the start of a conflict rule (`use as protected foo;`) rather than a statement. A `use`
     * statement is followed by a name, `function`, `const` or `(`, never by `as` or
     * `insteadof`, which are reserved and cannot be a name. Deciding per `use` keeps the walk
     * reading a malformed or unclosed conflict block exactly as it reads any other code.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function startsConflictRule(array $tokens, int $i): bool
    {
        $next = self::adjacentIndex($tokens, $i, 1);

        return $next !== null && $tokens[$next]->is([T_AS, T_INSTEADOF]);
    }

    /**
     * Namespace-level class imports. Tracks brace depth so a `use` in a class-like or function
     * body is skipped; the `{` opened by a braced namespace declaration does not count. Every
     * kind of opener counts, including `{$` and `${` inside interpolated strings and heredocs,
     * since each closes with a plain `}`.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $taken = [];
        $depth = 0;
        $namespaceBrace = null;
        $resume = null;

        for ($i = 0; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth += $i === $namespaceBrace ? 0 : 1;

                continue;
            }

            if ($token->is('}')) {
                // At depth 0 this closes a braced namespace, or is stray.
                $depth = max(0, $depth - 1);

                continue;
            }

            // A namespace declaration is top level in any code that parses, so a brace still
            // open before it was left unclosed and must not hide the imports that follow. One
            // an import missing its `;` ran into ends that import and is read here ($resume).
            if ($token->id === T_NAMESPACE) {
                if (($i === $resume || self::startsStatement($tokens, $i)) && self::declaresNamespace($tokens, $i)) {
                    $depth = 0;
                    $namespaceBrace = self::namespaceBrace($tokens, $i);
                }

                continue;
            }

            if ($token->id !== T_USE || $depth !== 0 || ! self::startsStatement($tokens, $i) || self::startsConflictRule($tokens, $i)) {
                continue;
            }

            [$i, $body] = self::importBody($tokens, $i);
            $resume = $i + 1;
            foreach ($body === null ? [] : self::parseImportBody($body) as $alias => $name) {
                $key = strtolower($alias);
                if (! isset($taken[$key])) {
                    $taken[$key] = true;
                    $imports[$alias] = $name;
                }
            }
        }

        return $imports;
    }

    /**
     * Read the import `use` statement at position $i. Returns the index of the last token
     * consumed and the statement's code tokens joined by single spaces (comments dropped), or
     * null for the body when the statement does not end at `;` or `?>`. A brace that is not a
     * group import's own is left unconsumed, so the caller's depth count sees it.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @return array{int, ?string}
     */
    private static function importBody(array $tokens, int $i): array
    {
        $parts = [];
        $group = false;
        $last = null;

        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->is([';', T_CLOSE_TAG])) {
                return [$j, implode(' ', $parts)];
            }

            // A namespace declaration never belongs to an import: this one is missing its `;`.
            // Leave the declaration to the caller so it still opens the namespace level.
            if ($token->id === T_NAMESPACE && self::declaresNamespace($tokens, $j)) {
                return [$j - 1, null];
            }

            if ($token->is('{') && ! $group && $last?->is(T_NS_SEPARATOR) === true) {
                $group = true;
            } elseif ($token->is('}') && $group) {
                $group = false;
            } elseif ($token->is(['{', '}', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                return [$j - 1, null];
            }

            $parts[] = $token->text;
            $last = $token;
        }

        // Unterminated statement at end of input.
        return [$j - 1, null];
    }

    /**
     * Resolve an import body from importBody() to alias => name, or nothing when any member
     * cannot be read.
     *
     * @return array<string, string>
     */
    private static function parseImportBody(string $body): array
    {
        // Tokens were joined by single spaces; drop those around the punctuation.
        $body = (string) preg_replace('/ ?([\\\\{},]) ?/', '$1', $body);

        if (preg_match('/^(?:function|const) /i', $body) === 1) {
            return [];
        }

        $prefix = '';
        $members = explode(',', $body);
        if (preg_match(self::IMPORT_GROUP, $body, $group) === 1) {
            $prefix = $group[1].'\\';
            $members = explode(',', $group[2]);
            // A group may end with a trailing comma.
            if (end($members) === '') {
                array_pop($members);
            }
        }

        $imports = [];
        foreach ($members as $member) {
            if ($prefix !== '' && preg_match('/^(?:function|const) /i', $member) === 1) {
                continue;
            }

            if (preg_match(self::IMPORT_MEMBER, $member, $match) !== 1) {
                return [];
            }

            $name = $prefix.$match[1];
            $alias = $match[2] ?? substr((string) strrchr('\\'.$name, '\\'), 1);
            $imports[$alias] ??= $name;
        }

        return $imports;
    }

    /**
     * Every named class-like declaration, qualified with its enclosing namespace.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @return list<array{name: string, kind: 'class'|'interface'|'trait'|'enum'}>
     */
    private static function classLikes(array $tokens): array
    {
        $found = [];
        $namespace = null;

        foreach ($tokens as $i => $token) {
            // The nearest namespace statement so far, as enclosingNamespace() reads it.
            if ($token->id === T_NAMESPACE && self::startsStatement($tokens, $i)) {
                $namespace = self::declaredNamespace($tokens, $i);

                continue;
            }

            $kind = match ($token->id) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => null,
            };

            $name = $kind === null ? null : self::declaredName($tokens, $i);
            if ($kind === null || $name === null) {
                continue;
            }

            $found[] = [
                'name' => $namespace === null ? $tokens[$name]->text : "{$namespace}\\{$tokens[$name]->text}",
                'kind' => $kind,
            ];
        }

        return $found;
    }

    /**
     * The first named namespace declaration.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function findNamespace(array $tokens): ?string
    {
        foreach ($tokens as $i => $token) {
            if ($token->id === T_NAMESPACE && self::startsStatement($tokens, $i) && ($name = self::declaredNamespace($tokens, $i)) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The name declared by the nearest namespace declaration before position $i, or null when
     * there is none or it opens a global `namespace { ... }` block.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function enclosingNamespace(array $tokens, int $i): ?string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->id === T_NAMESPACE && self::startsStatement($tokens, $j)) {
                return self::declaredNamespace($tokens, $j);
            }
        }

        return null;
    }

    /**
     * Whether the keyword at position $i starts a statement. Keywords also lex as their own
     * token when used as a name (`const USE`, `K::NAMESPACE`, `function namespace()`,
     * `foo(use: 1)`), and none of those follows a statement boundary.
     *
     * A colon is a boundary only when it ends a label (`foo: use A\B;`): the word before it
     * is a T_STRING that itself starts a statement. That rules out a named argument
     * (`f(bar: ...)`), a ternary (`? B :`) and `case B:`, whose word follows `(`, `?` or
     * `case`, and a return type's or alternative syntax's colon, which follows `)`, `else`
     * or `default`. Alternative-syntax colons do start statements, but `use` and `namespace`
     * declarations are top-level only and cannot follow one, and class-like declarations,
     * which can, are found without this check.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function startsStatement(array $tokens, int $i): bool
    {
        $prev = self::adjacentIndex($tokens, $i, -1);

        // Walk back over labels; each step moves left, so a chain is read once.
        while ($prev !== null && $tokens[$prev]->is(':')) {
            $label = self::adjacentIndex($tokens, $prev, -1);
            if ($label === null || ! $tokens[$label]->is(T_STRING)) {
                return false;
            }

            $prev = self::adjacentIndex($tokens, $label, -1);
        }

        return $prev === null || $tokens[$prev]->is([';', '{', '}', T_CLOSE_TAG, T_INLINE_HTML]);
    }

    /**
     * The name declared by the `namespace` token at position $i, or null when it declares
     * none (a global `namespace { ... }` block).
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function declaredNamespace(array $tokens, int $i): ?string
    {
        $name = self::adjacentIndex($tokens, $i, 1);
        if ($name === null || preg_match(self::NAMESPACE_NAME, $tokens[$name]->text) !== 1) {
            return null;
        }

        $after = self::adjacentIndex($tokens, $name, 1);

        return $after !== null && $tokens[$after]->is([';', '{', T_CLOSE_TAG]) ? $tokens[$name]->text : null;
    }

    /**
     * Whether the `namespace` token at position $i declares a namespace: named
     * (`namespace App;`, `namespace App {`) or a global `namespace { ... }` block.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function declaresNamespace(array $tokens, int $i): bool
    {
        $next = self::adjacentIndex($tokens, $i, 1);

        return ($next !== null && $tokens[$next]->is('{')) || self::declaredNamespace($tokens, $i) !== null;
    }

    /**
     * Index of the `{` opening the block of the namespace declaration at position $i, named
     * (`namespace App {`) or global (`namespace {`), or null for the unbraced form.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function namespaceBrace(array $tokens, int $i): ?int
    {
        $next = (int) self::adjacentIndex($tokens, $i, 1);
        if ($tokens[$next]->is('{')) {
            return $next;
        }

        // A declared name is followed by `;`, `{` or a close tag.
        $after = (int) self::adjacentIndex($tokens, $next, 1);

        return $tokens[$after]->is('{') ? $after : null;
    }

    /**
     * Index of the name token of the first `class` declaration.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function findClassNameIndex(array $tokens): ?int
    {
        foreach ($tokens as $i => $token) {
            if ($token->id === T_CLASS && ($name = self::declaredName($tokens, $i)) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Index of the name declared by the class-like keyword at position $i, or null when it
     * declares none: `Foo::class`, an anonymous class (`new class {`, `new class(...)`,
     * `new class extends ...`), `namespace Class;`, or `enum` lexed as a keyword but used as
     * a name, which is never followed by a T_STRING name.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function declaredName(array $tokens, int $i): ?int
    {
        $prev = self::adjacentIndex($tokens, $i, -1);
        if ($prev !== null && $tokens[$prev]->is(T_DOUBLE_COLON)) {
            return null;
        }

        $next = self::adjacentIndex($tokens, $i, 1);

        return $next !== null && $tokens[$next]->is(T_STRING) ? $next : null;
    }

    /**
     * Tokenize PHP source, or return no tokens when it is missing or mentions none of the
     * keywords. The case-insensitive pre-check skips the tokenizer, which costs far more
     * memory and time than a string search, for files that cannot match.
     *
     * PhpToken::tokenize() peaks at or below stripAllComments() on real large files (2.2 MB
     * generated class: 189 vs 202 MB; 8.5 MB autoload_static.php: 135 vs 173 MB). On input
     * made almost entirely of one-character tokens it peaks several times higher (1M `;`:
     * 138 vs 18 MB), since each becomes an object rather than a string.
     *
     * @return array<int, \PhpToken>
     */
    private static function tokenizeIfMentions(?string $content, string ...$keywords): array
    {
        if ($content === null) {
            return [];
        }

        foreach ($keywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                return @\PhpToken::tokenize($content);
            }
        }

        return [];
    }

    /**
     * Tokenize source passed to a *FromSource() extractor, which may be a fragment. The
     * source is tokenized as it is; when that yields no open-tag token (`<?php`, `<?=`) it is
     * a tagless fragment, read as PHP code by tokenizing it again behind `<?php `. Deciding by
     * token rather than by `<?php` appearing in the text means a fragment that mentions
     * `'<?php'` in a string still reads as code. Source that has a real open tag anywhere is
     * read as written, so text before it is inline HTML. A tagless fragment whose string or
     * comment holds an open tag the tokenizer accepts (`'<?php '`, `'<?='`, a docblock reading
     * `Usage: <?php foo();`) is therefore read as a template: what precedes that tag is
     * inline HTML and what follows it is read from inside the string or comment, so
     * declarations after it are missed (a docblock like that before `use A\B;` gives no
     * import).
     *
     * The file-path extractors do not do this: a file with no open tag is inline HTML and
     * declares nothing, as PHP itself would run it.
     *
     * @return array<int, \PhpToken>
     */
    private static function tokenizeSource(string $code, string ...$keywords): array
    {
        $tokens = self::tokenizeIfMentions($code, ...$keywords);
        foreach ($tokens as $token) {
            if ($token->is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO])) {
                return $tokens;
            }
        }

        return $tokens === [] ? [] : @\PhpToken::tokenize('<?php '.$code);
    }

    /**
     * Index of the nearest token before ($step = -1) or after ($step = 1) position $i that
     * is not whitespace, a comment or the open tag.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function adjacentIndex(array $tokens, int $i, int $step): ?int
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (! $tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Count lines in file.
     */
    public static function countLines(string $filePath): int
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return 0;
        }

        $lines = file($filePath);

        return $lines !== false ? count($lines) : 0;
    }

    /**
     * Get file size in bytes.
     */
    public static function getFileSize(string $filePath): int
    {
        if (! is_file($filePath)) {
            return 0;
        }

        $size = filesize($filePath);

        return $size !== false ? $size : 0;
    }

    /**
     * Read file contents.
     */
    public static function readFile(string $filePath): ?string
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);

        return $contents !== false ? $contents : null;
    }

    /**
     * Get lines from file.
     *
     * @return array<int, string>
     */
    public static function getLines(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $lines = file($filePath);

        return $lines !== false ? $lines : [];
    }

    /**
     * Get specific line from file (1-indexed).
     */
    public static function getLine(string $filePath, int $lineNumber): ?string
    {
        $lines = self::getLines($filePath);

        if (! isset($lines[$lineNumber - 1])) {
            return null;
        }

        return $lines[$lineNumber - 1];
    }

    /**
     * Get line range from file (1-indexed, inclusive).
     *
     * @return array<int, string>
     */
    public static function getLineRange(string $filePath, int $startLine, int $endLine): array
    {
        $lines = self::getLines($filePath);
        $result = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            if (isset($lines[$i - 1])) {
                $result[$i] = $lines[$i - 1];
            }
        }

        return $result;
    }

    /**
     * Check if file contains string.
     */
    public static function contains(string $filePath, string $needle): bool
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return false;
        }

        return str_contains($content, $needle);
    }

    /**
     * Check if file matches regex pattern.
     */
    public static function matches(string $filePath, string $pattern): bool
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return false;
        }

        return (bool) preg_match($pattern, $content);
    }

    /**
     * Count occurrences of string in file.
     */
    public static function countOccurrences(string $filePath, string $needle): int
    {
        $content = self::readFile($filePath);
        if ($content === null) {
            return 0;
        }

        return substr_count($content, $needle);
    }

    /**
     * Strip single-line comments from a line of code.
     * Removes // and # style comments.
     *
     * @param  string  $line
     * @return string
     */
    public static function stripComments(string $line): string
    {
        $lineWithoutComments = preg_replace('/\/\/.*$|#.*$/', '', $line);

        return is_string($lineWithoutComments) ? $lineWithoutComments : $line;
    }

    /**
     * Strip all PHP comments from code using the PHP tokenizer.
     *
     * Uses token_get_all() to correctly distinguish between actual comments
     * and comment-like patterns inside strings (e.g., URLs containing "//").
     * Removes single-line (//, #), multi-line, and docblock comments while
     * preserving line numbering (comments are replaced with blank lines).
     *
     * Unlike stripComments(), which only handles single-line // and # on a
     * per-line basis and breaks on URLs inside strings, this method handles
     * all PHP comment styles and is safe for arbitrary PHP source code.
     *
     * NOTE: String literal content is PRESERVED by design (so URLs containing
     * "//" survive). If the stripped output will be pattern-matched (via
     * str_contains() or preg_match()) to detect code-level anti-patterns,
     * use {@see stripCommentsAndStrings()} instead — otherwise pattern keywords
     * inside string literals like Log::info('Avoid User::all()') will
     * produce false positives.
     *
     * @param  string  $code  PHP source code (with or without opening <?php tag)
     */
    public static function stripAllComments(string $code): string
    {
        $needsPrefix = ! str_contains($code, '<?php') && ! str_contains($code, '<?=');

        if ($needsPrefix) {
            $code = '<?php '.$code;
        }

        $tokens = @token_get_all($code);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    // Preserve newlines to maintain line numbering
                    $result .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }

        if ($needsPrefix) {
            $result = (string) preg_replace('/^<\?php\s?/', '', $result);
        }

        return $result;
    }

    /**
     * Strip all PHP comments AND string literal content from code.
     *
     * Intended for analyzers that pattern-match (str_contains / preg_match)
     * the output to detect code-level anti-patterns. Unlike
     * {@see stripAllComments()}, which preserves string content, this method
     * also blanks out literal text inside:
     *   - Single/double-quoted strings without interpolation (T_CONSTANT_ENCAPSED_STRING)
     *   - Text chunks inside interpolated double-quoted strings, heredocs,
     *     nowdocs, and backtick shell-exec (T_ENCAPSED_AND_WHITESPACE)
     *   - Inline HTML outside PHP tags (T_INLINE_HTML)
     *
     * Prevents false positives like:
     *   Log::info('Avoid using User::all() in production');
     *   throw new \Exception('Do not use ->get()->count()');
     * where the pattern keyword is part of a human-readable message.
     *
     * Preserves line numbering so Issue Locations remain accurate — multi-line
     * strings are replaced with quote-wrapped newlines (e.g., 'a\nb\nc'
     * becomes '\n\n').
     *
     * Variables and real code references inside interpolated strings
     * (e.g., {$user->name}) are preserved: they represent actual runtime code.
     *
     * The output is intended for textual pattern matching, not execution —
     * syntactic validity for edge cases (e.g., binary-string prefix `b'...'`)
     * is not guaranteed.
     *
     * @param  string  $code  PHP source code (with or without opening <?php tag)
     */
    public static function stripCommentsAndStrings(string $code): string
    {
        $needsPrefix = ! str_contains($code, '<?php') && ! str_contains($code, '<?=');

        if ($needsPrefix) {
            $code = '<?php '.$code;
        }

        $tokens = @token_get_all($code);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                // Strip comments (same as stripAllComments)
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $result .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                // Strip single/double-quoted strings without interpolation.
                // Preserve closing quote + newline count so line numbers are stable
                // and the surrounding syntax remains roughly intact.
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $quote = substr($token[1], -1);
                    $newlines = substr_count($token[1], "\n");
                    $result .= $quote.str_repeat("\n", $newlines).$quote;

                    continue;
                }

                // Strip literal text chunks inside interpolated strings, heredocs,
                // nowdocs, and backtick exec. Variables and code references in these
                // contexts are separate tokens (T_VARIABLE, T_OBJECT_OPERATOR, ...)
                // and pass through unchanged.
                if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $result .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                // Strip inline HTML (content outside PHP open/close tags).
                if ($token[0] === T_INLINE_HTML) {
                    $result .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }

        if ($needsPrefix) {
            $result = (string) preg_replace('/^<\?php\s?/', '', $result);
        }

        return $result;
    }

    /**
     * Get code snippet from file with context lines as a plain string.
     *
     * This method is useful when you need a simple string snippet, such as for
     * the `code` parameter in `createIssue()`. It uses CodeSnippet internally
     * for better performance and features (including smart context expansion),
     * but returns a plain string for backward compatibility.
     *
     * **When to use:**
     * - For simple string snippets (e.g., `code` parameter in `createIssue()`)
     * - When you don't need structured line-by-line data
     * - For backward compatibility with existing code
     *
     * **When to use CodeSnippet::fromFile() instead:**
     * - When you need structured data with line numbers
     * - For `createIssueWithSnippet()` which expects a CodeSnippet object
     * - When you need access to line-by-line data with keys
     *
     * @param  string  $filePath  Path to the file
     * @param  int  $line  Target line number (1-indexed)
     * @param  int  $contextLines  Number of context lines before and after (default: 2); a negative count means 0
     * @return string|null  Code snippet as string with newlines, or null if file cannot be read
     */
    public static function getCodeSnippet(string $filePath, int $line, int $contextLines = 2): ?string
    {
        $codeSnippet = CodeSnippet::fromFile($filePath, $line, $contextLines);

        if ($codeSnippet === null) {
            return null;
        }

        // Convert CodeSnippet object to plain string format (maintaining backward compatibility)
        // Note: CodeSnippet lines are already rtrimmed, so we need to add newlines back
        $lines = $codeSnippet->getLines();
        $result = '';

        foreach ($lines as $lineContent) {
            $result .= $lineContent . "\n";
        }

        return $result;
    }
}
