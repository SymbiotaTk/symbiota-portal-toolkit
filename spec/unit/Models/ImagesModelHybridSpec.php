<?php

use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Models\ImagesModelHybridIndex;
use Symbiota\Helpers\Interfaces\ImagesSearchInterface;
use PDO;

describe('ImagesModelHybrid Plugin', function() {

    beforeAll(function() {
        // Setup test environment - build actual hybrid cache from source.db
        echo "\n[beforeAll] Starting Hybrid model setup...\n";
        $this->testDir = sys_get_temp_dir() . '/hybrid_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        echo "[beforeAll] Created test directory: {$this->testDir}\n";

        // Create test config
        $configPath = $this->testDir . '/test_config.ini';
        $configContent = <<<'INI'
[_config]
root_table = media
root_id_column = mediaID

[aliases]
taxon = sciname
collection = collectionName

[media]
primary_key = "mediaID"
foreign_key[] = "occid:omoccurrences.occid"
columns[] = mediaID:numeric
columns[] = url:text:display
columns[] = thumbnailUrl:text:display
columns[] = occid:numeric

[omoccurrences]
primary_key = "occid"
foreign_key[] = "collid:omcollections.collid"
columns[] = occid:numeric
columns[] = catalogNumber:text:whole
columns[] = sciname:text:whole
columns[] = family:text:whole
columns[] = genus:text:whole
columns[] = collid:numeric

[omcollections]
primary_key = "collid"
columns[] = collid:numeric
columns[] = collectionName:text:whole
columns[] = institutionCode:text:whole
INI;
        file_put_contents($configPath, $configContent);

        // Create autocomplete cache database (lightweight)
        $this->cacheDbPath = $this->testDir . '/autocomplete_cache.db';
        $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
        $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create Attributes table for field discovery
        $cacheDb->exec('
            CREATE TABLE Attributes (
                Aid INTEGER PRIMARY KEY,
                TableName TEXT NOT NULL,
                ColumnName TEXT NOT NULL,
                DataType TEXT NOT NULL,
                TokenStrategy TEXT DEFAULT \'whole\',
                UNIQUE(TableName, ColumnName)
            ) WITHOUT ROWID
        ');

        // Insert test attributes
        $cacheDb->exec("
            INSERT INTO Attributes (Aid, TableName, ColumnName, DataType, TokenStrategy) VALUES
            (1, 'omoccurrences', 'sciname', 'text', 'whole'),
            (2, 'omoccurrences', 'family', 'text', 'whole'),
            (3, 'omoccurrences', 'genus', 'text', 'whole'),
            (4, 'omcollections', 'collectionName', 'text', 'whole'),
            (5, 'media', 'url', 'text', 'display'),
            (6, 'media', 'thumbnailUrl', 'text', 'display')
        ");

        // Create autocomplete tables (minimal schema for testing)
        $cacheDb->exec('
            CREATE TABLE autocomplete_taxon (
                taxon_name TEXT NOT NULL,
                image_count INTEGER NOT NULL,
                source_field TEXT NOT NULL
            )
        ');

        $cacheDb->exec('
            CREATE TABLE autocomplete_collection (
                collection_value TEXT NOT NULL,
                image_count INTEGER NOT NULL,
                source_field TEXT NOT NULL
            )
        ');

        // Create ValuesText table for EAV fallback
        $cacheDb->exec('
            CREATE TABLE ValuesText (
                Vid INTEGER PRIMARY KEY,
                ValueText TEXT NOT NULL UNIQUE COLLATE NOCASE
            )
        ');

        // Create EAV table for fallback queries
        $cacheDb->exec('
            CREATE TABLE EAV (
                Eid INTEGER NOT NULL,
                Aid INTEGER NOT NULL,
                Vid INTEGER,
                VidOrder INTEGER,
                ValueNumber REAL
            )
        ');

        // Create Entities table
        $cacheDb->exec('
            CREATE TABLE Entities (
                Eid INTEGER PRIMARY KEY,
                EntityValue TEXT NOT NULL
            )
        ');

        // Insert test data into autocomplete tables
        $cacheDb->exec("
            INSERT INTO autocomplete_taxon (taxon_name, image_count, source_field) VALUES
            ('Agaricus campestris', 1, 'sciname'),
            ('Amanita muscaria', 1, 'sciname'),
            ('Agaricaceae', 2, 'family')
        ");

        $cacheDb->exec("
            INSERT INTO autocomplete_collection (collection_value, image_count, source_field) VALUES
            ('Denver Botanic Gardens Herbarium', 2, 'collectionName'),
            ('University of Colorado Museum', 1, 'collectionName')
        ");

        // Insert test data into ValuesText for EAV fallback (lowercase for case-insensitive matching)
        $cacheDb->exec("
            INSERT INTO ValuesText (Vid, ValueText) VALUES
            (1, 'agaricaceae'),
            (2, 'amanitaceae'),
            (3, 'agaricus campestris'),
            (4, 'amanita muscaria'),
            (5, 'tulostoma brumale'),
            (6, 'denver botanic gardens herbarium'),
            (7, 'university of colorado museum'),
            (8, 'agaricus'),
            (9, 'amanita'),
            (10, 'tulostoma')
        ");

        // Insert test data into Entities (mediaID as Eid)
        $cacheDb->exec("
            INSERT INTO Entities (Eid, EntityValue) VALUES
            (1, '1'),
            (2, '2'),
            (3, '3')
        ");

        // Insert test data into EAV
        // Aid 1 = sciname, Aid 2 = family, Aid 3 = genus
        $cacheDb->exec("
            INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES
            -- Entity 1: Agaricus campestris, Agaricaceae, Agaricus
            (1, 1, 3, NULL, NULL),
            (1, 2, 1, NULL, NULL),
            (1, 3, 8, NULL, NULL),
            -- Entity 2: Amanita muscaria, Amanitaceae, Amanita
            (2, 1, 4, NULL, NULL),
            (2, 2, 2, NULL, NULL),
            (2, 3, 9, NULL, NULL),
            -- Entity 3: Tulostoma brumale, Agaricaceae, Tulostoma
            (3, 1, 5, NULL, NULL),
            (3, 2, 1, NULL, NULL),
            (3, 3, 10, NULL, NULL)
        ");

        $cacheDb = null;

        // Create ImagesModelHybrid instance
        $this->model = new ImagesModelHybrid([
            'config_path' => $configPath,
            'cache_db_path' => $this->cacheDbPath
        ]);

        echo "[beforeAll] Hybrid model setup complete!\n\n";
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

    describe('ImagesSearchInterface implementation', function() {

        it('implements ImagesSearchInterface', function() {
            expect($this->model)->toBeAnInstanceOf(ImagesSearchInterface::class);
        });

        it('has search method', function() {
            expect(method_exists($this->model, 'search'))->toBe(true);
        });

        it('has autocompleteFields method', function() {
            expect(method_exists($this->model, 'autocompleteFields'))->toBe(true);
        });

        it('has autocompleteValues method', function() {
            expect(method_exists($this->model, 'autocompleteValues'))->toBe(true);
        });

        it('has getInfo method', function() {
            expect(method_exists($this->model, 'getInfo'))->toBe(true);
        });

        it('has isAvailable method', function() {
            expect(method_exists($this->model, 'isAvailable'))->toBe(true);
        });

        it('has getSearchMode method', function() {
            expect(method_exists($this->model, 'getSearchMode'))->toBe(true);
        });
    });

    describe('getSearchMode', function() {

        it('returns "hybrid"', function() {
            $mode = $this->model->getSearchMode();
            expect($mode)->toBe('hybrid');
        });
    });

    describe('isAvailable', function() {

        it('returns true when cache exists', function() {
            $available = $this->model->isAvailable();
            expect($available)->toBe(true);
        });
    });

    describe('autocompleteFields', function() {

        it('returns field suggestions for partial match', function() {
            $result = $this->model->autocompleteFields([
                'q' => 'fam',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('family');
        });
    });

    describe('autocompleteValues', function() {

        it('returns value suggestions from autocomplete cache', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('Agaricaceae');
        });
    });

    describe('search', function() {

        it('returns search results in HTMX format', function() {
            $result = $this->model->search([
                'query' => 'family:agaricaceae',
                'limit' => 5,
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect(isset($result['content']))->toBe(true);
        });
    });

    describe('getInfo', function() {

        it('returns cache information', function() {
            $info = $this->model->getInfo([
                'db-path' => $this->cacheDbPath
            ]);

            expect($info['type'])->toBe('success');
            expect(isset($info['content']))->toBe(true);
        });
    });
});

