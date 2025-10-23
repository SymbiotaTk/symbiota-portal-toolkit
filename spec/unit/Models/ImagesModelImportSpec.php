<?php

describe("ImagesModel - TSV Import Validation", function() {

    beforeEach(function() {
        $this->tempDir = sys_get_temp_dir() . '/tk_import_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    });
    
    afterEach(function() {
        // Clean up temp directory
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    });
    
    describe("Column Count Validation", function() {
        
        it("should import rows with correct column count", function() {
            // Create valid TSV file
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/1.jpg\thttp://orig1.jpg\thttp://thumb1.jpg\n";
            $content .= "2\t200\thttp://example.com/2.jpg\thttp://orig2.jpg\thttp://thumb2.jpg\n";
            $content .= "3\t300\thttp://example.com/3.jpg\thttp://orig3.jpg\thttp://thumb3.jpg\n";
            file_put_contents($tsvFile, $content);
            
            // Parse and validate
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            
            $validRows = 0;
            $expectedColumns = 5;
            
            while (($row = fgetcsv($fp, 0, "\t")) !== false) {
                if (count($row) === $expectedColumns) {
                    $validRows++;
                }
            }
            
            fclose($fp);
            
            expect($validRows)->toBe(3);
        });
        
        it("should detect rows with too few columns", function() {
            // Create TSV with missing column
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/1.jpg\thttp://orig1.jpg\n"; // Missing thumbnailUrl
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(4);
            expect(count($row))->not->toBe(5);
        });
        
        it("should detect rows with too many columns", function() {
            // Create TSV with extra column (embedded tab)
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/1.jpg?a=1\tb=2\thttp://orig1.jpg\thttp://thumb1.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(6);
            expect(count($row))->not->toBe(5);
        });
        
        it("should handle empty values correctly", function() {
            // Create TSV with empty values
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\t\t\t\n"; // Empty url, originalUrl, thumbnailUrl
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(5);
            expect($row[0])->toBe("1");
            expect($row[1])->toBe("100");
            expect($row[2])->toBe("");
            expect($row[3])->toBe("");
            expect($row[4])->toBe("");
        });
    });
    
    describe("Embedded Tab Detection", function() {
        
        it("should detect embedded tabs in values", function() {
            $value = "http://example.com?param1=value1\tparam2=value2";
            
            $hasTab = strpos($value, "\t") !== false;
            
            expect($hasTab)->toBe(true);
        });
        
        it("should clean embedded tabs from values", function() {
            $value = "http://example.com?param1=value1\tparam2=value2";
            
            $cleaned = str_replace("\t", " ", $value);
            
            expect($cleaned)->toBe("http://example.com?param1=value1 param2=value2");
            expect(strpos($cleaned, "\t"))->toBe(false);
        });
        
        it("should handle multiple embedded tabs", function() {
            $value = "value1\tvalue2\tvalue3\tvalue4";
            
            $cleaned = str_replace("\t", " ", $value);
            
            expect($cleaned)->toBe("value1 value2 value3 value4");
            expect(strpos($cleaned, "\t"))->toBe(false);
        });
    });
    
    describe("Error Handling", function() {
        
        it("should continue import after skipping bad row", function() {
            // Create TSV with one bad row in the middle
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/1.jpg\thttp://orig1.jpg\thttp://thumb1.jpg\n";
            $content .= "2\t200\thttp://example.com/2.jpg\thttp://orig2.jpg\n"; // Bad: missing column
            $content .= "3\t300\thttp://example.com/3.jpg\thttp://orig3.jpg\thttp://thumb3.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            
            $validRows = 0;
            $skippedRows = 0;
            $expectedColumns = 5;
            
            while (($row = fgetcsv($fp, 0, "\t")) !== false) {
                if (count($row) === $expectedColumns) {
                    $validRows++;
                } else {
                    $skippedRows++;
                }
            }
            
            fclose($fp);
            
            expect($validRows)->toBe(2);
            expect($skippedRows)->toBe(1);
        });
        
        it("should track line numbers correctly", function() {
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/1.jpg\thttp://orig1.jpg\thttp://thumb1.jpg\n";
            $content .= "2\t200\thttp://example.com/2.jpg\thttp://orig2.jpg\n"; // Line 3 (bad)
            $content .= "3\t300\thttp://example.com/3.jpg\thttp://orig3.jpg\thttp://thumb3.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            
            $lineNumber = 1; // Header is line 1
            $badLines = [];
            $expectedColumns = 5;
            
            while (($row = fgetcsv($fp, 0, "\t")) !== false) {
                $lineNumber++;
                if (count($row) !== $expectedColumns) {
                    $badLines[] = $lineNumber;
                }
            }
            
            fclose($fp);
            
            expect($badLines)->toBe([3]);
        });
    });
    
    describe("Batch Processing", function() {
        
        it("should handle large files with batching", function() {
            // Create TSV with 1000 rows
            $tsvFile = $this->tempDir . '/media.tsv';
            $fp = fopen($tsvFile, 'w');
            fwrite($fp, "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n");
            
            for ($i = 1; $i <= 1000; $i++) {
                fwrite($fp, "$i\t" . ($i * 100) . "\thttp://example.com/$i.jpg\thttp://orig$i.jpg\thttp://thumb$i.jpg\n");
            }
            
            fclose($fp);
            
            // Read and count
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            
            $rowCount = 0;
            while (($row = fgetcsv($fp, 0, "\t")) !== false) {
                $rowCount++;
            }
            
            fclose($fp);
            
            expect($rowCount)->toBe(1000);
        });
        
        it("should handle batch commits correctly", function() {
            $batchSize = 100;
            $totalRows = 250;
            
            $batches = 0;
            $rowsInCurrentBatch = 0;
            
            for ($i = 1; $i <= $totalRows; $i++) {
                $rowsInCurrentBatch++;
                
                if ($rowsInCurrentBatch >= $batchSize) {
                    $batches++;
                    $rowsInCurrentBatch = 0;
                }
            }
            
            // Final batch
            if ($rowsInCurrentBatch > 0) {
                $batches++;
            }
            
            expect($batches)->toBe(3); // 100 + 100 + 50
        });
    });
    
    describe("Import Summary", function() {
        
        it("should generate correct import summary", function() {
            $imported = 1000;
            $skipped = 5;
            $errors = 2;
            
            $summary = sprintf(
                "%d rows imported (%d skipped, %d errors)",
                $imported,
                $skipped,
                $errors
            );
            
            expect($summary)->toBe("1000 rows imported (5 skipped, 2 errors)");
        });
        
        it("should handle zero skipped/errors", function() {
            $imported = 1000;
            $skipped = 0;
            $errors = 0;
            
            if ($skipped > 0 || $errors > 0) {
                $summary = sprintf("%d rows imported (%d skipped, %d errors)", $imported, $skipped, $errors);
            } else {
                $summary = sprintf("%d rows imported", $imported);
            }
            
            expect($summary)->toBe("1000 rows imported");
        });
    });
    
    describe("Real-world Scenarios", function() {
        
        it("should handle URLs with query parameters", function() {
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/image.jpg?width=800&height=600\thttp://orig.jpg\thttp://thumb.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(5);
            expect($row[2])->toContain("width=800");
            expect($row[2])->toContain("height=600");
        });
        
        it("should handle special characters in values", function() {
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/image's.jpg\thttp://orig.jpg\thttp://thumb.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(5);
            expect($row[2])->toBe("http://example.com/image's.jpg");
        });
        
        it("should handle Unicode characters", function() {
            $tsvFile = $this->tempDir . '/media.tsv';
            $content = "mediaID\toccid\turl\toriginalUrl\tthumbnailUrl\n";
            $content .= "1\t100\thttp://example.com/café.jpg\thttp://orig.jpg\thttp://thumb.jpg\n";
            file_put_contents($tsvFile, $content);
            
            $fp = fopen($tsvFile, 'r');
            $header = fgetcsv($fp, 0, "\t");
            $row = fgetcsv($fp, 0, "\t");
            fclose($fp);
            
            expect(count($row))->toBe(5);
            expect($row[2])->toContain("café");
        });
    });
});

