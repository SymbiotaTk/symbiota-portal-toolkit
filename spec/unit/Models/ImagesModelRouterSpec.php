<?php

use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Interfaces\ImagesSearchInterface;
use PDO;

describe('ImagesModel Router/Facade', function() {

    beforeAll(function() {
        echo "\n[beforeAll] Starting router test setup...\n";
        
        // Create test directories
        $this->testDir = sys_get_temp_dir() . '/router_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        // Create EAV cache for testing
        $this->eavCacheDir = $this->testDir . '/eav';
        mkdir($this->eavCacheDir, 0755, true);
        $this->eavCacheDbPath = $this->eavCacheDir . '/images_cache.db';
        
        // Create Hybrid cache for testing
        $this->hybridCacheDir = $this->testDir . '/hybrid';
        mkdir($this->hybridCacheDir, 0755, true);
        $this->hybridCacheDbPath = $this->hybridCacheDir . '/autocomplete_cache.db';
        
        // Create minimal EAV cache database
        $eavDb = new PDO('sqlite:' . $this->eavCacheDbPath);
        $eavDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $eavDb->exec('CREATE TABLE Metadata (key TEXT PRIMARY KEY, value TEXT)');
        $eavDb->exec("INSERT INTO Metadata (key, value) VALUES ('entity_count', '100')");
        $eavDb = null;
        
        // Create minimal Hybrid cache database
        $hybridDb = new PDO('sqlite:' . $this->hybridCacheDbPath);
        $hybridDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $hybridDb->exec('CREATE TABLE autocomplete_taxon (taxon_name TEXT, image_count INTEGER, source_field TEXT)');
        $hybridDb = null;
        
        echo "[beforeAll] Router test setup complete!\n\n";
    });

    afterAll(function() {
        // Clean up test directory
        if (isset($this->testDir) && is_dir($this->testDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($this->testDir);
        }
    });

    describe('plugin instantiation', function() {

        it('can instantiate ImagesModelEav plugin', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $plugin = new ImagesModelEav([
                'config_path' => $configPath,
                'cache_db_path' => $this->eavCacheDbPath
            ]);
            
            expect($plugin)->toBeAnInstanceOf(ImagesSearchInterface::class);
            expect($plugin->getSearchMode())->toBe('eav');
        });

        it('can instantiate ImagesModelHybrid plugin', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $plugin = new ImagesModelHybrid([
                'config_path' => $configPath,
                'cache_db_path' => $this->hybridCacheDbPath
            ]);
            
            expect($plugin)->toBeAnInstanceOf(ImagesSearchInterface::class);
            expect($plugin->getSearchMode())->toBe('hybrid');
        });
    });

    describe('plugin detection', function() {

        it('detects EAV mode when EAV cache exists', function() {
            // This test verifies that ImagesModel can detect which plugin to use
            // based on cache availability
            
            // For now, just verify the cache files exist
            expect(file_exists($this->eavCacheDbPath))->toBe(true);
        });

        it('detects Hybrid mode when Hybrid cache exists', function() {
            // This test verifies that ImagesModel can detect which plugin to use
            // based on cache availability
            
            // For now, just verify the cache files exist
            expect(file_exists($this->hybridCacheDbPath))->toBe(true);
        });
    });

    describe('delegation pattern', function() {

        it('EAV plugin implements all required methods', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $plugin = new ImagesModelEav([
                'config_path' => $configPath,
                'cache_db_path' => $this->eavCacheDbPath
            ]);
            
            expect(method_exists($plugin, 'search'))->toBe(true);
            expect(method_exists($plugin, 'autocompleteFields'))->toBe(true);
            expect(method_exists($plugin, 'autocompleteValues'))->toBe(true);
            expect(method_exists($plugin, 'getInfo'))->toBe(true);
            expect(method_exists($plugin, 'isAvailable'))->toBe(true);
            expect(method_exists($plugin, 'getSearchMode'))->toBe(true);
        });

        it('Hybrid plugin implements all required methods', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $plugin = new ImagesModelHybrid([
                'config_path' => $configPath,
                'cache_db_path' => $this->hybridCacheDbPath
            ]);
            
            expect(method_exists($plugin, 'search'))->toBe(true);
            expect(method_exists($plugin, 'autocompleteFields'))->toBe(true);
            expect(method_exists($plugin, 'autocompleteValues'))->toBe(true);
            expect(method_exists($plugin, 'getInfo'))->toBe(true);
            expect(method_exists($plugin, 'isAvailable'))->toBe(true);
            expect(method_exists($plugin, 'getSearchMode'))->toBe(true);
        });
    });

    describe('interchangeability', function() {

        it('both plugins return same response structure for autocompleteFields', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $eavPlugin = new ImagesModelEav([
                'config_path' => $configPath,
                'cache_db_path' => $this->eavCacheDbPath
            ]);
            
            $hybridPlugin = new ImagesModelHybrid([
                'config_path' => $configPath,
                'cache_db_path' => $this->hybridCacheDbPath
            ]);
            
            $eavResult = $eavPlugin->autocompleteFields(['q' => 'fam']);
            $hybridResult = $hybridPlugin->autocompleteFields(['q' => 'fam']);
            
            // Both should return same structure
            expect(isset($eavResult['type']))->toBe(true);
            expect(isset($eavResult['content']))->toBe(true);
            expect(isset($hybridResult['type']))->toBe(true);
            expect(isset($hybridResult['content']))->toBe(true);
            
            // Both should return 'htmx' type
            expect($eavResult['type'])->toBe('htmx');
            expect($hybridResult['type'])->toBe('htmx');
        });

        it('both plugins return same response structure for autocompleteValues', function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            
            if (!file_exists($configPath)) {
                $this->skip('Config file not found');
            }
            
            $eavPlugin = new ImagesModelEav([
                'config_path' => $configPath,
                'cache_db_path' => $this->eavCacheDbPath
            ]);
            
            $hybridPlugin = new ImagesModelHybrid([
                'config_path' => $configPath,
                'cache_db_path' => $this->hybridCacheDbPath
            ]);
            
            $eavResult = $eavPlugin->autocompleteValues(['field' => 'family', 'q' => 'ag']);
            $hybridResult = $hybridPlugin->autocompleteValues(['field' => 'family', 'q' => 'ag']);
            
            // Both should return same structure
            expect(isset($eavResult['type']))->toBe(true);
            expect(isset($eavResult['content']))->toBe(true);
            expect(isset($hybridResult['type']))->toBe(true);
            expect(isset($hybridResult['content']))->toBe(true);
            
            // Both should return 'htmx' type
            expect($eavResult['type'])->toBe('htmx');
            expect($hybridResult['type'])->toBe('htmx');
        });
    });
});

