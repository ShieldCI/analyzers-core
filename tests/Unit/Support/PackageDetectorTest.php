<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\PackageDetector;

class PackageDetectorTest extends TestCase
{
    private string $testDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir().'/shield-ci-package-test-'.uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clear package detector cache
        PackageDetector::clearCache();

        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Create a composer.lock file with specified packages.
     *
     * @param  array<string>  $packages
     */
    private function createComposerLock(array $packages): void
    {
        $packageEntries = [];
        foreach ($packages as $packageName) {
            $packageEntries[] = <<<JSON
        {
            "name": "{$packageName}",
            "version": "1.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/example/repo.git",
                "reference": "abc123"
            }
        }
JSON;
        }

        $lockContent = <<<JSON
{
    "packages": [
{$this->indentLines(implode(",\n", $packageEntries), 2)}
    ],
    "packages-dev": []
}
JSON;

        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', $lockContent);
    }

    private function indentLines(string $content, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $content);

        return implode("\n", array_map(fn ($line) => $indent.$line, $lines));
    }

    /**
     * Register a service provider in bootstrap/providers.php (Laravel 11+).
     *
     * @param  string  $providerClass  Fully qualified class name
     */
    private function registerProviderInBootstrap(string $providerClass): void
    {
        $bootstrapDir = $this->testDir.'/bootstrap';
        if (! is_dir($bootstrapDir)) {
            mkdir($bootstrapDir, 0755, true);
        }

        $providersFile = $bootstrapDir.'/providers.php';
        $content = <<<PHP
<?php

return [
    {$providerClass}::class,
];
PHP;
        file_put_contents($providersFile, $content);
    }

    /**
     * Register a service provider in config/app.php (Laravel 10-).
     *
     * @param  string  $providerClass  Fully qualified class name
     */
    private function registerProviderInConfigApp(string $providerClass): void
    {
        $configDir = $this->testDir.'/config';
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $configFile = $configDir.'/app.php';
        $content = <<<PHP
<?php

return [
    'providers' => [
        {$providerClass}::class,
    ],
];
PHP;
        file_put_contents($configFile, $content);
    }

    // =================================================================
    // Tests for hasPackage() - Core Functionality
    // =================================================================

    public function test_has_package_returns_true_when_package_is_installed(): void
    {
        $this->createComposerLock(['laravel/framework', 'laravel/nova', 'symfony/console']);

        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_package_returns_false_when_package_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework', 'symfony/console']);

        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_returns_false_when_composer_lock_does_not_exist(): void
    {
        // Don't create composer.lock file
        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_handles_empty_composer_lock(): void
    {
        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', '');

        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_handles_malformed_composer_lock(): void
    {
        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', 'this is not valid JSON{{{');

        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_with_similar_package_names(): void
    {
        $this->createComposerLock(['nova/nova', 'my-vendor/laravel-nova-addon']);

        // Should not match partial names
        $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_is_case_sensitive(): void
    {
        $this->createComposerLock(['laravel/nova']);

        // Package names are case-sensitive in composer
        $result = PackageDetector::hasPackage('Laravel/Nova', $this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_package_with_non_existent_directory(): void
    {
        $nonExistentPath = '/non/existent/path/to/nowhere';

        $result = PackageDetector::hasPackage('laravel/nova', $nonExistentPath);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for hasNova()
    // =================================================================

    public function test_has_nova_returns_true_when_installed(): void
    {
        $this->createComposerLock(['laravel/nova']);

        $result = PackageDetector::hasNova($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_nova_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasNova($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for hasFilament()
    // =================================================================

    public function test_has_filament_returns_true_when_installed(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $result = PackageDetector::hasFilament($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_filament_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasFilament($this->testDir);

        $this->assertFalse($result);
    }

    public function test_has_filament_uses_correct_vendor_name(): void
    {
        // Ensure it's looking for filamentphp/filament, not laravel/filament
        $this->createComposerLock(['laravel/filament']);

        $result = PackageDetector::hasFilament($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for isFilamentConfigured()
    // =================================================================

    public function test_is_filament_configured_returns_false_when_package_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_returns_false_when_no_panel_providers(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_detects_admin_panel_provider_in_filament_dir(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        // Create app/Providers/Filament/AdminPanelProvider.php
        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('admin')
            ->default();
    }
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Register in bootstrap/providers.php (Laravel 11)
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_custom_panel_provider_in_providers_dir(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        // Create app/Providers/MyCustomPanelProvider.php (directly in Providers, not Filament subdir)
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Filament\Panel\PanelProvider;

class MyCustomPanelProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('custom');
    }
}
PHP;
        file_put_contents($providersDir.'/MyCustomPanelProvider.php', $providerCode);

        // Register in config/app.php (Laravel 10)
        $this->registerProviderInConfigApp('App\\Providers\\MyCustomPanelProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_panel_provider_with_fully_qualified_name(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Use fully qualified class name with leading backslash
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

class AppPanelProvider extends \Filament\Panel\PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('app');
    }
}
PHP;
        file_put_contents($filamentDir.'/AppPanelProvider.php', $providerCode);

        // Register provider
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AppPanelProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_panel_provider_with_use_statement(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Use statement imports PanelProvider, then just use the short name
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;
use Filament\Panel\Panel;

class DashboardProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('dashboard');
    }
}
PHP;
        file_put_contents($filamentDir.'/DashboardProvider.php', $providerCode);

        // Register provider
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\DashboardProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_returns_false_when_provider_does_not_extend_panel_provider(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a provider that extends ServiceProvider, not PanelProvider
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Illuminate\Support\ServiceProvider;

class FilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
PHP;
        file_put_contents($filamentDir.'/FilamentServiceProvider.php', $providerCode);

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_ignores_non_provider_files(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a non-provider file
        $helperCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

class FilamentHelper
{
    public static function configure(): void
    {
        // Just a helper, not a provider
    }
}
PHP;
        file_put_contents($filamentDir.'/FilamentHelper.php', $helperCode);

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_handles_multiple_panel_providers(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create multiple panel providers
        $adminProvider = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $adminProvider);

        $appProvider = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AppPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AppPanelProvider.php', $appProvider);

        // Register one of them (should be sufficient)
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_returns_false_when_provider_not_registered(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create panel provider but DON'T register it
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Note: Not calling registerProviderInBootstrap or registerProviderInConfigApp

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_detects_registration_in_config_app(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Register in config/app.php (Laravel 10 style)
        $this->registerProviderInConfigApp('App\\Providers\\Filament\\AdminPanelProvider');

        $result = PackageDetector::isFilamentConfigured($this->testDir);

        $this->assertTrue($result);
    }

    // =================================================================
    // Tests for hasTelescope()
    // =================================================================

    public function test_has_telescope_returns_true_when_installed(): void
    {
        $this->createComposerLock(['laravel/telescope']);

        $result = PackageDetector::hasTelescope($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_telescope_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasTelescope($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for hasSanctum()
    // =================================================================

    public function test_has_sanctum_returns_true_when_installed(): void
    {
        $this->createComposerLock(['laravel/sanctum']);

        $result = PackageDetector::hasSanctum($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_sanctum_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasSanctum($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for hasLivewire()
    // =================================================================

    public function test_has_livewire_returns_true_when_installed(): void
    {
        $this->createComposerLock(['livewire/livewire']);

        $result = PackageDetector::hasLivewire($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_livewire_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasLivewire($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for Caching
    // =================================================================

    public function test_caching_prevents_repeated_file_reads(): void
    {
        $this->createComposerLock(['laravel/nova']);

        // First call - reads file
        $result1 = PackageDetector::hasPackage('laravel/nova', $this->testDir);
        $this->assertTrue($result1);

        // Delete composer.lock to verify it's using cache
        unlink($this->testDir.DIRECTORY_SEPARATOR.'composer.lock');

        // Second call - should use cache, not read file
        $result2 = PackageDetector::hasPackage('laravel/nova', $this->testDir);
        $this->assertTrue($result2);

        // Different package but same lock file - should also use cached lock content
        $result3 = PackageDetector::hasPackage('laravel/telescope', $this->testDir);
        $this->assertFalse($result3);
    }

    public function test_clear_cache_resets_detection_results(): void
    {
        $this->createComposerLock(['laravel/nova']);

        // First detection
        $result1 = PackageDetector::hasNova($this->testDir);
        $this->assertTrue($result1);

        // Change the composer.lock file
        $this->createComposerLock(['laravel/framework']);

        // Before clearing cache - still returns cached result
        $result2 = PackageDetector::hasNova($this->testDir);
        $this->assertTrue($result2);

        // Clear cache
        PackageDetector::clearCache();

        // After clearing cache - reads new file content
        $result3 = PackageDetector::hasNova($this->testDir);
        $this->assertFalse($result3);
    }

    public function test_cache_isolation_between_different_base_paths(): void
    {
        // Create first project with Nova
        $this->createComposerLock(['laravel/nova']);

        // Create second project directory without Nova
        $testDir2 = sys_get_temp_dir().'/shield-ci-package-test-2-'.uniqid();
        mkdir($testDir2);

        $lockContent = <<<JSON
{
    "packages": [
        {
            "name": "laravel/framework",
            "version": "10.0.0"
        }
    ]
}
JSON;
        file_put_contents($testDir2.DIRECTORY_SEPARATOR.'composer.lock', $lockContent);

        // Check both projects
        $hasNovaInProject1 = PackageDetector::hasNova($this->testDir);
        $hasNovaInProject2 = PackageDetector::hasNova($testDir2);

        $this->assertTrue($hasNovaInProject1);
        $this->assertFalse($hasNovaInProject2);

        // Clean up second test directory
        unlink($testDir2.DIRECTORY_SEPARATOR.'composer.lock');
        rmdir($testDir2);
    }

    // =================================================================
    // Tests for Multiple Package Detection
    // =================================================================

    public function test_detects_multiple_packages_correctly(): void
    {
        $this->createComposerLock([
            'laravel/framework',
            'laravel/nova',
            'filamentphp/filament',
            'livewire/livewire',
        ]);

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
        $this->assertTrue(PackageDetector::hasFilament($this->testDir));
        $this->assertTrue(PackageDetector::hasLivewire($this->testDir));
        $this->assertFalse(PackageDetector::hasTelescope($this->testDir));
        $this->assertFalse(PackageDetector::hasSanctum($this->testDir));
    }

    // =================================================================
    // Edge Cases
    // =================================================================

    public function test_handles_composer_lock_with_only_dev_dependencies(): void
    {
        $lockContent = <<<JSON
{
    "packages": [],
    "packages-dev": [
        {
            "name": "laravel/telescope",
            "version": "4.0.0"
        }
    ]
}
JSON;
        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', $lockContent);

        // Should detect packages in packages-dev too
        $result = PackageDetector::hasTelescope($this->testDir);

        $this->assertTrue($result);
    }

    public function test_handles_package_with_special_characters_in_name(): void
    {
        $this->createComposerLock(['some-vendor/my.special-package_name']);

        $result = PackageDetector::hasPackage('some-vendor/my.special-package_name', $this->testDir);

        $this->assertTrue($result);
    }

    public function test_does_not_match_substring_of_package_name(): void
    {
        $this->createComposerLock(['my-vendor/laravel-nova-tools']);

        // Should not match because "laravel/nova" is substring, not exact match
        $result = PackageDetector::hasNova($this->testDir);

        $this->assertFalse($result);
    }
}
