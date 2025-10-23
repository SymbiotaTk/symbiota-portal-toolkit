<?php

use Symbiota\Helpers\Models\ImagesModel;

describe('ImagesModel EAV Search - Minimal Fixture', function() {

    beforeAll(function() {
        // Create in-memory cache database with minimal test data
        $this->cacheDb = new PDO('sqlite::memory:');
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create cache tables
        $this->cacheDb->exec("
            CREATE TABLE Entities (
                Eid INTEGER PRIMARY KEY,
                EntityValue TEXT NOT NULL UNIQUE,
                url TEXT,
                originalUrl TEXT,
                thumbnailUrl TEXT
            ) WITHOUT ROWID;
        ");

        $this->cacheDb->exec("
            CREATE TABLE Attributes (
                Aid INTEGER PRIMARY KEY,
                TableName TEXT NOT NULL,
                ColumnName TEXT NOT NULL,
                DataType TEXT NOT NULL,
                TokenStrategy TEXT DEFAULT 'whole',
                UNIQUE(TableName, ColumnName)
            ) WITHOUT ROWID;
        ");

        $this->cacheDb->exec("
            CREATE TABLE ValuesText (
                Vid INTEGER PRIMARY KEY AUTOINCREMENT,
                ValueText TEXT NOT NULL UNIQUE
            );
        ");

        $this->cacheDb->exec("
            CREATE TABLE EAV (
                Eid INTEGER NOT NULL,
                Aid INTEGER NOT NULL,
                Vid INTEGER,
                VidOrder INTEGER,
                ValueNumber REAL,
                FOREIGN KEY (Eid) REFERENCES Entities(Eid),
                FOREIGN KEY (Aid) REFERENCES Attributes(Aid),
                FOREIGN KEY (Vid) REFERENCES ValuesText(Vid),
                CHECK ((Vid IS NULL) != (ValueNumber IS NULL))
            );
        ");

        $this->cacheDb->exec("
            CREATE TABLE Config (
                ConfigKey TEXT PRIMARY KEY,
                ConfigValue TEXT NOT NULL
            ) WITHOUT ROWID;
        ");

        // Create indexes
        $this->cacheDb->exec("CREATE INDEX idx_eav_aid_vid ON EAV(Aid, Vid) WHERE Vid IS NOT NULL");
        $this->cacheDb->exec("CREATE INDEX idx_eav_vid ON EAV(Vid) WHERE Vid IS NOT NULL");
        $this->cacheDb->exec("CREATE INDEX idx_eav_eid ON EAV(Eid)");

        // Insert test entities
        $this->cacheDb->exec("
            INSERT INTO Entities (Eid, EntityValue, url, originalUrl, thumbnailUrl) VALUES
            (1, '1', 'https://example.org/img1.jpg', 'https://example.org/orig1.jpg', 'https://example.org/thumb1.jpg'),
            (2, '2', 'https://example.org/img2.jpg', 'https://example.org/orig2.jpg', 'https://example.org/thumb2.jpg'),
            (3, '3', 'https://example.org/img3.jpg', 'https://example.org/orig3.jpg', 'https://example.org/thumb3.jpg'),
            (4, '4', 'https://example.org/img4.jpg', 'https://example.org/orig4.jpg', 'https://example.org/thumb4.jpg'),
            (5, '5', 'https://example.org/img5.jpg', 'https://example.org/orig5.jpg', 'https://example.org/thumb5.jpg')
        ");

        // Insert test attributes
        $this->cacheDb->exec("
            INSERT INTO Attributes (Aid, TableName, ColumnName, DataType, TokenStrategy) VALUES
            (1, 'omoccurrences', 'family', 'text', 'whole'),
            (2, 'omoccurrences', 'genus', 'text', 'whole'),
            (3, 'omoccurrences', 'sciname', 'text', 'whole'),
            (4, 'omoccurrences', 'catalogNumber', 'text', 'whole'),
            (5, 'omoccurrences', 'locality', 'text', 'split'),
            (6, 'omoccurrences', 'stateProvince', 'text', 'whole'),
            (7, 'omoccurrences', 'country', 'text', 'whole'),
            (8, 'omcollections', 'collectionName', 'text', 'split'),
            (9, 'omcollections', 'collectionCode', 'text', 'whole'),
            (10, 'omoccurrences', 'occid', 'numeric', 'whole')
        ");

        // Insert test values (lowercase as per actual implementation)
        $this->cacheDb->exec("
            INSERT INTO ValuesText (Vid, ValueText) VALUES
            (1, 'russulaceae'),
            (2, 'boletaceae'),
            (3, 'agaricaceae'),
            (4, 'russula'),
            (5, 'boletus'),
            (6, 'agaricus'),
            (7, 'russula aeruginea'),
            (8, 'boletus edulis'),
            (9, 'agaricus augustus'),
            (10, 'dbg-001'),
            (11, 'dbg-002'),
            (12, 'ucm-100'),
            (13, 'gunnison'),
            (14, 'national'),
            (15, 'forest'),
            (16, 'colorado'),
            (17, 'michigan'),
            (18, 'united'),
            (19, 'states'),
            (20, 'america'),
            (21, 'denver'),
            (22, 'botanic'),
            (23, 'gardens'),
            (24, 'mich'),
            (25, 'dbg')
        ");

        // Insert EAV data
        // Entity 1: Russula from Colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 1, 1, NULL, NULL)"); // family=russulaceae
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 2, 4, NULL, NULL)"); // genus=russula
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 3, 7, NULL, NULL)"); // sciname=russula aeruginea
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 4, 10, NULL, NULL)"); // catalogNumber=dbg-001
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 5, 13, 1, NULL)"); // locality=gunnison (token 1)
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 5, 14, 2, NULL)"); // locality=national (token 2)
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 5, 15, 3, NULL)"); // locality=forest (token 3)
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 6, 16, NULL, NULL)"); // stateProvince=colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 9, 25, NULL, NULL)"); // collectionCode=dbg
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (1, 10, NULL, NULL, 1001)"); // occid=1001

        // Entity 2: Russula from Michigan
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 1, 1, NULL, NULL)"); // family=russulaceae
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 2, 4, NULL, NULL)"); // genus=russula
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 3, 7, NULL, NULL)"); // sciname=russula aeruginea
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 4, 11, NULL, NULL)"); // catalogNumber=dbg-002
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 6, 17, NULL, NULL)"); // stateProvince=michigan
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 9, 24, NULL, NULL)"); // collectionCode=mich
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (2, 10, NULL, NULL, 1002)"); // occid=1002

        // Entity 3: Boletus from Colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 1, 2, NULL, NULL)"); // family=boletaceae
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 2, 5, NULL, NULL)"); // genus=boletus
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 3, 8, NULL, NULL)"); // sciname=boletus edulis
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 4, 12, NULL, NULL)"); // catalogNumber=ucm-100
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 6, 16, NULL, NULL)"); // stateProvince=colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 9, 25, NULL, NULL)"); // collectionCode=dbg
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (3, 10, NULL, NULL, 1003)"); // occid=1003

        // Entity 4: Agaricus from Colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 1, 3, NULL, NULL)"); // family=agaricaceae
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 2, 6, NULL, NULL)"); // genus=agaricus
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 3, 9, NULL, NULL)"); // sciname=agaricus augustus
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 6, 16, NULL, NULL)"); // stateProvince=colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 9, 25, NULL, NULL)"); // collectionCode=dbg
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (4, 10, NULL, NULL, 1004)"); // occid=1004

        // Entity 5: Russula from Colorado (different species)
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (5, 1, 1, NULL, NULL)"); // family=russulaceae
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (5, 2, 4, NULL, NULL)"); // genus=russula
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (5, 6, 16, NULL, NULL)"); // stateProvince=colorado
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (5, 9, 25, NULL, NULL)"); // collectionCode=dbg
        $this->cacheDb->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES (5, 10, NULL, NULL, 1005)"); // occid=1005

        // Create ImagesModel instance
        $this->model = new ImagesModel();

        // Save cache DB to temp file (needed for model to access it)
        $this->testDir = sys_get_temp_dir() . '/eav_search_minimal_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $this->cacheDb->exec("VACUUM INTO '{$this->cacheDbPath}'");
        
        // Reopen as file-based DB
        $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    });
    
    afterAll(function() {
        $this->cacheDb = null;
        
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

    // Helper method to execute search
    beforeEach(function() {
        $this->executeSearch = function($query, $queryAnd = [], $queryOr = [], $limit = 100) {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('searchEavIndex');
            $method->setAccessible(true);

            return $method->invoke(
                $this->model,
                $this->cacheDb,
                $query,
                $queryAnd,
                $queryOr,
                $limit,
                0, // offset
                []  // sortBy
            );
        };
    });

    describe('Cache Structure', function() {

        it('should have 5 entities', function() {
            $count = $this->cacheDb->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($count)->toBe(5);
        });

        it('should have 10 attributes', function() {
            $count = $this->cacheDb->query('SELECT COUNT(*) FROM Attributes')->fetchColumn();
            expect($count)->toBe(10);
        });

        it('should have family attribute', function() {
            $family = $this->cacheDb->query("SELECT ColumnName FROM Attributes WHERE ColumnName = 'family'")->fetchColumn();
            expect($family)->toBe('family');
        });

    });

    describe('Basic Field Search', function() {

        it('should find records by family', function() {
            $results = ($this->executeSearch)('family:russulaceae', [], [], 100);
            expect(count($results))->toBe(3); // Entities 1, 2, 5
            
            foreach ($results as $entity) {
                expect(strtolower($entity['family'] ?? ''))->toBe('russulaceae');
            }
        });

        it('should find records by genus', function() {
            $results = ($this->executeSearch)('genus:russula', [], [], 100);
            expect(count($results))->toBe(3); // Entities 1, 2, 5
            
            foreach ($results as $entity) {
                expect(strtolower($entity['genus'] ?? ''))->toBe('russula');
            }
        });

        it('should find records by catalogNumber', function() {
            $results = ($this->executeSearch)('catalogNumber:dbg-001', [], [], 100);
            expect(count($results))->toBe(1);
            expect($results[0]['catalogNumber'])->toBe('dbg-001');
        });

    });

    describe('Case-Insensitive Search', function() {

        it('should match lowercase', function() {
            $results = ($this->executeSearch)('family:russulaceae', [], [], 100);
            expect(count($results))->toBe(3);
        });

        it('should match uppercase', function() {
            $results = ($this->executeSearch)('family:RUSSULACEAE', [], [], 100);
            expect(count($results))->toBe(3);
        });

        it('should match mixed case', function() {
            $results = ($this->executeSearch)('family:RuSsUlAcEaE', [], [], 100);
            expect(count($results))->toBe(3);
        });

        it('should return same results regardless of case', function() {
            $lower = ($this->executeSearch)('family:russulaceae', [], [], 100);
            $upper = ($this->executeSearch)('family:RUSSULACEAE', [], [], 100);
            
            expect(count($lower))->toBe(count($upper));
        });

    });

    describe('AND Logic - The Bug Report', function() {

        it('should find russula records', function() {
            $results = ($this->executeSearch)('genus:russula', [], [], 100);
            expect(count($results))->toBe(3); // Entities 1, 2, 5
        });

        it('should find MICH collection records', function() {
            $results = ($this->executeSearch)('collectionCode:MICH', [], [], 100);
            expect(count($results))->toBe(1); // Entity 2
        });

        it('should find russula AND collectionCode:MICH', function() {
            // This is the bug: --query="genus:russula" --queryAnd="collectionCode:MICH"
            $results = ($this->executeSearch)('genus:russula', ['collectionCode:MICH'], [], 100);
            expect(count($results))->toBe(1); // Should find Entity 2
            expect($results[0]['genus'])->toBe('russula');
            expect($results[0]['collectionCode'])->toBe('mich');
        });

        it('should find russula AND stateProvince:colorado', function() {
            $results = ($this->executeSearch)('genus:russula', ['stateProvince:colorado'], [], 100);
            expect(count($results))->toBe(2); // Entities 1, 5
        });

    });

});

