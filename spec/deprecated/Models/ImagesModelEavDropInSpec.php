<?php

use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\DatabaseManager;
use Symbiota\Helpers\Core\Environment;
use PDO;

describe('ImagesModel EAV Drop-in Replacement', function() {
    
    beforeEach(function() {
        // Set up test environment
        $this->testDir = sys_get_temp_dir() . '/eav_dropin_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        $this->sourceDbPath = $this->testDir . '/source.db';
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $this->configPath = $this->testDir . '/config.ini';
        
        // Create source database with test data
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables
        $this->sourceDb->exec("
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
        ");
        
        $this->sourceDb->exec("
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
        ");
        
        $this->sourceDb->exec("
            CREATE TABLE omcollections (
                collid INTEGER PRIMARY KEY,
                collectionName TEXT,
                institutionCode TEXT
            )
        ");
        
        $this->sourceDb->exec("
            CREATE TABLE taxa (
                tid INTEGER PRIMARY KEY,
                sciName TEXT,
                family TEXT
            )
        ");
        
        $this->sourceDb->exec("
            CREATE TABLE taxstatus (
                tid INTEGER PRIMARY KEY,
                parenttid INTEGER,
                taxonAuthor TEXT
            )
        ");
        
        // Insert test data
        $this->sourceDb->exec("
            INSERT INTO omcollections (collid, collectionName, institutionCode) VALUES
            (1, 'Test Collection', 'TEST')
        ");
        
        $this->sourceDb->exec("
            INSERT INTO taxa (tid, sciName, family) VALUES
            (100, 'Quercus alba', 'Fagaceae')
        ");
        
        $this->sourceDb->exec("
            INSERT INTO taxstatus (tid, parenttid, taxonAuthor) VALUES
            (100, 50, 'L.')
        ");
        
        $this->sourceDb->exec("
            INSERT INTO omoccurrences (occid, collid, tidInterpreted, catalogNumber, sciname, family, genus, locality, decimalLatitude, decimalLongitude) VALUES
            (1001, 1, 100, 'TEST-001', 'Quercus alba', 'Fagaceae', 'Quercus', 'Test Locality', 40.0, -105.0)
        ");
        
        $this->sourceDb->exec("
            INSERT INTO media (mediaID, occid, url, originalUrl, thumbnailUrl, sortsequence, photographer, caption) VALUES
            (1, 1001, 'https://example.org/img1.jpg', 'https://example.org/orig1.jpg', 'https://example.org/thumb1.jpg', 1, 'Test Photographer', 'Test caption with multiple words')
        ");
        
        // Create test config
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
columns[] = collectionName:text:whole
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
        
        // Create ImagesModel instance
        $this->model = new ImagesModel();
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
    
    describe('buildEavIndexNew()', function() {
        
        it('should build EAV index using new implementation', function() {
            // Call the new method through reflection (since it's private)
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('buildEavIndexNew');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath,
                'source-db-path' => $this->sourceDbPath
            ]);
            
            expect($result['type'])->toBe('success');
            expect(file_exists($this->cacheDbPath))->toBe(true);
        });
        
        it('should create all required EAV tables', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('buildEavIndexNew');
            $method->setAccessible(true);
            
            $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath,
                'source-db-path' => $this->sourceDbPath
            ]);
            
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Check tables exist
            $tables = $cacheDb->query("
                SELECT name FROM sqlite_master 
                WHERE type='table' 
                ORDER BY name
            ")->fetchAll(PDO::FETCH_COLUMN);
            
            expect(in_array('Entities', $tables))->toBe(true);
            expect(in_array('Attributes', $tables))->toBe(true);
            expect(in_array('ValuesText', $tables))->toBe(true);
            expect(in_array('EAV', $tables))->toBe(true);
        });
        
        it('should verify all 4 issues are fixed', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('buildEavIndexNew');
            $method->setAccessible(true);
            
            $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath,
                'source-db-path' => $this->sourceDbPath
            ]);
            
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Issue #1: URLs should NOT be in ValuesText
            $urlCount = $cacheDb->query("
                SELECT COUNT(*) FROM ValuesText 
                WHERE ValueText LIKE '%example.org%'
            ")->fetchColumn();
            expect($urlCount)->toBe(0);
            
            // Issue #2: Numeric values should NOT be in ValuesText
            $numericCount = $cacheDb->query("
                SELECT COUNT(*) FROM ValuesText 
                WHERE ValueText IN ('1', '1001', '100')
            ")->fetchColumn();
            expect($numericCount)->toBe(0);
            
            // Issue #3: Attributes should have DataType column
            $schema = $cacheDb->query("
                SELECT sql FROM sqlite_master 
                WHERE type='table' AND name='Attributes'
            ")->fetchColumn();
            expect($schema)->toContain('DataType');
            
            // Issue #4: VidArray should contain space-separated values
            $result = $cacheDb->query("
                SELECT e.VidArray 
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                WHERE a.ColumnName = 'caption' AND e.Eid = 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['VidArray']) {
                $vids = explode(' ', trim($result['VidArray']));
                expect(count($vids))->toBeGreaterThan(1);
            }
        });
        
    });
    
});

