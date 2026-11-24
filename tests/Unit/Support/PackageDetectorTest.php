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

    // --- A lock not in Composer's own pretty-printed layout ---

    public function test_detects_package_in_minified_composer_lock(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"laravel/nova","version":"4.0.0"}],"packages-dev":[]}'
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
    }

    public function test_detects_package_whose_slash_is_json_escaped(): void
    {
        // json_encode() without JSON_UNESCAPED_SLASHES writes "laravel\/nova"
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            (string) json_encode(['packages' => [['name' => 'laravel/nova', 'version' => '4.0.0']]])
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
    }

    public function test_detects_package_with_other_whitespace_around_the_colon(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            "{\n\t\"packages\": [\n\t\t{\n\t\t\t\"name\"\t:\n\t\t\t\t\"laravel/nova\"\n\t\t}\n\t]\n}"
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
    }

    public function test_minified_lock_does_not_match_a_longer_package_name(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"laravel/nova-tools","version":"1.0.0"},{"name":"acme/laravel/nova"}]}'
        );

        $this->assertFalse(PackageDetector::hasNova($this->testDir));
    }

    public function test_minified_lock_does_not_count_a_package_only_required_by_another(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"acme/nova-addon","require":{"laravel/nova":"^4.0"}}]}'
        );

        $this->assertFalse(PackageDetector::hasNova($this->testDir));
    }

    public function test_package_name_is_matched_literally_not_as_a_pattern(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"some-vendor/myXspecial"}]}'
        );

        $this->assertFalse(PackageDetector::hasPackage('some-vendor/my.special', $this->testDir));
    }

    public function test_an_author_named_like_the_package_does_not_count_as_installed(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"acme/tool","authors":[{"name":"laravel/nova"}]}]}'
        );

        $this->assertFalse(PackageDetector::hasNova($this->testDir));
    }

    public function test_a_lock_with_a_byte_order_mark_is_still_read_as_json(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            "\xEF\xBB\xBF".'{"packages":[{"name":"laravel/horizon","authors":[{"name":"laravel/nova"}]}]}'
        );

        $this->assertTrue(PackageDetector::hasHorizon($this->testDir));
        $this->assertFalse(PackageDetector::hasNova($this->testDir));
    }

    public function test_a_lock_with_an_invalid_utf8_byte_is_still_read_as_json(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"laravel/horizon","description":"caf'."\xE9".'","authors":[{"name":"laravel/nova"}]}]}'
        );

        $this->assertTrue(PackageDetector::hasHorizon($this->testDir));
        $this->assertFalse(PackageDetector::hasNova($this->testDir));
    }

    public function test_detects_package_whose_name_is_unicode_escaped(): void
    {
        // chr(92) is the backslash: the name is "laravel", backslash, "u002fnova", JSON's escape for "/"
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"laravel'.chr(92).'u002fnova"}]}'
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
    }

    public function test_entries_without_a_string_name_are_ignored(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":"laravel/nova","packages-dev":["laravel/nova",{"name":["laravel/nova"]},{"name":"laravel/nova"}]}'
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
        $this->assertFalse(PackageDetector::hasTelescope($this->testDir));
    }

    public function test_a_lock_left_with_merge_conflict_markers_still_answers(): void
    {
        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', <<<'LOCK'
{
    "packages": [
<<<<<<< HEAD
        {"name":"laravel/nova","version":"4.0.0"},
=======
        {"name": "laravel/nova", "version": "4.1.0"},
>>>>>>> feature
        {
            "name": "laravel/horizon"
        }
    ]
}
LOCK);

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
        $this->assertTrue(PackageDetector::hasHorizon($this->testDir));
        $this->assertFalse(PackageDetector::hasSanctum($this->testDir));
    }

    public function test_a_lock_that_does_not_decode_still_accepts_an_escaped_slash(): void
    {
        // chr(92) is the backslash: "laravel\/nova", then a stray conflict marker
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"laravel'.chr(92).'/nova"}]}'."\n>>>>>>> feature\n"
        );

        $this->assertTrue(PackageDetector::hasNova($this->testDir));
        $this->assertFalse(PackageDetector::hasPackage('laravel/nov', $this->testDir));
    }

    public function test_a_lock_that_does_not_decode_matches_the_name_literally(): void
    {
        file_put_contents(
            $this->testDir.DIRECTORY_SEPARATOR.'composer.lock',
            '{"packages":[{"name":"some-vendor/myXspecial"}]}'."\n>>>>>>> feature\n"
        );

        $this->assertFalse(PackageDetector::hasPackage('some-vendor/my.special', $this->testDir));
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

    // =================================================================
    // Tests for installedVersion()
    // =================================================================

    private function writeLock(string $content): void
    {
        file_put_contents($this->testDir.DIRECTORY_SEPARATOR.'composer.lock', $content);
    }

    public function test_installed_version_reads_a_pretty_printed_lock(): void
    {
        $this->createComposerLock(['laravel/nova', 'symfony/console']);

        $this->assertSame('1.0.0', PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_returns_the_version_exactly_as_written(): void
    {
        $this->writeLock(<<<'JSON'
{
    "packages": [
        {
            "name": "laravel/framework",
            "version": "v10.48.4"
        },
        {
            "name": "acme/tool",
            "version": "dev-main"
        }
    ]
}
JSON);

        $this->assertSame('v10.48.4', PackageDetector::installedVersion('laravel/framework', $this->testDir));
        $this->assertSame('dev-main', PackageDetector::installedVersion('acme/tool', $this->testDir));
    }

    public function test_installed_version_reads_a_minified_lock(): void
    {
        $this->writeLock('{"packages":[{"name":"laravel/nova","version":"4.32.1"}],"packages-dev":[]}');

        $this->assertSame('4.32.1', PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_reads_packages_dev(): void
    {
        $this->writeLock('{"packages":[],"packages-dev":[{"name":"laravel/telescope","version":"v5.2.0"}]}');

        $this->assertSame('v5.2.0', PackageDetector::installedVersion('laravel/telescope', $this->testDir));
    }

    public function test_installed_version_keeps_the_first_entry_for_a_name_listed_twice(): void
    {
        $this->writeLock(
            '{"packages":[{"name":"laravel/nova","version":"4.0.0"}],'
            .'"packages-dev":[{"name":"laravel/nova","version":"5.0.0"}]}'
        );

        $this->assertSame('4.0.0', PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_returns_null_for_a_package_not_in_the_lock(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $this->assertNull(PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_matches_the_name_case_sensitively(): void
    {
        $this->createComposerLock(['laravel/nova']);

        $this->assertNull(PackageDetector::installedVersion('Laravel/Nova', $this->testDir));
        $this->assertFalse(PackageDetector::hasPackage('Laravel/Nova', $this->testDir));
    }

    public function test_installed_version_returns_null_without_a_lock(): void
    {
        $this->assertNull(PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_returns_null_for_an_unreadable_lock(): void
    {
        $lockPath = $this->testDir.DIRECTORY_SEPARATOR.'composer.lock';
        file_put_contents($lockPath, '{"packages":[{"name":"laravel/nova","version":"4.0.0"}]}');
        chmod($lockPath, 0000);

        set_error_handler(fn () => true);

        try {
            $version = PackageDetector::installedVersion('laravel/nova', $this->testDir);
        } finally {
            restore_error_handler();
            chmod($lockPath, 0644);
        }

        $this->assertNull($version);
    }

    public function test_installed_version_returns_null_for_a_lock_that_does_not_decode(): void
    {
        // The text fallback hasPackage() uses can say a name is there, but not which of the
        // two conflicting versions is the one installed.
        $this->writeLock(<<<'LOCK'
{
    "packages": [
<<<<<<< HEAD
        {"name":"laravel/nova","version":"4.0.0"},
=======
        {"name": "laravel/nova", "version": "4.1.0"},
>>>>>>> feature
    ]
}
LOCK);

        $this->assertNull(PackageDetector::installedVersion('laravel/nova', $this->testDir));
        $this->assertTrue(PackageDetector::hasPackage('laravel/nova', $this->testDir));
    }

    public function test_installed_version_returns_null_for_an_entry_without_a_string_version(): void
    {
        $this->writeLock(
            '{"packages":[{"name":"laravel/nova"},{"name":"laravel/horizon","version":5},'
            .'{"name":"laravel/sanctum","version":null}]}'
        );

        foreach (['laravel/nova', 'laravel/horizon', 'laravel/sanctum'] as $package) {
            $this->assertNull(PackageDetector::installedVersion($package, $this->testDir), $package);
            // Still installed: a lock entry with a name is a package whatever else it lacks.
            $this->assertTrue(PackageDetector::hasPackage($package, $this->testDir), $package);
        }
    }

    public function test_installed_version_agrees_with_has_package(): void
    {
        $this->writeLock(
            '{"packages":[{"name":"laravel/nova","version":"4.0.0"},{"name":"acme/tool","version":"dev-main"}],'
            .'"packages-dev":[{"name":"laravel/telescope","version":"v5.0.0"}]}'
        );

        foreach (['laravel/nova', 'acme/tool', 'laravel/telescope', 'laravel/horizon', 'laravel/nov', 'LARAVEL/NOVA'] as $package) {
            $this->assertSame(
                PackageDetector::hasPackage($package, $this->testDir),
                PackageDetector::installedVersion($package, $this->testDir) !== null,
                $package
            );
        }
    }

    public function test_installed_version_answers_from_the_cache_until_it_is_cleared(): void
    {
        $this->createComposerLock(['laravel/nova']);

        $this->assertSame('1.0.0', PackageDetector::installedVersion('laravel/nova', $this->testDir));

        unlink($this->testDir.DIRECTORY_SEPARATOR.'composer.lock');

        $this->assertSame('1.0.0', PackageDetector::installedVersion('laravel/nova', $this->testDir));
        $this->assertNull(PackageDetector::installedVersion('laravel/telescope', $this->testDir));

        PackageDetector::clearCache();

        $this->assertNull(PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }

    public function test_installed_version_shares_the_lock_has_package_decoded(): void
    {
        $this->createComposerLock(['laravel/nova']);

        $this->assertTrue(PackageDetector::hasNova($this->testDir));

        unlink($this->testDir.DIRECTORY_SEPARATOR.'composer.lock');

        $this->assertSame('1.0.0', PackageDetector::installedVersion('laravel/nova', $this->testDir));
    }
}
