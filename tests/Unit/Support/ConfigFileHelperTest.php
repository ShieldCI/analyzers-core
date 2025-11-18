<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\ConfigFileHelper;

class ConfigFileHelperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/shieldci_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir.'/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        if ($scanned === false) {
            return;
        }

        $files = array_diff($scanned, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testGetConfigPathWithBasePath(): void
    {
        $path = ConfigFileHelper::getConfigPath('/var/www/app', 'cache');
        $this->assertEquals('/var/www/app/config/cache.php', $path);

        $path = ConfigFileHelper::getConfigPath('/var/www/app', 'cache.php');
        $this->assertEquals('/var/www/app/config/cache.php', $path);
    }

    public function testGetConfigPathWithTrailingSlash(): void
    {
        $path = ConfigFileHelper::getConfigPath('/var/www/app/', 'cache');
        $this->assertEquals('/var/www/app/config/cache.php', $path);
    }

    public function testGetConfigPathWithFallback(): void
    {
        $fallback = function (string $file): string {
            return '/custom/path/'.$file;
        };

        $path = ConfigFileHelper::getConfigPath('', 'cache', $fallback);
        $this->assertEquals('/custom/path/cache.php', $path);
    }

    public function testGetConfigPathWithoutBasePath(): void
    {
        $path = ConfigFileHelper::getConfigPath('', 'cache');
        $this->assertEquals('config/cache.php', $path);
    }

    public function testFindKeyLineFindsSimpleKey(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'default' => 'file',\n    'driver' => 'redis',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'default');
        $this->assertEquals(4, $line);

        $line = ConfigFileHelper::findKeyLine($configFile, 'driver');
        $this->assertEquals(5, $line);
    }

    public function testFindKeyLineIgnoresComments(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    // 'default' => 'file',\n    'default' => 'file',\n    # 'driver' => 'redis',\n    'driver' => 'redis',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'default');
        $this->assertEquals(5, $line); // Should find the actual key, not the one in comment

        $line = ConfigFileHelper::findKeyLine($configFile, 'driver');
        $this->assertEquals(7, $line); // Should find the actual key, not the one in comment
    }

    public function testFindKeyLineWithParentKey(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'connections' => [\n        'mysql' => [\n            'driver' => 'mysql',\n        ],\n    ],\n    'default' => 'mysql',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'mysql', 'connections');
        $this->assertEquals(5, $line);

        $line = ConfigFileHelper::findKeyLine($configFile, 'default');
        $this->assertEquals(9, $line);
    }

    public function testFindKeyLineReturnsOneWhenNotFound(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'default' => 'file',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'nonexistent');
        $this->assertEquals(1, $line);
    }

    public function testFindKeyLineReturnsOneWhenFileNotFound(): void
    {
        $line = ConfigFileHelper::findKeyLine('/nonexistent/file.php', 'default');
        $this->assertEquals(1, $line);
    }

    public function testFindNestedKeyLineFindsDriverInStore(): void
    {
        $configFile = $this->tempDir.'/config/cache.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'stores' => [\n        'redis' => [\n            'driver' => 'redis',\n            'connection' => 'default',\n        ],\n    ],\n];\n");

        $line = ConfigFileHelper::findNestedKeyLine($configFile, 'stores', 'driver', 'redis');
        $this->assertEquals(6, $line); // 'driver' is on line 6
    }

    public function testFindNestedKeyLineIgnoresComments(): void
    {
        $configFile = $this->tempDir.'/config/cache.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'stores' => [\n        'redis' => [\n            // 'driver' => 'redis',\n            'driver' => 'redis',\n        ],\n    ],\n];\n");

        $line = ConfigFileHelper::findNestedKeyLine($configFile, 'stores', 'driver', 'redis');
        $this->assertEquals(7, $line); // Should find the actual key on line 7, not the one in comment on line 6
    }

    public function testFindNestedKeyLineFallsBackToParentKey(): void
    {
        $configFile = $this->tempDir.'/config/cache.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'stores' => [\n        'redis' => [\n            'connection' => 'default',\n        ],\n    ],\n];\n");

        // Driver not found, should fallback to finding 'stores' key
        $line = ConfigFileHelper::findNestedKeyLine($configFile, 'stores', 'driver', 'redis');
        $this->assertEquals(5, $line); // Should return line where 'redis' array starts (line 5)
    }

    public function testFindNestedKeyLineHandlesMultipleStores(): void
    {
        $configFile = $this->tempDir.'/config/cache.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'stores' => [\n        'file' => [\n            'driver' => 'file',\n        ],\n        'redis' => [\n            'driver' => 'redis',\n        ],\n    ],\n];\n");

        $line = ConfigFileHelper::findNestedKeyLine($configFile, 'stores', 'driver', 'file');
        $this->assertEquals(6, $line); // 'driver' in 'file' store is on line 6

        $line = ConfigFileHelper::findNestedKeyLine($configFile, 'stores', 'driver', 'redis');
        $this->assertEquals(9, $line); // 'driver' in 'redis' store is on line 9
    }

    public function testFindNestedKeyLineReturnsOneWhenFileNotFound(): void
    {
        $line = ConfigFileHelper::findNestedKeyLine('/nonexistent/file.php', 'stores', 'driver', 'redis');
        $this->assertEquals(1, $line);
    }

    public function testFindKeyLineWithDoubleQuotes(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    \"default\" => 'file',\n    'driver' => 'redis',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'default');
        $this->assertEquals(4, $line);
    }

    public function testFindKeyLineWithSpacesAroundArrow(): void
    {
        $configFile = $this->tempDir.'/config/test.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'default'=> 'file',\n    'driver' =>'redis',\n    'key'  =>  'value',\n];\n");

        $line = ConfigFileHelper::findKeyLine($configFile, 'default');
        $this->assertEquals(4, $line);

        $line = ConfigFileHelper::findKeyLine($configFile, 'driver');
        $this->assertEquals(5, $line);

        $line = ConfigFileHelper::findKeyLine($configFile, 'key');
        $this->assertEquals(6, $line);
    }
}
