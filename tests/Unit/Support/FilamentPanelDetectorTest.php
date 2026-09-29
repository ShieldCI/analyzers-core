<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Support\FilamentPanelDetector;
use ShieldCI\AnalyzersCore\Tests\Support\CreatesTestApplication;
use ShieldCI\AnalyzersCore\Tests\Support\FakeRecordingParser;

class FilamentPanelDetectorTest extends TestCase
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

    public function test_is_filament_configured_returns_false_when_package_not_installed(): void
    {
        $this->createComposerLock(['laravel/framework']);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_returns_false_when_no_panel_providers(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_detects_admin_panel_provider_in_filament_dir(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        // Create app/Providers/Filament/AdminPanelProvider.php
        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('admin')
            ->default();
    }
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Register in bootstrap/providers.php (Laravel 11)
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_custom_panel_provider_in_providers_dir(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        // Create app/Providers/MyCustomPanelProvider.php (directly in Providers, not Filament subdir)
        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Filament\Panel\PanelProvider;

class MyCustomPanelProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('custom');
    }
}
PHP;
        file_put_contents($providersDir.'/MyCustomPanelProvider.php', $providerCode);

        // Register in config/app.php (Laravel 10)
        $this->registerProviderInConfigApp('App\\Providers\\MyCustomPanelProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_panel_provider_with_fully_qualified_name(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Use fully qualified class name with leading backslash
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

class AppPanelProvider extends \Filament\Panel\PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('app');
    }
}
PHP;
        file_put_contents($filamentDir.'/AppPanelProvider.php', $providerCode);

        // Register provider
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AppPanelProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_detects_panel_provider_with_use_statement(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Use statement imports PanelProvider, then just use the short name
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;
use Filament\Panel\Panel;

class DashboardProvider extends PanelProvider
{
    public function panel(): Panel
    {
        return Panel::make('dashboard');
    }
}
PHP;
        file_put_contents($filamentDir.'/DashboardProvider.php', $providerCode);

        // Register provider
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\DashboardProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_returns_false_when_provider_does_not_extend_panel_provider(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a provider that extends ServiceProvider, not PanelProvider
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Illuminate\Support\ServiceProvider;

class FilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
PHP;
        file_put_contents($filamentDir.'/FilamentServiceProvider.php', $providerCode);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_ignores_non_provider_files(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a non-provider file
        $helperCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

class FilamentHelper
{
    public static function configure(): void
    {
        // Just a helper, not a provider
    }
}
PHP;
        file_put_contents($filamentDir.'/FilamentHelper.php', $helperCode);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_handles_multiple_panel_providers(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create multiple panel providers
        $adminProvider = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $adminProvider);

        $appProvider = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AppPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AppPanelProvider.php', $appProvider);

        // Register one of them (should be sufficient)
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_returns_false_when_provider_not_registered(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create panel provider but DON'T register it
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Note: Not calling registerProviderInBootstrap or registerProviderInConfigApp

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_detects_registration_in_config_app(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Register in config/app.php (Laravel 10 style)
        $this->registerProviderInConfigApp('App\\Providers\\Filament\\AdminPanelProvider');

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertTrue($result);
    }

    public function test_is_filament_configured_skips_class_without_extends(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a PHP file that contains 'extends' and 'PanelProvider' as strings
        // (passes the quick string check) but the actual class has no extends clause
        // This triggers line 205: $class->extends === null
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

// The words "extends" and "PanelProvider" appear in this comment
class StandaloneClass
{
    public function description(): string
    {
        return 'This class extends nothing and is not a PanelProvider';
    }
}
PHP;
        file_put_contents($filamentDir.'/StandaloneClass.php', $providerCode);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_is_filament_configured_returns_false_when_provider_not_in_bootstrap(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a valid PanelProvider class
        $providerCode = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    //
}
PHP;
        file_put_contents($filamentDir.'/AdminPanelProvider.php', $providerCode);

        // Register a DIFFERENT provider — isAnyProviderInContent() returns false (line 321)
        $bootstrapDir = $this->testDir.'/bootstrap';
        mkdir($bootstrapDir, 0755, true);
        $content = <<<'PHP'
<?php

return [
    App\Providers\AppServiceProvider::class,
];
PHP;
        file_put_contents($bootstrapDir.'/providers.php', $content);

        $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);

        $this->assertFalse($result);
    }

    public function test_get_panel_provider_handles_unreadable_file(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        // Create a provider file with correct content but make it unreadable (line 189)
        $providerFile = $filamentDir.'/AdminPanelProvider.php';
        file_put_contents($providerFile, "<?php\nnamespace App\\Providers\\Filament;\nuse Filament\\Panel\\PanelProvider;\nclass AdminPanelProvider extends PanelProvider {}");
        chmod($providerFile, 0000);

        // Suppress expected PHP warning from file_get_contents on unreadable file
        $previousHandler = set_error_handler(fn () => true);

        try {
            $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);

        // Restore permissions for cleanup
        chmod($providerFile, 0644);
    }

    /**
     * A panel provider that will not parse makes isConfigured() answer false, which
     * is the same answer it gives for an application that never configured Filament. The
     * injected parser is what separates the two.
     *
     * The fixture has to contain both 'extends' and 'PanelProvider' as literal strings: a
     * cheap str_contains() gate rejects the file before any parsing otherwise, and a broken
     * file that fails that gate is never seen by the parser at all. Failure visibility here
     * is therefore partial by design -- do not read failures() as exhaustive for this method.
     *
     * The verdict below does not turn on the parse. A provider that does not parse still has
     * its class name recovered from the source; this one is unconfigured because nothing
     * registers it, and the recovery is recorded all the same.
     */
    public function test_is_filament_configured_records_a_broken_provider_on_an_injected_parser(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/AdminPanelProvider.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(
}
PHP);

        $parser = new AstParser();

        // False because nothing registers it, not because it would not parse: the class name is
        // recovered from the source either way. Register it and this is true -- see
        // test_recovers_a_registered_panel_provider_that_does_not_parse().
        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));

        $this->assertTrue($parser->hasFailure($providerFile));
        $this->assertSame(ParseFailureCause::SyntaxError, $parser->failures()[0]->cause);
        $this->assertSame([$providerFile], $parser->recoveries());
    }

    /**
     * A provider that parses leaves the injected parser's log empty, so the parameter does
     * not turn every healthy application into a reported failure.
     */
    public function test_an_injected_parser_records_nothing_for_a_provider_that_parses(): void
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

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $parser = new AstParser();

        // Asserting the verdict, not just the empty log. True is only reachable by parsing the
        // file and resolving its class name, so it is evidence the parser was actually used;
        // an empty failure log on its own is also what a detector that never opened the file
        // would produce, which is the opposite of what this test is for.
        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([], $parser->failures());
    }

    /**
     * AstParser records rather than throws, so the regex fallback in panelProviderClassName()
     * is unreachable through it -- which is why it carried a coverage-ignore until the
     * parameter became an interface. A consumer's own RecordingParserInterface may throw, and
     * then the fallback is the difference between "we could not read it" and telling an
     * application that plainly has Filament that it does not.
     */
    public function test_falls_back_to_a_regex_when_the_injected_parser_throws(): void
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

        $detector = new FilamentPanelDetector(new FakeRecordingParser(new \RuntimeException('parser exploded')));

        $this->assertTrue($detector->isConfigured($this->testDir));
    }

    /**
     * The fallback is not a rubber stamp: a file that trips the cheap string gate but does not
     * actually extend PanelProvider must still come back as not-a-panel-provider.
     */
    public function test_the_fallback_rejects_a_file_that_does_not_extend_panel_provider(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        // Contains both 'extends' and 'PanelProvider', so the cheap gate lets it through.
        file_put_contents($providersDir.'/AppServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Nothing to do with PanelProvider, despite the word appearing here.
class AppServiceProvider extends ServiceProvider
{
}
PHP);

        $detector = new FilamentPanelDetector(new FakeRecordingParser(new \RuntimeException('parser exploded')));

        $this->assertFalse($detector->isConfigured($this->testDir));
    }

    /**
     * An anonymous class extending PanelProvider satisfies the "does it extend" regex but has
     * no name to report, so the fallback has to give up rather than invent one.
     */
    public function test_the_fallback_gives_up_when_the_class_has_no_name(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        file_put_contents($providersDir.'/panels.php', <<<'PHP'
<?php

namespace App\Providers;

use Filament\Panel\PanelProvider;

return new class extends PanelProvider
{
};
PHP);

        $detector = new FilamentPanelDetector(new FakeRecordingParser(new \RuntimeException('parser exploded')));

        $this->assertFalse($detector->isConfigured($this->testDir));
    }

    /**
     * The detector asks for a contract, not for AstParser. A double that is plainly not an
     * AstParser must still drive it -- this is what the RecordingParserInterface parameter is
     * worth to a consumer holding its own parser.
     */
    public function test_drives_any_recording_parser_not_just_ast_parser(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/AdminPanelProvider.php';
        file_put_contents($providerFile, "<?php\n\nclass AdminPanelProvider extends PanelProvider\n{\n}\n");

        $parser = new FakeRecordingParser();

        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([$providerFile], $parser->parsedPaths);
    }


    /**
     * A provider in the global namespace. Unusual, but the join has to cope: there is no
     * namespace to prefix, so the bare class name is the fully qualified name, and prefixing
     * anything would produce a class that does not exist.
     */
    public function test_detects_a_panel_provider_that_declares_no_namespace(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        file_put_contents($providersDir.'/AdminPanelProvider.php', <<<'PHP'
<?php

class AdminPanelProvider extends PanelProvider
{
}
PHP);

        $this->registerProviderInBootstrap('AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * The bug behind #76, stated as behaviour: an application that has Filament, has written a
     * panel provider, and has registered it is configured -- and stays configured when that
     * provider stops parsing. Reporting false here is the same answer given for an application
     * that never had Filament, so every check gated on it is skipped on a panel that may be
     * unprotected.
     *
     * The verdict is recovered, not the file. The failure record stands, and recoveries() says
     * the class name came from a regex rather than an AST, so a reporter can tell the two apart.
     */
    public function test_recovers_a_registered_panel_provider_that_does_not_parse(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/AdminPanelProvider.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(
}
PHP);

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $parser = new AstParser();

        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));

        $this->assertTrue($parser->hasFailure($providerFile));
        $this->assertSame([$providerFile], $parser->recoveries());
        $this->assertSame([$providerFile], array_column($parser->failures(), 'path'));
    }

    /**
     * The fallback is gated on a recorded failure, not on an empty AST. A file that parses
     * cleanly and simply is not a panel provider must not reach the regex -- gate on the AST
     * instead and every empty file in app/Providers gets one, because an empty file parses
     * successfully to no statements and records nothing.
     */
    public function test_does_not_run_the_fallback_over_a_file_that_parsed(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        // Trips the cheap str_contains() gate on both words, parses fine, is not a panel provider.
        file_put_contents($providersDir.'/AppServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Nothing to do with PanelProvider, despite the word appearing here.
class AppServiceProvider extends ServiceProvider
{
}
PHP);

        $parser = new AstParser();

        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([], $parser->failures());
        $this->assertSame([], $parser->recoveries());
    }

    /**
     * The recovery names the class that extends PanelProvider, not merely the first class in the
     * file that extends anything. Both live here, the wrong one first, and only the anchored
     * match tells them apart.
     */
    public function test_the_fallback_names_the_class_that_actually_extends_panel_provider(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        $providerFile = $providersDir.'/Panels.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers;

use Filament\Panel\PanelProvider;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
}

class AdminPanelProvider extends PanelProvider
{
    public function panel(
}
PHP);

        $this->registerProviderInBootstrap('App\\Providers\\AdminPanelProvider');

        $parser = new AstParser();

        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([$providerFile], $parser->recoveries());
    }

    /**
     * An error-recovering parser rebuilds what it can and logs the file anyway, so it can hand
     * back a usable class from a file it also reported as a failure. That AST is better evidence
     * than a regex and is used as-is -- but it came out of a file that would not parse, so it is
     * still a recovery and the log has to say so.
     *
     * The two paths would otherwise be indistinguishable, since both end in recordRecovery(). So
     * the class the parser rebuilt is deliberately named differently from the one the regex would
     * read off the file, and only the rebuilt name is registered: true is reachable only if the
     * AST won.
     */
    public function test_records_a_recovery_when_the_parser_supplies_a_partial_ast(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/AdminPanelProvider.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel\PanelProvider;

class RegexWouldSayThis extends PanelProvider
{
    public function panel(
}
PHP);

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AstSaysThis');

        // What an error-recovering parser returns: the class it rebuilt, plus a logged failure.
        // Unwrapped by the namespace so it is a top-level node, which is all the fake filters.
        $rebuilt = (new AstParser())->parseCode("<?php\nclass AstSaysThis extends PanelProvider {}\n");

        $parser = new FakeRecordingParser(null, $rebuilt, [$providerFile]);

        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([$providerFile], $parser->recoveries());
    }
}
