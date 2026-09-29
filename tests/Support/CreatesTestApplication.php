<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Support;

use ShieldCI\AnalyzersCore\Support\PackageDetector;

/**
 * Builds the on-disk shape of a Laravel application in a temp directory.
 *
 * Shared by PackageDetectorTest and FilamentPanelDetectorTest, which ask different questions of
 * the same fixture: one reads composer.lock, the other parses app/Providers. Two copies of the
 * composer.lock builder would drift the moment either grows a field.
 */
trait CreatesTestApplication
{
    private string $testDir = '';

    protected function setUpTestApplication(): void
    {
        $this->testDir = sys_get_temp_dir().'/shield-ci-package-test-'.uniqid();
        mkdir($this->testDir);
    }

    protected function tearDownTestApplication(): void
    {
        PackageDetector::clearCache();

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
}
