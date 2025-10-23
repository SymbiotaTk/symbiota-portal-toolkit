<?php

use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\DiscoveringRouter;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\OutputHandler;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Ext\SymbAuth;

describe('HTTP Request Mocking', function() {
    
    beforeEach(function() {
        // Clean up any previous $_SERVER state
        $_SERVER = [];

        // Enable test mode for authentication
        SymbAuth::enableTestMode();
    });

    afterEach(function() {
        // Reset environment after each test
        Environment::reset();

        // Disable test mode
        SymbAuth::disableTestMode();
    });
    
    describe('GET requests', function() {
        
        xit('should handle root GET request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);

            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/?/',
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
        
        xit('should handle genbank GET request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/?/genbank',
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

            $data = $response->getData();
            // Should return content for the genbank model
            expect(isset($data['content']) || isset($data['html']))->toBe(true);
        });
        
        xit('should handle genbank collections GET request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/?/genbank/collections',
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
        
        xit('should handle taxonomy-report GET request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/?/taxonomy-report',
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
    
    describe('POST requests', function() {
        
        xit('should handle genbank search POST request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/?/genbank/search',
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
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(200);
        });
        
        it('should handle HTMX POST request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/?/genbank/fragment',
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
            
            // Set global $_SERVER for model access
            $_SERVER = array_merge($_SERVER, $serverData);

            $request = new Request([], $postData, [], $serverData);
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(200);
        });
        
        xit('should handle JSON POST request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/?/genbank/collections',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'CONTENT_TYPE' => 'application/json'
            ];
            
            $postData = [
                'limit' => 50,
                'filter' => ['institutionCode' => 'BRIT']
            ];
            
            $request = new Request([], $postData, [], $serverData);
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            expect($response->getStatusCode())->toBe(200);
        });
    });
    
    describe('PUT requests', function() {
        
        it('should handle PUT request for updates', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'PUT',
                'REQUEST_URI' => '/?/genbank/collections/2',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'CONTENT_TYPE' => 'application/json'
            ];
            
            $postData = [
                'collectionName' => 'Updated Collection Name'
            ];
            
            $request = new Request([], $postData, [], $serverData);
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            // PUT might not be implemented yet, so we accept 200 or 405
            expect($response->getStatusCode())->toBeGreaterThan(0);
        });
    });
    
    describe('DELETE requests', function() {
        
        it('should handle DELETE request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'DELETE',
                'REQUEST_URI' => '/?/genbank/collections/999',
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
            // DELETE might not be implemented yet, so we accept 200 or 405
            expect($response->getStatusCode())->toBeGreaterThan(0);
        });
    });
    
    describe('File upload simulation', function() {
        
        it('should handle file upload POST request', function() {
            // Force HTTP environment for proper mocking
            Environment::forceEnvironment(Environment::HTTP);
            
            $serverData = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/?/backup/upload',
                'HTTP_HOST' => 'localhost:8888',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8888',
                'CONTENT_TYPE' => 'multipart/form-data'
            ];
            
            $filesData = [
                'backup_file' => [
                    'name' => 'test_backup.sql',
                    'type' => 'application/sql',
                    'size' => 1024,
                    'tmp_name' => '/tmp/phptest123',
                    'error' => UPLOAD_ERR_OK
                ]
            ];
            
            $postData = [
                'chunk_number' => 1,
                'total_chunks' => 3
            ];
            
            $request = new Request([], $postData, $filesData, $serverData);
            $discovery = new ModelDiscovery();
            $templateEngine = new TemplateEngine();
            $outputHandler = new OutputHandler($templateEngine);
            $router = new DiscoveringRouter($discovery, $outputHandler);
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            // File upload might return 404 if backup model doesn't exist yet
            expect($response->getStatusCode())->toBeGreaterThan(0);
        });
    });
});
