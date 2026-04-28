<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Detects deployment platforms and runtime environments.
 *
 * Provides static methods to detect various Laravel deployment platforms
 * and hosting environments to enable platform-specific analysis logic.
 */
class PlatformDetector
{
    /**
     * Check if Laravel Vapor is installed/being used.
     *
     * Laravel Vapor uses serverless architecture (AWS Lambda) which is
     * incompatible with long-running processes like Horizon, scheduled tasks,
     * and traditional queue workers.
     *
     * Detection methods:
     * 1. Runtime class existence (most reliable for active Vapor deployments)
     * 2. Package directory existence (detects Vapor installation)
     * 3. Configuration file existence (detects Vapor project setup)
     *
     * @param  string  $basePath  The application base path
     * @return bool True if Laravel Vapor is detected
     *
     * @see https://github.com/laravel/vapor-core
     */
    public static function isLaravelVapor(string $basePath): bool
    {
        // Check if Vapor runtime is loaded (active Vapor deployment)
        if (class_exists(\Laravel\Vapor\Runtime\LambdaRuntime::class)) {
            return true;
        }

        // Check if vapor-core package exists in vendor
        $vaporCorePath = $basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'vapor-core';

        if (is_dir($vaporCorePath)) {
            return true;
        }

        // Check for vapor.yml configuration file (indicates Vapor project)
        $vaporConfigPath = $basePath.DIRECTORY_SEPARATOR.'vapor.yml';

        return file_exists($vaporConfigPath);
    }

    /**
     * Check if running in a serverless environment.
     *
     * Detects various serverless platforms that use ephemeral containers
     * or functions (AWS Lambda, Google Cloud Functions, Azure Functions, etc.).
     *
     * This method performs runtime-only detection using environment variables
     * and loaded classes. It does not check filesystem for project configuration.
     *
     * @return bool True if serverless runtime environment is detected
     */
    public static function isServerless(): bool
    {
        // AWS Lambda (including Vapor runtime)
        if (
            getenv('AWS_LAMBDA_FUNCTION_NAME') !== false ||
            getenv('AWS_EXECUTION_ENV') !== false ||
            getenv('VAPOR_SSM_PATH') !== false ||
            class_exists(\Laravel\Vapor\Runtime\LambdaRuntime::class)
        ) {
            return true;
        }

        // Google Cloud Functions / Cloud Run
        if (
            getenv('K_SERVICE') !== false ||
            getenv('FUNCTION_TARGET') !== false
        ) {
            return true;
        }

        // Azure Functions / Azure Container Apps
        if (
            getenv('FUNCTIONS_WORKER_RUNTIME') !== false ||
            getenv('WEBSITE_INSTANCE_ID') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if running inside a Docker container.
     *
     * Detection methods:
     * 1. /.dockerenv — created by Docker daemon in every container (most reliable)
     * 2. /proc/self/cgroup — fallback for rootless Docker and some Kubernetes setups
     *
     * Note: Cloud implies Docker, but Docker does NOT imply Cloud. Use isLaravelCloud()
     * for Cloud-specific logic; use this only for checks that are genuinely
     * "not applicable inside any container" (e.g. OS-level hardening analyzers).
     *
     * @param  string  $dockerEnvPath  Override for testing (default: /.dockerenv)
     * @param  string  $cgroupPath     Override for testing (default: /proc/self/cgroup)
     */
    public static function isDocker(
        string $dockerEnvPath = '/.dockerenv',
        string $cgroupPath = '/proc/self/cgroup',
    ): bool {
        // Docker daemon creates this file in every container
        if (file_exists($dockerEnvPath)) {
            return true;
        }

        // Fallback: cgroup inspection (Linux only)
        if (is_readable($cgroupPath)) {
            $cgroup = file_get_contents($cgroupPath);
            if ($cgroup !== false) {
                return str_contains($cgroup, 'docker')
                    || str_contains($cgroup, 'kubepods')
                    || str_contains($cgroup, 'containerd');
            }
        }

        return false;
    }

    /**
     * Check if running on Laravel Cloud.
     *
     * Uses the LARAVEL_CLOUD=1 env var, which Cloud sets on all compute types
     * (web, worker, scheduled task). This is the sole detection signal — no
     * fallback — because Cloud declined to commit to stability of any other
     * runtime fingerprint. A missing env var produces visible false positives,
     * which is preferable to a stale fallback producing silent misbehaviour.
     *
     * @see https://cloud.laravel.com/docs/environments
     */
    public static function isLaravelCloud(): bool
    {
        return getenv('LARAVEL_CLOUD') === '1';
    }
}
