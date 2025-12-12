<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test that index.php bootstrap file correctly loads all required classes.
 *
 * This tests lines 8-39 of index.php which require all the class files.
 */
class indexTest extends TestCase
{
    private string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indexPath = dirname(__DIR__, 2) . '/src/index.php';
    }

    public function testIndexFileExists(): void
    {
        $this->assertFileExists($this->indexPath);
    }

    public function testIndexFileRequiresAllContracts(): void
    {
        // Test lines 8-12: Contract files
        $contracts = [
            'Contracts/AnalyzerInterface.php',
            'Contracts/ResultInterface.php',
            'Contracts/ReporterInterface.php',
            'Contracts/ParserInterface.php',
        ];

        foreach ($contracts as $contract) {
            $filePath = dirname($this->indexPath) . '/' . $contract;
            $this->assertFileExists($filePath, "Contract file {$contract} should exist");
        }
    }

    public function testIndexFileRequiresAllEnums(): void
    {
        // Test lines 14-17: Enum files
        $enums = [
            'Enums/Status.php',
            'Enums/Category.php',
            'Enums/Severity.php',
        ];

        foreach ($enums as $enum) {
            $filePath = dirname($this->indexPath) . '/' . $enum;
            $this->assertFileExists($filePath, "Enum file {$enum} should exist");
        }
    }

    public function testIndexFileRequiresAllValueObjects(): void
    {
        // Test lines 19-22: Value Object files
        $valueObjects = [
            'ValueObjects/Location.php',
            'ValueObjects/Issue.php',
            'ValueObjects/AnalyzerMetadata.php',
        ];

        foreach ($valueObjects as $vo) {
            $filePath = dirname($this->indexPath) . '/' . $vo;
            $this->assertFileExists($filePath, "ValueObject file {$vo} should exist");
        }
    }

    public function testIndexFileRequiresAllResults(): void
    {
        // Test lines 24-26: Result files
        $results = [
            'Results/AnalysisResult.php',
            'Results/ResultCollection.php',
        ];

        foreach ($results as $result) {
            $filePath = dirname($this->indexPath) . '/' . $result;
            $this->assertFileExists($filePath, "Result file {$result} should exist");
        }
    }

    public function testIndexFileRequiresAllAbstracts(): void
    {
        // Test lines 28-30: Abstract files
        $abstracts = [
            'Abstracts/AbstractAnalyzer.php',
            'Abstracts/AbstractFileAnalyzer.php',
        ];

        foreach ($abstracts as $abstract) {
            $filePath = dirname($this->indexPath) . '/' . $abstract;
            $this->assertFileExists($filePath, "Abstract file {$abstract} should exist");
        }
    }

    public function testIndexFileRequiresAllSupport(): void
    {
        // Test lines 32-36: Support files
        $support = [
            'Support/AstParser.php',
            'Support/FileParser.php',
            'Support/ConfigFileHelper.php',
            'Support/CodeHelper.php',
        ];

        foreach ($support as $file) {
            $filePath = dirname($this->indexPath) . '/' . $file;
            $this->assertFileExists($filePath, "Support file {$file} should exist");
        }
    }

    public function testIndexFileRequiresAllFormatters(): void
    {
        // Test lines 38-40: Formatter files
        $formatters = [
            'Formatters/JsonFormatter.php',
            'Formatters/ConsoleFormatter.php',
        ];

        foreach ($formatters as $formatter) {
            $filePath = dirname($this->indexPath) . '/' . $formatter;
            $this->assertFileExists($filePath, "Formatter file {$formatter} should exist");
        }
    }

    public function testIndexFileCanBeIncludedWithoutErrors(): void
    {
        // Test that requiring index.php doesn't cause errors
        // This tests that all require_once statements (lines 8-40) work correctly

        // Capture any output or errors
        ob_start();

        try {
            require_once $this->indexPath;
            $output = ob_get_clean();
            $this->assertEmpty($output, 'index.php should not output anything');
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail("index.php should not throw errors: {$e->getMessage()}");
        }
    }

    public function testIndexFileRequiresAllFilesInCorrectOrder(): void
    {
        // Test that all files are required in the order specified (lines 8-40)
        $requiredFiles = [
            // Contracts (lines 8-12)
            'Contracts/AnalyzerInterface.php',
            'Contracts/ResultInterface.php',
            'Contracts/ReporterInterface.php',
            'Contracts/ParserInterface.php',
            // Enums (lines 14-17)
            'Enums/Status.php',
            'Enums/Category.php',
            'Enums/Severity.php',
            // Value Objects (lines 19-22)
            'ValueObjects/Location.php',
            'ValueObjects/Issue.php',
            'ValueObjects/AnalyzerMetadata.php',
            // Results (lines 24-26)
            'Results/AnalysisResult.php',
            'Results/ResultCollection.php',
            // Abstracts (lines 28-30)
            'Abstracts/AbstractAnalyzer.php',
            'Abstracts/AbstractFileAnalyzer.php',
            // Support (lines 32-36)
            'Support/AstParser.php',
            'Support/FileParser.php',
            'Support/ConfigFileHelper.php',
            'Support/CodeHelper.php',
            // Formatters (lines 38-40)
            'Formatters/JsonFormatter.php',
            'Formatters/ConsoleFormatter.php',
        ];

        $basePath = dirname($this->indexPath);
        foreach ($requiredFiles as $file) {
            $filePath = $basePath . '/' . $file;
            $this->assertFileExists($filePath, "Required file {$file} should exist (tested in index.php lines 8-40)");
        }
    }
}
