<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Caps the number of places in this package that build a parser nobody can read.
 *
 * A parser constructed inside a helper records its failures into an object that is discarded
 * when the call returns, so an unreadable file and an absent key become the same answer. The
 * package now offers instance classes that take a parser instead, and the only remaining
 * throwaways are the deprecated delegates kept for released callers.
 *
 * Nothing in the language stops the next parsing helper from being written the old way, and a
 * @deprecated docblock is an IDE strikethrough rather than a signal. This is the signal: add a
 * helper that builds its own parser and this test fails.
 *
 * Exact counts, not "at most". Removing a delegate in 3.0.0 has to come with an edit here,
 * which is how the allowlist stays a statement about the package rather than a stale ceiling.
 */
class NoThrowawayParsersTest extends TestCase
{
    /**
     * Files permitted to construct their own AstParser, and how many times each may do so.
     *
     * @var array<string, int>
     */
    private const ALLOWED = [
        'ConfigFileHelper.php' => 2,  // parseConfigArray(), findNestedArrayKeyLine() -- @deprecated
        'PackageDetector.php' => 1,   // isFilamentConfigured() -- @deprecated
    ];

    public function test_only_the_deprecated_delegates_build_their_own_parser(): void
    {
        $counts = [];

        foreach ($this->sourceFiles() as $path) {
            $found = self::countParserConstructions((string) file_get_contents($path));

            if ($found > 0) {
                $counts[basename($path)] = $found;
            }
        }

        ksort($counts);
        $expected = self::ALLOWED;
        ksort($expected);

        $this->assertSame(
            $expected,
            $counts,
            'A helper that builds its own AstParser discards the failure log with it. '
            .'Take a RecordingParserInterface in the constructor instead, or update this '
            .'allowlist deliberately if a delegate was added or removed.'
        );
    }

    /**
     * Count `new AstParser(` in real code, ignoring comments.
     *
     * Tokenising rather than grepping because the docblocks on ConfigFileParser and
     * FilamentPanelDetector show `new AstParser()` in their usage examples, which is exactly
     * the call this test is looking for and exactly the one it must not count.
     */
    private static function countParserConstructions(string $code): int
    {
        $count = 0;
        $sawNew = false;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_WHITESPACE) {
                    continue;
                }

                if ($token[0] === T_NEW) {
                    $sawNew = true;

                    continue;
                }

                if ($sawNew && $token[0] === T_STRING && $token[1] === 'AstParser') {
                    $count++;
                }
            }

            $sawNew = false;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $dir = dirname(__DIR__, 3).'/src';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
