<?php

use Symbiota\Helpers\Core\EavTokenizer;

describe('EavTokenizer', function() {
    
    beforeEach(function() {
        // Create temporary working database (for TSV import and tokenization)
        $this->workDbPath = sys_get_temp_dir() . '/test_eav_work_' . uniqid() . '.db';
        $this->workDb = new PDO(sprintf('sqlite:%s', $this->workDbPath));
        $this->workDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create temporary cache database (final EAV index)
        $this->cacheDbPath = sys_get_temp_dir() . '/test_eav_cache_' . uniqid() . '.db';
        $this->cacheDb = new PDO(sprintf('sqlite:%s', $this->cacheDbPath));
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create test TSV files
        $this->tsvDir = sys_get_temp_dir() . '/test_tsv_' . uniqid();
        mkdir($this->tsvDir);

        // Create sample TSV data
        $mediaTsv = <<<TSV
mediaID\turl\toccid
1\thttp://example.com/img1.jpg\t101
2\thttp://example.com/img2.jpg\t102
TSV;
        file_put_contents($this->tsvDir . '/media.tsv', $mediaTsv);

        $occurrencesTsv = <<<TSV
occid\tcatalogNumber\tscientificName\tlatitude
101\tCAT001\tQuercus alba\t34.5
102\tCAT002\tPinus strobus\t35.2
TSV;
        file_put_contents($this->tsvDir . '/omoccurrences.tsv', $occurrencesTsv);

        // Create test schema configuration
        $this->testConfigPath = sys_get_temp_dir() . '/test_tokenizer_config_' . uniqid() . '.ini';
        $configContent = <<<INI
[_config]
root_table = media
root_id_column = mediaID

[media]
relationship = ""
columns[] = url:text
columns[] = mediaID:text
columns[] = occid:text

[omoccurrences]
relationship = "JOIN omoccurrences t ON t.occid = m.occid"
columns[] = catalogNumber:text
columns[] = scientificName:text:split
columns[] = latitude:text
INI;
        file_put_contents($this->testConfigPath, $configContent);
    });

    afterEach(function() {
        // Cleanup
        $this->workDb = null;
        $this->cacheDb = null;
        
        if (file_exists($this->workDbPath)) {
            unlink($this->workDbPath);
        }
        if (file_exists($this->cacheDbPath)) {
            unlink($this->cacheDbPath);
        }
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
        if (is_dir($this->tsvDir)) {
            array_map('unlink', glob($this->tsvDir . '/*'));
            rmdir($this->tsvDir);
        }
    });

    describe('importTsvFiles', function() {
        it('should import TSV files into working database', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);

            // Check that tables were created
            $tables = $this->workDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            
            expect($tables)->toContain('media');
            expect($tables)->toContain('omoccurrences');

            // Check row counts
            $mediaCount = $this->workDb->query("SELECT COUNT(*) FROM media")->fetchColumn();
            $occCount = $this->workDb->query("SELECT COUNT(*) FROM omoccurrences")->fetchColumn();
            
            expect($mediaCount)->toBe(2);
            expect($occCount)->toBe(2);
        });
    });

    describe('tokenizeValues', function() {
        it('should extract and deduplicate all text values from working database', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();

            // Check that Tokens table was created in working database
            $tables = $this->workDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            expect($tables)->toContain('Tokens');

            // Check that tokens were extracted
            $count = $this->workDb->query("SELECT COUNT(*) FROM Tokens")->fetchColumn();
            
            // Expected unique tokens: '1', '2', '101', '102', 'http://example.com/img1.jpg', 
            // 'http://example.com/img2.jpg', 'CAT001', 'CAT002', 'Quercus', 'alba', 
            // 'Pinus', 'strobus', '34.5', '35.2'
            expect($count)->toBeGreaterThan(10);
        });

        it('should tokenize by whitespace for text fields', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();

            // Check that multi-word values are split
            $tokens = $this->workDb->query("SELECT token FROM Tokens ORDER BY token")->fetchAll(PDO::FETCH_COLUMN);
            
            expect($tokens)->toContain('Quercus');
            expect($tokens)->toContain('alba');
            expect($tokens)->toContain('Pinus');
            expect($tokens)->toContain('strobus');
        });
    });

    describe('buildCacheMetadata', function() {
        it('should create Entities, Attributes, and ValuesText in cache database', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();
            $tokenizer->buildCacheMetadata();

            // Check that metadata tables exist in cache database
            $tables = $this->cacheDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            
            expect($tables)->toContain('Entities');
            expect($tables)->toContain('Attributes');
            expect($tables)->toContain('ValuesText');

            // Check Entities (one per media record)
            $entityCount = $this->cacheDb->query("SELECT COUNT(*) FROM Entities")->fetchColumn();
            expect($entityCount)->toBe(2);

            // Check Attributes (one per column in schema config)
            $attrCount = $this->cacheDb->query("SELECT COUNT(*) FROM Attributes")->fetchColumn();
            expect($attrCount)->toBe(6); // 3 from media + 3 from omoccurrences

            // Check ValuesText (copied from Tokens in work db)
            $valuesCount = $this->cacheDb->query("SELECT COUNT(*) FROM ValuesText")->fetchColumn();
            expect($valuesCount)->toBeGreaterThan(10);
        });
    });

    describe('buildEavIndex', function() {
        it('should build EAV table in cache database by streaming TSV files', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();
            $tokenizer->buildCacheMetadata();
            $tokenizer->buildEavIndex($this->tsvDir);

            // Check that EAV table exists in cache database
            $tables = $this->cacheDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            expect($tables)->toContain('EAV');

            // Check that EAV rows were created
            $eavCount = $this->cacheDb->query("SELECT COUNT(*) FROM EAV")->fetchColumn();

            // 2 entities × 3 columns from media = 6 rows (root table only)
            expect($eavCount)->toBeGreaterThan(0);
            expect($eavCount)->toBeGreaterThan(5);
        });

        it('should process related tables with JOIN logic', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();
            $tokenizer->buildCacheMetadata();
            $tokenizer->buildEavIndex($this->tsvDir);

            // Check that EAV rows include data from related tables
            $eavCount = $this->cacheDb->query("SELECT COUNT(*) FROM EAV")->fetchColumn();

            // 2 entities × 6 attributes (3 from media + 3 from omoccurrences) = 12 rows expected
            // Each text value is tokenized, so actual count will be higher
            expect($eavCount)->toBeGreaterThan(10);

            // Verify that omoccurrences data is linked to correct entities
            // Entity 1 (mediaID=1, occid=101) should have catalogNumber='CAT001'
            // Note: VidArray is space-separated Vids, so we need to check if it contains the Vid
            $result = $this->cacheDb->query("
                SELECT v.ValueText
                FROM EAV eav
                JOIN Entities e ON e.Eid = eav.Eid
                JOIN Attributes a ON a.Aid = eav.Aid
                JOIN ValuesText v ON (' ' || eav.VidArray || ' ') LIKE ('% ' || v.Vid || ' %')
                WHERE e.EntityValue = 1
                AND a.TableName = 'omoccurrences'
                AND a.ColumnName = 'catalogNumber'
            ")->fetchAll(PDO::FETCH_COLUMN);

            expect($result)->toContain('CAT001');
        });
    });

    describe('cleanup', function() {
        it('should close working database and allow deletion', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();
            $tokenizer->cleanup();

            // Working database should be closeable
            $this->workDb = null;
            
            // Should be able to delete working database
            expect(file_exists($this->workDbPath))->toBe(true);
            unlink($this->workDbPath);
            expect(file_exists($this->workDbPath))->toBe(false);
        });
    });

    describe('getStats', function() {
        it('should return statistics from both databases', function() {
            $tokenizer = new EavTokenizer($this->workDb, $this->cacheDb, $this->testConfigPath, $this->workDbPath);
            $tokenizer->importTsvFiles($this->tsvDir);
            $tokenizer->tokenizeValues();
            $tokenizer->buildCacheMetadata();

            $stats = $tokenizer->getStats();

            expect($stats)->toBeAn('array');
            expect($stats)->toContainKey('work_db');
            expect($stats)->toContainKey('cache_db');
            expect($stats['work_db'])->toContainKey('Tokens');
            expect($stats['cache_db'])->toContainKey('ValuesText');
        });
    });
});

