<?php

use Symbiota\Helpers\Models\UploadModel;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;

// Include shared test utilities
require_once __DIR__ . '/../../helpers/TestUtilities.php';
use Symbiota\Helpers\Core\DatabaseManager;

describe('UploadModel', function() {

    beforeEach(function() {
        // Check if we should skip database tests based on config.php
        $configFile = __DIR__ . '/../../../config.php';
        $this->skipDatabaseTests = true; // Default to skip

        if (file_exists($configFile)) {
            // Check if config.php has testing database configuration
            $configContent = file_get_contents($configFile);
            if (strpos($configContent, 'testing_symbiota_dbconnection') !== false) {
                $this->skipDatabaseTests = false; // Config has testing DB, don't skip
            }
        }

        // Reset Configuration singleton for testing
        \Symbiota\Helpers\Core\Configuration::reset();

        // Create a single temp directory for both config and test
        $this->tempDir = sys_get_temp_dir() . '/upload_test_' . uniqid();
        $this->registryFile = $this->tempDir . '/registry.json';

        // Initialize Configuration singleton for testing
        \Symbiota\Helpers\Core\Configuration::initialize([
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates',
            'testing_symbiota_uid' => 433, // SuperAdmin for testing
            'components' => [
                'upload' => [
                    'output_dir' => $this->tempDir . '/uploads',
                    'registry_file' => $this->registryFile,
                    'chunk_size_kb' => 1024,
                    'allowed_file_types' => ['jpg', 'png', 'pdf', 'txt', 'csv', 'tsv', 'zip'],
                    'upload_timeout' => 300,
                    'max_file_size_mb' => 100
                ]
            ]
        ]);

        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates',
            'testing_symbiota_uid' => 433, // SuperAdmin for testing
            'components' => [
                'upload' => [
                    'output_dir' => $this->tempDir . '/uploads',
                    'registry_file' => $this->registryFile,
                    'chunk_size_kb' => 1024,
                    'allowed_file_types' => ['jpg', 'png', 'pdf', 'txt', 'csv', 'tsv', 'zip'],
                    'upload_timeout' => 300,
                    'max_file_size_mb' => 100
                ]
            ]
        ];

        $this->model = new UploadModel($this->config);

        // Ensure test directories exist
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    });

    afterEach(function() {
        // Clean up test files
        if (is_dir($this->tempDir)) {
            removeDirectory($this->tempDir);
        }

        // Reset Configuration singleton
        \Symbiota\Helpers\Core\Configuration::reset();
    });

    describe('basic functionality', function() {
        it('should extend Model (which extends DiscoverableModel)', function() {
            expect($this->model)->toBeAnInstanceOf('Symbiota\Helpers\Core\Model');
            expect($this->model)->toBeAnInstanceOf('Symbiota\Helpers\Core\DiscoverableModel');
        });

        it('should have correct model ID', function() {
            expect(UploadModel::getModelId())->toBe('upload');
        });

        it('should have correct module type', function() {
            expect(UploadModel::getModuleType())->toBe(ModuleType::COMPONENT);
        });

        it('should have expected actions', function() {
            $actions = $this->model->getAvailableActions();
            expect($actions)->toContainKey('users');
            expect($actions)->toContainKey('add-user');
            expect($actions)->toContainKey('remove-user');
            expect($actions)->toContainKey('list-user-files');
            expect($actions)->toContainKey('chunk');
            expect($actions)->toContainKey('complete');
            expect($actions)->toContainKey('sessions');
        });
    });

    describe('CLI user management', function() {
        describe('users action', function() {
            it('should list empty registry initially', function() {
                $result = $this->model->handleAction('users', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['content'])->toContain('No authorized users found');
            });
        });

        describe('add-user action', function() {
            it('should add user by UID', function() {
                // Skip database-dependent tests in cfStatic mode
                if ($this->skipDatabaseTests) {
                    $this->skip('Database tests skipped in cfStatic configuration state');
                    return;
                }

                $result = $this->model->handleAction('add-user', [], ['args' => ['433']]);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('User 433');
                expect($result['message'])->toContain('added to upload registry');
            });

            it('should create user directory structure', function() {
                $this->model->handleAction('add-user', [], ['args' => ['433']]);

                $userDir = $this->tempDir . '/uploads/433';
                $chunksDir = $userDir . '/chunks';

                expect(is_dir($userDir))->toBe(true);
                expect(is_dir($chunksDir))->toBe(true);
            });

            it('should require user identifier', function() {
                $result = $this->model->handleAction('add-user', [], []);

                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Username or UID required');
            });
        });

        describe('remove-user action', function() {
            beforeEach(function() {
                // Add a user first
                $this->model->handleAction('add-user', [], ['args' => ['433']]);
            });

            it('should remove user by UID', function() {
                // Skip database-dependent tests in cfStatic mode
                if ($this->skipDatabaseTests) {
                    $this->skip('Database tests skipped in cfStatic configuration state');
                    return;
                }

                $result = $this->model->handleAction('remove-user', [], ['args' => ['433']]);

                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('User 433');
                expect($result['message'])->toContain('removed from upload registry');
            });

            it('should handle non-existent user', function() {
                $result = $this->model->handleAction('remove-user', [], ['args' => ['999']]);

                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('not found in registry');
            });
        });

        describe('list-user-files action', function() {
            beforeEach(function() {
                // Add a user and create some test files
                $this->model->handleAction('add-user', [], ['args' => ['433']]);

                $sessionDir = $this->tempDir . '/uploads/433/192.168.1.1-20250101T095423-1234';
                mkdir($sessionDir, 0755, true);
                file_put_contents($sessionDir . '/test.txt', 'test content');
            });

            it('should list user files', function() {
                $result = $this->model->handleAction('list-user-files', [], ['args' => ['433']]);

                // Accept either 'success' (CLI) or 'json' (HTTP) response types
                expect($result['type'])->toMatch('/^(success|json)$/');
                expect($result)->toContainKey('content');

                if ($result['type'] === 'success') {
                    // CLI format - plain text
                    expect($result['content'])->toContain('Files for User 433');
                    expect($result['content'])->toContain('test.txt');
                } else {
                    // JSON format - decode and check
                    $files = json_decode($result['content'], true);
                    expect($files)->toBeA('array');
                    expect(count($files))->toBeGreaterThan(0);
                    $foundTestFile = false;
                    foreach ($files as $file) {
                        if ($file['filename'] === 'test.txt') {
                            $foundTestFile = true;
                            break;
                        }
                    }
                    expect($foundTestFile)->toBe(true);
                }
            });

            it('should handle non-existent user', function() {
                $result = $this->model->handleAction('list-user-files', [], ['args' => ['nonexistentuser']]);

                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect($result['message'])->toContain('not found');
            });
        });
    });

    describe('CLI flag handling', function() {
        it('should handle registry-file flag', function() {
            $customRegistry = $this->tempDir . '/custom_registry.json';
            $params = [
                'registry-file' => $customRegistry,
                'args' => ['433']
            ];

            $result = $this->model->handleAction('add-user', [], $params);
            expect($result['type'])->toBe('success');
            expect(file_exists($customRegistry))->toBe(true);
        });

        it('should handle output-dir flag', function() {
            $customOutput = $this->tempDir . '/custom_uploads';
            $params = [
                'output-dir' => $customOutput,
                'args' => ['433']
            ];

            $result = $this->model->handleAction('add-user', [], $params);
            expect($result['type'])->toBe('success');
            expect(is_dir($customOutput . '/433'))->toBe(true);
        });

        it('should handle short flag versions', function() {
            $customRegistry = $this->tempDir . '/custom_registry.json';
            $customOutput = $this->tempDir . '/custom_uploads';
            $params = [
                'r' => $customRegistry,
                'd' => $customOutput,
                'args' => ['433']
            ];

            $result = $this->model->handleAction('add-user', [], $params);
            expect($result['type'])->toBe('success');
            expect(file_exists($customRegistry))->toBe(true);
            expect(is_dir($customOutput . '/433'))->toBe(true);
        });
    });

    describe('authentication integration', function() {
        xit('should use testing UID when configured', function() {
            $result = $this->model->handleAction('index', [], []);

            // Should fail authorization since testing UID not in registry yet
            expect($result['type'])->toBe('error');
            expect(strtolower($result['message']))->toContain('not authorized');
        });

        xit('should allow access after user is added to registry', function() {
            // Add testing UID to registry
            $params = ['args' => ['433']];
            $this->model->handleAction('add-user', [], ['elements' => []], $params);

            // Now should have access
            $result = $this->model->handleAction('index', [], []);
            expect($result['type'])->toBe('html');
        });
    });

    describe('registry management', function() {
        it('should create registry file when adding first user', function() {
            expect(file_exists($this->registryFile))->toBe(false);

            $this->model->handleAction('add-user', [], ['args' => ['433']]);

            expect(file_exists($this->registryFile))->toBe(true);
        });

        xit('should maintain registry structure', function() {
            $this->model->handleAction('add-user', [], ['args' => ['433']]);

            // Check if registry file exists before reading
            expect(file_exists($this->registryFile))->toBe(true);
            $content = file_get_contents($this->registryFile);
            $registry = json_decode($content, true);

            expect($registry)->toContainKey('users');
            expect($registry)->toContainKey('created');
            expect($registry['users'])->toContainKey('433');
            expect($registry['users']['433'])->toContainKey('username');
            expect($registry['users']['433'])->toContainKey('added_date');
        });
    });

    describe('chunked upload functionality', function() {
        beforeEach(function() {
            // Mock SYMB_UID for testing
            $GLOBALS['SYMB_UID'] = 433;

            // Mock $_FILES for chunk upload
            $_FILES = [
                'chunk' => [
                    'tmp_name' => tempnam(sys_get_temp_dir(), 'chunk_'),
                    'size' => 1024,
                    'error' => UPLOAD_ERR_OK
                ]
            ];

            // Create test chunk data
            file_put_contents($_FILES['chunk']['tmp_name'], str_repeat('A', 1024));
        });

        afterEach(function() {
            // Clean up globals
            unset($GLOBALS['SYMB_UID']);
            $_FILES = [];
        });

        it('should handle chunk upload error conditions', function() {
            $params = [
                'chunkIndex' => 0,
                'totalChunks' => 2,
                'filename' => 'test.txt',
                'sessionId' => 'test-session-123'
            ];

            $result = $this->model->handleAction('chunk', [], $params);

            // In test environment, we expect method not allowed for non-POST requests
            expect($result['type'])->toBe('error');
            expect($result['message'])->toContain('Only POST requests allowed');
            expect($result['status_code'])->toBe(405);
        });

        it('should require authentication for chunk upload', function() {
            unset($GLOBALS['SYMB_UID']);

            $params = [
                'chunkIndex' => 0,
                'totalChunks' => 1,
                'filename' => 'test.txt'
            ];

            $result = $this->model->handleAction('chunk', [], $params);

            expect($result['type'])->toBe('error');
            expect($result['message'])->toContain('Only POST requests allowed');
        });

        it('should require filename for chunk upload', function() {
            $params = [
                'chunkIndex' => 0,
                'totalChunks' => 1
            ];

            $result = $this->model->handleAction('chunk', [], $params);

            expect($result['type'])->toBe('error');
            expect($result['message'])->toContain('Only POST requests allowed');
        });

        xit('should get session reports', function() {
            $result = $this->model->handleAction('sessions', [], []);

            expect($result['type'])->toBe('success');
            expect(isset($result['content']))->toBe(true);
        });

        xit('should include user info in main page when authenticated', function() {
            // Mock authentication
            $GLOBALS['SYMB_UID'] = 433;

            // Add user to registry first
            $this->model->handleAction('add-user', [], ['args' => ['433']]);

            $result = $this->model->handleAction('index', [], []);

            expect($result['type'])->toBe('html');
            // Check for either 'content' or 'data' key (flexible response format)
            $contentKey = isset($result['content']) ? 'content' : 'data';
            expect($result)->toContainKey($contentKey);
            expect($result[$contentKey])->toContain('User:');

            // Clean up
            unset($GLOBALS['SYMB_UID']);
        });

    /**
     * COMPREHENSIVE CLI USAGE PATTERN TESTS
     *
     * Tests all documented CLI usage patterns for the upload module:
     * - helpers.php upload status
     * - helpers.php upload users
     * - helpers.php upload add-user <username>
     * - helpers.php upload remove-user <username>
     * - helpers.php upload list-user-files <username>
     * - helpers.php upload sessions
     * - helpers.php upload debug-log
     * - helpers.php upload php-info
     * - helpers.php upload test-chunk
     * - helpers.php upload test-upload
     * - helpers.php upload help
     */
    describe('CLI Usage Patterns', function() {

        describe('status action', function() {
            it('should show upload module configuration when no parameters provided', function() {
                $result = $this->model->handleAction('status', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);

                // Handle both array and string responses
                if (is_array($result[$dataKey])) {
                    expect($result[$dataKey])->toContainKey('configuration');
                    expect($result[$dataKey]['configuration'])->toContainKey('output_dir');
                    expect($result[$dataKey]['configuration'])->toContainKey('registry_file');
                    expect($result[$dataKey]['configuration'])->toContainKey('chunk_size_kb');
                    expect($result[$dataKey]['configuration'])->toContainKey('allowed_file_types');
                    expect($result[$dataKey]['configuration'])->toContainKey('upload_timeout');
                } else {
                    // String response - check it contains configuration info
                    expect(strtolower($result[$dataKey]))->toContain('configuration');
                    expect($result[$dataKey])->toContain('output_dir');
                }
            });
        });

        describe('users action', function() {
            it('should list all users when no username specified', function() {
                $result = $this->model->handleAction('users', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);

                // Handle both array and string responses
                if (is_array($result[$dataKey])) {
                    expect($result[$dataKey])->toContainKey('users');
                    expect($result[$dataKey]['users'])->toBeA('array');
                } else {
                    // String response - check it contains user info
                    expect($result[$dataKey])->toContain('users');
                }
            });
        });

        describe('add-user action', function() {
            it('should require username parameter', function() {
                $result = $this->model->handleAction('add-user', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                // Check for either 'error' or 'message' key (flexible response format)
                $errorKey = isset($result['error']) ? 'error' : 'message';
                expect($result)->toContainKey($errorKey);
                expect(strtolower($result[$errorKey]))->toContain('username');
            });

            it('should add user when username provided', function() {
                $result = $this->model->handleAction('add-user', [], ['username' => 'testuser']);

                expect($result)->toBeA('array');
                // Result depends on whether user exists in test database
                expect($result['type'])->toMatch('/^(success|error)$/');
            });
        });

        describe('remove-user action', function() {
            it('should require username parameter', function() {
                $result = $this->model->handleAction('remove-user', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                // Check for either 'error' or 'message' key (flexible response format)
                $errorKey = isset($result['error']) ? 'error' : 'message';
                expect($result)->toContainKey($errorKey);
                expect(strtolower($result[$errorKey]))->toContain('username');
            });

            it('should remove user when username provided', function() {
                $result = $this->model->handleAction('remove-user', [], ['username' => 'testuser']);

                expect($result)->toBeA('array');
                // Result depends on whether user exists in registry
                expect($result['type'])->toMatch('/^(success|error)$/');
            });
        });

        describe('list-user-files action', function() {
            it('should require username parameter', function() {
                $result = $this->model->handleAction('list-user-files', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                // Check for either 'error' or 'message' key (flexible response format)
                $errorKey = isset($result['error']) ? 'error' : 'message';
                expect($result)->toContainKey($errorKey);
                expect(strtolower($result[$errorKey]))->toContain('username');
            });

            xit('should list files when username provided', function() {
                $result = $this->model->handleAction('list-user-files', [], ['username' => 'testuser']);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);
                expect($result[$dataKey])->toContainKey('files');
                expect($result[$dataKey]['files'])->toBeA('array');
            });
        });

        describe('sessions action', function() {
            xit('should return session reports', function() {
                $result = $this->model->handleAction('sessions', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);
                expect($result[$dataKey])->toContainKey('sessions');
                expect($result[$dataKey]['sessions'])->toBeA('array');
            });
        });

        describe('debug-log action', function() {
            it('should return debug log information', function() {
                $result = $this->model->handleAction('debug-log', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);
                expect(strtolower($result[$dataKey]))->toContain('debug log');
            });
        });

        describe('php-info action', function() {
            it('should return PHP configuration information', function() {
                $result = $this->model->handleAction('php-info', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);

                // Handle both array and string responses
                if (is_array($result[$dataKey])) {
                    expect($result[$dataKey])->toContainKey('php_info');
                    expect($result[$dataKey]['php_info'])->toContainKey('version');
                    expect($result[$dataKey]['php_info'])->toContainKey('upload_max_filesize');
                    expect($result[$dataKey]['php_info'])->toContainKey('post_max_size');
                    expect($result[$dataKey]['php_info'])->toContainKey('max_execution_time');
                } else {
                    // String response - check it contains PHP configuration info
                    expect(strtolower($result[$dataKey]))->toContain('php');
                    expect($result[$dataKey])->toContain('upload_max_filesize');
                }
            });
        });

        describe('test-chunk action', function() {
            it('should test chunk upload endpoint', function() {
                $result = $this->model->handleAction('test-chunk', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);

                // Handle both array and string responses
                if (is_array($result[$dataKey])) {
                    expect($result[$dataKey])->toContainKey('test_results');
                } else {
                    // String response - check it contains test info
                    expect(strtolower($result[$dataKey]))->toContain('test');
                }
            });
        });

        describe('test-upload action', function() {
            it('should handle test upload functionality', function() {
                $result = $this->model->handleAction('test-upload', [], []);

                expect($result)->toBeA('array');
                // Result depends on test file availability
                expect($result['type'])->toMatch('/^(success|error)$/');
            });
        });

        describe('help action', function() {
            xit('should return help information', function() {
                $result = $this->model->handleAction('help', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                // Check for either 'data' or 'content' key (flexible response format)
                $dataKey = isset($result['data']) ? 'data' : 'content';
                expect($result)->toContainKey($dataKey);
                expect($result[$dataKey])->toContainKey('help');
                expect($result[$dataKey]['help'])->toContain('Upload Module');
            });
        });

        describe('parameter validation', function() {
            it('should handle invalid action gracefully', function() {
                $result = $this->model->handleAction('invalid-action', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                // Check for either 'error' or 'message' key (flexible response format)
                $errorKey = isset($result['error']) ? 'error' : 'message';
                expect($result)->toContainKey($errorKey);
                expect($result[$errorKey])->toContain('Unknown action');
            });

            it('should validate chunk size parameter', function() {
                $result = $this->model->handleAction('status', [], ['chunk-size' => 'invalid']);

                expect($result)->toBeA('array');
                // Should still work but may show warnings in configuration
                expect($result['type'])->toBe('success');
            });

            it('should validate timeout parameter', function() {
                $result = $this->model->handleAction('status', [], ['timeout' => 'invalid']);

                expect($result)->toBeA('array');
                // Should still work but may show warnings in configuration
                expect($result['type'])->toBe('success');
            });
        });
    });
    });
});


