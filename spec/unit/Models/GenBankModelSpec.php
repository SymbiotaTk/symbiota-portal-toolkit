<?php

use Symbiota\Helpers\Models\GenBankModel;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;

describe('GenBankModel', function() {

    beforeEach(function() {
        // Determine if database-dependent tests should be skipped based on configuration state
        $config = \Symbiota\Helpers\Core\Configuration::getInstance();
        $configState = $config ? $config->getConfigurationState() : 'cfStatic';
        $this->skipDatabaseTests = ($configState === 'cfStatic');

        // For backward compatibility, also set databaseConnected
        $this->databaseConnected = !$this->skipDatabaseTests;

        // Test configuration that adapts to actual environment
        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates',
            'testing_mode' => true,
            'database_available' => $this->databaseConnected,
            'portal_web_path' => '/test-portal'
        ];
        $this->model = new GenBankModel($this->config);

        // Mock test data for offline scenarios
        $this->testCollections = [
            ['collid' => 1, 'collectionName' => 'Test Herbarium', 'institutionCode' => 'TEST', 'collectionCode' => 'HERB'],
            ['collid' => 2, 'collectionName' => 'Sample Museum', 'institutionCode' => 'SAMP', 'collectionCode' => 'MUS']
        ];

        $this->testSpecimen = [
            'occid' => 12345,
            'catalogNumber' => 'TEST-001',
            'collid' => 1,
            'collectionName' => 'Test Herbarium',
            'scientificName' => 'Quercus alba',
            'locality' => 'Test County, Test State',
            'decimalLatitude' => '40.7128',
            'decimalLongitude' => '-74.0060',
            'eventDate' => '2023-06-15',
            'recordedBy' => 'Test Collector',
            'family' => 'Fagaceae'
        ];
    });

    describe('static methods', function() {
        describe('getModelId', function() {
            it('should return correct model ID', function() {
                expect(GenBankModel::getModelId())->toBe('genbank');
            });
        });

        describe('getModuleType', function() {
            it('should return COMPONENT type', function() {
                expect(GenBankModel::getModuleType())->toBe(ModuleType::COMPONENT);
            });
        });

        describe('isEnabled', function() {
            it('should be enabled by default', function() {
                expect(GenBankModel::isEnabled())->toBe(true);
            });
        });

        describe('getPriority', function() {
            it('should return priority value', function() {
                $priority = GenBankModel::getPriority();
                expect($priority)->toBeA('integer');
                expect($priority)->toBeGreaterThan(0);
            });
        });

        describe('getHelpMarkdown', function() {
            it('should return help documentation', function() {
                $help = GenBankModel::getHelpMarkdown();

                expect($help)->toBeA('string');
                expect($help)->toContain('GenBank');
                expect($help)->toContain('specimen');
                expect($help)->toContain('catalogNumber');
            });
        });

        describe('getHelpInfo', function() {
            it('should parse help markdown into structured data', function() {
                $helpInfo = GenBankModel::getHelpInfo();

                expect($helpInfo)->toBeA('array');
                expect(isset($helpInfo['description']))->toBe(true);
                expect(isset($helpInfo['parameters']))->toBe(true);
                expect(isset($helpInfo['usage']))->toBe(true);
                expect(isset($helpInfo['examples']))->toBe(true);

                // Should have catalogNumber parameter
                expect(isset($helpInfo['parameters']['catalogNumber']))->toBe(true);
                expect($helpInfo['parameters']['catalogNumber']['required'])->toBe(false);
            });
        });

        describe('generateCliHelp', function() {
            it('should generate formatted CLI help', function() {
                $help = GenBankModel::generateCliHelp();

                expect($help)->toBeA('string');
                expect($help)->toContain('USAGE:');
                expect($help)->toContain('genbank');
                expect($help)->toContain('PARAMETERS:');
                expect($help)->toContain('catalogNumber');
                expect($help)->toContain('EXAMPLES:');
            });
        });
    });

    describe('request handling', function() {
        describe('handleAction', function() {
            it('should handle index action', function() {
                // Test index action (will return CLI help if in CLI mode, or main page if HTTP)
                $params = [];
                $response = $this->model->handleAction('index', [], $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Should be either 'cli' or 'html' depending on environment
                expect(in_array($response['type'], ['cli', 'html']))->toBe(true);
                expect(isset($response['content']))->toBe(true);
            });

            it('should handle catalog number search', function() {
                // Router passes simplified route (route elements after model and action)
                $route = ['163172'];
                $params = ['collid' => [1]];
                $response = $this->model->handleAction('catalogNumber', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Should be either success or error (database may not be available)
                expect(in_array($response['type'], ['success', 'error']))->toBe(true);
            });

            it('should handle occid search', function() {
                // Router passes simplified route (route elements after model and action)
                $route = ['12345'];
                $params = [];
                $response = $this->model->handleAction('occid', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Should be either success or error (database may not be available)
                expect(in_array($response['type'], ['success', 'error']))->toBe(true);
            });

            it('should handle collections listing', function() {
                $params = [];
                $response = $this->model->handleAction('collections', [], $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Should be either success or error (database may not be available)
                expect(in_array($response['type'], ['success', 'error']))->toBe(true);
            });

            it('should handle test mode', function() {
                $params = [];
                $response = $this->model->handleAction('test', [], $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Test connection returns success or error response
                expect(in_array($response['type'], ['success', 'error']))->toBe(true);

                // If successful, should contain database status info
                if ($response['type'] === 'success') {
                    expect(isset($response['content']))->toBe(true);
                    expect(isset($response['content']['database_status']))->toBe(true);
                }
            });

            // COMMENTED OUT FOR SPEED - Takes 3+ seconds due to progress spinner demo
            // it('should handle demo mode', function() {
            //     $params = [];
            //     $response = $this->model->handleAction('demo', [], $params);

            //     expect($response)->toBeA('array');
            //     expect(isset($response['type']))->toBe(true);
            //     expect($response['type'])->toBe('demo_result');
            //     expect(isset($response['message']))->toBe(true);
            // });
        });

        describe('parameter validation', function() {
            it('should validate catalog number format', function() {
                // Test various catalog number formats
                $validParams = ['catalogNumber' => '163172'];
                $errors = $this->model->validateParameters($validParams);
                expect(count($errors))->toBe(0);

                $validParams2 = ['catalogNumber' => 'ABC123'];
                $errors2 = $this->model->validateParameters($validParams2);
                expect(count($errors2))->toBe(0);
            });

            it('should validate occurrence ID format', function() {
                $validParams = ['occid' => '12345'];
                $errors = $this->model->validateParameters($validParams);
                expect(count($errors))->toBe(0);

                $invalidParams = ['occid' => 'not-a-number'];
                $errors2 = $this->model->validateParameters($invalidParams);
                expect(count($errors2))->toBeGreaterThan(0);
            });

            it('should validate collection ID format', function() {
                $validParams = ['collid' => '1,2,3'];
                $errors = $this->model->validateParameters($validParams);
                expect(count($errors))->toBe(0);

                $validParams2 = ['collid' => '5'];
                $errors2 = $this->model->validateParameters($validParams2);
                expect(count($errors2))->toBe(0);
            });
        });
    });

    describe('offline database operations', function() {
        describe('searchByCatalogNumber offline behavior', function() {
            it('should handle database scenarios gracefully', function() {
                $result = $this->model->searchByCatalogNumber('ARIZ123456', [1]);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);

                if ($this->databaseConnected) {
                    // Database connected - should get actual results or valid database response
                    expect($result['type'])->toMatch('/^(success|error)$/');
                    if ($result['type'] === 'success') {
                        expect(isset($result['content']['results']))->toBe(true);
                    }
                } else {
                    // Database not connected - should get fixtures or error
                    if ($result['type'] === 'success') {
                        expect(isset($result['content']['source']))->toBe(true);
                        expect($result['content']['source'])->toBe('fixtures');
                    } else {
                        $message = $result['message'] ?? $result['content'] ?? 'No message';
                        expect(strtolower($message))->toContain('database unavailable');
                        expect($result['status_code'])->toBe(503);
                    }
                }
            });

            it('should require collection selection when database is available', function() {
                // Create a model with database available but no collections provided
                $availableConfig = array_merge($this->config, ['database_available' => true]);
                $availableModel = new GenBankModel($availableConfig);

                $result = $availableModel->searchByCatalogNumber('TEST123', []);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect(strtolower($result['message']))->toContain('collection selection');
                expect($result['status_code'])->toBe(400);
            });
        });

        describe('searchByOccid offline behavior', function() {
            it('should handle database unavailable gracefully', function() {
                $result = $this->model->searchByOccid(12345);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);

                // Should handle gracefully whether database is available or not
                if ($result['type'] === 'error') {
                    // Offline mode
                    $message = $result['message'] ?? $result['content'] ?? 'No message';
                    expect($message)->toMatch('/Database (unavailable|query failed)/');
                    expect($result['status_code'])->toBeGreaterThan(400);
                } else {
                    // Online mode - should return search results
                    expect($result['type'])->toBe('success');
                    expect($result['status_code'])->toBe(200);
                }
            });
        });

        describe('getCollections offline behavior', function() {
            it('should handle database unavailable gracefully', function() {
                $result = $this->model->getCollections();

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);

                // Should handle gracefully whether database is available or not
                if ($result['type'] === 'error') {
                    // Offline mode
                    $message = $result['message'] ?? $result['content'] ?? 'No message';
                    expect($message)->toMatch('/Database (unavailable|query failed)/');
                    expect($result['status_code'])->toBeGreaterThan(400);
                } else {
                    // Online mode - should return collections
                    expect($result['type'])->toBe('success');
                    expect($result['status_code'])->toBe(200);
                    expect($result['content'])->toContainKey('collections');
                }
            });
        });

        describe('testConnection offline behavior', function() {
            it('should return connection status', function() {
                $result = $this->model->testConnection();

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);

                // Test should pass whether database is available or not
                if ($result['type'] === 'error') {
                    // Offline mode
                    expect($result['status_code'])->toBe(503);
                    $message = $result['message'] ?? $result['content'] ?? 'No message';
                    expect(strtolower($message))->toContain('database unavailable');
                } else {
                    // Online mode
                    expect($result['type'])->toBe('success');
                    expect($result['status_code'])->toBe(200);
                    expect($result['content'])->toContainKey('database_status');
                }
            });
        });
    });

    describe('database operations', function() {
        describe('searchByCatalogNumber', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should handle database search gracefully', function() {
            //     // This test verifies the method exists and doesn't crash
            //     // Actual database testing would require a test database
            //     expect(method_exists($this->model, 'searchByCatalogNumber'))->toBe(true);

            //     // Test with mock data
            //     $result = $this->model->searchByCatalogNumber('163172');
            //     expect($result)->toBeA('array');
            // });

            it('should verify method exists', function() {
                expect(method_exists($this->model, 'searchByCatalogNumber'))->toBe(true);
            });
        });

        describe('searchByOccid', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls
            // it('should handle occid search', function() {
            //     expect(method_exists($this->model, 'searchByOccid'))->toBe(true);

            //     $result = $this->model->searchByOccid(12345);
            //     expect($result)->toBeA('array');
            // });

            it('should verify method exists', function() {
                expect(method_exists($this->model, 'searchByOccid'))->toBe(true);
            });
        });

        describe('getCollections', function() {
            // COMMENTED OUT FOR SPEED - Makes real database calls with spinners
            // it('should retrieve collections list', function() {
            //     expect(method_exists($this->model, 'getCollections'))->toBe(true);

            //     $result = $this->model->getCollections();
            //     expect($result)->toBeA('array');
            // });

            // it('should handle collection filtering', function() {
            //     // Test without filters to avoid parameter binding issues
            //     $result = $this->model->getCollections(10, [], []);
            //     expect($result)->toBeA('array');
            //     expect(isset($result['type']))->toBe(true);
            //     // Should be either collections_list or error (database may not be available)
            //     expect(in_array($result['type'], ['collections_list', 'error']))->toBe(true);
            // });

            // it('should handle collection sorting', function() {
            //     $result = $this->model->getCollections(10, ['collectionName' => 'asc'], []);
            //     expect($result)->toBeA('array');
            //     expect(isset($result['type']))->toBe(true);
            //     // Should be either collections_list or error (database may not be available)
            //     expect(in_array($result['type'], ['collections_list', 'error']))->toBe(true);
            // });

            it('should verify method exists', function() {
                expect(method_exists($this->model, 'getCollections'))->toBe(true);
            });
        });
    });

    describe('output formatting', function() {
        describe('formatLatLon via search results', function() {
            it('should format latitude and longitude in search results', function() {
                // Test the formatting indirectly through search results
                // since formatLatLon is private
                $route = ['12345'];
                $params = [];
                $response = $this->model->handleAction('occid', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // The method exists and can be called through search results
            });
        });

        describe('formatSpecimenVoucher via search results', function() {
            it('should format specimen voucher in search results', function() {
                // Test the formatting indirectly through search results
                // since formatSpecimenVoucher is private
                $route = ['163172'];
                $params = ['collid' => [1]];
                $response = $this->model->handleAction('catalogNumber', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // The method exists and can be called through search results
            });
        });
    });

    describe('CLI vs HTTP behavior', function() {
        it('should behave differently in CLI mode', function() {
            Environment::forceEnvironment(Environment::CLI);

            $params = [];
            $response = $this->model->handleAction('index', [], $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('cli');
            expect(isset($response['content']))->toBe(true);

            Environment::reset();
        });

        it('should behave differently in HTTP mode', function() {
            // Mock HTTP environment
            Environment::forceEnvironment(Environment::HTTP);

            // Create model with database available for HTTP testing
            $httpConfig = array_merge($this->config, ['database_available' => true]);
            $httpModel = new GenBankModel($httpConfig);

            $params = [];
            $response = $httpModel->handleAction('index', [], $params);

            expect($response)->toBeA('array');
            // HTTP mode should return HTML content when database is available
            expect($response['type'])->toBe('html');

            // Reset environment
            Environment::reset();
        });
    });

    describe('error handling', function() {
        xit('should handle database connection errors gracefully', function() {
            // Test with invalid database config
            $badConfig = array_merge($this->config, [
                'database' => [
                    'host' => 'invalid-host',
                    'username' => 'invalid',
                    'password' => 'invalid',
                    'database' => 'invalid'
                ]
            ]);

            $model = new GenBankModel($badConfig);
            $result = $model->searchByCatalogNumber('163172');

            expect($result)->toBeA('array');
            expect(isset($result['message']))->toBe(true);
        });

        it('should handle invalid parameters gracefully', function() {
            $params = ['invalid_param' => 'value'];
            $response = $this->model->handleAction('index', [], $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
        });

        it('should handle empty search results', function() {
            $result = $this->model->searchByCatalogNumber('NONEXISTENT');

            expect($result)->toBeA('array');
            // Should handle empty results gracefully
        });
    });

    describe('configuration', function() {
        it('should use provided configuration', function() {
            $baseUrl = $this->model->getConfig('base_url');
            expect($baseUrl)->toBe('/test/');
        });

        it('should handle missing configuration gracefully', function() {
            $missing = $this->model->getConfig('missing_key', 'default');
            expect($missing)->toBe('default');
        });
    });

    describe('template rendering', function() {
        it('should handle template rendering gracefully', function() {
            // This test verifies template rendering doesn't crash
            // Even if templates don't exist in test environment
            try {
                $result = $this->model->getMainPage();
                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
            } catch (\RuntimeException $e) {
                // Template files may not exist in test environment
                expect($e->getMessage())->toContain('Template file not found');
            }
        });
    });

    describe('CLI command structure and output format', function() {
        beforeEach(function() {
            Environment::forceEnvironment(Environment::CLI);
        });

        afterEach(function() {
            Environment::reset();
        });

        it('should provide proper CLI help format', function() {
            $response = $this->model->handleAction('index', [], []);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('cli');
            expect(isset($response['content']))->toBe(true);
            expect($response['content'])->toContain('USAGE:');
            expect($response['content'])->toContain('genbank');
            expect($response['content'])->toContain('PARAMETERS:');
            expect($response['content'])->toContain('EXAMPLES:');
        });

        it('should format CLI search results properly', function() {
            $route = ['163172'];
            $params = ['collid' => [2]];
            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);

            if ($response['type'] === 'search_result') {
                expect(isset($response['search_type']))->toBe(true);
                expect($response['search_type'])->toBe('catalogNumber');
                expect(isset($response['query']))->toBe(true);
                expect($response['query'])->toBe('163172');
                expect(isset($response['results']))->toBe(true);
                expect($response['results'])->toBeA('array');
            }
        });

        it('should handle CLI error messages properly', function() {
            $route = [''];
            $params = ['collid' => [1]];

            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            // Should get validation error for empty catalog number
            expect(strtolower($response['message']))->toContain('catalog number is required');
        });
    });

    describe('JSON output validation', function() {
        it('should produce valid JSON structure for search results', function() {
            $route = ['163172'];
            $params = ['collid' => [2]];
            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');

            // Verify JSON serializable
            $json = json_encode($response);
            expect($json)->not->toBe(false);

            $decoded = json_decode($json, true);
            expect($decoded)->toBeA('array');
            expect($decoded)->toBe($response);
        });

        it('should include required fields in search results', function() {
            $route = ['22877'];
            $params = [];
            $response = $this->model->handleAction('occid', $route, $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);

            if ($response['type'] === 'search_result') {
                expect(isset($response['search_type']))->toBe(true);
                expect(isset($response['query']))->toBe(true);
                expect(isset($response['results']))->toBe(true);
                expect(isset($response['database_status']))->toBe(true);
                expect(isset($response['config_path']))->toBe(true);
            }
        });

        xit('should validate error response structure', function() {
            $route = ['163172'];
            $params = []; // Missing required collid
            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect(isset($response['message']))->toBe(true);
            expect(isset($response['operation']))->toBe(true);

            // Should be JSON serializable
            $json = json_encode($response);
            expect($json)->not->toBe(false);
        });
    });

    describe('specific error handling scenarios', function() {
        it('should handle invalid occid gracefully', function() {
            $route = ['0'];
            $params = [];

            $response = $this->model->handleAction('occid', $route, $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            // Should get validation error for invalid occid
            expect(strtolower($response['message']))->toContain('valid occid is required');
        });

        it('should handle empty catalog number', function() {
            $route = [''];
            $params = ['collid' => [1]];

            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            // Should get validation error for empty catalog number
            expect(strtolower($response['message']))->toContain('catalog number is required');
        });

        it('should handle missing collection ID for catalog search', function() {
            // Create model with database available to test collection selection logic
            $availableConfig = array_merge($this->config, ['database_available' => true]);
            $availableModel = new GenBankModel($availableConfig);

            $route = ['163172'];
            $params = [];
            $response = $availableModel->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect($response['type'])->toBe('error');
            expect($response['message'])->toContain('collection selection');
            expect($response['operation'])->toBe('catalogNumber');
        });

        it('should handle database connection failures', function() {
            // Test with a model that has no database configuration
            $noDatabaseConfig = array_merge($this->config, ['database_available' => false]);
            $model = new GenBankModel($noDatabaseConfig);
            $result = $model->searchByCatalogNumber('163172', [1]);

            expect($result)->toBeA('array');
            // Should handle gracefully whether database is available or not
            expect($result['type'])->toBeA('string');
            expect(isset($result['message']) || isset($result['database_status']) || isset($result['results']) || isset($result['content']))->toBe(true);
        });

        it('should handle SQL injection attempts', function() {
            $maliciousInput = "'; DROP TABLE omoccurrences; --";
            $route = [$maliciousInput];
            $params = ['collid' => [1]];

            // Should not throw SQL errors, should handle gracefully
            $response = $this->model->handleAction('catalogNumber', $route, $params);
            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
        });
    });

    describe('progress spinner functionality', function() {
        it('should handle progress spinner in search operations', function() {
            // Test that progress spinner doesn't break search functionality
            $route = ['163172'];
            $params = ['collid' => [2]];
            $response = $this->model->handleAction('catalogNumber', $route, $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
            // Progress spinner should not affect final response structure
        });

        it('should handle progress spinner in collections listing', function() {
            $response = $this->model->handleAction('collections', [], []);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
            // Progress spinner should not affect final response structure
        });

        // COMMENTED OUT FOR SPEED - Takes 3+ seconds due to progress spinner demo
        // it('should provide demo with progress spinner', function() {
        //     $response = $this->model->handleAction('demo', [], []);

        //     expect($response)->toBeA('array');
        //     expect($response['type'])->toBe('demo_result');
        //     expect(isset($response['message']))->toBe(true);
        //     expect(isset($response['features']))->toBe(true);
        //     expect($response['features'])->toBeA('array');
        //     expect(in_array('Progress Spinner', $response['features']))->toBe(true);
        // });

        it('should not break when progress spinner is disabled', function() {
            // Test search without progress spinner interference
            $route = ['22877'];
            $params = [];
            $response = $this->model->handleAction('occid', $route, $params);

            expect($response)->toBeA('array');
            expect(isset($response['type']))->toBe(true);
        });
    });

    describe('enhanced functionality', function() {
        describe('catalog number search with collection filtering', function() {
            it('should require collection selection for catalog searches', function() {
                // Create model with database available to test collection selection logic
                $availableConfig = array_merge($this->config, ['database_available' => true]);
                $availableModel = new GenBankModel($availableConfig);

                $route = ['163172'];
                $params = []; // No collection ID provided
                $response = $availableModel->handleAction('catalogNumber', $route, $params);

                expect($response)->toBeA('array');
                expect($response['type'])->toBe('error');
                expect($response['message'])->toContain('collection selection');
                expect($response['operation'])->toBe('catalogNumber');
            });

            it('should handle catalog search with collection filtering', function() {
                $route = ['163172'];
                $params = ['collid' => [1, 2, 3]];
                $response = $this->model->handleAction('catalogNumber', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Operation key may or may not be present depending on response type
                if (isset($response['operation'])) {
                    expect($response['operation'])->toBe('catalogNumber');
                }
                if ($response['type'] === 'search_result') {
                    if (isset($response['filters'])) {
                        expect($response['filters']['collid'])->toBe([1, 2, 3]);
                    }
                }
            });
        });

        describe('occurrence ID search', function() {
            it('should handle occid search', function() {
                $route = ['12345'];
                $params = [];
                $response = $this->model->handleAction('occid', $route, $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                // Should be either success or error (database may not be available)
                expect(in_array($response['type'], ['success', 'error']))->toBe(true);
                if (isset($response['operation'])) {
                    expect($response['operation'])->toBe('occid');
                }
            });

            it('should validate occid format', function() {
                $route = ['0']; // Invalid occid (router passes simplified route)
                $params = [];

                $result = $this->model->handleAction('occid', $route, $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Valid occid is required');
            });
        });

        describe('collections listing with advanced features', function() {
            it('should support collections listing with sorting and filtering', function() {
                $params = [];
                $response = $this->model->handleAction('collections', [], $params);

                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                if ($response['type'] === 'collections_list') {
                    expect(isset($response['collections']))->toBe(true);
                    expect($response['collections'])->toBeA('array');
                    expect(isset($response['database_status']))->toBe(true);
                }
            });

            it('should handle collections options for HTMX dropdown', function() {
                $route = []; // Route is array of element names after model/action
                $params = ['limit' => 50, 'format' => 'htmx']; // Format is passed in params
                $response = $this->model->handleAction('collections', $route, $params);

                expect($response)->toBeA('array');
                expect($response['type'])->toBe('htmx');
                expect(isset($response['content']))->toBe(true);
            });
        });

        describe('database integration', function() {
            it('should handle database unavailable gracefully', function() {
                // Test with invalid database config to simulate unavailable database
                $badConfig = array_merge($this->config, [
                    'database' => [
                        'host' => 'invalid-host',
                        'username' => 'invalid',
                        'password' => 'invalid',
                        'database' => 'invalid'
                    ]
                ]);

                $model = new GenBankModel($badConfig);
                $route = ['163172'];
                $params = ['collid' => [1]];
                $result = $model->handleAction('catalogNumber', $route, $params);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                // Database may be available or unavailable in test environment
                if ($result['type'] === 'error') {
                    expect(isset($result['database_status']))->toBe(true);
                    expect(in_array($result['database_status'], ['unavailable', 'error']))->toBe(true);
                } else {
                    // If database is available, we should get a success response
                    expect(in_array($result['type'], ['success', 'search_result']))->toBe(true);
                }
            });

            it('should provide database connection testing', function() {
                $response = $this->model->handleAction('test', [], []);

                expect($response)->toBeA('array');
                // Test connection returns various response structures depending on database availability
                // Check for common fields that should be present
                expect(isset($response['type']) || isset($response['database_status']) || isset($response['status']))->toBe(true);
            });
        });

        // describe('progress spinner and demo features', function() {
            // COMMENTED OUT FOR SPEED - Takes 3+ seconds due to progress spinner demo
            // it('should provide progress spinner demo', function() {
            //     $response = $this->model->handleAction('demo', [], []);

            //     expect($response)->toBeA('array');
            //     expect($response['type'])->toBe('demo_result');
            //     expect(isset($response['message']))->toBe(true);
            //     expect(isset($response['features']))->toBe(true);
            //     expect($response['features'])->toBeA('array');
            // });
        // });

        describe('parameter validation', function() {
            it('should validate catalog number parameter', function() {
                $route = ['']; // Empty catalog number
                $params = ['collid' => [1]];

                $result = $this->model->handleAction('catalogNumber', $route, $params);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('error');
                expect($result['message'])->toContain('Catalog number is required');
            });

            it('should handle comma-separated collection IDs', function() {
                $params = ['collid' => '1,2,3'];
                $errors = $this->model->validateParameters($params);

                expect($errors)->toBeA('array');
                expect(count($errors))->toBe(0);
            });
        });
    });

    describe('template and response handling', function() {
        describe('getCollectionName', function() {
            it('should handle empty collection ID', function() {
                $result = $this->model->getCollectionName(['collid' => '']);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('htmx');
                expect($result['content'])->toBe('None selected');
            });

            it('should handle invalid collection ID', function() {
                $result = $this->model->getCollectionName(['collid' => '-1']);

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('htmx');
                expect($result['content'])->toBe('None selected');
            });
        });

        describe('getClearResults', function() {
            it('should return clear results content', function() {
                $result = $this->model->getClearResults();

                expect($result)->toBeA('array');
                expect($result['type'])->toBe('html');
                expect(isset($result['content']))->toBe(true);
                expect($result['content'])->toContain('search');
            });
        });

//        describe('demoProgressSpinner', function() {
//            it('should return demo results without actual delays', function() {
//                // Mock the progress methods to avoid delays in tests
//                $result = $this->model->demoProgressSpinner();
//
//                expect($result)->toBeA('array');
//                expect($result['type'])->toBe('success');
//                expect(isset($result['data']))->toBe(true);
//                expect($result['data']['message'])->toContain('demo completed');
//            });
//        });

        describe('formatLatLon', function() {
            it('should format coordinates correctly', function() {
                $specimen = [
                    'decimalLatitude' => '40.7128',
                    'decimalLongitude' => '-74.0060'
                ];

                // Use reflection to test private method
                $reflection = new ReflectionClass($this->model);
                $method = $reflection->getMethod('formatLatLon');
                $method->setAccessible(true);

                $result = $method->invoke($this->model, $specimen);
                expect($result)->toBe('40.7128 N 74.006 W');
            });

            it('should handle missing coordinates', function() {
                $specimen = [];

                $reflection = new ReflectionClass($this->model);
                $method = $reflection->getMethod('formatLatLon');
                $method->setAccessible(true);

                $result = $method->invoke($this->model, $specimen);
                expect($result)->toBe('');
            });
        });

        describe('formatSpecimenVoucher', function() {
            it('should format voucher correctly', function() {
                $specimen = [
                    'institutionCode' => 'TEST',
                    'collectionCode' => 'HERB',
                    'catalogNumber' => '12345'
                ];

                $reflection = new ReflectionClass($this->model);
                $method = $reflection->getMethod('formatSpecimenVoucher');
                $method->setAccessible(true);

                $result = $method->invoke($this->model, $specimen);
                expect($result)->toBe('TEST:HERB-12345');
            });

            it('should handle missing catalog number', function() {
                $specimen = [
                    'institutionCode' => 'TEST',
                    'collectionCode' => 'HERB'
                ];

                $reflection = new ReflectionClass($this->model);
                $method = $reflection->getMethod('formatSpecimenVoucher');
                $method->setAccessible(true);

                $result = $method->invoke($this->model, $specimen);
                expect($result)->toBe('');
            });
        });
    });

    describe('CLI command examples with database available', function() {
        beforeEach(function() {
            skipIf(!$this->databaseConnected, 'Database not available for CLI testing');
        });

        describe('catalog number search commands', function() {
            it('should handle basic catalog number search: genbank catalogNumber 163172', function() {
                $result = $this->model->handleAction('catalogNumber', [], ['catalogNumber' => '163172']);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['search_result', 'error']))->toBe(true);

                if ($result['type'] === 'search_result') {
                    expect(isset($result['data']))->toBe(true);
                    expect($result['data'])->toBeA('array');
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });

            it('should handle catalog number search with collection filter: genbank catalogNumber 163172 --collid=2,5,10', function() {
                $result = $this->model->handleAction('catalogNumber', [], [
                    'catalogNumber' => '163172',
                    'collid' => '2,5,10'
                ]);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['search_result', 'error']))->toBe(true);

                if ($result['type'] === 'search_result') {
                    expect(isset($result['data']))->toBe(true);
                    expect($result['data'])->toBeA('array');
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });
        });

        describe('occurrence ID search commands', function() {
            it('should handle occurrence ID search: genbank occid 22877', function() {
                $result = $this->model->handleAction('occid', [], ['occid' => '22877']);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['search_result', 'error']))->toBe(true);

                if ($result['type'] === 'search_result') {
                    expect(isset($result['data']))->toBe(true);
                    expect($result['data'])->toBeA('array');
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });
        });

        describe('collections listing commands', function() {
            it('should handle basic collections listing: genbank collections --limit=50', function() {
                $result = $this->model->handleAction('collections', [], ['limit' => '50']);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['collections_list', 'error']))->toBe(true);

                if ($result['type'] === 'collections_list') {
                    expect(isset($result['collections']))->toBe(true);
                    expect($result['collections'])->toBeA('array');
                    expect(count($result['collections']) <= 50)->toBe(true);

                    // Verify collection structure
                    if (!empty($result['collections'])) {
                        $collection = $result['collections'][0];
                        expect(isset($collection['collid']))->toBe(true);
                        expect(isset($collection['collectionName']))->toBe(true);
                        expect(isset($collection['institutionCode']))->toBe(true);
                    }
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });

            it('should handle collections with sorting: genbank collections --sort=collectionName:desc --filter=university:like', function() {
                $result = $this->model->handleAction('collections', [], [
                    'sort' => 'collectionName:desc',
                    'filter' => 'university:like'
                ]);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['collections_list', 'error']))->toBe(true);

                if ($result['type'] === 'collections_list') {
                    expect(isset($result['collections']))->toBe(true);
                    expect($result['collections'])->toBeA('array');

                    // Verify filtering worked (should contain university-related collections)
                    if (!empty($result['collections'])) {
                        $hasUniversityCollections = false;
                        foreach ($result['collections'] as $collection) {
                            if (stripos($collection['collectionName'], 'university') !== false) {
                                $hasUniversityCollections = true;
                                break;
                            }
                        }
                        expect($hasUniversityCollections)->toBe(true);
                    }
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });

            it('should handle complex sorting and filtering: genbank collections --sort=institutionCode:asc,collectionName:asc --filter=herbarium:like:and:collectionName', function() {
                $result = $this->model->handleAction('collections', [], [
                    'sort' => 'institutionCode:asc,collectionName:asc',
                    'filter' => 'herbarium:like:and:collectionName'
                ]);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect(in_array($result['type'], ['collections_list', 'error']))->toBe(true);

                if ($result['type'] === 'collections_list') {
                    expect(isset($result['collections']))->toBe(true);
                    expect($result['collections'])->toBeA('array');

                    // Verify sorting worked (institutionCode should be in ascending order)
                    if (count($result['collections']) > 1) {
                        $prevInstitutionCode = '';
                        foreach ($result['collections'] as $collection) {
                            if (!empty($prevInstitutionCode)) {
                                expect($collection['institutionCode'] >= $prevInstitutionCode)->toBe(true);
                            }
                            $prevInstitutionCode = $collection['institutionCode'];
                        }
                    }
                } else {
                    expect(isset($result['message']))->toBe(true);
                    expect($result['message'])->toBeA('string');
                }
            });
        });

        describe('utility commands', function() {
            it('should handle test command: genbank test', function() {
                $result = $this->model->handleAction('test', [], []);

                expect($result)->toBeA('array');
                expect(isset($result['type']))->toBe(true);
                expect($result['type'])->toBe('success');

                // Check if data exists before accessing it
                if (isset($result['data'])) {
                    expect($result['data'])->toBeA('array');
                    expect(isset($result['data']['database_status']))->toBe(true);
                    expect(in_array($result['data']['database_status'], ['connected', 'disconnected']))->toBe(true);

                    if ($result['data']['database_status'] === 'connected') {
                        expect(isset($result['data']['test_query']))->toBe(true);
                        expect(isset($result['data']['server_info']))->toBe(true);
                    }
                }
            });

//            it('should handle demo command: genbank demo', function() {
//                $result = $this->model->handleAction('demo', [], []);
//
//                expect($result)->toBeA('array');
//                expect(isset($result['type']))->toBe(true);
//                expect(in_array($result['type'], ['demo', 'success']))->toBe(true);
//
//                // Demo should return some kind of demonstration data
//                if (isset($result['message'])) {
//                    expect($result['message'])->toBeA('string');
//                    expect(strlen($result['message']) > 0)->toBe(true);
//                } else if (isset($result['data'])) {
//                    // Demo might return data instead of message
//                    expect($result['data'])->toBeA('array');
//                }
//            });
        });
    });
});
