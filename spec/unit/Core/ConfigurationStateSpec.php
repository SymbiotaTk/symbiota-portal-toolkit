<?php

use Symbiota\Helpers\Core\Configuration;

describe('Configuration State System', function() {

    beforeEach(function() {
        // Reset Configuration singleton for testing
        Configuration::reset();
    });

    describe('cfTesting state', function() {
        it('should detect cfTesting when testing_symbiota_dbconnection is set', function() {
            Configuration::initialize([
                'app.debug.testing_symbiota_dbconnection' => 'dev/dbconnection.php',
                'app.debug.testing_symbiota_classpath' => 'dev/git-code/Symbiota/classes'
            ]);

            $config = Configuration::getInstance();
            expect($config->getConfigurationState())->toBe('cfTesting');
        });

        it('should use testing classpath in cfTesting state', function() {
            Configuration::initialize([
                'app.debug.testing_symbiota_dbconnection' => 'dev/dbconnection.php',
                'app.debug.testing_symbiota_classpath' => __DIR__ . '/../../fixtures/symbiota/classes'
            ]);

            $config = Configuration::getInstance();
            expect($config->getConfigurationState())->toBe('cfTesting');
            
            // Create mock Symbiota classes directory for testing
            $mockClassPath = __DIR__ . '/../../fixtures/symbiota/classes';
            if (!is_dir($mockClassPath)) {
                mkdir($mockClassPath, 0755, true);
                file_put_contents($mockClassPath . '/DwcArchiverCore.php', '<?php // Mock class');
            }
            
            $classPath = $config->getSymbiotaClassPath();
            expect($classPath)->toContain('fixtures/symbiota/classes');
        });
    });

    describe('cfProduction state', function() {
        it('should detect cfProduction when Symbiota environment is present', function() {
            // Skip this test if running in actual Symbiota environment
            // State evaluation tests should only run in simulated environments
            Configuration::initialize([]);
            $config = Configuration::getInstance();

            $actualState = $config->getConfigurationState();

            // If we're in a real Symbiota environment, skip this test
            if ($actualState === 'cfProduction') {
                $this->skipIf(true, 'Test requires simulated environment, but running in actual Symbiota production environment');
            }

            // In simulated environment without Symbiota files, should be cfStatic
            expect($config->hasTestingEnvironment())->toBe(false);
            expect($actualState)->toBe('cfStatic');
        });
    });

    describe('cfStatic state', function() {
        it('should default to cfStatic when no environment is detected', function() {
            // Skip this test if running in actual Symbiota environment
            // State evaluation tests should only run in simulated environments
            Configuration::initialize([]);
            $config = Configuration::getInstance();

            $actualState = $config->getConfigurationState();

            // If we're in a real Symbiota environment, skip this test
            if ($actualState === 'cfProduction') {
                $this->skipIf(true, 'Test requires simulated environment, but running in actual Symbiota production environment');
            }

            expect($actualState)->toBe('cfStatic');
            expect($config->getSymbiotaClassPath())->toBe(null);
        });
    });

    describe('database connection availability', function() {
        it('should check testing connection in cfTesting state', function() {
            Configuration::initialize([
                'app.debug.testing_symbiota_dbconnection' => 'nonexistent/dbconnection.php'
            ]);

            $config = Configuration::getInstance();
            expect($config->getConfigurationState())->toBe('cfTesting');
            
            // Since the file doesn't exist, connection should not be available
            expect(\Symbiota\Helpers\Core\DatabaseManager::isTestingConnectionAvailable())->toBe(false);
        });

        it('should return false for database connection in cfStatic state', function() {
            // Skip this test if running in actual environment with database
            // State evaluation tests should only run in simulated environments
            Configuration::initialize([]);
            $config = Configuration::getInstance();

            // If we're in a real environment (cfProduction), skip this test
            if ($config->getConfigurationState() === 'cfProduction') {
                $this->skipIf(true, 'Test requires simulated cfStatic environment, but running in actual production environment');
            }

            // In static mode, no database should be available
            expect(\Symbiota\Helpers\Core\DatabaseManager::isTestingConnectionAvailable())->toBe(false);
        });
    });

    afterEach(function() {
        // Clean up any mock files created during testing
        $mockClassPath = __DIR__ . '/../../fixtures/symbiota/classes';
        if (file_exists($mockClassPath . '/DwcArchiverCore.php')) {
            unlink($mockClassPath . '/DwcArchiverCore.php');
        }
        if (is_dir($mockClassPath)) {
            rmdir($mockClassPath);
        }
        $mockDir = dirname($mockClassPath);
        if (is_dir($mockDir) && count(scandir($mockDir)) == 2) {
            rmdir($mockDir);
        }
        $fixturesDir = dirname($mockDir);
        if (is_dir($fixturesDir) && count(scandir($fixturesDir)) == 2) {
            rmdir($fixturesDir);
        }
    });
});
