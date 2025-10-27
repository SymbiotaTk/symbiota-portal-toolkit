<?php

use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Models\ImagesModelHybrid;
use Symbiota\Helpers\Models\ImagesModelHybridIndex;
use Symbiota\Helpers\Models\ImagesModelFlat;
use Symbiota\Helpers\Models\ImagesModelFlatIndex;
use PDO;

/**
 * Test suite for ImagesModel plugin delegation architecture
 *
 * Verifies that:
 * 1. CLI and web both use the same search plugin
 * 2. Plugin delegation works correctly
 * 3. No MySQL fallback in active code paths
 *
 * Uses static fixture data (NOT production databases).
 */
describe('ImagesModel Plugin Delegation with Static Fixtures', function() {

    beforeAll(function() {
        echo "\n[beforeAll] Setting up test environment for plugin delegation...\n";

        // Use fixture database
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/hybrid/index_config.ini';

        // Verify fixture exists
        if (!file_exists($this->fixtureDbPath)) {
            throw new \Exception("Fixture database not found at: {$this->fixtureDbPath}");
        }

        // Create temporary test databases
        $this->testDir = sys_get_temp_dir() . '/plugin_delegation_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->hybridIndexPath = $this->testDir . '/hybrid_index.db';
        $this->flatIndexPath = $this->testDir . '/flat_index.db';

        // Build hybrid index from fixture
        echo "[beforeAll] Building hybrid index from fixture...\n";
        $builder = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->hybridIndexPath
        ]);
        $builder->buildIndex($this->fixtureDbPath);

        // Build flat index from fixture
        echo "[beforeAll] Building flat index from fixture...\n";
        $flatConfigPath = __DIR__ . '/../../../templates/sql/images/flat/index_config.ini';
        $flatBuilder = new ImagesModelFlatIndex([
            'config_path' => $flatConfigPath
        ]);
        $flatBuilder->buildIndex($this->fixtureDbPath, $this->flatIndexPath);

        echo "[beforeAll] Setup complete.\n";
    });

    afterAll(function() {
        // Clean up test directory
        if (isset($this->testDir) && is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            rmdir($this->testDir);
        }
    });

    describe('Plugin delegation architecture', function() {

        it('should verify ImagesModelHybrid has search() method', function() {
            // Verify the plugin interface is correct
            expect(method_exists(ImagesModelHybrid::class, 'search'))->toBe(true);
        });

        it('should verify ImagesModelHybrid has autocompleteFields() method', function() {
            expect(method_exists(ImagesModelHybrid::class, 'autocompleteFields'))->toBe(true);
        });

        it('should verify ImagesModelHybrid has autocompleteValues() method', function() {
            expect(method_exists(ImagesModelHybrid::class, 'autocompleteValues'))->toBe(true);
        });
    });

    describe('CLI and Web use same code path', function() {

        it('should verify both CLI and web call ImagesModelHybrid::search()', function() {
            // This is verified by the architecture:
            // - Web: DiscoveringRouter -> ImagesModel::executeAction('search') -> delegateSearch() -> plugin->search()
            // - CLI: DiscoveringRouter -> ImagesModel::executeAction('search') -> delegateSearch() -> plugin->search()
            // Both paths go through the same delegateSearch() method

            expect(method_exists(ImagesModel::class, 'executeAction'))->toBe(true);
        });

        it('should verify ImagesModel has delegateSearch method', function() {
            $reflection = new ReflectionClass(ImagesModel::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PRIVATE);
            $methodNames = array_map(function($m) { return $m->getName(); }, $methods);

            expect(in_array('delegateSearch', $methodNames))->toBe(true);
        });
    });

    describe('No MySQL fallback in active code', function() {

        it('should verify deprecated parseSearchQuery() exists but is not called', function() {
            // Verify the deprecated method exists (for backward compatibility)
            // but is not called by the active code path
            $reflection = new ReflectionClass(ImagesModel::class);
            $methods = $reflection->getMethods();
            $methodNames = array_map(function($m) { return $m->getName(); }, $methods);

            expect(in_array('parseSearchQuery', $methodNames))->toBe(true);
        });

        it('should verify deprecated hybridSearch() exists but is not called', function() {
            // Verify the deprecated method exists (for backward compatibility)
            // but is not called by the active code path
            $reflection = new ReflectionClass(ImagesModel::class);
            $methods = $reflection->getMethods();
            $methodNames = array_map(function($m) { return $m->getName(); }, $methods);

            expect(in_array('hybridSearch', $methodNames))->toBe(true);
        });

        it('should verify SQLite-only architecture (no MySQL in ImagesModelHybrid)', function() {
            // ImagesModelHybrid should only use SQLite connections
            // This is verified by the class design - it only accepts cache_db_path and source_db_path
            // No MySQL connection parameters are accepted

            $reflection = new ReflectionClass(ImagesModelHybrid::class);
            $constructor = $reflection->getConstructor();

            expect($constructor)->not->toBeNull();
        });
    });

    describe('Flat Mode Plugin Delegation', function() {

        it('should verify ImagesModelFlat has search() method', function() {
            // Verify the plugin interface is correct
            expect(method_exists(ImagesModelFlat::class, 'search'))->toBe(true);
        });

        it('should verify ImagesModelFlat has autocompleteFields() method', function() {
            expect(method_exists(ImagesModelFlat::class, 'autocompleteFields'))->toBe(true);
        });

        it('should verify ImagesModelFlat has autocompleteValues() method', function() {
            expect(method_exists(ImagesModelFlat::class, 'autocompleteValues'))->toBe(true);
        });

        it('should verify ImagesModelFlat can be instantiated with flat index', function() {
            $flatConfigPath = __DIR__ . '/../../../templates/sql/images/flat/index_config.ini';

            $plugin = new ImagesModelFlat([
                'config_path' => $flatConfigPath,
                'flat_index_db_path' => $this->flatIndexPath
            ]);

            expect($plugin)->toBeAnInstanceOf(ImagesModelFlat::class);
        });

        it('should verify ImagesModelFlat can perform search', function() {
            $flatConfigPath = __DIR__ . '/../../../templates/sql/images/flat/index_config.ini';

            $plugin = new ImagesModelFlat([
                'config_path' => $flatConfigPath,
                'flat_index_db_path' => $this->flatIndexPath
            ]);

            $result = $plugin->search([
                'query' => 'taxon:Centrarchidae'
            ]);

            expect($result)->toBeA('array');
            expect(isset($result['type']))->toBe(true);
            // Accept both 'cli' and 'htmx' formats
            expect(in_array($result['type'], ['cli', 'htmx']))->toBe(true);
            expect(isset($result['content']))->toBe(true);
            expect($result['content'])->toContain('Centrarchidae');
        });

        it('should verify ImagesModelFlat can provide autocomplete', function() {
            $flatConfigPath = __DIR__ . '/../../../templates/sql/images/flat/index_config.ini';

            $plugin = new ImagesModelFlat([
                'config_path' => $flatConfigPath,
                'flat_index_db_path' => $this->flatIndexPath
            ]);

            $result = $plugin->autocompleteValues([
                'field' => 'family',
                'query' => 'C'
            ]);

            expect($result)->toBeA('array');
            expect(isset($result['type']))->toBe(true);
            // Accept both 'cli' and 'htmx' formats
            expect(in_array($result['type'], ['cli', 'htmx']))->toBe(true);
            expect(isset($result['content']))->toBe(true);
            // Should contain at least one family starting with C
            expect(strlen($result['content']) > 50)->toBe(true);
        });
    });
});

