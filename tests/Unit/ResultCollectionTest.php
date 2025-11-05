<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Results\{AnalysisResult, ResultCollection};

class ResultCollectionTest extends TestCase
{
    public function testCanAddResults(): void
    {
        $collection = new ResultCollection();
        $result = AnalysisResult::passed('test-analyzer', 'Success');

        $collection->add($result);

        $this->assertCount(1, $collection);
    }

    public function testCanFilterByStatus(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::failed('analyzer-2', 'Failed'),
            AnalysisResult::passed('analyzer-3', 'Passed'),
        ]);

        $passed = $collection->passed();
        $failed = $collection->failed();

        $this->assertCount(2, $passed);
        $this->assertCount(1, $failed);
    }

    public function testCalculatesScore(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::passed('analyzer-2', 'Passed'),
            AnalysisResult::failed('analyzer-3', 'Failed'),
            AnalysisResult::failed('analyzer-4', 'Failed'),
        ]);

        $score = $collection->score();

        $this->assertEquals(50.0, $score);
    }

    public function testCalculatesTotalExecutionTime(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed', 1.0),
            AnalysisResult::passed('analyzer-2', 'Passed', 2.5),
        ]);

        $totalTime = $collection->totalExecutionTime();

        $this->assertEquals(3.5, $totalTime);
    }

    public function testIsSuccessful(): void
    {
        $successCollection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::skipped('analyzer-2', 'Skipped'),
        ]);

        $failedCollection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::failed('analyzer-2', 'Failed'),
        ]);

        $this->assertTrue($successCollection->isSuccess());
        $this->assertFalse($failedCollection->isSuccess());
    }

    public function testCanIterate(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::passed('analyzer-2', 'Passed'),
        ]);

        $count = 0;
        foreach ($collection as $result) {
            $count++;
            $this->assertInstanceOf(AnalysisResult::class, $result);
        }

        $this->assertEquals(2, $count);
    }

    public function testAllReturnsAllResults(): void
    {
        $results = [
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::failed('analyzer-2', 'Failed'),
        ];
        $collection = new ResultCollection($results);

        $all = $collection->all();

        $this->assertCount(2, $all);
        $this->assertEquals($results, $all);
    }

    public function testByStatusFiltersCorrectly(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::failed('analyzer-2', 'Failed'),
            AnalysisResult::warning('analyzer-3', 'Warning'),
            AnalysisResult::passed('analyzer-4', 'Passed'),
        ]);

        $passed = $collection->byStatus(\ShieldCI\AnalyzersCore\Enums\Status::Passed);
        $failed = $collection->byStatus(\ShieldCI\AnalyzersCore\Enums\Status::Failed);
        $warnings = $collection->byStatus(\ShieldCI\AnalyzersCore\Enums\Status::Warning);

        $this->assertCount(2, $passed);
        $this->assertCount(1, $failed);
        $this->assertCount(1, $warnings);
    }

    public function testWarningsReturnsWarningResults(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::warning('analyzer-2', 'Warning'),
            AnalysisResult::warning('analyzer-3', 'Warning'),
        ]);

        $warnings = $collection->warnings();

        $this->assertCount(2, $warnings);
    }

    public function testSkippedReturnsSkippedResults(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::skipped('analyzer-2', 'Skipped'),
            AnalysisResult::skipped('analyzer-3', 'Skipped'),
        ]);

        $skipped = $collection->skipped();

        $this->assertCount(2, $skipped);
    }

    public function testErrorsReturnsErrorResults(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::error('analyzer-2', 'Error occurred'),
            AnalysisResult::error('analyzer-3', 'Another error'),
        ]);

        $errors = $collection->errors();

        $this->assertCount(2, $errors);
    }

    public function testScoreReturns100ForEmptyCollection(): void
    {
        $collection = new ResultCollection();

        $score = $collection->score();

        $this->assertEquals(100.0, $score);
    }

    public function testScoreIncludesSkippedInSuccess(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::skipped('analyzer-2', 'Skipped'),
            AnalysisResult::failed('analyzer-3', 'Failed'),
            AnalysisResult::failed('analyzer-4', 'Failed'),
        ]);

        $score = $collection->score();

        // (1 passed + 1 skipped) / 4 total = 50%
        $this->assertEquals(50.0, $score);
    }

    public function testTotalIssuesCountsAllIssues(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::failed('analyzer-1', 'Failed', [
                new \ShieldCI\AnalyzersCore\ValueObjects\Issue(
                    'Issue 1',
                    new \ShieldCI\AnalyzersCore\ValueObjects\Location('/test.php', 1),
                    \ShieldCI\AnalyzersCore\Enums\Severity::High,
                    'Fix 1'
                ),
                new \ShieldCI\AnalyzersCore\ValueObjects\Issue(
                    'Issue 2',
                    new \ShieldCI\AnalyzersCore\ValueObjects\Location('/test.php', 2),
                    \ShieldCI\AnalyzersCore\Enums\Severity::Medium,
                    'Fix 2'
                ),
            ]),
            AnalysisResult::failed('analyzer-2', 'Failed', [
                new \ShieldCI\AnalyzersCore\ValueObjects\Issue(
                    'Issue 3',
                    new \ShieldCI\AnalyzersCore\ValueObjects\Location('/test.php', 3),
                    \ShieldCI\AnalyzersCore\Enums\Severity::Low,
                    'Fix 3'
                ),
            ]),
        ]);

        $totalIssues = $collection->totalIssues();

        $this->assertEquals(3, $totalIssues);
    }

    public function testTotalIssuesReturnsZeroForNoIssues(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::passed('analyzer-2', 'Passed'),
        ]);

        $totalIssues = $collection->totalIssues();

        $this->assertEquals(0, $totalIssues);
    }

    public function testIsSuccessReturnsTrueForEmptyCollection(): void
    {
        $collection = new ResultCollection();

        $this->assertTrue($collection->isSuccess());
    }

    public function testIsSuccessReturnsFalseForWarnings(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::warning('analyzer-2', 'Warning'),
        ]);

        $this->assertFalse($collection->isSuccess());
    }

    public function testIsSuccessReturnsFalseForErrors(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::error('analyzer-2', 'Error occurred'),
        ]);

        $this->assertFalse($collection->isSuccess());
    }

    public function testToArrayConvertsAllResults(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::failed('analyzer-2', 'Failed'),
        ]);

        $array = $collection->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertArrayHasKey('analyzer_id', $array[0]);
        $this->assertArrayHasKey('status', $array[0]);
    }

    public function testCountReturnsCorrectCount(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
            AnalysisResult::passed('analyzer-2', 'Passed'),
            AnalysisResult::passed('analyzer-3', 'Passed'),
        ]);

        $this->assertEquals(3, $collection->count());
        $this->assertCount(3, $collection);
    }

    public function testCountReturnsZeroForEmptyCollection(): void
    {
        $collection = new ResultCollection();

        $this->assertEquals(0, $collection->count());
        $this->assertCount(0, $collection);
    }

    public function testAddReturnsFluentInterface(): void
    {
        $collection = new ResultCollection();
        $result = AnalysisResult::passed('analyzer-1', 'Passed');

        $returned = $collection->add($result);

        $this->assertSame($collection, $returned);
    }

    public function testGetIteratorReturnsTraversable(): void
    {
        $collection = new ResultCollection([
            AnalysisResult::passed('analyzer-1', 'Passed'),
        ]);

        $iterator = $collection->getIterator();

        $this->assertInstanceOf(\Traversable::class, $iterator);
        $this->assertInstanceOf(\ArrayIterator::class, $iterator);
    }
}
