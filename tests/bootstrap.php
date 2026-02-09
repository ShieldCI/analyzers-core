<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for analyzers-core tests.
 *
 * Defines controllable config() and base_path() stubs backed by $GLOBALS
 * so that tests can exercise code paths that depend on these Laravel helpers
 * without requiring an actual Laravel installation.
 *
 * Usage in tests:
 *   $GLOBALS['__shieldci_test_config'] = ['app.env' => 'staging'];
 *   $GLOBALS['__shieldci_test_base_path'] = '/custom/path';
 *
 * Reset in tearDown:
 *   unset($GLOBALS['__shieldci_test_config']);
 *   unset($GLOBALS['__shieldci_test_base_path']);
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------
// config() stub
// ---------------------------------------------------------------
if (! function_exists('config')) {
    /**
     * Stub for Laravel's config() helper.
     *
     * When called with a string key: returns the value from the test config store.
     * When called with an array: merges the values into the test config store.
     *
     * @param  array<string, mixed>|string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        // Initialise the store on first call
        if (! isset($GLOBALS['__shieldci_test_config'])) {
            $GLOBALS['__shieldci_test_config'] = [];
        }

        // config(['key' => 'value']) — setter mode
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $GLOBALS['__shieldci_test_config'][$k] = $v;
            }

            return null;
        }

        // config() with no args — return the whole store
        if ($key === null) {
            return $GLOBALS['__shieldci_test_config'];
        }

        // config('key', $default) — getter mode
        return $GLOBALS['__shieldci_test_config'][$key] ?? $default;
    }
}

// ---------------------------------------------------------------
// base_path() stub
// ---------------------------------------------------------------
if (! function_exists('base_path')) {
    /**
     * Stub for Laravel's base_path() helper.
     *
     * Returns $GLOBALS['__shieldci_test_base_path'] if set,
     * otherwise returns '' (empty string) to let getBasePath()
     * fall through to its getcwd() fallback.
     */
    function base_path(string $path = ''): string
    {
        $base = $GLOBALS['__shieldci_test_base_path'] ?? '';

        if ($path === '') {
            return $base;
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
