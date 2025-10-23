<?php

describe('ImagesModelEav - Incremental Append Mode', function() {

    beforeEach(function() {
        // Define test paths (shared across all tests)
        $testDir = '/var/www/temp/myco/data';
        skipIf(!is_dir($testDir));

        $this->cacheDbPath = $testDir . '/test_append_cache.db';
        $this->workDbPath = $testDir . '/test_append_work.db';
        $this->sourceDbPath = $testDir . '/source.db';

        skipIf(!file_exists($this->sourceDbPath));
    });

    describe('Initial build (no append)', function() {

        it('should build initial cache with 1000 records', function() {
            // Clean up any existing test databases
            if (file_exists($this->cacheDbPath)) {
                unlink($this->cacheDbPath);
            }
            if (file_exists($this->workDbPath)) {
                unlink($this->workDbPath);
            }

            // Run initial build via CLI command
            $output = shell_exec('cd /var/www/html/portal/tk && php index.php images cache-build-eav --limit 1000 2>&1');

            // Verify cache database exists
            expect(file_exists($this->cacheDbPath))->toBe(true);

            // Verify cache database content
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $entityCount = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($entityCount)->toBe(1000);

            // Check last_indexed_id
            $stmt = $db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'");
            $lastId = (int)$stmt->fetchColumn();
            expect($lastId)->toBeGreaterThan(0);
        });

        it('should have no duplicate Eids after initial build', function() {
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $stmt = $db->query('SELECT Eid, COUNT(*) as cnt FROM Entities GROUP BY Eid HAVING cnt > 1');
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            expect($duplicates)->toBeEmpty();
        });

    });

    describe('Append mode', function() {

        it('should append 500 more records without duplicating', function() {
            // Get initial state
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $initialCount = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            $initialLastId = (int)$db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'")->fetchColumn();

            // Append with limit of 500 via CLI
            $output = shell_exec('cd /var/www/html/portal/tk && php index.php images cache-build-eav --append --limit 500 2>&1');

            // Verify total count increased
            $finalCount = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($finalCount)->toBe($initialCount + 500);

            // Verify last_indexed_id increased
            $finalLastId = (int)$db->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'")->fetchColumn();
            expect($finalLastId)->toBeGreaterThan($initialLastId);
        });

        it('should still have no duplicate Eids after append', function() {
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $stmt = $db->query('SELECT Eid, COUNT(*) as cnt FROM Entities GROUP BY Eid HAVING cnt > 1');
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            expect($duplicates)->toBeEmpty();
        });

        it('should have expected total count', function() {
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $count = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();

            // Should have 1000 + 500 = 1500
            expect($count)->toBe(1500);
        });

    });

    describe('Multiple appends', function() {

        it('should handle multiple sequential appends', function() {
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $initialCount = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();

            // Append 250 more
            shell_exec('cd /var/www/html/portal/tk && php index.php images cache-build-eav --append --limit 250 2>&1');
            $count1 = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($count1)->toBe($initialCount + 250);

            // Append another 250
            shell_exec('cd /var/www/html/portal/tk && php index.php images cache-build-eav --append --limit 250 2>&1');
            $count2 = (int)$db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            expect($count2)->toBe($count1 + 250);

            // Still no duplicates
            $stmt = $db->query('SELECT Eid, COUNT(*) as cnt FROM Entities GROUP BY Eid HAVING cnt > 1');
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            expect($duplicates)->toBeEmpty();
        });

    });

    describe('Append with :split tokenization', function() {

        it('should correctly tokenize :split attributes in append mode', function() {
            $db = new PDO('sqlite:' . $this->cacheDbPath);

            // Check that locality (Aid=12, :split) has VidArray with multiple Vids
            $stmt = $db->query("
                SELECT VidArray
                FROM EAV
                WHERE Aid = 12
                  AND VidArray IS NOT NULL
                  AND VidArray LIKE '% %'
                LIMIT 10
            ");
            $multiWordLocalities = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Should have some multi-word localities
            expect(count($multiWordLocalities))->toBeGreaterThan(0);

            // Each VidArray should be space-separated Vids
            foreach ($multiWordLocalities as $vidArray) {
                expect($vidArray)->toMatch('/^[0-9 ]+$/');
            }
        });

    });

});

