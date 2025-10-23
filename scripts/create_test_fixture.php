#!/usr/bin/env php
<?php
/**
 * Create test fixture from source.db
 * 
 * This script:
 * 1. Opens the source.db created by cache-export-tsv
 * 2. Finds 100 complete records with all foreign keys satisfied
 * 3. Creates a SQLite dump file for use in tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

$sourceDbPath = '/var/www/temp/myco/data/source.db';
$fixtureDbPath = __DIR__ . '/../spec/fixtures/eav_100/source.db';
$limit = 100;

if (!file_exists($sourceDbPath)) {
    echo "ERROR: Source database not found: $sourceDbPath\n";
    echo "Run: php index.php images cache-export-tsv --limit=5000\n";
    exit(1);
}

// Create fixture directory
$fixtureDir = dirname($fixtureDbPath);
if (!is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0755, true);
}

// Remove old fixture if exists
if (file_exists($fixtureDbPath)) {
    unlink($fixtureDbPath);
}

echo "Creating test fixture from $sourceDbPath...\n";
echo "Target: $fixtureDbPath\n";
echo "Limit: $limit records\n\n";

// Open source database
$sourceDb = new PDO('sqlite:' . $sourceDbPath);
$sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create fixture database
$fixtureDb = new PDO('sqlite:' . $fixtureDbPath);
$fixtureDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get schema from source database
echo "Step 1: Copying schema...\n";
$tables = ['media', 'omoccurrences', 'omcollections', 'taxa'];

foreach ($tables as $table) {
    $stmt = $sourceDb->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $fixtureDb->exec($row['sql']);
        echo "  ✓ Created table: $table\n";
    }
}

// Get indexes
foreach ($tables as $table) {
    $stmt = $sourceDb->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='$table' AND sql IS NOT NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fixtureDb->exec($row['sql']);
    }
}

echo "\nStep 2: Selecting $limit complete records...\n";

// Select media records that have complete foreign key chains
// media -> omoccurrences -> omcollections + taxa
$sql = "
    SELECT m.mediaID
    FROM media m
    JOIN omoccurrences o ON m.occid = o.occid
    LEFT JOIN omcollections c ON o.collid = c.collID
    LEFT JOIN taxa t ON o.tidInterpreted = t.tid
    WHERE m.occid IS NOT NULL
    LIMIT :limit
";

$stmt = $sourceDb->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$mediaIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "  Found " . count($mediaIds) . " media records\n";

// Get related occid, collid, tid values
$mediaIdList = implode(',', $mediaIds);

$occids = $sourceDb->query("SELECT DISTINCT occid FROM media WHERE mediaID IN ($mediaIdList) AND occid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$collids = $sourceDb->query("SELECT DISTINCT collid FROM omoccurrences WHERE occid IN (" . implode(',', $occids) . ") AND collid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$tids = $sourceDb->query("SELECT DISTINCT tidInterpreted FROM omoccurrences WHERE occid IN (" . implode(',', $occids) . ") AND tidInterpreted IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

echo "  Related records: " . count($occids) . " occurrences, " . count($collids) . " collections, " . count($tids) . " taxa\n";

echo "\nStep 3: Copying data...\n";

// Copy media records
$fixtureDb->exec("BEGIN TRANSACTION");

$columns = $sourceDb->query("PRAGMA table_info(media)")->fetchAll(PDO::FETCH_COLUMN, 1);
$columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));
$placeholders = implode(', ', array_fill(0, count($columns), '?'));

$insertStmt = $fixtureDb->prepare("INSERT INTO media ($columnList) VALUES ($placeholders)");
$selectStmt = $sourceDb->prepare("SELECT $columnList FROM media WHERE mediaID IN ($mediaIdList)");
$selectStmt->execute();

$count = 0;
while ($row = $selectStmt->fetch(PDO::FETCH_NUM)) {
    $insertStmt->execute($row);
    $count++;
}
echo "  ✓ Copied $count media records\n";

// Copy omoccurrences records
$columns = $sourceDb->query("PRAGMA table_info(omoccurrences)")->fetchAll(PDO::FETCH_COLUMN, 1);
$columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));
$placeholders = implode(', ', array_fill(0, count($columns), '?'));

$insertStmt = $fixtureDb->prepare("INSERT INTO omoccurrences ($columnList) VALUES ($placeholders)");
$selectStmt = $sourceDb->prepare("SELECT $columnList FROM omoccurrences WHERE occid IN (" . implode(',', $occids) . ")");
$selectStmt->execute();

$count = 0;
while ($row = $selectStmt->fetch(PDO::FETCH_NUM)) {
    $insertStmt->execute($row);
    $count++;
}
echo "  ✓ Copied $count omoccurrences records\n";

// Copy omcollections records
if (!empty($collids)) {
    $columns = $sourceDb->query("PRAGMA table_info(omcollections)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $insertStmt = $fixtureDb->prepare("INSERT INTO omcollections ($columnList) VALUES ($placeholders)");
    $selectStmt = $sourceDb->prepare("SELECT $columnList FROM omcollections WHERE collID IN (" . implode(',', $collids) . ")");
    $selectStmt->execute();

    $count = 0;
    while ($row = $selectStmt->fetch(PDO::FETCH_NUM)) {
        $insertStmt->execute($row);
        $count++;
    }
    echo "  ✓ Copied $count omcollections records\n";
}

// Copy taxa records
if (!empty($tids)) {
    $columns = $sourceDb->query("PRAGMA table_info(taxa)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $insertStmt = $fixtureDb->prepare("INSERT INTO taxa ($columnList) VALUES ($placeholders)");
    $selectStmt = $sourceDb->prepare("SELECT $columnList FROM taxa WHERE tid IN (" . implode(',', $tids) . ")");
    $selectStmt->execute();

    $count = 0;
    while ($row = $selectStmt->fetch(PDO::FETCH_NUM)) {
        $insertStmt->execute($row);
        $count++;
    }
    echo "  ✓ Copied $count taxa records\n";
}

$fixtureDb->exec("COMMIT");

echo "\n✓ Fixture created successfully: $fixtureDbPath\n";

// Show statistics
$stats = [
    'media' => $fixtureDb->query("SELECT COUNT(*) FROM media")->fetchColumn(),
    'omoccurrences' => $fixtureDb->query("SELECT COUNT(*) FROM omoccurrences")->fetchColumn(),
    'omcollections' => $fixtureDb->query("SELECT COUNT(*) FROM omcollections")->fetchColumn(),
    'taxa' => $fixtureDb->query("SELECT COUNT(*) FROM taxa")->fetchColumn(),
];

echo "\nFinal counts:\n";
foreach ($stats as $table => $count) {
    echo "  $table: $count\n";
}

// Show sample data
echo "\nSample family values:\n";
$families = $fixtureDb->query("SELECT DISTINCT family FROM omoccurrences WHERE family IS NOT NULL AND family != '' LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
foreach ($families as $family) {
    echo "  - $family\n";
}

echo "\nSample genus values:\n";
$genera = $fixtureDb->query("SELECT DISTINCT genus FROM omoccurrences WHERE genus IS NOT NULL AND genus != '' LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
foreach ($genera as $genus) {
    echo "  - $genus\n";
}

echo "\nDone!\n";

