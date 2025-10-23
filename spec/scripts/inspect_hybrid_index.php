<?php
/**
 * Inspect Hybrid Index Database
 * 
 * Shows detailed statistics about what's being indexed
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Get hybrid index path (from command line or default)
$hybridIndexPath = $argv[1] ?? '/var/www/temp/myco/data/images_hybrid_index.db';

if (!file_exists($hybridIndexPath)) {
    echo "❌ Hybrid index not found at: $hybridIndexPath\n";
    exit(1);
}

echo "🔍 Inspecting Hybrid Index\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Path: $hybridIndexPath\n";
echo "Size: " . number_format(filesize($hybridIndexPath) / 1024 / 1024, 2) . " MB\n\n";

// Open database
$db = new PDO("sqlite:$hybridIndexPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get attributes
echo "📋 Indexed Attributes:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$stmt = $db->query("SELECT Aid, ColumnName, TableName, DataType, TokenStrategy FROM Attributes ORDER BY Aid");
$attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($attributes as $attr) {
    // Get distinct value count for this attribute
    $countStmt = $db->prepare("
        SELECT COUNT(DISTINCT Vid) as value_count
        FROM EAV
        WHERE Aid = ?
    ");
    $countStmt->execute([$attr['Aid']]);
    $valueCount = $countStmt->fetchColumn();

    // Get EAV entry count for this attribute
    $eavStmt = $db->prepare("
        SELECT COUNT(*) as eav_count
        FROM EAV
        WHERE Aid = ?
    ");
    $eavStmt->execute([$attr['Aid']]);
    $eavCount = $eavStmt->fetchColumn();

    printf("[%2d] %-25s %10s values, %12s EAV entries\n",
        $attr['Aid'],
        $attr['ColumnName'],
        number_format($valueCount),
        number_format($eavCount)
    );
}

echo "\n📊 Table Sizes:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$tables = ['Entities', 'Collections', 'Attributes', 'ValuesText', 'EAV'];
foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    printf("%-20s %15s rows\n", $table, number_format($count));
}

// Get top 10 attributes by EAV entry count
echo "\n🔝 Top Attributes by EAV Entry Count:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$topStmt = $db->query("
    SELECT
        a.ColumnName,
        COUNT(*) as eav_count,
        COUNT(DISTINCT e.Vid) as distinct_values
    FROM EAV e
    JOIN Attributes a ON a.Aid = e.Aid
    GROUP BY e.Aid, a.ColumnName
    ORDER BY eav_count DESC
    LIMIT 10
");

foreach ($topStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("%-25s %12s entries (%s distinct values)\n",
        $row['ColumnName'],
        number_format($row['eav_count']),
        number_format($row['distinct_values'])
    );
}

// Get top 10 most common values
echo "\n🔤 Top 10 Most Common Values:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$topValuesStmt = $db->query("
    SELECT
        v.ValueText,
        a.ColumnName,
        COUNT(*) as usage_count
    FROM EAV e
    JOIN ValuesText v ON v.Vid = e.Vid
    JOIN Attributes a ON a.Aid = e.Aid
    GROUP BY e.Vid, v.ValueText, a.ColumnName
    ORDER BY usage_count DESC
    LIMIT 10
");

foreach ($topValuesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("%-30s %-20s %12s occurrences\n",
        substr($row['ValueText'], 0, 30),
        $row['ColumnName'],
        number_format($row['usage_count'])
    );
}

// Check for potential issues
echo "\n⚠️  Potential Issues:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Check for high-cardinality fields
$highCardStmt = $db->query("
    SELECT
        a.ColumnName,
        COUNT(DISTINCT e.Vid) as distinct_values,
        COUNT(*) as eav_count
    FROM EAV e
    JOIN Attributes a ON a.Aid = e.Aid
    GROUP BY e.Aid, a.ColumnName
    HAVING distinct_values > 10000
    ORDER BY distinct_values DESC
");

$highCardFields = $highCardStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($highCardFields) > 0) {
    echo "❌ High-cardinality fields detected (should be excluded from hybrid index):\n";
    foreach ($highCardFields as $field) {
        printf("   • %-25s %10s distinct values\n",
            $field['ColumnName'],
            number_format($field['distinct_values'])
        );
    }
} else {
    echo "✅ No high-cardinality fields detected\n";
}

// Check for low-value fields (< 100 distinct values but high EAV count)
$lowValueStmt = $db->query("
    SELECT
        a.ColumnName,
        COUNT(DISTINCT e.Vid) as distinct_values,
        COUNT(*) as eav_count
    FROM EAV e
    JOIN Attributes a ON a.Aid = e.Aid
    GROUP BY e.Aid, a.ColumnName
    HAVING distinct_values < 100 AND eav_count > 500000
    ORDER BY eav_count DESC
");

$lowValueFields = $lowValueStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($lowValueFields) > 0) {
    echo "\n✅ Good indexing candidates (low cardinality, high usage):\n";
    foreach ($lowValueFields as $field) {
        printf("   • %-25s %6s values, %10s entries\n",
            $field['ColumnName'],
            number_format($field['distinct_values']),
            number_format($field['eav_count'])
        );
    }
}

echo "\n";

