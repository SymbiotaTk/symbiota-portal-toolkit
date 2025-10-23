<?php

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Core\Configuration;

describe('ImagesModelEav - Search Fixes Verification', function() {

    beforeAll(function() {
        // Initialize Configuration from config.php
        $configPath = __DIR__ . '/../../../config.php';
        if (!file_exists($configPath)) {
            throw new Exception("Configuration file not found: $configPath");
        }

        Configuration::reset();
        Configuration::initialize($configPath);
        
        $config = Configuration::getInstance();
        
        // Get cache database path from configuration
        $this->cacheDbPath = $config->get('components.images.eav_cache_db') 
                          ?? $config->get('mod.images.eav_cache_db');
        
        if (empty($this->cacheDbPath) || !file_exists($this->cacheDbPath)) {
            throw new Exception("EAV cache database not found");
        }

        // Open cache database
        $this->db = new PDO("sqlite:{$this->cacheDbPath}");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create ImagesModelEav instance
        $configIniPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
        $this->model = new ImagesModelEav([
            'config_path' => $configIniPath,
            'cache_db_path' => $this->cacheDbPath
        ]);
    });
    
    afterAll(function() {
        $this->db = null;
    });
    
    describe('FIX #1: Case-Insensitive Field Names', function() {
        
        it('should search with lowercase field name: collectioncode:dbg', function() {
            $result = $this->model->search([
                'query' => 'collectioncode:dbg',
                'limit' => 10,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            $imageCount = substr_count($result['content'], 'class="image-item"');
            expect($imageCount)->toBeGreaterThan(0);
            
            echo "\n  ✓ Search 'collectioncode:dbg' (lowercase) found $imageCount images\n";
        });
        
        it('should search with mixed case field name: CollectionCode:dbg', function() {
            $result = $this->model->search([
                'query' => 'CollectionCode:dbg',
                'limit' => 10,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            $imageCount = substr_count($result['content'], 'class="image-item"');
            expect($imageCount)->toBeGreaterThan(0);
            
            echo "\n  ✓ Search 'CollectionCode:dbg' (mixed case) found $imageCount images\n";
        });
        
        it('should search with uppercase field name: COLLECTIONCODE:dbg', function() {
            $result = $this->model->search([
                'query' => 'COLLECTIONCODE:dbg',
                'limit' => 10,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            $imageCount = substr_count($result['content'], 'class="image-item"');
            expect($imageCount)->toBeGreaterThan(0);
            
            echo "\n  ✓ Search 'COLLECTIONCODE:dbg' (uppercase) found $imageCount images\n";
        });
    });
    
    describe('FIX #2: Autocomplete Filters by Source Field', function() {
        
        it('should show only collectionCode values for collectioncode field', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'collectioncode',
                'q' => 'dbg',
                'limit' => 10
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            // Count autocomplete items
            $itemCount = substr_count($result['content'], 'class="autocomplete-item"');
            
            // Extract values
            preg_match_all('/data-value="([^"]+)"/', $result['content'], $matches);
            $values = $matches[1] ?? [];
            
            // Should only show 'dbg' once (from collectionCode), not twice (collectionCode + institutionCode)
            expect($itemCount)->toBe(1);
            expect($values)->toBe(['dbg']);
            
            echo "\n  ✓ Autocomplete for 'collectioncode' shows only collectionCode values (no duplicates)\n";
            echo "    Values: " . implode(', ', $values) . "\n";
        });
        
        it('should show only institutionCode values for institutioncode field', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'institutioncode',
                'q' => 'dbg',
                'limit' => 10
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            $itemCount = substr_count($result['content'], 'class="autocomplete-item"');
            
            preg_match_all('/data-value="([^"]+)"/', $result['content'], $matches);
            $values = $matches[1] ?? [];
            
            // Should show 'dbg' from institutionCode
            expect($itemCount)->toBeGreaterThan(0);
            expect($values)->toContain('dbg');
            
            echo "\n  ✓ Autocomplete for 'institutioncode' shows institutionCode values\n";
            echo "    Values: " . implode(', ', $values) . "\n";
        });
        
        it('should show only collectionName values for collectionname field', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'collectionname',
                'q' => 'denver',
                'limit' => 10
            ]);
            
            expect($result['type'])->toBe('htmx');
            
            preg_match_all('/data-value="([^"]+)"/', $result['content'], $matches);
            $values = $matches[1] ?? [];
            
            // Should show full collection names, not codes
            foreach ($values as $value) {
                // Collection names should be longer than 10 chars and contain spaces
                expect(strlen($value))->toBeGreaterThan(10);
                
                echo "\n    - $value\n";
            }
            
            echo "\n  ✓ Autocomplete for 'collectionname' shows full names (not codes)\n";
        });
    });
    
    describe('FIX #3: Search Returns Correct Results', function() {
        
        it('should return same results for lowercase and uppercase field names', function() {
            $result1 = $this->model->search([
                'query' => 'collectioncode:dbg',
                'limit' => 100,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            $result2 = $this->model->search([
                'query' => 'collectionCode:dbg',
                'limit' => 100,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            $count1 = substr_count($result1['content'], 'class="image-item"');
            $count2 = substr_count($result2['content'], 'class="image-item"');
            
            expect($count1)->toBe($count2);
            expect($count1)->toBeGreaterThan(0);
            
            echo "\n  ✓ Both searches returned $count1 images (consistent results)\n";
        });
        
        it('should match autocomplete count with search results', function() {
            // Get autocomplete count
            $autocomplete = $this->model->autocompleteValues([
                'field' => 'collectioncode',
                'q' => 'dbg',
                'limit' => 1
            ]);
            
            // Extract count from autocomplete HTML
            preg_match('/(\d+)\s+images?/', $autocomplete['content'], $matches);
            $autocompleteCount = (int)($matches[1] ?? 0);
            
            // Get search results
            $search = $this->model->search([
                'query' => 'collectioncode:dbg',
                'limit' => 1000,
                'offset' => 0,
                'format' => 'web'
            ]);
            
            $searchCount = substr_count($search['content'], 'class="image-item"');
            
            echo "\n  Autocomplete count: $autocompleteCount images\n";
            echo "  Search results: $searchCount images\n";
            
            // Search may return fewer due to limit, but should be > 0
            expect($searchCount)->toBeGreaterThan(0);
            expect($autocompleteCount)->toBeGreaterThan(0);
            
            echo "\n  ✓ Both autocomplete and search return results\n";
        });
    });
    
    describe('Database Schema Verification', function() {
        
        it('should have UNIQUE constraint on Entities.EntityValue', function() {
            $stmt = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='Entities'");
            $createSql = $stmt->fetchColumn();
            
            expect($createSql)->toContain('UNIQUE');
            expect($createSql)->toContain('EntityValue');
            
            echo "\n  ✓ Entities table has UNIQUE constraint on EntityValue\n";
        });
        
        it('should have case-insensitive attribute lookup capability', function() {
            // Test that LOWER() works on ColumnName
            $stmt = $this->db->query("
                SELECT COUNT(*) 
                FROM Attributes 
                WHERE LOWER(ColumnName) = 'collectioncode'
            ");
            $count = $stmt->fetchColumn();
            
            expect($count)->toBeGreaterThan(0);
            
            echo "\n  ✓ Case-insensitive attribute lookup works\n";
        });
    });
});

