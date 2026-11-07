<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Helper utilities for path handling.
 */
class PathHelper
{
    /**
     * Strip a base path off a file path, answering a forward-slash relative path.
     *
     * Both operands are normalised to forward slashes before comparison. That is not
     * cosmetic: AbstractFileAnalyzer::getFilesToAnalyze() joins the scan root with a
     * literal '/' while SplFileInfo::getPathname() joins with DIRECTORY_SEPARATOR, so
     * on Windows the two halves of the same path arrive spelled differently. Answering
     * forward slashes also keeps reported locations comparable with the relative globs
     * consumers write in excluded_paths ('vendor/*').
     *
     * A file that does not sit under the base path is answered unchanged, in its
     * original spelling - callers tell a hit from a miss by identity, so a miss must
     * not come back normalised.
     *
     * '.' is treated as "no base path" because it is getBasePath()'s last-resort
     * fallback, where relativising against the current directory says nothing.
     *
     * @param  string  $file  The path to relativise
     * @param  string  $basePath  The root to strip; '' and '.' mean no root
     * @return string The path relative to $basePath, or $file unchanged
     */
    public static function relativeTo(string $file, string $basePath): string
    {
        if ($basePath === '' || $basePath === '.') {
            return $file;
        }

        $normalizedFile = str_replace('\\', '/', $file);
        $normalizedBasePath = str_replace('\\', '/', rtrim($basePath, '/\\')).'/';

        if (str_starts_with($normalizedFile, $normalizedBasePath)) {
            return substr($normalizedFile, strlen($normalizedBasePath));
        }

        return $file;
    }

    /**
     * Join a segment onto a base path, answering a forward-slash-joined path.
     *
     * The inverse of relativeTo(), and it trims the same charlist, so a base path
     * one accepts is a base path the other accepts. Before this existed, core held
     * three joins with three sets of rules: "{$basePath}/{$path}" and
     * $basePath.'/.env' in AbstractFileAnalyzer, DIRECTORY_SEPARATOR in
     * buildPath(), and rtrim($basePath, '/').'/config/' in ConfigFileHelper - none
     * of which trimmed a trailing backslash off a Windows base.
     *
     * An empty base answers the segment itself, and an empty segment answers the
     * base. That pairing is what lets getFilesToAnalyze() spell "scan the base path
     * itself" as '' instead of seeding $paths with the base and joining it on twice,
     * which produced '/app//app' and silently scanned nothing (#62).
     *
     * A segment that is exactly '.' collapses, whichever separator follows it: a
     * path of '.' or './' answers the base, leading './' and '.\' segments are
     * dropped, and inner '/./' and '\.\' segments fold away. It used to be kept,
     * and that broke two things (#84). Exclude patterns are anchored relative
     * globs, so a scan root of '/app/.' relativised to './vendor/...', which
     * 'vendor/*' does not match - setPaths(['.']) scanned vendor/, storage/ and
     * tests/ in full. And reported locations, and the fingerprints built from
     * them, changed with how the path was spelled: './app' reported './app/A.php'
     * where 'app' reported 'app/A.php'. '..' is not touched - resolving it needs
     * the filesystem, not string surgery - and neither are dot-files, dot-dirs or
     * names such as 'a.b'. With an empty base there is nothing for '.' to
     * collapse onto, so a segment of nothing but '.' answers '.' rather than ''.
     *
     * Interior '//' is left alone for the same reason relativeTo() leaves it
     * alone: only trailing separators are ours to touch.
     *
     * @param  string  $basePath  The root to join onto; '' means no root
     * @param  string  $path  The segment to append; '' means the base path itself
     * @return string The joined path
     */
    public static function join(string $basePath, string $path): string
    {
        $segment = self::withoutDotSegments(ltrim($path, '/\\'));

        if ($basePath === '') {
            // No base means the path answers for itself, so its leading
            // separators stay: '/src' must not come back relative.
            $root = substr($path, 0, strspn($path, '/\\'));

            if ($root === '' && $segment === '' && $path !== '') {
                return '.';
            }

            return $root.$segment;
        }

        $base = rtrim($basePath, '/\\');

        // A base of nothing but separators trims away to nothing. Stay anchored at
        // the root rather than turning an absolute scan root into a relative one.
        if ($base === '') {
            return $segment === '' ? '/' : '/'.$segment;
        }

        return $segment === '' ? $base : $base.'/'.$segment;
    }

    /**
     * Drop every segment that is exactly '.' from a path with no leading separator.
     *
     * Leading '.' segments go with the separators after them, so '././app' and
     * '.\app' answer 'app'. An inner '.' goes with the separator before it, so
     * 'app/./Http' answers 'app/Http' and a trailing 'app/.' answers 'app'. The
     * lookahead is what keeps '..', '.env' and '.github' intact: a '.' only
     * counts as a segment when a separator or the end of the path follows it.
     */
    private static function withoutDotSegments(string $path): string
    {
        $path = (string) preg_replace('#^(?:\.(?:[/\\\\]+|$))+#', '', $path);

        return (string) preg_replace('#[/\\\\]\.(?=[/\\\\]|$)#', '', $path);
    }
}
