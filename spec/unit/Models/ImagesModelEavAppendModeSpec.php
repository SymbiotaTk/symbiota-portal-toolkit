<?php

use Symbiota\Helpers\Models\ImagesModelEav;
use Symbiota\Helpers\Core\Configuration;

describe('ImagesModelEav - Append Mode', function () {

    beforeAll(function () {
        // Initialize Configuration from config.php
        $configPath = __DIR__ . '/../../../config.php';
        if (!file_exists($configPath)) {
            throw new Exception("Configuration file not found: $configPath");
        }

        Configuration::reset();
        Configuration::initialize($configPath);

        $this->config = Configuration::getInstance();

        // Get cache database path from configuration
        $this->cacheDbPath = $this->config->get('components.images.eav_cache_db')
                          ?? $this->config->get('mod.images.eav_cache_db');

        if (empty($this->cacheDbPath)) {
            throw new Exception("EAV cache database path not configured");
        }

        // Get config INI path
        $this->configIniPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
    });
    
    describe('last_indexed_id tracking', function () {
        
        it('should use MAX(EntityValue) not MAX(Eid) for last_indexed_id', function () {
            $model = new ImagesModelEav([
                'config_path' => $this->configIniPath,
                'cache_db_path' => $this->cacheDbPath
            ]);

            // Build initial cache with limit
            $response = $model->buildEavIndexPureSQL(100, false);

            expect($response)->toBeAn('array');
            expect($response['success'])->toBe(true);

            expect(file_exists($this->cacheDbPath))->toBe(true);

            // Open cache database
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Get last_indexed_id from Config table
            $stmt = $db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'");
            $lastIndexedId = (int)$stmt->fetchColumn();
            
            // Get MAX(EntityValue) from Entities table
            $stmt = $db->query("SELECT MAX(EntityValue) FROM Entities");
            $maxEntityValue = (int)$stmt->fetchColumn();
            
            // Get MAX(Eid) from Entities table
            $stmt = $db->query("SELECT MAX(Eid) FROM Entities");
            $maxEid = (int)$stmt->fetchColumn();
            
            // Verify last_indexed_id equals MAX(EntityValue), not MAX(Eid)
            expect($lastIndexedId)->toBe($maxEntityValue);
            expect($lastIndexedId)->not->toBe($maxEid);
            
            echo sprintf(
                "\n  ✓ last_indexed_id=%d matches MAX(EntityValue)=%d (not MAX(Eid)=%d)\n",
                $lastIndexedId,
                $maxEntityValue,
                $maxEid
            );
        });
        
        it('should only process new entities in append mode', function () {
            $model = new ImagesModelEav([
                'config_path' => $this->configIniPath,
                'cache_db_path' => $this->cacheDbPath
            ]);

            // Open cache database
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Get initial entity count
            $stmt = $db->query("SELECT COUNT(*) FROM Entities");
            $initialCount = (int)$stmt->fetchColumn();
            
            // Get last_indexed_id
            $stmt = $db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'");
            $lastIndexedId = (int)$stmt->fetchColumn();
            
            echo sprintf(
                "\n  Initial state: %d entities, last_indexed_id=%d\n",
                $initialCount,
                $lastIndexedId
            );
            
            // Run append mode with limit
            $response = $model->buildEavIndexPureSQL(50, true);
            
            expect($response)->toBeAn('array');
            expect($response['success'])->toBe(true);
            
            // Get new entity count
            $stmt = $db->query("SELECT COUNT(*) FROM Entities");
            $newCount = (int)$stmt->fetchColumn();
            
            // Get new last_indexed_id
            $stmt = $db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'");
            $newLastIndexedId = (int)$stmt->fetchColumn();
            
            // Verify entities were added
            $addedCount = $newCount - $initialCount;
            expect($addedCount)->toBeGreaterThan(0);
            expect($addedCount)->toBeLessThanOrEqual(50);
            
            // Verify last_indexed_id was updated
            expect($newLastIndexedId)->toBeGreaterThan($lastIndexedId);
            
            echo sprintf(
                "  ✓ Append mode added %d entities (last_indexed_id: %d → %d)\n",
                $addedCount,
                $lastIndexedId,
                $newLastIndexedId
            );
        });
        
        it('should not create duplicate EAV rows in append mode', function () {
            $model = new ImagesModelEav([
                'config_path' => $this->configIniPath,
                'cache_db_path' => $this->cacheDbPath
            ]);

            // Open cache database
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Get initial EAV count
            $stmt = $db->query("SELECT COUNT(*) FROM EAV");
            $initialEavCount = (int)$stmt->fetchColumn();
            
            // Run append mode again
            $response = $model->buildEavIndexPureSQL(50, true);
            
            expect($response)->toBeAn('array');
            expect($response['success'])->toBe(true);
            
            // Get new EAV count
            $stmt = $db->query("SELECT COUNT(*) FROM EAV");
            $newEavCount = (int)$stmt->fetchColumn();
            
            // Verify EAV rows were added (no duplicates)
            $addedEavRows = $newEavCount - $initialEavCount;
            expect($addedEavRows)->toBeGreaterThan(0);
            
            echo sprintf(
                "  ✓ Append mode added %d EAV rows (no UNIQUE constraint violations)\n",
                $addedEavRows
            );
            
            // Verify no duplicate (Eid, Aid, Vid, VidOrder) combinations for text values
            $stmt = $db->query("
                SELECT COUNT(*) - COUNT(DISTINCT Eid, Aid, Vid, VidOrder)
                FROM EAV
                WHERE Vid IS NOT NULL
            ");
            $duplicateTextRows = (int)$stmt->fetchColumn();
            expect($duplicateTextRows)->toBe(0);
            
            // Verify no duplicate (Eid, Aid) combinations for numeric values
            $stmt = $db->query("
                SELECT COUNT(*) - COUNT(DISTINCT Eid, Aid)
                FROM EAV
                WHERE ValueNumber IS NOT NULL
            ");
            $duplicateNumericRows = (int)$stmt->fetchColumn();
            expect($duplicateNumericRows)->toBe(0);
            
            echo "  ✓ No duplicate EAV rows found\n";
        });
        
        it('should handle :split tokenization correctly in append mode', function () {
            // Open cache database
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            
            // Find a :split attribute (e.g., locality)
            $stmt = $db->query("
                SELECT Aid, ColumnName, TokenStrategy
                FROM Attributes
                WHERE TokenStrategy = 'split'
                LIMIT 1
            ");
            $splitAttr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$splitAttr) {
                $this->skip('No :split attributes found in cache');
                return;
            }
            
            $aid = $splitAttr['Aid'];
            $columnName = $splitAttr['ColumnName'];
            
            echo sprintf(
                "\n  Testing :split attribute: %s (Aid=%d)\n",
                $columnName,
                $aid
            );
            
            // Find an entity with multiple tokens for this attribute
            $stmt = $db->prepare("
                SELECT Eid, COUNT(*) as token_count
                FROM EAV
                WHERE Aid = :aid AND VidOrder IS NOT NULL
                GROUP BY Eid
                HAVING token_count > 1
                LIMIT 1
            ");
            $stmt->execute(['aid' => $aid]);
            $entity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entity) {
                echo "  ⚠ No multi-token entities found for this attribute\n";
                return;
            }
            
            $eid = $entity['Eid'];
            $tokenCount = $entity['token_count'];
            
            // Get all tokens for this entity
            $stmt = $db->prepare("
                SELECT e.Vid, e.VidOrder, v.ValueText
                FROM EAV e
                JOIN ValuesText v ON e.Vid = v.Vid
                WHERE e.Eid = :eid AND e.Aid = :aid
                ORDER BY e.VidOrder
            ");
            $stmt->execute(['eid' => $eid, 'aid' => $aid]);
            $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Verify VidOrder sequence is correct (1, 2, 3, ...)
            $expectedOrder = 1;
            foreach ($tokens as $token) {
                expect($token['VidOrder'])->toBe($expectedOrder);
                $expectedOrder++;
            }
            
            echo sprintf(
                "  ✓ Entity %d has %d tokens with correct VidOrder sequence\n",
                $eid,
                $tokenCount
            );
            
            // Display the tokens
            $tokenValues = array_column($tokens, 'ValueText');
            echo sprintf(
                "  ✓ Tokens: %s\n",
                implode(', ', array_map(function($v) { return "'{$v}'"; }, $tokenValues))
            );
        });
    });
});

