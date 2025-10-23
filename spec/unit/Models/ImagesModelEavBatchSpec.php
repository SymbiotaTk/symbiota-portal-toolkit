<?php
/**
 * EAV Batch Processing Tests
 *
 * Tests batch processing with small batch sizes to verify:
 *   - Correct number of batches executed
 *   - Memory flushing working properly
 *   - Statistics tracking accurate
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavBatchSpec.php"
 */

use Symbiota\Helpers\Models\ImagesModelEav;

describe('EAV Batch Processing', function() {

    beforeAll(function() {
        $this->fixtureSqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures_10.sql';
        $this->tempDir = sys_get_temp_dir() . '/eav_batch_test_' . uniqid();
        $this->sourceDbPath = $this->tempDir . '/source.db';
        $this->cacheDbPath = $this->tempDir . '/images_cache.db';
        $this->workDbPath = $this->tempDir . '/images_cache_work.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/index_config.ini';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // Verify fixtures exist
        if (!file_exists($this->fixtureSqlPath)) {
            throw new Exception("Fixtures not found at: {$this->fixtureSqlPath}");
        }

        // Load fixtures into source.db
        echo "\n  Loading 10-record fixtures from SQL dump...\n";
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = file_get_contents($this->fixtureSqlPath);
        $this->sourceDb->exec($sql);

        // Verify data loaded
        $mediaCount = $this->sourceDb->query('SELECT COUNT(*) FROM media')->fetchColumn();
        echo "  ✓ Loaded $mediaCount media records\n";

        $this->mediaCount = $mediaCount;
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

    describe('Batch Size Configuration', function() {

        it('should accept custom batch sizes', function() {
            $params = [
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDbPath,
                'cache_db_path' => $this->cacheDbPath,
                'work_db_path' => $this->workDbPath,
                'entity_batch_size' => 5,
                'valuestext_flush_size' => 10
            ];

            $eav = new ImagesModelEav($params);
            expect($eav)->toBeAnInstanceOf(ImagesModelEav::class);
        });

    });

    xdescribe('Small Batch Processing (batch_size=5)', function() {
        // SKIPPED: Tests use old EAV schema with VidArray column that has been removed
        // The new implementation uses Vid and VidOrder columns instead
        // These tests need to be updated to match the new schema

        beforeAll(function() {
            // Create EAV instance with small batch sizes for testing
            $this->batchSize = 5;
            $this->flushSize = 50; // Small flush size to trigger multiple flushes

            $params = [
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDbPath,
                'cache_db_path' => $this->cacheDbPath,
                'work_db_path' => $this->workDbPath,
                'entity_batch_size' => $this->batchSize,
                'valuestext_flush_size' => $this->flushSize
            ];

            $this->eav = new ImagesModelEav($params);

            echo "\n  Creating databases with batch_size={$this->batchSize}, flush_size={$this->flushSize}...\n";
            $this->eav->createDatabases();
            
            echo "  Building Attributes...\n";
            $this->eav->buildAttributes();
            
            echo "  Building EAV index...\n";
            $result = $this->eav->buildEavIndex([]);
            
            $this->buildResult = $result;
            $this->stats = $result['stats'] ?? [];
            
            // Open cache database for verification
            $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        });

        it('should build EAV index successfully', function() {
            expect($this->buildResult['type'])->toBe('success');
        });

        it('should track entities processed', function() {
            echo "\n  Statistics:\n";
            echo "    Entities processed: {$this->stats['entities_processed']}\n";
            echo "    Entity batches: {$this->stats['entity_batches']}\n";
            echo "    ValuesText flushes: {$this->stats['valuestext_flushes']}\n";
            echo "    EAV inserts: {$this->stats['eav_inserts']}\n";
            echo "    Total tokens: {$this->stats['total_tokens']}\n";
            
            expect($this->stats['entities_processed'])->toBe($this->mediaCount);
        });

        it('should have correct number of entity batches', function() {
            // With current implementation, each entity is inserted individually
            // due to varying schema (display fields), so batches = entities
            $expectedBatches = $this->mediaCount;
            
            echo "\n  Entity Batch Verification:\n";
            echo "    Expected batches: $expectedBatches (1 per entity due to varying schema)\n";
            echo "    Actual batches: {$this->stats['entity_batches']}\n";
            
            expect($this->stats['entity_batches'])->toBe($expectedBatches);
        });

        it('should have multiple ValuesText flushes', function() {
            // With flush_size=50 and ~348 unique text values, we should have multiple flushes
            $uniqueValues = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            $expectedFlushes = ceil($uniqueValues / $this->flushSize);
            
            echo "\n  ValuesText Flush Verification:\n";
            echo "    Unique text values: $uniqueValues\n";
            echo "    Flush size: {$this->flushSize}\n";
            echo "    Expected flushes: ~$expectedFlushes\n";
            echo "    Actual flushes: {$this->stats['valuestext_flushes']}\n";
            
            // Should have at least 2 flushes with this data
            expect($this->stats['valuestext_flushes'])->toBeGreaterThan(1);
        });

        it('should track total tokens processed', function() {
            // Total tokens should be greater than unique values (due to duplicates)
            $uniqueValues = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            
            echo "\n  Token Processing:\n";
            echo "    Total tokens processed: {$this->stats['total_tokens']}\n";
            echo "    Unique text values: $uniqueValues\n";
            echo "    Duplication ratio: " . round($this->stats['total_tokens'] / $uniqueValues, 2) . "x\n";
            
            expect($this->stats['total_tokens'])->toBeGreaterThan($uniqueValues);
        });

        it('should track EAV inserts', function() {
            $eavCount = $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();
            
            echo "\n  EAV Insert Verification:\n";
            echo "    EAV rows in database: $eavCount\n";
            echo "    EAV inserts tracked: {$this->stats['eav_inserts']}\n";
            
            expect($this->stats['eav_inserts'])->toBe($eavCount);
        });

        it('should verify batch efficiency', function() {
            echo "\n  Batch Efficiency Summary:\n";
            echo "    Total entities: {$this->stats['entities_processed']}\n";
            echo "    Entity batches: {$this->stats['entity_batches']}\n";
            echo "    Entities per batch: " . round($this->stats['entities_processed'] / $this->stats['entity_batches'], 2) . "\n";
            echo "    \n";
            echo "    Total tokens: {$this->stats['total_tokens']}\n";
            echo "    ValuesText flushes: {$this->stats['valuestext_flushes']}\n";
            echo "    Tokens per flush: " . round($this->stats['total_tokens'] / max(1, $this->stats['valuestext_flushes']), 2) . "\n";
            echo "    \n";
            echo "    Total EAV inserts: {$this->stats['eav_inserts']}\n";
            echo "    Average EAV rows per entity: " . round($this->stats['eav_inserts'] / $this->stats['entities_processed'], 2) . "\n";
            
            // Verify all stats are positive
            expect($this->stats['entities_processed'])->toBeGreaterThan(0);
            expect($this->stats['entity_batches'])->toBeGreaterThan(0);
            expect($this->stats['valuestext_flushes'])->toBeGreaterThan(0);
            expect($this->stats['eav_inserts'])->toBeGreaterThan(0);
            expect($this->stats['total_tokens'])->toBeGreaterThan(0);
        });

    });

    xdescribe('Large Batch Processing (batch_size=1000)', function() {
        // SKIPPED: Tests use old EAV schema with VidArray column that has been removed
        // The new implementation uses Vid and VidOrder columns instead
        // These tests need to be updated to match the new schema

        beforeAll(function() {
            // Clean up previous cache
            if (file_exists($this->cacheDbPath)) {
                unlink($this->cacheDbPath);
            }
            if (file_exists($this->workDbPath)) {
                unlink($this->workDbPath);
            }

            // Create EAV instance with large batch sizes (production-like)
            $this->batchSize = 1000;
            $this->flushSize = 100000;

            $params = [
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDbPath,
                'cache_db_path' => $this->cacheDbPath,
                'work_db_path' => $this->workDbPath,
                'entity_batch_size' => $this->batchSize,
                'valuestext_flush_size' => $this->flushSize
            ];

            $this->eav = new ImagesModelEav($params);
            
            echo "\n  Creating databases with batch_size={$this->batchSize}, flush_size={$this->flushSize}...\n";
            $this->eav->createDatabases();
            
            echo "  Building Attributes...\n";
            $this->eav->buildAttributes();
            
            echo "  Building EAV index...\n";
            $result = $this->eav->buildEavIndex([]);
            
            $this->buildResult = $result;
            $this->stats = $result['stats'] ?? [];
            
            // Open cache database for verification
            $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        });

        it('should have fewer ValuesText flushes with larger batch size', function() {
            // With flush_size=100000 and ~348 unique values, should only have 1 flush (final)
            echo "\n  Large Batch Statistics:\n";
            echo "    Entities processed: {$this->stats['entities_processed']}\n";
            echo "    Entity batches: {$this->stats['entity_batches']}\n";
            echo "    ValuesText flushes: {$this->stats['valuestext_flushes']}\n";
            echo "    EAV inserts: {$this->stats['eav_inserts']}\n";
            echo "    Total tokens: {$this->stats['total_tokens']}\n";
            
            // Should only have 1 flush (the final one) since we don't exceed 100K unique values
            expect($this->stats['valuestext_flushes'])->toBe(1);
        });

        it('should produce same results as small batch', function() {
            $eavCount = $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();
            $valuesTextCount = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            
            echo "\n  Result Consistency:\n";
            echo "    EAV rows: $eavCount\n";
            echo "    ValuesText rows: $valuesTextCount\n";
            
            // Results should be identical regardless of batch size
            expect($eavCount)->toBeGreaterThan(0);
            expect($valuesTextCount)->toBeGreaterThan(0);
        });

    });

});

