<?php
/**
 * Check Autocomplete Table Schema
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

echo "🔍 Checking Autocomplete Table Schemas\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Open database
$db = new PDO("sqlite:$hybridIndexPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = ['autocomplete_taxon', 'autocomplete_collection', 'autocomplete_location'];

foreach ($tables as $table) {
    echo "📋 Table: $table\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    // Get schema
    $schema = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columns:\n";
    foreach ($schema as $col) {
        printf("  - %-20s %s\n", $col['name'], $col['type']);
    }
    
    // Get sample row
    $sample = $db->query("SELECT * FROM $table LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sample) {
        echo "\nSample row:\n";
        foreach ($sample as $key => $value) {
            printf("  %-20s = %s\n", $key, $value);
        }
    }
    
    echo "\n";
}

