<?php

declare(strict_types=1);

// Export all public APIs

// Contracts
require_once __DIR__ . '/Contracts/AnalyzerInterface.php';
require_once __DIR__ . '/Contracts/ResultInterface.php';
require_once __DIR__ . '/Contracts/ReporterInterface.php';
require_once __DIR__ . '/Contracts/ParserInterface.php';

// Enums
require_once __DIR__ . '/Enums/Status.php';
require_once __DIR__ . '/Enums/Category.php';
require_once __DIR__ . '/Enums/Severity.php';

// Value Objects
require_once __DIR__ . '/ValueObjects/Location.php';
require_once __DIR__ . '/ValueObjects/Issue.php';
require_once __DIR__ . '/ValueObjects/AnalyzerMetadata.php';

// Results
require_once __DIR__ . '/Results/AnalysisResult.php';
require_once __DIR__ . '/Results/ResultCollection.php';

// Abstracts
require_once __DIR__ . '/Abstracts/AbstractAnalyzer.php';
require_once __DIR__ . '/Abstracts/AbstractFileAnalyzer.php';

// Support
require_once __DIR__ . '/Support/AstParser.php';
require_once __DIR__ . '/Support/FileParser.php';
require_once __DIR__ . '/Support/CodeHelper.php';

// Formatters
require_once __DIR__ . '/Formatters/JsonFormatter.php';
require_once __DIR__ . '/Formatters/ConsoleFormatter.php';
