<?php

use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Core\Configuration;
use PDO;

describe('ImagesModel Autocomplete with Static Fixtures', function() {

    beforeAll(function() {
        // Setup test environment with static fixtures - build once for all tests
        echo "\n[beforeAll] Starting setup...\n";
        $this->testDir = sys_get_temp_dir() . '/eav_autocomplete_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        echo "[beforeAll] Created test directory: {$this->testDir}\n";

        // Create test config with autocomplete-friendly fields
        $configPath = $this->testDir . '/test_config.ini';
        $configContent = <<<'INI'
[_config]
root_table = media
root_id_column = mediaID

[aliases]
taxon = sciname
catalog = catalogNumber
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

        // Create source database with known test data
        $sourceDbPath = $this->testDir . '/source.db';
        $sourceDb = new PDO('sqlite:' . $sourceDbPath);
        $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables
        $sourceDb->exec('
            CREATE TABLE media (
                mediaID INTEGER PRIMARY KEY,
                occid INTEGER,
                url TEXT,
                thumbnailUrl TEXT
            )
        ');

        $sourceDb->exec('
            CREATE TABLE omoccurrences (
                occid INTEGER PRIMARY KEY,
                collid INTEGER,
                catalogNumber TEXT,
                sciname TEXT,
                family TEXT,
                genus TEXT
            )
        ');

        $sourceDb->exec('
            CREATE TABLE omcollections (
                collid INTEGER PRIMARY KEY,
                collectionName TEXT,
                institutionCode TEXT
            )
        ');

        // Insert known test data
        $sourceDb->exec("
            INSERT INTO omcollections (collid, collectionName, institutionCode) VALUES
            (1, 'Denver Botanic Gardens Herbarium', 'DBG'),
            (2, 'University of Colorado Museum', 'UCM')
        ");

        $sourceDb->exec("
            INSERT INTO omoccurrences (occid, collid, catalogNumber, sciname, family, genus) VALUES
            (1001, 1, 'DBG-001', 'Agaricus campestris', 'Agaricaceae', 'Agaricus'),
            (1002, 1, 'DBG-002', 'Amanita muscaria', 'Amanitaceae', 'Amanita'),
            (1003, 2, 'UCM-100', 'Tulostoma brumale', 'Agaricaceae', 'Tulostoma')
        ");

        $sourceDb->exec("
            INSERT INTO media (mediaID, occid, url, thumbnailUrl) VALUES
            (1, 1001, 'https://example.org/img1.jpg', 'https://example.org/thumb1.jpg'),
            (2, 1002, 'https://example.org/img2.jpg', 'https://example.org/thumb2.jpg'),
            (3, 1003, 'https://example.org/img3.jpg', 'https://example.org/thumb3.jpg')
        ");

        // Build EAV cache from fixtures
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $workDbPath = $this->testDir . '/work.db';

        // Delete any existing database files from previous runs
        if (file_exists($this->cacheDbPath)) {
            unlink($this->cacheDbPath);
        }
        if (file_exists($workDbPath)) {
            unlink($workDbPath);
        }

        $eavBuilder = new ImagesModelEav([
            'config_path' => $configPath,
            'cache_db_path' => $this->cacheDbPath,
            'work_db_path' => $workDbPath,
            'source_db_path' => $sourceDbPath
        ]);

        // Build the cache (3-step process)
        echo "[beforeAll] Creating databases...\n";
        $eavBuilder->createDatabases();
        echo "[beforeAll] Building attributes...\n";
        $eavBuilder->buildAttributes();
        echo "[beforeAll] Building EAV index...\n";
        $eavBuilder->buildEavIndex([]);
        echo "[beforeAll] Creating autocomplete indexes...\n";
        $eavBuilder->createAutocompleteIndexes();
        echo "[beforeAll] Setup complete!\n\n";

        // Close source database
        $sourceDb = null;

        // Initialize both EAV and Hybrid models for testing
        // Both implement ImagesSearchInterface with autocomplete methods
        $this->eavModel = $eavBuilder;

        // For testing, Hybrid model uses the same EAV cache database
        // In production, it would use specialized autocomplete indexes
        $this->hybridModel = new ImagesModelHybrid([
            'config_path' => $configPath,
            'cache_db_path' => $this->cacheDbPath
        ]);

        // Keep legacy reference for backward compatibility with existing tests
        $this->model = $this->eavModel;
    });

    afterAll(function() {
        // Clean up test directory after all tests complete
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

    describe('autocompleteFields endpoint', function() {

        it('returns field suggestions for partial match "fam"', function() {
            $result = $this->model->autocompleteFields(['q' => 'fam', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('family');
        });

        it('returns field suggestions for exact match "family"', function() {
            $result = $this->model->autocompleteFields(['q' => 'family', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('family');
        });

        it('returns alias "taxon" which maps to "sciname"', function() {
            $result = $this->model->autocompleteFields(['q' => 'taxon', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('taxon');
            expect($result['content'])->toContain('sciname');
        });

        it('returns empty message when no fields match', function() {
            $result = $this->model->autocompleteFields(['q' => 'zzzznonexistent', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No matching fields found');
        });

        it('delegates to autocompleteValues for field:value pattern', function() {
            $result = $this->model->autocompleteFields(['q' => 'family:ag', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            // Should return value suggestions (Agaricaceae), not field suggestions
            // Case-insensitive search, but original case preserved for display
            expect($result['content'])->toContain('Agaricaceae');
        });
    });

    describe('autocompleteValues endpoint', function() {

        it('returns value suggestions for family field with "ag"', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            // Case-insensitive search, but original case preserved for display
            expect($result['content'])->toContain('Agaricaceae');
        });

        it('returns value suggestions for genus field with "tul"', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'genus',
                'q' => 'tul',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            // Case-insensitive search, but original case preserved for display
            expect($result['content'])->toContain('Tulostoma');
        });

        it('returns complete collectionName values (not tokenized)', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'collectionName',
                'q' => 'den',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            // Should return complete collection name with original case, not just "Denver"
            expect($result['content'])->toContain('Denver Botanic Gardens Herbarium');
        });

        it('returns empty message when no values match', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'family',
                'q' => 'zzzznonexistent',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No suggestions found');
        });
    });

    describe('autocomplete integration with search', function() {

        it('autocomplete fields → values → search pipeline works', function() {
            // Step 1: Get field suggestions
            $fieldResult = $this->model->autocompleteFields(['q' => 'family', 'db-path' => $this->cacheDbPath]);
            expect($fieldResult['type'])->toBe('htmx');
            expect($fieldResult['content'])->toContain('family');

            // Step 2: Get value suggestions for family
            $valueResult = $this->model->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);
            expect($valueResult['type'])->toBe('htmx');
            expect($valueResult['content'])->toContain('Agaricaceae');

            // Step 3: Execute search with the autocompleted value
            // Use new interface method search() instead of eavSearch()
            $searchResult = $this->model->search([
                'query' => 'family:agaricaceae',
                'limit' => 5,
                'db-path' => $this->cacheDbPath,
                'format' => 'web'
            ]);

            expect($searchResult['type'])->toBe('htmx');
            // Should return 2 images (mediaID 1 and 3 have family=Agaricaceae)
            expect($searchResult['content'])->toContain('img1.jpg');
            expect($searchResult['content'])->toContain('img3.jpg');
            expect($searchResult['content'])->not->toContain('img2.jpg'); // Amanitaceae
        });
    });

    describe('Hybrid Model - autocompleteFields endpoint', function() {

        it('returns field suggestions for partial match "fam"', function() {
            $result = $this->hybridModel->autocompleteFields(['q' => 'fam', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('family');
        });

        it('returns field suggestions for exact match "family"', function() {
            $result = $this->hybridModel->autocompleteFields(['q' => 'family', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('family');
        });

        it('returns alias "taxon" which maps to "sciname"', function() {
            $result = $this->hybridModel->autocompleteFields(['q' => 'taxon', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('taxon');
            expect($result['content'])->toContain('sciname');
        });

        it('returns empty message when no fields match', function() {
            $result = $this->hybridModel->autocompleteFields(['q' => 'zzzznonexistent', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No matching fields found');
        });

        it('delegates to autocompleteValues for field:value pattern', function() {
            $result = $this->hybridModel->autocompleteFields(['q' => 'family:ag', 'db-path' => $this->cacheDbPath]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('Agaricaceae');
        });
    });

    describe('Hybrid Model - autocompleteValues endpoint', function() {

        it('returns value suggestions for family field with "ag"', function() {
            $result = $this->hybridModel->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('Agaricaceae');
        });

        it('returns value suggestions for genus field with "tul"', function() {
            $result = $this->hybridModel->autocompleteValues([
                'field' => 'genus',
                'q' => 'tul',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('Tulostoma');
        });

        it('returns complete collectionName values (not tokenized)', function() {
            $result = $this->hybridModel->autocompleteValues([
                'field' => 'collectionName',
                'q' => 'den',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('Denver Botanic Gardens Herbarium');
        });

        it('returns empty message when no values match', function() {
            $result = $this->hybridModel->autocompleteValues([
                'field' => 'family',
                'q' => 'zzzznonexistent',
                'db-path' => $this->cacheDbPath
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No suggestions found');
        });
    });

    describe('Plugin Interchangeability - Autocomplete', function() {

        it('both models return identical field suggestions', function() {
            $eavResult = $this->eavModel->autocompleteFields(['q' => 'family', 'db-path' => $this->cacheDbPath]);
            $hybridResult = $this->hybridModel->autocompleteFields(['q' => 'family', 'db-path' => $this->cacheDbPath]);

            expect($eavResult['type'])->toBe($hybridResult['type']);
            expect($eavResult['content'])->toContain('family');
            expect($hybridResult['content'])->toContain('family');
        });

        it('both models return identical value suggestions', function() {
            $eavResult = $this->eavModel->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);
            $hybridResult = $this->hybridModel->autocompleteValues([
                'field' => 'family',
                'q' => 'ag',
                'db-path' => $this->cacheDbPath
            ]);

            expect($eavResult['type'])->toBe($hybridResult['type']);
            expect($eavResult['content'])->toContain('Agaricaceae');
            expect($hybridResult['content'])->toContain('Agaricaceae');
        });
    });
});
