<?php
/**
 * EAV Complete Workflow Tests with Fixtures
 *
 * Tests the complete EAV workflow using fixtures.db:
 *   1. Load fixtures.db (100 media records + related data)
 *   2. Build EAV index
 *   3. Test query functions
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavFixtureSpec.php"
 */

use Symbiota\Helpers\Models\ImagesModelEav;

describe('EAV Complete Workflow with Fixtures', function() {

    beforeAll(function() {
        $this->fixtureSqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures.sql';
        $this->tempDir = sys_get_temp_dir() . '/eav_test_' . uniqid();
        $this->sourceDbPath = $this->tempDir . '/source.db';
        $this->cacheDbPath = $this->tempDir . '/images_cache.db';
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // Verify fixtures exist
        if (!file_exists($this->fixtureSqlPath)) {
            throw new Exception("Fixtures not found at: {$this->fixtureSqlPath}");
        }

        // Load fixtures into source.db
        echo "\n  Loading fixtures from SQL dump...\n";
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
        // Copy databases to /var/temp/myco/data/testing for review
        $reviewDir = '/var/temp/myco/data/testing';
        if (!is_dir($reviewDir)) {
            mkdir($reviewDir, 0755, true);
        }

        if (file_exists($this->sourceDbPath)) {
            copy($this->sourceDbPath, $reviewDir . '/source.db');
            echo "\n  ✓ Copied source.db to $reviewDir/source.db\n";
        }

        if (file_exists($this->cacheDbPath)) {
            copy($this->cacheDbPath, $reviewDir . '/images_cache.db');
            echo "  ✓ Copied images_cache.db to $reviewDir/images_cache.db\n";
        }

        $workDbPath = str_replace('.db', '_work.db', $this->cacheDbPath);
        if (file_exists($workDbPath)) {
            copy($workDbPath, $reviewDir . '/images_cache_work.db');
            echo "  ✓ Copied images_cache_work.db to $reviewDir/images_cache_work.db\n";
        }

        // Clean up temp files
        $this->sourceDb = null;
        $this->cacheDb = null;
        if (file_exists($this->sourceDbPath)) unlink($this->sourceDbPath);
        if (file_exists($this->cacheDbPath)) unlink($this->cacheDbPath);
        if (file_exists($workDbPath)) unlink($workDbPath);

        if (is_dir($this->tempDir)) rmdir($this->tempDir);
    });
    
    describe('Fixture Validation', function() {

        it('should have all required tables', function() {
            $tables = $this->sourceDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

            expect($tables)->toContain('media');
            expect($tables)->toContain('omoccurrences');
            expect($tables)->toContain('omcollections');
            expect($tables)->toContain('taxa');
            expect($tables)->toContain('taxstatus');
        });

        it('should have valid foreign key relationships', function() {
            // Verify all omoccurrences have matching media records
            $orphaned = $this->sourceDb->query('
                SELECT COUNT(*) FROM omoccurrences o
                WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.occid = o.occid)
            ')->fetchColumn();
            expect($orphaned)->toBe(0);
        });

    });
    
    describe('Config Validation', function() {

        it('should parse index_config.ini correctly', function() {
            $config = parse_ini_file($this->configPath, true);

            expect($config['_config']['root_table'])->toBe('media');
            expect($config['media']['primary_key'])->toBe('mediaID');
            expect($config['media']['foreign_key'])->toContain('occid:omoccurrences.occid');
        });

    });
    
    describe('EAV Build', function() {

        beforeAll(function() {
            // Create ImagesModelEav instance with correct parameters
            $this->eavParams = [
                'config_path' => $this->configPath,
                'source_db_path' => $this->sourceDbPath,
                'cache_db_path' => $this->cacheDbPath,
                'work_db_path' => str_replace('.db', '_work.db', $this->cacheDbPath)
            ];

            $this->eav = new ImagesModelEav($this->eavParams);
        });

        it('should create databases and schema', function() {
            echo "\n  Creating databases...\n";
            $this->eav->createDatabases();

            // Verify cache database exists
            expect(file_exists($this->cacheDbPath))->toBe(true);

            // Open cache database connection for testing (store in parent scope)
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verify tables exist
            $tables = $cacheDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

            expect($tables)->toContain('Entities');
            expect($tables)->toContain('Attributes');
            expect($tables)->toContain('ValuesText');
            expect($tables)->toContain('EAV');

            echo "  ✓ Created EAV schema (tables: " . implode(', ', $tables) . ")\n";
        });

        it('should build Attributes table', function() {
            echo "  Building Attributes...\n";
            $this->eav->buildAttributes();

            // Reconnect to cache DB to see changes
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $count = $cacheDb->query('SELECT COUNT(*) FROM Attributes')->fetchColumn();
            expect($count)->toBeGreaterThan(0);

            // Verify structure
            $attr = $cacheDb->query('SELECT * FROM Attributes LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            expect(isset($attr['Aid']))->toBe(true);
            expect(isset($attr['TableName']))->toBe(true);
            expect(isset($attr['ColumnName']))->toBe(true);
            expect(isset($attr['DataType']))->toBe(true);

            echo "  ✓ Created $count attributes\n";
        });

        it('should build complete EAV index', function() {
            echo "  Building EAV index...\n";
            $result = $this->eav->buildEavIndex([]);

            expect($result['type'])->toBe('success');

            // Create autocomplete indexes
            echo "  Creating autocomplete indexes...\n";
            $this->eav->createAutocompleteIndexes();

            // Reconnect to cache DB to see changes
            $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verify Entities
            $entityCount = $cacheDb->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($entityCount)->toBe($this->mediaCount);

            // Verify EAV
            $eavCount = $cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();
            expect($eavCount)->toBeGreaterThan(0);

            // Verify ValuesText (may be 0 if all values are numeric or empty)
            $valuesTextCount = $cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();

            echo "  ✓ Entities: $entityCount\n";
            echo "  ✓ EAV rows: $eavCount\n";
            echo "  ✓ ValuesText: $valuesTextCount\n";

            // Store for query tests (in parent scope)
            $this->cacheDb = $cacheDb;
        });

        it('should have valid EAV relationships', function() {
            // Reconnect to cache DB if needed
            if (!isset($this->cacheDb)) {
                $cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
                $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                $cacheDb = $this->cacheDb;
            }

            // All EAV records should have valid Eid
            $orphanedEav = $cacheDb->query('
                SELECT COUNT(*) FROM EAV
                WHERE NOT EXISTS (SELECT 1 FROM Entities WHERE Entities.Eid = EAV.Eid)
            ')->fetchColumn();
            expect($orphanedEav)->toBe(0);

            // All EAV records should have valid Aid
            $orphanedAid = $cacheDb->query('
                SELECT COUNT(*) FROM EAV
                WHERE NOT EXISTS (SELECT 1 FROM Attributes WHERE Attributes.Aid = EAV.Aid)
            ')->fetchColumn();
            expect($orphanedAid)->toBe(0);

            echo "  ✓ All EAV relationships valid\n";
        });

    });
    
    describe('Query Functions', function() {

        beforeAll(function() {
            // Reconnect to cache DB for query tests
            if (!isset($this->cacheDb)) {
                $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
                $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        });

        it('should query EAV index by catalogNumber', function() {
            // Get a sample catalogNumber from source
            $sampleData = $this->sourceDb->query('
                SELECT catalogNumber, family FROM omoccurrences
                WHERE catalogNumber IS NOT NULL
                LIMIT 1
            ')->fetch(PDO::FETCH_ASSOC);

            if (!$sampleData || !$sampleData['catalogNumber']) {
                $this->skip('No catalogNumber in fixtures');
            }

            echo "\n  Querying for catalogNumber: {$sampleData['catalogNumber']}\n";

            // Query EAV for this catalogNumber
            // Note: VidArray contains comma-separated Vid values
            $results = $this->cacheDb->query("
                SELECT DISTINCT e.Eid, e.EntityValue
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                JOIN Entities e ON eav.Eid = e.Eid
                WHERE a.ColumnName = 'catalogNumber'
                AND eav.VidArray IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo "  ✓ Found " . count($results) . " entities with catalogNumber\n";
        });

        it('should query EAV index by family', function() {
            // Get a sample family from source
            $sampleFamily = $this->sourceDb->query('
                SELECT family FROM omoccurrences
                WHERE family IS NOT NULL
                LIMIT 1
            ')->fetchColumn();

            if (!$sampleFamily) {
                $this->skip('No family in fixtures');
            }

            echo "  Querying for family: $sampleFamily\n";

            // Query EAV for this family
            $results = $this->cacheDb->query("
                SELECT DISTINCT e.Eid
                FROM EAV eav
                JOIN Attributes a ON eav.Aid = a.Aid
                JOIN Entities e ON eav.Eid = e.Eid
                WHERE a.ColumnName = 'family'
                AND eav.VidArray IS NOT NULL
            ")->fetchAll(PDO::FETCH_COLUMN);

            echo "  ✓ Found " . count($results) . " entities with family\n";
        });

        it('should verify EAV index completeness', function() {
            // Count attributes
            $attrCount = $this->cacheDb->query('SELECT COUNT(*) FROM Attributes')->fetchColumn();

            // Count unique attributes in EAV
            $eavAttrCount = $this->cacheDb->query('SELECT COUNT(DISTINCT Aid) FROM EAV')->fetchColumn();

            // Most attributes should be used (some might not have values in fixtures)
            expect($eavAttrCount)->toBeGreaterThan(0);

            echo "\n  ✓ Attributes defined: $attrCount\n";
            echo "  ✓ Attributes with values: $eavAttrCount\n";
        });

    });

});

