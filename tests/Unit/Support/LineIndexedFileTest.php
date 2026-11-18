<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\LineIndexedFile;

class LineIndexedFileTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_open_returns_null_for_missing_path_and_directory(): void
    {
        $this->assertNull(LineIndexedFile::open(sys_get_temp_dir().'/missing_'.uniqid().'.php'));
        $this->assertNull(LineIndexedFile::open(sys_get_temp_dir()));
    }

    public function test_counts_lines_as_split_on_newline(): void
    {
        $this->assertSame(1, $this->open('')->lineCount());
        $this->assertSame(2, $this->open("\n")->lineCount());
        $this->assertSame(2, $this->open("a\nb")->lineCount());
        $this->assertSame(3, $this->open("a\nb\n")->lineCount());
        $this->assertSame(1, $this->open("a\rb\rc")->lineCount());
    }

    public function test_head_is_each_line_without_its_newline(): void
    {
        $file = $this->open("one\r\ntwo\n\nfour");

        $this->assertSame(["one\r", 'two', '', 'four'], [$file->head(1), $file->head(2), $file->head(3), $file->head(4)]);
    }

    public function test_head_keeps_only_the_first_bytes_of_a_long_line(): void
    {
        $line = str_repeat('a', 100).str_repeat('b', 100000);
        $file = $this->open($line."\nshort");

        $this->assertSame(substr($line, 0, LineIndexedFile::HEAD_BYTES), $file->head(1));
        $this->assertSame('short', $file->head(2));
    }

    public function test_returns_null_outside_the_file(): void
    {
        $file = $this->open("a\nb");

        foreach ([0, -1, 3] as $number) {
            $this->assertNull($file->head($number));
            $this->assertNull($file->probe($number));
        }
    }

    public function test_reads_every_line_across_blocks_in_any_order(): void
    {
        $lines = array_map(static fn (int $n): string => "line {$n}", range(1, 1000));
        $file = $this->open(implode("\n", $lines));

        $this->assertSame(1000, $file->lineCount());

        foreach ([1000, 1, 500, 32, 33, 64, 65, 999, 2, 700] as $n) {
            $this->assertSame("line {$n}", $file->head($n));
        }

        foreach (range(1, 1000) as $n) {
            $this->assertSame("line {$n}", $file->head($n));
            $this->assertSame("line {$n}", $file->probe($n));
        }
    }

    public function test_final_empty_line_on_a_block_boundary(): void
    {
        // 32 lines each ending "\n": line 33 is empty and starts at the end of the file.
        $file = $this->open(str_repeat("x\n", 32));

        $this->assertSame(33, $file->lineCount());
        $this->assertSame('x', $file->head(32));
        $this->assertSame('', $file->head(33));
    }

    public function test_lines_straddling_read_chunks(): void
    {
        $lines = array_map(static fn (int $n): string => str_repeat((string) ($n % 10), 1000 + $n), range(1, 300));
        $file = $this->open(implode("\n", $lines)."\n");

        $this->assertSame(301, $file->lineCount());

        foreach ([1, 8, 9, 64, 65, 66, 150, 300] as $n) {
            $this->assertSame(substr($lines[$n - 1], 0, LineIndexedFile::HEAD_BYTES), $file->head($n));
        }
        $this->assertSame('', $file->head(301));
    }

    public function test_probe_is_the_exact_trimmed_line_when_short(): void
    {
        $file = $this->open(implode("\n", ['   }   ', "\t};\0\x0B", '', "  \t ", 'x', '  ab  ', str_repeat(' ', 20000).'}'.str_repeat(' ', 20000)]));

        $this->assertSame(['}', '};', '', '', 'x', 'ab', '}'], array_map($file->probe(...), range(1, 7)));
    }

    public function test_probe_collapses_whitespace_runs_in_a_longer_line(): void
    {
        $file = $this->open(implode("\n", [
            "  public   function\t\tfoo()  ",
            str_repeat(' ', 10000)."class\x0B\f".str_repeat(' ', 20000).'Foo'.str_repeat(' ', 10000),
            "}\f\f",
            str_repeat('w', 200),
        ]));

        $this->assertSame('public function foo() ', $file->probe(1));
        $this->assertSame('class Foo ', $file->probe(2));
        // "\f" is PCRE whitespace but not something trim() strips, so this is not a bare "}".
        $this->assertSame('} ', $file->probe(3));
        $this->assertSame(str_repeat('w', 64), $file->probe(4));
    }

    public function test_is_current_until_the_file_changes(): void
    {
        $path = $this->write("<?php\n// a\n");
        touch($path, time() - 100);
        $file = LineIndexedFile::open($path);
        $this->assertNotNull($file);

        $this->assertTrue($file->isCurrent());

        file_put_contents($path, "<?php\n// b\n");
        touch($path, time());
        $this->assertFalse($file->isCurrent(), 'Same size, new mtime');

        unlink($path);
        $this->assertFalse($file->isCurrent(), 'Deleted');
    }

    public function test_file_shrunk_after_indexing_returns_what_is_left(): void
    {
        $path = $this->write(str_repeat("line\n", 100));
        $file = LineIndexedFile::open($path);
        $this->assertNotNull($file);

        file_put_contents($path, "line\nline\n");

        $this->assertSame('line', $file->head(2));
        $this->assertNull($file->head(50));
    }

    public function test_reading_after_close_fails_loudly(): void
    {
        $file = $this->open("a\nb\n");
        $this->assertSame('a', $file->head(1));
        $file->close();
        $file->close();

        $this->assertSame(3, $file->lineCount());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been closed');
        $file->head(1);
    }

    private function open(string $content): LineIndexedFile
    {
        $file = LineIndexedFile::open($this->write($content));
        $this->assertNotNull($file);

        return $file;
    }

    private function write(string $content): string
    {
        $path = sys_get_temp_dir().'/line_indexed_'.uniqid('', true).'.txt';
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
