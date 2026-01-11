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
     * Note: The vendor name is "filamentphp", not "laravel".
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
        return self::hasPackage('filamentphp/filament', $basePath);
    }

    /**
     * Check if Filament is configured with panel providers.
     *
     * This method verifies that Filament has been properly set up by checking for
     * panel provider classes that extend Filament\Panel\PanelProvider and are
     * registered in Laravel's service provider configuration.
     *
     * Filament requires running "php artisan filament:install --panels" which:
     * 1. Creates panel providers (e.g., AdminPanelProvider, AppPanelProvider)
     * 2. Registers them in bootstrap/providers.php (Laravel 11+) or config/app.php (Laravel 10-)
     *
     * This method checks both app/Providers/Filament/ and app/Providers/ directories,
     * detects panel provider classes, and verifies at least one is registered.
     *
     * @param  string  $basePath  Application base path
     * @return bool True if Filament is installed, configured, and registered
     *
     * @see https://filamentphp.com/docs/4.x/panels/installation
     */
    public static function isFilamentConfigured(string $basePath): bool
    {
        // Must be installed first
        if (! self::hasFilament($basePath)) {
            return false;
        }

        $providersBaseDir = $basePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Providers';

        if (! is_dir($providersBaseDir)) {
            return false;
        }

        // Check both app/Providers/Filament/ and app/Providers/ directories
        $searchPaths = [
            $providersBaseDir.DIRECTORY_SEPARATOR.'Filament',
            $providersBaseDir,
        ];

        $foundPanelProviders = [];

        foreach ($searchPaths as $searchPath) {
            if (! is_dir($searchPath)) {
                continue;
            }

            // Find all PHP files in this directory (non-recursive)
            $files = glob($searchPath.DIRECTORY_SEPARATOR.'*.php');

            if ($files === false || empty($files)) {
                continue;
            }

            // Check if any file contains a class extending Filament\Panel\PanelProvider
            foreach ($files as $file) {
                $panelProviderClass = self::getPanelProviderClassName($file, $searchPath, $providersBaseDir);
                if ($panelProviderClass !== null) {
                    $foundPanelProviders[] = $panelProviderClass;
                }
            }
        }

        if (empty($foundPanelProviders)) {
            return false;
        }

        // Verify at least one panel provider is registered
        return self::isPanelProviderRegistered($foundPanelProviders, $basePath);
    }

    /**
     * Get the fully qualified class name of a panel provider from a file.
     *
     * @param  string  $filePath  Path to PHP file
     * @param  string  $searchPath  Directory being searched
     * @param  string  $providersBaseDir  Base providers directory
     * @return string|null Fully qualified class name if file contains PanelProvider, null otherwise
     */
    private static function getPanelProviderClassName(string $filePath, string $searchPath, string $providersBaseDir): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        // Quick string check first (optimization)
        if (! str_contains($content, 'extends') || ! str_contains($content, 'PanelProvider')) {
            return null;
        }

        // Parse with AST for accurate detection
        try {
            $parser = new AstParser();
            $ast = $parser->parseFile($filePath);
            $classes = $parser->findClasses($ast);

            foreach ($classes as $class) {
                if ($class->extends === null || $class->name === null) {
                    continue;
                }

                $extendsName = $class->extends->toString();

                // Check if extends PanelProvider (with or without namespace)
                if ($extendsName === 'PanelProvider' ||
                    $extendsName === 'Filament\\Panel\\PanelProvider' ||
                    $extendsName === '\\Filament\\Panel\\PanelProvider') {

                    // Extract namespace and build fully qualified class name
                    $namespace = self::extractNamespaceFromFile($filePath);
                    $className = $class->name->name;

                    return $namespace ? $namespace.'\\'.$className : $className;
                }
            }

            return null;
        } catch (\Throwable $e) {
            // Fall back to regex-based detection
            if (! preg_match('/extends\s+(?:\\\\?Filament\\\\Panel\\\\)?PanelProvider/', $content)) {
                return null;
            }

            // Extract class name and namespace
            $namespace = self::extractNamespaceFromFile($filePath);
            preg_match('/class\s+(\w+)\s+extends/', $content, $matches);
            $className = $matches[1] ?? null;

            if ($className === null) {
                return null;
            }

            return $namespace ? $namespace.'\\'.$className : $className;
        }
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
            return null;
        }

        // Match namespace declaration
        if (preg_match('/namespace\s+([a-zA-Z0-9_\\\\]+)\s*;/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if any of the panel provider classes are registered.
     *
     * Checks both Laravel 11+ (bootstrap/providers.php) and Laravel 10- (config/app.php).
     *
     * @param  array<string>  $panelProviders  Fully qualified panel provider class names
     * @param  string  $basePath  Application base path
     * @return bool True if at least one panel provider is registered
     */
    private static function isPanelProviderRegistered(array $panelProviders, string $basePath): bool
    {
        // Laravel 11+: Check bootstrap/providers.php
        $bootstrapProviders = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'providers.php';
        if (file_exists($bootstrapProviders)) {
            $content = file_get_contents($bootstrapProviders);
            if ($content !== false && self::isAnyProviderInContent($panelProviders, $content)) {
                return true;
            }
        }

        // Laravel 10-: Check config/app.php
        $configApp = $basePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        if (file_exists($configApp)) {
            $content = file_get_contents($configApp);
            if ($content !== false && self::isAnyProviderInContent($panelProviders, $content)) {
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
