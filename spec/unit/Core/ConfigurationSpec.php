<?php

use Symbiota\Helpers\Core\Configuration;

describe('Configuration', function() {
    
    beforeEach(function() {
        $this->config = new Configuration([
            'app' => ['name' => 'Test App'],
            'components' => [
                'test-component' => [
                    'enabled' => true,
                    'setting' => 'value'
                ]
            ]
        ]);
    });
    
    describe('constructor', function() {
        it('should merge config with defaults', function() {
            $name = $this->config->get('app.name');
            $version = $this->config->get('app.version');
            
            expect($name)->toBe('Test App'); // Override
            expect($version)->toBe('2.0.0'); // Default
        });
    });
    
    describe('get', function() {
        it('should return nested values', function() {
            $name = $this->config->get('app.name');
            expect($name)->toBe('Test App');
        });
        
        it('should return default for missing keys', function() {
            $missing = $this->config->get('missing.key', 'default');
            expect($missing)->toBe('default');
        });
        
        it('should return null for missing keys without default', function() {
            $missing = $this->config->get('missing.key');
            expect($missing)->toBeNull();
        });
    });
    
    describe('set', function() {
        it('should set nested values', function() {
            $this->config->set('new.nested.value', 'test');
            $value = $this->config->get('new.nested.value');
            expect($value)->toBe('test');
        });
        
        it('should override existing values', function() {
            $this->config->set('app.name', 'New Name');
            $name = $this->config->get('app.name');
            expect($name)->toBe('New Name');
        });
    });
    
    describe('component management', function() {
        describe('isComponentEnabled', function() {
            it('should return true for enabled component', function() {
                $enabled = $this->config->isComponentEnabled('test-component');
                expect($enabled)->toBe(true);
            });
            
            it('should return false for disabled component', function() {
                $this->config->set('components.test-component.enabled', false);
                $enabled = $this->config->isComponentEnabled('test-component');
                expect($enabled)->toBe(false);
            });
            
            it('should return false for missing component', function() {
                $enabled = $this->config->isComponentEnabled('missing-component');
                expect($enabled)->toBe(false);
            });
        });
        
        describe('enableComponent', function() {
            it('should enable component', function() {
                $this->config->enableComponent('new-component');
                $enabled = $this->config->isComponentEnabled('new-component');
                expect($enabled)->toBe(true);
            });
        });
        
        describe('disableComponent', function() {
            it('should disable component', function() {
                $this->config->disableComponent('test-component');
                $enabled = $this->config->isComponentEnabled('test-component');
                expect($enabled)->toBe(false);
            });
        });
        
        describe('getComponentConfig', function() {
            it('should return component configuration', function() {
                $config = $this->config->getComponentConfig('test-component');
                expect($config['enabled'])->toBe(true);
                expect($config['setting'])->toBe('value');
            });
            
            it('should return empty array for missing component', function() {
                $config = $this->config->getComponentConfig('missing');
                expect($config)->toBe([]);
            });
        });
    });
    
    describe('validate', function() {
        it('should return empty array for valid config', function() {
            $errors = $this->config->validate();
            expect($errors)->toBe([]);
        });
        
        it('should detect security issues', function() {
            $this->config->set('security.require_authentication', false);
            $errors = $this->config->validate();
            expect($errors)->toContain('Authentication should be required for security');
        });
        
        it('should detect unsafe storage paths', function() {
            $this->config->set('storage.base_path', '/var/www/html/storage');
            $errors = $this->config->validate();
            expect($errors)->toContain('Storage path must be outside web space for security');
        });
    });
    
    describe('getAll', function() {
        it('should return complete configuration', function() {
            $all = $this->config->getAll();
            expect($all)->toBeA('array');
            expect($all['app']['name'])->toBe('Test App');
        });
    });
    
    describe('fromFile', function() {
        it('should throw exception for missing file', function() {
            expect(function() {
                Configuration::fromFile('/nonexistent/file.php');
            })->toThrow(new RuntimeException('Configuration file not found: /nonexistent/file.php'));
        });
    });

    describe('Secure Configuration System', function() {

        beforeEach(function() {
            // Clean up any environment variables
            putenv('SYMBIOTA_DISABLED_COMPONENTS');
            putenv('SYMBIOTA_DB_CONNECTION');
            putenv('SYMBIOTA_DEBUG');
        });

        describe('Component Disable System', function() {

            it('should check if component is disabled for HTTP', function() {
                $config = new Configuration([
                    'app' => [
                        'disabled_components' => ['backup', 'upload']
                    ]
                ]);

                expect($config->isComponentDisabledForHttp('backup'))->toBe(true);
                expect($config->isComponentDisabledForHttp('upload'))->toBe(true);
                expect($config->isComponentDisabledForHttp('genbank'))->toBe(false);
            });

            it('should disable component for HTTP', function() {
                $config = new Configuration();

                expect($config->isComponentDisabledForHttp('backup'))->toBe(false);

                $config->disableComponentForHttp('backup');
                expect($config->isComponentDisabledForHttp('backup'))->toBe(true);
            });

            it('should enable component for HTTP', function() {
                $config = new Configuration([
                    'app' => ['disabled_components' => ['backup']]
                ]);

                expect($config->isComponentDisabledForHttp('backup'))->toBe(true);

                $config->enableComponentForHttp('backup');
                expect($config->isComponentDisabledForHttp('backup'))->toBe(false);
            });

            it('should get list of disabled HTTP components', function() {
                $config = new Configuration([
                    'app' => ['disabled_components' => ['backup', 'upload']]
                ]);

                $disabled = $config->getDisabledHttpComponents();
                expect($disabled)->toBe(['backup', 'upload']);
            });

        });

        describe('Environment Variable Overrides', function() {

            it('should override disabled components from environment', function() {
                putenv('SYMBIOTA_DISABLED_COMPONENTS=backup,upload,genbank');

                $config = new Configuration();

                expect($config->isComponentDisabledForHttp('backup'))->toBe(true);
                expect($config->isComponentDisabledForHttp('upload'))->toBe(true);
                expect($config->isComponentDisabledForHttp('genbank'))->toBe(true);
            });

            it('should override database connection from environment', function() {
                putenv('SYMBIOTA_DB_CONNECTION=/env/path/dbconnection.php');

                $config = new Configuration();

                expect($config->get('database.dbconnection'))->toBe('/env/path/dbconnection.php');
            });

            it('should override debug mode from environment', function() {
                putenv('SYMBIOTA_DEBUG=true');

                $config = new Configuration();

                expect($config->get('app.debug'))->toBe(true);
            });

            it('should handle empty environment variables', function() {
                putenv('SYMBIOTA_DISABLED_COMPONENTS=');

                $config = new Configuration();

                expect($config->getDisabledHttpComponents())->toBe([]);
            });

            it('should trim whitespace from environment components', function() {
                putenv('SYMBIOTA_DISABLED_COMPONENTS= backup , upload , genbank ');

                $config = new Configuration();

                $disabled = $config->getDisabledHttpComponents();
                expect($disabled)->toBe(['backup', 'upload', 'genbank']);
            });

        });

        describe('PHP Configuration Files', function() {

            beforeEach(function() {
                // Create temporary PHP config file
                $this->tempPhpFile = tempnam(sys_get_temp_dir(), 'config_test_') . '.php';
                $phpConfig = <<<'PHP'
<?php
return [
    'app' => [
        'debug' => true,
        'disabled_components' => ['backup', 'upload']
    ],
    'database' => [
        'dbconnection' => '/php/config/dbconnection.php'
    ]
];
PHP;
                file_put_contents($this->tempPhpFile, $phpConfig);
            });

            afterEach(function() {
                if (file_exists($this->tempPhpFile)) {
                    unlink($this->tempPhpFile);
                }
            });

            it('should load PHP configuration file', function() {
                $config = new Configuration($this->tempPhpFile);

                expect($config->get('app.debug'))->toBe(true);
                expect($config->get('app.disabled_components'))->toBe(['backup', 'upload']);
                expect($config->get('database.dbconnection'))->toBe('/php/config/dbconnection.php');
            });

            it('should handle missing PHP config file', function() {
                $config = new Configuration('/nonexistent/config.php');

                // Should fall back to defaults
                expect($config->get('app.name'))->toBe('Symbiota Portal Helpers');
                expect($config->get('app.disabled_components'))->toBe([]);
            });

        });

        describe('INI Configuration Files', function() {

            beforeEach(function() {
                // Create temporary INI config file
                $this->tempIniFile = tempnam(sys_get_temp_dir(), 'config_test_') . '.ini';
                $iniConfig = <<<'INI'
[app]
debug = true
disabled_components[] = "backup"
disabled_components[] = "upload"

[database]
dbconnection = "/ini/config/dbconnection.php"
timeout = 60
INI;
                file_put_contents($this->tempIniFile, $iniConfig);
            });

            afterEach(function() {
                if (file_exists($this->tempIniFile)) {
                    unlink($this->tempIniFile);
                }
            });

            it('should load INI configuration file', function() {
                $config = new Configuration($this->tempIniFile);

                expect($config->get('app.debug'))->toBe(true); // INI converted to boolean
                expect($config->get('app.disabled_components'))->toBe(['backup', 'upload']);
                expect($config->get('database.dbconnection'))->toBe('/ini/config/dbconnection.php');
                expect($config->get('database.timeout'))->toBe(60); // INI converted to integer
            });

            it('should handle missing INI config file', function() {
                $config = new Configuration('/nonexistent/config.ini');

                // Should fall back to defaults
                expect($config->get('app.name'))->toBe('Symbiota Portal Helpers');
                expect($config->get('app.disabled_components'))->toBe([]);
            });

        });

        describe('Reference Configuration', function() {

            beforeEach(function() {
                // Create external config file
                $this->externalIniFile = tempnam(sys_get_temp_dir(), 'external_config_') . '.ini';
                $externalConfig = <<<'INI'
[app]
disabled_components[] = "backup"
disabled_components[] = "upload"

[database]
dbconnection = "/external/dbconnection.php"
INI;
                file_put_contents($this->externalIniFile, $externalConfig);

                // Create reference file
                $this->referenceFile = tempnam(sys_get_temp_dir(), 'reference_config_') . '.ini';
                file_put_contents($this->referenceFile, "source: {$this->externalIniFile}");
            });

            afterEach(function() {
                if (file_exists($this->externalIniFile)) {
                    unlink($this->externalIniFile);
                }
                if (file_exists($this->referenceFile)) {
                    unlink($this->referenceFile);
                }
            });

            it('should load configuration from reference file', function() {
                $config = new Configuration($this->referenceFile);

                expect($config->get('app.disabled_components'))->toBe(['backup', 'upload']);
                expect($config->get('database.dbconnection'))->toBe('/external/dbconnection.php');
            });

            it('should handle missing external reference file', function() {
                $referenceFile = tempnam(sys_get_temp_dir(), 'bad_reference_') . '.ini';
                file_put_contents($referenceFile, 'source: /nonexistent/config.ini');

                $config = new Configuration($referenceFile);

                // Should fall back to defaults when external file doesn't exist
                expect($config->get('app.name'))->toBe('Symbiota Portal Helpers');

                unlink($referenceFile);
            });

        });

        describe('Example Configuration Generation', function() {

            it('should generate example configurations', function() {
                $examples = Configuration::getExampleConfigurations();

                expect($examples)->toBeA('array');
                expect(isset($examples['php']))->toBe(true);
                expect(isset($examples['reference']))->toBe(true);
                expect(isset($examples['ini']))->toBe(true);
                expect(isset($examples['environment']))->toBe(true);

                expect($examples['php'])->toContain('<?php');
                expect($examples['php'])->toContain('disabled_components');

                expect($examples['reference'])->toContain('source:');

                expect($examples['ini'])->toContain('[app]');
                expect($examples['ini'])->toContain('disabled_components[]');

                expect($examples['environment'])->toContain('SYMBIOTA_DISABLED_COMPONENTS');
            });

            it('should generate legacy INI example', function() {
                $ini = Configuration::getExampleIni();

                expect($ini)->toContain('[app]');
                expect($ini)->toContain('disabled_components[]');
                expect($ini)->toContain('WARNING');
            });

        });

        describe('Security Integration', function() {

            it('should integrate with component disable system', function() {
                $config = new Configuration([
                    'app' => ['disabled_components' => ['backup', 'upload']]
                ]);

                // Test that disabled components are properly tracked
                expect($config->isComponentDisabledForHttp('backup'))->toBe(true);
                expect($config->isComponentDisabledForHttp('upload'))->toBe(true);
                expect($config->isComponentDisabledForHttp('genbank'))->toBe(false);

                // Test that the list is accurate
                $disabled = $config->getDisabledHttpComponents();
                expect(count($disabled))->toBe(2);
                expect(in_array('backup', $disabled))->toBe(true);
                expect(in_array('upload', $disabled))->toBe(true);
            });

            it('should prioritize environment variables over config files', function() {
                // Set environment variable
                putenv('SYMBIOTA_DISABLED_COMPONENTS=genbank,taxonomy-report');

                // Create config with different disabled components
                $config = new Configuration([
                    'app' => ['disabled_components' => ['backup', 'upload']]
                ]);

                // Environment should override config file
                expect($config->isComponentDisabledForHttp('genbank'))->toBe(true);
                expect($config->isComponentDisabledForHttp('taxonomy-report'))->toBe(true);
                expect($config->isComponentDisabledForHttp('backup'))->toBe(false);
                expect($config->isComponentDisabledForHttp('upload'))->toBe(false);
            });

        });

    });

    describe('INI Heredoc Configuration', function() {

        describe('real config.php verification', function() {
            xit('should successfully load the actual config.php file', function() {
                $configPath = 'config.php';

                if (!file_exists($configPath)) {
                    $this->skip('config.php file not found');
                    return;
                }

                $config = new Configuration($configPath);

                // Verify required backup module configuration exists (converted to components.backup)
                expect($config->get('components.backup.output_dir'))->not->toBeNull();
                expect($config->get('components.backup.registry_file'))->not->toBeNull();
                expect($config->get('components.backup.retention_threshold'))->not->toBeNull();
                expect($config->get('components.backup.backup_threshold'))->not->toBeNull();
                expect($config->get('components.backup.site_salt'))->not->toBeNull();
                expect($config->get('components.backup.saltit'))->not->toBeNull();
                expect($config->get('components.backup.classpath'))->not->toBeNull();
                expect($config->get('components.backup.http_post'))->not->toBeNull();

                // Verify types are correct
                expect($config->get('components.backup.retention_threshold'))->toBeA('integer');
                expect($config->get('components.backup.backup_threshold'))->toBeA('double');
                expect($config->get('components.backup.http_post'))->toBeA('boolean');
                expect($config->get('components.backup.output_dir'))->toBeA('string');
                expect($config->get('mod.backup.registry_file'))->toBeA('string');
            });
        });

    });

    describe('Real Configuration Verification', function() {

        xit('should verify real config.php file exists and is valid', function() {
            $configPath = __DIR__ . '/../../../config.php';
            expect(file_exists($configPath))->toBe(true);

            // Reset singleton and initialize with config file
            Configuration::reset();
            $config = Configuration::initialize(['config_path' => $configPath]);

            // Test that essential backup configuration is present (under components.backup)
            expect($config->get('components.backup.output_dir'))->not->toBeEmpty();
            expect($config->get('components.backup.registry_file'))->not->toBeEmpty();
            expect($config->get('components.backup.site_salt'))->not->toBeEmpty();
            expect($config->get('components.backup.classpath'))->not->toBeEmpty();
            expect($config->get('components.backup.http_post'))->toBe(true);
        });

        it('should verify app name configuration override', function() {
            // Reset singleton and initialize with config file
            Configuration::reset();
            $config = Configuration::initialize(['config_path' => __DIR__ . '/../../../config.php']);

            // Test default app name
            expect($config->get('app.name', 'Symbiota Portal Helpers'))->toBe('Symbiota Portal Helpers');

            // Test with custom app name in config
            $config->set('app.name', 'Custom Portal Name');
            expect($config->get('app.name'))->toBe('Custom Portal Name');
        });

        xit('should verify testing mode configuration', function() {
            // Reset singleton and initialize with config file
            Configuration::reset();
            $config = Configuration::initialize(['config_path' => __DIR__ . '/../../../config.php']);

            // Check if testing_symbiota_uid is configured
            $testingUid = $config->get('app.debug.testing_symbiota_uid');
            expect($testingUid)->toBe(525);
        });
    });
});
