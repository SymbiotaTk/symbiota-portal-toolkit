<?php

use Symbiota\Helpers\Core\EavTokenizer;
use Symbiota\Helpers\Core\TextNormalizer;

describe('EavTokenizer - Display Fields', function() {
    
    beforeEach(function() {
        // Setup test databases
        $this->fixtureDir = __DIR__ . '/../../fixtures/eav';
        $this->testDir = sys_get_temp_dir() . '/eav_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->workDbPath = $this->testDir . '/work.db';
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';

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
    
    describe('TextNormalizer::parseColumnDef() - :display flag', function() {
        
        it('should parse :display flag for text columns', function() {
            $result = TextNormalizer::parseColumnDef('url:text:display');
            expect($result)->toBe([
                'name' => 'url',
                'type' => 'text',
                'display' => true,
                'exclude' => false,
                'strategy' => null  // No strategy for display fields
            ]);
        });
        
        it('should treat :exclude as :display (backward compatibility)', function() {
            $result = TextNormalizer::parseColumnDef('url:text:exclude');
            expect($result)->toBe([
                'name' => 'url',
                'type' => 'text',
                'display' => true,
                'exclude' => true,  // Kept for backward compatibility
                'strategy' => null
            ]);
        });
        
        it('should not set display flag for indexed fields', function() {
            $result = TextNormalizer::parseColumnDef('catalogNumber:text:whole');
            expect($result)->toBe([
                'name' => 'catalogNumber',
                'type' => 'text',
                'display' => false,
                'exclude' => false,
                'strategy' => 'whole'
            ]);
        });
        
    });
    
    describe('Entities table with display fields', function() {
        
        it('should add display field columns to Entities table', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            
            // Check that Entities table has display field columns
            $schema = $this->cacheDb->query("
                SELECT sql FROM sqlite_master WHERE type='table' AND name='Entities'
            ")->fetchColumn();
            
            // Should have base columns
            expect($schema)->toContain('Eid');
            expect($schema)->toContain('EntityValue');
            
            // Should have display field columns
            expect($schema)->toContain('url');
            expect($schema)->toContain('originalUrl');
            expect($schema)->toContain('thumbnailUrl');
        });
        
        it('should populate display fields in Entities table', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            
            // Get a sample entity with display fields
            $row = $this->cacheDb->query("
                SELECT Eid, EntityValue, url, originalUrl, thumbnailUrl
                FROM Entities
                WHERE url IS NOT NULL
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                // Should have Eid and EntityValue
                expect($row['Eid'])->not->toBeNull();
                expect($row['EntityValue'])->not->toBeNull();

                // Should have display field values
                expect($row['url'])->not->toBeNull();
                expect($row['url'])->toContain('http');  // URLs should contain http
            }
        });
        
        it('should NOT create Attributes for display fields', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            
            // Check that display fields are NOT in Attributes table
            $urlAttr = $this->cacheDb->query("
                SELECT COUNT(*) FROM Attributes
                WHERE ColumnName IN ('url', 'originalUrl', 'thumbnailUrl')
            ")->fetchColumn();
            
            // Display fields should NOT be indexed
            expect($urlAttr)->toBe(0);
        });
        
        it('should NOT tokenize display fields', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            
            // Get total tokens
            $totalTokens = $this->cacheDb->query("SELECT COUNT(*) FROM ValuesText")->fetchColumn();
            
            // Check that URL tokens are NOT in ValuesText
            // (URLs would create many tokens if tokenized)
            $urlTokens = $this->cacheDb->query("
                SELECT COUNT(*) FROM ValuesText 
                WHERE ValueText LIKE 'http%' OR ValueText LIKE '%.jpg%'
            ")->fetchColumn();
            
            // Should have no URL tokens
            expect($urlTokens)->toBe(0);
        });
        
    });
    
    describe('Full pipeline with display fields', function() {
        
        it('should complete full pipeline with display fields', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);
            
            // Get statistics
            $stats = $this->tokenizer->getStats();
            
            // Should have entities
            expect($stats['cache_db']['Entities']['rows'])->toBe(100);
            
            // Should have attributes (excluding display fields)
            $attrCount = $stats['cache_db']['Attributes']['rows'];
            expect($attrCount)->toBeGreaterThan(0);

            // Display fields should be excluded from Attributes
            // We have 27 indexed attributes (url, originalUrl, thumbnailUrl are excluded)
            expect($attrCount)->toBe(27);
        });
        
        it('should allow querying entities with display fields', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);
            
            // Query: Find entities and get their display fields
            $rows = $this->cacheDb->query("
                SELECT
                    e.Eid,
                    e.EntityValue as mediaID,
                    e.url,
                    e.originalUrl,
                    e.thumbnailUrl
                FROM Entities e
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            expect(count($rows))->toBe(5);

            foreach ($rows as $row) {
                // Should have all columns
                expect(isset($row['Eid']))->toBe(true);
                expect(isset($row['mediaID']))->toBe(true);
                expect(isset($row['url']))->toBe(true);
                expect(isset($row['originalUrl']))->toBe(true);
                expect(isset($row['thumbnailUrl']))->toBe(true);
            }
        });
        
        it('should allow joining Entities with EAV for search + display', function() {
            $this->tokenizer->importTsvFiles($this->testDir);
            $this->tokenizer->tokenizeValues();
            $this->tokenizer->buildCacheMetadata();
            $this->tokenizer->buildEavIndex($this->testDir);
            
            // Query: Search by indexed field, return with display fields
            $rows = $this->cacheDb->query("
                SELECT DISTINCT
                    e.Eid,
                    e.EntityValue as mediaID,
                    e.url,
                    e.originalUrl,
                    e.thumbnailUrl
                FROM EAV eav
                JOIN Entities e ON eav.Eid = e.Eid
                JOIN Attributes a ON eav.Aid = a.Aid
                WHERE a.ColumnName = 'mediaID'
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            expect(count($rows))->toBeGreaterThan(0);

            foreach ($rows as $row) {
                // Should have display fields from Entities
                expect(isset($row['url']))->toBe(true);
                expect(isset($row['originalUrl']))->toBe(true);
                expect(isset($row['thumbnailUrl']))->toBe(true);
            }
        });
        
    });
    
});

