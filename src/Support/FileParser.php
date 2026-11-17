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
     * Extract class name from PHP file.
     *
     * Returns the name of the first `class` declaration in the tokenized source. The word
     * "class" in a comment, string or heredoc is ignored, as are `Foo::class` and anonymous
     * `new class` expressions. Interfaces, traits and enums are not considered.
     */
    public static function extractClassName(string $filePath): ?string
    {
        $tokens = self::tokenizeIfMentions(self::readFile($filePath), 'class');
        $name = self::findClassNameIndex($tokens);

        return $name === null ? null : $tokens[$name]->text;
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
        $tokens = self::tokenizeIfMentions(self::readFile($filePath), 'class');

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
     * Extract use statements from file.
     *
     * Returns the trimmed source between `use` and `;` (or `?>`) for every import and trait `use`
     * statement, e.g. `Foo\Bar as Baz`, `function Foo\bar`, `Foo\{Bar, Baz}` or
     * `HasFactory`. A trait `use` with a conflict-resolution block stops at its `{`
     * (`use A, B { ... }` gives `A, B`). Each comment inside a statement becomes a single
     * space. Only a `use` that starts a statement counts, so the word "use" in a comment,
     * string or heredoc, a closure's `use (...)` clause, and `use` as a name (`const USE`,
     * `K::USE`, `foo(use: 1)`, `namespace Use;`) are all ignored.
     *
     * @return array<string>
     */
    public static function extractUseStatements(string $filePath): array
    {
        $tokens = self::tokenizeIfMentions(self::readFile($filePath), 'use');
        $statements = [];

        // Index-based so the walk resumes after each statement it consumed: rescanning those
        // tokens from the next `use` makes malformed input quadratic.
        for ($i = 0; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->id !== T_USE) {
                continue;
            }

            if (! self::startsStatement($tokens, $i)) {
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
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function startsStatement(array $tokens, int $i): bool
    {
        $prev = self::adjacentIndex($tokens, $i, -1);

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
     * Index of the name token of the first `class` declaration.
     *
     * @param  array<int, \PhpToken>  $tokens
     */
    private static function findClassNameIndex(array $tokens): ?int
    {
        foreach ($tokens as $i => $token) {
            if ($token->id !== T_CLASS) {
                continue;
            }

            $prev = self::adjacentIndex($tokens, $i, -1);
            if ($prev !== null && $tokens[$prev]->is(T_DOUBLE_COLON)) {
                continue;
            }

            // An anonymous class (`new class {`, `new class(...)`, `new class extends ...`)
            // is never followed by a name; nor is `namespace Class;`.
            $next = self::adjacentIndex($tokens, $i, 1);
            if ($next !== null && $tokens[$next]->is(T_STRING)) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Tokenize PHP source, or return no tokens when it is missing or cannot contain the
     * keyword. The case-insensitive pre-check skips the tokenizer, which costs far more
     * memory and time than a string search, for files that cannot match.
     *
     * PhpToken::tokenize() peaks at or below stripAllComments() on real large files (2.2 MB
     * generated class: 189 vs 202 MB; 8.5 MB autoload_static.php: 135 vs 173 MB). On input
     * made almost entirely of one-character tokens it peaks several times higher (1M `;`:
     * 138 vs 18 MB), since each becomes an object rather than a string.
     *
     * @return array<int, \PhpToken>
     */
    private static function tokenizeIfMentions(?string $content, string $keyword): array
    {
        if ($content === null || stripos($content, $keyword) === false) {
            return [];
        }

        return @\PhpToken::tokenize($content);
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
     * @param  int  $contextLines  Number of context lines before and after (default: 2)
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
