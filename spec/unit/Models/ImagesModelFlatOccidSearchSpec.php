<?php
/**
 * Kahlan spec for ImagesModelFlat occid search functionality
 *
 * Tests:
 * - Single occid search
 * - Multiple occid search (comma-separated)
 * - Occid search with additional filters
 * - Performance verification
 *
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */

use Symbiota\Helpers\Models\ImagesModelFlat;

describe('ImagesModelFlat Occid Search', function() {

    beforeAll(function() {
        // Use the fixture flat index for testing (NOT production data)
        $this->flatIndexPath = __DIR__ . '/../../fixtures/images/flat/fixtures.db';

        if (!file_exists($this->flatIndexPath)) {
            throw new Exception("Flat index fixture not found at {$this->flatIndexPath}. Run: php index.php images cache --build --sourcedb=spec/fixtures/images/eav/fixtures.db --output-file=spec/fixtures/images/flat/fixtures.db");
        }

        $this->model = new ImagesModelFlat([
            'db_connection' => [
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'symbiota_myco',
                'username' => 'root',
                'password' => 'password'
            ]
        ]);

        // Get sample occids from fixture for testing
        $db = new PDO("sqlite:{$this->flatIndexPath}");
        $this->sampleOccids = $db->query("SELECT DISTINCT occid FROM omoccurrences ORDER BY occid LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $this->sampleGenera = $db->query("SELECT DISTINCT genus FROM omoccurrences WHERE genus IS NOT NULL ORDER BY genus LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);

        // Get occids for a specific genus (for filter testing)
        // Find a genus that has multiple occids
        $genusWithMultipleOccids = $db->query("
            SELECT genus
            FROM omoccurrences
            WHERE genus IS NOT NULL
            GROUP BY genus
            HAVING COUNT(*) >= 2
            ORDER BY COUNT(*) DESC
            LIMIT 1
        ")->fetchColumn();

        $this->testGenus = $genusWithMultipleOccids;
        $this->testGenusOccids = $db->query("
            SELECT occid
            FROM omoccurrences
            WHERE genus = '{$genusWithMultipleOccids}'
            LIMIT 3
        ")->fetchAll(PDO::FETCH_COLUMN);
    });

    describe('Single occid search', function() {
        it('should return exact matches only (not partial matches)', function() {
            // Use first sample occid from fixture
            $testOccid = $this->sampleOccids[0];

            $result = $this->model->search([
                'query' => "occid:{$testOccid}",
                'limit' => 10,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');

            // Extract occids from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+/m', $result['content'], $matches);
            $occids = $matches[1] ?? [];

            // Should have results
            expect(count($occids))->toBeGreaterThan(0);

            // All results should have exact occid match
            foreach ($occids as $occid) {
                expect($occid)->toBe((string)$testOccid);
            }
        });
    });

    describe('Multiple occid search', function() {
        it('should support comma-separated occid list', function() {
            // Use first 3 sample occids from fixture
            $testOccids = array_slice($this->sampleOccids, 0, 3);
            $occidList = implode(',', $testOccids);

            $result = $this->model->search([
                'query' => "occid:{$occidList}",
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');

            // Extract occids from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+/m', $result['content'], $matches);
            $occids = $matches[1] ?? [];

            // Should have results
            expect(count($occids))->toBeGreaterThan(0);

            // All results should have occid in the list
            $validOccids = array_map('strval', $testOccids);
            foreach ($occids as $occid) {
                expect(in_array($occid, $validOccids))->toBe(true);
            }
        });
    });

    describe('Occid search with filters', function() {
        it('should apply additional filters correctly (LIKE with wildcards)', function() {
            // Use pre-selected occids that all have the same genus
            $testOccids = $this->testGenusOccids;
            $testGenus = $this->testGenus;
            $occidList = implode(',', $testOccids);

            // Search for specific occids but filter by genus
            // Note: genus uses LIKE "%value%" which matches partial strings
            $result = $this->model->search([
                'query' => "occid:{$occidList}",
                'queryAnd' => ["genus:{$testGenus}"],
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');

            // Extract occids and genus from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+\S+\s+(\S+)/m', $result['content'], $matches);
            $occids = $matches[1] ?? [];
            $genera = $matches[2] ?? [];

            // Should have results (all occids should match the genus filter)
            expect(count($occids))->toBeGreaterThan(0);

            // All returned occids should be in the test set (may have multiple media per occid)
            $testOccidsStr = array_map('strval', $testOccids);
            foreach ($occids as $occid) {
                expect(in_array($occid, $testOccidsStr))->toBe(true);
            }

            // All results should have genus containing the test genus (LIKE behavior, case-insensitive)
            foreach ($genera as $genus) {
                // Genus might be truncated in CLI output (e.g., "Albatrellus..." instead of "Albatrellus")
                // Use case-insensitive search (stripos) since SQL LIKE is case-insensitive with COLLATE NOCASE
                $containsGenus = (stripos($genus, strtolower($testGenus)) !== false) || (substr($genus, -3) === '...');
                expect($containsGenus)->toBe(true);
            }
        });
    });

    describe('Performance', function() {
        it('should execute occid search quickly (< 1 second for fixture)', function() {
            // Use first sample occid from fixture
            $testOccid = $this->sampleOccids[0];

            $start = microtime(true);

            $result = $this->model->search([
                'query' => "occid:{$testOccid}",
                'limit' => 10,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            $duration = microtime(true) - $start;

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('cli');
            expect($duration)->toBeLessThan(1.0); // Fixture is small, should be very fast
        });
    });

});
