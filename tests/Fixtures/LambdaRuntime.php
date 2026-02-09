<?php

declare(strict_types=1);

namespace Laravel\Vapor\Runtime;

/**
 * Stub class for testing PlatformDetector::isLaravelVapor().
 *
 * The real class lives in laravel/vapor-core, which is not a dependency
 * of analyzers-core. This stub allows us to test the class_exists()
 * detection path without installing the full Vapor package.
 *
 * This file is loaded explicitly in tests via require_once.
 */
class LambdaRuntime
{
}
