<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Enums\ParseFailureCause;
use ShieldCI\AnalyzersCore\Support\AstParser;
use ShieldCI\AnalyzersCore\Support\FilamentPanelDetector;
use ShieldCI\AnalyzersCore\Support\PackageDetector;
use ShieldCI\AnalyzersCore\Tests\Support\CreatesTestApplication;
use ShieldCI\AnalyzersCore\Tests\Support\FakeRecordingParser;

class FilamentPanelDetectorTest extends TestCase
{
    use CreatesTestApplication;

    /**
     * Trips the cheap str_contains() gate on both 'extends' and 'PanelProvider', parses fine,
     * and is not a panel provider. Shared so the tests that differ only in which parser they
     * inject cannot drift apart in their fixture instead.
     */
    private const NOT_A_PANEL_PROVIDER = <<<'PHP'
        <?php

        namespace App\Providers;

        use Illuminate\Support\ServiceProvider;

        // Nothing to do with PanelProvider, despite the word appearing here.
        class AppServiceProvider extends ServiceProvider
        {
        }
        PHP;

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

        $parser = new FakeRecordingParser(new \RuntimeException('parser exploded'));

        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));

        // The limit of the docblock's promise that the parser says how the answer was reached,
        // asserted rather than left to be discovered. A parser that throws never recorded a
        // failure, and recordRecovery() is defined as a no-op without one, so this verdict comes
        // out of a regex and says so nowhere. RecordingParserInterface has no recordFailure()
        // for the detector to call, so closing this means widening that contract.
        $this->assertSame([], $parser->failures());
        $this->assertSame([], $parser->recoveries());
    }

    /**
     * Bookkeeping is a courtesy and must not cost the caller its verdict. recordRecovery() is
     * the one parser call the detector makes after it already knows the answer, so a consumer
     * implementation that throws there -- a container-backed log, a full disk -- has to lose the
     * log entry, not turn isConfigured() into an exception the caller never asked to handle.
     */
    public function test_survives_a_parser_that_throws_when_recording_a_recovery(): void
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
}
PHP);

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        // Parses fine and is on the failure log, so the AST path reaches recordRecovery().
        $rebuilt = (new AstParser())->parseCode("<?php\nclass AdminPanelProvider extends PanelProvider {}\n");

        $parser = new FakeRecordingParser(
            null,
            $rebuilt,
            [$providerFile],
            new \RuntimeException('record exploded'),
        );

        $this->assertTrue((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
    }

    /**
     * A commented-out provider is not a provider. The regex runs on a file nobody can parse --
     * that is the whole premise -- so it is the one match in the package with no syntax tree
     * behind it to tell a live declaration from a dead one. Left on raw source it reports a
     * class that does not exist, and the caller cannot tell that verdict from a real one.
     */
    public function test_the_fallback_ignores_a_provider_that_only_appears_in_a_comment(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/AppServiceProvider.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers\Filament;

use Illuminate\Support\ServiceProvider;

// class AdminPanelProvider extends PanelProvider (removed in v3)
class AppServiceProvider extends ServiceProvider
{
    public function register(
}
PHP);

        // Still listed, which is what makes the false positive reachable end to end: recover a
        // class name from the comment and it matches a registration that means nothing.
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $parser = new AstParser();

        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));

        // The file genuinely would not parse, so the failure record stands. Nothing was
        // recovered from it, and the log has to keep those two apart.
        $this->assertTrue($parser->hasFailure($providerFile));
        $this->assertSame([], $parser->recoveries());
    }

    /**
     * The same hole one step along: a provider declaration inside a string literal -- a code
     * generator's template, a fixture, a docblock example lifted into a heredoc -- reads to a
     * regex exactly like the real thing.
     */
    public function test_the_fallback_ignores_a_provider_inside_a_string_literal(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $filamentDir = $this->testDir.'/app/Providers/Filament';
        mkdir($filamentDir, 0755, true);

        $providerFile = $filamentDir.'/PanelStubs.php';
        file_put_contents($providerFile, <<<'PHP'
<?php

namespace App\Providers\Filament;

use Illuminate\Support\ServiceProvider;

class PanelStubs extends ServiceProvider
{
    public const STUB = 'class AdminPanelProvider extends PanelProvider {}';

    public function register(
}
PHP);

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $parser = new AstParser();

        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));
        $this->assertSame([], $parser->recoveries());
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

        file_put_contents($providersDir.'/AppServiceProvider.php', self::NOT_A_PANEL_PROVIDER);

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
     * #78: a second run on a shared parser. The orchestrator resets the failure log between
     * runs but need not drain the AST cache, and the fallback is gated on hasFailure() -- so
     * a cached empty AST from the first run would silently switch recovery off in the second.
     */
    public function test_recovers_a_broken_panel_provider_again_after_the_failure_log_is_reset(): void
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
        $detector = new FilamentPanelDetector($parser);

        $this->assertTrue($detector->isConfigured($this->testDir));

        $parser->resetFailures();

        $this->assertTrue($detector->isConfigured($this->testDir));
        $this->assertTrue($parser->hasFailure($providerFile));
        $this->assertSame([$providerFile], $parser->recoveries());
    }

    /**
     * The fallback is gated on a recorded failure, not on an empty AST. A file that parses
     * cleanly and simply is not a panel provider must not reach the regex: "no panel provider
     * here" is a real answer and the AST is the authority on it. Gate on the AST instead and
     * the reasoning inverts into #76 itself -- a file whose statements are legitimately absent
     * gets read as one the parser could not read.
     */
    public function test_does_not_run_the_fallback_over_a_file_that_parsed(): void
    {
        $this->createComposerLock(['filamentphp/filament']);

        $providersDir = $this->testDir.'/app/Providers';
        mkdir($providersDir, 0755, true);

        file_put_contents($providersDir.'/AppServiceProvider.php', self::NOT_A_PANEL_PROVIDER);

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

    /**
     * Filament's make:filament-panel writes to app/Providers/Filament, but nothing stops an
     * application grouping its panels one directory further down. A provider there is every bit
     * as registered, and answering false for it skips every check gated on this.
     */
    public function test_detects_a_panel_provider_one_directory_below_filament(): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Filament/Admin', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\Admin\\AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_detects_a_panel_provider_in_a_directory_other_than_filament(): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Panels', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Panels\\AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_detects_a_panel_provider_three_directories_down(): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Filament/Panels/Admin', 'AdminPanelProvider');
        $this->registerProviderInConfigApp('App\\Providers\\Filament\\Panels\\Admin\\AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_the_deprecated_package_detector_entry_point_finds_a_nested_provider(): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Filament/Admin', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\Admin\\AdminPanelProvider');

        $this->assertTrue(PackageDetector::isFilamentConfigured($this->testDir));
    }

    public function test_a_nested_file_that_is_not_a_panel_provider_does_not_count(): void
    {
        $this->createComposerLock(['filament/filament']);

        $dir = $this->testDir.'/app/Providers/Filament/Admin';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/AppServiceProvider.php', self::NOT_A_PANEL_PROVIDER);

        $this->registerProviderInBootstrap('App\\Providers\\AppServiceProvider');

        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_a_nested_panel_provider_that_is_not_registered_does_not_count(): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Filament/Admin', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\AppServiceProvider');

        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * One walk replaced two directory listings, the first of which was inside the second's tree.
     * Each file has to be handed to the parser once, in an order that does not depend on the
     * filesystem's.
     */
    public function test_each_provider_file_is_parsed_once_in_sorted_order(): void
    {
        $this->createComposerLock(['filament/filament']);
        $nested = $this->writePanelProvider('Filament/Admin', 'AdminPanelProvider');
        $flat = $this->writePanelProvider('Filament', 'AppPanelProvider');
        $root = $this->writePanelProvider('', 'RootPanelProvider');

        $parser = new FakeRecordingParser();

        $this->assertFalse((new FilamentPanelDetector($parser))->isConfigured($this->testDir));

        $expected = [$nested, $flat, $root];
        sort($expected);

        $this->assertSame($expected, $parser->parsedPaths);
    }

    /**
     * A link back up the tree would make a walk that follows links run until the path is too
     * long to open. Links are not followed, so the walk ends and the real provider is found once.
     */
    public function test_a_symlinked_directory_loop_neither_hangs_nor_hides_the_provider(): void
    {
        $this->createComposerLock(['filament/filament']);
        $provider = $this->writePanelProvider('Filament/Admin', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\Admin\\AdminPanelProvider');

        if (! @symlink($this->testDir.'/app/Providers', $this->testDir.'/app/Providers/Filament/Loop')) {
            $this->markTestSkipped('symlink() is not available here');
        }

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($this->testDir);

        $this->assertSame([$provider], $parser->parsedPaths);
        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_an_unreadable_directory_does_not_lose_a_provider_elsewhere(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Panels', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Panels\\AdminPanelProvider');

        $locked = $this->testDir.'/app/Providers/Filament';
        mkdir($locked.'/Admin', 0755, true);
        chmod($locked, 0000);

        try {
            $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);
        } finally {
            chmod($locked, 0755);
        }

        $this->assertTrue($result);
    }

    public function test_an_unreadable_providers_directory_answers_false_rather_than_throwing(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Filament', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $providers = $this->testDir.'/app/Providers';
        chmod($providers, 0000);

        try {
            $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);
        } finally {
            chmod($providers, 0755);
        }

        $this->assertFalse($result);
    }

    /**
     * A provider kept elsewhere and linked into app/Providers is as registered as one written
     * there, and the flat globs this walk replaced always found it.
     */
    public function test_detects_a_panel_provider_reached_through_a_file_link(): void
    {
        $this->createComposerLock(['filament/filament']);

        $shared = $this->testDir.'/shared';
        mkdir($shared, 0755, true);
        mkdir($this->testDir.'/app/Providers/Filament', 0755, true);
        file_put_contents($shared.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));

        if (! @symlink($shared.'/AdminPanelProvider.php', $this->testDir.'/app/Providers/Filament/AdminPanelProvider.php')) {
            $this->markTestSkipped('symlink() is not available here');
        }

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    public function test_detects_a_panel_provider_reached_through_a_directory_link(): void
    {
        $this->createComposerLock(['filament/filament']);

        $panels = $this->testDir.'/packages/panels';
        mkdir($panels, 0755, true);
        mkdir($this->testDir.'/app/Providers', 0755, true);
        file_put_contents($panels.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));

        if (! @symlink($panels, $this->testDir.'/app/Providers/Filament')) {
            $this->markTestSkipped('symlink() is not available here');
        }

        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * A host whose error handler throws on every warning must not lose the verdict to one file it
     * cannot read, any more than an unreadable directory loses it.
     */
    public function test_an_unreadable_file_neither_warns_nor_loses_a_provider_elsewhere(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        $this->createComposerLock(['filament/filament']);
        $this->writePanelProvider('Panels', 'AdminPanelProvider');
        $locked = $this->writePanelProvider('Filament/Admin', 'LockedPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Panels\\AdminPanelProvider');

        chmod($locked, 0000);
        set_error_handler(static function (int $errno, string $message): never {
            throw new \ErrorException($message, 0, $errno);
        });

        try {
            $result = (new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir);
        } finally {
            restore_error_handler();
            chmod($locked, 0644);
        }

        $this->assertTrue($result);
    }

    /**
     * Panels shared between applications live outside any one of them, linked in. The globs
     * this walk replaced read straight through such a link, so the walk has to as well.
     */
    public function test_a_directory_link_out_of_the_project_is_walked(): void
    {
        $outside = sys_get_temp_dir().'/shield-ci-shared-panels-'.uniqid();

        try {
            $this->createComposerLock(['filament/filament']);
            mkdir($outside, 0755, true);
            mkdir($this->testDir.'/app/Providers', 0755, true);
            file_put_contents($outside.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
            $this->symlinkOrSkip($outside, $this->testDir.'/app/Providers/Filament');
            $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

            $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
        } finally {
            $this->removeDirectory($outside);
        }
    }

    public function test_a_monorepo_application_whose_providers_directory_is_a_link_out_is_walked(): void
    {
        $base = $this->testDir.'/apps/web';
        $this->makeApplicationAt($base, 'App\\Providers\\Filament\\AdminPanelProvider');

        $shared = $this->testDir.'/monorepo/shared/Providers';
        mkdir($shared.'/Filament', 0755, true);
        file_put_contents($shared.'/Filament/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
        mkdir($base.'/app', 0755, true);
        $this->symlinkOrSkip($shared, $base.'/app/Providers');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    public function test_a_monorepo_application_whose_app_directory_is_a_link_out_is_walked(): void
    {
        $base = $this->testDir.'/apps/web';
        $this->makeApplicationAt($base, 'App\\Providers\\Filament\\AdminPanelProvider');

        $app = $this->testDir.'/src/app';
        mkdir($app.'/Providers/Filament', 0755, true);
        file_put_contents($app.'/Providers/Filament/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
        $this->symlinkOrSkip($app, $base.'/app');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    /**
     * realpath() keeps the case it was given on a case-insensitive filesystem, so the base path
     * and a link target can spell one directory two ways.
     */
    public function test_a_base_path_spelled_in_another_case_still_follows_a_link_inside_it(): void
    {
        $this->skipUnlessCaseInsensitive();

        $real = $this->testDir.'/CaseApp';
        $this->makeApplicationAt($real, 'App\\Providers\\Filament\\AdminPanelProvider');

        mkdir($real.'/packages/panels', 0755, true);
        mkdir($real.'/app/Providers', 0755, true);
        file_put_contents($real.'/packages/panels/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
        $this->symlinkOrSkip($real.'/packages/panels', $real.'/app/Providers/Filament');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir.'/caseapp'));
    }

    /**
     * Dependencies are someone else's panels and a great many files. Refused wherever the link
     * points, including a provider directory linked straight into vendor/.
     */
    public function test_a_directory_link_into_vendor_is_not_walked(): void
    {
        $this->assertLinkedDirectoryIsNotWalked($this->testDir.'/vendor/acme/panels', 'Lib');
    }

    public function test_a_panel_directory_linked_into_vendor_is_not_walked(): void
    {
        $this->assertLinkedDirectoryIsNotWalked($this->testDir.'/vendor/acme/panels', 'Filament');
    }

    public function test_a_directory_link_into_node_modules_is_not_walked(): void
    {
        $this->assertLinkedDirectoryIsNotWalked($this->testDir.'/node_modules/acme', 'Lib');
    }

    public function test_vendor_is_refused_whatever_case_the_link_spells_it_in(): void
    {
        $this->skipUnlessCaseInsensitive();

        mkdir($this->testDir.'/vendor/acme', 0755, true);

        $this->assertLinkedDirectoryIsNotWalked($this->testDir.'/VENDOR/acme', 'Lib');
    }

    /**
     * Only the segments below the project are dependency directories: one checked out inside
     * someone's vendor/ is still an application.
     */
    public function test_a_project_that_itself_lives_under_vendor_is_still_walked(): void
    {
        $base = $this->testDir.'/vendor/acme/app';
        $this->makeApplicationAt($base, 'App\\Providers\\Filament\\AdminPanelProvider');

        mkdir($base.'/app/Providers/Filament', 0755, true);
        file_put_contents($base.'/app/Providers/Filament/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    /**
     * The project's own path is not a dependency directory, and neither is the part of it a link
     * target shares: panels kept beside a project that lives under vendor/ are still panels.
     */
    public function test_an_outside_target_sharing_the_projects_vendor_prefix_is_walked(): void
    {
        $base = $this->testDir.'/vendor/proj';
        $this->makeApplicationAt($base, 'App\\Providers\\Filament\\AdminPanelProvider');

        $shared = $this->testDir.'/vendor/shared/Filament';
        mkdir($shared, 0755, true);
        file_put_contents($shared.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
        mkdir($base.'/app/Providers', 0755, true);
        $this->symlinkOrSkip($shared, $base.'/app/Providers/Filament');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    public function test_a_monorepo_under_vendor_whose_app_directory_is_a_link_is_walked(): void
    {
        $mono = $this->testDir.'/vendor/mono';
        $base = $mono.'/apps/admin';
        $this->makeApplicationAt($base, 'App\\Providers\\Filament\\AdminPanelProvider');

        mkdir($mono.'/shared/app/Providers/Filament', 0755, true);
        file_put_contents($mono.'/shared/app/Providers/Filament/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Filament', 'AdminPanelProvider'));
        $this->symlinkOrSkip($mono.'/shared/app', $base.'/app');

        $this->assertTrue((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    public function test_an_outside_target_with_its_own_vendor_segment_is_still_refused(): void
    {
        $base = $this->testDir.'/vendor/proj';
        $this->makeApplicationAt($base, 'App\\Providers\\Lib\\AdminPanelProvider');

        $target = $this->testDir.'/vendor/shared/vendor/x';
        mkdir($target, 0755, true);
        file_put_contents($target.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Lib', 'AdminPanelProvider'));
        mkdir($base.'/app/Providers', 0755, true);
        $this->symlinkOrSkip($target, $base.'/app/Providers/Lib');

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($base);

        $this->assertSame([], $parser->parsedPaths);
        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($base));
    }

    /**
     * Once app/Providers is a link out, app/ is no longer above the walk's start, and a link back
     * to it would walk the rest of the application. It is refused all the same.
     */
    public function test_a_link_back_to_the_app_directory_from_outside_is_not_walked(): void
    {
        $this->makeApplicationAt($this->testDir, 'App\\Filament\\OtherPanelProvider');

        $shared = $this->testDir.'/shared/Providers';
        mkdir($shared, 0755, true);
        mkdir($this->testDir.'/app/Filament', 0755, true);
        file_put_contents($this->testDir.'/app/Filament/OtherPanelProvider.php', self::panelProviderSource('App\\Filament', 'OtherPanelProvider'));
        $this->symlinkOrSkip($shared, $this->testDir.'/app/Providers');
        $this->symlinkOrSkip($this->testDir.'/app', $shared.'/Back');

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($this->testDir);

        $this->assertSame([], $parser->parsedPaths);
        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * A link to the project root, to the directory above it, or to app/ brings the whole
     * project -- or the whole machine -- into range. None of them is entered.
     */
    public function test_a_link_to_the_project_root_is_not_walked(): void
    {
        $this->assertWalkStopsAtLinkTo($this->testDir, 'packages/RootPanelProvider.php');
    }

    public function test_a_link_to_the_directory_above_the_project_is_not_walked(): void
    {
        $this->assertWalkStopsAtLinkTo(dirname($this->testDir), 'packages/RootPanelProvider.php');
    }

    public function test_a_link_to_the_app_directory_is_not_walked(): void
    {
        $this->assertWalkStopsAtLinkTo($this->testDir.'/app', 'app/Other/OtherPanelProvider.php');
    }

    public function test_a_providers_directory_that_is_a_link_to_the_project_root_walks_nothing(): void
    {
        $this->createComposerLock(['filament/filament']);
        mkdir($this->testDir.'/app', 0755, true);
        mkdir($this->testDir.'/Panels', 0755, true);
        file_put_contents($this->testDir.'/Panels/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\Panels', 'AdminPanelProvider'));
        $this->registerProviderInBootstrap('App\\Providers\\Panels\\AdminPanelProvider');
        $this->symlinkOrSkip($this->testDir, $this->testDir.'/app/Providers');

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($this->testDir);

        $this->assertSame([], $parser->parsedPaths);
        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * Link app/Providers/Up to $target, put a panel-provider-like file at $decoy below the
     * project, and assert the walk ends having parsed only the real provider.
     */
    private function assertWalkStopsAtLinkTo(string $target, string $decoy): void
    {
        $this->createComposerLock(['filament/filament']);
        $provider = $this->writePanelProvider('Filament', 'AdminPanelProvider');
        $this->registerProviderInBootstrap('App\\Providers\\Filament\\AdminPanelProvider');

        foreach ([$decoy, 'vendor/acme/VendorPanelProvider.php', 'node_modules/acme/VendorPanelProvider.php'] as $file) {
            $path = $this->testDir.'/'.$file;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, self::panelProviderSource('Acme', basename($file, '.php')));
        }

        $this->symlinkOrSkip($target, $this->testDir.'/app/Providers/Up');

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($this->testDir);

        $this->assertSame([$provider], $parser->parsedPaths);
    }

    /**
     * Link app/Providers/$linkName to $target, holding a registered panel provider, and assert
     * the walk never parses it.
     */
    private function assertLinkedDirectoryIsNotWalked(string $target, string $linkName): void
    {
        $this->createComposerLock(['filament/filament']);
        $real = $this->writePanelProvider('Real', 'RealPanelProvider');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        file_put_contents($target.'/AdminPanelProvider.php', self::panelProviderSource('App\\Providers\\'.$linkName, 'AdminPanelProvider'));
        $this->registerProviderInBootstrap('App\\Providers\\'.$linkName.'\\AdminPanelProvider');
        $this->symlinkOrSkip($target, $this->testDir.'/app/Providers/'.$linkName);

        $parser = new FakeRecordingParser();
        (new FilamentPanelDetector($parser))->isConfigured($this->testDir);

        $this->assertSame([$real], $parser->parsedPaths);
        $this->assertFalse((new FilamentPanelDetector(new AstParser()))->isConfigured($this->testDir));
    }

    /**
     * Give the application at $base a composer.lock with Filament and $providerClass registered.
     */
    private function makeApplicationAt(string $base, string $providerClass): void
    {
        $this->createComposerLock(['filament/filament']);
        $this->registerProviderInBootstrap($providerClass);

        if (! is_dir($base)) {
            mkdir($base, 0755, true);
        }

        rename($this->testDir.'/composer.lock', $base.'/composer.lock');
        rename($this->testDir.'/bootstrap', $base.'/bootstrap');
    }

    private function symlinkOrSkip(string $target, string $link): void
    {
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('symlink() is not available here');
        }
    }

    private function skipUnlessCaseInsensitive(): void
    {
        mkdir($this->testDir.'/CaseProbe');
        $insensitive = is_dir($this->testDir.'/caseprobe');
        rmdir($this->testDir.'/CaseProbe');

        if (! $insensitive) {
            $this->markTestSkipped('the filesystem here is case-sensitive');
        }
    }

    private function skipWhenPermissionsAreNotEnforced(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod() does not lock a directory on Windows');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a directory whatever its mode');
        }
    }

    /**
     * Write a panel provider under app/Providers/$subdirectory, namespaced to match, and return
     * its path.
     */
    private function writePanelProvider(string $subdirectory, string $className): string
    {
        $dir = $this->testDir.'/app/Providers'.($subdirectory === '' ? '' : '/'.$subdirectory);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $namespace = 'App\\Providers'.($subdirectory === '' ? '' : '\\'.str_replace('/', '\\', $subdirectory));
        $path = $dir.'/'.$className.'.php';

        file_put_contents($path, self::panelProviderSource($namespace, $className));

        return $path;
    }

    private static function panelProviderSource(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Filament\\Panel\\PanelProvider;

class {$className} extends PanelProvider
{
}
PHP;
    }
}
