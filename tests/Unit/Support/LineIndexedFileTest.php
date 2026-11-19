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

    public function test_index_memory_stays_bounded_for_a_file_of_many_lines(): void
    {
        // Past the 65536-checkpoint cap at the starting 32 lines per block, so the index has
        // compacted. Only "\n", the cheapest way to get millions of lines (#102).
        $lines = (1 << 21) + (1 << 16);
        $path = $this->write(str_repeat("\n", $lines - 1));

        $before = memory_get_usage();
        $file = LineIndexedFile::open($path);
        $growth = memory_get_usage() - $before;
        $this->assertNotNull($file);

        $this->assertLessThan(640 * 1024, $growth, 'The index is at most 65536 offsets of 8 bytes');
        $this->assertSame($lines, $file->lineCount());

        foreach ([1, 64, 65, 2_097_152, 2_097_153, $lines] as $number) {
            $this->assertSame('', $file->head($number));
            $this->assertSame('', $file->probe($number));
        }
        $this->assertNull($file->head($lines + 1));
    }

    public function test_a_compacted_index_reads_the_same_lines_as_explode(): void
    {
        $lines = array_map(static fn (int $n): string => match ($n % 7) {
            0 => '',
            1 => "  public   function\tf{$n}()  ",
            2 => str_repeat((string) ($n % 10), $n % 100 === 2 ? 9000 + $n : 300),
            3 => "}\r",
            4 => "\t};",
            default => "line {$n}",
        }, range(1, 600));

        $contents = [
            '',
            "\n",
            str_repeat("x\n", 63),
            str_repeat("x\n", 64),
            str_repeat("x\n", 128),
            implode("\n", $lines),
            implode("\n", $lines)."\n",
        ];

        foreach ($contents as $content) {
            $path = $this->write($content);
            $expected = explode("\n", $content);
            $uncompacted = LineIndexedFile::open($path);
            $this->assertNotNull($uncompacted);

            foreach ([2, 3, 5, 8] as $cap) {
                $compacted = LineIndexedFile::open($path, $cap);
                $this->assertNotNull($compacted);
                $this->assertLessThanOrEqual($cap * 8, strlen($this->checkpoints($compacted)));
                $this->assertSame(count($expected), $compacted->lineCount());
                $this->assertSame($uncompacted->lineCount(), $compacted->lineCount());

                // Backwards, so every read after the first goes to an earlier block.
                foreach (array_reverse(array_keys($expected)) as $index) {
                    $number = $index + 1;
                    $this->assertSame(substr($expected[$index], 0, LineIndexedFile::HEAD_BYTES), $compacted->head($number), "cap {$cap}, line {$number}");
                    $this->assertSame($uncompacted->head($number), $compacted->head($number), "cap {$cap}, line {$number}");
                    $this->assertSame($uncompacted->probe($number), $compacted->probe($number), "cap {$cap}, line {$number}");
                }

                $this->assertNull($compacted->head(count($expected) + 1));
            }
        }

        // 601 lines under a cap of 2 leave one checkpoint covering a block of 1024 lines.
        $file = LineIndexedFile::open($path, 2);
        $this->assertNotNull($file);
        $this->assertSame(8, strlen($this->checkpoints($file)));
        $this->assertSame(1024, $this->stride($file));
    }

    public function test_a_file_under_the_cap_keeps_32_lines_per_block(): void
    {
        $file = $this->open(implode("\n", array_map(static fn (int $n): string => "// line {$n}", range(1, 200))));

        $this->assertSame(32, $this->stride($file));
        $this->assertSame(7 * 8, strlen($this->checkpoints($file)));
        $this->assertSame('// line 150', $file->head(150));
    }

    public function test_a_clone_does_not_close_the_original(): void
    {
        $file = $this->open(implode("\n", array_map(static fn (int $n): string => "// line {$n}", range(1, 200))));
        $clone = clone $file;
        unset($clone);

        $this->assertSame('// line 150', $file->head(150));
    }

    public function test_a_clone_reads_on_after_the_original_is_closed(): void
    {
        $file = $this->open(implode("\n", array_map(static fn (int $n): string => "// line {$n}", range(1, 200))));
        $this->assertSame('// line 1', $file->head(1));
        $clone = clone $file;
        $file->close();

        $this->assertSame('// line 1', $clone->head(1));
        $this->assertSame('// line 200', $clone->head(200));
        $this->assertSame(200, $clone->lineCount());
    }

    public function test_a_clone_of_a_closed_file_stays_closed(): void
    {
        $file = $this->open("a\nb\n");
        $file->close();
        $clone = clone $file;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been closed');
        $clone->head(1);
    }

    public function test_a_clone_of_a_deleted_file_fails_loudly(): void
    {
        $path = $this->write("a\nb\n");
        $file = LineIndexedFile::open($path);
        $this->assertNotNull($file);
        unlink($path);
        $clone = clone $file;

        $this->assertSame('a', $file->head(1), 'The original keeps its handle on the old file');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been closed');
        $clone->head(1);
    }

    public function test_a_clone_after_the_path_is_replaced_never_reads_the_new_file(): void
    {
        $path = $this->write(implode("\n", array_map(static fn (int $n): string => sprintf('// line %03d', $n), range(1, 200))));
        $file = LineIndexedFile::open($path);
        $this->assertNotNull($file);
        $replacement = $this->write(implode("\n", array_map(static fn (int $n): string => sprintf('// other %05d', $n), range(1, 200))));
        rename($replacement, $path);
        $clone = clone $file;

        $this->assertSame('// line 150', $file->head(150), 'The original keeps its handle on the indexed file');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been closed');
        $clone->head(150);
    }

    private function checkpoints(LineIndexedFile $file): string
    {
        $checkpoints = (new \ReflectionProperty($file, 'checkpoints'))->getValue($file);
        $this->assertIsString($checkpoints);

        return $checkpoints;
    }

    private function stride(LineIndexedFile $file): int
    {
        $stride = (new \ReflectionProperty($file, 'stride'))->getValue($file);
        $this->assertIsInt($stride);

        return $stride;
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
