<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Support\FilamentPanelDetector;
use ShieldCI\AnalyzersCore\Support\PackageDetector;
use ShieldCI\AnalyzersCore\Tests\Support\CreatesTestApplication;

class PackageDetectorTest extends TestCase
{
    use CreatesTestApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestApplication();
    }

    protected function tearDown(): void
    {
        $this->tearDownTestApplication();
        parent::tearDown();
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

    public function test_has_filament_returns_true_for_v4_package_name(): void
    {
        // Filament v4 renamed the vendor from "filamentphp" to "filament"
        $this->createComposerLock(['filament/filament']);

        $result = PackageDetector::hasFilament($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_filament_uses_correct_vendor_name(): void
    {
        // Ensure it's looking for filamentphp/filament or filament/filament, not laravel/filament
        $this->createComposerLock(['laravel/filament']);

        $result = PackageDetector::hasFilament($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for isFilamentConfigured()
    // =================================================================

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
    // Tests for hasHorizon()
    // =================================================================

    public function test_has_horizon_returns_true_when_installed(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        $result = PackageDetector::hasHorizon($this->testDir);

        $this->assertTrue($result);
    }

    public function test_has_horizon_returns_false_when_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::hasHorizon($this->testDir);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for isHorizonConfigured()
    // =================================================================

    public function test_is_horizon_configured_returns_false_when_package_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_horizon_configured_returns_false_when_config_missing(): void
    {
        $this->createComposerLock(['laravel/horizon']);
        // Don't create config/horizon.php

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_horizon_configured_returns_false_when_provider_missing(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config but not provider
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_horizon_configured_returns_false_when_provider_not_registered(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        // Create provider
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);
        file_put_contents($providersDir.'/HorizonServiceProvider.php', '<?php namespace App\Providers;');

        // Don't register it

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_horizon_configured_returns_true_when_fully_configured(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        // Create provider
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);
        file_put_contents($providersDir.'/HorizonServiceProvider.php', '<?php namespace App\Providers;');

        // Register provider (Laravel 11 style)
        $this->registerProviderInBootstrap('App\\Providers\\HorizonServiceProvider');

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_horizon_configured_detects_registration_in_config_app(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        // Create provider
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);
        file_put_contents($providersDir.'/HorizonServiceProvider.php', '<?php namespace App\Providers;');

        // Register provider (Laravel 10 style)
        $this->registerProviderInConfigApp('App\\Providers\\HorizonServiceProvider');

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_horizon_configured_works_with_custom_namespace(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        // Create provider with CUSTOM namespace
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace Acme\Providers;

use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    //
}
PHP;
        file_put_contents($providersDir.'/HorizonServiceProvider.php', $providerCode);

        // Register provider with custom namespace
        $this->registerProviderInBootstrap('Acme\\Providers\\HorizonServiceProvider');

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_horizon_configured_falls_back_to_app_namespace_when_extraction_fails(): void
    {
        $this->createComposerLock(['laravel/horizon']);

        // Create config
        $configDir = $this->testDir.'/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir.'/horizon.php', '<?php return [];');

        // Create provider WITHOUT namespace declaration (unusual but possible)
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

// No namespace declaration - should fallback to App\Providers\HorizonServiceProvider

class HorizonServiceProvider
{
    //
}
PHP;
        file_put_contents($providersDir.'/HorizonServiceProvider.php', $providerCode);

        // Register provider using default App namespace (what the fallback expects)
        $this->registerProviderInBootstrap('App\\Providers\\HorizonServiceProvider');

        $result = PackageDetector::isHorizonConfigured($this->testDir);

        $this->assertTrue($result);
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

    public function test_get_composer_lock_content_handles_unreadable_file(): void
    {
        // Create composer.lock and make it unreadable (lines 480, 482)
        $lockPath = $this->testDir.DIRECTORY_SEPARATOR.'composer.lock';
        file_put_contents($lockPath, '{"packages":[]}');
        chmod($lockPath, 0000);

        // Suppress expected PHP warning from file_get_contents on unreadable file
        $previousHandler = set_error_handler(fn () => true);

        try {
            $result = PackageDetector::hasPackage('laravel/nova', $this->testDir);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);

        // Restore permissions for cleanup
        chmod($lockPath, 0644);
    }

    public function test_does_not_match_substring_of_package_name(): void
    {
        $this->createComposerLock(['my-vendor/laravel-nova-tools']);

        // Should not match because "laravel/nova" is substring, not exact match
        $result = PackageDetector::hasNova($this->testDir);

        $this->assertFalse($result);
    }

    // --- Reaching the parse failures panel provider discovery would otherwise discard ---


    /**
     * The Filament panel scan now lives on FilamentPanelDetector; what is left here is a
     * deprecated delegate kept so released callers keep working. This pins the delegate to the
     * instance it forwards to rather than restating the scan, which FilamentPanelDetectorTest
     * covers -- if the two ever disagree, the deprecation has become a behaviour change.
     */
    public function test_the_deprecated_is_filament_configured_matches_the_instance_it_delegates_to(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        file_put_contents($filamentDir.'/AdminPanelProvider.php', <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
}
PHP);

        $this->registerProviderInBootstrap('App\Providers\Filament\AdminPanelProvider');

        $this->assertSame(
            (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir),
            PackageDetector::isFilamentConfigured($this->testDir)
        );
        $this->assertTrue(PackageDetector::isFilamentConfigured($this->testDir));
    }

}
