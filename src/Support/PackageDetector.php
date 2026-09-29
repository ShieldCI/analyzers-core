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

        // Search for package name in composer.lock
        // Format: "name": "vendor/package"
        $found = str_contains($lockContent, '"name": "'.$packageName.'"');

        self::$packageCache[$cacheKey] = $found;

        return $found;
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
     * Extract namespace from a PHP file.
     *
     * @param  string  $filePath  Path to PHP file
     * @return string|null Namespace or null if not found
     */
    private static function extractNamespaceFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return null; // @codeCoverageIgnore
        }

        return CodeHelper::extractNamespace($content);
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
     * @param  array<string>  $providerClasses  Fully qualified class names
     * @param  string  $content  File content
     * @return bool True if any provider class is found
     */
    private static function isAnyProviderInContent(array $providerClasses, string $content): bool
    {
        foreach ($providerClasses as $provider) {
            // Check for fully qualified class name
            if (str_contains($content, $provider)) {
                return true;
            }

            // Check for class name with ::class
            $className = substr($provider, strrpos($provider, '\\') + 1);
            if (str_contains($content, $className.'::class')) {
                return true;
            }
        }

        return false;
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

        // 4. Extract namespace from provider file to support custom application namespaces
        $namespace = self::extractNamespaceFromFile($providerPath);

        // Build fully qualified class name (fallback to App if namespace extraction fails)
        $providerClass = $namespace
            ? $namespace.'\\Providers\\HorizonServiceProvider'
            : 'App\\Providers\\HorizonServiceProvider';

        // 5. Provider must be registered
        return self::isServiceProviderRegistered($providerClass, $basePath);
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
