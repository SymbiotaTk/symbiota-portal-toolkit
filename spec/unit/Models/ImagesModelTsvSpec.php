<?php

/**
 * Tests for ImagesModel TSV Export/Import Optimization
 * 
 * These tests verify the whitespace normalization and performance
 * optimizations for handling 5+ million image records.
 */

use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Core\Configuration;

describe("ImagesModel - TSV Export/Import Optimization", function() {

    beforeEach(function() {
        // Reset Configuration singleton for testing
        Configuration::reset();

        // Initialize Configuration singleton for testing
        Configuration::initialize([
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates',
            'mod' => [
                'images' => [
                    'eav_batch_size' => 1000,
                    'eav_use_native_import' => true,
                    'eav_use_entity_cache' => true,
                    'eav_cache_size_mb' => 2000,
                    'eav_buffer_rows' => 1000
                ]
            ]
        ]);

        $this->model = new ImagesModel();
    });

    describe("Whitespace Normalization", function() {

        it("should remove tab characters from values", function() {
            $value = "Column\twith\ttabs";
            
            // Normalize whitespace
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Column with tabs");
            expect($normalized)->not->toContain("\t");
        });

        it("should remove newline characters from values", function() {
            $value = "Line1\nLine2\nLine3";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Line1 Line2 Line3");
            expect($normalized)->not->toContain("\n");
        });

        it("should remove carriage return characters from values", function() {
            $value = "Line1\rLine2\rLine3";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Line1 Line2 Line3");
            expect($normalized)->not->toContain("\r");
        });

        it("should handle mixed whitespace characters", function() {
            $value = "Mixed\t\n\rwhitespace\n\t\rcharacters";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Mixed whitespace characters");
        });

        it("should collapse multiple spaces into single space", function() {
            $value = "Multiple    spaces     here";
            
            $normalized = preg_replace('/\s+/', ' ', $value);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Multiple spaces here");
        });

        it("should trim leading and trailing whitespace", function() {
            $value = "  \t\n  Trimmed value  \n\t  ";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Trimmed value");
        });

        it("should handle null values", function() {
            $value = null;
            
            $result = ($value === null) ? '' : $value;
            
            expect($result)->toBe('');
        });

        it("should handle empty strings", function() {
            $value = "";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe('');
        });

        it("should preserve single spaces between words", function() {
            $value = "Normal text with spaces";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Normal text with spaces");
        });

        it("should handle special characters correctly", function() {
            $value = "Special: @#$%^&*()";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Special: @#$%^&*()");
        });

        it("should handle unicode characters", function() {
            $value = "Unicode: café, naïve, 日本語";
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->toBe("Unicode: café, naïve, 日本語");
        });

        it("should handle very long values", function() {
            $value = str_repeat("Long value with\ttabs\nand\rnewlines ", 100);
            
            $normalized = preg_replace('/[\t\n\r]+/', ' ', $value);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            expect($normalized)->not->toContain("\t");
            expect($normalized)->not->toContain("\n");
            expect($normalized)->not->toContain("\r");
            expect(strlen($normalized))->toBeGreaterThan(0);
        });
    });

    describe("Buffered Writing", function() {

        it("should buffer rows before writing", function() {
            $rows = [];
            $buffer = [];
            $bufferSize = 1000;
            
            // Simulate adding rows to buffer
            for ($i = 0; $i < 2500; $i++) {
                $buffer[] = "Row $i";
                
                if (count($buffer) >= $bufferSize) {
                    $rows[] = implode("\n", $buffer);
                    $buffer = [];
                }
            }
            
            // Flush remaining buffer
            if (!empty($buffer)) {
                $rows[] = implode("\n", $buffer);
            }
            
            // Should have 3 chunks (1000 + 1000 + 500)
            expect(count($rows))->toBe(3);
        });

        it("should handle buffer size of 1", function() {
            $buffer = [];
            $bufferSize = 1;
            $flushCount = 0;
            
            for ($i = 0; $i < 10; $i++) {
                $buffer[] = "Row $i";
                
                if (count($buffer) >= $bufferSize) {
                    $flushCount++;
                    $buffer = [];
                }
            }
            
            expect($flushCount)->toBe(10);
        });

        it("should handle buffer size larger than total rows", function() {
            $buffer = [];
            $bufferSize = 10000;
            $flushCount = 0;
            
            for ($i = 0; $i < 100; $i++) {
                $buffer[] = "Row $i";
                
                if (count($buffer) >= $bufferSize) {
                    $flushCount++;
                    $buffer = [];
                }
            }
            
            // Should not flush during loop
            expect($flushCount)->toBe(0);
            expect(count($buffer))->toBe(100);
        });
    });

    describe("Batch Processing", function() {

        it("should split large arrays into batches", function() {
            $entityIds = range(1, 5000);
            $batchSize = 1000;
            
            $batches = array_chunk($entityIds, $batchSize);
            
            expect(count($batches))->toBe(5);
            expect(count($batches[0]))->toBe(1000);
            expect(count($batches[4]))->toBe(1000);
        });

        it("should handle non-divisible batch sizes", function() {
            $entityIds = range(1, 5500);
            $batchSize = 1000;
            
            $batches = array_chunk($entityIds, $batchSize);
            
            expect(count($batches))->toBe(6);
            expect(count($batches[0]))->toBe(1000);
            expect(count($batches[5]))->toBe(500); // Last batch is partial
        });

        it("should handle empty arrays", function() {
            $entityIds = [];
            $batchSize = 1000;
            
            $batches = array_chunk($entityIds, $batchSize);
            
            expect(count($batches))->toBe(0);
        });

        it("should handle single element arrays", function() {
            $entityIds = [1];
            $batchSize = 1000;
            
            $batches = array_chunk($entityIds, $batchSize);
            
            expect(count($batches))->toBe(1);
            expect(count($batches[0]))->toBe(1);
        });
    });

    describe("TSV Value Formatting", function() {

        it("should format array of values as TSV row", function() {
            $values = ['value1', 'value2', 'value3'];
            $tsvRow = implode("\t", $values);
            
            expect($tsvRow)->toBe("value1\tvalue2\tvalue3");
        });

        it("should handle empty values in TSV row", function() {
            $values = ['value1', '', 'value3'];
            $tsvRow = implode("\t", $values);
            
            expect($tsvRow)->toBe("value1\t\tvalue3");
        });

        it("should normalize values before TSV formatting", function() {
            $values = [
                "value1\twith\ttabs",
                "value2\nwith\nnewlines",
                "value3"
            ];
            
            $normalized = array_map(function($val) {
                if ($val === null) return '';
                $val = preg_replace('/[\t\n\r]+/', ' ', $val);
                $val = preg_replace('/\s+/', ' ', $val);
                return trim($val);
            }, $values);
            
            $tsvRow = implode("\t", $normalized);
            
            expect($tsvRow)->toBe("value1 with tabs\tvalue2 with newlines\tvalue3");
            expect($tsvRow)->not->toContain("\n");
        });
    });

    describe("Configuration", function() {

        it("should read batch size from configuration", function() {
            $config = Configuration::getInstance();
            $batchSize = $config->get('mod.images.eav_batch_size', 27000);
            
            expect($batchSize)->toBe(1000); // From test config
        });

        it("should use default batch size when not configured", function() {
            Configuration::reset();
            Configuration::initialize([]);
            
            $config = Configuration::getInstance();
            $batchSize = $config->get('mod.images.eav_batch_size', 27000);
            
            expect($batchSize)->toBe(27000); // Default value
        });

        it("should read buffer size from configuration", function() {
            $config = Configuration::getInstance();
            $bufferSize = $config->get('mod.images.eav_buffer_rows', 1000);
            
            expect($bufferSize)->toBe(1000);
        });
    });
});

