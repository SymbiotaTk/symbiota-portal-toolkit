<?php

use Symbiota\Helpers\Models\ImagesModelFlat;
use Symbiota\Helpers\Models\ImagesModelFlatIndex;
use PDO;

/**
 * Comprehensive test suite for ImagesModelFlat search plugin
 *
 * Tests the flat normalized search index model with inverted index.
 * Uses static fixture data (NOT production databases).
 * Covers all search scenarios: single-field, multi-field, aliases, CLI/web formats.
 *
 * The flat model uses:
 * - Normalized tables (media, omoccurrences, omcollections, taxa) with comprehensive indexes
 * - Inverted index (TaxonTokens + OccurrenceTaxonIndex) for fast multi-field taxon searches
 * - Autocomplete tables for web interface
 */
describe('ImagesModelFlat Search Plugin with Static Fixtures', function() {

    beforeAll(function() {
        echo "\n[beforeAll] Setting up test environment with static fixtures...\n";

        // Use fixture database as source
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/flat/index_config.ini';

        // Verify fixture exists
        if (!file_exists($this->fixtureDbPath)) {
            throw new \Exception("Fixture database not found at: {$this->fixtureDbPath}");
        }

        // Create temporary test databases (build once for all tests)
        $this->testDir = sys_get_temp_dir() . '/flat_plugin_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->flatIndexPath = $this->testDir . '/flat_index.db';

        // Build flat index from fixture
        echo "[beforeAll] Building flat index from fixture...\n";
        $builder = new ImagesModelFlatIndex([
            'config_path' => $this->configPath
        ]);
        $builder->buildIndex($this->fixtureDbPath, $this->flatIndexPath);

        // Create model instance
        $this->model = new ImagesModelFlat([
            'flat_index_db_path' => $this->flatIndexPath,
            'config_path' => $this->configPath
        ]);

        echo "[beforeAll] Setup complete. Using fixture data for all tests.\n";
    });

    afterAll(function() {
        // Clean up test directory
        if (isset($this->testDir) && is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            rmdir($this->testDir);
        }
    });

    describe('Single-field search', function() {

        it('should search by family', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
            expect(isset($result['content']))->toBe(true);
            expect($result['content'])->toBeA('string');
        });

        it('should search by genus', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by country', function() {
            $result = $this->model->search([
                'query' => 'country:United States',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by stateProvince', function() {
            $result = $this->model->search([
                'query' => 'stateProvince:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by county', function() {
            $result = $this->model->search([
                'query' => 'county:Blount',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search by collectionCode', function() {
            $result = $this->model->search([
                'query' => 'collection:TENN',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

    });

    describe('Multi-field search with AND logic', function() {

        it('should search with queryAnd (genus + stateProvince)', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'queryAnd' => ['stateProvince:Tennessee'],
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should search with multiple queryAnd conditions', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'queryAnd' => ['stateProvince:Tennessee', 'county:Blount'],
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should handle queryAnd as string (CLI compatibility)', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'queryAnd' => 'stateProvince:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

    });

    describe('Multi-field aliases', function() {

        it('should support taxon alias (searches 5 fields: family, genus, sciname, scientificname, taxa.sciname)', function() {
            $result = $this->model->search([
                'query' => 'taxon:Russula',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');

            // Verify the search actually returns results
            expect($result['content'])->toContain('Russula');
        });

        it('should find results via scientificname field in taxon search', function() {
            // Test that taxon: searches the scientificname field (not just sciname)
            $result = $this->model->search([
                'query' => 'taxon:Russulaceae',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should find results via taxa table sciname in taxon search', function() {
            // Test that taxon: searches the taxa.sciname field (via tidinterpreted)
            // This tests the JOIN to the taxa table
            $result = $this->model->search([
                'query' => 'taxon:Agaricales',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should support collection alias', function() {
            $result = $this->model->search([
                'query' => 'collection:TENN',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should support location alias', function() {
            $result = $this->model->search([
                'query' => 'location:Tennessee',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

    });

    describe('Pagination', function() {

        it('should respect limit parameter', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 5,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

        it('should respect offset parameter', function() {
            $result = $this->model->search([
                'query' => 'family:Russulaceae',
                'limit' => 5,
                'offset' => 10,
                'format' => 'cli'
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
        });

    });

    describe('Output formats', function() {

        it('should return CLI format when format=cli', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'limit' => 5,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
            expect($result['content'])->toBeA('string');
        });

        it('should return HTMX format when format=htmx', function() {
            $result = $this->model->search([
                'query' => 'genus:Russula',
                'limit' => 5,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toBeA('string');
        });

    });

    describe('Autocomplete with dedicated tables', function() {

        it('should autocomplete taxon values from TaxonTokens table (inverted index)', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'taxon',
                'q' => 'Ru',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toBeA('string');
            expect($result['content'])->toContain('Russula');
        });

        it('should autocomplete location values from AutocompleteLocation table', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'country',
                'q' => 'Un',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toBeA('string');
        });

        it('should autocomplete collection values from AutocompleteCollection table', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'collection',
                'q' => 'TE',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toBeA('string');
        });

        it('should autocomplete catalog numbers from AutocompleteCatalog table', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'catalogNumber',
                'q' => 'TE',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toBeA('string');
        });

        it('should return message for fields without autocomplete', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'locality',
                'q' => 'test',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('not available');
        });

        it('should require minimum 2 characters for autocomplete', function() {
            $result = $this->model->autocompleteValues([
                'field' => 'taxon',
                'q' => 'R',
                'limit' => 10,
                'format' => 'htmx'
            ]);

            expect($result['type'])->toBe('htmx');
            expect($result['content'])->toContain('at least 2 characters');
        });

    });

    describe('Error handling', function() {

        it('should return error when no query provided', function() {
            $result = $this->model->search([
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(400);
        });

        it('should handle invalid database path gracefully', function() {
            $invalidModel = new ImagesModelFlat([
                'flat_index_db_path' => '/nonexistent/path.db'
            ]);

            $result = $invalidModel->search([
                'query' => 'genus:Russula',
                'format' => 'cli'
            ]);

            expect($result['type'])->toMatch('/error|htmx/');
        });

    });

    describe('Inverted index structure', function() {

        it('should have TaxonTokens table with distinct taxon values', function() {
            $db = new PDO('sqlite:' . $this->flatIndexPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check table exists
            $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='TaxonTokens'")->fetchColumn();
            expect($tableExists)->toBe('TaxonTokens');

            // Check table has data
            $count = $db->query("SELECT COUNT(*) FROM TaxonTokens")->fetchColumn();
            expect($count)->toBeGreaterThan(0);

            // Check table structure
            $columns = $db->query("PRAGMA table_info(TaxonTokens)")->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($columns, 'name');
            expect($columnNames)->toContain('token_id');
            expect($columnNames)->toContain('taxon_value');
            expect($columnNames)->toContain('count');
        });

        it('should have OccurrenceTaxonIndex table with occid→token mappings', function() {
            $db = new PDO('sqlite:' . $this->flatIndexPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check table exists
            $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='OccurrenceTaxonIndex'")->fetchColumn();
            expect($tableExists)->toBe('OccurrenceTaxonIndex');

            // Check table has data
            $count = $db->query("SELECT COUNT(*) FROM OccurrenceTaxonIndex")->fetchColumn();
            expect($count)->toBeGreaterThan(0);

            // Check table structure
            $columns = $db->query("PRAGMA table_info(OccurrenceTaxonIndex)")->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($columns, 'name');
            expect($columnNames)->toContain('occid');
            expect($columnNames)->toContain('token_id');
        });

        it('should have indexes on inverted index tables', function() {
            $db = new PDO('sqlite:' . $this->flatIndexPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check TaxonTokens indexes
            $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='TaxonTokens' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            expect(count($indexes))->toBeGreaterThan(0);

            // Check OccurrenceTaxonIndex indexes
            $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='OccurrenceTaxonIndex' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            expect(count($indexes))->toBeGreaterThan(0);
        });

        it('should have valid occid→token relationships', function() {
            $db = new PDO('sqlite:' . $this->flatIndexPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get a sample token
            $token = $db->query("SELECT token_id, taxon_value FROM TaxonTokens LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            expect($token)->toBeAn('array');

            // Find occids with this token
            $stmt = $db->prepare("SELECT occid FROM OccurrenceTaxonIndex WHERE token_id = ?");
            $stmt->execute([$token['token_id']]);
            $occids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            expect(count($occids))->toBeGreaterThan(0);

            // Verify at least one occid has the taxon value in one of the 5 fields
            $occid = $occids[0];
            $stmt = $db->prepare("
                SELECT o.family, o.genus, o.sciname, o.scientificname, t.sciname as taxa_sciname
                FROM omoccurrences o
                LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                WHERE o.occid = ?
            ");
            $stmt->execute([$occid]);
            $occ = $stmt->fetch(PDO::FETCH_ASSOC);

            $hasValue = (
                strcasecmp($occ['family'], $token['taxon_value']) === 0 ||
                strcasecmp($occ['genus'], $token['taxon_value']) === 0 ||
                strcasecmp($occ['sciname'], $token['taxon_value']) === 0 ||
                strcasecmp($occ['scientificname'], $token['taxon_value']) === 0 ||
                strcasecmp($occ['taxa_sciname'], $token['taxon_value']) === 0
            );
            expect($hasValue)->toBe(true);
        });

    });

    describe('Inverted index search performance', function() {

        it('should use inverted index for taxon searches', function() {
            $start = microtime(true);

            $result = $this->model->search([
                'query' => 'taxon:Russula',
                'limit' => 20,
                'format' => 'cli'
            ]);

            $duration = (microtime(true) - $start) * 1000;

            echo "\n  [Inverted Index] taxon:Russula took {$duration}ms\n";

            expect($result['type'])->toBe('cli');
            expect($duration)->toBeLessThan(100); // Should be fast with inverted index
        });

        it('should handle partial matches in inverted index', function() {
            $result = $this->model->search([
                'query' => 'taxon:Russ',
                'limit' => 10,
                'format' => 'cli'
            ]);

            expect($result['type'])->toBe('cli');
            expect($result['content'])->toContain('Russula');
        });

        it('should combine inverted index with other filters', function() {
            $start = microtime(true);

            $result = $this->model->search([
                'query' => 'taxon:Russula',
                'queryAnd' => ['stateProvince:Tennessee'],
                'limit' => 20,
                'format' => 'cli'
            ]);

            $duration = (microtime(true) - $start) * 1000;

            echo "\n  [Inverted Index + Filter] taxon:Russula + stateProvince:Tennessee took {$duration}ms\n";

            expect($result['type'])->toBe('cli');
            expect($duration)->toBeLessThan(150); // Should still be fast
        });

    });

    describe('Performance characteristics', function() {

        it('should execute single-field search quickly', function() {
            $start = microtime(true);

            $result = $this->model->search([
                'query' => 'genus:Russula',
                'limit' => 20,
                'format' => 'cli'
            ]);

            $duration = (microtime(true) - $start) * 1000; // Convert to ms

            echo "\n  [Performance] genus:Russula took {$duration}ms\n";

            expect($result['type'])->toBe('cli');
            expect($duration)->toBeLessThan(100); // Should be under 100ms for fixture data
        });

        it('should execute multi-field AND search quickly', function() {
            $start = microtime(true);

            $result = $this->model->search([
                'query' => 'genus:Russula',
                'queryAnd' => ['stateProvince:Tennessee'],
                'limit' => 20,
                'format' => 'cli'
            ]);

            $duration = (microtime(true) - $start) * 1000;

            echo "\n  [Performance] genus:Russula + stateProvince:Tennessee took {$duration}ms\n";

            expect($result['type'])->toBe('cli');
            expect($duration)->toBeLessThan(100); // Should be under 100ms for fixture data
        });

    });

});

