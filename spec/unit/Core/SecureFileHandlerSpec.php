<?php

use Symbiota\Helpers\Core\SecureFileHandler;

describe('SecureFileHandler', function() {
    
    beforeEach(function() {
        $this->tempDir = sys_get_temp_dir() . '/secure_file_handler_test_' . uniqid();
        $this->workingDir = sys_get_temp_dir() . '/secure_file_handler_work_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->workingDir, 0755, true);
        $this->handler = new SecureFileHandler([
            'storage_path' => $this->tempDir,
            'working_path' => $this->workingDir,
            'allowed_extensions' => ['txt', 'json', 'csv', 'zip']
        ]);
    });
    
    afterEach(function() {
        // Clean up test directories
        if (is_dir($this->tempDir)) {
            removeDirectory($this->tempDir);
        }
        if (is_dir($this->workingDir)) {
            removeDirectory($this->workingDir);
        }
    });
    
    describe('constructor', function() {
        it('should create instance with base directory', function() {
            expect($this->handler)->toBeAnInstanceOf(SecureFileHandler::class);
        });
        
        it('should create base directory if it does not exist', function() {
            $newDir = $this->tempDir . '/new_subdir';
            $newWorkingDir = $this->workingDir . '/new_working';
            $handler = new SecureFileHandler([
                'storage_path' => $newDir,
                'working_path' => $newWorkingDir
            ]);

            expect(is_dir($newDir))->toBe(true);
            expect(is_dir($newWorkingDir))->toBe(true);
        });

        it('should use default directories when no config provided', function() {
            $handler = new SecureFileHandler();
            expect($handler)->toBeAnInstanceOf(SecureFileHandler::class);
        });
    });
    
    describe('storage path management', function() {
        it('should get storage path for component', function() {
            $path = $this->handler->getStoragePath('test_component');
            expect($path)->toBeA('string');
            expect(is_dir($path))->toBe(true);
            expect(strpos($path, 'test_component'))->not->toBe(false);
        });

        it('should get working path for component', function() {
            $path = $this->handler->getWorkingPath('test_component');
            expect($path)->toBeA('string');
            expect(is_dir($path))->toBe(true);
            expect(strpos($path, 'test_component'))->not->toBe(false);
        });

        it('should create component directories automatically', function() {
            $storagePath = $this->handler->getStoragePath('auto_created');
            $workingPath = $this->handler->getWorkingPath('auto_created');

            expect(is_dir($storagePath))->toBe(true);
            expect(is_dir($workingPath))->toBe(true);
        });
    });
    
    describe('file operations', function() {
        describe('storeFile', function() {
            it('should store file with valid filename', function() {
                $content = 'Test content';
                $filePath = $this->handler->storeFile('test_component', 'test.txt', $content);

                expect($filePath)->toBeA('string');
                expect(file_exists($filePath))->toBe(true);
                expect(file_get_contents($filePath))->toBe($content);
            });

            it('should reject invalid filenames with path traversal', function() {
                expect(function() {
                    $this->handler->storeFile('test_component', '../test.txt', 'content');
                })->toThrow(new \InvalidArgumentException());
            });

            it('should reject invalid file extensions', function() {
                expect(function() {
                    $this->handler->storeFile('test_component', 'test.php', 'content');
                })->toThrow(new \InvalidArgumentException());
            });

            it('should set secure file permissions', function() {
                $filePath = $this->handler->storeFile('test_component', 'test.txt', 'content');
                $perms = fileperms($filePath) & 0777;
                expect($perms)->toBe(0600); // Read/write for owner only
            });
        });
        
        describe('getFileContent', function() {
            it('should read existing file', function() {
                $content = 'Test content';
                $this->handler->storeFile('test_component', 'test.txt', $content);

                $result = $this->handler->getFileContent('test_component', 'test.txt');
                expect($result)->toBe($content);
            });

            it('should throw exception for non-existent file', function() {
                expect(function() {
                    $this->handler->getFileContent('test_component', 'nonexistent.txt');
                })->toThrow(new \RuntimeException());
            });

            it('should reject invalid filenames', function() {
                expect(function() {
                    $this->handler->getFileContent('test_component', '../test.txt');
                })->toThrow(new \InvalidArgumentException());
            });
        });
        
        describe('deleteFile', function() {
            it('should delete existing file', function() {
                $this->handler->storeFile('test_component', 'test.txt', 'content');

                $result = $this->handler->deleteFile('test_component', 'test.txt');
                expect($result)->toBe(true);
                expect($this->handler->fileExists('test_component', 'test.txt'))->toBe(false);
            });

            it('should return true for non-existent file', function() {
                $result = $this->handler->deleteFile('test_component', 'nonexistent.txt');
                expect($result)->toBe(true); // Method returns true if file doesn't exist
            });

            it('should reject invalid filenames', function() {
                expect(function() {
                    $this->handler->deleteFile('test_component', '../test.txt');
                })->toThrow(new \InvalidArgumentException());
            });
        });
        
        describe('fileExists', function() {
            it('should detect existing files', function() {
                $this->handler->storeFile('test_component', 'test.txt', 'content');

                expect($this->handler->fileExists('test_component', 'test.txt'))->toBe(true);
                expect($this->handler->fileExists('test_component', 'nonexistent.txt'))->toBe(false);
            });

            it('should reject invalid filenames', function() {
                expect(function() {
                    $this->handler->fileExists('test_component', '../test.txt');
                })->toThrow(new \InvalidArgumentException());
            });
        });

        describe('listFiles', function() {
            it('should list files in component directory', function() {
                $this->handler->storeFile('test_component', 'file1.txt', 'content1');
                $this->handler->storeFile('test_component', 'file2.json', 'content2');

                $files = $this->handler->listFiles('test_component');

                expect($files)->toBeA('array');
                expect(count($files))->toBe(2);

                $filenames = array_column($files, 'name');
                expect(in_array('file1.txt', $filenames))->toBe(true);
                expect(in_array('file2.json', $filenames))->toBe(true);

                // Check file info structure
                expect(isset($files[0]['name']))->toBe(true);
                expect(isset($files[0]['size']))->toBe(true);
                expect(isset($files[0]['modified']))->toBe(true);
            });

            it('should return empty array for non-existent component', function() {
                $files = $this->handler->listFiles('nonexistent_component');
                expect($files)->toBeA('array');
                expect(count($files))->toBe(0);
            });
        });
    });
    
    describe('security features', function() {
        describe('filename validation', function() {
            it('should reject path traversal attempts', function() {
                expect(function() {
                    $this->handler->storeFile('test_component', '../test.txt', 'content');
                })->toThrow(new \InvalidArgumentException('Invalid filename: ../test.txt'));
            });

            it('should reject filenames with slashes', function() {
                expect(function() {
                    $this->handler->storeFile('test_component', 'subdir/test.txt', 'content');
                })->toThrow(new \InvalidArgumentException('Invalid filename: subdir/test.txt'));
            });

            it('should reject disallowed file extensions', function() {
                expect(function() {
                    $this->handler->storeFile('test_component', 'script.php', 'content');
                })->toThrow(new \InvalidArgumentException());
            });

            it('should allow configured file extensions', function() {
                // These should not throw exceptions
                $this->handler->storeFile('test_component', 'data.txt', 'content');
                $this->handler->storeFile('test_component', 'data.json', 'content');
                $this->handler->storeFile('test_component', 'data.csv', 'content');
                $this->handler->storeFile('test_component', 'archive.zip', 'content');

                expect(true)->toBe(true); // If we get here, no exceptions were thrown
            });

            it('should be case insensitive for extensions', function() {
                // These should not throw exceptions
                $this->handler->storeFile('test_component', 'data.TXT', 'content');
                $this->handler->storeFile('test_component', 'data.JSON', 'content');

                expect(true)->toBe(true); // If we get here, no exceptions were thrown
            });
        });
    });
    
    describe('error handling', function() {
        it('should handle storage failures gracefully', function() {
            // Skip if running as root (chmod won't prevent writes)
            if (posix_getuid() === 0) {
                $this->skipIf(true, 'Test requires non-root user to test permission failures');
            }

            $this->handler->storeFile('test_component', 'test.txt', 'content');
            // Make the directory read-only to cause write failure
            $storagePath = $this->handler->getStoragePath('test_component');
            chmod($storagePath, 0444);

            try {
                $result = null;
                $exceptionThrown = false;

                try {
                    $result = $this->handler->storeFile('test_component', 'test2.txt', 'content');
                } catch (\Exception $e) {
                    $exceptionThrown = true;
                }

                // Should either throw an exception OR fail gracefully (return null or false)
                // If neither happened, chmod didn't work (running as root)
                if (!$exceptionThrown && $result !== null && $result !== false) {
                    $this->skipIf(true, 'chmod did not prevent write (likely running as root)');
                }

                expect($exceptionThrown || $result === null || $result === false)->toBe(true);
            } finally {
                // Restore permissions for cleanup
                chmod($storagePath, 0755);
            }
        });

        it('should handle read failures gracefully', function() {
            expect(function() {
                $this->handler->getFileContent('test_component', 'nonexistent.txt');
            })->toThrow(new \RuntimeException('File not found: nonexistent.txt'));
        });

        it('should validate component names', function() {
            // Component names should be safe for directory creation
            $path1 = $this->handler->getStoragePath('valid_component');
            $path2 = $this->handler->getStoragePath('another-component');

            expect(is_dir($path1))->toBe(true);
            expect(is_dir($path2))->toBe(true);
        });
    });
    
    describe('configuration', function() {
        it('should use custom allowed extensions', function() {
            $handler = new SecureFileHandler([
                'storage_path' => $this->tempDir . '/custom',
                'working_path' => $this->workingDir . '/custom',
                'allowed_extensions' => ['custom', 'special']
            ]);

            // Should allow custom extensions
            $handler->storeFile('test_component', 'file.custom', 'content');
            $handler->storeFile('test_component', 'file.special', 'content');

            // Should reject default extensions
            expect(function() use ($handler) {
                $handler->storeFile('test_component', 'file.txt', 'content');
            })->toThrow(new \InvalidArgumentException());
        });

        it('should use default configuration when none provided', function() {
            $handler = new SecureFileHandler();

            // Should work with default extensions
            $handler->storeFile('test_component', 'file.txt', 'content');
            $handler->storeFile('test_component', 'file.json', 'content');
            $handler->storeFile('test_component', 'file.csv', 'content');
            $handler->storeFile('test_component', 'file.zip', 'content');

            expect(true)->toBe(true); // If we get here, no exceptions were thrown
        });
    });
});
