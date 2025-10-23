<?php

use Symbiota\Helpers\Core\EavSchemaBuilder;

describe('EavSchemaBuilder', function() {
    
    beforeEach(function() {
        // Create temporary test database
        $this->testDbPath = sys_get_temp_dir() . '/test_eav_' . uniqid() . '.db';
        $this->db = new PDO(sprintf('sqlite:%s', $this->testDbPath));
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create test schema configuration
        $this->testConfigPath = sys_get_temp_dir() . '/test_schema_config_' . uniqid() . '.ini';
        $this->testTemplateDir = sys_get_temp_dir() . '/test_templates_' . uniqid();
        
        mkdir($this->testTemplateDir);

        // Write minimal test schema config
        $configContent = <<<INI
[_config]
root_table = test_media
root_id_column = mediaID

[test_media]
relationship = ""
columns[] = url:text
columns[] = mediaID:text

[test_occurrences]
relationship = "JOIN test_occurrences t ON t.mediaID = m.mediaID"
columns[] = catalogNumber:text
columns[] = latitude:text
INI;
        file_put_contents($this->testConfigPath, $configContent);

        // Create test source tables with sample data
        $this->db->exec("
            CREATE TABLE test_media (
                mediaID TEXT,
                url TEXT
            )
        ");
        
        $this->db->exec("
            CREATE TABLE test_occurrences (
                mediaID TEXT,
                catalogNumber TEXT,
                latitude TEXT
            )
        ");

        // Insert test data
        $this->db->exec("INSERT INTO test_media VALUES ('1', 'http://example.com/img1.jpg')");
        $this->db->exec("INSERT INTO test_media VALUES ('2', 'http://example.com/img2.jpg')");
        $this->db->exec("INSERT INTO test_occurrences VALUES ('1', 'CAT001', '34.5')");
        $this->db->exec("INSERT INTO test_occurrences VALUES ('2', 'CAT002', '35.2')");

        // Create Entities and Attributes tables (normally created in step 3)
        $this->db->exec("
            CREATE TABLE Entities (
                Eid INTEGER PRIMARY KEY AUTOINCREMENT,
                EntityValue INTEGER NOT NULL UNIQUE
            )
        ");
        
        $this->db->exec("
            CREATE TABLE Attributes (
                Aid INTEGER PRIMARY KEY AUTOINCREMENT,
                TableName TEXT NOT NULL,
                ColumnName TEXT NOT NULL,
                UNIQUE(TableName, ColumnName)
            )
        ");

        // Populate Entities (one per media record)
        $this->db->exec("INSERT INTO Entities (EntityValue) VALUES (1)");
        $this->db->exec("INSERT INTO Entities (EntityValue) VALUES (2)");

        // Populate Attributes
        $this->db->exec("INSERT INTO Attributes (TableName, ColumnName) VALUES ('test_media', 'url')");
        $this->db->exec("INSERT INTO Attributes (TableName, ColumnName) VALUES ('test_media', 'mediaID')");
        $this->db->exec("INSERT INTO Attributes (TableName, ColumnName) VALUES ('test_occurrences', 'catalogNumber')");
        $this->db->exec("INSERT INTO Attributes (TableName, ColumnName) VALUES ('test_occurrences', 'latitude')");

        // Create minimal SQL templates
        $this->createTestTemplates();
    });

    // Helper to create test templates
    $this->createTestTemplates = function() {
        // Create build_valuestext.sql template
        $valuesTextTemplate = <<<SQL
-- Build ValuesText Table
DROP TABLE IF EXISTS ValuesText;

CREATE TABLE ValuesText (
    Vid INTEGER PRIMARY KEY AUTOINCREMENT,
    ValueText TEXT NOT NULL UNIQUE
);

INSERT INTO ValuesText (ValueText)
SELECT DISTINCT value FROM (
{UNION_QUERIES}
)
ORDER BY value;
SQL;
        file_put_contents($this->testTemplateDir . '/build_valuestext.sql', $valuesTextTemplate);

        // Create build_eav.sql template
        $eavTemplate = <<<SQL
-- Build EAV Table
DROP TABLE IF EXISTS EAV;

CREATE TABLE EAV (
    Eid INTEGER NOT NULL,
    Aid INTEGER NOT NULL,
    Vid INTEGER,
    ValueNumber REAL,
    PRIMARY KEY (Eid, Aid),
    FOREIGN KEY (Eid) REFERENCES Entities(Eid),
    FOREIGN KEY (Aid) REFERENCES Attributes(Aid),
    FOREIGN KEY (Vid) REFERENCES ValuesText(Vid),
    CHECK ((Vid IS NULL) != (ValueNumber IS NULL))
) WITHOUT ROWID;

{INSERT_STATEMENTS}
SQL;
        file_put_contents($this->testTemplateDir . '/build_eav.sql', $eavTemplate);

        // Create create_minimal_indexes.sql
        $indexesTemplate = <<<SQL
-- Create Minimal Indexes
CREATE INDEX IF NOT EXISTS idx_eav_eid ON EAV(Eid);
CREATE INDEX IF NOT EXISTS idx_eav_vid ON EAV(Vid) WHERE Vid IS NOT NULL;
SQL;
        file_put_contents($this->testTemplateDir . '/create_minimal_indexes.sql', $indexesTemplate);
    };

    afterEach(function() {
        // Cleanup
        $this->db = null;
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
        if (is_dir($this->testTemplateDir)) {
            array_map('unlink', glob($this->testTemplateDir . '/*'));
            rmdir($this->testTemplateDir);
        }
    });

    describe('initialization', function() {
        it('should load schema configuration', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            expect($builder)->toBeAnInstanceOf(EavSchemaBuilder::class);
        });

        it('should throw exception if config file not found', function() {
            $closure = function() {
                new EavSchemaBuilder($this->db, '/nonexistent/config.ini', $this->testTemplateDir);
            };
            expect($closure)->toThrow(new Exception());
        });
    });

    describe('buildValuesText', function() {
        it('should create ValuesText table', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();

            // Check that ValuesText table exists
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ValuesText'");
            $table = $result->fetch(PDO::FETCH_ASSOC);
            
            expect($table)->not->toBeNull();
            expect($table['name'])->toBe('ValuesText');
        });

        it('should insert deduplicated text values', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();

            // Check that values were inserted
            $count = $this->db->query("SELECT COUNT(*) FROM ValuesText")->fetchColumn();
            
            // Should have deduplicated text values from all text columns
            // Expected: '1', '2', 'http://example.com/img1.jpg', 'http://example.com/img2.jpg', 'CAT001', 'CAT002', '34.5', '35.2'
            expect($count)->toBeGreaterThan(0);
            expect($count)->toBeGreaterThan(5); // At least 6 unique values
        });
    });

    describe('buildEav', function() {
        it('should create EAV table', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();
            $builder->buildEav();

            // Check that EAV table exists
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='EAV'");
            $table = $result->fetch(PDO::FETCH_ASSOC);
            
            expect($table)->not->toBeNull();
            expect($table['name'])->toBe('EAV');
        });

        it('should insert EAV rows', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();
            $builder->buildEav();

            // Check that EAV rows were inserted
            $count = $this->db->query("SELECT COUNT(*) FROM EAV")->fetchColumn();
            
            // Should have rows for each entity-attribute-value combination
            // 2 entities × 4 attributes = 8 rows expected
            expect($count)->toBeGreaterThan(0);
            expect($count)->toBeGreaterThan(7); // At least 8 rows
        });
    });

    describe('createIndexes', function() {
        it('should create indexes on EAV table', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();
            $builder->buildEav();
            $builder->createIndexes();

            // Check that indexes were created
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_eav_%'");
            $indexes = $result->fetchAll(PDO::FETCH_COLUMN);
            
            expect(count($indexes))->toBeGreaterThan(1); // At least 2 indexes
        });
    });

    describe('getStats', function() {
        it('should return statistics for all tables', function() {
            $builder = new EavSchemaBuilder($this->db, $this->testConfigPath, $this->testTemplateDir);
            $builder->buildValuesText();
            $builder->buildEav();

            $stats = $builder->getStats();

            expect($stats)->toBeAn('array');
            expect($stats)->toContainKey('ValuesText');
            expect($stats)->toContainKey('EAV');
            expect($stats['ValuesText']['rows'])->toBeGreaterThan(0);
            expect($stats['EAV']['rows'])->toBeGreaterThan(0);
        });
    });
});

