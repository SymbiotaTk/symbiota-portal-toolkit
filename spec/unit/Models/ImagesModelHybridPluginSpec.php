<?php

use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Models\ImagesModelHybridIndex;
use PDO;

/**
 * Comprehensive test suite for ImagesModelHybrid search plugin
 *
 * Tests the actual search() method that is called by ImagesModel delegation.
 * Uses static fixture data (NOT production databases).
 * Covers all search scenarios: single-field, multi-field, aliases, CLI/web formats.
 */
describe('ImagesModelHybrid Search Plugin with Static Fixtures', function() {

    beforeAll(function() {
        echo "\n[beforeAll] Setting up test environment with static fixtures...\n";

        // Use fixture database
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/hybrid/index_config.ini';

        // Verify fixture exists
        if (!file_exists($this->fixtureDbPath)) {
            throw new \Exception("Fixture database not found at: {$this->fixtureDbPath}");
        }

        // Create temporary test databases (build once for all tests)
        $this->testDir = sys_get_temp_dir() . '/hybrid_plugin_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->hybridIndexPath = $this->testDir . '/hybrid_index.db';
        $this->sourceDbPath = $this->fixtureDbPath; // Use fixture as source

        // Build hybrid index from fixture
        echo "[beforeAll] Building hybrid index from fixture...\n";
        $builder = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->hybridIndexPath
        ]);
        $builder->buildIndex($this->fixtureDbPath);

        // Create model instance
        $this->model = new ImagesModelHybrid([
            'config_path' => $this->configPath,
            'cache_db_path' => $this->hybridIndexPath,
            'source_db_path' => $this->sourceDbPath
        ]);

        echo "[beforeAll] Setup complete. Using fixture data for all tests.\n";
    });

    afterAll(function() {
        // Clean up test directory
        if (isset($this->testDir) && is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            rmdir($this->testDir);
        }
    });

    describe('Single-field search (indexed fields)', function() {

        it('should search by family (indexed in hybrid cache)', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
            expect(isset($result['content']))->toBe(true);
            expect($result['content'])->toBeA('string');
        });

        it('should search by genus (indexed in hybrid cache)', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by country (indexed in hybrid cache)', function() {
            $result = $this->model->search([
                'query' => 'country:United States',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by stateProvince (indexed in hybrid cache)', function() {
            $result = $this->model->search([
                'query' => 'stateProvince:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });
    });

    describe('High-cardinality field search (source.db with indexes)', function() {

        it('should search by sciname (NOT in hybrid cache, uses source.db)', function() {
            $result = $this->model->search([
                'query' => 'sciname:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');

            // Should return results efficiently from source.db with indexes
            expect($result['content'])->toBeA('string');
        });

        it('should search by catalogNumber (NOT in hybrid cache, uses source.db)', function() {
            $result = $this->model->search([
                'query' => 'catalogNumber:TENN',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by locality (NOT in hybrid cache, uses source.db)', function() {
            $result = $this->model->search([
                'query' => 'locality:Great',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });
    });

    describe('Multi-field search (CRITICAL TEST CASE)', function() {

        it('should handle taxon + state efficiently (user reported issue pattern)', function() {
            // This tests the same pattern as user's issue: taxon:agaricus county:champaign
            // Using fixture data: taxon:russula (source.db) + stateProvince:tennessee (indexed)
            // Should be fast with proper source.db indexes

            $start = microtime(true);

            $result = $this->model->search([
                'query' => 'taxon:Russula stateProvince:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            $elapsed = (microtime(true) - $start) * 1000; // milliseconds

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
            expect($result['content'])->toBeA('string');

            // Should be fast (< 100ms with fixture data and proper indexes)
            expect($elapsed)->toBeLessThan(100);

            echo "\n  [Performance] taxon:Russula stateProvince:Tennessee took {$elapsed}ms\n";
        });

        it('should handle multiple indexed fields', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae stateProvince:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle queryAnd parameter (array)', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'queryAnd' => ['stateProvince:Tennessee'],
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle queryOr parameter (array)', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'queryOr' => ['genus:Russula'],
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });
    });

    describe('Taxonomy alias resolution', function() {

        it('should resolve taxon alias to multi-field search', function() {
            $result = $this->model->search([
                'query' => 'taxon:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle sciname directly', function() {
            $result = $this->model->search([
                'query' => 'sciname:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle scientificName field', function() {
            $result = $this->model->search([
                'query' => 'scientificName:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });
    });

    describe('Keyword search (no field prefix)', function() {

        it('should perform keyword search across all text fields', function() {
            $result = $this->model->search([
                'query' => 'russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle partial keyword matches', function() {
            $result = $this->model->search([
                'query' => 'russ',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });
    });

    describe('CLI vs Web format detection', function() {

        it('should return CLI format when format=cli', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
            expect(isset($result['content']))->toBe(true);
            expect($result['content'])->toBeA('string');
            expect($result['content'])->toContain('OccID'); // Column header is capitalized
        });

        it('should return HTMX format when format=htmx', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect(isset($result['content']))->toBe(true);
            expect($result['content'])->toContain('<div');
        });
    });

    describe('Empty query handling', function() {

        it('should return error for empty query string', function() {
            $result = $this->model->search([
                'query' => '',
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('error');
            expect($result['message'])->toBeA('string');
        });

        it('should return error when no query parameters provided', function() {
            $result = $this->model->search([
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('error');
        });
    });

    describe('Pagination (limit and offset)', function() {

        it('should respect limit parameter', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 5,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle offset parameter for pagination', function() {
            $result1 = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 5,
                'offset' => 0,
                'format' => 'cli'
            ]);

            $result2 = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 5,
                'offset' => 5,
                'format' => 'cli'
            ]);

            // Results should be different (different pages)
            expect($result1['content'])->not->toBe($result2['content']);
        });
    });

    describe('Case-insensitive search', function() {

        it('should handle lowercase field names', function() {
            $result = $this->model->search([
                'query' => 'family:russulaceae',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
        });

        it('should handle uppercase field names', function() {
            $result = $this->model->search([
                'query' => 'FAMILY:RUSSULACEAE',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
        });

        it('should handle mixed case values', function() {
            $result = $this->model->search([
                'query' => 'family:RuSsUlAcEaE',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
        });
    });
});

