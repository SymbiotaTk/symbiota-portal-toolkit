<?php

/**
 * Query Parity Tests for ImagesModelFlat
 *
 * Verifies that CLI and web query parsing produce identical search results.
 * This ensures that the same query parameters work consistently across both interfaces.
 *
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */

use Symbiota\Helpers\Models\ImagesModelFlat;

describe('ImagesModelFlat Query Parity (CLI vs Web)', function() {

    beforeAll(function() {
        // Use the production flat index for testing
        $this->flatIndexPath = '/var/www/temp/myco/data/images_flat_index.db';

        if (!file_exists($this->flatIndexPath)) {
            throw new Exception("Flat index not found at {$this->flatIndexPath}. Run cache-build-flat first.");
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
    });

    describe('Single filter queries', function() {

        it('should return identical results for CLI and web format (taxon search)', function() {
            $cliResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            $webResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'limit' => 20,
                'format' => 'web',
                'db-path' => $this->flatIndexPath
            ]);

            expect($cliResult['type'])->toBe('cli');
            expect($webResult['type'])->toBe('htmx');

            // Extract occids from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+/m', $cliResult['content'], $cliMatches);
            $cliOccids = $cliMatches[1] ?? [];

            // Extract occids from web HTML output
            preg_match_all('/data-occid="(\d+)"/', $webResult['content'], $webMatches);
            $webOccids = $webMatches[1] ?? [];

            // Both should return same number of results
            expect(count($cliOccids))->toBe(count($webOccids));

            // Both should return same occids in same order
            expect($cliOccids)->toBe($webOccids);
        });

    });

    describe('Multiple filter queries (queryAnd)', function() {

        it('should return identical results for CLI and web format (taxon + collection)', function() {
            $cliResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY'],
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            $webResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY'],
                'limit' => 20,
                'format' => 'web',
                'db-path' => $this->flatIndexPath
            ]);

            expect($cliResult['type'])->toBe('cli');
            expect($webResult['type'])->toBe('htmx');

            // Extract occids from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+/m', $cliResult['content'], $cliMatches);
            $cliOccids = $cliMatches[1] ?? [];

            // Extract occids from web HTML output
            preg_match_all('/data-occid="(\d+)"/', $webResult['content'], $webMatches);
            $webOccids = $webMatches[1] ?? [];

            // Both should return same number of results
            expect(count($cliOccids))->toBe(count($webOccids));

            // Both should return same occids in same order
            expect($cliOccids)->toBe($webOccids);
        });

        it('should return identical results for CLI and web format (multiple queryAnd)', function() {
            $cliResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY', 'country:United States'],
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);

            $webResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY', 'country:United States'],
                'limit' => 20,
                'format' => 'web',
                'db-path' => $this->flatIndexPath
            ]);

            expect($cliResult['type'])->toBe('cli');
            expect($webResult['type'])->toBe('htmx');

            // Extract occids from CLI output
            preg_match_all('/^\s*\d+\s+(\d+)\s+/m', $cliResult['content'], $cliMatches);
            $cliOccids = $cliMatches[1] ?? [];

            // Extract occids from web HTML output
            preg_match_all('/data-occid="(\d+)"/', $webResult['content'], $webMatches);
            $webOccids = $webMatches[1] ?? [];

            // Both should return same number of results
            expect(count($cliOccids))->toBe(count($webOccids));

            // Both should return same occids in same order
            expect($cliOccids)->toBe($webOccids);
        });

    });

    describe('Performance parity', function() {

        it('should execute CLI and web searches in similar time', function() {
            $cliStart = microtime(true);
            $cliResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY'],
                'limit' => 20,
                'format' => 'cli',
                'db-path' => $this->flatIndexPath
            ]);
            $cliDuration = (microtime(true) - $cliStart) * 1000;

            $webStart = microtime(true);
            $webResult = $this->model->search([
                'query' => 'taxon:Boletus',
                'queryAnd' => ['institutionCode:NY'],
                'limit' => 20,
                'format' => 'web',
                'db-path' => $this->flatIndexPath
            ]);
            $webDuration = (microtime(true) - $webStart) * 1000;

            echo "\n  [CLI] taxon:Boletus + institutionCode:NY took {$cliDuration}ms\n";
            echo "  [Web] taxon:Boletus + institutionCode:NY took {$webDuration}ms\n";

            // Both should be fast (under 2 seconds)
            expect($cliDuration)->toBeLessThan(2000);
            expect($webDuration)->toBeLessThan(2000);

            // Web should not be significantly slower than CLI (within 2x)
            $ratio = $webDuration / $cliDuration;
            echo "  [Ratio] Web/CLI = {$ratio}x\n";
            expect($ratio)->toBeLessThan(2.0);
        });

    });

});
