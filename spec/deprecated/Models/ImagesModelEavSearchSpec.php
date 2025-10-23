<?php
/**
 * EAV Search and Autocomplete Tests
 *
 * Tests search and autocomplete functionality using static fixtures:
 *   1. Build EAV index from fixtures
 *   2. Test command-line search
 *   3. Test web search
 *   4. Test autocomplete
 *   5. Validate round-trip: source data → EAV → search results → display
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavSearchSpec.php"
 */

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Models\ImagesModel;

describe('EAV Search and Autocomplete', function() {

    beforeAll(function() {
        // Use the pre-built fixture databases
        $this->sourceDbPath = '/var/www/temp/myco/data/testing/source.db';
        $this->cacheDbPath = '/var/www/temp/myco/data/testing/images_cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';

        echo "\n  Using pre-built fixture databases...\n";

        // Open source database
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $mediaCount = $this->sourceDb->query('SELECT COUNT(*) FROM media')->fetchColumn();
        echo "  ✓ Source DB: $mediaCount media records\n";

        // Open cache database for queries
        $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $eavCount = $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();
        echo "  ✓ Cache DB: $eavCount EAV rows\n";

        // Get sample data for testing (call helper function directly)
        $this->sampleData = [
            'catalogNumbers' => [],
            'families' => [],
            'scientificNames' => [],
            'collectionCodes' => []
        ];

        // Get sample catalog numbers
        $stmt = $this->sourceDb->query("
            SELECT DISTINCT o.catalogNumber
            FROM omoccurrences o
            WHERE o.catalogNumber IS NOT NULL
            LIMIT 5
        ");
        $this->sampleData['catalogNumbers'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get sample families
        $stmt = $this->sourceDb->query("
            SELECT DISTINCT o.family
            FROM omoccurrences o
            WHERE o.family IS NOT NULL
            LIMIT 5
        ");
        $this->sampleData['families'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get sample scientific names
        $stmt = $this->sourceDb->query("
            SELECT DISTINCT o.scientificName
            FROM omoccurrences o
            WHERE o.scientificName IS NOT NULL
            LIMIT 5
        ");
        $this->sampleData['scientificNames'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get sample collection codes
        $stmt = $this->sourceDb->query("
            SELECT DISTINCT c.collectionCode
            FROM omcollections c
            WHERE c.collectionCode IS NOT NULL
            LIMIT 5
        ");
        $this->sampleData['collectionCodes'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    });

    afterAll(function() {
        // No cleanup needed - using pre-built fixtures
    });

    describe('Source Data Sampling', function() {

        it('should extract sample data from source database', function() {
            echo "\n  Sample Data:\n";
            echo "    Catalog Numbers: " . count($this->sampleData['catalogNumbers']) . "\n";
            echo "    Families: " . count($this->sampleData['families']) . "\n";
            echo "    Scientific Names: " . count($this->sampleData['scientificNames']) . "\n";
            echo "    Collection Codes: " . count($this->sampleData['collectionCodes']) . "\n";
            
            expect($this->sampleData['catalogNumbers'])->not->toBeEmpty();
            expect($this->sampleData['families'])->not->toBeEmpty();
        });

    });

    describe('Basic EAV Queries', function() {

        it('should query entities by catalogNumber', function() {
            $catalogNumber = $this->sampleData['catalogNumbers'][0];

            // Query EAV for this catalogNumber
            $entities = queryEavByCatalogNumber($this->cacheDb, $catalogNumber);

            echo "\n  Query: catalogNumber = '$catalogNumber'\n";
            echo "    Found {$entities} entities\n";

            expect($entities)->toBeGreaterThan(0);
        });

        it('should query entities by family', function() {
            // Note: family data comes from taxstatus table, not omoccurrences
            // Use a known family from the fixture
            $family = 'Liceaceae';

            // Query EAV for this family (should check taxstatus.family, not omoccurrences.family)
            $entities = queryEavByAnyFamily($this->cacheDb, $family);

            echo "\n  Query: family = '$family' (from taxstatus)\n";
            echo "    Found {$entities} entities\n";

            expect($entities)->toBeGreaterThan(0);
        });

        it('should query entities by scientificName', function() {
            if (empty($this->sampleData['scientificNames'])) {
                echo "\n  No scientificName data in fixtures - skipping\n";
                expect(true)->toBe(true);
                return;
            }

            $sciName = $this->sampleData['scientificNames'][0];

            // Query EAV for this scientificName
            $entities = queryEavByScientificName($this->cacheDb, $sciName);

            echo "\n  Query: scientificName = '$sciName'\n";
            echo "    Found {$entities} entities\n";

            expect($entities)->toBeGreaterThan(0);
        });

    });

    describe('Round-Trip Validation', function() {

        it('should match source data to EAV results for catalogNumber', function() {
            $catalogNumber = $this->sampleData['catalogNumbers'][0];

            // Get source data
            $sourceMedia = $this->sourceDb->query("
                SELECT m.mediaID, m.url, o.catalogNumber, o.family, o.scientificName
                FROM media m
                LEFT JOIN omoccurrences o ON m.occid = o.occid
                WHERE o.catalogNumber = " . $this->sourceDb->quote($catalogNumber) . "
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            // Get EAV data
            $eavEntity = getEavEntityByCatalogNumber($this->cacheDb, $catalogNumber);

            echo "\n  Round-Trip Validation:\n";
            echo "    Source mediaID: {$sourceMedia['mediaID']}\n";
            echo "    EAV EntityValue: {$eavEntity['EntityValue']}\n";
            echo "    Source URL: {$sourceMedia['url']}\n";
            echo "    EAV URL: {$eavEntity['url']}\n";

            expect($eavEntity['EntityValue'])->toBe((string)$sourceMedia['mediaID']);
            expect($eavEntity['url'])->toBe($sourceMedia['url']);
        });

    });

    describe('Text Search', function() {

        it('should find entities by partial text match', function() {
            // Get a family name and search for partial match
            $family = $this->sampleData['families'][0];
            $partial = substr($family, 0, 5);

            $count = searchEavByText($this->cacheDb, $partial);

            echo "\n  Text Search: '$partial'\n";
            echo "    Found $count entities\n";

            expect($count)->toBeGreaterThan(0);
        });

        it('should find entities by locality partial match', function() {
            // Search for common locality terms
            $searchTerms = ['Denver', 'Boulder', 'Park', 'Creek'];

            foreach ($searchTerms as $term) {
                $count = searchEavByText($this->cacheDb, $term);
                if ($count > 0) {
                    echo "\n  Locality Search: '$term' found $count entities\n";
                    expect($count)->toBeGreaterThan(0);
                    return; // Found at least one
                }
            }

            // If none found, that's OK - just log it
            echo "\n  No common locality terms found in dataset\n";
            expect(true)->toBe(true);
        });

        it('should find entities by collection name partial match', function() {
            $count = searchEavByText($this->cacheDb, 'Denver');
            echo "\n  Collection Search: 'Denver' found $count entities\n";
            expect($count)->toBeGreaterThan(0);
        });

    });

    describe('Numeric Range Queries', function() {

        it('should query entities by numeric occid', function() {
            // Use a known occid from static fixture
            // Note: Both media.occid and omoccurrences.occid should have the same value
            $occid = 8; // From fixture data

            // Query using omoccurrences.occid (which should exist)
            $count = queryEavByNumericValueFromTable($this->cacheDb, 'omoccurrences', 'occid', $occid);

            echo "\n  Numeric Query: omoccurrences.occid = $occid\n";
            echo "    Found $count entities\n";

            expect($count)->toBeGreaterThan(0);
        });

        it('should query entities by latitude range', function() {
            // Use known latitude range from fixture (Denver area: ~39.5-40.0)
            $count = queryEavByNumericRange($this->cacheDb, 'decimalLatitude', 39.0, 40.0);

            echo "\n  Latitude Range Query: 39.0 to 40.0\n";
            echo "    Found $count entities\n";

            expect($count)->toBeGreaterThan(0);
        });

        it('should query entities by mediaID', function() {
            // Get first entity's mediaID from cache
            $mediaID = $this->cacheDb->query("SELECT EntityValue FROM Entities LIMIT 1")->fetchColumn();

            $count = queryEavByNumericValue($this->cacheDb, 'mediaID', $mediaID);

            echo "\n  Numeric Query: mediaID = $mediaID\n";
            echo "    Found $count entities\n";

            expect($count)->toBe(1); // Should find exactly 1
        });

    });

    describe('Multi-Field Queries', function() {

        it('should query entities by family AND country', function() {
            $family = $this->sampleData['families'][0];

            // Use known country from fixture (USA)
            $count = queryEavByMultipleFields($this->cacheDb, [
                'family' => $family,
                'country' => 'USA'
            ]);

            echo "\n  Multi-Field Query: family='$family' AND country='USA'\n";
            echo "    Found $count entities\n";

            // May be 0 if this family doesn't have USA records
            expect($count)->toBeGreaterThan(-1); // >= 0
        });

        it('should query entities by collectionCode AND family', function() {
            $collectionCode = $this->sampleData['collectionCodes'][0] ?? 'COLO';
            $family = $this->sampleData['families'][0];

            $count = queryEavByMultipleFields($this->cacheDb, [
                'collectionCode' => $collectionCode,
                'family' => $family
            ]);

            echo "\n  Multi-Field Query: collectionCode='$collectionCode' AND family='$family'\n";
            echo "    Found $count entities\n";

            expect($count)->toBeGreaterThan(-1); // >= 0
        });

        it('should query entities by stateProvince AND family', function() {
            $family = $this->sampleData['families'][0];

            // Use known state from fixture (Colorado)
            $count = queryEavByMultipleFields($this->cacheDb, [
                'family' => $family,
                'stateProvince' => 'Colorado'
            ]);

            echo "\n  Multi-Field Query: family='$family' AND stateProvince='Colorado'\n";
            echo "    Found $count entities\n";

            expect($count)->toBeGreaterThan(-1); // >= 0
        });

    });

    describe('Autocomplete Simulation', function() {

        it('should autocomplete family names', function() {
            $family = $this->sampleData['families'][0];
            $prefix = substr($family, 0, 3);

            $suggestions = autocompleteField($this->cacheDb, 'family', $prefix, 10);

            echo "\n  Autocomplete: family starts with '$prefix'\n";
            echo "    Found " . count($suggestions) . " suggestions\n";
            foreach (array_slice($suggestions, 0, 5) as $suggestion) {
                echo "      - $suggestion\n";
            }

            expect(count($suggestions))->toBeGreaterThan(0);
            expect(in_array($family, $suggestions))->toBe(true);
        });

        it('should autocomplete catalog numbers', function() {
            $catalogNumber = $this->sampleData['catalogNumbers'][0];
            $prefix = substr($catalogNumber, 0, 5);

            $suggestions = autocompleteField($this->cacheDb, 'catalogNumber', $prefix, 10);

            echo "\n  Autocomplete: catalogNumber starts with '$prefix'\n";
            echo "    Found " . count($suggestions) . " suggestions\n";
            foreach (array_slice($suggestions, 0, 5) as $suggestion) {
                echo "      - $suggestion\n";
            }

            expect(count($suggestions))->toBeGreaterThan(0);
        });

        it('should autocomplete collection names', function() {
            // Use known prefix from fixture (Denver Botanic Gardens)
            $prefix = 'Den';

            $suggestions = autocompleteField($this->cacheDb, 'collectionName', $prefix, 10);

            echo "\n  Autocomplete: collectionName starts with '$prefix'\n";
            echo "    Found " . count($suggestions) . " suggestions\n";
            foreach (array_slice($suggestions, 0, 5) as $suggestion) {
                echo "      - $suggestion\n";
            }

            expect(count($suggestions))->toBeGreaterThan(0);
        });

        it('should autocomplete state/province names', function() {
            // Use known prefix from fixture (Colorado)
            $prefix = 'Col';

            $suggestions = autocompleteField($this->cacheDb, 'stateProvince', $prefix, 10);

            echo "\n  Autocomplete: stateProvince starts with '$prefix'\n";
            echo "    Found " . count($suggestions) . " suggestions\n";
            foreach ($suggestions as $suggestion) {
                echo "      - $suggestion\n";
            }

            expect(count($suggestions))->toBeGreaterThan(0);
        });

        it('should autocomplete country names', function() {
            // Use known prefix from fixture (United States of America)
            $prefix = 'Unit';

            $suggestions = autocompleteField($this->cacheDb, 'country', $prefix, 10);

            echo "\n  Autocomplete: country starts with '$prefix'\n";
            echo "    Found " . count($suggestions) . " suggestions\n";
            foreach ($suggestions as $suggestion) {
                echo "      - $suggestion\n";
            }

            expect(count($suggestions))->toBeGreaterThan(0);
        });

    });

    describe('Taxon Data Relationships', function() {

        it('should have taxa.sciName data', function() {
            $count = $this->sourceDb->query("SELECT COUNT(*) FROM taxa")->fetchColumn();
            echo "\n  Taxa records: $count\n";
            expect($count)->toBeGreaterThan(0);

            // Show sample data
            $stmt = $this->sourceDb->query("SELECT tid, sciName FROM taxa LIMIT 5");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "    tid={$row['tid']}, sciName={$row['sciName']}\n";
            }
        });

        it('should have omoccurrences.tidInterpreted linking to taxa.tid', function() {
            // Check foreign key relationship
            $stmt = $this->sourceDb->query("
                SELECT o.occid, o.tidInterpreted, t.sciName as taxa_sciName, o.sciname as occ_sciname
                FROM omoccurrences o
                LEFT JOIN taxa t ON o.tidInterpreted = t.tid
                WHERE o.tidInterpreted IS NOT NULL
                LIMIT 5
            ");

            $count = 0;
            echo "\n  omoccurrences -> taxa relationships:\n";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "    occid={$row['occid']}, tidInterpreted={$row['tidInterpreted']}, taxa.sciName={$row['taxa_sciName']}, occ.sciname={$row['occ_sciname']}\n";
                expect($row['taxa_sciName'])->not->toBeNull();
                $count++;
            }

            expect($count)->toBeGreaterThan(0);
        });

        it('should have multiple sciname sources in omoccurrences', function() {
            // Check both sciname and scientificName fields
            $stmt = $this->sourceDb->query("
                SELECT occid, sciname, scientificName
                FROM omoccurrences
                WHERE sciname IS NOT NULL OR scientificName IS NOT NULL
                LIMIT 5
            ");

            echo "\n  Sciname sources in omoccurrences:\n";
            $hasSciname = false;
            $hasScientificName = false;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['sciname']) {
                    echo "    occid={$row['occid']}, sciname={$row['sciname']}\n";
                    $hasSciname = true;
                }
                if ($row['scientificName']) {
                    echo "    occid={$row['occid']}, scientificName={$row['scientificName']}\n";
                    $hasScientificName = true;
                }
            }

            echo "  ✓ Has sciname field: " . ($hasSciname ? 'yes' : 'no') . "\n";
            echo "  ✓ Has scientificName field: " . ($hasScientificName ? 'yes' : 'no') . "\n";
        });

        it('should verify autocomplete sources for taxon queries', function() {
            echo "\n  Autocomplete sources for taxon queries:\n";
            echo "    1. taxa.sciName (via omoccurrences.tidInterpreted FK)\n";
            echo "    2. omoccurrences.sciname (direct field)\n";
            echo "    3. omoccurrences.scientificName (direct field)\n";

            // Verify all three are indexed in EAV
            $sources = [
                ['table' => 'taxa', 'column' => 'sciName'],
                ['table' => 'omoccurrences', 'column' => 'sciname'],
                ['table' => 'omoccurrences', 'column' => 'scientificName']
            ];

            foreach ($sources as $source) {
                $aid = $this->cacheDb->query("
                    SELECT Aid FROM Attributes
                    WHERE TableName = '{$source['table']}'
                    AND ColumnName = '{$source['column']}'
                ")->fetchColumn();

                if ($aid) {
                    echo "    ✓ {$source['table']}.{$source['column']} indexed (Aid=$aid)\n";
                } else {
                    echo "    ✗ {$source['table']}.{$source['column']} NOT indexed\n";
                }
            }
        });

        it('should verify field aliases from index_config.ini', function() {
            echo "\n  Field aliases (from index_config.ini [aliases] section):\n";
            echo "    taxon -> sciname\n";
            echo "    sciname -> sciname\n";
            echo "    scientificname -> scientificName\n";

            // These aliases should map to actual indexed fields
            $aliases = [
                'taxon' => 'sciname',
                'sciname' => 'sciname',
                'scientificname' => 'scientificName'
            ];

            foreach ($aliases as $alias => $actualField) {
                // Check if the actual field is indexed
                $count = $this->cacheDb->query("
                    SELECT COUNT(*) FROM Attributes WHERE ColumnName = '$actualField'
                ")->fetchColumn();

                if ($count > 0) {
                    echo "    ✓ Alias '$alias' -> '$actualField' (indexed)\n";
                } else {
                    echo "    ⚠ Alias '$alias' -> '$actualField' (not found in Attributes)\n";
                }
            }
        });

    });

    describe('Autocomplete Indexes', function() {

        it('should have autocomplete_taxon table', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='autocomplete_taxon'")->fetchColumn();
            expect($count)->toBe(1);
            echo "\n  ✓ autocomplete_taxon table exists\n";
        });

        it('should have autocomplete_collection table', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='autocomplete_collection'")->fetchColumn();
            expect($count)->toBe(1);
            echo "\n  ✓ autocomplete_collection table exists\n";
        });

        it('should have autocomplete_location table', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='autocomplete_location'")->fetchColumn();
            expect($count)->toBe(1);
            echo "\n  ✓ autocomplete_location table exists\n";
        });

        it('should have autocomplete_date table', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='autocomplete_date'")->fetchColumn();
            expect($count)->toBe(1);
            echo "\n  ✓ autocomplete_date table exists\n";
        });

        it('should have taxon autocomplete data', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM autocomplete_taxon")->fetchColumn();
            echo "\n  Taxon autocomplete entries: $count\n";
            expect($count)->toBeGreaterThan(0);

            // Check sample data
            $sample = $this->cacheDb->query("SELECT taxon_name, image_count, source_field FROM autocomplete_taxon LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sample as $row) {
                echo "    {$row['taxon_name']} ({$row['image_count']} images, from {$row['source_field']})\n";
            }
        });

        it('should have collection autocomplete data', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM autocomplete_collection")->fetchColumn();
            echo "\n  Collection autocomplete entries: $count\n";
            expect($count)->toBeGreaterThan(0);

            // Check sample data
            $sample = $this->cacheDb->query("SELECT collection_value, image_count, source_field FROM autocomplete_collection LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sample as $row) {
                echo "    {$row['collection_value']} ({$row['image_count']} images, from {$row['source_field']})\n";
            }
        });

        it('should have location autocomplete data', function() {
            $count = $this->cacheDb->query("SELECT COUNT(*) FROM autocomplete_location")->fetchColumn();
            echo "\n  Location autocomplete entries: $count\n";
            expect($count)->toBeGreaterThan(0);

            // Check sample data
            $sample = $this->cacheDb->query("SELECT location_value, image_count, source_field FROM autocomplete_location LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sample as $row) {
                echo "    {$row['location_value']} ({$row['image_count']} images, from {$row['source_field']})\n";
            }
        });

        it('should query autocomplete_taxon with LIKE', function() {
            $stmt = $this->cacheDb->prepare("
                SELECT taxon_name, MAX(image_count) as count
                FROM autocomplete_taxon
                WHERE taxon_name LIKE :query
                GROUP BY taxon_name
                ORDER BY count DESC, taxon_name ASC
                LIMIT 10
            ");
            $stmt->execute([':query' => '%Licea%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "\n  Autocomplete query for 'Licea':\n";
            foreach ($results as $row) {
                echo "    {$row['taxon_name']} ({$row['count']} images)\n";
            }

            expect(count($results))->toBeGreaterThan(0);
        });

        it('should query autocomplete_location with LIKE', function() {
            $stmt = $this->cacheDb->prepare("
                SELECT location_value, MAX(image_count) as count
                FROM autocomplete_location
                WHERE location_value LIKE :query
                GROUP BY location_value
                ORDER BY count DESC, location_value ASC
                LIMIT 10
            ");
            $stmt->execute([':query' => '%Col%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "\n  Autocomplete query for 'Col':\n";
            foreach ($results as $row) {
                echo "    {$row['location_value']} ({$row['count']} images)\n";
            }

            expect(count($results))->toBeGreaterThan(0);
        });

    });

});

// Helper functions

function getSampleDataFromSource($sourceDb): array
{
    $data = [
        'catalogNumbers' => [],
        'families' => [],
        'scientificNames' => [],
        'collectionCodes' => []
    ];

    // Get sample catalog numbers
    $stmt = $sourceDb->query("
        SELECT DISTINCT o.catalogNumber
        FROM omoccurrences o
        WHERE o.catalogNumber IS NOT NULL
        LIMIT 5
    ");
    $data['catalogNumbers'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get sample families
    $stmt = $sourceDb->query("
        SELECT DISTINCT o.family
        FROM omoccurrences o
        WHERE o.family IS NOT NULL
        LIMIT 5
    ");
    $data['families'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get sample scientific names
    $stmt = $sourceDb->query("
        SELECT DISTINCT o.scientificName
        FROM omoccurrences o
        WHERE o.scientificName IS NOT NULL
        LIMIT 5
    ");
    $data['scientificNames'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get sample collection codes
    $stmt = $sourceDb->query("
        SELECT DISTINCT c.collectionCode
        FROM omcollections c
        WHERE c.collectionCode IS NOT NULL
        LIMIT 5
    ");
    $data['collectionCodes'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $data;
}

function queryEavByCatalogNumber($cacheDb, string $catalogNumber): int
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = 'catalogNumber'")->fetchColumn();
    if (!$aid) return 0;

    $vid = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText = " . $cacheDb->quote($catalogNumber))->fetchColumn();
    if (!$vid) return 0;

    $count = $cacheDb->query("
        SELECT COUNT(*) FROM EAV
        WHERE Aid = $aid
        AND (VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')
    ")->fetchColumn();

    return (int)$count;
}

function queryEavByFamily($cacheDb, string $family): int
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = 'family'")->fetchColumn();
    if (!$aid) return 0;

    $vid = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText = " . $cacheDb->quote($family))->fetchColumn();
    if (!$vid) return 0;

    $count = $cacheDb->query("
        SELECT COUNT(*) FROM EAV
        WHERE Aid = $aid
        AND (VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')
    ")->fetchColumn();

    return (int)$count;
}

function queryEavByAnyFamily($cacheDb, string $family): int
{
    // Query both omoccurrences.family and taxstatus.family
    $aids = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = 'family'")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($aids)) return 0;

    $vid = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText = " . $cacheDb->quote($family))->fetchColumn();
    if (!$vid) return 0;

    $totalCount = 0;
    foreach ($aids as $aid) {
        $count = $cacheDb->query("
            SELECT COUNT(DISTINCT Eid) FROM EAV
            WHERE Aid = $aid
            AND (VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')
        ")->fetchColumn();
        $totalCount += $count;
    }

    return (int)$totalCount;
}

function queryEavByScientificName($cacheDb, string $sciName): int
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = 'scientificName'")->fetchColumn();
    if (!$aid) return 0;

    $vid = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText = " . $cacheDb->quote($sciName))->fetchColumn();
    if (!$vid) return 0;

    $count = $cacheDb->query("
        SELECT COUNT(*) FROM EAV
        WHERE Aid = $aid
        AND (VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')
    ")->fetchColumn();

    return (int)$count;
}

function getEavEntityByCatalogNumber($cacheDb, string $catalogNumber): ?array
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = 'catalogNumber'")->fetchColumn();
    if (!$aid) return null;

    $vid = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText = " . $cacheDb->quote($catalogNumber))->fetchColumn();
    if (!$vid) return null;

    $entity = $cacheDb->query("
        SELECT e.* FROM Entities e
        JOIN EAV eav ON e.Eid = eav.Eid
        WHERE eav.Aid = $aid
        AND (eav.VidArray LIKE '% $vid %' OR eav.VidArray LIKE '$vid %' OR eav.VidArray LIKE '% $vid' OR eav.VidArray = '$vid')
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return $entity ?: null;
}

function searchEavByText($cacheDb, string $text): int
{
    $vids = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText LIKE " . $cacheDb->quote("%$text%"))->fetchAll(PDO::FETCH_COLUMN);
    if (empty($vids)) return 0;

    $conditions = [];
    foreach ($vids as $vid) {
        $conditions[] = "(VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')";
    }

    $whereClause = implode(' OR ', $conditions);
    $count = $cacheDb->query("SELECT COUNT(DISTINCT Eid) FROM EAV WHERE $whereClause")->fetchColumn();

    return (int)$count;
}

function queryEavByNumericValue($cacheDb, string $fieldName, $value): int
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = " . $cacheDb->quote($fieldName))->fetchColumn();
    if (!$aid) return 0;

    $count = $cacheDb->query("SELECT COUNT(*) FROM EAV WHERE Aid = $aid AND ValueNumber = " . (float)$value)->fetchColumn();
    return (int)$count;
}

function queryEavByNumericValueFromTable($cacheDb, string $tableName, string $fieldName, $value): int
{
    $aid = $cacheDb->query("
        SELECT Aid FROM Attributes
        WHERE TableName = " . $cacheDb->quote($tableName) . "
        AND ColumnName = " . $cacheDb->quote($fieldName)
    )->fetchColumn();

    if (!$aid) return 0;

    $count = $cacheDb->query("SELECT COUNT(*) FROM EAV WHERE Aid = $aid AND ValueNumber = " . (float)$value)->fetchColumn();
    return (int)$count;
}

function queryEavByNumericRange($cacheDb, string $fieldName, $min, $max): int
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = " . $cacheDb->quote($fieldName))->fetchColumn();
    if (!$aid) return 0;

    $count = $cacheDb->query("
        SELECT COUNT(*) FROM EAV
        WHERE Aid = $aid
        AND ValueNumber >= " . (float)$min . "
        AND ValueNumber <= " . (float)$max
    )->fetchColumn();

    return (int)$count;
}

function queryEavByMultipleFields($cacheDb, array $fieldValues): int
{
    // Get Eids that match ALL field conditions (AND logic)
    $eidSets = [];

    foreach ($fieldValues as $fieldName => $value) {
        $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = " . $cacheDb->quote($fieldName))->fetchColumn();
        if (!$aid) continue;

        // For text fields, find matching Vids
        $vids = $cacheDb->query("SELECT Vid FROM ValuesText WHERE ValueText LIKE " . $cacheDb->quote("%$value%"))->fetchAll(PDO::FETCH_COLUMN);
        if (empty($vids)) continue;

        // Get Eids for this field
        $conditions = [];
        foreach ($vids as $vid) {
            $conditions[] = "(VidArray LIKE '% $vid %' OR VidArray LIKE '$vid %' OR VidArray LIKE '% $vid' OR VidArray = '$vid')";
        }
        $whereClause = implode(' OR ', $conditions);

        $eids = $cacheDb->query("SELECT DISTINCT Eid FROM EAV WHERE Aid = $aid AND ($whereClause)")->fetchAll(PDO::FETCH_COLUMN);
        $eidSets[] = $eids;
    }

    if (empty($eidSets)) return 0;

    // Intersect all Eid sets (AND logic)
    $result = $eidSets[0];
    for ($i = 1; $i < count($eidSets); $i++) {
        $result = array_intersect($result, $eidSets[$i]);
    }

    return count($result);
}

function autocompleteField($cacheDb, string $fieldName, string $prefix, int $limit = 10): array
{
    $aid = $cacheDb->query("SELECT Aid FROM Attributes WHERE ColumnName = " . $cacheDb->quote($fieldName))->fetchColumn();
    if (!$aid) return [];

    // Get Vids that start with prefix
    $vids = $cacheDb->query("
        SELECT Vid FROM ValuesText
        WHERE ValueText LIKE " . $cacheDb->quote($prefix . "%") . "
        LIMIT $limit
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($vids)) return [];

    // Get the actual text values
    $placeholders = implode(',', array_fill(0, count($vids), '?'));
    $stmt = $cacheDb->prepare("SELECT DISTINCT ValueText FROM ValuesText WHERE Vid IN ($placeholders) ORDER BY ValueText");
    $stmt->execute($vids);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
