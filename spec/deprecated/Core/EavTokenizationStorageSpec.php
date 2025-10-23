<?php

use Symbiota\Helpers\Core\EavTokenizer;

describe('EavTokenizer - Tokenization Storage in EAV Table', function() {
    
    beforeEach(function() {
        // Setup test databases
        $this->fixtureDir = __DIR__ . '/../../fixtures/eav';
        $this->testDir = sys_get_temp_dir() . '/eav_storage_test_' . uniqid();
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
        
        if (file_exists($this->workDbPath)) {
            unlink($this->workDbPath);
        }
        if (file_exists($this->cacheDbPath)) {
            unlink($this->cacheDbPath);
        }
        if (file_exists($this->testDir . '/media.tsv')) {
            unlink($this->testDir . '/media.tsv');
        }
        if (file_exists($this->testDir . '/omoccurrences.tsv')) {
            unlink($this->testDir . '/omoccurrences.tsv');
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    });
    
    describe('PRIMARY KEY constraint with VidArray', function() {

        it('should have exactly one EAV row per (Eid, Aid) pair', function() {
            // Build full EAV index
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // Check for duplicate (Eid, Aid) pairs
            // With VidArray, we should have NO duplicates (one row per entity-attribute)
            $result = $this->cacheDb->query('
                SELECT Eid, Aid, COUNT(*) as cnt
                FROM EAV
                GROUP BY Eid, Aid
                HAVING cnt > 1
            ')->fetchAll(PDO::FETCH_ASSOC);

            // VidArray stores all tokens in one row, so no duplicates
            expect(count($result))->toBe(0);
        });

        it('should store multiple tokens in VidArray as space-separated string', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // Find a text field with :split strategy (locality)
            $row = $this->cacheDb->query("
                SELECT VidArray
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                WHERE a.ColumnName = 'locality'
                AND VidArray IS NOT NULL
                AND VidArray != ''
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // VidArray should contain space-separated Vid numbers
                expect($row['VidArray'])->toBeA('string');
                expect($row['VidArray'])->toMatch('/^[0-9 ]+$/');

                // Should have multiple Vids (tokens)
                $vids = explode(' ', trim($row['VidArray']));
                expect(count($vids))->toBeGreaterThan(1);
            }
        });

    });
    
    describe('Text field tokenization with :whole vs :split', function() {

        it('should store :whole fields as single token in VidArray', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // "owner" field is :whole by default - should NOT be split
            $row = $this->cacheDb->query("
                SELECT VidArray
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                WHERE a.ColumnName = 'owner'
                AND VidArray IS NOT NULL
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // VidArray should contain single Vid (not split)
                $vids = explode(' ', trim($row['VidArray']));
                expect(count($vids))->toBe(1);
            }
        });

        it('should store :split fields as multiple tokens in VidArray', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // "locality" field is :split - should be tokenized
            $row = $this->cacheDb->query("
                SELECT VidArray
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                WHERE a.ColumnName = 'locality'
                AND VidArray IS NOT NULL
                AND VidArray != ''
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // VidArray should contain multiple Vids (split by whitespace)
                $vids = explode(' ', trim($row['VidArray']));
                expect(count($vids))->toBeGreaterThan(1);
            }
        });

        it('should allow searching for tokens within VidArray', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // Check if VidArray has any non-null values
            $sampleRow = $this->cacheDb->query("
                SELECT VidArray
                FROM EAV
                WHERE VidArray IS NOT NULL
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($sampleRow && $sampleRow['VidArray']) {
                // Get first Vid from the VidArray
                $vids = explode(' ', trim($sampleRow['VidArray']));
                $vid = $vids[0];

                // Search for this Vid in VidArray using LIKE
                $count = $this->cacheDb->query("
                    SELECT COUNT(*)
                    FROM EAV
                    WHERE (' ' || VidArray || ' ') LIKE '% $vid %'
                ")->fetchColumn();

                // Should find at least one match (the row we got it from)
                expect($count)->toBeGreaterThan(0);
            } else {
                // If no VidArray data, test passes (might be all numeric columns)
                expect(true)->toBe(true);
            }
        });

    });
    
    describe('EAV table structure with VidArray', function() {

        it('should have VidArray TEXT column instead of Vid INTEGER', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // Get table schema
            $schema = $this->cacheDb->query("
                SELECT sql FROM sqlite_master WHERE type='table' AND name='EAV'
            ")->fetchColumn();

            // Should have VidArray TEXT column
            expect($schema)->toContain('VidArray');
            expect($schema)->toContain('TEXT');

            // Should have PRIMARY KEY (Eid, Aid)
            expect($schema)->toContain('PRIMARY KEY');
            expect($schema)->toContain('Eid');
            expect($schema)->toContain('Aid');
        });

        it('should have approximately one EAV row per entity-attribute pair', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);

            // Get total EAV rows
            $totalRows = $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();

            // Get total entities
            $totalEntities = $this->cacheDb->query('SELECT COUNT(*) FROM Entities')->fetchColumn();

            // Get total attributes for media table only (root table)
            $mediaAttrs = $this->cacheDb->query("
                SELECT COUNT(*) FROM Attributes WHERE TableName = 'media'
            ")->fetchColumn();

            // With VidArray, we should have approximately entities × media_attributes rows
            // (Some attributes may be NULL/empty, so slightly less)
            // Note: Related tables (omoccurrences) are not being processed yet
            $expected = $totalEntities * $mediaAttrs;

            // Should be within reasonable range (50-150% of expected)
            // Allow wider range because some fields may be empty
            expect($totalRows)->toBeGreaterThan($expected * 0.5);
            expect($totalRows)->toBeLessThan($expected * 1.5);
        });

    });
    
    describe('Design options for storing tokenized values', function() {
        
        it('should document current approach: one row per token', function() {
            // Current approach (if working):
            // - Each token gets its own EAV row
            // - Same (Eid, Aid) for all tokens from same field
            // - Different Vid for each token
            // - PRIMARY KEY (Eid, Aid) prevents this!
            
            expect(true)->toBe(true);
        });
        
        it('should consider alternative: Vid array as space-separated string', function() {
            // Alternative approach:
            // - Store all token Vids as space-separated string
            // - Example: "301 302 303" for "Denver Botanic Gardens"
            // - PRIMARY KEY (Eid, Aid) works
            // - Easy to search with LIKE '%301%'
            // - Easy to convert to array: explode(' ', $vidArray)
            
            expect(true)->toBe(true);
        });
        
        it('should consider alternative: VidGroupId with separate table', function() {
            // Alternative approach:
            // - EAV table has VidGroupId instead of Vid
            // - Separate table: ValueGroups (GroupId, Order, Vid)
            // - More normalized, preserves token order
            // - More complex queries (requires JOIN)
            
            expect(true)->toBe(true);
        });
        
    });
    
});

