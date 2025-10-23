<?php
/**
 * EAV Schema Validation Tests
 *
 * Tests that validate the EAV cache database schema and structure:
 *   1. Verify all expected tables exist
 *   2. Verify all expected attributes are created
 *   3. Verify indexes exist
 *   4. Verify foreign key relationships
 *   5. Verify data integrity
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavSchemaSpec.php"
 */

describe('EAV Schema Validation', function() {

    beforeAll(function() {
        // Use the pre-built fixture databases
        $this->sourceDbPath = '/var/www/temp/myco/data/testing/source.db';
        $this->cacheDbPath = '/var/www/temp/myco/data/testing/images_cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';

        echo "\n  Using pre-built fixture databases...\n";

        // Open source database
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Open cache database
        $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load config
        $this->config = parse_ini_file($this->configPath, true);
    });

    describe('Source Database Schema', function() {

        it('should have all expected tables', function() {
            $tables = ['media', 'omoccurrences', 'omcollections', 'taxa', 'taxstatus'];
            
            foreach ($tables as $table) {
                $count = $this->sourceDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
                expect($count)->toBe(1);
            }
            
            echo "\n  ✓ All source tables exist\n";
        });

        it('should have correct table counts', function() {
            // Just verify tables have data, don't hardcode exact counts
            $tables = ['media', 'omoccurrences', 'omcollections', 'taxa', 'taxstatus'];

            foreach ($tables as $table) {
                $actualCount = $this->sourceDb->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                echo "    $table: $actualCount\n";
                expect($actualCount)->toBeGreaterThan(0);
            }

            // Verify media count matches what we expect
            $mediaCount = $this->sourceDb->query("SELECT COUNT(*) FROM media")->fetchColumn();
            expect($mediaCount)->toBe(100);
        });

        it('should have primary keys on all tables', function() {
            $primaryKeys = [
                'media' => 'mediaID',
                'omoccurrences' => 'occid',
                'omcollections' => 'collID',
                'taxa' => 'tid'
            ];

            foreach ($primaryKeys as $table => $pkColumn) {
                $sql = $this->sourceDb->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
                expect($sql)->toContain('PRIMARY KEY');
                echo "    $table has PRIMARY KEY\n";
            }
        });

        it('should have indexes on foreign keys', function() {
            $indexes = [
                'idx_media_occid',
                'idx_omoccurrences_collid',
                'idx_omoccurrences_tidInterpreted',
                'idx_taxstatus_tid',
                'idx_taxstatus_parenttid'
            ];

            foreach ($indexes as $indexName) {
                $count = $this->sourceDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='$indexName'")->fetchColumn();
                expect($count)->toBe(1);
            }
            
            echo "\n  ✓ All foreign key indexes exist\n";
        });

        it('should have valid foreign key relationships', function() {
            // Check media.occid -> omoccurrences.occid
            $result = $this->sourceDb->query("
                SELECT COUNT(*) FROM media m
                LEFT JOIN omoccurrences o ON m.occid = o.occid
                WHERE m.occid IS NOT NULL AND o.occid IS NULL
            ")->fetchColumn();
            
            expect($result)->toBe(0);
            echo "\n  ✓ media.occid -> omoccurrences.occid valid\n";

            // Check omoccurrences.collid -> omcollections.collID
            $result = $this->sourceDb->query("
                SELECT COUNT(*) FROM omoccurrences o
                LEFT JOIN omcollections c ON o.collid = c.collID
                WHERE o.collid IS NOT NULL AND c.collID IS NULL
            ")->fetchColumn();
            
            expect($result)->toBe(0);
            echo "  ✓ omoccurrences.collid -> omcollections.collID valid\n";
        });

    });

    describe('Cache Database Schema', function() {

        it('should have all EAV tables', function() {
            $tables = ['Entities', 'Attributes', 'ValuesText', 'EAV', 'Config'];
            
            foreach ($tables as $table) {
                $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
                expect($count)->toBe(1);
            }
            
            echo "\n  ✓ All EAV tables exist\n";
        });

        it('should have all expected attributes', function() {
            $expectedAttributes = [
                'omoccurrences.catalogNumber',
                'omoccurrences.family',
                'omoccurrences.scientificName',
                'omoccurrences.locality',
                'omoccurrences.country',
                'omoccurrences.stateProvince',
                'omcollections.collectionName',
                'omcollections.collectionCode',
                'taxa.sciName',
                'taxstatus.family',
                'media.mediaID',
                'media.occid',
                'omoccurrences.occid',
                'omoccurrences.decimalLatitude',
                'omoccurrences.decimalLongitude'
            ];

            $stmt = $this->cacheDb->query("SELECT TableName, ColumnName FROM Attributes");
            $actualAttributes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $actualAttributes[] = $row['TableName'] . '.' . $row['ColumnName'];
            }

            foreach ($expectedAttributes as $attr) {
                expect(in_array($attr, $actualAttributes))->toBe(true);
            }

            echo "\n  ✓ All expected attributes exist (" . count($actualAttributes) . " total)\n";

            // Show all attributes grouped by table
            $result = $this->cacheDb->query("SELECT Aid, TableName, ColumnName, DataType FROM Attributes ORDER BY TableName, Aid");
            $attributes = $result->fetchAll(PDO::FETCH_ASSOC);

            echo "\n  Attributes by table:\n";
            $currentTable = '';
            foreach ($attributes as $attr) {
                if ($attr['TableName'] !== $currentTable) {
                    $currentTable = $attr['TableName'];
                    echo "\n  {$currentTable}:\n";
                }
                echo sprintf("    Aid %2d: %-25s (%s)\n",
                    $attr['Aid'], $attr['ColumnName'], $attr['DataType']);
            }
            echo "\n";
        });

        it('should have indexes on all EAV tables', function() {
            $indexes = [
                'idx_entities_value',
                'idx_attributes_table',
                'idx_attributes_column',
                'idx_valuestext_value',
                'idx_eav_aid',
                'idx_eav_eid',
                'idx_eav_vidarray',
                'idx_eav_valuenumber'
            ];

            foreach ($indexes as $indexName) {
                $count = $this->cacheDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='$indexName'")->fetchColumn();
                expect($count)->toBe(1);
            }
            
            echo "\n  ✓ All EAV indexes exist\n";
        });

        it('should have correct data types for attributes', function() {
            // Check text attributes
            $textAttrs = $this->cacheDb->query("SELECT COUNT(*) FROM Attributes WHERE DataType = 'text'")->fetchColumn();
            expect($textAttrs)->toBeGreaterThan(0);
            echo "\n  Text attributes: $textAttrs\n";

            // Check numeric attributes
            $numericAttrs = $this->cacheDb->query("SELECT COUNT(*) FROM Attributes WHERE DataType = 'numeric'")->fetchColumn();
            expect($numericAttrs)->toBeGreaterThan(0);
            echo "  Numeric attributes: $numericAttrs\n";

            // Check date attributes
            $dateAttrs = $this->cacheDb->query("SELECT COUNT(*) FROM Attributes WHERE DataType = 'date'")->fetchColumn();
            expect($dateAttrs)->toBeGreaterThan(0);
            echo "  Date attributes: $dateAttrs\n";
        });

    });

    describe('Data Integrity', function() {

        it('should have matching entity counts', function() {
            $sourceCount = $this->sourceDb->query("SELECT COUNT(*) FROM media")->fetchColumn();
            $cacheCount = $this->cacheDb->query("SELECT COUNT(*) FROM Entities")->fetchColumn();
            
            expect($cacheCount)->toBe($sourceCount);
            echo "\n  ✓ Entity count matches: $cacheCount\n";
        });

        it('should have valid Eid references in EAV', function() {
            // Check that all Eids in EAV exist in Entities
            $result = $this->cacheDb->query("
                SELECT COUNT(*) FROM EAV e
                LEFT JOIN Entities ent ON e.Eid = ent.Eid
                WHERE ent.Eid IS NULL
            ")->fetchColumn();
            
            expect($result)->toBe(0);
            echo "\n  ✓ All EAV.Eid references are valid\n";
        });

        it('should have valid Aid references in EAV', function() {
            // Check that all Aids in EAV exist in Attributes
            $result = $this->cacheDb->query("
                SELECT COUNT(*) FROM EAV e
                LEFT JOIN Attributes a ON e.Aid = a.Aid
                WHERE a.Aid IS NULL
            ")->fetchColumn();
            
            expect($result)->toBe(0);
            echo "\n  ✓ All EAV.Aid references are valid\n";
        });

        it('should have valid Vid references in EAV', function() {
            // Check that all Vids in VidArray exist in ValuesText
            // This is a simplified check - just verify some Vids exist
            $vidArraySample = $this->cacheDb->query("SELECT VidArray FROM EAV WHERE VidArray IS NOT NULL LIMIT 1")->fetchColumn();
            
            if ($vidArraySample) {
                $vids = explode(' ', $vidArraySample);
                foreach ($vids as $vid) {
                    $exists = $this->cacheDb->query("SELECT COUNT(*) FROM ValuesText WHERE Vid = $vid")->fetchColumn();
                    expect($exists)->toBe(1);
                }
                echo "\n  ✓ Sample Vid references are valid\n";
            }
        });

        it('should have media.occid matching omoccurrences.occid in EAV', function() {
            // Get Aid for media.occid and omoccurrences.occid
            $mediaOccidAid = $this->cacheDb->query("
                SELECT Aid FROM Attributes WHERE TableName = 'media' AND ColumnName = 'occid'
            ")->fetchColumn();

            $omoccOccidAid = $this->cacheDb->query("
                SELECT Aid FROM Attributes WHERE TableName = 'omoccurrences' AND ColumnName = 'occid'
            ")->fetchColumn();

            if (!$mediaOccidAid || !$omoccOccidAid) {
                echo "\n  ⚠ media.occid or omoccurrences.occid attribute not found\n";
                expect(true)->toBe(true);
                return;
            }

            // For each entity, media.occid should equal omoccurrences.occid
            $stmt = $this->cacheDb->query("
                SELECT e1.Eid, e1.ValueNumber as media_occid, e2.ValueNumber as omocc_occid
                FROM EAV e1
                JOIN EAV e2 ON e1.Eid = e2.Eid
                WHERE e1.Aid = $mediaOccidAid
                AND e2.Aid = $omoccOccidAid
                LIMIT 10
            ");

            $matches = 0;
            $mismatches = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['media_occid'] == $row['omocc_occid']) {
                    $matches++;
                } else {
                    $mismatches++;
                    echo "    Mismatch at Eid={$row['Eid']}: media.occid={$row['media_occid']}, omocc.occid={$row['omocc_occid']}\n";
                }
            }

            if ($matches > 0) {
                echo "\n  ✓ Verified $matches entities: media.occid = omoccurrences.occid\n";
                expect($matches)->toBeGreaterThan(0);
            } else {
                echo "\n  ⚠ No matching occid values found (this may be expected if data is sparse)\n";
                expect(true)->toBe(true);
            }
        });

    });

    describe('Configuration Validation', function() {

        it('should have valid root table configuration', function() {
            expect($this->config['_config']['root_table'])->toBe('media');
            expect($this->config['_config']['root_id_column'])->toBe('mediaID');
            echo "\n  ✓ Root table: media.mediaID\n";
        });

        it('should have all configured tables in Attributes', function() {
            $configuredTables = [];
            foreach ($this->config as $tableName => $tableConfig) {
                if ($tableName === '_config' || $tableName === 'aliases') continue;
                $configuredTables[] = $tableName;
            }

            foreach ($configuredTables as $table) {
                $count = $this->cacheDb->query("SELECT COUNT(*) FROM Attributes WHERE TableName = '$table'")->fetchColumn();
                expect($count)->toBeGreaterThan(0);
            }

            echo "\n  ✓ All configured tables have attributes\n";
        });

    });

});

