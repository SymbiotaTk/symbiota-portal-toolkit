<?php

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Core\Configuration;

describe('ImagesModelEav Pure SQL Subset', function() {

    beforeAll(function() {
        // Use 10-record fixtures instead of live data
        $this->fixtureSqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures_10.sql';
        $this->tempDir = sys_get_temp_dir() . '/eav_subset_test_' . uniqid();
        $this->subsetSourceDbPath = $this->tempDir . '/source.db';
        $this->testCacheDbPath = $this->tempDir . '/test_subset_cache.db';
        $this->testWorkDbPath = $this->tempDir . '/test_subset_work.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/index_config.ini';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // Verify fixtures exist
        if (!file_exists($this->fixtureSqlPath)) {
            throw new Exception("Fixtures not found at: {$this->fixtureSqlPath}");
        }

        // Define subset size (fixtures_10.sql has 10 occurrences, 18 media records)
        $this->occurrenceCount = 10;
        $this->mediaCount = 18;
    });
    
    beforeEach(function() {
        // Remove old test databases
        if (file_exists($this->subsetSourceDbPath)) {
            unlink($this->subsetSourceDbPath);
        }
        if (file_exists($this->testCacheDbPath)) {
            unlink($this->testCacheDbPath);
        }
        if (file_exists($this->testWorkDbPath)) {
            unlink($this->testWorkDbPath);
        }
    });
    
    afterEach(function() {
        // Cleanup test databases
        if (file_exists($this->subsetSourceDbPath)) {
            unlink($this->subsetSourceDbPath);
        }
        if (file_exists($this->testCacheDbPath)) {
            unlink($this->testCacheDbPath);
        }
        if (file_exists($this->testWorkDbPath)) {
            unlink($this->testWorkDbPath);
        }
    });

    afterAll(function() {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    });
    
    it('creates subset source database for testing', function() {
        echo "\n  Loading {$this->mediaCount}-media-record fixtures...\n";

        $startTime = microtime(true);

        // Create subset database from fixtures
        $subsetDb = new PDO('sqlite:' . $this->subsetSourceDbPath);
        $subsetDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load fixtures
        $sql = file_get_contents($this->fixtureSqlPath);
        $subsetDb->exec($sql);

        $elapsed = microtime(true) - $startTime;

        // Verify counts
        $mediaCount = $subsetDb->query('SELECT COUNT(*) FROM media')->fetchColumn();
        $occCount = $subsetDb->query('SELECT COUNT(*) FROM omoccurrences')->fetchColumn();

        echo sprintf("\n  ✓ Subset database created in %.2f seconds\n", $elapsed);
        echo "    Media records: $mediaCount\n";
        echo "    Omoccurrences records: $occCount\n";

        expect($mediaCount)->toBe($this->mediaCount);
        expect($occCount)->toBe($this->occurrenceCount);

        $subsetDb = null;
    });
    
    it('builds EAV index on subset using pure SQL', function() {
        // Load fixtures
        echo "\n  Loading {$this->mediaCount}-media-record fixtures...\n";

        $subsetDb = new PDO('sqlite:' . $this->subsetSourceDbPath);
        $subsetDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = file_get_contents($this->fixtureSqlPath);
        $subsetDb->exec($sql);
        unset($subsetDb);

        echo "  ✓ Fixtures loaded\n";
        
        // Now test EAV build
        echo "\n  Testing Pure SQL EAV Builder on subset...\n";
        
        $eavModel = new ImagesModelEav([
            'config_path' => $this->configPath,
            'work_db_path' => $this->testWorkDbPath,
            'cache_db_path' => $this->testCacheDbPath,
            'source_db_path' => $this->subsetSourceDbPath
        ]);
        
        echo "  Creating databases...\n";
        $eavModel->createDatabases();
        
        echo "  Building attributes...\n";
        $eavModel->buildAttributes();
        
        echo "  Building EAV index (pure SQL)...\n";
        $startTime = microtime(true);

        $result = $eavModel->buildEavIndexPureSQL(null);

        $elapsed = microtime(true) - $startTime;

        // Check for errors (method returns error array with 'type' key on error)
        if (isset($result['type']) && $result['type'] === 'error') {
            echo "\n  ERROR: " . $result['message'] . "\n";
            throw new Exception($result['message']);
        }

        // Verify result structure (success returns: entityCount, valuesTextCount, eavCount, totalTime)
        expect($result)->toBeAn('array');
        expect($result)->toContainKey('entityCount');
        expect($result)->toContainKey('valuesTextCount');
        expect($result)->toContainKey('eavCount');

        // Show statistics
        echo sprintf("\n  ✓ Pure SQL EAV build completed in %.2f seconds\n", $elapsed);
        echo sprintf("    Entities: %s\n", number_format($result['entityCount']));
        echo sprintf("    ValuesText: %s\n", number_format($result['valuesTextCount']));
        echo sprintf("    EAV rows: %s\n", number_format($result['eavCount']));
        echo sprintf("    Rate: %s entities/sec\n", number_format($result['entityCount'] / $elapsed, 0));
        
        // Calculate projected time for full dataset
        $fullDatasetSize = 6100000;
        $rateEntitiesPerSec = $result['entityCount'] / $elapsed;
        $projectedTime = $fullDatasetSize / $rateEntitiesPerSec;
        echo sprintf("    Projected time for 6.1M records: %.1f seconds (%.1f minutes)\n",
            $projectedTime, $projectedTime / 60);
        
        // Verify data integrity
        $cacheDb = new PDO('sqlite:' . $this->testCacheDbPath);
        
        $stmt = $cacheDb->query('SELECT COUNT(*) FROM Entities');
        $entityCount = $stmt->fetchColumn();
        expect($entityCount)->toBe($this->mediaCount);
        
        $stmt = $cacheDb->query('SELECT COUNT(*) FROM EAV WHERE ValueNumber IS NOT NULL');
        $numericCount = $stmt->fetchColumn();
        expect($numericCount)->toBeGreaterThan(0);
        echo sprintf("    Numeric EAV rows: %s\n", number_format($numericCount));
        
        $stmt = $cacheDb->query('SELECT COUNT(*) FROM EAV WHERE Vid IS NOT NULL');
        $textCount = $stmt->fetchColumn();
        expect($textCount)->toBeGreaterThan(0);
        echo sprintf("    Text EAV rows: %s\n", number_format($textCount));
        
        echo "  ✓ Data integrity verified\n";
    });
});

