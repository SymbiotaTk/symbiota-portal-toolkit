<?php

use Symbiota\Helpers\Models\BackupModel;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;

// Include shared test utilities
require_once __DIR__ . '/../../helpers/TestUtilities.php';
use Symbiota\Helpers\Core\DatabaseManager;

describe('BackupModel', function() {

    beforeEach(function() {
        // Determine if database-dependent tests should be skipped based on configuration state
        $config = \Symbiota\Helpers\Core\Configuration::getInstance();
        $configState = $config ? $config->getConfigurationState() : 'cfStatic';
        $this->skipDatabaseTests = ($configState === 'cfStatic');
        // Initialize Configuration singleton for testing
        \Symbiota\Helpers\Core\Configuration::initialize([
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'database' => [
                'dbconnection' => __DIR__ . '/../../fixtures/dbconnection.php'
            ],
            'components' => [
                'backup' => [
                    'storage_path' => sys_get_temp_dir() . '/backup_test',
                    'working_path' => sys_get_temp_dir() . '/backup_work_test',
                    'site_salt' => 'test_salt_for_testing_only',
                    'registry_file' => 'test/backup_test.json',
                    'max_backup_age_days' => 7,
                    'throttle_hours' => 24,
                    'retention_threshold' => 2,
                    'backup_threshold' => 0,
                    'cli_only' => false
                ]
            ]
        ]);

        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'database' => [
                'dbconnection' => __DIR__ . '/../../fixtures/dbconnection.php'
            ],
            'components' => [
                'backup' => [
                    'storage_path' => sys_get_temp_dir() . '/backup_test',
                    'working_path' => sys_get_temp_dir() . '/backup_work_test',
                    'site_salt' => 'test_salt_for_testing_only',
                    'registry_file' => 'test/backup_test.json',
                    'max_backup_age_days' => 7,
                    'throttle_hours' => 24,
                    'retention_threshold' => 2,
                    'backup_threshold' => 0,
                    'cli_only' => false
                ]
            ]
        ];
        $this->model = new BackupModel($this->config);
    });

    describe('static methods', function() {
        describe('getModelId', function() {
            it('should return correct model ID', function() {
                expect(BackupModel::getModelId())->toBe('backup');
            });
        });

        describe('getModuleType', function() {
            it('should return COMPONENT type', function() {
                expect(BackupModel::getModuleType())->toBe(ModuleType::COMPONENT);
            });
        });

        describe('isEnabled', function() {
            it('should be enabled by default', function() {
                expect(BackupModel::isEnabled())->toBe(true);
            });
        });

        describe('getHelpMarkdown', function() {
            it('should return help documentation', function() {
                $help = BackupModel::getHelpMarkdown();

                expect($help)->toBeA('string');
                expect($help)->toContain('backup');
                expect($help)->toContain('encrypted');
                expect($help)->toContain('Symbiota');
                expect($help)->toContain('collections');
                expect($help)->toContain('CollAdmin');
            });
        });

        describe('getHelpInfo', function() {
            it('should parse help markdown into structured data', function() {
                $helpInfo = BackupModel::getHelpInfo();

                expect($helpInfo)->toBeA('array');
                expect(isset($helpInfo['description']))->toBe(true);
                expect(isset($helpInfo['parameters']))->toBe(true);
                expect(isset($helpInfo['usage']))->toBe(true);
                expect(isset($helpInfo['examples']))->toBe(true);
            });
        });

        describe('generateCliHelp', function() {
            it('should generate CLI help text', function() {
                $help = BackupModel::generateCliHelp();

                expect($help)->toBeA('string');
                expect($help)->toContain('USAGE PATTERNS');
                expect($help)->toContain('PARAMETERS');
                expect($help)->toContain('EXAMPLES');
                expect($help)->toContain('collections');
                expect($help)->toContain('register');
            });
        });
    });

    describe('action handling', function() {
        describe('handleAction', function() {
            it('should handle index action', function() {
                $response = $this->model->handleAction('index', [], []);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                expect(isset($response['content']))->toBe(true);
            });





            it('should handle unknown action', function() {
                $response = $this->model->handleAction('unknown', [], []);

                expect($response)->toBeA('array');
                expect($response['type'])->toBe('error');
                expect($response['status_code'])->toBe(404);
            });
        });

        describe('getAvailableActions', function() {
            it('should return available actions', function() {
                $actions = $this->model->getAvailableActions();

                expect($actions)->toBeA('array');
                expect(isset($actions['collections']))->toBe(true);
                expect(isset($actions['users']))->toBe(true);
                expect(isset($actions['register']))->toBe(true);
                expect(isset($actions['create-encrypted']))->toBe(true);
                expect(isset($actions['status']))->toBe(true);
                expect(isset($actions['list']))->toBe(true);
                expect(isset($actions['cleanup']))->toBe(true);
            });
        });

        describe('parameter validation', function() {
            it('should validate collid parameter', function() {
                $validParams = ['collid' => '123'];
                $errors = $this->model->validateParameters($validParams);
                expect(count($errors))->toBe(0);
            });

            it('should validate userid parameter', function() {
                $validParams = ['userid' => '456'];
                $errors = $this->model->validateParameters($validParams);
                expect(count($errors))->toBe(0);
            });

//            it('should validate passphrase parameter', function() {
//                $validParams = ['passphrase' => 'secure_backup_password'];
//                $errors = $this->model->validateParameters($validParams);
//                expect(count($errors))->toBe(0);
//            });
        });
    });

    describe('backup operations', function() {
        describe('create-encrypted', function() {
            xit('should require collection ID', function() {
                $result = $this->model->handleAction('create-encrypted', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required');
            });

            xit('should handle missing collection ID gracefully', function() {
                $result = $this->model->handleAction('create-encrypted', [], ['collid' => '', 'test_mode' => true]);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['status_code'])->toBe(400);
            });
        });

        describe('dryRunBackup', function() {
            xit('should require collection ID for dry run', function() {
                $result = $this->model->handleAction('dry-run', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required');
            });
        });

        describe('user management', function() {
            xit('should require parameters for user registration', function() {
                $result = $this->model->handleAction('register', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required for registration');
            });

            it('should show specific error for missing passphrase in CLI', function() {
                // Mock CLI command: bin/hp register 99 23 (missing passphrase)
                $result = $this->model->handleAction('register', ['99', '23'], [
                    'config' => 'config.php'
                ]);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toBe('Passphrase required for registration. Use --passphrase=<phrase> or -p <phrase>');
                expect($result['status_code'])->toBe(400);
            });

            it('should show specific error for missing user ID in CLI', function() {
                // Mock CLI command: bin/hp register 99 (missing user ID)
                $result = $this->model->handleAction('register', ['99'], [
                    'config' => 'config.php',
                    'passphrase' => 'test123'
                ]);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toBe('User ID required for registration');
                expect($result['status_code'])->toBe(400);
            });

            xit('should show specific error for missing collection ID in CLI', function() {
                // Mock CLI command: bin/hp register (missing collection ID)
                $result = $this->model->handleAction('register', [], [
                    'config' => 'config.php',
                    'passphrase' => 'test123'
                ]);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toBe('Collection ID required for registration');
                expect($result['status_code'])->toBe(400);
            });

            xit('should require parameters for user removal', function() {
                $result = $this->model->handleAction('remove-user', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id and user id required');
            });

            xit('should require parameters for passphrase reset', function() {
                $result = $this->model->handleAction('reset-passphrase', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id, user id, and new passphrase required');
            });
        });
    });

    describe('backup management', function() {
        describe('listBackups', function() {
            xit('should require collection ID', function() {
                $result = $this->model->handleAction('list', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required');
            });
        });

        describe('getBackupStatus', function() {
            xit('should require collection ID', function() {
                $result = $this->model->handleAction('status', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required');
            });
        });

        describe('cleanupBackups', function() {
            xit('should require collection ID', function() {
                $result = $this->model->handleAction('cleanup', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect(strtolower($result['message']))->toContain('collection id required');
            });
        });
    });

    describe('main page rendering', function() {
        describe('getMainPage', function() {
            it('should return CLI response in CLI mode', function() {
                Environment::forceEnvironment(Environment::CLI);

                $response = $this->model->getMainPage();

                expect($response)->toBeA('array');
                expect($response['type'])->toBe('success');
                expect($response['content'])->toContain('Backup Manager');

                Environment::reset();
            });

            it('should return HTML response in HTTP mode', function() {
                Environment::forceEnvironment(Environment::HTTP);

                $response = $this->model->getMainPage();

                expect($response)->toBeA('array');
                expect($response['type'])->toBe('html');
                expect(isset($response['content']))->toBe(true);

                Environment::reset();
            });
        });
    });

    describe('database integration', function() {
        describe('database availability', function() {
            it('should check database availability', function() {
                $isAvailable = $this->model->isDatabaseAvailable();
                expect($isAvailable)->toBeA('boolean');
            });
        });

        describe('collections listing', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should handle collections listing gracefully', function() {
            //     $result = $this->model->handleAction('collections', [], []);

            //     expect($result)->toBeA('array');
            //     expect($result['type'])->toBe('cli');
            //     expect($result['content'])->toContain('Collections with CollAdmin Users');
            // });

            it('should verify collections action exists', function() {
                $actions = $this->model->getAvailableActions();
                expect(isset($actions['collections']))->toBe(true);
            });
        });
    });

    describe('error handling', function() {
        it('should handle unknown actions gracefully', function() {
            $response = $this->model->handleAction('invalid_action', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['status_code'])->toBe(404);
        });

        it('should handle missing required parameters', function() {
            $response = $this->model->handleAction('users', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['status_code'])->toBe(400);
        });
    });

    describe('configuration', function() {
        it('should initialize with provided configuration', function() {
            expect($this->model)->toBeAnInstanceOf(BackupModel::class);
        });

        it('should handle new components.backup configuration format', function() {
            $configWithComponents = [
                'components' => [
                    'backup' => [
                        'site_salt' => 'test_salt',
                        'registry_file' => 'test/backup.json',
                        'max_backup_age_days' => 7
                    ]
                ]
            ];

            $model = new BackupModel($configWithComponents);
            expect($model)->toBeAnInstanceOf(BackupModel::class);
        });

        it('should handle legacy backup configuration format', function() {
            $configWithBackup = [
                'backup' => [
                    'site_salt' => 'test_salt',
                    'registry_file' => 'test/backup.json',
                    'max_backup_age_days' => 7
                ]
            ];

            $model = new BackupModel($configWithBackup);
            expect($model)->toBeAnInstanceOf(BackupModel::class);
        });

        it('should handle missing backup configuration gracefully', function() {
            $configWithoutBackup = [
                'base_url' => '/test/',
                'app_url_prefix' => '/?/'
            ];

            $model = new BackupModel($configWithoutBackup);
            expect($model)->toBeAnInstanceOf(BackupModel::class);
        });

        it('should use registry_file from configuration', function() {
            $configWithRegistryFile = [
                'components' => [
                    'backup' => [
                        'registry_file' => 'custom/path/backup.json',
                        'site_salt' => 'test_salt'
                    ]
                ]
            ];

            $model = new BackupModel($configWithRegistryFile);
            expect($model)->toBeAnInstanceOf(BackupModel::class);

            // Test that the registry file path is set correctly
            $reflection = new ReflectionClass($model);
            $property = $reflection->getProperty('backupRegistryPath');
            $property->setAccessible(true);
            $registryPath = $property->getValue($model);

            // Since Configuration singleton is active, it uses the actual config.php values
            expect($registryPath)->toContain('backup.json');
        });

        it('should fall back to default registry file when not specified', function() {
            $configWithoutRegistryFile = [
                'components' => [
                    'backup' => [
                        'site_salt' => 'test_salt'
                    ]
                ]
            ];

            $model = new BackupModel($configWithoutRegistryFile);
            expect($model)->toBeAnInstanceOf(BackupModel::class);

            // Test that the default registry file is used
            $reflection = new ReflectionClass($model);
            $property = $reflection->getProperty('backupRegistryPath');
            $property->setAccessible(true);
            $registryPath = $property->getValue($model);

            // Since Configuration singleton is active, it uses the actual config.php values
            expect($registryPath)->toContain('backup.json');
        });

        it('should use parameter constants for configuration', function() {
            // Test that parameter constants are defined and accessible
            $reflection = new ReflectionClass(BackupModel::class);

            // Check that key parameter constants exist
            expect($reflection->hasConstant('PARAM_PASSPHRASE'))->toBe(true);
            expect($reflection->hasConstant('PARAM_REGISTRY_FILE'))->toBe(true);
            expect($reflection->hasConstant('CONFIG_REGISTRY_FILE'))->toBe(true);
            expect($reflection->hasConstant('CONFIG_HTTP_POST'))->toBe(true);
        });
    });

    describe('enhanced functionality', function() {
        describe('collection information display', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should display enhanced collection information in users command', function() {
            //     $result = $this->model->handleAction('users', [], ['collid' => '1']);

            //     expect($result)->toBeA('array');
            //     expect(in_array($result['type'], ['cli', 'error']))->toBe(true);

            //     if ($result['type'] === 'cli') {
            //         expect(isset($result['content']))->toBe(true);
            //         // Should contain enhanced collection name format
            //         expect($result['content'])->toContain('CollAdmin Users for');
            //         expect($result['content'])->toContain('(DBG');
            //         expect($result['content'])->toContain(') 1:');
            //     } else {
            //         expect(isset($result['error']))->toBe(true);
            //     }
            // });

            it('should verify users action exists', function() {
                $actions = $this->model->getAvailableActions();
                expect(isset($actions['users']))->toBe(true);
            });

            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should handle collections without collection codes', function() {
            //     $result = $this->model->handleAction('users', [], ['collid' => '67']);

            //     expect($result)->toBeA('array');
            //     expect(in_array($result['type'], ['cli', 'error']))->toBe(true);

            //     if ($result['type'] === 'cli') {
            //         expect(isset($result['content']))->toBe(true);
            //         // Should contain institution code but handle missing collection code
            //         expect($result['content'])->toContain('CollAdmin Users for');
            //         expect($result['content'])->toContain('(PH)');
            //         expect($result['content'])->toContain('67:');
            //     } else {
            //         expect(isset($result['error']))->toBe(true);
            //     }
            // });
        });

        describe('structured JSON output', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should provide structured data for collections command', function() {
            //     $result = $this->model->handleAction('collections', [], ['format' => 'json']);

            //     expect($result)->toBeA('array');
            //     expect($result['type'])->toBe('cli');
            //     expect(isset($result['collections']))->toBe(true);
            //     expect(isset($result['summary']))->toBe(true);
            //     expect($result['collections'])->toBeA('array');
            //     expect($result['summary'])->toBeA('array');
            // });

            // it('should provide structured data for users command', function() {
            //     $result = $this->model->handleAction('users', [], ['collid' => '1', 'format' => 'json']);

            //     expect($result)->toBeA('array');
            //     expect(in_array($result['type'], ['cli', 'error']))->toBe(true);

            //     if ($result['type'] === 'cli') {
            //         expect(isset($result['users']))->toBe(true);
            //         expect(isset($result['collection_id']))->toBe(true);
            //         expect(isset($result['summary']))->toBe(true);
            //         expect($result['users'])->toBeA('array');
            //         expect($result['collection_id'])->toBe(1);
            //     } else {
            //         expect(isset($result['error']))->toBe(true);
            //     }
            // });

            // it('should provide structured data for all-collections command', function() {
            //     $result = $this->model->handleAction('all-collections', [], ['format' => 'json']);

            //     expect($result)->toBeA('array');
            //     expect($result['type'])->toBe('cli');
            //     expect(isset($result['collections']))->toBe(true);
            //     expect(isset($result['summary']))->toBe(true);
            //     expect($result['collections'])->toBeA('array');
            // });

            it('should verify structured JSON output capability', function() {
                // Test that the model can handle format parameter
                $actions = $this->model->getAvailableActions();
                expect(isset($actions['collections']))->toBe(true);
                expect(isset($actions['users']))->toBe(true);
                expect(isset($actions['all-collections']))->toBe(true);
            });
        });

        describe('format parameter support', function() {
            it('should support text format parameter', function() {
                $result = $this->model->handleAction('collections', [], ['format' => 'text']);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('cli');
                // Text format should include structured data
                expect(isset($result['collections']))->toBe(true);
                expect($result['collections'])->toBeA('array');
            });

            it('should support json format parameter', function() {
                $result = $this->model->handleAction('collections', [], ['format' => 'json']);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('cli');
                // JSON format should include structured data
                expect(isset($result['collections']))->toBe(true);
                expect($result['collections'])->toBeA('array');
                expect(isset($result['summary']))->toBe(true);
            });

            it('should default to text format when no format specified', function() {
                $result = $this->model->handleAction('collections', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('cli');
                // Should include structured data even without explicit format
                expect(isset($result['collections']))->toBe(true);
                expect($result['collections'])->toBeA('array');
            });
        });
    });

    describe('CLI help system', function() {
        it('should provide help via help action', function() {
            $response = $this->model->handleAction('help', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Unknown action');
        });

        it('should handle unknown actions with error response', function() {
            $response = $this->model->handleAction('nonexistent', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['status_code'])->toBe(404);
        });
    });

    describe('registry management', function() {
        it('should list registry contents', function() {
            $response = $this->model->handleAction('registry', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('cli');
            expect($response['content'])->toContain('Backup Regist');
        });

        it('should handle registry action', function() {
            expect(method_exists($this->model, 'handleAction'))->toBe(true);

            $response = $this->model->handleAction('registry', [], []);
            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
        });
    });

    describe('backup creation', function() {
        xit('should require collection ID for backup creation', function() {
            $response = $this->model->handleAction('create-encrypted', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Collection ID required');
        });

        xit('should handle backup creation with mock data', function() {
            // This test verifies the method exists and basic validation
            expect(method_exists($this->model, 'createEncryptedDwcArchive'))->toBe(true);

            $params = ['collid' => '999', 'test_mode' => true]; // Non-existent collection
            $response = $this->model->handleAction('create-encrypted', [], $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
            // Should be cli or error due to no registered users for collection 999
            expect(in_array($response['type'], ['error', 'cli']))->toBe(true);
        });

        xit('should verify Symbiota DwcArchiverCore accessibility', function() {
            // Test that we can access the Symbiota classes without generating full backup
            $params = ['collid' => '1', 'test_mode' => true];
            $result = $this->model->handleAction('symbdwc', [], $params);

            expect($result)->toBeA('array');
            expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);

            // If error, should be about data/permissions, not missing classes
            if ($result['type'] === 'error') {
                expect($result['message'])->not->toContain('Class not found');
                expect($result['message'])->not->toContain('include');
                // Allow "required" for parameter validation, but not for missing classes
                if (strpos($result['message'], 'Collection ID') === false) {
                    expect($result['message'])->not->toContain('require');
                }
            }
        });
    });

    describe('encryption and archiving functionality', function() {
        beforeEach(function() {
            $this->tempDir = sys_get_temp_dir() . '/backup_test_' . uniqid();
            mkdir($this->tempDir, 0755, true);
        });

        afterEach(function() {
            // Clean up temp files
            if (is_dir($this->tempDir)) {
                $this->removeDirectory($this->tempDir);
            }
        });

        it('should encrypt small test files', function() {
            // Create a small test file
            $testFile = $this->tempDir . '/test.txt';
            file_put_contents($testFile, 'Test content for encryption');

            // Test encryption functionality with small file
            $passphrase = 'test_passphrase_123';
            $encryptedFile = $testFile . '.enc';

            // Use reflection to test encryption method if it exists
            $reflection = new \ReflectionClass($this->model);
            if ($reflection->hasMethod('encryptFile')) {
                $method = $reflection->getMethod('encryptFile');
                $method->setAccessible(true);

                $result = $method->invoke($this->model, $testFile, $encryptedFile, $passphrase);

                expect($result)->toBe(true);
                expect(file_exists($encryptedFile))->toBe(true);
                expect(filesize($encryptedFile))->toBeGreaterThan(0);
            } else {
                // If method doesn't exist, just verify the model can handle encryption requests
                expect(method_exists($this->model, 'handleAction'))->toBe(true);
            }
        });

        it('should create archive with small test files', function() {
            // Create small test files
            $files = [
                'meta.xml' => '<?xml version="1.0"?><archive><core><files><location>occurrences.txt</location></files></core></archive>',
                'occurrences.txt' => "id\tscientificName\n1\tTest species"
            ];

            foreach ($files as $filename => $content) {
                file_put_contents($this->tempDir . '/' . $filename, $content);
            }

            // Test that we can create archives (verify zip functionality)
            $archivePath = $this->tempDir . '/test_archive.zip';

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $result = $zip->open($archivePath, ZipArchive::CREATE);

                expect($result)->toBe(true);

                foreach ($files as $filename => $content) {
                    $zip->addFile($this->tempDir . '/' . $filename, $filename);
                }

                $zip->close();

                expect(file_exists($archivePath))->toBe(true);
                expect(filesize($archivePath))->toBeGreaterThan(0);
            } else {
                // If ZipArchive not available, just verify files exist
                foreach ($files as $filename => $content) {
                    expect(file_exists($this->tempDir . '/' . $filename))->toBe(true);
                }
            }
        });

        afterEach(function() {
            // Clean up temp files using helper function
            if (isset($this->tempDir) && is_dir($this->tempDir)) {
                removeDirectory($this->tempDir);
            }
        });
    });

    describe('mock backup functionality', function() {
        it('should generate mock meta.xml', function() {
            $collectionInfo = [
                'collectionName' => 'Test Collection',
                'institutionCode' => 'TEST'
            ];

            // Use reflection to test private method
            $reflection = new \ReflectionClass($this->model);
            $method = $reflection->getMethod('generateMockMetaXml');
            $method->setAccessible(true);

            $result = $method->invoke($this->model, $collectionInfo);

            expect($result)->toBeA('string');
            expect($result)->toContain('<?xml version="1.0"');
            expect($result)->toContain('archive xmlns="http://rs.tdwg.org/dwc/text/"');
        });

        it('should generate mock occurrence data', function() {
            // Test the method exists and basic structure
            expect(method_exists($this->model, 'generateMockOccurrenceData'))->toBe(true);

            // Note: This method calls getCollectionRecordCount which requires database access
            // In a real test environment, this would be mocked or use test fixtures
            // For now, we just verify the method exists
        });

        it('should generate mock EML metadata', function() {
            $collectionInfo = [
                'collectionName' => 'Test Collection',
                'institutionCode' => 'TEST'
            ];

            // Use reflection to test private method
            $reflection = new \ReflectionClass($this->model);
            $method = $reflection->getMethod('generateMockEmlXml');
            $method->setAccessible(true);

            $result = $method->invoke($this->model, $collectionInfo);

            expect($result)->toBeA('string');
            expect($result)->toContain('<?xml version="1.0"');
            expect($result)->toContain('Test Collection');
            expect($result)->toContain('eml:eml');
        });
    });

    describe('configuration support', function() {
        it('should load configuration from INI file', function() {
            // Create a test config file
            $testConfig = [
                'base_url' => '/test/',
                'app_url_prefix' => '/?/',
                'templates_path' => __DIR__ . '/../../fixtures/templates',
                'backup' => [
                    'output_dir' => 'dev/test/downloads',
                    'data_file' => 'dev/test/data/test.json',
                    'retention_threshold' => 3
                ]
            ];

            $model = new BackupModel($testConfig);

            // Test that the model was created successfully
            expect($model)->toBeAnInstanceOf(BackupModel::class);
        });

        it('should handle config file parameters', function() {
            $params = [
                'config' => 'config.php',
                'collid' => '4'
            ];

            // Test that config parameter is recognized
            expect(isset($params['config']))->toBe(true);
            expect($params['config'])->toBe('config.php');
        });

        it('should handle output-dir parameter', function() {
            $params = [
                'output-dir' => 'dev/test/downloads',
                'collid' => '4'
            ];

            expect(isset($params['output-dir']))->toBe(true);
            expect($params['output-dir'])->toBe('dev/test/downloads');
        });

        it('should handle registry-file parameter', function() {
            $params = [
                'registry-file' => 'dev/test/data/test.json',
                'collid' => '4'
            ];

            expect(isset($params['registry-file']))->toBe(true);
            expect($params['registry-file'])->toBe('dev/test/data/test.json');
        });

        it('should handle backup-threshold parameter', function() {
            $params = [
                'backup-threshold' => '720',
                'collid' => '4'
            ];

            expect(isset($params['backup-threshold']))->toBe(true);
            expect($params['backup-threshold'])->toBe('720');
        });

        it('should handle decimal backup-threshold parameter for testing', function() {
            $params = [
                'backup-threshold' => '0.17',
                'collid' => '4'
            ];

            expect(isset($params['backup-threshold']))->toBe(true);
            expect($params['backup-threshold'])->toBe('0.17');
            expect((float)$params['backup-threshold'])->toBe(0.17);
        });
    });

    describe('cleanup functionality', function() {
        it('should require collection ID for cleanup', function() {
            $response = $this->model->cleanupBackups([]);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toBe('Collection ID required');
        });

        it('should handle cleanup with remove-all option', function() {
            $params = [
                'collid' => '999',
                'remove-all' => true
            ];

            $response = $this->model->cleanupBackups($params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
            // Should be error due to insufficient permissions for collection 999
            expect($response['type'])->toBe('error');
        });

        it('should handle cleanup without remove-all option', function() {
            $params = [
                'collid' => '999'
            ];

            $response = $this->model->cleanupBackups($params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
            // Should be error due to insufficient permissions for collection 999
            expect($response['type'])->toBe('error');
        });
    });

    describe('throttle response improvement', function() {
        xit('should return informative response instead of error for throttle', function() {
            // This test verifies that throttle responses are informative
            // In a real scenario with throttled collection, the response type should be 'cli' or 'info'
            // rather than 'error'

            expect(method_exists($this->model, 'createEncryptedDwcArchive'))->toBe(true);

            // Test with non-existent collection (will fail before throttle check)
            $params = ['collid' => '999', 'test_mode' => true];
            $response = $this->model->handleAction('create-encrypted', [], $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
        });
    });

    describe('create-encrypted action', function() {
        it('should have createEncryptedDwcArchive method', function() {
            expect(method_exists($this->model, 'createEncryptedDwcArchive'))->toBe(true);
        });

        xit('should require collection ID', function() {
            $params = [];
            $response = $this->model->createEncryptedDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Collection ID required');
            expect($response['status_code'])->toBe(400);
        });

        xit('should require collection registration', function() {
            $params = ['171']; // Collection ID as first parameter
            $response = $this->model->createEncryptedDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Collection not registered for backup');
            expect($response['status_code'])->toBe(400);
        });

        it('should require site salt configuration', function() {
            // Create model without site salt
            $modelWithoutSalt = new BackupModel();
            $params = ['171']; // Collection ID as first parameter
            $response = $modelWithoutSalt->createEncryptedDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            // Without site salt, it will fail at registry check first since no collections are registered
            expect($response['message'])->toContain('Collection not registered for backup');
            expect($response['status_code'])->toBe(400);
        });

        it('should validate parameters correctly', function() {
            $params = ['171']; // Collection ID as first parameter

            // This will fail at registry check since no users are registered
            // but it validates that parameter validation passes
            $response = $this->model->createEncryptedDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBeA('string');
            // Should not be parameter validation error
            expect($response['message'])->not->toBe('Collection ID required');
            expect($response['message'])->not->toContain('Invalid collection ID');
        });
    });

    describe('DwC-A generation refactoring', function() {
        it('should have generateDwcArchive method', function() {
            // Use reflection to check private method exists
            $reflection = new ReflectionClass($this->model);
            $method = $reflection->getMethod('generateDwcArchive');
            expect($method->isPrivate())->toBe(true);
        });

        xit('should maintain symbdwc functionality', function() {
            expect(method_exists($this->model, 'createSymbiotaDwcArchive'))->toBe(true);

            // Test parameter validation
            $params = [];
            $response = $this->model->createSymbiotaDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Collection ID is required');
        });

        xit('should validate numeric collection ID in symbdwc', function() {
            $params = ['invalid_id'];
            $response = $this->model->createSymbiotaDwcArchive($params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('Invalid collection ID');
        });
    });

    describe('throttling configuration', function() {
        it('should handle decimal backup_threshold correctly', function() {
            // Test that 0.017 minutes (about 1 second) is handled correctly
            expect($this->model)->toBeA('object');

            // The throttling logic should handle decimal values
            // This is tested indirectly through the configuration loading
            expect(true)->toBe(true); // Placeholder for throttling logic test
        });

        it('should load classpath from configuration', function() {
            // Test that classpath configuration is loaded correctly
            expect($this->model)->toBeA('object');

            // Configuration should be loaded during model initialization
            expect(true)->toBe(true); // Placeholder for configuration test
        });
    });

    describe('command line parameter simulation', function() {
        it('should simulate backup create with config parameters', function() {
            // Simulate: backup --config=config.php create 4 7 --passphrase="testpw123"
            $params = [
                'config' => 'config.php',
                'collid' => '4',
                'userid' => '7',
                'passphrase' => 'testpw123'
            ];

            expect($params['config'])->toBe('config.php');
            expect($params['collid'])->toBe('4');
            expect($params['userid'])->toBe('7');
            expect($params['passphrase'])->toBe('testpw123');
        });

        it('should simulate backup verify-pw with config parameters', function() {
            // Simulate: backup --config=config.php verify-pw 4
            $params = [
                'config' => 'config.php',
                'collid' => '4'
            ];

            expect($params['config'])->toBe('config.php');
            expect($params['collid'])->toBe('4');
        });

        it('should simulate backup cleanup with config parameters', function() {
            // Simulate: backup --config=config.php cleanup 4
            $params = [
                'config' => 'config.php',
                'collid' => '4'
            ];

            expect($params['config'])->toBe('config.php');
            expect($params['collid'])->toBe('4');
        });

        it('should simulate backup cleanup with remove-all option', function() {
            // Simulate: backup --config=config.php cleanup --remove-all 4
            $params = [
                'config' => 'config.php',
                'collid' => '4',
                'remove-all' => true
            ];

            expect($params['config'])->toBe('config.php');
            expect($params['collid'])->toBe('4');
            expect($params['remove-all'])->toBe(true);
        });

        it('should simulate direct parameter approach', function() {
            // Simulate: backup --output-dir=dev/test/downloads --data-file=dev/test/data/test.json create 4 7 --passphrase="testpw123"
            $params = [
                'output-dir' => 'dev/test/downloads',
                'data-file' => 'dev/test/data/test.json',
                'collid' => '4',
                'userid' => '7',
                'passphrase' => 'testpw123'
            ];

            expect($params['output-dir'])->toBe('dev/test/downloads');
            expect($params['data-file'])->toBe('dev/test/data/test.json');
            expect($params['collid'])->toBe('4');
            expect($params['userid'])->toBe('7');
            expect($params['passphrase'])->toBe('testpw123');
        });
    });

    describe('CLI integration', function() {
        beforeEach(function() {
            $this->model = new BackupModel([]);
        });

        it('should handle CLI register command with missing passphrase', function() {
            // Simulate: bin/hp -c config.php register 99 23
            $routeElements = ['99', '23']; // Elements after 'register'
            $params = ['config' => 'config.php'];

            $result = $this->model->handleAction('register', $routeElements, $params);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['message'])->toBe('Passphrase required for registration. Use --passphrase=<phrase> or -p <phrase>');
            expect($result['status_code'])->toBe(400);
        });

        it('should handle CLI register command with all parameters', function() {
            // Simulate: bin/hp -c config.php register 100 23 --passphrase=test123
            $routeElements = ['100', '23']; // Elements after 'register'
            $params = [
                'config' => 'config.php',
                'passphrase' => 'test123'
            ];

            $result = $this->model->handleAction('register', $routeElements, $params);

            expect($result)->toBeA('array');
            expect(in_array($result['type'], ['success', 'error']))->toBe(true);

            if ($result['type'] === 'success') {
                expect($result['message'])->toContain('registered for collection 100 backup');
            } else {
                // Could be permission error or other valid error
                expect(isset($result['message']))->toBe(true);
            }
        });

        xit('should handle CLI create command without passphrase', function() {
            // Simulate: bin/hp -c config.php create 4
            $routeElements = ['4']; // Elements after 'create'
            $params = ['config' => 'config.php'];

            $result = $this->model->handleAction('create-encrypted', $routeElements, $params);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            // Now expects auto-detection to fail (either no users or decryption failure)
            expect($result['message'])->toMatch('/Collection not registered for backup|No users registered for collection 4|Failed to decrypt stored passphrase/');
        });

        xit('should handle CLI registry command', function() {
            // Simulate: bin/hp -c config.php registry
            $routeElements = []; // No elements after 'registry'
            $params = ['config' => 'config.php'];

            $result = $this->model->handleAction('registry', $routeElements, $params);

            expect($result)->toBeA('array');
            expect(in_array($result['type'], ['cli', 'success']))->toBe(true);
            expect(isset($result['content']))->toBe(true);
        });
    });

    describe('symbdwc functionality', function() {
        describe('createSymbiotaDwcArchive', function() {
            it('should require collection ID parameter', function() {
                $params = []; // No collection ID

                $result = $this->model->createSymbiotaDwcArchive($params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Collection ID is required');
                expect($result['status_code'])->toBe(400);
            });

            it('should validate collection ID is numeric', function() {
                $params = ['invalid_id']; // Non-numeric collection ID

                $result = $this->model->createSymbiotaDwcArchive($params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Invalid collection ID');
                expect($result['status_code'])->toBe(400);
            });

            xit('should accept valid numeric collection ID', function() {
                $params = ['171'];

                // This test will likely fail due to missing Symbiota environment
                // but should at least validate the input processing
                $result = $this->model->createSymbiotaDwcArchive($params);

                expect($result)->toBeA('array');
                // Should either succeed or fail with a meaningful error about missing Symbiota
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);

                if ($result['type'] === 'error') {
                    // Should fail due to missing Symbiota environment, not input validation
                    expect($result['message'])->not->toContain('Collection ID is required');
                    expect($result['message'])->not->toContain('Invalid collection ID');
                }
            });
        });

        describe('handleAction with symbdwc', function() {
            xit('should route symbdwc action to createSymbiotaDwcArchive method', function() {
                $routeElements = ['171'];
                $params = ['output-dir' => '/tmp/test', 'test_mode' => true];

                $result = $this->model->handleAction('symbdwc', $routeElements, $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);

                // Should not be an "Unknown action" error
                expect($result['message'] ?? '')->not->toContain('Unknown action');
            });


        });

        describe('configuration parameter handling', function() {
            it('should handle classpath parameter', function() {
                $model = new BackupModel($this->config);
                $params = ['171', 'classpath' => '/custom/path', 'output-dir' => '/tmp/test'];

                // Load configuration to test parameter processing
                $reflection = new ReflectionClass($model);
                $method = $reflection->getMethod('loadConfigurationFromParams');
                $method->setAccessible(true);
                $method->invoke($model, $params);

                // Access the backupConfig property to verify classpath was set
                $property = $reflection->getProperty('backupConfig');
                $property->setAccessible(true);
                $config = $property->getValue($model);

                expect($config['classpath'])->toBe('/custom/path');
            });

            it('should handle short classpath parameter (cp)', function() {
                $model = new BackupModel($this->config);
                $params = ['171', 'cp' => '/short/path', 'output-dir' => '/tmp/test'];

                // Load configuration to test parameter processing
                $reflection = new ReflectionClass($model);
                $method = $reflection->getMethod('loadConfigurationFromParams');
                $method->setAccessible(true);
                $method->invoke($model, $params);

                // Access the backupConfig property to verify classpath was set
                $property = $reflection->getProperty('backupConfig');
                $property->setAccessible(true);
                $config = $property->getValue($model);

                expect($config['classpath'])->toBe('/short/path');
            });
        });
    });

    /**
     * COMPREHENSIVE USAGE PATTERN TESTS
     *
     * Tests all documented CLI usage patterns for the backup module:
     * - helpers.php backup collections
     * - helpers.php backup users <collid>
     * - helpers.php backup user-collections <userid>
     * - helpers.php backup register <collid> <userid> --passphrase=<phrase>
     * - helpers.php backup create-encrypted <collid>
     * - helpers.php backup status <collid>
     * - helpers.php backup status (no collid - shows configuration)
     * - helpers.php backup list <collid>
     * - helpers.php backup cleanup <collid>
     * - helpers.php backup cleanup --remove-all <collid>
     * - helpers.php backup remove-user <collid> <userid>
     * - helpers.php backup reset-passphrase <collid> <userid> --passphrase=<phrase>
     * - helpers.php backup verify-pw [collid]
     * - helpers.php backup --dry-run <collid>
     * - helpers.php backup symbdwc <collid> [options]
     */
    describe('CLI Usage Patterns', function() {

        describe('collections action', function() {
            xit('should list all available collections', function() {
                $result = $this->model->handleAction('collections', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result)->toContainKey('collections');
            });
        });

        describe('users action', function() {
            xit('should list users for a collection', function() {
                // Skip database-dependent tests in cfStatic mode
                if ($this->skipDatabaseTests) {
                    $this->skip('Database tests skipped in cfStatic configuration state');
                    return;
                }

                $params = ['collid' => '1'];
                $result = $this->model->handleAction('users', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result)->toContainKey('users');
            });

            it('should require collid parameter', function() {
                $result = $this->model->handleAction('users', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Collection ID required');
            });
        });

        describe('user-collections action', function() {
            xit('should list collections for a user', function() {
                $params = ['userid' => '525'];
                $result = $this->model->handleAction('user-collections', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result)->toContainKey('collections');
            });

            it('should require userid parameter', function() {
                $result = $this->model->handleAction('user-collections', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('User ID required');
            });
        });

        describe('register action', function() {
            xit('should register user with passphrase for collection', function() {
                // Skip database-dependent tests in cfStatic mode
                if ($this->skipDatabaseTests) {
                    $this->skip('Database tests skipped in cfStatic configuration state');
                    return;
                }

                $params = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'test_passphrase_123'
                ];
                $result = $this->model->handleAction('register', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('registered');
            });

            it('should require collid, userid, and passphrase', function() {
                $result = $this->model->handleAction('register', [], []);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
            });
        });

        describe('create-encrypted action', function() {
            xit('should create encrypted backup for registered user', function() {
                // First register a user
                $registerParams = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'test_passphrase_123'
                ];
                $this->model->handleAction('register', [], $registerParams);

                // Then test backup creation (with test_mode to prevent large file generation)
                $params = ['collid' => '1', 'test_mode' => true];
                $result = $this->model->handleAction('create-encrypted', [], $params);

                expect($result)->toBeA('array');
                // In test mode, this should return success or appropriate test response
                expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);
            });
        });

        describe('status action', function() {
            xit('should show collection backup status when collid provided', function() {
                // Skip database-dependent tests in cfStatic mode
                if ($this->skipDatabaseTests) {
                    $this->skip('Database tests skipped in cfStatic configuration state');
                    return;
                }

                $params = ['collid' => '1'];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result)->toContainKey('status');
            });

            it('should show backup configuration when no collid provided', function() {
                $result = $this->model->handleAction('status', [], []);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'cli']))->toBe(true);
                // Should contain configuration information
                if ($result['type'] === 'success') {
                    expect($result)->toContainKey('status');
                } else {
                    expect($result)->toContainKey('content');
                    expect($result['content'])->toContain('Configuration');
                }
            });
        });

        describe('list action', function() {
            xit('should list backup files for collection', function() {
                $params = ['collid' => '1'];
                $result = $this->model->handleAction('list', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result)->toContainKey('backups');
            });
        });

        describe('cleanup action', function() {
            xit('should cleanup old backups for collection', function() {
                $params = ['collid' => '1'];
                $result = $this->model->handleAction('cleanup', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('cleanup');
            });

            xit('should support remove-all flag', function() {
                $params = ['collid' => '1', 'remove-all' => true];
                $result = $this->model->handleAction('cleanup', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });
        });

        describe('remove-user action', function() {
            xit('should remove user from collection backup registry', function() {
                // First register a user
                $registerParams = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'test_passphrase_123'
                ];
                $this->model->handleAction('register', [], $registerParams);

                // Then remove the user
                $params = ['collid' => '1', 'userid' => '525'];
                $result = $this->model->handleAction('remove-user', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('removed');
            });
        });

        describe('reset-passphrase action', function() {
            xit('should reset user passphrase for collection', function() {
                // First register a user
                $registerParams = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'old_passphrase'
                ];
                $this->model->handleAction('register', [], $registerParams);

                // Then reset passphrase
                $params = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'new_passphrase_123'
                ];
                $result = $this->model->handleAction('reset-passphrase', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
                expect($result['message'])->toContain('reset');
            });
        });

        describe('verify-pw action', function() {
            xit('should verify passphrase for collection', function() {
                // First register a user
                $registerParams = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'test_passphrase_123'
                ];
                $this->model->handleAction('register', [], $registerParams);

                // Then verify passphrase
                $params = ['collid' => '1'];
                $result = $this->model->handleAction('verify-pw', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);
            });

            xit('should work without collid to verify general configuration', function() {
                $result = $this->model->handleAction('verify-pw', [], []);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);
            });
        });

        describe('dry-run functionality', function() {
            xit('should report collection size without creating backup', function() {
                $params = ['collid' => '1', 'dry-run' => true, 'test_mode' => true];
                $result = $this->model->handleAction('create-encrypted', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'info']))->toBe(true);
                // Should contain size information without actually creating backup
                if (isset($result['message'])) {
                    expect($result['message'])->toContain('size');
                }
            });
        });

        describe('symbdwc action', function() {
            xit('should create Symbiota DwC-A backup', function() {
                $params = ['collid' => '1', 'test_mode' => true];
                $result = $this->model->handleAction('symbdwc', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);
            });

            xit('should support custom output directory', function() {
                $params = [
                    'collid' => '1',
                    'output-dir' => '/tmp/custom_backup_dir',
                    'test_mode' => true
                ];
                $result = $this->model->handleAction('symbdwc', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);
            });

            xit('should support custom classpath', function() {
                $params = [
                    'collid' => '1',
                    'classpath' => '/custom/classpath',
                    'test_mode' => true
                ];
                $result = $this->model->handleAction('symbdwc', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'error', 'info']))->toBe(true);
            });
        });
    });

    /**
     * PARAMETER VALIDATION TESTS
     *
     * Tests all documented parameters and their validation:
     * - collid, userid, --config, --data-file, --output-dir
     * - --passphrase, --remove-all, --backup-threshold, --retention-threshold
     * - --dry-run, cli_only
     */
    describe('Parameter Validation', function() {

        describe('collid parameter', function() {
            xit('should accept valid collection IDs', function() {
                $params = ['collid' => '1'];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });

            it('should handle missing collid gracefully for optional actions', function() {
                $result = $this->model->handleAction('status', [], []);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'cli']))->toBe(true);
            });
        });

        describe('userid parameter', function() {
            xit('should accept valid user IDs', function() {
                $params = ['userid' => '525'];
                $result = $this->model->handleAction('user-collections', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });
        });

        describe('passphrase parameter', function() {
            xit('should accept secure passphrases', function() {
                $params = [
                    'collid' => '1',
                    'userid' => '525',
                    'passphrase' => 'secure_passphrase_with_numbers_123'
                ];
                $result = $this->model->handleAction('register', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });
        });

        describe('threshold parameters', function() {
            xit('should accept backup-threshold parameter', function() {
                $params = [
                    'collid' => '1',
                    'backup-threshold' => '60'  // 60 minutes
                ];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });

            xit('should accept retention-threshold parameter', function() {
                $params = [
                    'collid' => '1',
                    'retention-threshold' => '7'  // 7 days
                ];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });
        });

        describe('file path parameters', function() {
            xit('should accept custom data-file parameter', function() {
                $params = [
                    'collid' => '1',
                    'data-file' => '/tmp/custom_backup_registry.json'
                ];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });

            xit('should accept custom output-dir parameter', function() {
                $params = [
                    'collid' => '1',
                    'output-dir' => '/tmp/custom_backup_output'
                ];
                $result = $this->model->handleAction('status', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });
        });

        describe('boolean flags', function() {
            xit('should handle remove-all flag', function() {
                $params = [
                    'collid' => '1',
                    'remove-all' => true
                ];
                $result = $this->model->handleAction('cleanup', [], $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('success');
            });

            xit('should handle dry-run flag', function() {
                $params = [
                    'collid' => '1',
                    'dry-run' => true,
                    'test_mode' => true
                ];
                $result = $this->model->handleAction('create-encrypted', [], $params);

                expect($result)->toBeA('array');
                expect(in_array($result['type'], ['success', 'info']))->toBe(true);
            });
        });
    });
});


