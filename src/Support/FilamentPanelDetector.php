<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

use PhpParser\Node\Stmt\Class_;
use ShieldCI\AnalyzersCore\Contracts\RecordingParserInterface;

/**
 * Whether an application has Filament installed, a panel provider written anywhere under
 * app/Providers, and it registered.
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
 *     $parser->recoveries();   // non-empty => a provider came out of a file that would not parse
 *
 * So true with a non-empty recoveries() means "configured, and one of those files is broken" --
 * worth reporting on its own, and not something the verdict alone can say.
 *
 * That visibility is partial by design and the limits are worth stating plainly. A file is only
 * parsed once it contains both 'extends' and 'PanelProvider', so a provider broken badly enough
 * to lose either string is rejected before the parser ever sees it and leaves no record. And a
 * consumer parser that throws rather than records leaves none either: nothing is on its failure
 * log, recordRecovery() is defined as a no-op without one, and the verdict that comes back from
 * the regex is indistinguishable from one an AST produced. Closing that second gap means a
 * recordFailure() on RecordingParserInterface, which is a wider change than this class.
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

        $foundPanelProviders = [];

        foreach (self::phpFilesUnder($providersBaseDir, $basePath) as $file) {
            $panelProviderClass = $this->panelProviderClassName($file);

            if ($panelProviderClass !== null) {
                $foundPanelProviders[] = $panelProviderClass;
            }
        }

        if ($foundPanelProviders === []) {
            return false;
        }

        return PackageDetector::isServiceProviderRegistered($foundPanelProviders, $basePath);
    }

    /**
     * Every .php file anywhere under $directory, sorted, for the project at $basePath.
     *
     * make:filament-panel writes to app/Providers/Filament, and older or hand-written providers
     * sit in app/Providers itself, but nothing keeps an application from grouping its panels
     * deeper -- and a registered provider missed there reads as "Filament not configured". Depth
     * is cheap: panelProviderClassName() rejects most files on a string check before parsing.
     *
     * Links are followed, file and directory alike: a provider kept elsewhere and linked in is
     * still registered. Each directory is entered once by its real path, so a link back up the
     * tree ends the walk instead of extending it, and each file is kept once by its real path,
     * under the first spelling a sorted walk reaches.
     *
     * Directory links are followed wherever they point -- a monorepo links shared panels in from
     * outside the application -- with two exceptions, which mayEnter() states. A linked file is
     * read wherever it points, since one file costs one read. A directory that cannot be listed
     * is skipped, under a handler that swallows the warning so a host handler that throws on every
     * warning cannot turn one locked directory into no verdict at all.
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory, string $basePath): array
    {
        $project = self::comparable(realpath($basePath) ?: $basePath);
        $app = self::comparable(realpath($basePath.DIRECTORY_SEPARATOR.'app') ?: $basePath.DIRECTORY_SEPARATOR.'app');
        $start = self::comparable(realpath($directory) ?: $directory);
        /** @var array<string, string> $files */
        $files = [];
        $visited = [];

        set_error_handler(static fn (): bool => true);

        try {
            self::collectPhpFiles($directory, $project, $app, $start, $visited, $files);
        } finally {
            restore_error_handler();
        }

        $paths = array_values($files);
        sort($paths);

        return $paths;
    }

    /**
     * @param  string  $project  The project's real path, as comparable() spells it.
     * @param  string  $app  The real path of the project's app/, as comparable() spells it.
     * @param  string  $start  The real path the walk started from, as comparable() spells it.
     * @param  array<string, true>  $visited  Real paths of the directories already entered.
     * @param  array<string, string>  $files  Real path => the spelling it was first reached by.
     */
    private static function collectPhpFiles(string $directory, string $project, string $app, string $start, array &$visited, array &$files): void
    {
        $realDirectory = realpath($directory);

        if ($realDirectory === false || isset($visited[$realDirectory]) || ! self::mayEnter($directory, $realDirectory, $project, $app, $start)) {
            return;
        }

        $visited[$realDirectory] = true;

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path)) {
                self::collectPhpFiles($path, $project, $app, $start, $visited, $files);

                continue;
            }

            $realFile = realpath($path);

            if (str_ends_with($entry, '.php') && is_file($path) && $realFile !== false && ! isset($files[$realFile])) {
                $files[$realFile] = $path;
            }
        }
    }

    /**
     * Whether the walk may enter $directory, really at $realDirectory.
     *
     * Not if it is the project, its app/, app/Providers' parent chain or anything above them: a
     * link to the project root, to app/ or to / brings the whole project or the whole machine
     * into range. app/ is named on its own because once app/Providers is a link out, app/ is no
     * longer above the start.
     * The directory the walk started from is the one exception, unless it is itself the project
     * or above it, in which case there is nothing to walk.
     *
     * Not if it sits in vendor/ or node_modules/ either: dependencies are someone else's panels
     * and a great many files. The leading segments a directory shares with the project's own
     * path do not count, so a project checked out inside someone's vendor/ is still walked, and
     * so are panels kept beside it there; whatever the directory adds below that shared prefix
     * does count. The name the directory was reached by counts too, so a link called vendor is
     * refused wherever it points.
     *
     * Both comparisons ignore case, since realpath() keeps whatever case it was given on a
     * case-insensitive filesystem. On a case-sensitive one that refuses a directory differing
     * from the project only in case, which is a cost worth paying.
     */
    private static function mayEnter(string $directory, string $realDirectory, string $project, string $app, string $start): bool
    {
        $real = self::comparable($realDirectory);

        if (self::isSelfOrAncestor($real, $project) || self::isSelfOrAncestor($real, $app)
            || ($real !== $start && self::isSelfOrAncestor($real, $start))) {
            return false;
        }

        $segments = explode(DIRECTORY_SEPARATOR, $real);
        $projectSegments = explode(DIRECTORY_SEPARATOR, $project);
        $shared = 0;

        while (isset($segments[$shared], $projectSegments[$shared]) && $segments[$shared] === $projectSegments[$shared]) {
            $shared++;
        }

        $segments = array_slice($segments, $shared);
        $segments[] = strtolower(basename($directory));

        return array_intersect($segments, ['vendor', 'node_modules']) === [];
    }

    /**
     * Whether $candidate is $path or a directory above it. Compared by whole segments, so
     * '/app' is not above '/app-other' merely because it is a prefix of it.
     */
    private static function isSelfOrAncestor(string $candidate, string $path): bool
    {
        return $candidate === $path || str_starts_with($path, $candidate.DIRECTORY_SEPARATOR);
    }

    /**
     * $path lower-cased and without a trailing separator, so '/' becomes '' and still prefixes
     * every absolute path once a separator is appended.
     */
    private static function comparable(string $path): string
    {
        return strtolower(rtrim($path, DIRECTORY_SEPARATOR));
    }

    /**
     * Fully qualified class name of the panel provider in $filePath, or null if it is not one.
     */
    private function panelProviderClassName(string $filePath): ?string
    {
        // A file the walk can list but not read is no panel provider anyone can show, and
        // reading it must not warn: a host handler that throws would cost the whole verdict.
        $content = FileParser::readFile($filePath);

        if ($content === null) {
            return null;
        }

        // Cheap gate before paying for a parse. Also the reason failure reporting here is
        // partial: a file rejected on this line is never handed to the parser.
        if (! str_contains($content, 'extends') || ! str_contains($content, 'PanelProvider')) {
            return null;
        }

        $className = null;

        try {
            $ast = $this->parser->parseFile($filePath);

            /** @var array<Class_> $classes */
            $classes = $this->parser->findNodes($ast, Class_::class);

            $className = self::panelProviderClassIn($classes);

            // "No panel provider here" is a real answer, and the recovery below must not
            // second-guess it over every ordinary file in app/Providers. Ask the failure log
            // rather than the AST: a file of nothing but comments parses successfully to no
            // statements, so reading an empty AST as "could not read it" is #76 pointing the
            // other way. Asked only once the AST has come up empty, so a parser that answers
            // this one badly cannot cost a class name already in hand.
            if ($className === null && ! $this->parser->hasFailure($filePath)) {
                return null;
            }
        } catch (\Throwable) {
            // AstParser records rather than throws, so this is unreachable through it. The
            // parameter is an interface, though, and a consumer's own implementation may throw
            // -- from the parse or from the failure log -- and this method still owes its caller
            // a verdict either way. Both land on the recovery below, which is the honest reading
            // of both: the file could not be turned into an answer here.
            $className = null;
        }

        if ($className !== null) {
            // An error-recovering parser rebuilds what it can, drops the broken region and logs
            // the file anyway, so a usable class here may have come out of a file that would not
            // parse. That is a recovery too, and a no-op when it is not.
            $this->recordRecovery($filePath);

            return self::qualify($content, $className);
        }

        return $this->recoverPanelProviderFromSource($filePath, $content);
    }

    /**
     * Name of the first class in $classes that extends PanelProvider, or null for none.
     *
     * @param  array<Class_>  $classes
     */
    private static function panelProviderClassIn(array $classes): ?string
    {
        foreach ($classes as $class) {
            if ($class->extends === null || $class->name === null) {
                continue;
            }

            $extendsName = $class->extends->toString();

            if ($extendsName === 'PanelProvider' ||
                $extendsName === 'Filament\\Panel\\PanelProvider' ||
                $extendsName === '\\Filament\\Panel\\PanelProvider') {
                return $class->name->name;
            }
        }

        return null;
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
        // Comments and string literals out first, or this is not a syntactic check at all. It is
        // the one match in the package with no syntax tree behind it -- the file is by definition
        // one nobody can parse -- so a commented-out provider or a generator's template reads
        // exactly like a live declaration, and the verdict it produces names a class that does
        // not exist. A lexer does not need the file to parse, which is why this is reachable at
        // all: token_get_all() without TOKEN_PARSE tokenises broken source happily.
        $source = FileParser::stripCommentsAndStrings($content);

        if (preg_match('/class\s+(\w+)\s+extends\s+(?:\\\\?Filament\\\\Panel\\\\)?PanelProvider\b/', $source, $matches) !== 1) {
            return null;
        }

        // The verdict is recovered, not the file: any failure record stands, and this says the
        // class name came from a regex rather than an AST.
        $this->recordRecovery($filePath);

        // The lexer reads the declaration from the original source, which need not parse
        return self::qualify($content, $matches[1]);
    }

    /**
     * Note that the verdict for $filePath was recovered from a file that would not parse.
     *
     * A no-op unless the parser has a failure on file for it. This runs after the answer is
     * already known, so a consumer implementation that throws here costs the log entry and not
     * the verdict -- the caller asked whether Filament is configured, not to handle the parser's
     * bookkeeping.
     */
    private function recordRecovery(string $filePath): void
    {
        try {
            $this->parser->recordRecovery($filePath);
        } catch (\Throwable) {
            // The verdict stands either way.
        }
    }

    /**
     * Qualify $className with the namespace that encloses its declaration in $content.
     *
     * Read by the lexer, so it works on a file that does not parse, takes the braced form and
     * the namespace around this class rather than the file's first one, and ignores a
     * declaration-shaped comment. Falls back to the file's first namespace when the lexer finds
     * no such class, and to the bare name when there is no namespace either.
     */
    private static function qualify(string $content, string $className): string
    {
        $declared = array_values(array_filter(
            FileParser::extractClassLikesFromSource($content),
            fn (array $classLike): bool => $classLike['kind'] === 'class'
                && str_ends_with(strtolower('\\'.$classLike['name']), strtolower('\\'.$className))
        ));

        return $declared[0]['name'] ?? ltrim(FileParser::extractNamespaceFromSource($content).'\\'.$className, '\\');
    }
}
