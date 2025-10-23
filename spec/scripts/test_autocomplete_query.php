<?php
/**
 * Test Autocomplete Queries
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
$field = $argv[2] ?? 'collectionCode';
$query = $argv[3] ?? 'd';

if (!file_exists($hybridIndexPath)) {
    echo "❌ Hybrid index not found at: $hybridIndexPath\n";
    exit(1);
}

echo "🔍 Testing Autocomplete Query\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Field: $field\n";
echo "Query: $query\n\n";

// Open database
$db = new PDO("sqlite:$hybridIndexPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Determine table and column
$tableMap = [
    'sciname' => ['table' => 'autocomplete_taxon', 'column' => 'taxon_name'],
    'family' => ['table' => 'autocomplete_taxon', 'column' => 'taxon_name'],
    'genus' => ['table' => 'autocomplete_taxon', 'column' => 'taxon_name'],
    'collectionName' => ['table' => 'autocomplete_collection', 'column' => 'collection_value'],
    'collectionCode' => ['table' => 'autocomplete_collection', 'column' => 'collection_value'],
    'institutionCode' => ['table' => 'autocomplete_collection', 'column' => 'collection_value'],
    'country' => ['table' => 'autocomplete_location', 'column' => 'location_value'],
    'stateProvince' => ['table' => 'autocomplete_location', 'column' => 'location_value'],
    'county' => ['table' => 'autocomplete_location', 'column' => 'location_value'],
];

if (!isset($tableMap[$field])) {
    echo "❌ Unknown field: $field\n";
    exit(1);
}

$table = $tableMap[$field]['table'];
$column = $tableMap[$field]['column'];

echo "Table: $table\n";
echo "Column: $column\n\n";

// Test query
$sql = "
    SELECT
        $column as value,
        image_count as count,
        source_field
    FROM $table
    WHERE LOWER($column) LIKE LOWER(:query)
    AND LOWER(source_field) = LOWER(:field)
    ORDER BY image_count DESC, value ASC
    LIMIT 10
";

echo "SQL:\n$sql\n\n";

$stmt = $db->prepare($sql);
$stmt->execute([
    ':query' => '%' . $query . '%',
    ':field' => $field
]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Results (" . count($results) . "):\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if (empty($results)) {
    echo "❌ No results found\n\n";
    
    // Debug: Show all values for this field
    echo "Debug: All values for source_field='$field':\n";
    $debugSql = "SELECT $column, image_count, source_field FROM $table WHERE LOWER(source_field) = LOWER(:field) LIMIT 10";
    $debugStmt = $db->prepare($debugSql);
    $debugStmt->execute([':field' => $field]);
    $debugResults = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($debugResults as $row) {
        printf("  - %-30s (count: %d, field: %s)\n", $row[$column], $row['image_count'], $row['source_field']);
    }
} else {
    foreach ($results as $row) {
        printf("  %-30s (count: %d, field: %s)\n", $row['value'], $row['count'], $row['source_field']);
    }
}

echo "\n";

