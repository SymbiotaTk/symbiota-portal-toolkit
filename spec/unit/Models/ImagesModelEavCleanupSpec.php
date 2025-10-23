<?php

use Symbiota\Helpers\Models\ImagesModel;

describe('ImagesModel EAV Cache Cleanup', function() {
    
    beforeAll(function() {
        $this->model = new ImagesModel();
        $this->testDir = sys_get_temp_dir() . '/eav_cleanup_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        $this->cacheDbPath = $this->testDir . '/cache.db';
        $this->workDbPath = $this->testDir . '/cache_work.db';
    });
    
    afterAll(function() {
        // Cleanup test directory
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    });
    
    describe('Help Option', function() {
        
        it('should display help with --help option', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, ['help' => true]);
            
            expect($result['type'])->toBe('success');
            expect($result['content'])->toBe('Help displayed');
        });
        
        it('should display help with -h option', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, ['h' => true]);
            
            expect($result['type'])->toBe('success');
            expect($result['content'])->toBe('Help displayed');
        });
        
    });
    
    describe('Cleanup Modes', function() {
        
        beforeEach(function() {
            // Create test database files
            touch($this->cacheDbPath);
            touch($this->workDbPath);
        });
        
        it('should require cleanup mode when no options provided', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath
            ]);
            
            expect($result['type'])->toBe('error');
            expect($result['message'])->toContain('No cleanup mode specified');
        });
        
        it('should clean only work database with --work option', function() {
            expect(file_exists($this->cacheDbPath))->toBe(true);
            expect(file_exists($this->workDbPath))->toBe(true);
            
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath,
                'work' => true
            ]);
            
            expect($result['type'])->toBe('success');
            expect(file_exists($this->cacheDbPath))->toBe(true);  // Cache still exists
            expect(file_exists($this->workDbPath))->toBe(false);  // Work removed
        });
        
        it('should clean all databases with --full option', function() {
            // Recreate files for this test
            touch($this->cacheDbPath);
            touch($this->workDbPath);
            
            expect(file_exists($this->cacheDbPath))->toBe(true);
            expect(file_exists($this->workDbPath))->toBe(true);
            
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->model, [
                'db-path' => $this->cacheDbPath,
                'full' => true
            ]);
            
            expect($result['type'])->toBe('success');
            expect(file_exists($this->cacheDbPath))->toBe(false);  // Cache removed
            expect(file_exists($this->workDbPath))->toBe(false);  // Work removed
        });
        
    });
    
    describe('Error Handling', function() {
        
        it('should handle missing database files gracefully', function() {
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('eavCacheCleanup');
            $method->setAccessible(true);
            
            // Try to clean non-existent files
            $result = $method->invoke($this->model, [
                'db-path' => $this->testDir . '/nonexistent.db',
                'work' => true
            ]);
            
            // Should succeed even if files don't exist
            expect($result['type'])->toBe('success');
        });
        
    });
    
});

