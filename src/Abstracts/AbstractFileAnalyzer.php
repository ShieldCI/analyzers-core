<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Abstracts;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ShieldCI\AnalyzersCore\Support\PathHelper;
use SplFileInfo;

/**
 * Base class for analyzers that scan files.
 * Provides file filtering and iteration utilities.
 */
abstract class AbstractFileAnalyzer extends AbstractAnalyzer
{
    /**
     * Base path for analysis.
     */
    protected string $basePath = '';

    /**
     * Paths to analyze.
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * Patterns to exclude.
     *
     * @var array<string>
     */
    protected array $excludePatterns = [];

    /**
     * Pre-compiled regex patterns built from $excludePatterns.
     * Populated by setExcludePatterns() to avoid per-file glob→regex compilation.
     *
     * @var array<string>
     */
    private array $compiledExcludePatterns = [];

    /**
     * Directories the latest walk skipped because they could not be opened.
     *
     * @var list<string>
     */
    private array $unreadablePaths = [];

    /**
     * pathKey() of each recorded unreadable directory, so one spelled two ways
     * on Windows is recorded once.
     *
     * @var array<string, true>
     */
    private array $unreadableKeys = [];

    /**
     * Configured entries the latest walk found naming nothing.
     *
     * @var list<string>
     */
    private array $missingPaths = [];

    /**
     * Set the base path.
     *
     * Trims the same charlist as PathHelper, so a Windows base_path() handed in
     * as 'C:\app\' is stored the way every consumer expects to find it. This
     * used to rtrim '/' alone: relativisation still worked, because
     * PathHelper::relativeTo() trims both ends itself, but every path built by
     * concatenating onto the stored value double-separated.
     */
    public function setBasePath(string $path): static
    {
        $this->basePath = rtrim($path, '/\\');

        return $this;
    }

    /**
     * Set paths to analyze.
     *
     * @param array<string> $paths
     */
    public function setPaths(array $paths): static
    {
        $this->paths = $paths;

        return $this;
    }

    /**
     * Set exclude patterns.
     *
     * @param array<string> $patterns
     */
    public function setExcludePatterns(array $patterns): static
    {
        $this->excludePatterns = $patterns;
        $this->compiledExcludePatterns = array_map(
            fn (string $p): string => '#^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($p, '#')).'$#',
            $patterns
        );

        return $this;
    }

    /**
     * Get all PHP files to analyze.
     *
     * @return array<string>
     */
    protected function getPhpFiles(): array
    {
        $files = [];

        foreach ($this->getFilesToAnalyze() as $file) {
            if ($this->shouldAnalyzeFile($file)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get files to analyze based on configured paths.
     *
     * No configured paths means "scan the base path". That default used to be
     * installed by writing the base path into $this->paths, which the loop below
     * then joined the base path onto a second time - '/app' became '/app//app',
     * which is neither a directory nor a file, so the analyzer scanned nothing
     * and reported a pass. AnalyzerManager always calls setBasePath() but only
     * calls setPaths() when shieldci.paths.analyze is non-empty, so every file
     * analyzer in an app with that key empty or absent took this path silently.
     *
     * '' is the spelling callers already use for the same thing, and it costs
     * nothing here because PathHelper::join() answers the base path for it.
     *
     * The default is no longer written back. AnalyzerManager caches analyzer
     * instances, and FatModelAnalyzer and its siblings branch on
     * empty($this->paths) to install a narrower scan root of their own - a
     * getter that mutates is one reordering away from taking that away.
     *
     * A directory that cannot be opened or entered is skipped and recorded for
     * getUnreadablePaths() rather than thrown (#91). The iterator used to throw
     * out of the walk, and analyze() turned that into an Error result, so one
     * root-owned directory left every readable file unjudged.
     *
     * Each pathname is yielded once, in the order the walk first meets it
     * (#97). Every entry used to be walked on its own, so overlapping entries -
     * ['app', 'app/Http'], 'app' beside base_path('app') or './app', a file
     * beside its directory - yielded the same file twice and every issue in it
     * was reported twice. The dedup lives here rather than in getPhpFiles() so
     * subclasses that iterate this walk directly get it too. It compares
     * pathnames, not realpath(): a symlinked spelling ('applink' for 'app')
     * still yields its own pathnames, which excludes written against it rely
     * on, and so do '..' and case variants, which only resolution could match.
     *
     * Each configured entry is cleaned first (normaliseEntry()): repeated and
     * trailing separators are folded, so 'app//Http' and 'app/Providers/' walk
     * and report as 'app/Http' and 'app/Providers'. Folding only the dedup key
     * would not do - whichever spelling came first would be the one yielded,
     * and 'app//Http/...' slips past an 'app/Http/*' exclude. Only the entry
     * is cleaned, never the base: yielded paths must keep starting with the
     * base as stored, or relativeTo() misses and relative excludes and
     * locations stop applying under a base such as '/srv//p'. The key then
     * folds '\' to '/' on Windows only, where the root is joined with '/' but
     * the iterator appends DIRECTORY_SEPARATOR, so one file reached from 'app'
     * and from 'app/Http' arrives spelled two ways. With a single scan root
     * no pathname can repeat, so nothing is tracked.
     *
     * An entry that is neither a directory nor a file is recorded rather than
     * skipped in silence (#99). A typo ('ap'), an absolute path outside the
     * base, or a path behind a directory the walk cannot enter all used to
     * scan nothing, record nothing, and let the analyzer pass. One the walk
     * cannot reach records the directory in the way (getUnreadablePaths());
     * anything else is missing (getMissingPaths()).
     *
     * @return iterable<SplFileInfo>
     */
    protected function getFilesToAnalyze(): iterable
    {
        // Resolve through getBasePath(), not the raw property: shouldAnalyzeFile()
        // and the inherited getRelativePath() already do, and the scan root was
        // the last reader left that could disagree with them (#58, #62).
        $basePath = $this->getBasePath();
        $paths = $this->paths === [] ? [''] : $this->paths;
        $this->unreadablePaths = [];
        $this->unreadableKeys = [];
        $this->missingPaths = [];
        $track = count($paths) > 1;
        /** @var array<string, true> $yielded */
        $yielded = [];
        /** @var array<string, true> $missingKeys */
        $missingKeys = [];

        foreach ($paths as $path) {
            $entry = self::normaliseEntry($path);
            $fullPath = self::scanRoot($basePath, $entry);

            if (! is_dir($fullPath)) {
                if (is_file($fullPath)) {
                    if (! ($track && self::seenBefore($yielded, $fullPath))) {
                        yield new SplFileInfo($fullPath);
                    }
                } else {
                    $this->recordUnscannableEntry($entry, $fullPath, $missingKeys);
                }

                continue;
            }

            if (! $this->canEnterDirectory($fullPath)) {
                $this->recordUnreadablePath($fullPath);

                continue;
            }

            // The filter checks each directory before the walk descends into it.
            // CATCH_GET_CHILD is only the backstop for one that turns unreadable
            // between that check and the open: it swallows the exception without
            // saying which directory failed, so that skip goes unrecorded.
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    function (SplFileInfo $current, string $key, RecursiveDirectoryIterator $iterator): bool {
                        if ($iterator->hasChildren() && ! $this->canEnterDirectory($current->getPathname())) {
                            $this->recordUnreadablePath($current->getPathname());

                            return false;
                        }

                        return true;
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && ! ($track && self::seenBefore($yielded, $file->getPathname()))) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Whether $path was already in $seen, adding it if not.
     *
     * @param array<string, true> $seen
     */
    private static function seenBefore(array &$seen, string $path): bool
    {
        $key = self::pathKey($path);

        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;

        return false;
    }

    /**
     * The spelling two pathnames of one file share, for dedup only - what is
     * yielded and recorded keeps its own spelling. On Windows the scan root is
     * joined with '/' and the iterator appends '\', so the separators are
     * folded there. Not on POSIX, where '\' is a filename byte and 'a\b.php'
     * is a different file from 'a/b.php'.
     */
    private static function pathKey(string $path, bool $windows = DIRECTORY_SEPARATOR === '\\'): string
    {
        return $windows ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * Fold repeated separators in a configured entry and trim trailing ones
     * (#97).
     *
     * PathHelper::join() leaves both alone, and the iterator neither keeps a
     * trailing separator nor repeats one, so without this 'app/Providers/'
     * was recorded unreadable beside 'app/Providers', and 'app//Http' yielded
     * a second spelling of every file that relative excludes did not match.
     * The entry is cleaned before it reaches scanRoot(), so the base path is
     * joined on exactly as stored; an absolute entry's remainder comes back
     * from remainderUnderBase() already segment-normalised.
     *
     * Only '/' is a separator on POSIX, where '\' is a filename byte: 'weird\'
     * names its own directory and is left alone. On Windows both are. A
     * leading pair is kept, since '\\server\share' is a UNC root on Windows.
     * The trim never goes past a root: '/', '\' and, on Windows, a drive
     * root such as 'C:\' stay as they are, since '' and 'C:' name the working
     * directory instead. On POSIX 'C:' is an ordinary name and 'C:/' trims.
     */
    private static function normaliseEntry(string $path, bool $windows = DIRECTORY_SEPARATOR === '\\'): string
    {
        $separator = $windows ? '[/\\\\]' : '/';
        $other = $windows ? '[^/\\\\]' : '[^/]';
        $lead = preg_match('#^'.$separator.'{2}#', $path) === 1 ? substr($path, 0, 2) : '';
        $rest = substr($path, strlen($lead));

        if ($lead !== '') {
            $rest = (string) preg_replace('#^'.$separator.'+#', '', $rest);
        }

        $path = $lead.preg_replace('#('.$separator.')'.$separator.'+#', '$1', $rest);
        $driveRoot = $windows ? '(?<!^[A-Za-z]:)' : '';

        // \z, not $: '$' also matches before a final "\n", a filename byte on
        // POSIX, so "app/\n" was trimmed to "app\n".
        return (string) preg_replace('#(?<='.$other.')'.$driveRoot.$separator.'+\z#', '', $path);
    }

    /**
     * Directories the latest getFilesToAnalyze() walk skipped as unreadable.
     *
     * Spelled like the pathnames of the files the walk yields (so relative when
     * the base path is), each once, in the order the walk met them. A directory
     * in which the exclude patterns would drop every PHP file is skipped
     * silently.
     *
     * The record resets when a walk starts, so on a cached instance it never carries a
     * directory fixed since the last run - but it also describes only that walk:
     * read it after the walk you care about, and know that a walk abandoned
     * part-way has recorded only the directories it reached. Subclasses that
     * replace getFilesToAnalyze() record nothing here.
     *
     * @return list<string>
     */
    protected function getUnreadablePaths(): array
    {
        return $this->unreadablePaths;
    }

    /**
     * Configured entries the latest getFilesToAnalyze() walk found naming
     * nothing (#99).
     *
     * Spelled as configured once normaliseEntry() has cleaned them, so 'ap/'
     * is recorded as 'ap'; the default scan root is spelled '', as
     * setPaths(['']) is, and is only missing when the base path itself is.
     * Each entry once, in config order, under the first spelling seen; the
     * comparison is the walk's pathname key, so separator, './' and absolute
     * spellings of one location fold together but a '..' spelling does not.
     * An entry the exclude patterns fully cover - every PHP file under it, or
     * a '.php' entry matched itself - is not recorded, and neither is a
     * directory blocking it (excludesEntry()). An entry the
     * walk cannot reach because a directory above it cannot be entered is not
     * missing either: that directory is recorded in getUnreadablePaths()
     * instead. Nor is one naming something that is neither a file nor a
     * directory (a link to a device, a FIFO).
     *
     * The record resets when a walk starts, so on a cached instance it never
     * carries an entry created since the last run - but it also describes
     * only that walk: read it after the walk you care about, and know that a
     * walk abandoned part-way has recorded only the entries it reached.
     * Subclasses that replace getFilesToAnalyze() record nothing here.
     *
     * @return list<string>
     */
    protected function getMissingPaths(): array
    {
        return $this->missingPaths;
    }

    /**
     * Record a configured entry that is neither a directory nor a file.
     *
     * @param array<string, true> $missingKeys
     */
    private function recordUnscannableEntry(string $entry, string $fullPath, array &$missingKeys): void
    {
        // Silenced like realpath() in remainderUnderBase(): under open_basedir
        // every stat outside the allowed list warns, and this one can climb
        // above the base path.
        if (@file_exists($fullPath) || $this->excludesEntry($fullPath)) {
            return;
        }

        $blocker = $this->blockingDirectory($fullPath);

        if ($blocker !== null) {
            $this->recordUnreadablePath($blocker);

            return;
        }

        if (! self::seenBefore($missingKeys, $fullPath)) {
            $this->missingPaths[] = $entry;
        }
    }

    /**
     * Whether the excludes would drop everything an unscannable entry could
     * yield, so neither it nor the directory blocking it is worth reporting.
     *
     * The entry may be a directory or a file, and it is covered either way:
     * as a directory when the excludes drop every PHP file inside it (the
     * probe), as a file when they match the entry's own path in either
     * spelling. The second applies only to a '.php' entry - only that would
     * be analyzed as a file, and a literal 'lib' excludes no file below a
     * 'lib' directory, the same reason a literal directory exclude does not
     * hide an unreadable directory.
     */
    private function excludesEntry(string $fullPath): bool
    {
        if ($this->excludesEveryPhpFileIn($fullPath)) {
            return true;
        }

        return str_ends_with($fullPath, '.php')
            && $this->matchesExcludePattern(ltrim($this->getRelativePath($fullPath), '/'), $fullPath);
    }

    /**
     * The directory that hides $fullPath from the walk, or null when nothing
     * does and $fullPath is simply missing.
     *
     * A path cannot be stat'ed below a directory that cannot be searched, so
     * every ancestor under the blocking one looks missing too: the nearest
     * ancestor that does exist is the one to judge. dirname() stops at '/'
     * (or a drive root) for an absolute path and at '.' for a relative one.
     *
     * Only the search bit blocks a stat, so this is narrower than
     * canEnterDirectory(): under a search-only (0111) directory the entry
     * could be stat'ed and is genuinely missing, while 0444 and 0000 block.
     * Windows has no search bit, so the same split as canEnterDirectory()
     * applies and readability stands in for it there.
     */
    private function blockingDirectory(string $fullPath): ?string
    {
        $dir = dirname($fullPath);

        while (! @file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }

        if (! @is_dir($dir)) {
            return null;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return is_readable($dir) ? null : $dir; // @codeCoverageIgnore
        }

        return is_executable($dir) ? null : $dir;
    }

    /**
     * Whether the walk can list a directory and stat what is in it.
     *
     * Read permission alone is not enough: a 0444 directory lists its entries
     * but none of them can be stat'ed, so the walk would drop them as non-files
     * without failing. Windows has no search bit and is_executable() answers
     * false for every directory there, so the search check is POSIX-only.
     */
    private function canEnterDirectory(string $dir): bool
    {
        if (! is_readable($dir)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return true; // @codeCoverageIgnore
        }

        return is_executable($dir);
    }

    private function recordUnreadablePath(string $path): void
    {
        // The directory is skipped either way; only the report differs.
        if ($this->excludesEveryPhpFileIn($path)) {
            return;
        }

        if (! self::seenBefore($this->unreadableKeys, $path)) {
            $this->unreadablePaths[] = $path;
        }
    }

    /**
     * Whether the excludes would drop a PHP file of any name inside $path.
     * The probe is named with a NUL byte, which no real filename or literal
     * pattern holds, so only a wildcard ('vendor/*', '*.php') can match it.
     */
    private function excludesEveryPhpFileIn(string $path): bool
    {
        $probe = $path.'/'."\0.php";

        return $this->matchesExcludePattern(ltrim($this->getRelativePath($probe), '/'), $probe);
    }

    /**
     * Match the compiled exclude patterns against both spellings of a path, the
     * relative one and the absolute one, as shouldAnalyzeFile() explains.
     */
    private function matchesExcludePattern(string $relativePath, string $path): bool
    {
        foreach ($this->compiledExcludePatterns as $regex) {
            if (preg_match($regex, $relativePath) === 1 || preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where to walk for one configured path.
     *
     * Joined onto the base path first, exactly as before #96. Only when that
     * names nothing is an entry spelling out a location inside the base path -
     * base_path('app') in a config file - rewritten to its remainder. join()
     * strips leading separators, so such an entry used to be appended to the
     * base: '/srv/app' walked '/srv/srv/app', found nothing, and the analyzer
     * passed having read no file (#96).
     *
     * The join wins whenever it exists because the string alone cannot decide.
     * Under a top-level base such as Docker's WORKDIR /app, '/app' is both the
     * base itself and the project-relative 'app' directory, and the latter is
     * what it always meant. This way the fix only changes entries that
     * scanned nothing. The remainder is joined rather than walked raw so that
     * reported paths, '.' collapsing (#84) and exclude matching come out
     * byte-identical to the relative spelling.
     */
    private static function scanRoot(string $basePath, string $path): string
    {
        $fullPath = PathHelper::join($basePath, $path);

        if (file_exists($fullPath)) {
            return $fullPath;
        }

        $remainder = self::remainderUnderBase($path, $basePath);

        return $remainder === null ? $fullPath : PathHelper::join($basePath, $remainder);
    }

    /**
     * The part of $path below $basePath, or null when $path is not inside it.
     *
     * Only an entry inside the base path is read as absolute. Anything else -
     * '/', '/app', '\app', 'a:/b', an absolute path elsewhere - is joined
     * onto the base exactly as before: a leading separator meaning
     * project-relative is an established convention (Laravel's
     * base_path('/app'), join('/var/www/project', '/src')), and treating '/'
     * as the filesystem root would walk the whole disk. An absolute path
     * outside the base therefore still scans nothing, as it did before #96;
     * that is accepted for now.
     *
     * The comparison is lexical first, against an absolute base only - a
     * relative base such as '0' would otherwise rewrite '0/x' to 'x'. Failing
     * that, an entry with a leading separator is compared by realpath(), so a
     * symlinked spelling of the base (/var vs /private/var on macOS) is still
     * recognised. The realpath branch is limited to such entries because a
     * relative one would resolve against the working directory. No case
     * folding below the drive letter: that would be wrong on case-sensitive
     * filesystems. A miscased spelling of the base is still recognised where
     * the filesystem says it is the same directory (step 2).
     *
     * Order matters, and each step exists because the one after it resolves
     * more than it should:
     * 1. The entry as text against the base as given - cheap, and the common
     *    case.
     * 2. The entry's prefixes, shortest first, until one is the base: its
     *    realpath() equals realpath(base), or failing that it is the same
     *    directory (sameDirectory()). The rest of the entry is the remainder
     *    as typed. This finds the base however it is reached (/var vs
     *    /private/var, a second link to the project) without resolving
     *    anything below it. The identity check is for case-insensitive
     *    filesystems (macOS APFS, Windows NTFS by default), where realpath()
     *    keeps the case it was given: with base '/t/proj', the entry
     *    '/t/PROJ/app' resolved to '/t/PROJ', never matched, and was scanned
     *    as nothing and recorded missing although the directory exists. On a
     *    case-sensitive filesystem '/t/PROJ' is another directory, or none,
     *    so nothing changes there. A prefix that is the base but leaves a
     *    remainder with '..' does not end the walk: '/t/PROJ/../proj/x'
     *    matches at '/t/PROJ', is refused there, and is claimed at
     *    '/t/PROJ/../proj'. Every prefix that matches is the base itself and
     *    a '..' remainder is always refused, so going on cannot claim a path
     *    outside it.
     * 3. realpath() of the whole entry, for a symlink from outside the base
     *    that points into it, where no prefix resolves to the base.
     * Resolving the whole entry any earlier resolves every symlink inside the
     * base too, so 'applink' came back as 'app' and excludes written against
     * 'applink/*' stopped applying.
     *
     * $sameDirectory replaces sameDirectory() in tests only, so the identity
     * branch can be exercised on a case-sensitive filesystem as well.
     *
     * @param (\Closure(string, string): bool)|null $sameDirectory
     */
    private static function remainderUnderBase(string $path, string $basePath, ?\Closure $sameDirectory = null): ?string
    {
        if (preg_match('#^(?:[/\\\\]|[A-Za-z]:[/\\\\])#', $basePath) === 1) {
            $remainder = self::relativeRemainder($path, $basePath);

            if ($remainder !== null) {
                return $remainder;
            }
        }

        if (! str_starts_with($path, '/') && ! str_starts_with($path, '\\')) {
            return null;
        }

        // Every realpath() is silenced, as FileParser silences token_get_all():
        // under open_basedir it warns for any path outside the allowed list,
        // and a host that turns warnings into exceptions (Laravel's
        // HandleExceptions) would fail an analysis that the pre-#96 walk,
        // which never called realpath(), passed.
        $realBase = @realpath($basePath);

        if ($realBase === false) {
            return null;
        }

        // Shortest prefix first, one realpath() each, stopping at the first
        // that is the base and leaves a remainder without '..'. The rest of
        // the entry is kept as typed.
        preg_match_all('#[/\\\\]+#', $path, $separators, PREG_OFFSET_CAPTURE);
        $sameDirectory ??= self::sameDirectory(...);

        foreach ([...$separators[0], ['', strlen($path)]] as [, $offset]) {
            $realPrefix = $offset > 0 ? @realpath(substr($path, 0, $offset)) : false;

            if ($realPrefix !== false && ($realPrefix === $realBase || $sameDirectory($realPrefix, $realBase))) {
                $remainder = self::relativeRemainder($realBase.'/'.substr($path, $offset), $realBase);

                if ($remainder !== null) {
                    return $remainder;
                }
            }
        }

        $realPath = @realpath($path);

        return $realPath === false ? null : self::relativeRemainder($realPath, $realBase);
    }

    /**
     * Whether two paths that realpath() has already resolved name the same
     * directory, by device and inode: on a case-insensitive filesystem
     * '/t/PROJ' and '/t/proj' are one directory that realpath() spells two
     * ways. Only resolved paths are passed, so stat() is never asked about a
     * missing path or one outside open_basedir - realpath() has already
     * answered false for those - and adds no warning the walk did not have.
     * It is silenced all the same, like every realpath() above, for a path
     * removed in between. An inode of 0 means the platform reported none
     * (older Windows builds), and would make every path on a drive the
     * base, so it never matches.
     */
    private static function sameDirectory(string $realPath, string $realBase): bool
    {
        $path = @stat($realPath);
        $base = @stat($realBase);

        return $path !== false && $base !== false && $path['ino'] !== 0
            && $path['dev'] === $base['dev'] && $path['ino'] === $base['ino'];
    }

    /**
     * Strip $basePath off $path on a segment boundary, comparing both with
     * forward slashes, repeated separators collapsed and '.' segments dropped.
     * The boundary is what stops base '/p' from claiming '/proj/x'. On
     * Windows a drive letter is upper-cased, since Windows never tells 'c:'
     * from 'C:': 'c:\proj\app' under base 'C:\proj' used to be refused, and
     * a drive-letter entry never reaches the realpath() steps that could have
     * matched it. Only the letter is folded: the case of the rest of a path
     * is the filesystem's business, not this comparison's. On POSIX 'c:' and
     * 'C:' are two ordinary directories, so nothing is folded there. The POSIX
     * root '/' claims nothing: joining onto it already answers the path
     * itself. A drive root ('C:\') normalises to 'C:' and does claim what
     * lies below it, which joins back to the same path.
     *
     * A remainder with a '..' segment is refused: '/p/..' and
     * '/p/app/../../outside' start with '/p' but resolve outside it, where the
     * anchored excludes cannot reach ('../p/vendor/...' escapes 'vendor/*').
     * That leaves '..' to the realpath() comparison, which resolves it, so an
     * entry that stays inside the base is still claimed.
     */
    private static function relativeRemainder(string $path, string $basePath, bool $windows = DIRECTORY_SEPARATOR === '\\'): ?string
    {
        $normalise = static function (string $value) use ($windows): string {
            $value = str_replace('\\', '/', $value);

            if ($windows && preg_match('#^[a-z]:#', $value) === 1) {
                $value = strtoupper($value[0]).substr($value, 1);
            }

            $segments = array_filter(
                explode('/', $value),
                static fn (string $segment): bool => $segment !== '' && $segment !== '.'
            );

            return (str_starts_with($value, '/') ? '/' : '').implode('/', $segments);
        };

        $path = $normalise($path);
        $base = $normalise($basePath);

        if ($base === '/' || $base === '') {
            return null;
        }

        if ($path === $base) {
            return '';
        }

        if (! str_starts_with($path, $base.'/')) {
            return null;
        }

        $remainder = substr($path, strlen($base) + 1);

        return in_array('..', explode('/', $remainder), true) ? null : $remainder;
    }

    /**
     * Check if a file should be analyzed.
     */
    protected function shouldAnalyzeFile(SplFileInfo $file): bool
    {
        // Only PHP files
        if ($file->getExtension() !== 'php') {
            return false;
        }

        $path = $file->getPathname();
        // Exclude patterns are written relative to the base path ('vendor/*'),
        // so match the relative path; also match the absolute pathname for
        // patterns that relied on it ('*/vendor/*').
        $relativePath = ltrim($this->getRelativePath($path), '/');

        return ! $this->matchesExcludePattern($relativePath, $path);
    }

    /**
     * Check if path matches a glob pattern.
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Convert glob pattern to regex
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);

        return (bool) preg_match("#^{$pattern}$#", $path);
    }

    /**
     * Override getBasePath to use explicitly set $basePath property.
     *
     * FileAnalyzers allow setting a custom basePath (primarily for testing).
     * This override ensures that when basePath is explicitly set via setBasePath(),
     * it takes precedence over Laravel's base_path() helper.
     *
     * This is also the single lever for relative-path reporting. getRelativePath()
     * used to be overridden here to read $this->basePath directly, which skipped this
     * fallback chain and the separator normalisation the parent does - so with no base
     * path set, shouldAnalyzeFile() and the inherited createIssueWithSnippet() could
     * disagree about what "relative" meant on the same instance. Resolve through here
     * rather than re-adding that override.
     *
     * @return string The base path (from $basePath property or parent fallback)
     */
    protected function getBasePath(): string
    {
        // Use the explicitly set basePath if available (for testing scenarios)
        if ($this->basePath !== '') {
            return $this->basePath;
        }

        // Otherwise delegate to parent implementation (base_path() or getcwd())
        return parent::getBasePath();
    }

    /**
     * Override getEnvironment to prioritize .env file reading.
     *
     * File analyzers often need to read from .env files in test scenarios
     * where basePath is explicitly set. This method prioritizes .env file
     * reading over the config() helper for more accurate test scenarios.
     *
     * @return string The environment name (e.g., 'local', 'production', 'staging')
     */
    protected function getEnvironment(): string
    {
        // Priority 1: Read from .env file if basePath is set (test scenarios).
        // Deliberately the raw property rather than getBasePath(): this branch
        // exists for an explicitly set base path, and resolving through the
        // fallback would make every production analyzer prefer .env over
        // config('app.env'). The guard compares against '' because empty() reads
        // a base path of '0' as no base path at all.
        if ($this->basePath !== '') {
            $envFile = PathHelper::join($this->basePath, '.env');
            if (file_exists($envFile)) {
                $content = file_get_contents($envFile);
                // Use [^\s#"']+ so hyphenated names like "local-test" are captured in full
                if ($content !== false && preg_match('/^APP_ENV\s*=\s*["\']?([^\s#"\']+)["\']?/m', $content, $matches)) {
                    // Apply environment mapping, consistent with parent::getEnvironment()
                    return $this->applyEnvironmentMapping($matches[1]);
                }
            }
        }

        // Priority 2: Fall back to parent's implementation (config helper)
        return parent::getEnvironment();
    }
}
