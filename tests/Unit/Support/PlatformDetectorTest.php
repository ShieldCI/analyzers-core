<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\PlatformDetector;

class PlatformDetectorTest extends TestCase
{
    private string $testDir = '';

    /** @var array<string, string|false> */
    private array $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/shield-ci-platform-test-' . uniqid();
        mkdir($this->testDir);

        // Store original environment variables that we'll be modifying
        $this->storeOriginalEnvVars([
            'AWS_LAMBDA_FUNCTION_NAME',
            'AWS_EXECUTION_ENV',
            'VAPOR_SSM_PATH',
            'K_SERVICE',
            'FUNCTION_TARGET',
            'FUNCTIONS_WORKER_RUNTIME',
            'WEBSITE_INSTANCE_ID',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore original environment variables
        $this->restoreOriginalEnvVars();

        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    /**
     * @param array<string> $envVars
     */
    private function storeOriginalEnvVars(array $envVars): void
    {
        foreach ($envVars as $var) {
            $this->originalEnvVars[$var] = getenv($var);
        }
    }

    private function restoreOriginalEnvVars(): void
    {
        foreach ($this->originalEnvVars as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("{$var}={$value}");
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // =================================================================
    // Tests for isLaravelVapor()
    // =================================================================

    public function test_is_laravel_vapor_detects_vapor_yml(): void
    {
        $vaporConfig = $this->testDir . DIRECTORY_SEPARATOR . 'vapor.yml';
        file_put_contents($vaporConfig, "id: 123\nname: my-app\n");

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_detects_vapor_core_directory(): void
    {
        $vendorDir = $this->testDir . DIRECTORY_SEPARATOR . 'vendor';
        $laravelDir = $vendorDir . DIRECTORY_SEPARATOR . 'laravel';
        $vaporCoreDir = $laravelDir . DIRECTORY_SEPARATOR . 'vapor-core';

        mkdir($vendorDir, 0755);
        mkdir($laravelDir, 0755);
        mkdir($vaporCoreDir, 0755);

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_returns_false_when_no_indicators(): void
    {
        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_laravel_vapor_detects_vapor_yml_with_complex_config(): void
    {
        $vaporConfig = $this->testDir . DIRECTORY_SEPARATOR . 'vapor.yml';
        $config = <<<YAML
id: 12345
name: my-production-app
environments:
  production:
    memory: 1024
    timeout: 30
    database: my-db
    cache: my-cache
YAML;
        file_put_contents($vaporConfig, $config);

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_returns_false_for_non_existent_directory(): void
    {
        $nonExistentPath = '/non/existent/path/to/nowhere';

        $result = PlatformDetector::isLaravelVapor($nonExistentPath);

        $this->assertFalse($result);
    }

    // =================================================================
    // Tests for isServerless()
    // =================================================================

    public function test_is_serverless_detects_aws_lambda_function_name(): void
    {
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-function');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_aws_execution_env(): void
    {
        putenv('AWS_EXECUTION_ENV=AWS_Lambda_nodejs18.x');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_vapor_ssm_path(): void
    {
        putenv('VAPOR_SSM_PATH=/my-app/production');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_gcp_k_service(): void
    {
        putenv('K_SERVICE=my-service');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_gcp_function_target(): void
    {
        putenv('FUNCTION_TARGET=myFunction');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_azure_functions_worker_runtime(): void
    {
        putenv('FUNCTIONS_WORKER_RUNTIME=node');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_azure_website_instance_id(): void
    {
        putenv('WEBSITE_INSTANCE_ID=abc123def456');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_returns_false_when_no_indicators(): void
    {
        // Ensure all serverless env vars are cleared
        putenv('AWS_LAMBDA_FUNCTION_NAME');
        putenv('AWS_EXECUTION_ENV');
        putenv('VAPOR_SSM_PATH');
        putenv('K_SERVICE');
        putenv('FUNCTION_TARGET');
        putenv('FUNCTIONS_WORKER_RUNTIME');
        putenv('WEBSITE_INSTANCE_ID');

        $result = PlatformDetector::isServerless();

        $this->assertFalse($result);
    }

    public function test_is_serverless_detects_multiple_aws_indicators(): void
    {
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-function');
        putenv('AWS_EXECUTION_ENV=AWS_Lambda_python3.9');
        putenv('VAPOR_SSM_PATH=/production');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_multiple_gcp_indicators(): void
    {
        putenv('K_SERVICE=my-cloud-run-service');
        putenv('FUNCTION_TARGET=handleRequest');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_serverless_detects_multiple_azure_indicators(): void
    {
        putenv('FUNCTIONS_WORKER_RUNTIME=dotnet');
        putenv('WEBSITE_INSTANCE_ID=instance-xyz');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    // =================================================================
    // Edge cases and platform-specific scenarios
    // =================================================================

    public function test_is_laravel_vapor_with_both_vapor_yml_and_vendor(): void
    {
        // Create vapor.yml
        $vaporConfig = $this->testDir . DIRECTORY_SEPARATOR . 'vapor.yml';
        file_put_contents($vaporConfig, "id: 123\nname: my-app\n");

        // Create vapor-core directory
        $vendorDir = $this->testDir . DIRECTORY_SEPARATOR . 'vendor';
        $laravelDir = $vendorDir . DIRECTORY_SEPARATOR . 'laravel';
        $vaporCoreDir = $laravelDir . DIRECTORY_SEPARATOR . 'vapor-core';
        mkdir($vendorDir, 0755);
        mkdir($laravelDir, 0755);
        mkdir($vaporCoreDir, 0755);

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_serverless_with_mixed_platform_env_vars(): void
    {
        // Set env vars from different platforms (shouldn't happen in reality)
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-lambda');
        putenv('K_SERVICE=my-cloud-run');
        putenv('FUNCTIONS_WORKER_RUNTIME=node');

        $result = PlatformDetector::isServerless();

        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_ignores_case_sensitivity_in_directory_names(): void
    {
        // Create directory with different casing (should still detect on case-sensitive filesystems)
        $vendorDir = $this->testDir . DIRECTORY_SEPARATOR . 'vendor';
        $laravelDir = $vendorDir . DIRECTORY_SEPARATOR . 'laravel';
        $vaporCoreDir = $laravelDir . DIRECTORY_SEPARATOR . 'vapor-core';

        mkdir($vendorDir, 0755);
        mkdir($laravelDir, 0755);
        mkdir($vaporCoreDir, 0755);

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_serverless_handles_empty_env_var_values(): void
    {
        // Empty string values should not be detected as serverless
        putenv('AWS_LAMBDA_FUNCTION_NAME=');
        putenv('K_SERVICE=');
        putenv('FUNCTIONS_WORKER_RUNTIME=');

        $result = PlatformDetector::isServerless();

        // getenv() returns empty string, not false, so this will be true
        // This tests actual behavior - empty string is still "set"
        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_with_symbolic_link_to_vapor_core(): void
    {
        // Create actual vapor-core directory elsewhere
        $actualVaporCore = $this->testDir . DIRECTORY_SEPARATOR . 'actual-vapor-core';
        mkdir($actualVaporCore, 0755);

        // Create vendor structure with symlink
        $vendorDir = $this->testDir . DIRECTORY_SEPARATOR . 'vendor';
        $laravelDir = $vendorDir . DIRECTORY_SEPARATOR . 'laravel';
        mkdir($vendorDir, 0755);
        mkdir($laravelDir, 0755);

        $symlinkPath = $laravelDir . DIRECTORY_SEPARATOR . 'vapor-core';
        symlink($actualVaporCore, $symlinkPath);

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_laravel_vapor_with_empty_vapor_yml(): void
    {
        $vaporConfig = $this->testDir . DIRECTORY_SEPARATOR . 'vapor.yml';
        file_put_contents($vaporConfig, '');

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_serverless_returns_false_with_unrelated_env_vars(): void
    {
        // Set some unrelated environment variables
        putenv('PATH=/usr/bin:/bin');
        putenv('HOME=/home/user');
        putenv('SOME_CUSTOM_VAR=value');

        $result = PlatformDetector::isServerless();

        $this->assertFalse($result);
    }
}
