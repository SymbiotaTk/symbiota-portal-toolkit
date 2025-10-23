#!/usr/bin/env php
<?php
/**
 * TSV Analysis Utility
 * 
 * Analyzes TSV files for parsing errors:
 * - Rows with incorrect tab count
 * - Rows with embedded tabs, newlines, carriage returns
 * - Rows with problematic whitespace
 * 
 * Usage:
 *   php scripts/analyze_tsv.php <tsv-file>
 *   php scripts/analyze_tsv.php /var/www/temp/bioc/data/media.tsv
 */

if ($argc < 2) {
    echo "Usage: php scripts/analyze_tsv.php <tsv-file>\n";
    echo "\nExample:\n";
    echo "  php scripts/analyze_tsv.php /var/www/temp/bioc/data/media.tsv\n";
    exit(1);
}

$tsvFile = $argv[1];

if (!file_exists($tsvFile)) {
    echo "Error: File not found: $tsvFile\n";
    exit(1);
}

echo "Analyzing TSV file: $tsvFile\n";
echo str_repeat("=", 80) . "\n\n";

// Open file
$fp = fopen($tsvFile, 'r');
if (!$fp) {
    echo "Error: Could not open file\n";
    exit(1);
}

// Read header
$header = fgets($fp);
if (!$header) {
    echo "Error: Empty file\n";
    exit(1);
}

$headerTabCount = substr_count($header, "\t");
$columnCount = $headerTabCount + 1;

echo "Header Analysis:\n";
echo "  Columns: $columnCount\n";
echo "  Tab count: $headerTabCount\n";
echo "  Header: " . trim($header) . "\n\n";

// Analysis counters
$totalRows = 0;
$errorRows = 0;
$tabErrorCount = 0;
$newlineErrorCount = 0;
$carriageReturnErrorCount = 0;
$mixedWhitespaceErrorCount = 0;

// Sample problematic rows (only store samples, not all line numbers)
$sampleTabErrors = [];
$sampleNewlineErrors = [];
$sampleCarriageReturnErrors = [];

$maxSamples = 5;

echo "Analyzing rows...\n";

$lineNumber = 1; // Header is line 1
while (($line = fgets($fp)) !== false) {
    $lineNumber++;
    $totalRows++;
    
    // Check tab count
    $tabCount = substr_count($line, "\t");
    if ($tabCount !== $headerTabCount) {
        $errorRows++;
        $tabErrorCount++;

        if (count($sampleTabErrors) < $maxSamples) {
            $sampleTabErrors[] = [
                'line' => $lineNumber,
                'expected_tabs' => $headerTabCount,
                'actual_tabs' => $tabCount,
                'content' => substr($line, 0, 200) // First 200 chars
            ];
        }
    }

    // Check for embedded newlines (in the middle of the line)
    $trimmedLine = rtrim($line, "\n\r");
    if (strpos($trimmedLine, "\n") !== false) {
        $newlineErrorCount++;

        if (count($sampleNewlineErrors) < $maxSamples) {
            $sampleNewlineErrors[] = [
                'line' => $lineNumber,
                'content' => substr($line, 0, 200)
            ];
        }
    }

    // Check for embedded carriage returns
    if (strpos($trimmedLine, "\r") !== false) {
        $carriageReturnErrorCount++;

        if (count($sampleCarriageReturnErrors) < $maxSamples) {
            $sampleCarriageReturnErrors[] = [
                'line' => $lineNumber,
                'content' => substr($line, 0, 200)
            ];
        }
    }

    // Check for mixed whitespace (tabs + newlines + carriage returns)
    if (preg_match('/[\t\n\r].*[\t\n\r]/', $trimmedLine)) {
        $mixedWhitespaceErrorCount++;
    }
    
    // Progress indicator
    if ($totalRows % 10000 === 0) {
        echo "  ... $totalRows rows analyzed\n";
    }
}

fclose($fp);

echo "\n";
echo str_repeat("=", 80) . "\n";
echo "ANALYSIS RESULTS\n";
echo str_repeat("=", 80) . "\n\n";

echo "Total rows analyzed: " . number_format($totalRows) . "\n";
echo "Rows with errors: " . number_format($errorRows) . " (" . round($errorRows / $totalRows * 100, 2) . "%)\n\n";

// Tab count errors
echo "Tab Count Errors:\n";
echo "  Rows with incorrect tab count: " . number_format($tabErrorCount) . "\n";
if (!empty($sampleTabErrors)) {
    echo "\n  Sample errors:\n";
    foreach ($sampleTabErrors as $error) {
        echo "    Line {$error['line']}: Expected {$error['expected_tabs']} tabs, found {$error['actual_tabs']}\n";
        echo "      Content: " . addcslashes(trim($error['content']), "\t\n\r") . "\n";
    }
}
echo "\n";

// Newline errors
echo "Embedded Newline Errors:\n";
echo "  Rows with embedded newlines: " . number_format($newlineErrorCount) . "\n";
if (!empty($sampleNewlineErrors)) {
    echo "\n  Sample errors:\n";
    foreach ($sampleNewlineErrors as $error) {
        echo "    Line {$error['line']}:\n";
        echo "      Content: " . addcslashes(trim($error['content']), "\t\n\r") . "\n";
    }
}
echo "\n";

// Carriage return errors
echo "Embedded Carriage Return Errors:\n";
echo "  Rows with embedded carriage returns: " . number_format($carriageReturnErrorCount) . "\n";
if (!empty($sampleCarriageReturnErrors)) {
    echo "\n  Sample errors:\n";
    foreach ($sampleCarriageReturnErrors as $error) {
        echo "    Line {$error['line']}:\n";
        echo "      Content: " . addcslashes(trim($error['content']), "\t\n\r") . "\n";
    }
}
echo "\n";

// Mixed whitespace errors
echo "Mixed Whitespace Errors:\n";
echo "  Rows with mixed whitespace: " . number_format($mixedWhitespaceErrorCount) . "\n\n";

// Summary
echo str_repeat("=", 80) . "\n";
echo "SUMMARY\n";
echo str_repeat("=", 80) . "\n\n";

if ($errorRows === 0) {
    echo "✓ No errors found! TSV file is clean.\n";
} else {
    echo "✗ Found errors in " . number_format($errorRows) . " rows (" . round($errorRows / $totalRows * 100, 2) . "%)\n\n";

    echo "Recommendations:\n";
    if ($tabErrorCount > 0) {
        echo "  1. Normalize whitespace in column values (remove tabs)\n";
    }
    if ($newlineErrorCount > 0) {
        echo "  2. Remove embedded newlines from column values\n";
    }
    if ($carriageReturnErrorCount > 0) {
        echo "  3. Remove embedded carriage returns from column values\n";
    }
    
    echo "\n";
    echo "Fix: Use normalizeTsvValue() function during export:\n";
    echo "  - Removes tabs, newlines, carriage returns\n";
    echo "  - Collapses multiple spaces\n";
    echo "  - Trims leading/trailing whitespace\n";
}

echo "\n";

