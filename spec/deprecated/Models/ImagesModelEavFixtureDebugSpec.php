<?php

use Symbiota\Helpers\Models\ImagesModelEav;

describe('EAV Fixture Data Debug', function() {
    
    beforeAll(function() {
        $this->fixtureSqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures.sql';
        $this->tempDir = sys_get_temp_dir() . '/eav_debug_' . uniqid();
        $this->sourceDbPath = $this->tempDir . '/source.db';
        $this->cacheDbPath = $this->tempDir . '/cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        
        // Load fixtures
        echo "\n  Loading fixtures...\n";
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $sql = file_get_contents($this->fixtureSqlPath);
        $this->sourceDb->exec($sql);
    });
    
    afterAll(function() {
        $this->sourceDb = null;
        if (file_exists($this->sourceDbPath)) unlink($this->sourceDbPath);
        if (file_exists($this->cacheDbPath)) unlink($this->cacheDbPath);
        
        $workDbPath = str_replace('.db', '_work.db', $this->cacheDbPath);
        if (file_exists($workDbPath)) unlink($workDbPath);
        
        if (is_dir($this->tempDir)) rmdir($this->tempDir);
    });
    
    describe('Fixture Data Analysis', function() {
        
        it('should show table counts', function() {
            $counts = [
                'media' => $this->sourceDb->query('SELECT COUNT(*) FROM media')->fetchColumn(),
                'omoccurrences' => $this->sourceDb->query('SELECT COUNT(*) FROM omoccurrences')->fetchColumn(),
                'omcollections' => $this->sourceDb->query('SELECT COUNT(*) FROM omcollections')->fetchColumn(),
                'taxa' => $this->sourceDb->query('SELECT COUNT(*) FROM taxa')->fetchColumn(),
                'taxstatus' => $this->sourceDb->query('SELECT COUNT(*) FROM taxstatus')->fetchColumn(),
            ];
            
            echo "\n  Table counts:\n";
            foreach ($counts as $table => $count) {
                echo "    $table: $count\n";
            }
            
            expect($counts['media'])->toBe(100);
        });
        
        it('should show media->omoccurrences relationships', function() {
            // Count how many media records have occid
            $mediaWithOccid = $this->sourceDb->query('
                SELECT COUNT(*) FROM media WHERE occid IS NOT NULL
            ')->fetchColumn();
            
            echo "\n  Media records with occid: $mediaWithOccid / 100\n";
            
            // Check if those occids exist in omoccurrences
            $matchingOccurrences = $this->sourceDb->query('
                SELECT COUNT(DISTINCT o.occid) 
                FROM media m 
                JOIN omoccurrences o ON m.occid = o.occid
            ')->fetchColumn();
            
            echo "  Matching omoccurrences: $matchingOccurrences\n";
            
            // Sample data
            $sample = $this->sourceDb->query('
                SELECT m.mediaID, m.occid, o.catalogNumber, o.family, o.scientificName
                FROM media m
                LEFT JOIN omoccurrences o ON m.occid = o.occid
                LIMIT 5
            ')->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n  Sample media->omoccurrences join:\n";
            foreach ($sample as $row) {
                echo "    mediaID={$row['mediaID']}, occid={$row['occid']}, catalog={$row['catalogNumber']}, family={$row['family']}\n";
            }
        });
        
        it('should show configured columns from index_config.ini', function() {
            $config = parse_ini_file($this->configPath, true);
            
            echo "\n  Configured tables and columns:\n";
            foreach ($config as $tableName => $tableConfig) {
                if ($tableName === '_config' || $tableName === 'aliases') continue;
                
                echo "    $tableName:\n";
                
                if (isset($tableConfig['columns'])) {
                    $columns = is_array($tableConfig['columns']) ? $tableConfig['columns'] : [$tableConfig['columns']];
                    echo "      Columns (" . count($columns) . "): " . implode(', ', array_slice($columns, 0, 5));
                    if (count($columns) > 5) echo " ... (+" . (count($columns) - 5) . " more)";
                    echo "\n";
                }
                
                if (isset($tableConfig['foreign_key'])) {
                    $fks = is_array($tableConfig['foreign_key']) ? $tableConfig['foreign_key'] : [$tableConfig['foreign_key']];
                    echo "      Foreign keys: " . implode(', ', $fks) . "\n";
                }
            }
        });
        
        it('should show what the join query returns', function() {
            $config = parse_ini_file($this->configPath, true);
            $rootTable = $config['_config']['root_table'];
            $rootIdColumn = $config['_config']['root_id_column'];
            
            // Build a simple join query to see what data we get
            $query = "
                SELECT 
                    m.mediaID,
                    m.occid,
                    o.catalogNumber,
                    o.family,
                    o.scientificName,
                    c.collectionName,
                    t.sciname
                FROM media m
                LEFT JOIN omoccurrences o ON m.occid = o.occid
                LEFT JOIN omcollections c ON o.collid = c.collid
                LEFT JOIN taxa t ON o.tidInterpreted = t.tid
                LIMIT 10
            ";
            
            $rows = $this->sourceDb->query($query)->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n  Sample joined data (10 rows):\n";
            foreach ($rows as $i => $row) {
                echo "    Row " . ($i + 1) . ":\n";
                foreach ($row as $col => $val) {
                    if ($val !== null) {
                        echo "      $col: " . substr($val, 0, 50) . "\n";
                    }
                }
            }
            
            expect(count($rows))->toBe(10);
        });
        
    });
    
    describe('EAV Build Analysis', function() {
        
        beforeAll(function() {
            // Build the EAV index
            $eavParams = [
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDbPath,
                'cache_db_path' => $this->cacheDbPath,
                'work_db_path' => str_replace('.db', '_work.db', $this->cacheDbPath)
            ];
            
            $this->eav = new ImagesModelEav($eavParams);
            
            echo "\n  Creating EAV databases...\n";
            $this->eav->createDatabases();
            
            echo "  Building Attributes...\n";
            $this->eav->buildAttributes();
            
            echo "  Building EAV index...\n";
            $result = $this->eav->buildEavIndex([]);
            
            if ($result['type'] !== 'success') {
                echo "  ERROR: " . $result['message'] . "\n";
            }
            
            // Open cache DB for inspection
            $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        });
        
        it('should show EAV table counts', function() {
            $counts = [
                'Entities' => $this->cacheDb->query('SELECT COUNT(*) FROM Entities')->fetchColumn(),
                'Attributes' => $this->cacheDb->query('SELECT COUNT(*) FROM Attributes')->fetchColumn(),
                'ValuesText' => $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn(),
                'EAV' => $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn(),
            ];
            
            echo "\n  EAV table counts:\n";
            foreach ($counts as $table => $count) {
                echo "    $table: $count\n";
            }
            
            // This should be MUCH higher than 101!
            expect($counts['EAV'])->toBeGreaterThan(100);
        });
        
        it('should show Attributes breakdown', function() {
            $attrs = $this->cacheDb->query('
                SELECT TableName, COUNT(*) as cnt
                FROM Attributes
                GROUP BY TableName
                ORDER BY TableName
            ')->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n  Attributes by table:\n";
            foreach ($attrs as $row) {
                echo "    {$row['TableName']}: {$row['cnt']} attributes\n";
            }
        });
        
        it('should show EAV rows by attribute', function() {
            $eavByAttr = $this->cacheDb->query('
                SELECT a.TableName, a.ColumnName, COUNT(*) as cnt
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                GROUP BY a.TableName, a.ColumnName
                ORDER BY cnt DESC
                LIMIT 20
            ')->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n  Top 20 EAV rows by attribute:\n";
            foreach ($eavByAttr as $row) {
                echo "    {$row['TableName']}.{$row['ColumnName']}: {$row['cnt']} rows\n";
            }
        });
        
        it('should show sample EAV data', function() {
            $sample = $this->cacheDb->query('
                SELECT 
                    e.Eid,
                    e.EntityValue,
                    a.TableName,
                    a.ColumnName,
                    eav.VidArray,
                    eav.ValueNumber
                FROM EAV eav
                JOIN Entities e ON eav.Eid = e.Eid
                JOIN Attributes a ON eav.Aid = a.Aid
                LIMIT 10
            ')->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n  Sample EAV data (10 rows):\n";
            foreach ($sample as $i => $row) {
                echo "    Row " . ($i + 1) . ": Eid={$row['Eid']}, Entity={$row['EntityValue']}, ";
                echo "{$row['TableName']}.{$row['ColumnName']} = ";
                echo ($row['VidArray'] ?? $row['ValueNumber'] ?? 'NULL') . "\n";
            }
        });
        
        it('should check what columns buildJoinQuery selects', function() {
            // Use reflection to call private buildTableGraph method
            $config = parse_ini_file($this->configPath, true);
            $rootTable = $config['_config']['root_table'];
            $rootIdColumn = $config['_config']['root_id_column'];

            $reflection = new \ReflectionClass($this->eav);

            // Check the table graph
            $graphMethod = $reflection->getMethod('buildTableGraph');
            $graphMethod->setAccessible(true);
            $graph = $graphMethod->invoke($this->eav, $rootTable);

            echo "\n  Table graph:\n";
            foreach ($graph as $table => $children) {
                echo "    $table:\n";
                foreach ($children as $childTable => $joinInfo) {
                    echo "      -> $childTable (local={$joinInfo['local_col']}, ref={$joinInfo['ref_col']})\n";
                }
            }

            // Check the join query
            $method = $reflection->getMethod('buildJoinQuery');
            $method->setAccessible(true);

            $query = $method->invoke($this->eav, $rootTable, $rootIdColumn);

            echo "\n  Generated SQL query:\n";
            echo "    " . substr($query, 0, 500) . "...\n";

            // Execute the query and see what columns are returned
            $sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
            $stmt = $sourceDb->query($query . ' LIMIT 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            echo "\n  Columns returned by query:\n";
            if ($row) {
                foreach (array_keys($row) as $col) {
                    echo "    $col\n";
                }
            }
        });

        it('should check if ValuesText has data', function() {
            $count = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();

            if ($count > 0) {
                $sample = $this->cacheDb->query('SELECT * FROM ValuesText LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
                echo "\n  Sample ValuesText (10 rows):\n";
                foreach ($sample as $row) {
                    echo "    Vid={$row['Vid']}: " . substr($row['ValueText'], 0, 50) . "\n";
                }
            } else {
                echo "\n  WARNING: ValuesText is empty!\n";
                echo "  This means no text values are being indexed.\n";

                // Check if source data has text values
                echo "\n  Checking source data for text values:\n";
                $sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
                $sample = $sourceDb->query('
                    SELECT catalogNumber, family, scientificName
                    FROM omoccurrences
                    WHERE catalogNumber IS NOT NULL
                    LIMIT 5
                ')->fetchAll(PDO::FETCH_ASSOC);

                foreach ($sample as $row) {
                    echo "    catalog={$row['catalogNumber']}, family={$row['family']}, sci={$row['scientificName']}\n";
                }

                // Check what the join query returns
                echo "\n  Checking what buildJoinQuery returns:\n";
                $joinSample = $sourceDb->query('
                    SELECT m.mediaID, m.occid, o.catalogNumber, o.family
                    FROM media m
                    LEFT JOIN omoccurrences o ON m.occid = o.occid
                    WHERE m.occid IS NOT NULL
                    LIMIT 5
                ')->fetchAll(PDO::FETCH_ASSOC);

                foreach ($joinSample as $row) {
                    echo "    mediaID={$row['mediaID']}, occid={$row['occid']}, catalog={$row['catalogNumber']}, family={$row['family']}\n";
                }
            }
        });
        
    });
    
});

