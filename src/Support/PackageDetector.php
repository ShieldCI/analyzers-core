<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Detects installed Laravel packages.
 *
 * Provides static methods to detect whether specific Laravel packages
 * are installed in a project by parsing composer.lock.
 *
 * Uses caching to minimize file I/O operations when checking multiple
 * packages in the same request. Cache is reset between test runs.
 */
class PackageDetector
{
    /**
     * Cache for package detection results.
     *
     * Format: ['package-name@/path/to/project' => bool]
     *
     * @var array<string, bool>
     */
    private static array $packageCache = [];

    /**
     * Cache for composer.lock file content per base path.
     *
     * Format: ['/path/to/project' => 'lock file content' or null if file doesn't exist]
     *
     * @var array<string, string|null>
     */
    private static array $lockContentCache = [];

    /**
     * Cache for the packages composer.lock lists, per base path.
     *
     * Format: ['/path/to/project' => ['vendor/package' => '1.2.3' or null] or null if the lock is not valid JSON]
     *
     * A package's version is null when its entry has none, or one that is not a string; the
     * package is still installed, so membership is array_key_exists(), not isset().
     *
     * @var array<string, array<string, string|null>|null>
     */
    private static array $installedPackagesCache = [];

    /**
     * Check if a specific package is installed.
     *
     * Parses composer.lock to detect package presence. This is the most
     * authoritative source as it reflects actually installed packages.
     *
     * @param  string  $packageName  Full package name (e.g., "laravel/nova")
     * @param  string  $basePath  Application base path
     * @return bool True if package is installed
     */
    public static function hasPackage(string $packageName, string $basePath): bool
    {
        $cacheKey = $packageName.'@'.$basePath;

        // Return cached result if available
        if (array_key_exists($cacheKey, self::$packageCache)) {
            return self::$packageCache[$cacheKey];
        }

        $lockContent = self::getComposerLockContent($basePath);

        if ($lockContent === null) {
            self::$packageCache[$cacheKey] = false;

            return false;
        }

        $installed = self::getInstalledPackages($basePath, $lockContent);

        if ($installed !== null) {
            $found = array_key_exists($packageName, $installed);
        } else {
            // Not valid JSON, e.g. a lock git left conflict markers in: search the text for
            // "name": "vendor/package", with any whitespace around the colon and the slash
            // optionally escaped, so a minified lock reads the same as Composer's own layout.
            $name = str_replace('/', '\\\\?/', preg_quote($packageName, '~'));
            $found = preg_match('~"name"\s*:\s*"'.$name.'"~', $lockContent) === 1;
        }

        self::$packageCache[$cacheKey] = $found;

        return $found;
    }

    /**
     * Get the version of an installed package, as composer.lock writes it.
     *
     * The string is not normalised: "v10.48.4", "1.2.3" and "dev-main" come back as written.
     * It is the JSON-decoded value, so an invalid UTF-8 byte (which Composer never writes) comes
     * back as U+FFFD, as it does for every other string hasPackage() reads from the lock.
     * The name is matched as hasPackage() matches it, case-sensitively, under "packages" or
     * "packages-dev"; if a name is listed twice, the first entry wins.
     *
     * Null when there is no readable lock, when the package is not listed, or when its entry
     * has no string version. Also null when the lock is not valid JSON, even where hasPackage()
     * still finds the name in the text: a lock left with conflict markers can hold two versions
     * of one package, and the text cannot say which is installed.
     *
     * Shares hasPackage()'s caches, so the lock is read and decoded once per base path until
     * clearCache().
     *
     * @param  string  $packageName  Full package name (e.g., "laravel/framework")
     * @param  string  $basePath  Application base path
     * @return string|null The version as written in composer.lock, or null
     */
    public static function installedVersion(string $packageName, string $basePath): ?string
    {
        $lockContent = self::getComposerLockContent($basePath);

        if ($lockContent === null) {
            return null;
        }

        return self::getInstalledPackages($basePath, $lockContent)[$packageName] ?? null;
    }

    /**
     * Check if Laravel Nova is installed.
     *
     * Laravel Nova is a commercial administration panel for Laravel applications.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Nova is installed
     *
     * @see https://nova.laravel.com/
     */
    public static function hasNova(string $basePath): bool
    {
        return self::hasPackage('laravel/nova', $basePath);
    }

    /**
     * Check if Filament is installed.
     *
     * Filament is an open-source administration panel and form builder for
     * Laravel applications.
     *
     * Note: The vendor was renamed between major versions:
     * - Filament v3 and earlier: "filamentphp/filament"
     * - Filament v4+: "filament/filament"
     * Both names are checked so detection works across all supported versions.
     *
     * This method only checks composer.lock. Use isFilamentConfigured() to verify
     * that Filament panels have been set up via artisan commands.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Filament is installed
     *
     * @see https://filamentphp.com/
     */
    public static function hasFilament(string $basePath): bool
    {
        // v3 and earlier used the "filamentphp" vendor; v4+ renamed it to "filament"
        return self::hasPackage('filamentphp/filament', $basePath)
            || self::hasPackage('filament/filament', $basePath);
    }

    /**
     * Check if Filament is configured with panel providers.
     *
     * @deprecated 2.8.0 Builds a throwaway parser and drops it, so the verdict arrives with no
     *                   way to ask whether a provider file failed to parse and had its class
     *                   name recovered from source. Use FilamentPanelDetector::isConfigured()
     *                   with a parser you keep, then read failures() and recoveries().
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Filament is installed, configured, and registered
     *
     * @see https://filamentphp.com/docs/4.x/panels/installation
     */
    public static function isFilamentConfigured(string $basePath): bool
    {
        return (new FilamentPanelDetector(new AstParser()))->isConfigured($basePath);
    }

    /**
     * Check if a service provider is registered.
     *
     * Checks both Laravel 11+ (bootstrap/providers.php) and Laravel 10- (config/app.php).
     *
     * Public because FilamentPanelDetector asks the same question and isHorizonConfigured()
     * below still needs it here -- moving it would leave this class reaching into a detector
     * that carries a parser it has no use for.
     *
     * @param  string|array<string>  $providerClasses  Provider class name(s) to check
     * @param  string  $basePath  Application base path
     * @return bool True if at least one provider is registered
     */
    public static function isServiceProviderRegistered(string|array $providerClasses, string $basePath): bool
    {
        $providers = is_array($providerClasses) ? $providerClasses : [$providerClasses];

        // Laravel 11+: Check bootstrap/providers.php
        $bootstrapProviders = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'providers.php';
        if (file_exists($bootstrapProviders)) {
            $content = file_get_contents($bootstrapProviders);
            if ($content !== false && self::isAnyProviderInContent($providers, $content)) {
                return true;
            }
        }

        // Laravel 10-: Check config/app.php
        $configApp = $basePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        if (file_exists($configApp)) {
            $content = file_get_contents($configApp);
            if ($content !== false && self::isAnyProviderInContent($providers, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any provider class appears in the file content.
     *
     * A namespaced provider counts when its fully qualified name appears anywhere in the file,
     * in any letter case. Otherwise each `Name::class` and each quoted string in the file is
     * resolved the way PHP resolves it, and the provider counts when one of them names it. A
     * provider in the global namespace counts only through that resolution, so a longer name
     * that merely ends in its short name does not.
     *
     * @param  array<string>  $providerClasses  Fully qualified class names
     * @param  string  $content  File content
     * @return bool True if any provider class is found
     */
    private static function isAnyProviderInContent(array $providerClasses, string $content): bool
    {
        $referenced = null;

        foreach ($providerClasses as $provider) {
            $provider = ltrim($provider, '\\');

            if (str_contains($provider, '\\') && stripos($content, $provider) !== false) {
                return true;
            }

            $referenced ??= self::referencedClasses($content);

            foreach ($referenced as $class) {
                if (strcasecmp($class, $provider) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The fully qualified classes a file names through `Name::class` constants and quoted strings.
     *
     * Quoted strings are read from the lexer's tokens and are already fully qualified once
     * their doubled backslashes are unescaped. `Name::class` references are read from the raw
     * text, comments included. One with a leading backslash is fully qualified, and a
     * `namespace\` prefix is relative to the file's namespace. Otherwise its first segment is
     * replaced by the class or namespace a namespace-level `use` imports under that alias, or
     * failing that the name is prefixed with the file's namespace. A file declaring several
     * namespaces resolves the name against each of them, since the text does not say which
     * one encloses it.
     *
     * @param  string  $content  File content
     * @return array<string> Class names without a leading backslash
     */
    private static function referencedClasses(string $content): array
    {
        $classes = [];

        // Quoted strings come from the lexer, so an apostrophe in a comment, heredoc or
        // nowdoc cannot throw the quote pairing off.
        foreach (@\PhpToken::tokenize($content) as $token) {
            if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                $classes[] = ltrim(str_replace('\\\\', '\\', substr($token->text, 1, -1)), '\\');
            }
        }

        $segment = '[A-Za-z_\x80-\xff][\w\x80-\xff]*';

        // A failed scan (a PCRE limit, say) finds no references here, but the strings above and
        // the full-name match still answer
        $names = preg_match_all(
            '/(?<![\w\x80-\xff\\\\$])(\\\\?'.$segment.'(?:\\\\'.$segment.')*)\s*::\s*class\b/i',
            $content,
            $references
        ) > 0 ? $references[1] : [];

        if ($names === []) {
            return $classes;
        }

        $imports = array_change_key_case(FileParser::extractImportsFromSource($content));

        $namespaces = preg_match_all(
            '/\bnamespace\s+('.$segment.'(?:\\\\'.$segment.')*)\s*[;{]/i',
            FileParser::stripCommentsAndStrings($content),
            $declared
        ) > 0 ? $declared[1] : [''];

        foreach ($names as $name) {
            if (str_starts_with($name, '\\')) {
                $classes[] = substr($name, 1);

                continue;
            }

            $alias = explode('\\', $name, 2)[0];

            if (strcasecmp($alias, 'namespace') === 0 && $alias !== $name) {
                // `namespace\Foo` is relative to the current namespace, never to an import
                $name = substr($name, strlen($alias) + 1);
            } elseif (($import = $imports[strtolower($alias)] ?? null) !== null) {
                $classes[] = $import.substr($name, strlen($alias));

                continue;
            }

            foreach ($namespaces as $namespace) {
                $classes[] = ltrim($namespace.'\\'.$name, '\\');
            }
        }

        return $classes;
    }

    /**
     * Check if Laravel Telescope is installed.
     *
     * Laravel Telescope is a debugging assistant that provides insights into
     * requests, exceptions, database queries, and more. Can cause significant
     * performance overhead in production if not properly configured.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Telescope is installed
     *
     * @see https://laravel.com/docs/telescope
     */
    public static function hasTelescope(string $basePath): bool
    {
        return self::hasPackage('laravel/telescope', $basePath);
    }

    /**
     * Check if Laravel Sanctum is installed.
     *
     * Laravel Sanctum provides a simple authentication system for SPAs,
     * mobile applications, and simple token-based APIs.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Sanctum is installed
     *
     * @see https://laravel.com/docs/sanctum
     */
    public static function hasSanctum(string $basePath): bool
    {
        return self::hasPackage('laravel/sanctum', $basePath);
    }

    /**
     * Check if Livewire is installed.
     *
     * Livewire is a full-stack framework for building dynamic interfaces
     * using server-side rendering.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Livewire is installed
     *
     * @see https://laravel-livewire.com/
     */
    public static function hasLivewire(string $basePath): bool
    {
        return self::hasPackage('livewire/livewire', $basePath);
    }

    /**
     * Check if Laravel Horizon is installed.
     *
     * Laravel Horizon is a dashboard for monitoring Redis queues.
     *
     * Note: This only checks composer.lock. Use isHorizonConfigured() to verify
     * that Horizon has been set up via artisan commands.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Horizon is installed
     *
     * @see https://laravel.com/docs/horizon
     */
    public static function hasHorizon(string $basePath): bool
    {
        return self::hasPackage('laravel/horizon', $basePath);
    }

    /**
     * Check if Horizon is configured and ready to use.
     *
     * This method verifies that Horizon has been properly set up by checking:
     * 1. Package installed in composer.lock
     * 2. config/horizon.php exists (published via horizon:install)
     * 3. app/Providers/HorizonServiceProvider.php exists
     * 4. Provider is registered in bootstrap/providers.php or config/app.php
     *
     * Running "php artisan horizon:install" creates the configuration file,
     * service provider, and registers it automatically.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Horizon is installed, configured, and registered
     *
     * @see https://laravel.com/docs/horizon#installation
     */
    public static function isHorizonConfigured(string $basePath): bool
    {
        // 1. Must be installed in composer.lock
        if (! self::hasHorizon($basePath)) {
            return false;
        }

        // 2. config/horizon.php must exist (published via horizon:install)
        $configPath = $basePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'horizon.php';
        if (! file_exists($configPath)) {
            return false;
        }

        // 3. HorizonServiceProvider must exist
        $providerPath = $basePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.
            'Providers'.DIRECTORY_SEPARATOR.'HorizonServiceProvider.php';
        if (! file_exists($providerPath)) {
            return false;
        }

        // 4. Qualify the class with the namespace around its declaration, so a custom
        //    application namespace works
        $providerClass = self::horizonProviderClass((string) @file_get_contents($providerPath));

        // 5. Provider must be registered
        return self::isServiceProviderRegistered($providerClass, $basePath);
    }

    /**
     * The fully qualified name of the HorizonServiceProvider class declared in $source.
     *
     * The class is qualified with the namespace that encloses it. It is global only when PHP
     * would see it so: the source opens with a real PHP tag and has no namespace statement at
     * all. Anything less clear (an empty or unreadable file, a namespace statement the lexer
     * cannot read, a class hidden in an unterminated comment, a short open tag PHP does not
     * honour) falls back to the default App\Providers\HorizonServiceProvider.
     */
    private static function horizonProviderClass(string $source): string
    {
        foreach (FileParser::extractClassLikesFromSource($source) as $classLike) {
            $name = $classLike['name'];

            if ($classLike['kind'] !== 'class' || ! str_ends_with(strtolower('\\'.$name), '\\horizonserviceprovider')) {
                continue;
            }

            $tokens = @\PhpToken::tokenize($source);
            $global = array_filter($tokens, fn (\PhpToken $token): bool => $token->is(T_OPEN_TAG)) !== []
                && array_filter($tokens, fn (\PhpToken $token): bool => $token->is(T_NAMESPACE)) === [];

            if (str_contains($name, '\\') || $global) {
                return $name;
            }
        }

        return 'App\\Providers\\HorizonServiceProvider';
    }

    /**
     * Clear all caches.
     *
     * Should be called in test tearDown() to prevent cache pollution
     * between tests. May also be useful in long-running processes that
     * need to detect package changes.
     */
    public static function clearCache(): void
    {
        self::$packageCache = [];
        self::$lockContentCache = [];
        self::$installedPackagesCache = [];
    }

    /**
     * Get the packages composer.lock lists under "packages" and "packages-dev", with their versions.
     *
     * Reading the decoded lock rather than its text keeps a "name" key elsewhere in an entry
     * (an author's, say) from passing for a package, and accepts any escaping JSON allows.
     *
     * @param  string  $basePath  Application base path
     * @param  string  $lockContent  composer.lock content
     * @return array<string, string|null>|null Package names as keys and their versions (null if
     *                                         the entry has no string version) as values, or null
     *                                         if the lock is not valid JSON
     */
    private static function getInstalledPackages(string $basePath, string $lockContent): ?array
    {
        if (array_key_exists($basePath, self::$installedPackagesCache)) {
            return self::$installedPackagesCache[$basePath];
        }

        // A BOM or one stray byte in a description would otherwise send the whole lock to the
        // text fallback, which cannot tell a package's "name" from an author's
        $lock = json_decode(
            str_starts_with($lockContent, "\xEF\xBB\xBF") ? substr($lockContent, 3) : $lockContent,
            true,
            512,
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (! is_array($lock)) {
            return self::$installedPackagesCache[$basePath] = null;
        }

        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            if (! isset($lock[$section]) || ! is_array($lock[$section])) {
                continue;
            }

            foreach ($lock[$section] as $package) {
                if (! is_array($package) || ! isset($package['name']) || ! is_string($package['name'])) {
                    continue;
                }

                // The first entry wins, so a name listed under both sections reports "packages"
                if (! array_key_exists($package['name'], $packages)) {
                    $version = $package['version'] ?? null;
                    $packages[$package['name']] = is_string($version) ? $version : null;
                }
            }
        }

        return self::$installedPackagesCache[$basePath] = $packages;
    }

    /**
     * Get composer.lock file content for a given base path.
     *
     * Reads and caches composer.lock content to minimize file I/O.
     * Returns null if file doesn't exist or can't be read.
     *
     * @param  string  $basePath  Application base path
     * @return string|null File content or null if file doesn't exist
     */
    private static function getComposerLockContent(string $basePath): ?string
    {
        // Return cached content if available
        if (array_key_exists($basePath, self::$lockContentCache)) {
            return self::$lockContentCache[$basePath];
        }

        $lockPath = $basePath.DIRECTORY_SEPARATOR.'composer.lock';

        if (! file_exists($lockPath)) {
            self::$lockContentCache[$basePath] = null;

            return null;
        }

        $content = file_get_contents($lockPath);

        if ($content === false) {
            self::$lockContentCache[$basePath] = null;

            return null;
        }

        self::$lockContentCache[$basePath] = $content;

        return $content;
    }
}
