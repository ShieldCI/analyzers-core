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

        foreach ($paths as $path) {
            $fullPath = PathHelper::join($basePath, $path);

            if (! is_dir($fullPath)) {
                if (is_file($fullPath)) {
                    yield new SplFileInfo($fullPath);
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
                if ($file->isFile()) {
                    yield $file;
                }
            }
        }
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
        // Not recorded if the excludes would drop a PHP file of any name inside
        // it. The probe is named with a NUL byte, which no real filename or
        // literal pattern holds, so only a wildcard ('vendor/*', '*.php') can
        // match it. The directory is skipped either way; only the report differs.
        $probe = $path.'/'."\0.php";

        if ($this->matchesExcludePattern(ltrim($this->getRelativePath($probe), '/'), $probe)) {
            return;
        }

        if (! in_array($path, $this->unreadablePaths, true)) {
            $this->unreadablePaths[] = $path;
        }
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
