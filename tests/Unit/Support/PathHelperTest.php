<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\PathHelper;

class PathHelperTest extends TestCase
{
    // =========================================================================
    // relativeTo() - ShieldCI/analyzers-core#58
    //
    // These pin the behaviour AbstractAnalyzer::getRelativePath() had before the
    // extraction, so the move stays a refactor. The cases below the second
    // banner are the ones neither abstract covered.
    // =========================================================================

    public function test_returns_the_file_when_the_base_path_is_empty(): void
    {
        $this->assertSame(
            '/var/www/project/src/File.php',
            PathHelper::relativeTo('/var/www/project/src/File.php', '')
        );
    }

    public function test_returns_the_file_when_the_base_path_is_dot(): void
    {
        $this->assertSame(
            '/var/www/project/src/File.php',
            PathHelper::relativeTo('/var/www/project/src/File.php', '.')
        );
    }

    public function test_strips_the_base_path(): void
    {
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('/var/www/project/src/File.php', '/var/www/project')
        );
    }

    public function test_handles_a_trailing_slash_in_the_base_path(): void
    {
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('/var/www/project/src/File.php', '/var/www/project/')
        );
    }

    public function test_handles_windows_backslashes(): void
    {
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('C:\\Projects\\myapp\\src\\File.php', 'C:\\Projects\\myapp')
        );
    }

    public function test_handles_a_forward_slash_base_against_a_backslash_file(): void
    {
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('C:\\Projects\\myapp\\src\\File.php', 'C:/Projects/myapp')
        );
    }

    public function test_returns_the_original_when_the_file_is_not_under_the_base_path(): void
    {
        $this->assertSame(
            '/var/www/project2/src/File.php',
            PathHelper::relativeTo('/var/www/project2/src/File.php', '/var/www/project1')
        );
    }

    public function test_handles_nested_directories(): void
    {
        $this->assertSame(
            'app/Http/Controllers/UserController.php',
            PathHelper::relativeTo('/var/www/project/app/Http/Controllers/UserController.php', '/var/www/project')
        );
    }

    public function test_normalises_slashes_in_the_answer(): void
    {
        $result = PathHelper::relativeTo('/var/www/project/src\\File.php', '/var/www/project');

        $this->assertSame('src/File.php', $result);
        $this->assertStringNotContainsString('\\', $result);
    }

    public function test_handles_a_file_in_the_root_of_the_base_path(): void
    {
        $this->assertSame(
            'README.md',
            PathHelper::relativeTo('/var/www/project/README.md', '/var/www/project')
        );
    }

    public function test_is_case_sensitive(): void
    {
        $this->assertSame(
            '/var/www/project/src/File.php',
            PathHelper::relativeTo('/var/www/project/src/File.php', '/var/www/Project')
        );
    }

    // =========================================================================
    // Cases neither abstract covered before the extraction.
    // =========================================================================

    public function test_handles_a_trailing_backslash_in_the_base_path(): void
    {
        // base_path() on Windows can hand back a trailing separator, and
        // setBasePath() only rtrims '/', so the backslash survives to here.
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('C:\\Projects\\myapp\\src\\File.php', 'C:\\Projects\\myapp\\')
        );
    }

    public function test_handles_a_backslash_base_against_a_forward_slash_file(): void
    {
        $this->assertSame(
            'src/File.php',
            PathHelper::relativeTo('C:/Projects/myapp/src/File.php', 'C:\\Projects\\myapp')
        );
    }

    public function test_handles_the_mixed_separators_spl_file_info_produces(): void
    {
        // getFilesToAnalyze() joins the scan root with a literal '/', while
        // SplFileInfo::getPathname() joins with DIRECTORY_SEPARATOR - so a real
        // Windows run produces exactly this shape.
        $this->assertSame(
            'vendor/package/File.php',
            PathHelper::relativeTo('C:/Projects/myapp\\vendor\\package\\File.php', 'C:\\Projects\\myapp')
        );
    }

    public function test_does_not_match_a_file_equal_to_the_base_path(): void
    {
        // The appended separator is what makes the base path itself a miss.
        $this->assertSame(
            '/var/www/project',
            PathHelper::relativeTo('/var/www/project', '/var/www/project')
        );
    }

    public function test_does_not_match_a_sibling_directory_sharing_a_prefix(): void
    {
        // Without the appended separator this would answer '2/src/File.php'.
        $this->assertSame(
            '/var/www/project2/src/File.php',
            PathHelper::relativeTo('/var/www/project2/src/File.php', '/var/www/project')
        );
    }

    public function test_keeps_the_original_spelling_on_a_miss(): void
    {
        // Callers tell a hit from a miss by identity, so a miss must not come
        // back normalised.
        $this->assertSame(
            'D:\\other\\src\\File.php',
            PathHelper::relativeTo('D:\\other\\src\\File.php', 'C:\\Projects\\myapp')
        );
    }

    public function test_treats_zero_as_a_real_base_path(): void
    {
        // AbstractFileAnalyzer used empty(), which read '0' as "no base path".
        // The surviving implementation compares against '' exactly.
        $this->assertSame('src/File.php', PathHelper::relativeTo('0/src/File.php', '0'));
    }

    // =========================================================================
    // join() - ShieldCI/analyzers-core#62
    //
    // The inverse of relativeTo(), sharing its trim charlist so a base path one
    // accepts is a base path the other accepts. It replaces the literal-'/'
    // joins in AbstractFileAnalyzer, AbstractAnalyzer::buildPath() and
    // ConfigFileHelper::getConfigPath().
    // =========================================================================

    public function test_join_answers_the_path_unchanged_for_an_empty_base(): void
    {
        $this->assertSame('src/File.php', PathHelper::join('', 'src/File.php'));
    }

    public function test_join_answers_the_base_for_an_empty_path(): void
    {
        // '' is how callers spell "the base path itself" - getFilesToAnalyze()
        // defaults to it when setPaths() was never called.
        $this->assertSame('/var/www/project', PathHelper::join('/var/www/project', ''));
    }

    public function test_join_trims_a_trailing_forward_slash_off_the_base(): void
    {
        $this->assertSame('/var/www/project/src', PathHelper::join('/var/www/project/', 'src'));
    }

    public function test_join_trims_a_trailing_backslash_off_the_base(): void
    {
        // setBasePath() used to rtrim '/' only, so a Windows base handed in as
        // 'C:\app\' reached the join with its separator still attached.
        $this->assertSame('C:\\Projects\\myapp/src', PathHelper::join('C:\\Projects\\myapp\\', 'src'));
    }

    public function test_join_does_not_double_a_separator_on_a_rooted_segment(): void
    {
        $this->assertSame('/var/www/project/src', PathHelper::join('/var/www/project', '/src'));
    }

    public function test_join_strips_a_leading_backslash_off_the_segment(): void
    {
        $this->assertSame('/var/www/project/src', PathHelper::join('/var/www/project', '\\src'));
    }

    public function test_join_keeps_a_base_of_only_separators_anchored_at_the_root(): void
    {
        // rtrim() takes a base of '/' down to nothing; answering 'src' would
        // turn an absolute scan root into a relative one.
        $this->assertSame('/src', PathHelper::join('/', 'src'));
    }

    public function test_join_answers_the_root_for_a_separator_base_and_an_empty_path(): void
    {
        $this->assertSame('/', PathHelper::join('/', ''));
    }

    public function test_join_treats_zero_as_a_real_base_path(): void
    {
        // Same empty()-on-a-path-string trap relativeTo() avoids: '0' is falsy.
        $this->assertSame('0/src', PathHelper::join('0', 'src'));
    }

    public function test_join_leaves_a_dot_segment_alone(): void
    {
        // setPaths(['.']) is the dominant idiom in the downstream suites, and
        // consumers strip the resulting './' prefix off reported locations.
        // Collapsing it here would change behaviour in shieldci/laravel-pro.
        $this->assertSame('/var/www/project/.', PathHelper::join('/var/www/project', '.'));
    }

    public function test_join_and_relative_to_are_inverses(): void
    {
        $joined = PathHelper::join('/var/www/project', 'src/File.php');

        $this->assertSame('src/File.php', PathHelper::relativeTo($joined, '/var/www/project'));
    }
}
