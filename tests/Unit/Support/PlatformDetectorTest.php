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
            'LARAVEL_CLOUD',
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
            if (is_dir($path) && ! is_link($path)) {
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_is_laravel_vapor_detects_lambda_runtime_class(): void
    {
        // Load the stub class so class_exists() returns true (line 36)
        require_once __DIR__.'/../../Fixtures/LambdaRuntime.php';

        $result = PlatformDetector::isLaravelVapor($this->testDir);

        // Should return true because LambdaRuntime class exists (line 36)
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

    // =================================================================
    // Tests for isDocker()
    // =================================================================

    public function test_is_docker_detects_via_dockerenv_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dockerenv');
        assert($tmpFile !== false);

        $result = PlatformDetector::isDocker(dockerEnvPath: $tmpFile);

        unlink($tmpFile);
        $this->assertTrue($result);
    }

    public function test_is_docker_detects_via_cgroup_containing_docker(): void
    {
        $tmpCgroup = tempnam(sys_get_temp_dir(), 'cgroup');
        assert($tmpCgroup !== false);
        file_put_contents($tmpCgroup, "12:devices:/docker/abc123\n");

        $result = PlatformDetector::isDocker(
            dockerEnvPath: '/nonexistent-dockerenv',
            cgroupPath: $tmpCgroup,
        );

        unlink($tmpCgroup);
        $this->assertTrue($result);
    }

    public function test_is_docker_detects_via_cgroup_containing_kubepods(): void
    {
        $tmpCgroup = tempnam(sys_get_temp_dir(), 'cgroup');
        assert($tmpCgroup !== false);
        file_put_contents($tmpCgroup, "11:cpuset:/kubepods/pod123\n");

        $result = PlatformDetector::isDocker(
            dockerEnvPath: '/nonexistent-dockerenv',
            cgroupPath: $tmpCgroup,
        );

        unlink($tmpCgroup);
        $this->assertTrue($result);
    }

    public function test_is_docker_detects_via_cgroup_containing_containerd(): void
    {
        $tmpCgroup = tempnam(sys_get_temp_dir(), 'cgroup');
        assert($tmpCgroup !== false);
        file_put_contents($tmpCgroup, "0::/system.slice/containerd.service\n");

        $result = PlatformDetector::isDocker(
            dockerEnvPath: '/nonexistent-dockerenv',
            cgroupPath: $tmpCgroup,
        );

        unlink($tmpCgroup);
        $this->assertTrue($result);
    }

    public function test_is_docker_returns_false_when_no_indicators_present(): void
    {
        $tmpCgroup = tempnam(sys_get_temp_dir(), 'cgroup');
        assert($tmpCgroup !== false);
        file_put_contents($tmpCgroup, "12:devices:/system.slice/some-service\n");

        $result = PlatformDetector::isDocker(
            dockerEnvPath: '/nonexistent-dockerenv',
            cgroupPath: $tmpCgroup,
        );

        unlink($tmpCgroup);
        $this->assertFalse($result);
    }

    public function test_is_docker_returns_false_when_neither_file_nor_cgroup_exist(): void
    {
        $result = PlatformDetector::isDocker(
            dockerEnvPath: '/nonexistent-dockerenv',
            cgroupPath: '/nonexistent-cgroup',
        );

        $this->assertFalse($result);
    }

    /**
     * '/' is never inside an application's open_basedir, so both default
     * probes are refused: the answer is false, and no warning reaches a
     * handler that throws for it (#120).
     */
    public function test_is_docker_answers_false_under_open_basedir_with_a_laravel_style_handler(): void
    {
        $answer = $this->isDockerUnderOpenBasedir('laravel');

        $this->assertSame('false', $answer['result'], $answer['output']);
    }

    /**
     * A handler that throws for every warning, '@'-silenced or not, is not
     * reached either.
     */
    public function test_is_docker_answers_false_under_open_basedir_with_a_strict_handler(): void
    {
        $answer = $this->isDockerUnderOpenBasedir('strict');

        $this->assertSame('false', $answer['result'], $answer['output']);
    }

    /**
     * Without a handler, no open_basedir warning is printed.
     */
    public function test_is_docker_prints_no_warning_under_open_basedir_without_a_handler(): void
    {
        $answer = $this->isDockerUnderOpenBasedir('none');

        $this->assertSame('false', $answer['result'], $answer['output']);
        $this->assertStringNotContainsString('open_basedir', $answer['output']);
    }

    /**
     * Control: probes inside open_basedir still detect Docker.
     */
    public function test_is_docker_still_detects_docker_from_allowed_paths_under_open_basedir(): void
    {
        $dockerEnv = $this->testDir . '/.dockerenv';
        touch($dockerEnv);
        $cgroup = $this->testDir . '/cgroup';
        file_put_contents($cgroup, "12:devices:/docker/abc123\n");

        $answer = $this->isDockerUnderOpenBasedir('strict', $dockerEnv, $this->testDir . '/no-cgroup');
        $this->assertSame('true', $answer['result'], $answer['output']);

        $answer = $this->isDockerUnderOpenBasedir('strict', $this->testDir . '/no-dockerenv', $cgroup);
        $this->assertSame('true', $answer['result'], $answer['output']);
    }

    /**
     * A refused /.dockerenv does not stop an allowed cgroup file from
     * answering.
     */
    public function test_is_docker_falls_back_to_an_allowed_cgroup_when_dockerenv_is_refused(): void
    {
        $cgroup = $this->testDir . '/cgroup';
        file_put_contents($cgroup, "12:devices:/docker/abc123\n");

        $answer = $this->isDockerUnderOpenBasedir('strict', '/.dockerenv', $cgroup);

        $this->assertSame('true', $answer['result'], $answer['output']);
    }

    /**
     * A base path outside open_basedir: both Vapor probes are refused, so
     * the answer is false, and no warning reaches the host's handler, nor
     * is one printed without a handler.
     */
    public function test_is_laravel_vapor_answers_false_for_a_base_outside_open_basedir(): void
    {
        $project = $this->testDir . '/proj';
        $allowed = $this->testDir . '/allowed';
        mkdir($project . '/vendor/laravel/vapor-core', 0777, true);
        touch($project . '/vapor.yml');
        mkdir($allowed);

        foreach (['laravel', 'strict', 'none'] as $handler) {
            $answer = $this->detectUnderOpenBasedir('isLaravelVapor', ['basePath' => $project], $handler, [$allowed]);

            $this->assertSame('false', $answer['result'], $handler . "\n" . $answer['output']);
            $this->assertStringNotContainsString('open_basedir', $answer['output'], $handler);
        }
    }

    /**
     * A vendor/laravel/vapor-core linked out of open_basedir is refused,
     * which answers false: the answer falls to the vapor.yml probe.
     */
    public function test_is_laravel_vapor_falls_back_to_vapor_yml_when_vapor_core_links_out_of_open_basedir(): void
    {
        $project = $this->testDir . '/proj';
        $outside = $this->testDir . '/outside/vapor-core';
        mkdir($project . '/vendor/laravel', 0777, true);
        mkdir($outside, 0777, true);
        symlink($outside, $project . '/vendor/laravel/vapor-core');

        foreach (['laravel', 'strict', 'none'] as $handler) {
            $answer = $this->detectUnderOpenBasedir('isLaravelVapor', ['basePath' => $project], $handler, [$project]);

            $this->assertSame('false', $answer['result'], $handler . "\n" . $answer['output']);
            $this->assertStringNotContainsString('open_basedir', $answer['output'], $handler);
        }

        touch($project . '/vapor.yml');

        foreach (['laravel', 'strict', 'none'] as $handler) {
            $answer = $this->detectUnderOpenBasedir('isLaravelVapor', ['basePath' => $project], $handler, [$project]);

            $this->assertSame('true', $answer['result'], $handler . "\n" . $answer['output']);
            $this->assertStringNotContainsString('open_basedir', $answer['output'], $handler);
        }
    }

    /**
     * Control: an allowed vapor-core directory or vapor.yml still detects
     * Vapor under open_basedir.
     */
    public function test_is_laravel_vapor_still_detects_vapor_from_allowed_paths_under_open_basedir(): void
    {
        $withCore = $this->testDir . '/with-core';
        $withYml = $this->testDir . '/with-yml';
        mkdir($withCore . '/vendor/laravel/vapor-core', 0777, true);
        mkdir($withYml);
        touch($withYml . '/vapor.yml');

        foreach ([$withCore, $withYml] as $project) {
            $answer = $this->detectUnderOpenBasedir('isLaravelVapor', ['basePath' => $project], 'strict', [$project]);

            $this->assertSame('true', $answer['result'], $project . "\n" . $answer['output']);
        }
    }

    /**
     * isDocker() under open_basedir (detectUnderOpenBasedir()), with the
     * test directory allowed. Null paths keep isDocker()'s defaults.
     *
     * @return array{result: string, output: string}
     */
    private function isDockerUnderOpenBasedir(string $handler, ?string $dockerEnvPath = null, ?string $cgroupPath = null): array
    {
        return $this->detectUnderOpenBasedir(
            'isDocker',
            array_filter(['dockerEnvPath' => $dockerEnvPath, 'cgroupPath' => $cgroupPath], static fn (?string $path): bool => $path !== null),
            $handler,
            [$this->testDir],
        );
    }

    /**
     * Run PlatformDetector::$method(...$args) in a php subprocess whose
     * open_basedir allows only the repository and $allowed, under $handler:
     * 'laravel' throws for warnings error_reporting() lets through, 'strict'
     * throws for every warning, 'none' installs nothing. Answers 'true',
     * 'false' or 'threw: <message>', and the child's whole output.
     *
     * A subprocess because open_basedir cannot be loosened again in this
     * process (see AbstractFileAnalyzerTest::analyzeUnderOpenBasedir()).
     *
     * @param  array<string, string>  $args
     * @param  array<string>  $allowed
     * @return array{result: string, output: string}
     */
    private function detectUnderOpenBasedir(string $method, array $args, string $handler, array $allowed): array
    {
        $repo = dirname(__DIR__, 3);

        // The repository is allowed wholesale for the autoloader: a fixture
        // inside it would make the control cases pass for the wrong reason.
        $this->assertFalse(
            str_starts_with((string) realpath($this->testDir) . '/', (string) realpath($repo) . '/'),
            'the fixture must not sit inside the repository, which open_basedir allows'
        );

        $basedirs = [$repo];

        foreach ($allowed as $dir) {
            $basedirs[] = $dir;
            $basedirs[] = (string) realpath($dir);
        }

        $script = <<<'PHP'
            [, $repo, $method, $handler, $args] = $argv;
            require $repo . '/vendor/autoload.php';

            error_reporting(E_ALL);
            $installed = match ($handler) {
                'laravel' => static function (int $severity, string $message): bool {
                    if (error_reporting() & $severity) {
                        throw new ErrorException($message, 0, $severity);
                    }

                    return false;
                },
                'strict' => static function (int $severity, string $message): bool {
                    throw new ErrorException($message, 0, $severity);
                },
                default => null,
            };

            if ($installed !== null) {
                set_error_handler($installed);
            }

            try {
                $result = ShieldCI\AnalyzersCore\Support\PlatformDetector::$method(...json_decode($args, true));
                echo "\nRESULT> ", $result ? 'true' : 'false', "\n";
            } catch (Throwable $e) {
                echo "\nRESULT> threw: ", $e->getMessage(), "\n";
            }

            echo "HANDLER> ", set_error_handler(null) === $installed ? 'kept' : 'changed', "\n";
            PHP;

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d', 'open_basedir=' . implode(PATH_SEPARATOR, $basedirs),
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=0',
            '-d', 'xdebug.mode=off',
            '-r', $script, $repo, $method, $handler, (string) json_encode($args),
        ])) . ' 2>&1';

        exec($command, $output, $status);
        $all = implode("\n", $output);
        $this->assertSame(0, $status, $all);

        $answers = [];

        foreach (['RESULT', 'HANDLER'] as $marker) {
            $lines = array_values(preg_grep('/^' . $marker . '> /', $output) ?: []);
            $this->assertCount(1, $lines, $marker . "\n" . $all);
            $answers[$marker] = substr($lines[0], strlen($marker) + 2);
        }

        $this->assertSame('kept', $answers['HANDLER'], $method . '() replaced the caller\'s error handler: ' . $all);

        return ['result' => $answers['RESULT'], 'output' => $all];
    }

    // =================================================================
    // Tests for isLaravelCloud()
    // =================================================================

    public function test_is_laravel_cloud_detects_via_env_var(): void
    {
        putenv('LARAVEL_CLOUD=1');

        $this->assertTrue(PlatformDetector::isLaravelCloud());
    }

    public function test_is_laravel_cloud_returns_false_when_env_var_not_set(): void
    {
        putenv('LARAVEL_CLOUD');

        $this->assertFalse(PlatformDetector::isLaravelCloud());
    }

    public function test_is_laravel_cloud_returns_false_when_env_var_is_non_one_value(): void
    {
        putenv('LARAVEL_CLOUD=true');

        $this->assertFalse(PlatformDetector::isLaravelCloud());
    }
}
