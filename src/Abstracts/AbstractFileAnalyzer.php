<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Abstracts;

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
     * @return iterable<SplFileInfo>
     */
    protected function getFilesToAnalyze(): iterable
    {
        // Resolve through getBasePath(), not the raw property: shouldAnalyzeFile()
        // and the inherited getRelativePath() already do, and the scan root was
        // the last reader left that could disagree with them (#58, #62).
        $basePath = $this->getBasePath();
        $paths = $this->paths === [] ? [''] : $this->paths;

        foreach ($paths as $path) {
            $fullPath = PathHelper::join($basePath, $path);

            if (! is_dir($fullPath)) {
                if (is_file($fullPath)) {
                    yield new SplFileInfo($fullPath);
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
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

        foreach ($this->compiledExcludePatterns as $regex) {
            if (preg_match($regex, $relativePath) === 1 || preg_match($regex, $path) === 1) {
                return false;
            }
        }

        return true;
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
