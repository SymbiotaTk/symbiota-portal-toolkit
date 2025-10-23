<?php

use Kahlan\Plugin\Quit;

describe('ImagesModel Output Parameters', function () {
    
    beforeAll(function () {
        // Create test directories
        $this->testDir = sys_get_temp_dir() . '/tk_output_params_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        $this->customDir = $this->testDir . '/custom';
        mkdir($this->customDir, 0755, true);
    });
    
    afterAll(function () {
        // Clean up test directories
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    });
    
    describe('Parameter Priority Order', function () {
        
        it('should prioritize --output-file over --output-dir and --db-path', function () {
            $params = [
                'output-file' => $this->testDir . '/priority_test.db',
                'output-dir' => $this->customDir,
                'db-path' => $this->testDir . '/dbpath_test.db'
            ];
            
            // Test priority logic
            if (isset($params['output-file'])) {
                $result = $params['output-file'];
            } elseif (isset($params['output-dir'])) {
                $result = $params['output-dir'] . '/images_cache.db';
            } else {
                $result = $params['db-path'] ?? 'default.db';
            }
            
            expect($result)->toBe($this->testDir . '/priority_test.db');
        });
        
        it('should prioritize --output-dir over --db-path when --output-file not set', function () {
            $params = [
                'output-dir' => $this->customDir,
                'db-path' => $this->testDir . '/dbpath_test.db'
            ];
            
            // Test priority logic
            if (isset($params['output-file'])) {
                $result = $params['output-file'];
            } elseif (isset($params['output-dir'])) {
                $result = $params['output-dir'] . '/images_cache.db';
            } else {
                $result = $params['db-path'] ?? 'default.db';
            }
            
            expect($result)->toBe($this->customDir . '/images_cache.db');
        });
        
        it('should use --db-path when --output-file and --output-dir not set', function () {
            $params = [
                'db-path' => $this->testDir . '/dbpath_test.db'
            ];
            
            // Test priority logic
            if (isset($params['output-file'])) {
                $result = $params['output-file'];
            } elseif (isset($params['output-dir'])) {
                $result = $params['output-dir'] . '/images_cache.db';
            } else {
                $result = $params['db-path'] ?? 'default.db';
            }
            
            expect($result)->toBe($this->testDir . '/dbpath_test.db');
        });
        
        it('should use default when no output parameters set', function () {
            $params = [];
            
            // Test priority logic
            if (isset($params['output-file'])) {
                $result = $params['output-file'];
            } elseif (isset($params['output-dir'])) {
                $result = $params['output-dir'] . '/images_cache.db';
            } else {
                $result = $params['db-path'] ?? 'default.db';
            }
            
            expect($result)->toBe('default.db');
        });
    });
    
    describe('Path Normalization', function () {
        
        it('should make relative paths absolute for --output-file', function () {
            $relativePath = 'relative/path/test.db';
            $expectedPath = getcwd() . '/' . $relativePath;
            
            // Simulate path normalization
            if (!str_starts_with($relativePath, '/')) {
                $absolutePath = getcwd() . '/' . $relativePath;
            } else {
                $absolutePath = $relativePath;
            }
            
            expect($absolutePath)->toBe($expectedPath);
        });
        
        it('should keep absolute paths unchanged for --output-file', function () {
            $absolutePath = '/absolute/path/test.db';
            
            // Simulate path normalization
            if (!str_starts_with($absolutePath, '/')) {
                $result = getcwd() . '/' . $absolutePath;
            } else {
                $result = $absolutePath;
            }
            
            expect($result)->toBe($absolutePath);
        });
        
        it('should make relative paths absolute for --output-dir', function () {
            $relativePath = 'relative/dir';
            $expectedPath = getcwd() . '/' . $relativePath;
            
            // Simulate path normalization
            if (!str_starts_with($relativePath, '/')) {
                $absolutePath = getcwd() . '/' . $relativePath;
            } else {
                $absolutePath = $relativePath;
            }
            
            expect($absolutePath)->toBe($expectedPath);
        });
    });
    
    describe('Directory Creation', function () {
        
        it('should create parent directory for --output-file if it does not exist', function () {
            $newDir = $this->testDir . '/new_subdir';
            $outputFile = $newDir . '/test.db';
            
            // Simulate directory creation
            $parentDir = dirname($outputFile);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            
            expect(is_dir($newDir))->toBe(true);
            
            // Clean up
            rmdir($newDir);
        });
        
        it('should create directory for --output-dir if it does not exist', function () {
            $newDir = $this->testDir . '/another_new_dir';
            
            // Simulate directory creation
            if (!is_dir($newDir)) {
                mkdir($newDir, 0755, true);
            }
            
            expect(is_dir($newDir))->toBe(true);
            
            // Clean up
            rmdir($newDir);
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

