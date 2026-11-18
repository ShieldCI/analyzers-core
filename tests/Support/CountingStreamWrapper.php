<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Support;

/**
 * A pass-through stream wrapper that counts how often each file is opened and how many bytes
 * are read from it.
 *
 * `counting://<absolute path>` behaves like the plain file at that path - file_exists(),
 * is_file(), is_readable(), file_get_contents() and SplFileObject (including its seeks) all work
 * on it - while every stream_open() and every byte handed back by stream_read() is tallied.
 * That turns "this reads the file once per issue" into a deterministic assertion instead of a
 * wall-clock one.
 *
 * Call register() in setUp and unregister() in tearDown; unregister() also resets the tallies.
 */
final class CountingStreamWrapper
{
    public const SCHEME = 'counting';

    /** @var array<string, int> Opens per real path. */
    public static array $opens = [];

    /** @var array<string, int> Bytes read per real path. */
    public static array $bytesRead = [];

    /** @var resource|null Set by PHP on every wrapper instance. */
    public $context;

    /** @var resource|null */
    private $handle;

    private string $path = '';

    public static function register(): void
    {
        self::$opens = [];
        self::$bytesRead = [];

        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        self::$opens = [];
        self::$bytesRead = [];
    }

    public static function url(string $realPath): string
    {
        return self::SCHEME.'://'.$realPath;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = self::realPath($path);
        $handle = @fopen($this->path, $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;
        self::$opens[$this->path] = (self::$opens[$this->path] ?? 0) + 1;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->handle === null) {
            return false;
        }

        $data = fread($this->handle, max(1, $count));

        if ($data !== false) {
            self::$bytesRead[$this->path] = (self::$bytesRead[$this->path] ?? 0) + strlen($data);
        }

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->handle !== null && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $position = ftell($this->handle);

        return $position === false ? 0 : $position;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : fstat($this->handle);
    }

    /**
     * Declines every option. Defined so stream_set_read_buffer() fails quietly instead of
     * warning. A user-space stream is always read through PHP's 8192-byte buffer, so a read
     * after a seek costs up to 8192 bytes here even where a plain file would read fewer.
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $realPath = self::realPath($path);

        if (! file_exists($realPath)) {
            return false;
        }

        return stat($realPath);
    }

    private static function realPath(string $url): string
    {
        return substr($url, strlen(self::SCHEME.'://'));
    }
}
