<?php

use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\UriParser;
use Symbiota\Helpers\Core\DiscoveringRouter;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\Configuration;

describe('Routing Debug', function() {
    
    describe('URI parsing for subdirectory deployment', function() {
        
        it('should parse genbank route correctly', function() {
            // Simulate the problematic request
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/myco/help/?/genbank',
                'SCRIPT_NAME' => '/myco/help/index.php',
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080'
            ];
            
            $request = Request::fromGlobals();
            $route = $request->getRoute();
            
            expect($route)->toBeA('array');
            expect($route['elements'])->toBeA('array');
            expect(count($route['elements']))->toBeGreaterThan(0);
            
            // The first element should be 'genbank'
            expect($route['elements'][0]['name'])->toBe('genbank');
        });
        
        it('should parse taxonomy-report route correctly', function() {
            // Simulate the problematic request
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/myco/help/?/taxonomy-report',
                'SCRIPT_NAME' => '/myco/help/index.php',
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080'
            ];
            
            $request = Request::fromGlobals();
            $route = $request->getRoute();
            
            expect($route)->toBeA('array');
            expect($route['elements'])->toBeA('array');
            expect(count($route['elements']))->toBeGreaterThan(0);
            
            // The first element should be 'taxonomy-report'
            expect($route['elements'][0]['name'])->toBe('taxonomy-report');
        });
        
        it('should parse root route correctly', function() {
            // Simulate the root request that works
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/myco/help/?/',
                'SCRIPT_NAME' => '/myco/help/index.php',
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080'
            ];
            
            $request = Request::fromGlobals();
            $route = $request->getRoute();
            
            expect($route)->toBeA('array');
            expect($route['elements'])->toBeA('array');
            // Root should have empty elements
            expect(count($route['elements']))->toBe(0);
        });
        
        it('should handle UriParser correctly for subdirectory', function() {
            $parser = UriParser::parse('/myco/help/?/genbank');
            
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
        });
    });
    
    describe('Model discovery integration', function() {
        
        it('should find genbank model', function() {
            $discovery = new ModelDiscovery();

            expect($discovery->modelExists('genbank'))->toBe(true);
            expect($discovery->isModelEnabled('genbank'))->toBe(true);
        });

        it('should find taxonomy-report model', function() {
            $discovery = new ModelDiscovery();

            expect($discovery->modelExists('taxonomy-report'))->toBe(true);
            expect($discovery->isModelEnabled('taxonomy-report'))->toBe(true);
        });
    });
    
    describe('Router integration', function() {
        
        it('should route genbank request correctly', function() {
            // Set up the environment
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/myco/help/?/genbank',
                'SCRIPT_NAME' => '/myco/help/index.php',
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080'
            ];
            
            $discovery = new ModelDiscovery();
            $router = new DiscoveringRouter($discovery);
            $request = Request::fromGlobals();
            
            $response = $router->handleRequest($request);
            
            expect($response)->toBeAnInstanceOf('Symbiota\Helpers\Core\Response');
            // Should not be an error response
            expect($response->getStatusCode())->not->toBe(404);
            expect($response->getStatusCode())->not->toBe(500);
        });
    });
});
