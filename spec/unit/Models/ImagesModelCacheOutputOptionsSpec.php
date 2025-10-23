<?php

/**
 * Test cache build and info commands with alternative output locations
 * Uses spec/fixtures/images/eav/fixtures.db test data
 */

use Kahlan\Plugin\Quit;

describe('ImagesModel Cache Output Options', function () {
    
    beforeAll(function () {
        // Create test output directory
        $this->testDir = sys_get_temp_dir() . '/tk_cache_output_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        // Get fixture source.db path (use fixtures.db as source)
        $this->fixtureSourceDb = __DIR__ . '/../../fixtures/images/eav/fixtures.db';

        // Verify fixture exists
        if (!file_exists($this->fixtureSourceDb)) {
            throw new Exception("Fixture source.db not found: {$this->fixtureSourceDb}");
        }
    });
    
    afterAll(function () {
        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    });
    
    describe('EAV Cache with --output-file', function () {
        
        it('should build EAV cache to custom file location', function () {
            $customCachePath = $this->testDir . '/custom_eav_cache.db';
            
            $command = sprintf(
                'php index.php images cache-eav --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            expect($returnCode)->toBe(0);
            expect(file_exists($customCachePath))->toBe(true);
            
            // Verify it's a valid SQLite database
            $db = new PDO('sqlite:' . $customCachePath);
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            
            expect($tables)->toContain('Entities');
            expect($tables)->toContain('EAV');
            expect($tables)->toContain('ValuesText');
        });
        
        it('should show info for EAV cache at custom location', function () {
            $customCachePath = $this->testDir . '/custom_eav_cache.db';
            
            // Build cache first
            $buildCommand = sprintf(
                'php index.php images cache-eav --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info
            $infoCommand = sprintf(
                'php index.php images cache-eav --info --output-file=%s',
                escapeshellarg($customCachePath)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain('Cache Information');
            expect($outputText)->toContain('Entities');
        });
    });
    
    describe('EAV Cache with --output-dir', function () {
        
        it('should build EAV cache to custom directory', function () {
            $customDir = $this->testDir . '/eav_cache_dir';
            $expectedCachePath = $customDir . '/images_cache.db';
            
            $command = sprintf(
                'php index.php images cache-eav --build --sourcedb=%s --output-dir=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customDir)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            expect($returnCode)->toBe(0);
            expect(is_dir($customDir))->toBe(true);
            expect(file_exists($expectedCachePath))->toBe(true);
        });
        
        it('should show info for EAV cache in custom directory', function () {
            $customDir = $this->testDir . '/eav_cache_dir';
            $expectedCachePath = $customDir . '/images_cache.db';
            
            // Build cache first
            $buildCommand = sprintf(
                'php index.php images cache-eav --build --sourcedb=%s --output-dir=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customDir)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info
            $infoCommand = sprintf(
                'php index.php images cache-eav --info --output-dir=%s',
                escapeshellarg($customDir)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain('Cache Information');
            expect($outputText)->toContain('Entities');
        });
    });
    
    describe('Hybrid Cache with --output-file', function () {
        
        it('should build hybrid cache to custom file location', function () {
            $customCachePath = $this->testDir . '/custom_hybrid_cache.db';
            
            $command = sprintf(
                'php index.php images cache --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            expect($returnCode)->toBe(0);
            expect(file_exists($customCachePath))->toBe(true);
            
            // Verify it's a valid SQLite database
            $db = new PDO('sqlite:' . $customCachePath);
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            
            expect($tables)->toContain('Entities');
            expect($tables)->toContain('Collections');
            expect($tables)->toContain('Attributes');
        });
        
        it('should show info for hybrid cache at custom location', function () {
            $customCachePath = $this->testDir . '/custom_hybrid_cache.db';
            
            // Build cache first
            $buildCommand = sprintf(
                'php index.php images cache --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info
            $infoCommand = sprintf(
                'php index.php images cache --info --output-file=%s',
                escapeshellarg($customCachePath)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain('Hybrid Index Information');
            expect($outputText)->toContain($customCachePath);
        });
    });
    
    describe('Hybrid Cache with --output-dir', function () {
        
        it('should build hybrid cache to custom directory', function () {
            $customDir = $this->testDir . '/hybrid_cache_dir';
            $expectedCachePath = $customDir . '/hybrid_index.db';
            
            $command = sprintf(
                'php index.php images cache --build --sourcedb=%s --output-dir=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customDir)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            expect($returnCode)->toBe(0);
            expect(is_dir($customDir))->toBe(true);
            expect(file_exists($expectedCachePath))->toBe(true);
        });
        
        it('should show info for hybrid cache in custom directory', function () {
            $customDir = $this->testDir . '/hybrid_cache_dir';
            $expectedCachePath = $customDir . '/hybrid_index.db';
            
            // Build cache first
            $buildCommand = sprintf(
                'php index.php images cache --build --sourcedb=%s --output-dir=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customDir)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info
            $infoCommand = sprintf(
                'php index.php images cache --info --output-dir=%s',
                escapeshellarg($customDir)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain($expectedCachePath);
        });
    });
    
    describe('Info with --sourcedb parameter', function () {
        
        it('should display source.db info for EAV cache', function () {
            $customCachePath = $this->testDir . '/eav_with_source_info.db';
            
            // Build cache
            $buildCommand = sprintf(
                'php index.php images cache-eav --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info with --sourcedb
            $infoCommand = sprintf(
                'php index.php images cache-eav --info --output-file=%s --sourcedb=%s',
                escapeshellarg($customCachePath),
                escapeshellarg($this->fixtureSourceDb)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain('Source database');
            expect($outputText)->toContain('fixtures.db');
        });
        
        it('should display source.db info for hybrid cache', function () {
            $customCachePath = $this->testDir . '/hybrid_with_source_info.db';
            
            // Build cache
            $buildCommand = sprintf(
                'php index.php images cache --build --sourcedb=%s --output-file=%s --limit=50',
                escapeshellarg($this->fixtureSourceDb),
                escapeshellarg($customCachePath)
            );
            exec($buildCommand . ' 2>&1', $buildOutput, $buildReturnCode);
            expect($buildReturnCode)->toBe(0);
            
            // Get info with --sourcedb
            $infoCommand = sprintf(
                'php index.php images cache --info --output-file=%s --sourcedb=%s',
                escapeshellarg($customCachePath),
                escapeshellarg($this->fixtureSourceDb)
            );
            
            exec($infoCommand . ' 2>&1', $output, $returnCode);
            $outputText = implode("\n", $output);
            
            expect($returnCode)->toBe(0);
            expect($outputText)->toContain('Source database');
            expect($outputText)->toContain('fixtures.db');
        });
    });
    
    // Helper method to remove directory recursively
    $this->removeDirectory = function ($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };
});

