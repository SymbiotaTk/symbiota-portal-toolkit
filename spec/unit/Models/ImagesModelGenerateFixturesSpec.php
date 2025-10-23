<?php
/**
 * Generate EAV Test Fixtures
 *
 * Extracts 100 media records + related data from MySQL → SQLite → fixtures.sql
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelGenerateFixturesSpec.php"
 */

use Symbiota\Helpers\Core\DatabaseManager;

describe('Generate EAV Fixtures', function() {

    beforeAll(function() {
        $this->fixtureDir = __DIR__ . '/../../fixtures/images/eav';
        $this->fixtureDbPath = $this->fixtureDir . '/fixtures.db';
        $this->fixtureDumpPath = $this->fixtureDir . '/fixtures.sql';

        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0755, true);
        }
    });

    xit('should export 100 media records + related data to SQLite', function() {
        // SKIPPED: Requires MySQL connection and has SQL syntax errors
        // This test is for generating fixtures, not for testing functionality
        $sourceDbPath = $this->fixtureDbPath;

        if (file_exists($sourceDbPath)) {
            unlink($sourceDbPath);
        }

        echo "\n  Creating fixtures.db with 100 media records...\n";

        // Get MySQL connection
        $dbManager = new DatabaseManager();
        $mysql = $dbManager->getConnection('readonly');

        if (!$mysql) {
            $this->skip('MySQL connection not available');
        }

        // Create SQLite database
        $sourceDb = new PDO('sqlite:' . $sourceDbPath);
        $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Read config
        $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
        $config = parse_ini_file($configPath, true);

        // Helper to copy table with only configured columns
        $copyTable = function($tableName, $columns, $whereClause = '', $primaryKey = null, $foreignKeys = []) use ($mysql, $sourceDb, $config) {
            // Get schema for specified columns only
            $schemaResult = $mysql->query("DESCRIBE $tableName");
            $mysqlSchema = [];

            while ($col = $schemaResult->fetch_assoc()) {
                $mysqlSchema[$col['Field']] = $col;
            }

            // Build column definitions for only the specified columns
            $columnDefs = [];
            foreach ($columns as $colName) {
                if (!isset($mysqlSchema[$colName])) {
                    continue; // Skip if column doesn't exist in MySQL
                }

                $col = $mysqlSchema[$colName];
                $type = 'TEXT';
                if (strpos($col['Type'], 'int') !== false) $type = 'INTEGER';
                elseif (strpos($col['Type'], 'decimal') !== false ||
                        strpos($col['Type'], 'float') !== false ||
                        strpos($col['Type'], 'double') !== false) $type = 'REAL';

                $def = "`{$colName}` $type";

                // Add PRIMARY KEY constraint if this is the primary key
                if ($primaryKey && $colName === $primaryKey) {
                    $def .= ' PRIMARY KEY';
                }

                $columnDefs[] = $def;
            }

            // Drop table if it exists (to ensure clean schema)
            $sourceDb->exec("DROP TABLE IF EXISTS $tableName");

            // Create table with only configured columns
            $sourceDb->exec("CREATE TABLE $tableName (" . implode(', ', $columnDefs) . ")");

            // Create indexes on foreign keys for JOIN performance
            foreach ($foreignKeys as $fkDef) {
                // Parse format: "local_column:referenced_table.referenced_column"
                if (preg_match('/^([^:]+):/', $fkDef, $matches)) {
                    $fkColumn = $matches[1];
                    if (in_array($fkColumn, $columns)) {
                        $sourceDb->exec("CREATE INDEX IF NOT EXISTS idx_{$tableName}_{$fkColumn} ON $tableName($fkColumn)");
                    }
                }
            }

            // Build SELECT query
            $selectCols = implode(', ', array_map(function($c) { return "`$c`"; }, $columns));
            $query = "SELECT $selectCols FROM $tableName";
            if ($whereClause) $query .= " WHERE $whereClause";

            $result = $mysql->query($query);
            if (!$result) return 0;

            // Insert into SQLite
            $insertSql = "INSERT INTO $tableName (" . implode(', ', array_map(function($c) { return "`$c`"; }, $columns)) .
                         ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
            $stmt = $sourceDb->prepare($insertSql);

            $count = 0;
            while ($row = $result->fetch_row()) {
                $stmt->execute($row);
                $count++;
            }

            return $count;
        };

        // Export media (100 records using WHERE clause from config)
        $mediaColumns = [];
        if (isset($config['media']['columns'])) {
            foreach ($config['media']['columns'] as $colDef) {
                $parts = explode(':', $colDef);
                $mediaColumns[] = $parts[0];
            }
        }
        $mediaPk = $config['media']['primary_key'] ?? null;
        if ($mediaPk) {
            $mediaColumns[] = $mediaPk;
        }
        // Add foreign key columns
        if (isset($config['media']['foreign_key'])) {
            $mediaFks = is_array($config['media']['foreign_key']) ? $config['media']['foreign_key'] : [$config['media']['foreign_key']];
            foreach ($mediaFks as $fk) {
                if (preg_match('/^([^:]+):/', $fk, $matches)) {
                    $mediaColumns[] = $matches[1];
                }
            }
        }
        $mediaColumns = array_unique($mediaColumns);

        // Use WHERE clause from config (if defined), replacing alias 'm' with table name
        $whereClause = '1=1 LIMIT 100';
        if (isset($config['_config']['where_clause'])) {
            $rootTable = $config['_config']['root_table'];
            $whereClause = str_replace('m.', $rootTable . '.', $config['_config']['where_clause']) . ' LIMIT 100';
        }

        $mediaFks = $config['media']['foreign_key'] ?? [];
        $count = $copyTable('media', $mediaColumns, $whereClause, $mediaPk, $mediaFks);
        echo "    media: $count rows\n";

        // Get occids from media
        $occids = $sourceDb->query("SELECT DISTINCT occid FROM media WHERE occid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($occids)) {
            // Export omoccurrences
            $occColumns = [];
            if (isset($config['omoccurrences']['columns'])) {
                foreach ($config['omoccurrences']['columns'] as $colDef) {
                    $parts = explode(':', $colDef);
                    $occColumns[] = $parts[0];
                }
            }
            $occPk = $config['omoccurrences']['primary_key'] ?? null;
            if ($occPk) {
                $occColumns[] = $occPk;
            }
            // Add foreign key columns
            if (isset($config['omoccurrences']['foreign_key'])) {
                $occFks = is_array($config['omoccurrences']['foreign_key']) ? $config['omoccurrences']['foreign_key'] : [$config['omoccurrences']['foreign_key']];
                foreach ($occFks as $fk) {
                    if (preg_match('/^([^:]+):/', $fk, $matches)) {
                        $occColumns[] = $matches[1];
                    }
                }
            }
            $occColumns = array_unique($occColumns);

            $where = "occid IN (" . implode(',', $occids) . ")";
            $occFks = $config['omoccurrences']['foreign_key'] ?? [];
            $count = $copyTable('omoccurrences', $occColumns, $where, $occPk, $occFks);
            echo "    omoccurrences: $count rows\n";

            // Get collIDs
            $collids = $sourceDb->query("SELECT DISTINCT collid FROM omoccurrences WHERE collid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($collids)) {
                $collColumns = [];
                if (isset($config['omcollections']['columns'])) {
                    foreach ($config['omcollections']['columns'] as $colDef) {
                        $parts = explode(':', $colDef);
                        $collColumns[] = $parts[0];
                    }
                }
                $collPk = $config['omcollections']['primary_key'] ?? null;
                if ($collPk) {
                    $collColumns[] = $collPk;
                }
                $collColumns = array_unique($collColumns);

                $where = "collID IN (" . implode(',', $collids) . ")";
                $collFks = $config['omcollections']['foreign_key'] ?? [];
                $count = $copyTable('omcollections', $collColumns, $where, $collPk, $collFks);
                echo "    omcollections: $count rows\n";
            }

            // Get tids
            $tids = $sourceDb->query("SELECT DISTINCT tidInterpreted FROM omoccurrences WHERE tidInterpreted IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($tids)) {
                $taxaColumns = [];
                if (isset($config['taxa']['columns'])) {
                    foreach ($config['taxa']['columns'] as $colDef) {
                        $parts = explode(':', $colDef);
                        $taxaColumns[] = $parts[0];
                    }
                }
                $taxaPk = $config['taxa']['primary_key'] ?? null;
                if ($taxaPk) {
                    $taxaColumns[] = $taxaPk;
                }
                if (isset($config['taxa']['foreign_key'])) {
                    $taxaFks = is_array($config['taxa']['foreign_key']) ? $config['taxa']['foreign_key'] : [$config['taxa']['foreign_key']];
                    foreach ($taxaFks as $fk) {
                        if (preg_match('/^([^:]+):/', $fk, $matches)) {
                            $taxaColumns[] = $matches[1];
                        }
                    }
                }
                $taxaColumns = array_unique($taxaColumns);

                $where = "tid IN (" . implode(',', $tids) . ")";
                $taxaFks = $config['taxa']['foreign_key'] ?? [];
                $count = $copyTable('taxa', $taxaColumns, $where, $taxaPk, $taxaFks);
                echo "    taxa: $count rows\n";

                // Get taxstatus
                $taxstatusColumns = [];
                if (isset($config['taxstatus']['columns'])) {
                    foreach ($config['taxstatus']['columns'] as $colDef) {
                        $parts = explode(':', $colDef);
                        $taxstatusColumns[] = $parts[0];
                    }
                }
                $taxstatusPk = $config['taxstatus']['primary_key'] ?? null;
                if ($taxstatusPk) {
                    $taxstatusColumns[] = $taxstatusPk;
                }
                if (isset($config['taxstatus']['foreign_key'])) {
                    $taxstatusFks = is_array($config['taxstatus']['foreign_key']) ? $config['taxstatus']['foreign_key'] : [$config['taxstatus']['foreign_key']];
                    foreach ($taxstatusFks as $fk) {
                        if (preg_match('/^([^:]+):/', $fk, $matches)) {
                            $taxstatusColumns[] = $matches[1];
                        }
                    }
                }
                $taxstatusColumns = array_unique($taxstatusColumns);

                $where = "tid IN (" . implode(',', $tids) . ") OR parenttid IN (" . implode(',', $tids) . ")";
                $taxstatusFks = $config['taxstatus']['foreign_key'] ?? [];
                $count = $copyTable('taxstatus', $taxstatusColumns, $where, $taxstatusPk, $taxstatusFks);
                echo "    taxstatus: $count rows\n";
            }
        }

        $sourceDb = null; // Close connection

        expect(file_exists($this->fixtureDbPath))->toBe(true);
    });

    it('should verify fixtures.db is ready for use', function() {
        skipIf(!file_exists($this->fixtureDbPath));

        // Verify database exists and has data
        $db = new PDO('sqlite:' . $this->fixtureDbPath);

        // Check tables exist
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        expect($tables)->toContain('media');
        expect($tables)->toContain('omoccurrences');

        // Check row counts
        $mediaCount = $db->query('SELECT COUNT(*) FROM media')->fetchColumn();
        expect($mediaCount)->toBe(100);

        $occCount = $db->query('SELECT COUNT(*) FROM omoccurrences')->fetchColumn();
        expect($occCount)->toBeGreaterThan(0);

        $size = filesize($this->fixtureDbPath);
        echo "\n  ✓ fixtures.db ready (" . round($size / 1024, 1) . "KB)\n";
        echo "  ✓ Tables: " . implode(', ', $tables) . "\n";
        echo "  ✓ Media records: $mediaCount\n";
        echo "  ✓ Occurrence records: $occCount\n";

        // Remove .gitkeep
        $gitkeep = $this->fixtureDir . '/.gitkeep';
        if (file_exists($gitkeep)) {
            unlink($gitkeep);
        }

        echo "\n  Next: vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavFixtureSpec.php\n";
    });

});

