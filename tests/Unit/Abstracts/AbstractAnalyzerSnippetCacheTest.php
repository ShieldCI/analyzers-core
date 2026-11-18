<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Abstracts;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ShieldCI\AnalyzersCore\Abstracts\AbstractAnalyzer;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\Enums\{Category, Severity, Status};
use ShieldCI\AnalyzersCore\Tests\Support\CountingStreamWrapper;
use ShieldCI\AnalyzersCore\ValueObjects\{AnalyzerMetadata, CodeSnippet, Issue};

/**
 * createIssueWithSnippet() reads each file once per analyze() run - ShieldCI/analyzers-core#92.
 *
 * It used to hand every issue to CodeSnippet::fromFile(), which opened the file afresh and
 * re-read it from the top for every backward seek, so N issues in one file cost about N times
 * the file. The run now keeps the most recently read file's lines and reuses them, and only for
 * the duration of analyze(): a direct call outside a run, or the next run, always reads the
 * file as it is on disk.
 */
class AbstractAnalyzerSnippetCacheTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__shieldci_test_config'] = [];
        CountingStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        CountingStreamWrapper::unregister();
        unset($GLOBALS['__shieldci_test_config']);

        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testManyIssuesInOneFileReadTheFileOnce(): void
    {
        $path = $this->makeFile($this->classSource(400));
        $url = CountingStreamWrapper::url($path);
        $targets = [];

        for ($line = 1; $line <= 200; $line++) {
            $targets[] = [$url, $line * 2];
        }

        $result = (new SnippetTargetsAnalyzer($targets))->analyze();

        $this->assertCount(200, $result->getIssues());
        $this->assertSame(1, CountingStreamWrapper::$opens[$path] ?? 0, 'The file should be opened once per run, not once per issue');
        // One pass to index the file, then each 32-line block at most once, at most one stream
        // buffer (8192 bytes) per block. The old code re-read the file from the top for every
        // backward seek: 27,963,080 bytes for this 9,660-byte file.
        $blocks = (int) ceil(400 / 32);
        $this->assertLessThanOrEqual(filesize($path) + $blocks * 8192, CountingStreamWrapper::$bytesRead[$path] ?? 0);

        foreach ($result->getIssues() as $issue) {
            $this->assertNotNull($issue->codeSnippet);
        }
    }

    public function testCachedSnippetsMatchFreshlyReadOnes(): void
    {
        $path = $this->makeFile($this->classSource(60));
        $targets = [[$path, 1], [$path, 12], [$path, 30], [$path, 59], [$path, 61], [$path, 0]];

        $issues = (new SnippetTargetsAnalyzer($targets))->analyze()->getIssues();

        $this->assertCount(count($targets), $issues);

        foreach ($targets as $i => [$file, $line]) {
            $this->assertSame(
                CodeSnippet::fromFile($file, $line)?->toArray(),
                $issues[$i]->codeSnippet?->toArray(),
                "Snippet for line {$line} differs from a fresh fromFile() read"
            );
        }
    }

    public function testSnippetShowsTargetLineAndExpandsToTheMethodSignature(): void
    {
        $path = $this->makeFile($this->classSource(60));

        // Line 3 is `class Generated`, line 5 `public function method()`; line 14 is in the method body.
        $issues = (new SnippetTargetsAnalyzer([[$path, 14]], 8))->analyze()->getIssues();
        $snippet = $issues[0]->codeSnippet;

        $this->assertNotNull($snippet);
        $lines = $snippet->getLines();
        $this->assertSame('        $value10 = 10;', $lines[14]);
        $this->assertSame(5, array_key_first($lines), 'The window should expand up to the method signature');
        $this->assertSame('    public function method()', $lines[5]);
    }

    public function testSwitchingBetweenFilesWithinOneRunGivesEachFilesOwnLines(): void
    {
        $a = $this->makeFile("<?php\n// file A line 2\n// file A line 3\n");
        $b = $this->makeFile("<?php\n// file B line 2\n// file B line 3\n");

        $issues = (new SnippetTargetsAnalyzer([[$a, 2], [$b, 2], [$a, 3], [$b, 3]], 0))->analyze()->getIssues();

        $this->assertSame([2 => '// file A line 2'], $issues[0]->codeSnippet?->getLines());
        $this->assertSame([2 => '// file B line 2'], $issues[1]->codeSnippet?->getLines());
        $this->assertSame([3 => '// file A line 3'], $issues[2]->codeSnippet?->getLines());
        $this->assertSame([3 => '// file B line 3'], $issues[3]->codeSnippet?->getLines());
    }

    public function testMissingFileStillYieldsNullSnippetInsideARun(): void
    {
        $present = $this->makeFile("<?php\n// present\n");
        $missing = sys_get_temp_dir().'/shieldci_snippet_missing_'.uniqid().'.php';

        $issues = (new SnippetTargetsAnalyzer([[$missing, 1], [$missing, 2], [$present, 2], [$missing, 1]], 0))
            ->analyze()
            ->getIssues();

        $this->assertNull($issues[0]->codeSnippet);
        $this->assertNull($issues[1]->codeSnippet);
        $this->assertSame([2 => '// present'], $issues[2]->codeSnippet?->getLines());
        $this->assertNull($issues[3]->codeSnippet);
    }

    public function testDirectoryYieldsNullSnippet(): void
    {
        $analyzer = new SnippetTargetsAnalyzer([[sys_get_temp_dir(), 1]]);

        $this->assertNull($analyzer->analyze()->getIssues()[0]->codeSnippet);
        $this->assertNull($analyzer->exposedCreateIssueWithSnippet(sys_get_temp_dir(), 1)->codeSnippet);
    }

    public function testDirectCallsOutsideARunAlwaysReadTheFileFresh(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        $analyzer = new SnippetTargetsAnalyzer([]);

        $first = $analyzer->exposedCreateIssueWithSnippet($path, 2);
        file_put_contents($path, "<?php\n// changed\n");
        $second = $analyzer->exposedCreateIssueWithSnippet($path, 2);

        $this->assertSame('// original', $first->codeSnippet?->getLines()[2]);
        $this->assertSame('// changed', $second->codeSnippet?->getLines()[2]);
    }

    public function testCacheDoesNotSurviveIntoTheNextRun(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        $analyzer = new SnippetTargetsAnalyzer([[$path, 2]], 0);

        $first = $analyzer->analyze()->getIssues()[0];
        file_put_contents($path, "<?php\n// changed\n");
        $second = $analyzer->analyze()->getIssues()[0];

        $this->assertSame([2 => '// original'], $first->codeSnippet?->getLines());
        $this->assertSame([2 => '// changed'], $second->codeSnippet?->getLines());
    }

    public function testCacheIsClearedWhenRunAnalysisThrows(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        $analyzer = new SnippetTargetsAnalyzer([[$path, 2]], 0, throwAfterIssues: true);

        $result = $analyzer->analyze();
        $this->assertSame(Status::Error, $result->getStatus());
        $this->assertSame([2 => '// original'], $analyzer->lastIssues[0]->codeSnippet?->getLines());

        file_put_contents($path, "<?php\n// changed\n");

        // A cache left switched on by the thrown run would hand back "// original" here.
        $direct = $analyzer->exposedCreateIssueWithSnippet($path, 2, 0);
        $this->assertSame([2 => '// changed'], $direct->codeSnippet?->getLines());

        $analyzer->throwAfterIssues = false;
        $rerun = $analyzer->analyze()->getIssues()[0];
        $this->assertSame([2 => '// changed'], $rerun->codeSnippet?->getLines());
    }

    public function testAlternatingFilesAreEachOpenedOncePerRun(): void
    {
        $a = $this->makeFile($this->classSource(300));
        $b = $this->makeFile($this->classSource(300));
        $targets = [];

        for ($i = 1; $i <= 200; $i++) {
            $targets[] = [CountingStreamWrapper::url($a), $i];
            $targets[] = [CountingStreamWrapper::url($b), $i];
        }

        $issues = (new SnippetTargetsAnalyzer($targets))->analyze()->getIssues();

        $this->assertCount(400, $issues);
        $this->assertSame(1, CountingStreamWrapper::$opens[$a] ?? 0);
        $this->assertSame(1, CountingStreamWrapper::$opens[$b] ?? 0);
    }

    public function testLeastRecentlyUsedFileIsEvictedBeyondTheCap(): void
    {
        $paths = [];
        $targets = [];

        // Nine files, one more than the cache holds, then the first again: it was evicted.
        for ($i = 0; $i < 9; $i++) {
            $paths[$i] = $this->makeFile("<?php\n// file {$i}\n");
            $targets[] = [CountingStreamWrapper::url($paths[$i]), 2];
        }
        $targets[] = [CountingStreamWrapper::url($paths[0]), 2];
        $targets[] = [CountingStreamWrapper::url($paths[8]), 2];

        $issues = (new SnippetTargetsAnalyzer($targets, 0))->analyze()->getIssues();

        $this->assertSame([2 => '// file 0'], $issues[9]->codeSnippet?->getLines());
        $this->assertSame([2 => '// file 8'], $issues[10]->codeSnippet?->getLines());
        $this->assertSame(2, CountingStreamWrapper::$opens[$paths[0]] ?? 0);
        $this->assertSame(1, CountingStreamWrapper::$opens[$paths[8]] ?? 0);
    }

    public function testNestedAnalyzeDoesNotSwitchTheCacheOffForTheOuterRun(): void
    {
        $path = $this->makeFile($this->classSource(200));
        $url = CountingStreamWrapper::url($path);
        $targets = [];

        for ($i = 1; $i <= 100; $i++) {
            $targets[] = [$url, $i];
        }

        $analyzer = new SnippetTargetsAnalyzer($targets, nestOnce: true);
        $result = $analyzer->analyze();

        // Outer: 1 issue, then a nested analyze() (100 issues), then the outer's remaining 99.
        $this->assertCount(100, $result->getIssues());
        $this->assertSame(1, CountingStreamWrapper::$opens[$path] ?? 0, 'The nested run must not end the outer run\'s cache');
    }

    public function testFileRewrittenMidRunWithDifferentSizeShowsNewContent(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        $analyzer = new SnippetTargetsAnalyzer([[$path, 2], [$path, 2]], 0);
        $analyzer->betweenIssues = static function () use ($path): void {
            file_put_contents($path, "<?php\n// changed, and longer\n");
        };

        $issues = $analyzer->analyze()->getIssues();

        $this->assertSame([2 => '// original'], $issues[0]->codeSnippet?->getLines());
        $this->assertSame([2 => '// changed, and longer'], $issues[1]->codeSnippet?->getLines());
    }

    public function testFileRewrittenMidRunWithSameSizeAndNewMtimeShowsNewContent(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        touch($path, time() - 100);
        $analyzer = new SnippetTargetsAnalyzer([[$path, 2], [$path, 2]], 0);
        $analyzer->betweenIssues = static function () use ($path): void {
            file_put_contents($path, "<?php\n// replaced\n");
            touch($path, time());
        };

        $issues = $analyzer->analyze()->getIssues();

        $this->assertSame([2 => '// original'], $issues[0]->codeSnippet?->getLines());
        $this->assertSame([2 => '// replaced'], $issues[1]->codeSnippet?->getLines());
    }

    public function testFileDeletedMidRunYieldsNullSnippet(): void
    {
        $path = $this->makeFile("<?php\n// original\n");
        $analyzer = new SnippetTargetsAnalyzer([[$path, 2], [$path, 2]], 0);
        $analyzer->betweenIssues = static function () use ($path): void {
            unlink($path);
        };

        $issues = $analyzer->analyze()->getIssues();

        $this->assertSame([2 => '// original'], $issues[0]->codeSnippet?->getLines());
        $this->assertNull($issues[1]->codeSnippet);
    }

    public function testCachedFilesWithLongLinesStayWithinMemory(): void
    {
        // Eight files of 32 lines x 128 KiB, one issue each, in one run. Caching whole blocks
        // keeps 32 MiB alive; a memory fatal cannot be caught, so it runs in a subprocess.
        $files = [];
        for ($i = 0; $i < 8; $i++) {
            $files[] = $this->makeFile(str_repeat(str_repeat('z', 131072)."\n", 32));
        }
        $code = 'require "'.dirname(__DIR__, 3).'/tests/bootstrap.php";'
            .' require "'.__FILE__.'";'
            .' $t = []; foreach (array_slice($argv, 1) as $f) { $t[] = [$f, 5]; }'
            .' $issues = (new \ShieldCI\AnalyzersCore\Tests\Unit\Abstracts\SnippetTargetsAnalyzer($t))->analyze()->getIssues();'
            .' echo count(array_filter($issues, static fn ($i) => $i->codeSnippet !== null));';

        exec(PHP_BINARY.' -d memory_limit=24M -r '.escapeshellarg($code).' '.implode(' ', array_map('escapeshellarg', $files)).' 2>&1', $out, $rc);

        $this->assertSame([0, '8'], [$rc, implode("\n", $out)]);
    }

    public function testCloneDuringARunDoesNotShareOrCloseTheOriginalsFiles(): void
    {
        $main = $this->makeFile("<?php\n// main line 2\n// main line 3\n");
        $others = [];
        for ($i = 0; $i < 9; $i++) {
            $others[] = CountingStreamWrapper::url($this->makeFile("<?php\n// other {$i}\n"));
        }

        $clone = null;
        $analyzer = new SnippetTargetsAnalyzer([[$main, 2], [$main, 3]], 0);
        $analyzer->betweenIssuesWithAnalyzer = static function (SnippetTargetsAnalyzer $a) use ($others, &$clone): void {
            $clone = clone $a;
            foreach ($others as $other) {
                $clone->exposedCreateIssueWithSnippet($other, 2, 0);
            }
        };

        $issues = $analyzer->analyze()->getIssues();

        $this->assertSame([2 => '// main line 2'], $issues[0]->codeSnippet?->getLines());
        $this->assertSame([3 => '// main line 3'], $issues[1]->codeSnippet?->getLines());

        // The clone is not mid-run: two direct calls open the file twice.
        $this->assertInstanceOf(SnippetTargetsAnalyzer::class, $clone);
        $path = substr($others[0], strlen(CountingStreamWrapper::SCHEME.'://'));
        $before = CountingStreamWrapper::$opens[$path] ?? 0;
        $clone->exposedCreateIssueWithSnippet($others[0], 2, 0);
        $clone->exposedCreateIssueWithSnippet($others[0], 2, 0);
        $this->assertSame($before + 2, CountingStreamWrapper::$opens[$path] ?? 0);
    }

    public function testSnippetsDisabledReadNothing(): void
    {
        config(['shieldci.report.show_code_snippets' => false]);
        $path = $this->makeFile($this->classSource(20));

        $issues = (new SnippetTargetsAnalyzer([[CountingStreamWrapper::url($path), 5]]))->analyze()->getIssues();

        $this->assertNull($issues[0]->codeSnippet);
        $this->assertSame(0, CountingStreamWrapper::$opens[$path] ?? 0);
    }

    private function makeFile(string $content): string
    {
        $path = sys_get_temp_dir().'/shieldci_snippet_cache_'.uniqid('', true).'.php';
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /**
     * A class whose body is one method of numbered assignments, `$valueN = N;` on line N + 4.
     */
    private function classSource(int $totalLines): string
    {
        $lines = ['<?php', '', 'class Generated', '{', '    public function method()', '    {'];

        for ($n = 7; $n <= $totalLines - 2; $n++) {
            $lines[] = '        $value'.($n - 4).' = '.($n - 4).';';
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }
}

class SnippetTargetsAnalyzer extends AbstractAnalyzer
{
    /** @var list<Issue> */
    public array $lastIssues = [];

    /** @var (callable(): void)|null Called after each issue but the last. */
    public $betweenIssues = null;

    /** @var (callable(self): void)|null Called with this analyzer after each issue but the last. */
    public $betweenIssuesWithAnalyzer = null;

    private bool $nested = false;

    /**
     * @param  list<array{string, int}>  $targets  [file, line] pairs, one issue each, in order.
     */
    public function __construct(
        private array $targets,
        private ?int $contextLines = null,
        public bool $throwAfterIssues = false,
        private bool $nestOnce = false,
    ) {
    }

    public function exposedCreateIssueWithSnippet(string $file, int $line, ?int $contextLines = null): Issue
    {
        return $this->createIssueWithSnippet(
            message: 'Direct issue',
            filePath: $file,
            lineNumber: $line,
            severity: Severity::Low,
            recommendation: 'Fix it',
            contextLines: $contextLines,
        );
    }

    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'snippet-targets-analyzer',
            name: 'Snippet Targets Analyzer',
            description: 'Creates one snippet issue per target',
            category: Category::Performance,
            severity: Severity::Low
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issues = [];
        $last = count($this->targets) - 1;

        foreach ($this->targets as $i => [$file, $line]) {
            $issues[] = $this->createIssueWithSnippet(
                message: "Issue at {$line}",
                filePath: $file,
                lineNumber: $line,
                severity: Severity::Low,
                recommendation: 'Fix it',
                contextLines: $this->contextLines,
            );

            if ($this->nestOnce && ! $this->nested) {
                $this->nested = true;
                $this->analyze();
            }

            if ($this->betweenIssues !== null && $i < $last) {
                ($this->betweenIssues)();
            }

            if ($this->betweenIssuesWithAnalyzer !== null && $i < $last) {
                ($this->betweenIssuesWithAnalyzer)($this);
            }
        }

        $this->lastIssues = $issues;

        if ($this->throwAfterIssues) {
            throw new RuntimeException('Analyzer blew up after creating issues');
        }

        return $this->failed('Issues found', $this->lastIssues);
    }
}
