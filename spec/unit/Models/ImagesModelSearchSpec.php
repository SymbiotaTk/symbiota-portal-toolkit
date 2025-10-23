<?php

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Core\ApplicationPaths;
use PDO;

describe('ImagesModel Search Implementations', function() {

    beforeAll(function() {
        // Set up paths
        $this->testDir = sys_get_temp_dir() . '/eav_search_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->eavCacheDbPath = $this->testDir . '/test_eav_cache.db';
        $this->hybridCacheDbPath = $this->testDir . '/test_hybrid_cache.db';

        // Create test config
        $this->configPath = $this->testDir . '/test_config.ini';
        $configContent = <<<'INI'
[_config]
root_table = media
root_id_column = mediaID

[aliases]
taxon = sciname

[media]
primary_key = "mediaID"
foreign_key[] = "occid:omoccurrences.occid"
columns[] = mediaID:numeric
columns[] = url:text:display
columns[] = occid:numeric

[omoccurrences]
primary_key = "occid"
columns[] = occid:numeric
columns[] = family:text:whole
columns[] = genus:text:whole
INI;
        file_put_contents($this->configPath, $configContent);

        // Clean up any existing test databases
        if (file_exists($this->eavCacheDbPath)) {
            unlink($this->eavCacheDbPath);
        }
        if (file_exists($this->hybridCacheDbPath)) {
            unlink($this->hybridCacheDbPath);
        }

        // Build EAV cache for testing
        $db = new \PDO('sqlite:' . $this->eavCacheDbPath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create EAV schema
        $db->exec('CREATE TABLE Entities (
            Eid INTEGER PRIMARY KEY AUTOINCREMENT,
            EntityValue TEXT,
            url TEXT,
            originalUrl TEXT,
            thumbnailUrl TEXT
        )');

        $db->exec('CREATE TABLE Attributes (
            Aid INTEGER PRIMARY KEY AUTOINCREMENT,
            TableName TEXT,
            ColumnName TEXT,
            TokenStrategy TEXT,
            IsNumeric INTEGER DEFAULT 0
        )');

        $db->exec('CREATE TABLE ValuesText (
            Vid INTEGER PRIMARY KEY AUTOINCREMENT,
            ValueText TEXT UNIQUE
        )');

        $db->exec('CREATE TABLE EAV (
            Eid INTEGER,
            Aid INTEGER,
            Vid INTEGER,
            VidOrder INTEGER,
            ValueNumber REAL,
            PRIMARY KEY (Eid, Aid, Vid, VidOrder)
        )');

        // Insert test data
        $db->exec("INSERT INTO Entities (Eid, EntityValue, url) VALUES
            (1, '1001', 'http://example.com/img1.jpg'),
            (2, '1002', 'http://example.com/img2.jpg'),
            (3, '1003', 'http://example.com/img3.jpg')
        ");

        $db->exec("INSERT INTO Attributes (Aid, TableName, ColumnName, TokenStrategy) VALUES
            (1, 'omoccurrences', 'family', 'whole'),
            (2, 'omoccurrences', 'genus', 'whole')
        ");

        $db->exec("INSERT INTO ValuesText (Vid, ValueText) VALUES
            (1, 'agaricaceae'),
            (2, 'amanitaceae'),
            (3, 'agaricus'),
            (4, 'amanita'),
            (5, 'tulostoma')
        ");

        $db->exec("INSERT INTO EAV (Eid, Aid, Vid, VidOrder) VALUES
            (1, 1, 1, NULL),  -- img1: family=Agaricaceae
            (1, 2, 3, NULL),  -- img1: genus=Agaricus
            (2, 1, 2, NULL),  -- img2: family=Amanitaceae
            (2, 2, 4, NULL),  -- img2: genus=Amanita
            (3, 1, 1, NULL),  -- img3: family=Agaricaceae
            (3, 2, 5, NULL)   -- img3: genus=Tulostoma
        ");

        // Create indexes
        $db->exec('CREATE INDEX idx_eav_eid ON EAV(Eid)');
        $db->exec('CREATE INDEX idx_eav_aid ON EAV(Aid)');
        $db->exec('CREATE INDEX idx_eav_vid ON EAV(Vid)');

        // Build Hybrid cache for testing
        $hybridDb = new \PDO('sqlite:' . $this->hybridCacheDbPath);
        $hybridDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create autocomplete tables
        $hybridDb->exec('CREATE TABLE autocomplete_taxon (
            taxon_name TEXT,
            image_count INTEGER,
            source_field TEXT
        )');

        $hybridDb->exec('CREATE TABLE autocomplete_collection (
            collection_value TEXT,
            image_count INTEGER,
            source_field TEXT
        )');

        // Insert test data
        $hybridDb->exec("INSERT INTO autocomplete_taxon (taxon_name, image_count, source_field) VALUES
            ('Agaricus campestris', 1, 'sciname'),
            ('Amanita muscaria', 1, 'sciname')
        ");

        $hybridDb->exec("INSERT INTO autocomplete_collection (collection_value, image_count, source_field) VALUES
            ('Denver Botanic Gardens', 2, 'collectionName'),
            ('University of Colorado Museum', 1, 'collectionName')
        ");

        // Initialize both models
        $this->eavModel = new ImagesModelEav([
            'config_path' => $this->configPath,
            'cache_db_path' => $this->eavCacheDbPath
        ]);

        // For testing, Hybrid model uses the same EAV cache database
        // In production, it would query MySQL directly
        $this->hybridModel = new ImagesModelHybrid([
            'config_path' => $this->configPath,
            'cache_db_path' => $this->eavCacheDbPath  // Use same DB for testing
        ]);
    });

    afterAll(function() {
        // Clean up test directory
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

    describe('EAV Model Search - Basic Queries', function() {

        it('returns results for simple field:value query', function() {
            $result = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 10,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->toContain('http://example.com/img3.jpg');
        });

        it('returns error for empty query', function() {
            $result = $this->eavModel->search([
                'query' => '',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No search query provided');
        });

        it('returns empty results for non-matching query', function() {
            $result = $this->eavModel->search([
                'query' => 'family:nonexistent',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            // Should not contain any of our test image URLs
            expect($result['content'])->not->toContain('http://example.com/img1.jpg');
            expect($result['content'])->not->toContain('http://example.com/img2.jpg');
        });

        it('searches genus field correctly', function() {
            $result = $this->eavModel->search([
                'query' => 'genus:agaricus',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->not->toContain('http://example.com/img2.jpg');
        });

        it('is case-insensitive', function() {
            $result1 = $this->eavModel->search([
                'query' => 'family:AGARICACEAE',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);
            $result2 = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            // Both should return same results
            expect($result1['type'])->toBe('htmx');
            expect($result2['type'])->toBe('htmx');
            expect($result1['content'])->toContain('http://example.com/img1.jpg');
            expect($result2['content'])->toContain('http://example.com/img1.jpg');
        });
    });

    describe('EAV Model Search - Pagination', function() {

        it('handles limit parameter', function() {
            $result = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 1,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('offset=1');
        });

        it('handles offset parameter', function() {
            $result = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 1,
                'offset' => 1,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('offset=2');
        });

        it('uses default limit of 20', function() {
            $result = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            // With only 2 results, no load more button should appear
            expect($result['content'])->not->toContain('Load More');
            // But both images should be returned
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->toContain('http://example.com/img3.jpg');
        });
    });

    describe('EAV Model Search - Multiple Conditions', function() {

        it('handles queryAnd parameter', function() {
            $result = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'queryAnd' => ['genus:agaricus'],
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->not->toContain('http://example.com/img3.jpg'); // Tulostoma
        });

        it('handles queryOr parameter', function() {
            $result = $this->eavModel->search([
                'query' => 'genus:agaricus',
                'queryOr' => ['genus:amanita'],
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg'); // Agaricus
            expect($result['content'])->toContain('http://example.com/img2.jpg'); // Amanita
        });

        it('handles multiple queryAnd conditions', function() {
            $result = $this->eavModel->search([
                'queryAnd' => ['family:agaricaceae', 'genus:tulostoma'],
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img3.jpg');
            expect($result['content'])->not->toContain('http://example.com/img1.jpg');
        });
    });

    describe('Hybrid Model Search - Basic Queries', function() {

        it('returns results for simple field:value query', function() {
            $result = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 10,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->toContain('http://example.com/img3.jpg');
        });

        it('returns error for empty query', function() {
            $result = $this->hybridModel->search([
                'query' => '',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No search query provided');
        });

        it('returns empty results for non-matching query', function() {
            $result = $this->hybridModel->search([
                'query' => 'family:nonexistent',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->not->toContain('http://example.com/img1.jpg');
        });

        it('is case-insensitive', function() {
            $result1 = $this->hybridModel->search([
                'query' => 'family:AGARICACEAE',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);
            $result2 = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            // Both should return same results
            expect($result1['type'])->toBe('htmx');
            expect($result2['type'])->toBe('htmx');
            expect($result1['content'])->toContain('http://example.com/img1.jpg');
            expect($result2['content'])->toContain('http://example.com/img1.jpg');
        });
    });

    describe('Hybrid Model Search - Pagination', function() {

        it('handles limit parameter', function() {
            $result = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 1,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('offset=1');
        });

        it('handles offset parameter', function() {
            $result = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 1,
                'offset' => 1,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('offset=2');
        });
    });

    describe('Hybrid Model Search - Multiple Conditions', function() {

        it('handles queryAnd parameter', function() {
            $result = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'queryAnd' => ['genus:agaricus'],
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg');
            expect($result['content'])->not->toContain('http://example.com/img3.jpg'); // Tulostoma
        });

        it('handles queryOr parameter', function() {
            $result = $this->hybridModel->search([
                'query' => 'genus:agaricus',
                'queryOr' => ['genus:amanita'],
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('http://example.com/img1.jpg'); // Agaricus
            expect($result['content'])->toContain('http://example.com/img2.jpg'); // Amanita
        });
    });

    describe('Plugin Interchangeability', function() {

        it('both models implement search() method', function() {
            expect(method_exists($this->eavModel, 'search'))->toBe(true);
            expect(method_exists($this->hybridModel, 'search'))->toBe(true);
        });

        it('both models return same response structure', function() {
            $eavResult = $this->eavModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 5,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            $hybridResult = $this->hybridModel->search([
                'query' => 'family:agaricaceae',
                'limit' => 5,
                'db-path' => $this->eavCacheDbPath,
                'format' => 'web'
            ]);

            expect(isset($eavResult['type']))->toBe(true);
            expect(isset($hybridResult['type']))->toBe(true);
            expect(isset($eavResult['content']))->toBe(true);
            expect(isset($hybridResult['content']))->toBe(true);
        });
    });

});

