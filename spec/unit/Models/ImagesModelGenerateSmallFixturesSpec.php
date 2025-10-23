<?php
/**
 * Generate Small EAV Fixtures
 *
 * This test generates 10-record and 20-record fixture subsets from the full 100-record fixtures.
 * It maintains all relationships between media, omoccurrences, omcollections, and taxa tables.
 *
 * Run in Docker:
 *   docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php"
 */

describe('Generate Small EAV Fixtures', function() {

    beforeAll(function() {
        $this->fixturesDir = __DIR__ . '/../../fixtures/eav';
        $this->outputDir10 = __DIR__ . '/../../fixtures/eav_10';
        $this->outputDir20 = __DIR__ . '/../../fixtures/eav_20';

        // Helper function to read TSV file
        $this->readTsv = function($filepath) {
            if (!file_exists($filepath)) {
                throw new Exception("File not found: $filepath");
            }

            $handle = fopen($filepath, 'r');
            $headers = fgetcsv($handle, 0, "\t");

            $rows = [];
            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                if (count($row) >= count($headers)) {
                    $rows[] = array_combine($headers, $row);
                }
            }

            fclose($handle);
            return ['headers' => $headers, 'rows' => $rows];
        };

        // Helper function to write TSV file
        $this->writeTsv = function($filepath, $headers, $rows) {
            $handle = fopen($filepath, 'w');
            fputcsv($handle, $headers, "\t");

            foreach ($rows as $row) {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row[$header] ?? '';
                }
                fputcsv($handle, $values, "\t");
            }

            fclose($handle);
        };
    });

    it('should create 10-record fixture subset', function() {
        // Create output directory
        if (!is_dir($this->outputDir10)) {
            mkdir($this->outputDir10, 0755, true);
        }

        echo "\n  Creating 10-record subset...\n";

        // Read all fixtures
        $media = ($this->readTsv)($this->fixturesDir . '/media.tsv');
        $omoccurrences = ($this->readTsv)($this->fixturesDir . '/omoccurrences.tsv');
        $omcollections = ($this->readTsv)($this->fixturesDir . '/omcollections.tsv');
        $taxa = ($this->readTsv)($this->fixturesDir . '/taxa.tsv');

        // Get first 10 unique occids from media
        $selectedOccids = [];
        $selectedMedia = [];
        foreach ($media['rows'] as $row) {
            if (count($selectedOccids) >= 10 && isset($row['occid']) && !in_array($row['occid'], $selectedOccids)) {
                break;
            }
            $selectedMedia[] = $row;
            if (isset($row['occid']) && $row['occid'] !== '' && !in_array($row['occid'], $selectedOccids)) {
                $selectedOccids[] = $row['occid'];
            }
        }

        echo "    Selected " . count($selectedMedia) . " media records with " . count($selectedOccids) . " unique occids\n";

        // Get related omoccurrences
        $selectedOmoccurrences = [];
        $selectedCollids = [];
        $selectedTids = [];
        foreach ($omoccurrences['rows'] as $row) {
            if (in_array($row['occid'], $selectedOccids)) {
                $selectedOmoccurrences[] = $row;
                if (isset($row['collid']) && $row['collid'] !== '') {
                    $selectedCollids[] = $row['collid'];
                }
                if (isset($row['tidInterpreted']) && $row['tidInterpreted'] !== '') {
                    $selectedTids[] = $row['tidInterpreted'];
                }
            }
        }

        echo "    Selected " . count($selectedOmoccurrences) . " omoccurrences records\n";

        // Get related omcollections
        $selectedOmcollections = [];
        foreach ($omcollections['rows'] as $row) {
            if (in_array($row['collID'], $selectedCollids)) {
                $selectedOmcollections[] = $row;
            }
        }

        echo "    Selected " . count($selectedOmcollections) . " omcollections records\n";

        // Get related taxa
        $selectedTaxa = [];
        foreach ($taxa['rows'] as $row) {
            if (in_array($row['tid'], $selectedTids)) {
                $selectedTaxa[] = $row;
            }
        }

        echo "    Selected " . count($selectedTaxa) . " taxa records\n";

        // Write files
        ($this->writeTsv)($this->outputDir10 . '/media.tsv', $media['headers'], $selectedMedia);
        ($this->writeTsv)($this->outputDir10 . '/omoccurrences.tsv', $omoccurrences['headers'], $selectedOmoccurrences);
        ($this->writeTsv)($this->outputDir10 . '/omcollections.tsv', $omcollections['headers'], $selectedOmcollections);
        ($this->writeTsv)($this->outputDir10 . '/taxa.tsv', $taxa['headers'], $selectedTaxa);

        // Create README
        $readme = <<<'README'
# EAV Test Fixtures - 10 Records

This directory contains a minimal 10-record subset of the full EAV fixtures for fast unit testing.

## Contents

- `media.tsv` - 10+ media records (multiple images per occurrence)
- `omoccurrences.tsv` - 10 occurrence records
- `omcollections.tsv` - Related collection records
- `taxa.tsv` - Related taxonomy records

## Usage

These fixtures are designed for fast unit tests that need to verify EAV functionality
without the overhead of building large indexes.

## Generation

Generated from `spec/fixtures/eav/` using `spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php`
README;
        file_put_contents($this->outputDir10 . '/README.md', $readme);

        echo "    ✓ Written to spec/fixtures/eav_10/\n";

        // Verify files exist
        expect(file_exists($this->outputDir10 . '/media.tsv'))->toBe(true);
        expect(file_exists($this->outputDir10 . '/omoccurrences.tsv'))->toBe(true);
        expect(file_exists($this->outputDir10 . '/omcollections.tsv'))->toBe(true);
        expect(file_exists($this->outputDir10 . '/taxa.tsv'))->toBe(true);
        expect(file_exists($this->outputDir10 . '/README.md'))->toBe(true);

        // Verify record counts
        expect(count($selectedMedia))->toBeGreaterThan(0);
        expect(count($selectedOccids))->toBe(10);
        expect(count($selectedOmoccurrences))->toBe(10);
    });

    it('should create 10-record SQL fixture', function() {
        echo "\n  Creating 10-record SQL fixture...\n";

        // Create temp database from TSV fixtures
        $tempDb = new PDO('sqlite::memory:');
        $tempDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load TSV data
        $media = ($this->readTsv)($this->outputDir10 . '/media.tsv');
        $omoccurrences = ($this->readTsv)($this->outputDir10 . '/omoccurrences.tsv');
        $omcollections = ($this->readTsv)($this->outputDir10 . '/omcollections.tsv');
        $taxa = ($this->readTsv)($this->outputDir10 . '/taxa.tsv');

        // Create tables and insert data
        $createAndInsert = function($db, $tableName, $headers, $rows) {
            // Create table
            $columns = array_map(function($col) {
                return "`{$col}` TEXT";
            }, $headers);
            $db->exec("CREATE TABLE `{$tableName}` (" . implode(', ', $columns) . ")");

            // Insert rows
            if (count($rows) > 0) {
                $placeholders = array_fill(0, count($headers), '?');
                $sql = "INSERT INTO `{$tableName}` VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $db->prepare($sql);

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($headers as $header) {
                        $values[] = $row[$header] ?? '';
                    }
                    $stmt->execute($values);
                }
            }
        };

        $createAndInsert($tempDb, 'media', $media['headers'], $media['rows']);
        $createAndInsert($tempDb, 'omoccurrences', $omoccurrences['headers'], $omoccurrences['rows']);
        $createAndInsert($tempDb, 'omcollections', $omcollections['headers'], $omcollections['rows']);
        $createAndInsert($tempDb, 'taxa', $taxa['headers'], $taxa['rows']);

        // Export to SQL dump
        $sqlDump = "-- EAV Test Fixtures - 10 Records\n";
        $sqlDump .= "-- Generated from spec/fixtures/eav_10/ TSV files\n\n";

        foreach (['media', 'omoccurrences', 'omcollections', 'taxa'] as $table) {
            // Get schema
            $schema = $tempDb->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
            $sqlDump .= "$schema;\n\n";

            // Get data
            $rows = $tempDb->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';

                foreach ($rows as $row) {
                    $values = array_map(function($val) use ($tempDb) {
                        return $tempDb->quote($val);
                    }, array_values($row));
                    $sqlDump .= "INSERT INTO `$table` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sqlDump .= "\n";
            }
        }

        // Write SQL file
        $sqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures_10.sql';
        file_put_contents($sqlPath, $sqlDump);

        echo "    ✓ Written to spec/fixtures/images/eav/fixtures_10.sql\n";

        expect(file_exists($sqlPath))->toBe(true);
    });

    it('should create 20-record fixture subset', function() {
        // Create output directory
        if (!is_dir($this->outputDir20)) {
            mkdir($this->outputDir20, 0755, true);
        }

        echo "\n  Creating 20-record subset...\n";

        // Read all fixtures
        $media = ($this->readTsv)($this->fixturesDir . '/media.tsv');
        $omoccurrences = ($this->readTsv)($this->fixturesDir . '/omoccurrences.tsv');
        $omcollections = ($this->readTsv)($this->fixturesDir . '/omcollections.tsv');
        $taxa = ($this->readTsv)($this->fixturesDir . '/taxa.tsv');

        // Get first 20 unique occids from media
        $selectedOccids = [];
        $selectedMedia = [];
        foreach ($media['rows'] as $row) {
            if (count($selectedOccids) >= 20 && isset($row['occid']) && !in_array($row['occid'], $selectedOccids)) {
                break;
            }
            $selectedMedia[] = $row;
            if (isset($row['occid']) && $row['occid'] !== '' && !in_array($row['occid'], $selectedOccids)) {
                $selectedOccids[] = $row['occid'];
            }
        }

        echo "    Selected " . count($selectedMedia) . " media records with " . count($selectedOccids) . " unique occids\n";

        // Get related omoccurrences
        $selectedOmoccurrences = [];
        $selectedCollids = [];
        $selectedTids = [];
        foreach ($omoccurrences['rows'] as $row) {
            if (in_array($row['occid'], $selectedOccids)) {
                $selectedOmoccurrences[] = $row;
                if (isset($row['collid']) && $row['collid'] !== '') {
                    $selectedCollids[] = $row['collid'];
                }
                if (isset($row['tidInterpreted']) && $row['tidInterpreted'] !== '') {
                    $selectedTids[] = $row['tidInterpreted'];
                }
            }
        }

        echo "    Selected " . count($selectedOmoccurrences) . " omoccurrences records\n";

        // Get related omcollections
        $selectedOmcollections = [];
        foreach ($omcollections['rows'] as $row) {
            if (in_array($row['collID'], $selectedCollids)) {
                $selectedOmcollections[] = $row;
            }
        }

        echo "    Selected " . count($selectedOmcollections) . " omcollections records\n";

        // Get related taxa
        $selectedTaxa = [];
        foreach ($taxa['rows'] as $row) {
            if (in_array($row['tid'], $selectedTids)) {
                $selectedTaxa[] = $row;
            }
        }

        echo "    Selected " . count($selectedTaxa) . " taxa records\n";

        // Write files
        ($this->writeTsv)($this->outputDir20 . '/media.tsv', $media['headers'], $selectedMedia);
        ($this->writeTsv)($this->outputDir20 . '/omoccurrences.tsv', $omoccurrences['headers'], $selectedOmoccurrences);
        ($this->writeTsv)($this->outputDir20 . '/omcollections.tsv', $omcollections['headers'], $selectedOmcollections);
        ($this->writeTsv)($this->outputDir20 . '/taxa.tsv', $taxa['headers'], $selectedTaxa);

        // Create README
        $readme = <<<'README'
# EAV Test Fixtures - 20 Records

This directory contains a 20-record subset of the full EAV fixtures for unit testing.

## Contents

- `media.tsv` - 20+ media records (multiple images per occurrence)
- `omoccurrences.tsv` - 20 occurrence records
- `omcollections.tsv` - Related collection records
- `taxa.tsv` - Related taxonomy records

## Usage

These fixtures are designed for unit tests that need more data variation than the
10-record subset but still want fast execution.

## Generation

Generated from `spec/fixtures/eav/` using `spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php`
README;
        file_put_contents($this->outputDir20 . '/README.md', $readme);

        echo "    ✓ Written to spec/fixtures/eav_20/\n";

        // Verify files exist
        expect(file_exists($this->outputDir20 . '/media.tsv'))->toBe(true);
        expect(file_exists($this->outputDir20 . '/omoccurrences.tsv'))->toBe(true);
        expect(file_exists($this->outputDir20 . '/omcollections.tsv'))->toBe(true);
        expect(file_exists($this->outputDir20 . '/taxa.tsv'))->toBe(true);
        expect(file_exists($this->outputDir20 . '/README.md'))->toBe(true);

        // Verify record counts
        expect(count($selectedMedia))->toBeGreaterThan(0);
        expect(count($selectedOccids))->toBe(20);
        expect(count($selectedOmoccurrences))->toBe(20);
    });

    it('should create 20-record SQL fixture', function() {
        echo "\n  Creating 20-record SQL fixture...\n";

        // Create temp database from TSV fixtures
        $tempDb = new PDO('sqlite::memory:');
        $tempDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load TSV data
        $media = ($this->readTsv)($this->outputDir20 . '/media.tsv');
        $omoccurrences = ($this->readTsv)($this->outputDir20 . '/omoccurrences.tsv');
        $omcollections = ($this->readTsv)($this->outputDir20 . '/omcollections.tsv');
        $taxa = ($this->readTsv)($this->outputDir20 . '/taxa.tsv');

        // Create tables and insert data
        $createAndInsert = function($db, $tableName, $headers, $rows) {
            // Create table
            $columns = array_map(function($col) {
                return "`{$col}` TEXT";
            }, $headers);
            $db->exec("CREATE TABLE `{$tableName}` (" . implode(', ', $columns) . ")");

            // Insert rows
            if (count($rows) > 0) {
                $placeholders = array_fill(0, count($headers), '?');
                $sql = "INSERT INTO `{$tableName}` VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $db->prepare($sql);

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($headers as $header) {
                        $values[] = $row[$header] ?? '';
                    }
                    $stmt->execute($values);
                }
            }
        };

        $createAndInsert($tempDb, 'media', $media['headers'], $media['rows']);
        $createAndInsert($tempDb, 'omoccurrences', $omoccurrences['headers'], $omoccurrences['rows']);
        $createAndInsert($tempDb, 'omcollections', $omcollections['headers'], $omcollections['rows']);
        $createAndInsert($tempDb, 'taxa', $taxa['headers'], $taxa['rows']);

        // Export to SQL dump
        $sqlDump = "-- EAV Test Fixtures - 20 Records\n";
        $sqlDump .= "-- Generated from spec/fixtures/eav_20/ TSV files\n\n";

        foreach (['media', 'omoccurrences', 'omcollections', 'taxa'] as $table) {
            // Get schema
            $schema = $tempDb->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
            $sqlDump .= "$schema;\n\n";

            // Get data
            $rows = $tempDb->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';

                foreach ($rows as $row) {
                    $values = array_map(function($val) use ($tempDb) {
                        return $tempDb->quote($val);
                    }, array_values($row));
                    $sqlDump .= "INSERT INTO `$table` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sqlDump .= "\n";
            }
        }

        // Write SQL file
        $sqlPath = __DIR__ . '/../../fixtures/images/eav/fixtures_20.sql';
        file_put_contents($sqlPath, $sqlDump);

        echo "    ✓ Written to spec/fixtures/images/eav/fixtures_20.sql\n";

        expect(file_exists($sqlPath))->toBe(true);
    });

});

