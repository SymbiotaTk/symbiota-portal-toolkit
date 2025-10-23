<?php

use Symbiota\Helpers\Models\ImagesModelHybridIndex;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\Environment;

describe('ImagesModelHybridIndex - InstitutionCode Search', function () {
    beforeAll(function () {
        // Use shared test database
        $this->testDbPath = __DIR__ . '/../../fixtures/images/eav/test_hybrid_index.db';
        $this->fixtureDbPath = __DIR__ . '/../../fixtures/images/eav/fixtures.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/index_config.ini';

        // Always rebuild to ensure fresh data
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }

        $builder = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->testDbPath
        ]);

        $builder->buildIndex($this->fixtureDbPath);
    });

    beforeEach(function () {
        $this->builder = new ImagesModelHybridIndex([
            'config_path' => $this->configPath,
            'hybrid_index_db_path' => $this->testDbPath
        ]);
    });

    it('should have institutionCode indexed in ValuesText', function () {
        // Verify that institutionCode values are actually in the index
        $db = new PDO('sqlite:' . $this->testDbPath);

        // Check Attributes table (standardized schema uses ColumnName)
        $stmt = $db->prepare("SELECT Aid FROM Attributes WHERE ColumnName = 'institutionCode'");
        $stmt->execute();
        $aid = $stmt->fetchColumn();

        if (Environment::isCli()) {
            echo "\nInstitutionCode Aid: " . ($aid ?: 'NOT FOUND') . "\n";
        }

        expect($aid)->toBeTruthy();

        // Check ValuesText for institutionCode values
        $stmt = $db->prepare("
            SELECT DISTINCT v.ValueText
            FROM ValuesText v
            JOIN EAV e ON e.Vid = v.Vid
            JOIN Attributes a ON e.Aid = a.Aid
            WHERE a.ColumnName = 'institutionCode'
            ORDER BY v.ValueText
        ");
        $stmt->execute();
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (Environment::isCli()) {
            echo "InstitutionCode values in index: " . implode(', ', $values) . "\n";
        }

        expect(count($values))->toBeGreaterThan(0);
        expect(in_array('dbg', $values))->toBe(true); // Should be lowercase
    });

    it('should use prefix match for institutionCode (like EAV)', function () {
        // Fixture has institutionCode "DBG"
        // Searching for "DB" SHOULD match "DBG" (prefix match)
        // Searching for "DBG" should match "DBG"
        // Searching for "BG" should NOT match "DBG" (not a prefix)

        // Get all Amanita records
        $allAmanita = $this->builder->searchIndexedFields([
            'genus' => 'Amanita'
        ]);

        // Search for Amanita + "DB" (prefix - SHOULD match "DBG")
        $resultsPrefix = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'DB'
        ]);

        // Search for Amanita + "DBG" (exact - should match)
        $resultsExact = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'DBG'
        ]);

        // Search for Amanita + "BG" (not a prefix - should NOT match)
        $resultsNotPrefix = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'BG'
        ]);

        // Log results for debugging
        if (Environment::isCli()) {
            echo "\nAll Amanita: " . count($allAmanita) . " occids\n";
            echo "Amanita + 'DB' (prefix): " . count($resultsPrefix) . " occids\n";
            echo "Amanita + 'DBG' (exact): " . count($resultsExact) . " occids\n";
            echo "Amanita + 'BG' (not prefix): " . count($resultsNotPrefix) . " occids\n";
        }

        // Prefix match should return results (matches "DBG")
        expect(count($resultsPrefix))->toBeGreaterThan(0);

        // Exact match should return same results as prefix
        expect(count($resultsExact))->toBe(count($resultsPrefix));

        // Non-prefix should return ZERO results
        expect(count($resultsNotPrefix))->toBe(0);

        // Results should be subset of all Amanita
        expect(count($resultsExact) <= count($allAmanita))->toBe(true);
    });

    it('should handle case-insensitive institutionCode search', function () {
        $resultsLower = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'dbg'
        ]);

        $resultsUpper = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'DBG'
        ]);

        $resultsMixed = $this->builder->searchIndexedFields([
            'genus' => 'Amanita',
            'institutionCode' => 'DbG'
        ]);

        // All should return the same results (case-insensitive)
        expect($resultsLower)->toBe($resultsUpper);
        expect($resultsLower)->toBe($resultsMixed);

        // Should have results
        expect(count($resultsLower))->toBeGreaterThan(0);
    });

    it('should use prefix match for sciname (not exact match)', function () {
        // Sciname should support prefix matching
        // "Aman" should match "Amanita fulva", "Amanita muscaria", etc.

        $resultsPrefix = $this->builder->searchIndexedFields([
            'sciname' => 'Aman'
        ]);

        $resultsExact = $this->builder->searchIndexedFields([
            'sciname' => 'Amanita fulva'
        ]);

        // Log results
        if (Environment::isCli()) {
            echo "\nSciname 'Aman' (prefix): " . count($resultsPrefix) . " occids\n";
            echo "Sciname 'Amanita fulva' (exact): " . count($resultsExact) . " occids\n";
        }

        // Prefix should match multiple species
        expect(count($resultsPrefix))->toBeGreaterThan(count($resultsExact));

        // Both should have results
        expect(count($resultsPrefix))->toBeGreaterThan(0);
        expect(count($resultsExact))->toBeGreaterThan(0);
    });
});

