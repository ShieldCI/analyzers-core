<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ShieldCI\AnalyzersCore\Support\TraceHelper;
use Throwable;

class TraceHelperTest extends TestCase
{
    // =========================================================================
    // Argument and object stripping - ShieldCI/analyzers-core#64
    //
    // getTraceAsString() renders every frame's arguments, truncating strings at
    // 15 characters, so a credential shorter than that appears in full. The
    // array form carries the same values under 'args', and additionally an
    // 'object' key holding the live receiver, which getTraceAsString() never
    // rendered at all. frames() names the four keys it keeps, so both are absent
    // by construction rather than by removal - an allowlist, not a denylist.
    //
    // Every test here forces zend.exception_ignore_args off. It is Off by
    // default, but a host that copied php.ini-production has it On, and under
    // that setting these tests would pass while proving nothing.
    // =========================================================================

    public function test_frames_omit_argument_values(): void
    {
        $frames = $this->framesFrom(
            static fn () => traceHelperConnect('mysql:host=db.internal;dbname=prod', 'root', 'hunter2pass')
        );

        $this->assertNotSame([], $frames);
        $this->assertStringNotContainsString('hunter2pass', (string) json_encode($frames));

        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
        }
    }

    public function test_frames_omit_the_receiving_object(): void
    {
        $probe = new TraceHelperProbe('hunter2pass');

        $frames = $this->framesFrom(static fn () => $probe->connect('mysql:host=db.internal'));

        $this->assertNotSame([], $frames);
        $this->assertStringNotContainsString('hunter2pass', (string) json_encode($frames));

        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('object', $frame);
        }
    }

    public function test_frames_keep_file_line_function_and_class(): void
    {
        $probe = new TraceHelperProbe('hunter2pass');

        $frames = $this->framesFrom(static fn () => $probe->connect('mysql:host=db.internal'));

        $this->assertSame(
            ['file', 'line', 'function', 'class'],
            array_keys($frames[0])
        );
        $this->assertSame(__FILE__, $frames[0]['file']);
        $this->assertIsInt($frames[0]['line']);
        $this->assertSame('connect', $frames[0]['function']);
        $this->assertSame(TraceHelperProbe::class, $frames[0]['class']);
    }

    public function test_frames_report_a_null_file_and_line_for_an_internal_frame(): void
    {
        // A closure invoked by array_map has no userland call site, so PHP omits
        // file and line from that frame entirely. Reached through a real internal
        // call rather than a hand-built frame, so it stays true of real traces.
        $frames = $this->framesFrom(static fn () => traceHelperViaArrayMap());

        $internal = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => $frame['file'] === null
        ));

        $this->assertNotSame([], $internal, 'Expected at least one frame with no file.');
        $this->assertNull($internal[0]['line']);
        $this->assertNotSame('', $internal[0]['function']);
    }

    // =========================================================================
    // Frame cap
    //
    // getTraceAsString() bounded nothing; a 61-frame trace renders to ~1.9 KB of
    // payload. The cap is what makes the uploaded value bounded.
    // =========================================================================

    public function test_frames_cap_the_frame_count_by_default(): void
    {
        $frames = $this->framesFrom(static fn () => traceHelperRecurse(60));

        $this->assertCount(TraceHelper::MAX_FRAMES, $frames);
    }

    public function test_frames_honour_an_explicit_cap(): void
    {
        $frames = $this->framesFrom(static fn () => traceHelperRecurse(60), '', 3);

        $this->assertCount(3, $frames);
    }

    public function test_frames_treat_a_negative_cap_as_zero(): void
    {
        // array_slice() reads a negative length as "stop this many from the end",
        // which would silently return almost the whole trace.
        $frames = $this->framesFrom(static fn () => traceHelperRecurse(60), '', -1);

        $this->assertSame([], $frames);
    }

    // =========================================================================
    // Path relativisation
    //
    // Absolute frame paths name the user's machine (/home/forge/..., /Users/...).
    // Issue locations are already reported base-relative; frames now match.
    // =========================================================================

    public function test_frames_relativise_file_paths_against_the_base_path(): void
    {
        $frames = $this->framesFrom(
            static fn () => traceHelperConnect('dsn', 'root', 'hunter2pass'),
            dirname(__DIR__, 3)
        );

        $this->assertSame('tests/Unit/Support/TraceHelperTest.php', $frames[0]['file']);
    }

    public function test_frames_leave_a_file_outside_the_base_path_unchanged(): void
    {
        $frames = $this->framesFrom(
            static fn () => traceHelperConnect('dsn', 'root', 'hunter2pass'),
            '/some/unrelated/root'
        );

        $this->assertSame(__FILE__, $frames[0]['file']);
    }

    public function test_frames_keep_absolute_paths_when_no_base_path_is_given(): void
    {
        $frames = $this->framesFrom(static fn () => traceHelperConnect('dsn', 'root', 'hunter2pass'));

        $this->assertSame(__FILE__, $frames[0]['file']);
    }

    public function test_frames_honour_a_zero_cap(): void
    {
        // There is no "throwable with no trace" to test against from inside a
        // running suite - anything constructed here carries the PHPUnit stack -
        // so a zero cap is how the empty result is reached on purpose.
        $frames = $this->framesFrom(static fn () => traceHelperRecurse(60), '', 0);

        $this->assertSame([], $frames);
    }

    /**
     * Run $thrower with exception arguments forced on, and summarise what it threw.
     *
     * @param  callable():mixed  $thrower
     * @return list<array{file: string|null, line: int|null, function: string, class: string|null}>
     */
    private function framesFrom(callable $thrower, string $basePath = '', ?int $maxFrames = null): array
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $thrower();
        } catch (Throwable $e) {
            return $maxFrames === null
                ? TraceHelper::frames($e, $basePath)
                : TraceHelper::frames($e, $basePath, $maxFrames);
        } finally {
            if (is_string($original)) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }

        $this->fail('Expected the callable to throw.');
    }
}

/**
 * A plain function frame whose arguments carry a credential.
 */
function traceHelperConnect(string $dsn, string $user, string $password): void
{
    throw new RuntimeException('connection refused');
}

/**
 * Throws from inside a closure invoked by an internal function, which produces a
 * frame with no file and no line.
 */
function traceHelperViaArrayMap(): void
{
    array_map(
        static function (int $n): int {
            throw new RuntimeException('thrown inside a closure');
        },
        [1]
    );
}

/**
 * Builds a trace deep enough to exceed the frame cap.
 */
function traceHelperRecurse(int $depth): void
{
    if ($depth <= 0) {
        throw new RuntimeException('bottom of the stack');
    }

    traceHelperRecurse($depth - 1);
}

/**
 * A method frame, so the trace carries 'class', 'type' and the live 'object'.
 */
class TraceHelperProbe
{
    public function __construct(public readonly string $password)
    {
    }

    public function connect(string $dsn): void
    {
        throw new RuntimeException('connection refused');
    }
}
