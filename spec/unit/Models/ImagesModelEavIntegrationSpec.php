<?php

use Symbiota\Helpers\Models\ImagesModelEav;

describe('ImagesModelEav Integration Tests', function() {
    
    beforeEach(function() {
        // Setup test environment
        $this->testDir = sys_get_temp_dir() . '/eav_refactored_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        // Create test config
        $this->configPath = $this->testDir . '/test_config.ini';
        $configContent = <<<INI
[_config]
root_table = media
root_id_column = mediaID

[media]
primary_key = "mediaID"
foreign_key[] = "occid:omoccurrences.occid"
columns[] = mediaID:numeric
columns[] = url:text:display
columns[] = originalUrl:text:display
columns[] = thumbnailUrl:text:display
columns[] = occid:numeric
columns[] = sortsequence:numeric
columns[] = photographer:text:whole
columns[] = caption:text:split

[omoccurrences]
primary_key = "occid"
foreign_key[] = "collid:omcollections.collid"
foreign_key[] = "tidInterpreted:taxa.tid"
columns[] = occid:numeric
columns[] = catalogNumber:text:whole
columns[] = sciname:text:whole
columns[] = family:text:whole
columns[] = genus:text:whole
columns[] = locality:text:split
columns[] = decimalLatitude:numeric
columns[] = decimalLongitude:numeric
columns[] = collid:numeric
columns[] = tidInterpreted:numeric

[omcollections]
primary_key = "collid"
columns[] = collid:numeric
columns[] = collectionName:text:split
columns[] = institutionCode:text:whole

[taxa]
primary_key = "tid"
columns[] = tid:numeric
columns[] = sciName:text:whole
columns[] = family:text:whole

[taxstatus]
primary_key = "tid"
foreign_key[] = "tid:taxa.tid"
foreign_key[] = "parenttid:taxa.tid"
columns[] = tid:numeric
columns[] = parenttid:numeric
columns[] = taxonAuthor:text:whole
INI;
        file_put_contents($this->configPath, $configContent);
        
        // Create source database with test data
        $this->sourceDbPath = $this->testDir . '/source.db';
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create media table
        $this->sourceDb->exec('
            CREATE TABLE media (
                mediaID INTEGER PRIMARY KEY,
                occid INTEGER,
                url TEXT,
                originalUrl TEXT,
                thumbnailUrl TEXT,
                sortsequence INTEGER,
                photographer TEXT,
                caption TEXT
            )
        ');
        
        // Create omoccurrences table
        $this->sourceDb->exec('
            CREATE TABLE omoccurrences (
                occid INTEGER PRIMARY KEY,
                collid INTEGER,
                tidInterpreted INTEGER,
                catalogNumber TEXT,
                sciname TEXT,
                family TEXT,
                genus TEXT,
                locality TEXT,
                decimalLatitude REAL,
                decimalLongitude REAL
            )
        ');
        
        // Create omcollections table
        $this->sourceDb->exec('
            CREATE TABLE omcollections (
                collid INTEGER PRIMARY KEY,
                collectionName TEXT,
                institutionCode TEXT
            )
        ');
        
        // Create taxa table
        $this->sourceDb->exec('
            CREATE TABLE taxa (
                tid INTEGER PRIMARY KEY,
                sciName TEXT,
                family TEXT
            )
        ');
        
        // Create taxstatus table
        $this->sourceDb->exec('
            CREATE TABLE taxstatus (
                tid INTEGER,
                parenttid INTEGER,
                taxonAuthor TEXT
            )
        ');
        
        // Insert test data
        // Insert collections
        $this->sourceDb->exec("
            INSERT INTO omcollections (collid, collectionName, institutionCode) VALUES
            (1, 'Denver Botanic Gardens Herbarium', 'DBG'),
            (2, 'University of Colorado Museum', 'UCM')
        ");

        // Insert taxa
        $this->sourceDb->exec("
            INSERT INTO taxa (tid, sciName, family) VALUES
            (100, 'Quercus alba', 'Fagaceae'),
            (200, 'Acer rubrum', 'Sapindaceae')
        ");

        // Insert taxstatus
        $this->sourceDb->exec("
            INSERT INTO taxstatus (tid, parenttid, taxonAuthor) VALUES
            (100, 50, 'L.'),
            (200, 60, 'L.')
        ");

        // Insert occurrences
        $this->sourceDb->exec("
            INSERT INTO omoccurrences (occid, collid, tidInterpreted, catalogNumber, sciname, family, genus, locality, decimalLatitude, decimalLongitude) VALUES
            (1001, 1, 100, 'DBG-001', 'Quercus alba', 'Fagaceae', 'Quercus', 'Rocky Mountain National Park Trail Ridge Road', 40.3428, -105.6836),
            (1002, 1, 200, 'DBG-002', 'Acer rubrum', 'Sapindaceae', 'Acer', 'Boulder Creek Path near downtown', 40.0150, -105.2705),
            (1003, 2, 100, 'UCM-100', 'Quercus alba', 'Fagaceae', 'Quercus', 'Garden of the Gods red rock formations', 38.8719, -104.8861)
        ");

        // Insert media
        $this->sourceDb->exec("
            INSERT INTO media (mediaID, occid, url, originalUrl, thumbnailUrl, sortsequence, photographer, caption) VALUES
            (1, 1001, 'https://example.org/images/img1.jpg', 'https://example.org/original/img1.jpg', 'https://example.org/thumb/img1.jpg', 1, 'John Doe', 'White oak leaf detail close up'),
            (2, 1001, 'https://example.org/images/img2.jpg', 'https://example.org/original/img2.jpg', 'https://example.org/thumb/img2.jpg', 2, 'Jane Smith', 'White oak bark texture pattern'),
            (3, 1002, 'https://example.org/images/img3.jpg', 'https://example.org/original/img3.jpg', 'https://example.org/thumb/img3.jpg', 1, 'Bob Johnson', 'Red maple autumn foliage color'),
            (4, 1003, 'https://example.org/images/img4.jpg', 'https://example.org/original/img4.jpg', 'https://example.org/thumb/img4.jpg', 1, 'Alice Williams', 'Oak tree full canopy view')
        ");

        // Create model instance
        $this->workDbPath = $this->testDir . '/work.db';
        $this->cacheDbPath = $this->testDir . '/cache.db';

        $this->model = new ImagesModelEav([
            'config_path' => $this->configPath,
            'work_db_path' => $this->workDbPath,
            'cache_db_path' => $this->cacheDbPath,
            'source_db_path' => $this->sourceDbPath
        ]);
    });

    afterEach(function() {
        // Cleanup
        $this->sourceDb = null;

        // Clean up all files in test directory
        if (isset($this->testDir) && is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    });
    
    describe('Full EAV Pipeline', function() {

        it('should complete full pipeline without errors', function() {
            // Create databases
            $this->model->createDatabases();

            // Build attributes
            $this->model->buildAttributes();

            // Build EAV index using new Pure SQL method
            $result = $this->model->buildEavIndexPureSQL();

            // Check for error response
            if (isset($result['type']) && $result['type'] === 'error') {
                throw new Exception("buildEavIndexPureSQL failed: " . ($result['message'] ?? 'Unknown error'));
            }

            expect($result)->toContainKey('entityCount');
            expect($result['entityCount'])->toBeGreaterThan(0);
        });

    });
    
    describe('Issue #1: URLs in ValuesText', function() {

        it('should NOT store display field URLs in ValuesText', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();
            
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Check that URLs are NOT in ValuesText
            $urlCount = $cacheDb->query("
                SELECT COUNT(*) FROM ValuesText 
                WHERE ValueText LIKE '%example.org%'
                   OR ValueText LIKE '%https://%'
                   OR ValueText LIKE '%.jpg%'
            ")->fetchColumn();
            
            expect($urlCount)->toBe(0);
        });
        
        it('should store display field URLs in Entities table', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();
            
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Check that URLs ARE in Entities table
            $entity = $cacheDb->query("
                SELECT url, originalUrl, thumbnailUrl 
                FROM Entities 
                WHERE Eid = 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            expect($entity['url'])->toBe('https://example.org/images/img1.jpg');
            expect($entity['originalUrl'])->toBe('https://example.org/original/img1.jpg');
            expect($entity['thumbnailUrl'])->toBe('https://example.org/thumb/img1.jpg');
        });

    });

    describe('Issue #2: Numeric Values in ValuesText', function() {

        it('should NOT store numeric field values in ValuesText', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Get all numeric values from source data
            $numericValues = ['1', '2', '3', '4', '1001', '1002', '1003', '100', '200', '1', '2', '50', '60'];

            // Check that numeric values are NOT in ValuesText
            $placeholders = implode(',', array_fill(0, count($numericValues), '?'));
            $stmt = $cacheDb->prepare("
                SELECT COUNT(*) FROM ValuesText
                WHERE ValueText IN ($placeholders)
            ");
            $stmt->execute($numericValues);
            $count = $stmt->fetchColumn();

            expect($count)->toBe(0);
        });

        it('should store numeric field values in EAV.ValueNumber', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Check that numeric values ARE in EAV.ValueNumber
            $numericCount = $cacheDb->query("
                SELECT COUNT(*) FROM EAV
                WHERE ValueNumber IS NOT NULL
            ")->fetchColumn();

            expect($numericCount)->toBeGreaterThan(0);

            // Verify specific numeric value (mediaID = 1)
            $result = $cacheDb->query("
                SELECT e.ValueNumber
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                WHERE a.ColumnName = 'mediaID' AND e.Eid = 1
            ")->fetch(PDO::FETCH_ASSOC);

            expect($result['ValueNumber'])->toBe(1.0);
        });

    });

    describe('Issue #3: Missing DataType in Attributes', function() {

        it('should include DataType column in Attributes table', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Check schema includes DataType
            $schema = $cacheDb->query("
                SELECT sql FROM sqlite_master
                WHERE type='table' AND name='Attributes'
            ")->fetchColumn();

            expect($schema)->toContain('DataType');
        });

        it('should populate DataType with correct values', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Check numeric field has DataType = 'numeric'
            $result = $cacheDb->query("
                SELECT DataType FROM Attributes
                WHERE ColumnName = 'mediaID'
            ")->fetch(PDO::FETCH_ASSOC);

            expect($result['DataType'])->toBe('numeric');

            // Check text field has DataType = 'text'
            $result = $cacheDb->query("
                SELECT DataType FROM Attributes
                WHERE ColumnName = 'sciname'
            ")->fetch(PDO::FETCH_ASSOC);

            expect($result['DataType'])->toBe('text');
        });

    });

    describe('Normalized Schema: (Vid, VidOrder) for :split tokenization', function() {

        it('should store multiple rows with VidOrder for :split strategy', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Find EAV rows with split strategy (caption field)
            $rows = $cacheDb->query("
                SELECT e.Vid, e.VidOrder, v.ValueText
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                JOIN ValuesText v ON e.Vid = v.Vid
                WHERE a.ColumnName = 'caption' AND e.Eid = 1
                ORDER BY e.VidOrder
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Should have multiple rows (caption has 6 words)
            expect(count($rows))->toBeGreaterThan(1);

            // Each row should have a Vid and VidOrder
            foreach ($rows as $row) {
                expect($row['Vid'])->toBeA('integer');
                expect($row['VidOrder'])->toBeA('integer');
                expect($row['ValueText'])->toBeA('string');
            }
        });

        it('should allow querying with normalized Vid column', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();

            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);

            // Search for "oak" in caption using normalized schema
            $result = $cacheDb->query("
                SELECT DISTINCT e.Eid
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                JOIN ValuesText v ON e.Vid = v.Vid
                WHERE a.ColumnName = 'caption'
                  AND LOWER(v.ValueText) = 'oak'
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            // Should find entity 1 (has "oak" in caption)
            expect($result)->toBeTruthy();
            expect($result['Eid'])->toBe(1);
        });

    });

    describe('Performance and Statistics', function() {

        it('should provide accurate cache statistics', function() {
            $this->model->createDatabases();
            $this->model->buildAttributes();
            $this->model->buildEavIndexPureSQL();

            $result = $this->model->getInfo([]);

            expect($result['type'])->toBe('success');
            expect($result['content'])->toContain('Entities: 4');
            expect($result['content'])->toContain('Attributes:');
            expect($result['content'])->toContain('ValuesText:');
            expect($result['content'])->toContain('EAV Rows:');
        });

    });
});

