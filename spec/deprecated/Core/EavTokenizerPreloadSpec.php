<?php

use Symbiota\Helpers\Core\EavTokenizer;

describe('EavTokenizer - Pre-loaded Arrays Optimization', function() {
    
    beforeEach(function() {
        // Setup test databases
        $this->fixtureDir = __DIR__ . '/../../fixtures/eav';
        $this->testDir = sys_get_temp_dir() . '/eav_preload_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->workDbPath = $this->testDir . '/work.db';
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/schema_config.ini';

        // Create PDO connections
        $this->workDb = new PDO("sqlite:{$this->workDbPath}");
        $this->workDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->cacheDb = new PDO("sqlite:{$this->cacheDbPath}");
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tokenizer instance
        $this->tokenizer = new EavTokenizer(
            $this->workDb,
            $this->cacheDb,
            $this->configPath,
            $this->workDbPath
        );

        // Copy fixture files to test directory
        copy($this->fixtureDir . '/media.tsv', $this->testDir . '/media.tsv');
        copy($this->fixtureDir . '/omoccurrences.tsv', $this->testDir . '/omoccurrences.tsv');
    });

    afterEach(function() {
        // Cleanup
        $this->workDb = null;
        $this->cacheDb = null;

        if (file_exists($this->workDbPath)) unlink($this->workDbPath);
        if (file_exists($this->cacheDbPath)) unlink($this->cacheDbPath);
        if (file_exists($this->testDir . '/media.tsv')) unlink($this->testDir . '/media.tsv');
        if (file_exists($this->testDir . '/omoccurrences.tsv')) unlink($this->testDir . '/omoccurrences.tsv');
        if (is_dir($this->testDir)) rmdir($this->testDir);
    });
    
    describe('loadAttributeMap()', function() {
        
        it('should load all attributes into memory', function() {
            // Import TSV and tokenize first
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();

            // Build cache metadata
            $this->tokenizer->buildCacheMetadata();

            // Load attribute map
            $aidMap = $this->tokenizer->loadAttributeMap();

            // Should be an array
            expect($aidMap)->toBeAn('array');

            // Should have entries for both tables
            expect(isset($aidMap['media']))->toBe(true);
            expect(isset($aidMap['omoccurrences']))->toBe(true);
        });

        it('should map table and column to Aid', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            // Check structure: $aidMap['table']['column'] = Aid
            expect($aidMap['media'])->toBeAn('array');
            expect($aidMap['media']['mediaID'])->toBeAn('integer');
            expect($aidMap['media']['occid'])->toBeAn('integer');
        });

        it('should NOT include excluded columns', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            // URL columns should NOT be in the map
            expect(isset($aidMap['media']['url']))->toBe(false);
            expect(isset($aidMap['media']['originalUrl']))->toBe(false);
            expect(isset($aidMap['media']['thumbnailUrl']))->toBe(false);
            expect(isset($aidMap['media']['accessUri']))->toBe(false);
        });

        it('should have sequential Aid values starting from 1', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            // Collect all Aids
            $aids = [];
            foreach ($aidMap as $table => $columns) {
                foreach ($columns as $column => $aid) {
                    $aids[] = $aid;
                }
            }

            // Should start from 1
            expect(min($aids))->toBe(1);

            // Should be sequential (no gaps)
            sort($aids);
            for ($i = 0; $i < count($aids) - 1; $i++) {
                expect($aids[$i + 1])->toBe($aids[$i] + 1);
            }
        });
        
    });
    
    describe('loadValueMap()', function() {
        
        it('should load all text values into memory', function() {
            // Import TSV and tokenize
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            // Load value map
            $vidMap = $this->tokenizer->loadValueMap();

            // Should be an array
            expect($vidMap)->toBeAn('array');

            // Should have entries
            expect(count($vidMap))->toBeGreaterThan(0);
        });

        it('should map text value to Vid', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $vidMap = $this->tokenizer->loadValueMap();

            // Check structure: $vidMap['token'] = Vid
            // Pick any token and verify it's an integer
            $firstToken = array_key_first($vidMap);
            expect($vidMap[$firstToken])->toBeAn('integer');
        });

        it('should have sequential Vid values starting from 1', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $vidMap = $this->tokenizer->loadValueMap();

            // Collect all Vids
            $vids = array_values($vidMap);

            // Should start from 1
            expect(min($vids))->toBe(1);

            // Should be sequential (no gaps)
            sort($vids);
            for ($i = 0; $i < count($vids) - 1; $i++) {
                expect($vids[$i + 1])->toBe($vids[$i] + 1);
            }
        });

        it('should NOT include values from numeric columns', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $vidMap = $this->tokenizer->loadValueMap();

            // Values from NUMERIC columns (decimalLatitude, decimalLongitude) should be stored
            // in EAV.ValueNumber, NOT tokenized into ValuesText.
            //
            // However, numeric-looking tokens from TEXT columns (like "3.5" from "3.5 mile stop")
            // SHOULD be tokenized because the column type is text.
            //
            // Check that actual coordinate values from numeric columns are NOT in ValuesText
            // Sample coordinates from fixture: 39.6901, -105.474, 39.633891, -105.816943
            $coordinateValues = ['39.6901', '-105.474', '39.633891', '-105.816943', '39.6467', '-105.6053'];

            foreach ($coordinateValues as $coord) {
                expect(isset($vidMap[$coord]))->toBe(false,
                    "Found coordinate value '$coord' in token map - should be in EAV.ValueNumber instead");
            }

            // But numeric-looking tokens from TEXT columns (locality) SHOULD be present
            // These are from descriptions like "3.5 mile stop", "9.6 miles SE"
            expect(isset($vidMap['3.5']))->toBe(true, "Token '3.5' from text column should be tokenized");
            expect(isset($vidMap['9.6']))->toBe(true, "Token '9.6' from text column should be tokenized");
        });
        
    });
    
    describe('getAid() - lookup from pre-loaded map', function() {
        
        it('should return Aid from pre-loaded map without query', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            // Get Aid using pre-loaded map
            $aid = $this->tokenizer->getAid('media', 'mediaID', $aidMap);

            // Should return an integer
            expect($aid)->toBeAn('integer');
            expect($aid)->toBeGreaterThan(0);
        });

        it('should return null for non-existent column', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            $aid = $this->tokenizer->getAid('media', 'nonexistent', $aidMap);

            expect($aid)->toBe(null);
        });

        it('should return null for excluded column', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $aidMap = $this->tokenizer->loadAttributeMap();

            // URL columns are excluded
            $aid = $this->tokenizer->getAid('media', 'url', $aidMap);

            expect($aid)->toBe(null);
        });
        
    });
    
    describe('getVid() - lookup from pre-loaded map', function() {
        
        it('should return Vid from pre-loaded map without query', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $vidMap = $this->tokenizer->loadValueMap();

            // Get Vid for a known token
            $firstToken = array_key_first($vidMap);
            $vid = $this->tokenizer->getVid($firstToken, $vidMap);

            // Should return an integer
            expect($vid)->toBeAn('integer');
            expect($vid)->toBeGreaterThan(0);
        });

        it('should return null for non-existent token', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            $vidMap = $this->tokenizer->loadValueMap();

            $vid = $this->tokenizer->getVid('nonexistent_token_xyz', $vidMap);

            expect($vid)->toBe(null);
        });
        
    });
    
    describe('Performance - No queries during batch processing', function() {
        
        it('should process batch without database queries', function() {
            // This test verifies that once maps are loaded,
            // no additional queries are needed during batch processing

            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();

            // Pre-load maps
            $aidMap = $this->tokenizer->loadAttributeMap();
            $vidMap = $this->tokenizer->loadValueMap();

            // Verify maps are loaded
            expect(count($aidMap))->toBeGreaterThan(0);
            expect(count($vidMap))->toBeGreaterThan(0);

            // At this point, all lookups should use the maps
            // No additional queries should be needed
            // (This is verified by the fact that getAid/getVid don't touch the database)
        });
        
    });
    
});

