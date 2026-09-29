<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\Node\Stmt\Class_;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;

/**
 * Whether an application has Filament installed, a panel provider written, and it registered.
 *
 * All three have to hold, and false says only "not all three". A provider that does not parse is
 * not one of the three: its class name is read straight out of the source instead, because
 * reporting "no panel provider" for an application that plainly has one skips every check gated
 * on this, on a panel that may be unprotected.
 *
 * The verdict is recovered that way, not the file, which is why the parser is a constructor
 * dependency rather than a trailing argument: after the call, the parser you passed says how the
 * answer was reached.
 *
 *     $parser = new AstParser();
 *
 *     $configured = (new FilamentPanelDetector($parser))->isConfigured($basePath);
 *
 *     $parser->failures();     // empty => every provider file was read
 *     $parser->recoveries();   // non-empty => a provider was identified by regex, not by AST
 *
 * So true with a non-empty recoveries() means "configured, and one of those files is broken" --
 * worth reporting on its own, and not something the verdict alone can say.
 *
 * That visibility is partial by design and the limit is worth stating plainly: a file is only
 * parsed once it contains both 'extends' and 'PanelProvider', so a provider broken badly enough
 * to lose either string is rejected before the parser ever sees it and leaves no record.
 *
 * Lives beside PackageDetector rather than inside it because it is the only question there that
 * needs a parser. Folding it back in would put a parser dependency on a class whose other
 * fourteen methods read composer.lock and match strings.
 *
 * @see https://filamentphp.com/docs/4.x/panels/installation
 */
final class FilamentPanelDetector
{
    public function __construct(private readonly RecordingParserInterface $parser)
    {
    }

    /**
     * Whether Filament is installed, a panel provider exists, and one is registered.
     */
    public function isConfigured(string $basePath): bool
    {
        if (! PackageDetector::hasFilament($basePath)) {
            return false;
        }

        $providersBaseDir = $basePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Providers';

        if (! is_dir($providersBaseDir)) {
            return false;
        }

        // Laravel 11+ scaffolds panels into app/Providers/Filament; earlier layouts and hand
        // written providers sit directly in app/Providers.
        $searchPaths = [
            $providersBaseDir.DIRECTORY_SEPARATOR.'Filament',
            $providersBaseDir,
        ];

        $foundPanelProviders = [];

        foreach ($searchPaths as $searchPath) {
            if (! is_dir($searchPath)) {
                continue;
            }

            $files = glob($searchPath.DIRECTORY_SEPARATOR.'*.php');

            if ($files === false || $files === []) {
                continue;
            }

            foreach ($files as $file) {
                $panelProviderClass = $this->panelProviderClassName($file);

                if ($panelProviderClass !== null) {
                    $foundPanelProviders[] = $panelProviderClass;
                }
            }
        }

        if ($foundPanelProviders === []) {
            return false;
        }

        return PackageDetector::isServiceProviderRegistered($foundPanelProviders, $basePath);
    }

    /**
     * Fully qualified class name of the panel provider in $filePath, or null if it is not one.
     */
    private function panelProviderClassName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return null; // @codeCoverageIgnore
        }

        // Cheap gate before paying for a parse. Also the reason failure reporting here is
        // partial: a file rejected on this line is never handed to the parser.
        if (! str_contains($content, 'extends') || ! str_contains($content, 'PanelProvider')) {
            return null;
        }

        try {
            $ast = $this->parser->parseFile($filePath);

            /** @var array<Class_> $classes */
            $classes = $this->parser->findNodes($ast, Class_::class);

            foreach ($classes as $class) {
                if ($class->extends === null || $class->name === null) {
                    continue;
                }

                $extendsName = $class->extends->toString();

                if ($extendsName === 'PanelProvider' ||
                    $extendsName === 'Filament\\Panel\\PanelProvider' ||
                    $extendsName === '\\Filament\\Panel\\PanelProvider') {

                    // An error-recovering parser rebuilds what it can, drops the broken region
                    // and logs the file anyway, so a usable class here may have come out of a
                    // file that would not parse. That is a recovery too. A no-op otherwise.
                    $this->parser->recordRecovery($filePath);

                    return self::qualify(CodeHelper::extractNamespace($content), $class->name->name);
                }
            }

            // Nothing recorded against the file: "no panel provider here" is a real answer, and
            // the recovery below must not run over every ordinary file in app/Providers. Ask the
            // log rather than the AST -- an empty file parses successfully to no statements and
            // records nothing, so an empty AST does not mean the file went unread.
            if (! $this->parser->hasFailure($filePath)) {
                return null;
            }
        } catch (\Throwable) {
            // AstParser records rather than throws, so this is unreachable through it. The
            // parameter is an interface, though, and a consumer's own implementation may throw --
            // which lands on the same recovery as a recorded failure.
        }

        return $this->recoverPanelProviderFromSource($filePath, $content);
    }

    /**
     * Read a panel provider's class name straight out of the source, for a file the parser could
     * not turn into one.
     *
     * Best effort, and better than the alternative: reporting "no panel provider" for an
     * application that plainly has one skips every check gated on it, on a panel that may be
     * unprotected. The match is anchored to the class that extends PanelProvider rather than to
     * the first class in the file that extends anything, so a file holding both names the right
     * one. An anonymous class has no name to capture and is given up on.
     */
    private function recoverPanelProviderFromSource(string $filePath, string $content): ?string
    {
        if (preg_match('/class\s+(\w+)\s+extends\s+(?:\\\\?Filament\\\\Panel\\\\)?PanelProvider\b/', $content, $matches) !== 1) {
            return null;
        }

        // The verdict is recovered, not the file: the failure record stands, and this says the
        // class name came from a regex rather than an AST.
        $this->parser->recordRecovery($filePath);

        return self::qualify(CodeHelper::extractNamespace($content), $matches[1]);
    }

    /**
     * Join a namespace and a class name, tolerating a file that declares no namespace.
     */
    private static function qualify(?string $namespace, string $className): string
    {
        return ($namespace === null || $namespace === '')
            ? $className
            : $namespace.'\\'.$className;
    }
}
