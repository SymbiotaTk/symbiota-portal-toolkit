<?php

use Symbiota\Helpers\Core\TemplateFormat;

describe('TemplateFormat', function() {

    describe('enum values', function() {
        it('should define HTML format', function() {
            expect(TemplateFormat::HTML)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::HTML->name)->toBe('HTML');
            expect(TemplateFormat::HTML->value)->toBe('html');
        });

        it('should define JSON format', function() {
            expect(TemplateFormat::JSON)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::JSON->name)->toBe('JSON');
            expect(TemplateFormat::JSON->value)->toBe('json');
        });

        it('should define TEXT format', function() {
            expect(TemplateFormat::TEXT)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::TEXT->name)->toBe('TEXT');
            expect(TemplateFormat::TEXT->value)->toBe('text');
        });

        it('should define XML format', function() {
            expect(TemplateFormat::XML)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::XML->name)->toBe('XML');
            expect(TemplateFormat::XML->value)->toBe('xml');
        });

        it('should define CSV format', function() {
            expect(TemplateFormat::CSV)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::CSV->name)->toBe('CSV');
            expect(TemplateFormat::CSV->value)->toBe('csv');
        });

        it('should define CLI format', function() {
            expect(TemplateFormat::CLI)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::CLI->name)->toBe('CLI');
            expect(TemplateFormat::CLI->value)->toBe('cli');
        });

        it('should define SQL format', function() {
            expect(TemplateFormat::SQL)->toBeAnInstanceOf(TemplateFormat::class);
            expect(TemplateFormat::SQL->name)->toBe('SQL');
            expect(TemplateFormat::SQL->value)->toBe('sql');
        });
    });

    describe('enum comparison', function() {
        it('should allow equality comparison', function() {
            $format1 = TemplateFormat::HTML;
            $format2 = TemplateFormat::HTML;
            $format3 = TemplateFormat::JSON;

            expect($format1 === $format2)->toBe(true);
            expect($format1 === $format3)->toBe(false);
        });

        it('should work with match expressions', function() {
            $format = TemplateFormat::JSON;

            $result = match($format) {
                TemplateFormat::HTML => 'text/html',
                TemplateFormat::JSON => 'application/json',
                TemplateFormat::TEXT => 'text/plain',
                TemplateFormat::XML => 'application/xml',
                TemplateFormat::CSV => 'text/csv'
            };

            expect($result)->toBe('application/json');
        });

        it('should work with switch statements', function() {
            $format = TemplateFormat::XML;
            $contentType = '';

            switch($format) {
                case TemplateFormat::HTML:
                    $contentType = 'text/html';
                    break;
                case TemplateFormat::JSON:
                    $contentType = 'application/json';
                    break;
                case TemplateFormat::TEXT:
                    $contentType = 'text/plain';
                    break;
                case TemplateFormat::XML:
                    $contentType = 'application/xml';
                    break;
                case TemplateFormat::CSV:
                    $contentType = 'text/csv';
                    break;
            }

            expect($contentType)->toBe('application/xml');
        });
    });

    describe('enum methods', function() {
        it('should provide cases() method', function() {
            $cases = TemplateFormat::cases();

            expect($cases)->toBeA('array');
            expect(count($cases))->toBe(7); // CLI, HTML, SQL, TEXT, JSON, XML, CSV

            foreach ($cases as $case) {
                expect($case)->toBeAnInstanceOf(TemplateFormat::class);
            }
        });

        it('should have name property for all cases', function() {
            $cases = TemplateFormat::cases();
            $expectedNames = ['CLI', 'HTML', 'SQL', 'TEXT', 'JSON', 'XML', 'CSV'];

            $actualNames = array_map(fn($case) => $case->name, $cases);

            foreach ($expectedNames as $name) {
                expect(in_array($name, $actualNames))->toBe(true);
            }
        });
    });

    describe('format semantics', function() {
        it('should represent different output formats', function() {
            // HTML: Web pages and fragments
            $html = TemplateFormat::HTML;
            expect($html->name)->toBe('HTML');

            // JSON: API responses and data
            $json = TemplateFormat::JSON;
            expect($json->name)->toBe('JSON');

            // TEXT: Plain text output
            $text = TemplateFormat::TEXT;
            expect($text->name)->toBe('TEXT');

            // XML: Structured data
            $xml = TemplateFormat::XML;
            expect($xml->name)->toBe('XML');

            // CSV: Tabular data
            $csv = TemplateFormat::CSV;
            expect($csv->name)->toBe('CSV');
        });
    });

    describe('serialization', function() {
        it('should be serializable', function() {
            $format = TemplateFormat::HTML;
            $serialized = serialize($format);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBe($format);
            expect($unserialized->name)->toBe('HTML');
        });

        it('should work with json encoding', function() {
            $format = TemplateFormat::JSON;
            $json = json_encode(['format' => $format->name]);
            $decoded = json_decode($json, true);

            expect($decoded['format'])->toBe('JSON');
        });
    });

    describe('type checking', function() {
        it('should work with instanceof', function() {
            $format = TemplateFormat::TEXT;

            expect($format instanceof TemplateFormat)->toBe(true);
            expect($format instanceof \BackedEnum)->toBe(true); // IS a backed enum (has string values)
            expect($format instanceof \UnitEnum)->toBe(true); // Also implements UnitEnum
        });

        it('should work with is_a function', function() {
            $format = TemplateFormat::CSV;

            expect(is_a($format, TemplateFormat::class))->toBe(true);
            expect(is_a($format, \UnitEnum::class))->toBe(true);
        });
    });

    describe('array operations', function() {
        it('should work as array keys using values', function() {
            // Backed enums can be used as array keys by using their values
            $contentTypes = [
                TemplateFormat::HTML->value => 'text/html',
                TemplateFormat::JSON->value => 'application/json',
                TemplateFormat::TEXT->value => 'text/plain',
                TemplateFormat::XML->value => 'application/xml',
                TemplateFormat::CSV->value => 'text/csv'
            ];

            expect($contentTypes[TemplateFormat::HTML->value])->toBe('text/html');
            expect($contentTypes[TemplateFormat::JSON->value])->toBe('application/json');
            expect($contentTypes[TemplateFormat::TEXT->value])->toBe('text/plain');
            expect($contentTypes[TemplateFormat::XML->value])->toBe('application/xml');
            expect($contentTypes[TemplateFormat::CSV->value])->toBe('text/csv');
        });

        it('should work with in_array', function() {
            $webFormats = [TemplateFormat::HTML, TemplateFormat::JSON];

            expect(in_array(TemplateFormat::HTML, $webFormats, true))->toBe(true);
            expect(in_array(TemplateFormat::JSON, $webFormats, true))->toBe(true);
            expect(in_array(TemplateFormat::CSV, $webFormats, true))->toBe(false);
        });

        it('should work with array_filter', function() {
            $allFormats = TemplateFormat::cases();
            $textFormats = array_filter($allFormats, function($format) {
                return in_array($format, [TemplateFormat::TEXT, TemplateFormat::CSV]);
            });

            expect(count($textFormats))->toBe(2);
        });
    });

    describe('string representation', function() {
        it('should have meaningful string representation', function() {
            // Backed enums can be converted to string using their value property
            $html = TemplateFormat::HTML->value;
            $json = TemplateFormat::JSON->value;
            $text = TemplateFormat::TEXT->value;
            $xml = TemplateFormat::XML->value;
            $csv = TemplateFormat::CSV->value;
            $cli = TemplateFormat::CLI->value;
            $sql = TemplateFormat::SQL->value;

            // All should be strings
            expect($html)->toBeA('string');
            expect($json)->toBeA('string');
            expect($text)->toBeA('string');
            expect($xml)->toBeA('string');
            expect($csv)->toBeA('string');
            expect($cli)->toBeA('string');
            expect($sql)->toBeA('string');

            // All should be different
            $formats = [$html, $json, $text, $xml, $csv, $cli, $sql];
            $unique = array_unique($formats);
            expect(count($unique))->toBe(7); // All 7 formats should be unique
        });
    });

    describe('practical usage', function() {
        it('should work in template engine context', function() {
            $getFileExtension = function(TemplateFormat $format): string {
                return match($format) {
                    TemplateFormat::HTML => '.html',
                    TemplateFormat::JSON => '.json',
                    TemplateFormat::TEXT => '.txt',
                    TemplateFormat::XML => '.xml',
                    TemplateFormat::CSV => '.csv'
                };
            };

            expect($getFileExtension(TemplateFormat::HTML))->toBe('.html');
            expect($getFileExtension(TemplateFormat::JSON))->toBe('.json');
            expect($getFileExtension(TemplateFormat::TEXT))->toBe('.txt');
            expect($getFileExtension(TemplateFormat::XML))->toBe('.xml');
            expect($getFileExtension(TemplateFormat::CSV))->toBe('.csv');
        });

        it('should work in content type determination', function() {
            $getContentType = function(TemplateFormat $format): string {
                return match($format) {
                    TemplateFormat::HTML => 'text/html; charset=utf-8',
                    TemplateFormat::JSON => 'application/json; charset=utf-8',
                    TemplateFormat::TEXT => 'text/plain; charset=utf-8',
                    TemplateFormat::XML => 'application/xml; charset=utf-8',
                    TemplateFormat::CSV => 'text/csv; charset=utf-8'
                };
            };

            expect($getContentType(TemplateFormat::HTML))->toContain('text/html');
            expect($getContentType(TemplateFormat::JSON))->toContain('application/json');
            expect($getContentType(TemplateFormat::TEXT))->toContain('text/plain');
            expect($getContentType(TemplateFormat::XML))->toContain('application/xml');
            expect($getContentType(TemplateFormat::CSV))->toContain('text/csv');
        });
    });

    describe('enum methods', function() {
        it('should provide getExtension() method', function() {
            expect(TemplateFormat::HTML->getExtension())->toBe('html');
            expect(TemplateFormat::JSON->getExtension())->toBe('json');
            expect(TemplateFormat::TEXT->getExtension())->toBe('txt');
            expect(TemplateFormat::XML->getExtension())->toBe('xml');
            expect(TemplateFormat::CSV->getExtension())->toBe('csv');
            expect(TemplateFormat::CLI->getExtension())->toBe('txt');
            expect(TemplateFormat::SQL->getExtension())->toBe('sql');
        });

        it('should provide getDirectory() method', function() {
            expect(TemplateFormat::HTML->getDirectory())->toBe('html');
            expect(TemplateFormat::JSON->getDirectory())->toBe('json');
            expect(TemplateFormat::TEXT->getDirectory())->toBe('text');
            expect(TemplateFormat::XML->getDirectory())->toBe('xml');
            expect(TemplateFormat::CSV->getDirectory())->toBe('csv');
            expect(TemplateFormat::CLI->getDirectory())->toBe('cli');
            expect(TemplateFormat::SQL->getDirectory())->toBe('sql');
        });

        it('should provide getMimeType() method', function() {
            expect(TemplateFormat::HTML->getMimeType())->toBe('text/html');
            expect(TemplateFormat::JSON->getMimeType())->toBe('application/json');
            expect(TemplateFormat::TEXT->getMimeType())->toBe('text/plain');
            expect(TemplateFormat::XML->getMimeType())->toBe('application/xml');
            expect(TemplateFormat::CSV->getMimeType())->toBe('text/csv');
            expect(TemplateFormat::CLI->getMimeType())->toBe('text/plain');
            expect(TemplateFormat::SQL->getMimeType())->toBe('text/plain');
        });
    });

    describe('edge cases', function() {
        it('should handle null comparison', function() {
            $format = TemplateFormat::HTML;

            expect($format === null)->toBe(false);
            expect($format !== null)->toBe(true);
        });

        it('should handle type coercion', function() {
            $format = TemplateFormat::JSON;

            // Should not be equal to strings
            expect($format == 'JSON')->toBe(false);
            expect($format === 'JSON')->toBe(false);
        });
    });
});
