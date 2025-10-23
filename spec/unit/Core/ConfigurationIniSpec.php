<?php

use Symbiota\Helpers\Core\Configuration;

describe('Configuration INI Format', function() {
    
    beforeEach(function() {
        // Clean up any global variables
        unset($GLOBALS['CONFIG']);
    });
    
    describe('PHP Heredoc INI Configuration', function() {
        
        it('should parse INI heredoc from PHP file', function() {
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[app.debug]
enabled = true

[mod.backup]
http_post = true
registry_file = "test.json"

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            expect($config->get('app.debug.enabled'))->toBe(true);
            expect($config->get('components.backup.http_post'))->toBe(true);
            expect($config->get('components.backup.registry_file'))->toBe('test.json');
            
            unlink($phpFile);
        });
        
        it('should process environment variable substitutions', function() {
            $_ENV['TEST_DEBUG'] = 'true';
            
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[app.debug]
enabled = {{ _ENV['TEST_DEBUG'] }}

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            expect($config->get('app.debug.enabled'))->toBe(true);
            
            unset($_ENV['TEST_DEBUG']);
            unlink($phpFile);
        });
        
    });
    
    describe('Namespace Prefix Processing', function() {
        
        it('should handle app.* namespaces', function() {
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[app.database]
host = "localhost"
username = "test"

[app.security]
require_authentication = true

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            expect($config->get('app.database.host'))->toBe('localhost');
            expect($config->get('app.database.username'))->toBe('test');
            expect($config->get('app.security.require_authentication'))->toBe(true);
            
            unlink($phpFile);
        });
        
        it('should handle mod.* namespaces and map to components', function() {
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[mod.backup]
http_post = true
registry_file = "backup.json"
site_salt = "test_salt"

[mod.genbank]
max_records = 1000

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            expect($config->get('components.backup.http_post'))->toBe(true);
            expect($config->get('components.backup.registry_file'))->toBe('backup.json');
            expect($config->get('components.backup.site_salt'))->toBe('test_salt');
            expect($config->get('components.genbank.max_records'))->toBe(1000);
            
            unlink($phpFile);
        });
        
        it('should handle disable attribute for modules', function() {
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[mod.backup]
disable = true
http_post = true

[mod.upload]
disable = false
registry_file = "upload.json"

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            expect($config->getDisabledHttpComponents())->toContain('backup');
            expect($config->getDisabledHttpComponents())->not->toContain('upload');
            expect($config->get('components.backup.http_post'))->toBe(true);
            expect($config->get('components.upload.registry_file'))->toBe('upload.json');
            
            unlink($phpFile);
        });
        
    });
    
    describe('Legacy INI Support', function() {
        
        it('should handle legacy sections without namespace', function() {
            $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
            $phpFile = $tempFile . '.php';
            
            $content = <<<'PHP'
<?php
$CONFIG = <<<INI

[backup]
data_file = "legacy.json"
site_salt = "legacy_salt"

[database]
host = "localhost"

INI;
PHP;
            
            file_put_contents($phpFile, $content);
            
            $config = new Configuration($phpFile);
            
            // Legacy data_file should be renamed to registry_file
            expect($config->get('components.backup.registry_file'))->toBe('legacy.json');
            expect($config->get('components.backup.site_salt'))->toBe('legacy_salt');
            expect($config->get('database.host'))->toBe('localhost');
            
            unlink($phpFile);
        });
        
    });
    
    describe('Configuration Validation', function() {
        
        it('should support http_post configuration for backup', function() {
            $config = new Configuration([
                'components' => [
                    'backup' => [
                        'http_post' => true,
                        'registry_file' => 'test.json'
                    ]
                ]
            ]);
            
            expect($config->get('components.backup.http_post'))->toBe(true);
            expect($config->get('components.backup.registry_file'))->toBe('test.json');
        });
        
        it('should default http_post to false', function() {
            $config = new Configuration([
                'components' => [
                    'backup' => [
                        'enabled' => true
                    ]
                ]
            ]);

            expect($config->get('components.backup.http_post'))->toBe(false);
        });

        it('should parse the actual config.php file correctly', function() {
            $config = new Configuration('config.php');

            // Test that the configuration loads successfully
            expect($config->get('app.name'))->toBe('Symbiota Portal Toolkit');
            expect($config->get('app.version'))->toBe('2.0.0');

            // Test backup module configuration exists and has valid structure
            // Don't test specific values as they vary between environments
            expect($config->get('components.backup.http_post'))->toBeA('boolean');
            expect($config->get('components.backup.registry_file'))->toBeA('string');
            expect($config->get('components.backup.output_dir'))->toBeA('string');

            // Test upload module configuration exists and has valid structure
            expect($config->get('components.upload.output_dir'))->toBeA('string');
            expect($config->get('components.upload.registry_file'))->toBeA('string');
        });

    });
    
});
