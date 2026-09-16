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
}
