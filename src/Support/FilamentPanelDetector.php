<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\Node\Stmt\Class_;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;

/**
 * Whether an application has Filament installed, a panel provider written, and it registered.
 *
 * All three have to hold, and false says only "not all three". A provider that would not parse
 * lands on the same false as an application that never had Filament, which is why the parser is
 * a constructor dependency rather than a trailing argument: after the call, the parser you
 * passed distinguishes them.
 *
 *     $parser = new AstParser();
 *
 *     if (! (new FilamentPanelDetector($parser))->isConfigured($basePath)) {
 *         $broken = $parser->failures();   // empty => genuinely not configured
 *     }
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

                    return self::qualify(CodeHelper::extractNamespace($content), $class->name->name);
                }
            }

            return null;
        } catch (\Throwable) {
            // AstParser records rather than throws, so this is unreachable through it. The
            // parameter is an interface, though, and a consumer's own implementation may throw
            // -- at which point a best-effort regex beats reporting "no panel provider" for an
            // application that plainly has one.
            if (preg_match('/extends\s+(?:\\\\?Filament\\\\Panel\\\\)?PanelProvider/', $content) !== 1) {
                return null;
            }

            if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches) !== 1) {
                return null;
            }

            return self::qualify(CodeHelper::extractNamespace($content), $matches[1]);
        }
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
