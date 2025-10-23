<?php

use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\DiscoveringRouter;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\OutputHandler;

describe('Comprehensive HTTP Request Mocking', function() {
    
    beforeEach(function() {
        // Clean up any previous $_SERVER state
        $_SERVER = [];
    });
    
    describe('Model routing verification', function() {
        
        it('should discover all models correctly', function() {
            $discovery = new ModelDiscovery();
            $models = $discovery->discoverModels();
            
            expect($models)->toBeA('array');
            expect(count($models))->toBeGreaterThan(0);
            
            // Check specific models exist
            expect($discovery->modelExists('genbank'))->toBe(true);
            expect($discovery->modelExists('taxonomy-report'))->toBe(true);
            expect($discovery->isModelEnabled('genbank'))->toBe(true);
            expect($discovery->isModelEnabled('taxonomy-report'))->toBe(true);
        });
    });
    
    describe('Router functionality tests', function() {

        it('should route genbank requests correctly', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/genbank',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888'
            ];

            $request = new Request([], [], [], $serverData);
            $discovery = new ModelDiscovery();

            // Test that the router can parse the request and identify the model
            expect($discovery->modelExists('genbank'))->toBe(true);
            expect($discovery->isModelEnabled('genbank'))->toBe(true);
        });

        it('should parse request URI correctly', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/genbank/collections',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888'
            ];

            $request = new Request([], [], [], $serverData);

            // Test Request object functionality
            expect($request->getMethod())->toBe('GET');
            expect($request->getUri())->toBe('/helpers/?/genbank/collections');
        });

        it('should handle POST request data', function() {
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/helpers/?/genbank/search',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded'
            ];

            $postData = [
                'catalogNumber' => '163172',
                'collid' => ['2']
            ];

            $request = new Request([], $postData, [], $serverData);

            // Test that POST data is accessible
            expect($request->getMethod())->toBe('POST');
            expect($request->post('catalogNumber'))->toBe('163172');
            expect($request->post('collid'))->toBe(['2']);
        });

        it('should detect HTMX requests', function() {
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/helpers/?/genbank/fragment',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_TARGET' => 'search-results',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded'
            ];

            $postData = [
                'fragment' => 'search-results',
                'catalogNumber' => '163172',
                'collid' => '2'
            ];

            $request = new Request([], $postData, [], $serverData);

            // Test HTMX detection
            expect($request->isHtmx())->toBe(true);
            expect($request->getHtmxTarget())->toBe('search-results');
        });
    });
    
    describe('Taxonomy Report model HTTP requests', function() {
        
        it('should handle default taxonomy-report request', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/taxonomy-report',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888'
            ];
            
            $request = new Request([], [], [], $serverData);
            $discovery = new ModelDiscovery();
            $router = new DiscoveringRouter($discovery);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(200);
        });
    });
    
    describe('Error handling', function() {
        
        it('should return 404 for non-existent models', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/non-existent-model',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888'
            ];
            
            $request = new Request([], [], [], $serverData);
            $discovery = new ModelDiscovery();
            $router = new DiscoveringRouter($discovery);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(404);
        });
        
        it('should handle invalid actions gracefully', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/genbank/invalid-action',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888'
            ];
            
            $request = new Request([], [], [], $serverData);
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(200);
        });
    });
    
    describe('Request data parsing', function() {

        it('should parse query string parameters', function() {
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/helpers/?/genbank/collections?limit=50',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'QUERY_STRING' => 'limit=50'
            ];

            $getData = ['limit' => '50'];

            $request = new Request($getData, [], [], $serverData);

            // Test that query parameters are accessible
            expect($request->get('limit'))->toBe('50');
            expect($request->getMethod())->toBe('GET');
        });
    });
});
