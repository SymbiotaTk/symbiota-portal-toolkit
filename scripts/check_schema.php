<?php

/**
 * Check schema of imported tables in EAV cache database
 */

$dbPath = $argv[1] ?? '/var/www/temp/myco/data/images_cache.db';

if (!file_exists($dbPath)) {
    echo "Database not found: $dbPath\n";
    exit(1);
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = ['media', 'omoccurrences', 'omcollections', 'taxa', 'taxstatus'];

foreach ($tables as $table) {
    echo "\n=== $table ===\n";
    
    $result = $db->query("PRAGMA table_info($table)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "  (table not found)\n";
        continue;
    }
    
    foreach ($columns as $col) {
        echo sprintf("  %s (%s)\n", $col['name'], $col['type']);
    }
}

echo "\n";

