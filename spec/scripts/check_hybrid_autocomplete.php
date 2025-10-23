<?php
/**
 * Check Hybrid Index Autocomplete Tables
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\Environment;

// Get hybrid index path from Configuration or command line argument
$config = Configuration::getInstance();
$env = Environment::getInstance();

if ($config) {
    $defaultPath = $config->get('components.images.hybrid_index_db');
} elseif ($env) {
    $tempDir = $env->getSymbiotaTempDirRoot();
    $defaultPath = $tempDir ? rtrim($tempDir, '/') . '/data/images_hybrid_index.db' : null;
} else {
    $defaultPath = null;
}

$hybridIndexPath = $argv[1] ?? $defaultPath;

if (!file_exists($hybridIndexPath)) {
    echo "❌ Hybrid index not found at: $hybridIndexPath\n";
    exit(1);
}

echo "🔍 Checking Hybrid Index Autocomplete Tables\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Path: $hybridIndexPath\n\n";

// Open database
$db = new PDO("sqlite:$hybridIndexPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get all tables
echo "📋 Tables in Database:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    printf("%-30s %15s rows\n", $table, number_format($count));
}

// Check for autocomplete tables
echo "\n🔍 Autocomplete Tables:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$autocompleteTables = [
    'autocomplete_taxon',
    'autocomplete_collection',
    'autocomplete_location',
    'autocomplete_date'
];

foreach ($autocompleteTables as $table) {
    $exists = in_array($table, $tables);
    if ($exists) {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "✅ $table - $count rows\n";
        
        // Show sample data
        $sample = $db->query("SELECT * FROM $table LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($sample)) {
            echo "   Sample data:\n";
            foreach ($sample as $row) {
                echo "   - " . json_encode($row) . "\n";
            }
        }
    } else {
        echo "❌ $table - NOT FOUND\n";
    }
}

echo "\n";

