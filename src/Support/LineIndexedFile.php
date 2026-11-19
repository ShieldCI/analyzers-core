<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Random access to a file's lines with an index of bounded size.
 *
 * open() streams the file once, recording the byte offset where every stride-th line starts.
 * The stride begins at FIRST_STRIDE lines; when the index reaches MAX_CHECKPOINTS offsets it
 * drops every other one and the stride doubles, so it only grows for a file of more than
 * MAX_CHECKPOINTS * FIRST_STRIDE lines. Asking for a line then streams only the block of
 * stride lines it falls in, in READ_CHUNK pieces, and keeps two short strings per line - never
 * the line itself:
 *
 * - head(): the first HEAD_BYTES raw bytes, enough to render the line.
 * - probe(): enough of the trimmed line to test it for a signature or a closing brace.
 *
 * So the index costs at most MAX_CHECKPOINTS * CHECKPOINT_BYTES however many lines the file
 * has, a cached block costs at most stride * (HEAD_BYTES + PROBE_BYTES) bytes of text, and a
 * single read costs one READ_CHUNK - however long the lines are. A caller asking for many
 * lines of one file reads it once plus the blocks it touches, never once per line.
 *
 * Lines are split on "\n" only, as `explode("\n", $contents)` would: a file ending in "\n" has a
 * final empty line, an empty file is one empty line, and a CRLF line keeps its "\r". That is
 * the line numbering CodeSnippet has always used.
 *
 * @internal Used by CodeSnippet and AbstractAnalyzer; not part of the public API.
 */
final class LineIndexedFile
{
    /**
     * Raw bytes of each line kept for display. CodeSnippet shows 250 and looks up to three
     * bytes past the cut to avoid splitting a UTF-8 character.
     */
    public const HEAD_BYTES = 256;

    /**
     * Bytes of the whitespace-collapsed, left-trimmed line kept for the signature search. Its
     * patterns are anchored at the start and the longest needs under 30 bytes to match.
     */
    private const PROBE_BYTES = 64;

    /**
     * Lines per index block until the index is full. Small enough that a snippet reads a few
     * short blocks; the index of a million-line file is ~31k offsets.
     */
    private const FIRST_STRIDE = 32;

    /**
     * Most offsets the index holds: 512 KB. Reached only past ~2M lines at FIRST_STRIDE; each
     * time it fills, half are dropped and the stride doubles.
     */
    private const MAX_CHECKPOINTS = 65536;

    /** Bytes per offset in the index, as pack('P'): unsigned 64-bit, little-endian. */
    private const CHECKPOINT_BYTES = 8;

    /**
     * How many decoded blocks to keep. A snippet spans at most a handful of blocks (a 15-line
     * signature search plus the context window), so this covers one snippet and its neighbours.
     */
    private const CACHED_BLOCKS = 4;

    /** Bytes per read, both while indexing and while reading a block. */
    private const READ_CHUNK = 8192;

    /** What trim() strips. */
    private const TRIM_CHARS = " \t\n\r\0\x0B";

    /** What PCRE's \s matches without the u modifier. */
    private const PCRE_SPACE_RUN = '/[\t\n\x0B\f\r ]+/';

    /**
     * @var array<int, list<array{string, string}>> Decoded blocks of [head, probe] by block
     *                                                number, least recently used first.
     */
    private array $blocks = [];

    /**
     * @param resource|null $handle Unbuffered, positioned anywhere; null once unusable
     * @param string $checkpoints pack('P*') of the byte offset of line (n * $stride) + 1
     * @param int $stride Lines per block: FIRST_STRIDE times a power of two
     * @param array{size: int, mtime: int, ino: int} $fingerprint What the file looked like when indexed
     */
    private function __construct(
        private readonly string $path,
        private $handle,
        private readonly int $size,
        private readonly int $lineCount,
        private readonly string $checkpoints,
        private readonly int $stride,
        private readonly array $fingerprint,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * A clone opens the path again, so closing either leaves the other readable. A clone of a
     * closed file, or of a path that no longer opens to a file with the indexed size, mtime and
     * inode (deleted, replaced by rename, rewritten), cannot be read: this index's offsets would
     * show another file's text.
     */
    public function __clone()
    {
        $handle = is_resource($this->handle) ? self::openHandle($this->path) : null;

        if ($handle !== null && self::describe(@fstat($handle)) !== $this->fingerprint) {
            fclose($handle);
            $handle = null;
        }

        $this->handle = $handle;
    }

    /**
     * Index a file. Returns null if the path is missing, not a regular file, or unreadable.
     *
     * @param int $maxCheckpoints Index size limit, at least 2; lowered only by tests, to compact
     *                            a small file
     */
    public static function open(string $path, int $maxCheckpoints = self::MAX_CHECKPOINTS): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = self::openHandle($path);

        if ($handle === null) {
            return null; // @codeCoverageIgnore
        }

        $maxBytes = max(2, $maxCheckpoints) * self::CHECKPOINT_BYTES;
        $fingerprint = self::fingerprint($path);
        $checkpoints = pack('P', 0);
        $stride = self::FIRST_STRIDE;
        $newlines = 0;
        $offset = 0;

        while (! feof($handle)) {
            $chunk = fread($handle, self::READ_CHUNK);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $position = -1;

            while (($position = strpos($chunk, "\n", $position + 1)) !== false) {
                $newlines++;

                if ($newlines % $stride === 0) {
                    $checkpoints .= pack('P', $offset + $position + 1);

                    if (strlen($checkpoints) >= $maxBytes) {
                        // Offset n covers line n * stride + 1, so the even ones are exactly the
                        // lines a stride twice as long starts its blocks at.
                        $checkpoints = self::everyOther($checkpoints);
                        $stride *= 2;
                    }
                }
            }

            $offset += strlen($chunk);
        }

        return new self($path, $handle, $offset, $newlines + 1, $checkpoints, $stride, $fingerprint);
    }

    /**
     * The number of lines, counted as `count(explode("\n", $contents))`.
     */
    public function lineCount(): int
    {
        return $this->lineCount;
    }

    /**
     * The first HEAD_BYTES raw bytes of 1-indexed line $number, without its "\n", or null if
     * there is no such line.
     *
     * @throws \LogicException If the file has been closed
     */
    public function head(int $number): ?string
    {
        return $this->entry($number)[0] ?? null;
    }

    /**
     * A short stand-in for `trim($line)` that start-anchored patterns can be tested against,
     * or null if there is no such line.
     *
     * When the trimmed line is at most two bytes it is exactly `trim($line)`, so comparisons
     * with '}' and '};' are exact. Otherwise it is the left-trimmed line with each run of PCRE
     * whitespace collapsed to one space, cut to PROBE_BYTES: a pattern anchored with ^ and
     * built from literals, \s+ and \w+ matches it exactly when it matches `trim($line)`, and it
     * is never equal to '}' or '};'.
     *
     * @throws \LogicException If the file has been closed
     */
    public function probe(int $number): ?string
    {
        return $this->entry($number)[1] ?? null;
    }

    /**
     * Whether the file on disk still has the size, mtime and inode it had when indexed.
     *
     * A rewrite that keeps all three (same size, within the same second, in place) is not
     * detected; anything else is, and the caller should index the file again.
     */
    public function isCurrent(): bool
    {
        return self::fingerprint($this->path) === $this->fingerprint;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->blocks = [];
    }

    /**
     * @return array{string, string}|null
     */
    private function entry(int $number): ?array
    {
        if ($number < 1 || $number > $this->lineCount) {
            return null;
        }

        $block = intdiv($number - 1, $this->stride);

        return $this->block($block)[($number - 1) % $this->stride] ?? null;
    }

    /**
     * @return list<array{string, string}>
     */
    private function block(int $block): array
    {
        if (isset($this->blocks[$block])) {
            // Move it to the most recently used end.
            $entries = $this->blocks[$block];
            unset($this->blocks[$block]);

            return $this->blocks[$block] = $entries;
        }

        $entries = $this->readBlock($block);

        if (count($this->blocks) >= self::CACHED_BLOCKS) {
            unset($this->blocks[array_key_first($this->blocks)]);
        }

        return $this->blocks[$block] = $entries;
    }

    /**
     * Stream one block, keeping [head, probe] per line.
     *
     * @return list<array{string, string}>
     */
    private function readBlock(int $block): array
    {
        // entry() asks only for blocks that hold a line, and every such block has an offset.
        $start = $this->checkpoint($block) ?? $this->size;
        $next = $this->checkpoint($block + 1);
        $isLastBlock = $next === null;
        $remaining = ($next ?? $this->size) - $start;

        if (! is_resource($this->handle) || fseek($this->handle, $start) !== 0) {
            throw new \LogicException("Cannot read {$this->path}: it has been closed");
        }

        $entries = [];
        $line = self::newLine();

        while ($remaining > 0) {
            $chunk = fread($this->handle, min($remaining, self::READ_CHUNK));

            // The file shrank since it was indexed; keep what is there.
            if ($chunk === false || $chunk === '') {
                break;
            }

            $remaining -= strlen($chunk);
            $segments = explode("\n", $chunk);
            $lastSegment = array_pop($segments);

            foreach ($segments as $segment) {
                $entries[] = self::finishLine(self::feedLine($line, $segment));
                $line = self::newLine();
            }

            $line = self::feedLine($line, $lastSegment);
        }

        // Every block but the last ends with the "\n" before the next checkpoint, so what is
        // open now is the start of the next block. The last block's open line is its last line.
        if ($isLastBlock) {
            $entries[] = self::finishLine($line);
        }

        return $entries;
    }

    /**
     * The byte offset where block $block starts, or null past the last block.
     */
    private function checkpoint(int $block): ?int
    {
        if (($block + 1) * self::CHECKPOINT_BYTES > strlen($this->checkpoints)) {
            return null;
        }

        /** @var array{1: int} $offset */
        $offset = unpack('P', $this->checkpoints, $block * self::CHECKPOINT_BYTES);

        return $offset[1];
    }

    /**
     * Open a path for reading, or null if it cannot be.
     *
     * @return resource|null
     */
    private static function openHandle(string $path)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        // Unbuffered, so each fread() asks the file for exactly what is wanted. Streams that
        // refuse (some user-space wrappers) stay buffered, which costs bytes but not correctness.
        @stream_set_read_buffer($handle, 0);

        return $handle;
    }

    /**
     * Keep the 1st, 3rd, 5th, ... offset of a packed index.
     */
    private static function everyOther(string $checkpoints): string
    {
        $kept = '';

        for ($at = 0, $length = strlen($checkpoints); $at < $length; $at += 2 * self::CHECKPOINT_BYTES) {
            $kept .= substr($checkpoints, $at, self::CHECKPOINT_BYTES);
        }

        return $kept;
    }

    /**
     * @return array{head: string, started: bool, prefix: string, length: int, last: int, collapsed: string}
     */
    private static function newLine(): array
    {
        return ['head' => '', 'started' => false, 'prefix' => '', 'length' => 0, 'last' => -1, 'collapsed' => ''];
    }

    /**
     * Add the next piece of a line to its running state.
     *
     * "prefix", "length" and "last" describe the left-trimmed line: its first two bytes, its
     * length so far, and the index of its last byte trim() would keep.
     *
     * @param array{head: string, started: bool, prefix: string, length: int, last: int, collapsed: string} $line
     * @return array{head: string, started: bool, prefix: string, length: int, last: int, collapsed: string}
     */
    private static function feedLine(array $line, string $segment): array
    {
        if (strlen($line['head']) < self::HEAD_BYTES) {
            $line['head'] .= substr($segment, 0, self::HEAD_BYTES - strlen($line['head']));
        }

        if (! $line['started']) {
            $segment = substr($segment, strspn($segment, self::TRIM_CHARS));

            if ($segment === '') {
                return $line;
            }

            $line['started'] = true;
        }

        if (strlen($line['prefix']) < 2) {
            $line['prefix'] .= substr($segment, 0, 2 - strlen($line['prefix']));
        }

        $kept = rtrim($segment, self::TRIM_CHARS);

        if ($kept !== '') {
            $line['last'] = $line['length'] + strlen($kept) - 1;
        }

        $line['length'] += strlen($segment);

        if (strlen($line['collapsed']) < self::PROBE_BYTES) {
            $collapsed = (string) preg_replace(self::PCRE_SPACE_RUN, ' ', $segment);

            if (str_ends_with($line['collapsed'], ' ') && str_starts_with($collapsed, ' ')) {
                $collapsed = substr($collapsed, 1);
            }

            $line['collapsed'] .= substr($collapsed, 0, self::PROBE_BYTES - strlen($line['collapsed']));
        }

        return $line;
    }

    /**
     * @param array{head: string, started: bool, prefix: string, length: int, last: int, collapsed: string} $line
     * @return array{string, string} [head, probe]
     */
    private static function finishLine(array $line): array
    {
        $probe = $line['last'] < 2 ? substr($line['prefix'], 0, $line['last'] + 1) : $line['collapsed'];

        return [$line['head'], $probe];
    }

    /**
     * @return array{size: int, mtime: int, ino: int}
     */
    private static function fingerprint(string $path): array
    {
        clearstatcache(true, $path);

        return self::describe(@stat($path));
    }

    /**
     * @param array{size: int, mtime: int, ino: int}|false $stat From stat() or fstat()
     * @return array{size: int, mtime: int, ino: int}
     */
    private static function describe(array|false $stat): array
    {
        if ($stat === false) {
            return ['size' => -1, 'mtime' => -1, 'ino' => -1];
        }

        return ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'ino' => $stat['ino']];
    }
}
