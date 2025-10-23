<?php

use Symbiota\Helpers\Models\ImagesModelHybridIndex;
use Symbiota\Helpers\Core\ApplicationPaths;

describe('ImagesModel - Hybrid Search Query Parsing', function () {

    beforeEach(function () {
        // Get paths (same as ImagesModelHybridIndexSpec.php)
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';

        // Use a shared test database for all tests (build once)
        static $sharedTestDb = null;
        static $isBuilt = false;

        if ($sharedTestDb === null) {
            $sharedTestDb = sys_get_temp_dir() . '/test_hybrid_search_shared.db';
        }

        // Only build if not already built
        if (!$isBuilt) {
            // Remove old database if it exists
            if (file_exists($sharedTestDb)) {
                unlink($sharedTestDb);
            }

            // Verify fixture exists
            if (!file_exists($this->fixtureDbPath)) {
                throw new \Exception("Fixture database not found at: {$this->fixtureDbPath}");
            }

            // Build hybrid index from fixture (only once)
            $builder = new ImagesModelHybridIndex([
                'config_path' => $this->configPath,
                'hybrid_index_db_path' => $sharedTestDb
            ]);

            $builder->buildIndex($this->fixtureDbPath);
            $isBuilt = true;
        }

        $this->testHybridIndexDb = $sharedTestDb;

        // Create hybrid index instance for testing
        $this->hybridIndex = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->testHybridIndexDb
        ]);
    });

    afterEach(function () {
        // Don't delete the shared database - it will be reused
    });
    
    describe('Single field search', function () {

        it('searches by family', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'family' => 'russulaceae'
            ]);

            expect($occids)->toBeA('array');
            expect(count($occids))->toBeGreaterThan(0);
        });

        it('searches by genus', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'genus' => 'russula'
            ]);

            expect($occids)->toBeA('array');
        });

        it('searches by country', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'country' => 'united states'
            ]);

            expect($occids)->toBeA('array');
        });

        it('searches by stateProvince', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'stateProvince' => 'tennessee'
            ]);

            expect($occids)->toBeA('array');
        });

        it('searches by sciname', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'sciname' => 'russula'
            ]);

            expect($occids)->toBeA('array');
        });
    });
    
    describe('Multiple field search (AND logic)', function () {

        it('searches with family AND country', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'family' => 'russulaceae',
                'country' => 'united states'
            ]);

            expect($occids)->toBeA('array');
        });

        it('searches with family AND country AND state', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'family' => 'russulaceae',
                'country' => 'united states',
                'stateProvince' => 'tennessee'
            ]);

            expect($occids)->toBeA('array');
        });

        it('searches with genus AND country', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'genus' => 'russula',
                'country' => 'united states'
            ]);

            expect($occids)->toBeA('array');
        });
    });
    
    describe('Empty results', function () {

        it('handles no matching results gracefully', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'family' => 'nonexistentfamily'
            ]);

            expect($occids)->toBeA('array');
            expect(count($occids))->toBe(0);
        });

        it('handles empty search params gracefully', function () {
            $occids = $this->hybridIndex->searchIndexedFields([]);

            expect($occids)->toBeA('array');
            expect(count($occids))->toBe(0);
        });
    });

    describe('Case insensitivity', function () {

        it('searches case-insensitively for family', function () {
            $occids1 = $this->hybridIndex->searchIndexedFields([
                'family' => 'russulaceae'
            ]);

            $occids2 = $this->hybridIndex->searchIndexedFields([
                'family' => 'RUSSULACEAE'
            ]);

            $occids3 = $this->hybridIndex->searchIndexedFields([
                'family' => 'Russulaceae'
            ]);

            // All should return the same results
            expect(count($occids1))->toBe(count($occids2));
            expect(count($occids1))->toBe(count($occids3));
        });
    });

    describe('Partial matching', function () {

        it('finds results with partial family name', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'family' => 'russul'
            ]);

            expect($occids)->toBeA('array');
            // Should find russulaceae
        });

        it('finds results with partial genus name', function () {
            $occids = $this->hybridIndex->searchIndexedFields([
                'genus' => 'russ'
            ]);

            expect($occids)->toBeA('array');
            // Should find russula
        });
    });
});

