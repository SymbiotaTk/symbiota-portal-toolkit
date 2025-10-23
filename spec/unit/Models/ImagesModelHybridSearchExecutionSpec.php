<?php

use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Models\ImagesModelHybridIndex;

describe('ImagesModelHybrid - Search Execution', function() {

    beforeAll(function() {
        // Use existing fixture database as source
        $this->sourceDb = __DIR__ . '/../../fixtures/images/eav/fixtures.db';

        if (!file_exists($this->sourceDb)) {
            throw new Exception("Fixture database not found at: {$this->sourceDb}");
        }

        // Build hybrid cache from fixtures
        $this->hybridCacheDb = sys_get_temp_dir() . '/test_hybrid_search_' . uniqid() . '.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/hybrid/index_config.ini';

        // Build the hybrid index using the actual builder
        $builder = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->hybridCacheDb
        ]);

        $builder->buildIndex($this->sourceDb);

        // Query source database to know what to expect
        $db = new PDO("sqlite:{$this->sourceDb}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get sample county values from source to know what to expect
        $stmt = $db->query("
            SELECT DISTINCT LOWER(TRIM(county)) as county, COUNT(*) as count
            FROM omoccurrences o
            JOIN media m ON o.occid = m.occid
            WHERE county IS NOT NULL AND county != ''
            GROUP BY LOWER(TRIM(county))
            ORDER BY count DESC
            LIMIT 5
        ");
        $this->sampleCounties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get sample family values
        $stmt = $db->query("
            SELECT DISTINCT LOWER(TRIM(family)) as family, COUNT(*) as count
            FROM omoccurrences o
            JOIN media m ON o.occid = m.occid
            WHERE family IS NOT NULL AND family != ''
            GROUP BY LOWER(TRIM(family))
            ORDER BY count DESC
            LIMIT 5
        ");
        $this->sampleFamilies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total media count
        $this->totalMedia = $db->query("SELECT COUNT(*) FROM media WHERE occid IS NOT NULL")->fetchColumn();

        $db = null;
    });

    afterAll(function() {
        // Clean up temporary hybrid cache
        if (file_exists($this->hybridCacheDb)) {
            unlink($this->hybridCacheDb);
        }
    });

    describe('Field:value search with real fixture data', function() {

        it('searches without SQL errors (no EntityValue column reference)', function() {
            // Skip if no counties in fixture
            if (empty($this->sampleCounties)) {
                $this->skip('No county data in fixtures');
            }

            $testCounty = $this->sampleCounties[0]['county'];

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "county:{$testCounty}",
                'limit' => 20,
                'offset' => 0,
                
            ]);

            // Should not have SQL errors about missing columns
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('htmx');
            expect($result)->toContainKey('content');
            expect($result['content'])->not->toContain('SQLSTATE');
            expect($result['content'])->not->toContain('no such column');
            expect($result['content'])->not->toContain('EntityValue');
        });

        it('returns results for county search', function() {
            if (empty($this->sampleCounties)) {
                $this->skip('No county data in fixtures');
            }

            $testCounty = $this->sampleCounties[0]['county'];
            $expectedCount = $this->sampleCounties[0]['count'];

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "county:{$testCounty}",
                'limit' => 100,
                'offset' => 0,
                
            ]);

            $content = $result['content'];

            // Should have results (not "No images found")
            expect($content)->not->toContain('No images found');

            // Should contain image elements
            expect($content)->toMatch('/data-media-id/');
        });

        it('includes URL data in results', function() {
            if (empty($this->sampleCounties)) {
                $this->skip('No county data in fixtures');
            }

            $testCounty = $this->sampleCounties[0]['county'];

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "county:{$testCounty}",
                'limit' => 1,
                'offset' => 0,

            ]);

            $content = $result['content'];

            // URLs should be included from EAV attributes (stored as :display fields)
            expect($content)->toMatch('/http/'); // Should contain URLs
        });

        it('handles case-insensitive field names', function() {
            if (empty($this->sampleCounties)) {
                $this->skip('No county data in fixtures');
            }

            $testCounty = $this->sampleCounties[0]['county'];

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "COUNTY:{$testCounty}",
                'limit' => 20,
                'offset' => 0,

            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->not->toContain('No images found');
        });

        it('handles case-insensitive values', function() {
            if (empty($this->sampleCounties)) {
                $this->skip('No county data in fixtures');
            }

            $testCounty = strtoupper($this->sampleCounties[0]['county']);

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "county:{$testCounty}",
                'limit' => 20,
                'offset' => 0,

            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->not->toContain('No images found');
        });

        it('returns empty results for non-existent values', function() {
            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => 'county:nonexistentcountyxyz123',
                'limit' => 20,
                'offset' => 0,
                
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('No images found');
        });

        it('searches by family field', function() {
            if (empty($this->sampleFamilies)) {
                $this->skip('No family data in fixtures');
            }

            $testFamily = $this->sampleFamilies[0]['family'];

            $model = new ImagesModelHybrid([
                'cache_db_path' => $this->hybridCacheDb,
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDb
            ]);

            $result = $model->search([
                'query' => "family:{$testFamily}",
                'limit' => 20,
                'offset' => 0,

            ]);

            $content = $result['content'];

            // Should have results
            expect($content)->not->toContain('No images found');
            expect($content)->toMatch('/data-media-id/');
        });

        it('verifies hybrid schema has correct Entities table structure', function() {
            // Open hybrid cache and verify schema
            $db = new PDO("sqlite:{$this->hybridCacheDb}");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get Entities table schema
            $stmt = $db->query("PRAGMA table_info(Entities)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $columnNames = array_column($columns, 'name');

            // Hybrid schema should have: Eid, mediaID, occid
            expect($columnNames)->toContain('Eid');
            expect($columnNames)->toContain('mediaID');
            expect($columnNames)->toContain('occid');

            // Hybrid schema should NOT have: EntityValue, url, originalUrl, thumbnailUrl
            expect($columnNames)->not->toContain('EntityValue');
            expect($columnNames)->not->toContain('url');
            expect($columnNames)->not->toContain('originalUrl');
            expect($columnNames)->not->toContain('thumbnailUrl');
        });
    });
});

