<?php
/**
 * Check EAV Attributes table
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ApplicationPaths;

ApplicationPaths::setApplicationFilename(__DIR__ . '/../index.php');

$config = new Configuration();
$config->loadFromFile(__DIR__ . '/../config.php');

$dbPath = $config->get('eav_cache_db');
echo "DB Path: $dbPath\n\n";

if (!file_exists($dbPath)) {
    echo "ERROR: Database file not found: $dbPath\n";
    exit(1);
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Attributes Table ===\n";
$stmt = $db->query("SELECT Aid, ColumnName, DataType FROM Attributes ORDER BY Aid");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%d: %s (%s)\n", $row['Aid'], $row['ColumnName'], $row['DataType']);
}

echo "\n=== Sample EAV Records (first 10) ===\n";
$stmt = $db->query("SELECT e.Eid, a.ColumnName, e.VidArray, e.ValueNumber 
                    FROM EAV e 
                    JOIN Attributes a ON e.Aid = a.Aid 
                    LIMIT 10");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("Eid=%d, Attr=%s, VidArray=%s, ValueNumber=%s\n", 
        $row['Eid'], 
        $row['ColumnName'], 
        $row['VidArray'] ?? 'NULL',
        $row['ValueNumber'] ?? 'NULL'
    );
}

echo "\n=== Search for collectioncode ===\n";
$stmt = $db->query("SELECT Aid, ColumnName FROM Attributes WHERE LOWER(ColumnName) LIKE '%collection%'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%d: %s\n", $row['Aid'], $row['ColumnName']);
}

echo "\n=== Count records for each collection attribute ===\n";
$stmt = $db->query("SELECT a.ColumnName, COUNT(*) as cnt 
                    FROM EAV e 
                    JOIN Attributes a ON e.Aid = a.Aid 
                    WHERE LOWER(a.ColumnName) LIKE '%collection%'
                    GROUP BY a.ColumnName");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%s: %d records\n", $row['ColumnName'], $row['cnt']);
}

