<?php

use Symbiota\Helpers\Core\Environment;

describe('Environment Format Detection', function() {

    describe('CLI format detection', function() {

        it('should detect CLI format when running from command line', function() {
            // This test runs in CLI context via Kahlan
            $env = Environment::getInstance();
            
            expect(Environment::isCli())->toBe(true);
            expect($env->getResponseFormat())->toBe('cli');
        });

        it('should report correct SAPI name', function() {
            expect(php_sapi_name())->toBe('cli');
        });

        it('should detect CLI environment properties', function() {
            $env = Environment::getInstance();
            
            // In CLI, these should be true
            expect(Environment::isCli())->toBe(true);
            expect(Environment::isHttp())->toBe(false);
        });
    });

    describe('Format override via parameters', function() {

        it('should allow format override via params', function() {
            // Simulate what happens when params['format'] is set
            $params = ['format' => 'json'];
            
            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
            
            expect($format)->toBe('json');
        });

        it('should use Environment format when params format is not set', function() {
            $params = [];
            
            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
            
            expect($format)->toBe('cli');
        });
    });

    describe('Response format detection logic', function() {

        it('should correctly determine isCli flag from format', function() {
            $env = Environment::getInstance();

            // Test the logic used in ImagesModelHybrid
            $params = [];
            $format = $params['format'] ?? $env->getResponseFormat();
            $isCli = ($format === 'cli');

            expect($isCli)->toBe(true);
        });

        it('should correctly determine isCli flag when format is overridden', function() {
            // Test with format override
            $params = ['format' => 'htmx'];
            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
            $isCli = ($format === 'cli');

            expect($isCli)->toBe(false);
        });
    });

    describe('Search command format detection', function() {

        it('should use CLI format for search command without format param', function() {
            // Simulate: php index.php images search --query="taxon:cronartium"
            // This is what the router passes to the model
            $params = [
                'query' => 'taxon:cronartium',
                'limit' => 5
                // Note: no 'format' key
            ];

            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
            $isCli = ($format === 'cli');

            expect($format)->toBe('cli');
            expect($isCli)->toBe(true);
        });

        it('should detect what format the search would return', function() {
            // This simulates the actual flow in ImagesModelHybrid::search()
            $params = ['query' => 'taxon:cronartium'];

            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();

            // Log for debugging
            echo "\nDetected format: {$format}\n";
            echo "Environment::isCli(): " . (Environment::isCli() ? 'true' : 'false') . "\n";
            echo "php_sapi_name(): " . php_sapi_name() . "\n";

            expect($format)->toBe('cli');
        });

        it('should return CLI response type for search results', function() {
            // Simulate the logic in ImagesModelHybrid::search()
            $params = ['query' => 'taxon:cronartium'];
            $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
            $isCli = ($format === 'cli');

            // Simulate what the model would return
            if ($isCli) {
                $response = [
                    'type' => 'cli',
                    'content' => 'Search results for: taxon:cronartium'
                ];
            } else {
                $response = [
                    'type' => 'htmx',
                    'content' => '<div>Search results</div>'
                ];
            }

            expect($response['type'])->toBe('cli');
            expect($response['content'])->toContain('Search results for:');
        });
    });
});

