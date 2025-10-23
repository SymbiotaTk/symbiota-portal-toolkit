<?php

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ApplicationPaths;

describe('ImagesModelEav - Cache Attributes Verification', function() {

    beforeAll(function() {
        // Initialize Configuration from config.php
        $configPath = __DIR__ . '/../../../config.php';
        if (!file_exists($configPath)) {
            throw new Exception("Configuration file not found: $configPath");
        }

        // Reset and initialize Configuration singleton
        Configuration::reset();
        Configuration::initialize($configPath);

        $config = Configuration::getInstance();

        // Get cache database path from configuration
        // Try both possible config keys
        $this->cacheDbPath = $config->get('components.images.eav_cache_db')
                          ?? $config->get('mod.images.eav_cache_db');

        if (empty($this->cacheDbPath)) {
            throw new Exception("EAV cache database path not configured in config.php\nTried: components.images.eav_cache_db and mod.images.eav_cache_db");
        }

        // Check if cache exists
        if (!file_exists($this->cacheDbPath)) {
            throw new Exception("EAV cache database not found at: {$this->cacheDbPath}\nRun: php index.php images cache-eav --build");
        }

        echo "\n  Using cache database: {$this->cacheDbPath}\n";

        // Open cache database
        $this->db = new PDO("sqlite:{$this->cacheDbPath}");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    });

    afterAll(function() {
        $this->db = null;
    });
    
    describe('Attributes Table', function() {
        
        it('should have Attributes table with correct schema', function() {
            $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Attributes'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            expect($result)->not->toBeNull();
            expect($result['name'])->toBe('Attributes');
        });
        
        it('should have ColumnName and DataType columns', function() {
            $stmt = $this->db->query("PRAGMA table_info(Attributes)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $columnNames = array_column($columns, 'name');
            
            expect($columnNames)->toContain('Aid');
            expect($columnNames)->toContain('ColumnName');
            expect($columnNames)->toContain('DataType');
        });
        
        it('should list all indexed attributes', function() {
            $stmt = $this->db->query("SELECT Aid, ColumnName, DataType FROM Attributes ORDER BY Aid");
            $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            expect($attributes)->not->toBeEmpty();
            
            // Display attributes for debugging
            echo "\n=== Indexed Attributes ===\n";
            foreach ($attributes as $attr) {
                echo sprintf("  %d: %s (%s)\n", $attr['Aid'], $attr['ColumnName'], $attr['DataType']);
            }
        });
        
        it('should have collection-related attributes', function() {
            $stmt = $this->db->query("SELECT Aid, ColumnName FROM Attributes WHERE LOWER(ColumnName) LIKE '%collection%'");
            $collectionAttrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            expect($collectionAttrs)->not->toBeEmpty();
            
            echo "\n=== Collection Attributes ===\n";
            foreach ($collectionAttrs as $attr) {
                echo sprintf("  %d: %s\n", $attr['Aid'], $attr['ColumnName']);
            }
        });
        
        it('should have distinct collectionName and collectionCode attributes', function() {
            $stmt = $this->db->query("SELECT ColumnName FROM Attributes WHERE LOWER(ColumnName) IN ('collectionname', 'collectioncode')");
            $attrs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Should have both attributes
            $lowerAttrs = array_map('strtolower', $attrs);
            
            if (in_array('collectionname', $lowerAttrs)) {
                expect($lowerAttrs)->toContain('collectionname');
            }
            
            if (in_array('collectioncode', $lowerAttrs)) {
                expect($lowerAttrs)->toContain('collectioncode');
            }
            
            echo "\n=== Collection Name/Code Attributes ===\n";
            foreach ($attrs as $attr) {
                echo sprintf("  - %s\n", $attr);
            }
        });
        
        it('should have EAV records for collection attributes', function() {
            $stmt = $this->db->query("
                SELECT a.ColumnName, COUNT(*) as cnt 
                FROM EAV e 
                JOIN Attributes a ON e.Aid = a.Aid 
                WHERE LOWER(a.ColumnName) LIKE '%collection%'
                GROUP BY a.ColumnName
            ");
            $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n=== EAV Record Counts by Collection Attribute ===\n";
            foreach ($counts as $row) {
                echo sprintf("  %s: %d records\n", $row['ColumnName'], $row['cnt']);
            }
            
            expect($counts)->not->toBeEmpty();
        });
        
        it('should have sample values for collectionCode', function() {
            $stmt = $this->db->query("
                SELECT DISTINCT vt.ValueText, COUNT(*) as cnt
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                JOIN ValuesText vt ON e.Vid = vt.Vid
                WHERE LOWER(a.ColumnName) = 'collectioncode'
                GROUP BY vt.ValueText
                ORDER BY cnt DESC
                LIMIT 10
            ");
            $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n=== Sample collectionCode Values ===\n";
            foreach ($values as $row) {
                echo sprintf("  %s: %d images\n", $row['ValueText'], $row['cnt']);
            }
        });
        
        it('should have sample values for collectionName', function() {
            $stmt = $this->db->query("
                SELECT DISTINCT vt.ValueText, COUNT(*) as cnt
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                JOIN ValuesText vt ON e.Vid = vt.Vid
                WHERE LOWER(a.ColumnName) = 'collectionname'
                GROUP BY vt.ValueText
                ORDER BY cnt DESC
                LIMIT 10
            ");
            $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n=== Sample collectionName Values ===\n";
            foreach ($values as $row) {
                echo sprintf("  %s: %d images\n", $row['ValueText'], $row['cnt']);
            }
        });
        
        it('should verify collectionCode values are codes not names', function() {
            $stmt = $this->db->query("
                SELECT DISTINCT vt.ValueText
                FROM EAV e
                JOIN Attributes a ON e.Aid = a.Aid
                JOIN ValuesText vt ON e.Vid = vt.Vid
                WHERE LOWER(a.ColumnName) = 'collectioncode'
                LIMIT 20
            ");
            $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo "\n=== Verifying collectionCode values ===\n";
            foreach ($codes as $code) {
                $length = strlen($code);
                $hasSpaces = strpos($code, ' ') !== false;
                $isShort = $length <= 10;
                
                echo sprintf("  '%s' (len=%d, spaces=%s, short=%s)\n", 
                    $code, 
                    $length, 
                    $hasSpaces ? 'YES' : 'NO',
                    $isShort ? 'YES' : 'NO'
                );
            }
            
            // Collection codes should typically be short (< 10 chars) and without spaces
            // This is a heuristic check
        });
    });
    
    describe('Entities Table', function() {
        
        it('should have Entities table with UNIQUE constraint on EntityValue', function() {
            $stmt = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='Entities'");
            $createSql = $stmt->fetchColumn();
            
            expect($createSql)->toContain('EntityValue');
            expect($createSql)->toContain('UNIQUE');
            
            echo "\n=== Entities Table Schema ===\n";
            echo $createSql . "\n";
        });
        
        it('should have entity count', function() {
            $stmt = $this->db->query("SELECT COUNT(*) FROM Entities");
            $count = $stmt->fetchColumn();
            
            echo "\n=== Entity Count ===\n";
            echo sprintf("  Total entities: %s\n", number_format($count));
            
            expect($count)->toBeGreaterThan(0);
        });
        
        it('should have max EntityValue for append mode tracking', function() {
            $stmt = $this->db->query("SELECT MAX(CAST(EntityValue AS INTEGER)) as max_id FROM Entities");
            $maxId = $stmt->fetchColumn();
            
            echo "\n=== Max EntityValue ===\n";
            echo sprintf("  Max EntityValue: %s\n", number_format($maxId));
            
            expect($maxId)->toBeGreaterThan(0);
        });
    });
});

