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
}
