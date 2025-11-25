# ShieldCI Analyzers Core

Shared foundation for building static analysis tools. Includes abstract analyzer classes, result formatters, file parsers, and utilities.

[![Tests](https://github.com/shieldci/analyzers-core/actions/workflows/tests.yml/badge.svg)](https://github.com/shieldci/analyzers-core/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/shieldci/analyzers-core/branch/master/graph/badge.svg)](https://codecov.io/gh/shieldci/analyzers-core)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%209-brightgreen)](https://phpstan.org/)

## Features

- **Framework Agnostic**: Works with any PHP 8.1+ project
- **Type Safe**: Full type hints and strict typing
- **Extensible**: Easy to create custom analyzers
- **Well Tested**: Comprehensive test suite (90%+ coverage)
- **Modern PHP**: Uses PHP 8.1+ features
- **Laravel Compatible**: Works with Laravel 9.x, 10.x, 11.x, and 12.x

## Requirements

- PHP 8.1 or higher
- Composer

## Installation

```bash
composer require shieldci/analyzers-core
```

## Architecture

### Core Components

1. **Interfaces**
   - `AnalyzerInterface` - Contract for all analyzers
   - `ResultInterface` - Contract for analysis results
   - `ReporterInterface` - Contract for result formatters
   - `ParserInterface` - Contract for code parsers

2. **Abstract Base Classes**
   - `AbstractAnalyzer` - Base class with timing, error handling, and helper methods
   - `AbstractFileAnalyzer` - Base class for file-based analyzers with file filtering

3. **Value Objects**
   - `Location` - Represents a code location (file, line, column)
   - `Issue` - Represents a specific issue found
   - `AnalyzerMetadata` - Metadata about an analyzer

4. **Results**
   - `AnalysisResult` - Result of running a single analyzer
   - `ResultCollection` - Collection of analysis results

5. **Utilities**
   - `AstParser` - AST parsing using nikic/php-parser
   - `FileParser` - File content parsing utilities
   - `CodeHelper` - Code analysis helpers

6. **Formatters**
   - `JsonFormatter` - Format results as JSON
   - `ConsoleFormatter` - Format results for console output

## Usage

### Creating a Custom Analyzer

```php
<?php

use ShieldCI\AnalyzersCore\Abstracts\AbstractFileAnalyzer;
use ShieldCI\AnalyzersCore\Contracts\ResultInterface;
use ShieldCI\AnalyzersCore\ValueObjects\{AnalyzerMetadata, Issue, Location};
use ShieldCI\AnalyzersCore\Enums\{Category, Severity};

class MySecurityAnalyzer extends AbstractFileAnalyzer
{
    protected function metadata(): AnalyzerMetadata
    {
        return new AnalyzerMetadata(
            id: 'my-security-analyzer',
            name: 'My Security Analyzer',
            description: 'Checks for security vulnerabilities',
            category: Category::Security,
            severity: Severity::High,
        );
    }

    protected function runAnalysis(): ResultInterface
    {
        $issues = [];

        foreach ($this->getPhpFiles() as $file) {
            $content = $this->readFile($file);

            if (str_contains($content, 'eval(')) {
                $issues[] = $this->createIssue(
                    message: 'Dangerous eval() function found',
                    location: new Location($file, 1),
                    severity: Severity::Critical,
                    recommendation: 'Remove eval() and use safer alternatives'
                );
            }
        }

        if (empty($issues)) {
            return $this->passed('No security issues found');
        }

        return $this->failed(
            'Security issues detected',
            $issues,
            ['files_scanned' => count($this->getPhpFiles())]
        );
    }
}
```

### Running an Analyzer

```php
<?php

$analyzer = new MySecurityAnalyzer();
$analyzer->setBasePath('/path/to/project');
$analyzer->setPaths(['src', 'app']);

$result = $analyzer->analyze();

echo "Status: " . $result->getStatus()->value . PHP_EOL;
echo "Message: " . $result->getMessage() . PHP_EOL;
echo "Issues: " . count($result->getIssues()) . PHP_EOL;
```

### Using Result Collection

```php
<?php

use ShieldCI\AnalyzersCore\Results\ResultCollection;

$collection = new ResultCollection();
$collection->add($analyzer1->analyze());
$collection->add($analyzer2->analyze());
$collection->add($analyzer3->analyze());

echo "Score: " . $collection->score() . "%" . PHP_EOL;
echo "Total Issues: " . $collection->totalIssues() . PHP_EOL;
echo "Execution Time: " . $collection->totalExecutionTime() . "s" . PHP_EOL;
```

### Formatting Results

```php
<?php

use ShieldCI\AnalyzersCore\Formatters\{ConsoleFormatter, JsonFormatter};

$results = [$result1, $result2, $result3];

// Console output
$consoleFormatter = new ConsoleFormatter(useColors: true, verbose: true);
echo $consoleFormatter->format($results);

// JSON output
$jsonFormatter = new JsonFormatter(prettyPrint: true);
$json = $jsonFormatter->format($results);
file_put_contents('report.json', $json);
```

### Using the AST Parser

```php
<?php

use ShieldCI\AnalyzersCore\Support\AstParser;
use PhpParser\Node\Expr\MethodCall;

$parser = new AstParser();
$ast = $parser->parseFile('/path/to/file.php');

// Find all method calls
$methodCalls = $parser->findMethodCalls($ast, 'query');

// Find static calls
$staticCalls = $parser->findStaticCalls($ast, 'DB', 'raw');

// Find nodes of specific type
$classes = $parser->findNodes($ast, \PhpParser\Node\Stmt\Class_::class);
```

### Using Code Helpers

```php
<?php

use ShieldCI\AnalyzersCore\Support\CodeHelper;

$code = file_get_contents('/path/to/file.php');

// Calculate complexity
$complexity = CodeHelper::calculateComplexity($code);

// Find dangerous functions
$dangerous = CodeHelper::findDangerousFunctions($code);

// Check if looks like SQL
$isSql = CodeHelper::looksLikeSql($string);

// Validate naming conventions
$isValid = CodeHelper::isValidClassName('MyClass');
```

## Enums

### Status

- `Passed` - Analysis passed
- `Failed` - Analysis found issues
- `Warning` - Analysis found warnings
- `Skipped` - Analysis was skipped
- `Error` - Analysis encountered an error

### Category

- `Security` - Security vulnerabilities and risks
- `Performance` - Performance issues and optimizations
- `CodeQuality` - Code quality and maintainability
- `BestPractices` - Best practices and conventions
- `Reliability` - Reliability and stability issues

### Severity

- `Critical` - Critical issue requiring immediate attention
- `High` - High priority issue
- `Medium` - Medium priority issue
- `Low` - Low priority issue
- `Info` - Informational message

## Testing

```bash
# Run tests
composer test

# Run tests with coverage
composer test-coverage

# Run static analysis
composer analyse

# Format code
composer format
```

## Directory Structure

```
src/
├── Contracts/          # Interfaces
├── Abstracts/          # Abstract base classes
├── Enums/             # Enum types
├── ValueObjects/      # Immutable value objects
├── Results/           # Result classes
├── Support/           # Utility classes
└── Formatters/        # Result formatters

tests/
├── Unit/              # Unit tests
```

## Used By

- [shieldci/laravel](https://github.com/shieldci/laravel) - Free Laravel analyzer package
- [shieldci/laravel-pro](https://github.com/shieldci/laravel-pro) - Pro Laravel analyzer package

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License. See LICENSE file for details.

## Credits

Built by the ShieldCI team.
