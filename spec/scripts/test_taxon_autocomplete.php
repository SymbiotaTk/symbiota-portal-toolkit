<?php
/**
 * Test Taxon Autocomplete (multi-field alias)
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
$query = $argv[2] ?? 'puc';

if (!file_exists($hybridIndexPath)) {
    echo "❌ Hybrid index not found at: $hybridIndexPath\n";
    exit(1);
}

echo "🔍 Testing Taxon Autocomplete (Multi-Field Alias)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Query: $query\n\n";

// Open database directly
$db = new PDO("sqlite:$hybridIndexPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Test the multi-field query
$sql = "
    SELECT
        taxon_name as value,
        image_count as count,
        source_field
    FROM autocomplete_taxon
    WHERE LOWER(taxon_name) LIKE LOWER(:query)
    AND LOWER(source_field) IN ('family', 'genus', 'sciname', 'sciname')
    ORDER BY image_count DESC, value ASC
    LIMIT 10
";

echo "SQL:\n$sql\n\n";

$stmt = $db->prepare($sql);
$stmt->execute([':query' => '%' . $query . '%']);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Results (" . count($results) . "):\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

foreach ($results as $row) {
    printf("  %-30s (count: %d, field: %s)\n", $row['value'], $row['count'], $row['source_field']);
}

