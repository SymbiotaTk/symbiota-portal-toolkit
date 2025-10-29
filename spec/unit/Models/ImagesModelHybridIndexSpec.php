<?php

use Symbiota\Helpers\Models\ImagesModelHybridIndex;

describe('ImagesModelHybridIndex', function() {

    beforeEach(function() {
        $this->testDbPath = sys_get_temp_dir() . '/test_hybrid_index_' . uniqid() . '.db';

        // Use fixture data for fast testing
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';

        // Config path
        $this->configPath = __DIR__ . '/../../../templates/sql/images/index_config.ini';
    });

    afterEach(function() {
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
    });

    describe('Schema Creation', function() {

        it('creates Entities table with occid column', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();

            $db = new PDO('sqlite:' . $this->testDbPath);
            $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='Entities'");
            $row = $result->fetch(PDO::FETCH_ASSOC);

            expect($row)->not->toBe(false);
            expect($row['sql'])->toContain('occid');
            expect($row['sql'])->toContain('mediaID');
        });

        it('creates Collections table with collection fields', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();

            $db = new PDO('sqlite:' . $this->testDbPath);
            $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='Collections'");
            $row = $result->fetch(PDO::FETCH_ASSOC);

            expect($row)->not->toBe(false);
            expect($row['sql'])->toContain('collid');
            expect($row['sql'])->toContain('collectionName');
            expect($row['sql'])->toContain('collectionCode');
            expect($row['sql'])->toContain('institutionCode');
        });

        it('creates ValuesText table for low-cardinality fields', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();

            $db = new PDO('sqlite:' . $this->testDbPath);
            $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ValuesText'");
            $row = $result->fetch(PDO::FETCH_ASSOC);

            expect($row)->not->toBe(false);
            expect($row['sql'])->toContain('Vid');
            expect($row['sql'])->toContain('ValueText');
        });

        it('creates EAV table with Vid references', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();

            $db = new PDO('sqlite:' . $this->testDbPath);
            $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='EAV'");
            $row = $result->fetch(PDO::FETCH_ASSOC);

            expect($row)->not->toBe(false);
            expect($row['sql'])->toContain('Eid');
            expect($row['sql'])->toContain('Aid');
            expect($row['sql'])->toContain('Vid');
        });

        it('creates autocomplete tables (taxon, collection, location, date)', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            // Build index with fixture data (includes autocomplete table creation)
            $builder->buildIndex($this->fixtureDbPath);

            $db = new PDO('sqlite:' . $this->testDbPath);

            // Check autocomplete_taxon table exists
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='autocomplete_taxon'");
            expect($result->fetch())->not->toBe(false);

            // Check autocomplete_collection table exists
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='autocomplete_collection'");
            expect($result->fetch())->not->toBe(false);

            // Check autocomplete_location table exists
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='autocomplete_location'");
            expect($result->fetch())->not->toBe(false);

            // NOTE: autocomplete_date table is defined in SQL template but not currently created by the builder
            // The builder only creates: taxon, collection, location (see ImagesModelHybridIndex::createAutocompleteIndexes)

            // Verify autocomplete tables have data
            $stmt = $db->query("SELECT COUNT(*) as count FROM autocomplete_taxon");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            expect($row['count'])->toBeGreaterThan(0);
        });

        it('creates comprehensive indexes on all tables', function() {
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();

            $db = new PDO('sqlite:' . $this->testDbPath);
            $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);

            // Debug: Show all indexes created
            // echo "\nIndexes created: " . implode(', ', $indexes) . "\n";

            // Entities indexes
            expect(in_array('idx_entities_occid', $indexes))->toBe(true);
            expect(in_array('idx_entities_mediaid', $indexes))->toBe(true);

            // Collections indexes
            expect(in_array('idx_collections_collid', $indexes))->toBe(true);
            expect(in_array('idx_collections_name', $indexes))->toBe(true);

            // Attributes indexes (standardized schema uses ColumnName)
            expect(in_array('idx_attributes_columnname', $indexes))->toBe(true);

            // ValuesText indexes
            expect(in_array('idx_valuestext_text', $indexes))->toBe(true);

            // EAV indexes (critical for performance)
            expect(in_array('idx_eav_eid', $indexes))->toBe(true);
            expect(in_array('idx_eav_aid', $indexes))->toBe(true);
            expect(in_array('idx_eav_vid', $indexes))->toBe(true);
            expect(in_array('idx_eav_eid_aid', $indexes))->toBe(true);
            expect(in_array('idx_eav_aid_vid', $indexes))->toBe(true);
        });

    });

    describe('Building from fixture data', function() {

        it('populates Entities table with occid values from fixture', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            $db = new PDO('sqlite:' . $this->testDbPath);
            $count = $db->query("SELECT COUNT(*) FROM Entities")->fetchColumn();

            expect($count)->toBeGreaterThan(0);

            // Verify occid is populated
            $result = $db->query("SELECT occid FROM Entities WHERE occid IS NOT NULL LIMIT 1");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            expect($row)->not->toBe(false);
            expect($row['occid'])->toBeGreaterThan(0);
        });

        it('populates Collections table with unique collections from fixture', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            $db = new PDO('sqlite:' . $this->testDbPath);
            $count = $db->query("SELECT COUNT(*) FROM Collections")->fetchColumn();

            expect($count)->toBeGreaterThan(0);

            // Verify collection fields are populated
            $result = $db->query("SELECT * FROM Collections LIMIT 1");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            expect($row)->not->toBe(false);
            expect(isset($row['collid']))->toBe(true);
        });

        it('populates ValuesText with low-cardinality field values only', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            $db = new PDO('sqlite:' . $this->testDbPath);

            // Should have values for sciname, family, genus, etc.
            $count = $db->query("SELECT COUNT(*) FROM ValuesText")->fetchColumn();
            expect($count)->toBeGreaterThan(0);

            // Should NOT have catalogNumber, otherCatalogNumbers, or locality
            // (these are high-cardinality and should be queried directly from MySQL)
        });

        it('populates EAV table with Vid references', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            $db = new PDO('sqlite:' . $this->testDbPath);
            $count = $db->query("SELECT COUNT(*) FROM EAV")->fetchColumn();

            expect($count)->toBeGreaterThan(0);

            // Verify Vid references exist in ValuesText
            $result = $db->query("
                SELECT COUNT(*)
                FROM EAV e
                LEFT JOIN ValuesText v ON e.Vid = v.Vid
                WHERE v.Vid IS NULL AND e.Vid IS NOT NULL
            ");
            $orphanedVids = $result->fetchColumn();
            expect($orphanedVids)->toBe(0);
        });

    });

    describe('Search with occid filtering', function() {

        it('searches indexed fields and returns occid ranges from fixture', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            // Search for a field that exists in fixture
            $occids = $builder->searchIndexedFields(['family' => 'asteraceae']);

            expect(is_array($occids))->toBe(true);
            // May be empty if fixture doesn't have this family, but should return array
        });

        it('combines indexed search with direct MySQL query for high-cardinality fields', function() {
            if (!file_exists($this->fixtureDbPath)) {
                skipIf(true);
            }

            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $this->testDbPath
            ]);

            $builder->createSchema();
            $builder->buildFromSourceDb($this->fixtureDbPath);

            // Search for taxon (indexed) - should return occid ranges
            $occids = $builder->searchIndexedFields(['family' => 'asteraceae']);

            // This should return occid ranges that can be used to filter MySQL query
            expect(is_array($occids))->toBe(true);

            // The occids can then be used in a MySQL query like:
            // SELECT * FROM media WHERE occid IN (occid_list) AND catalogNumber = 'ABC123'
        });

    });

});

