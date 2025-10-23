<?php

use Symbiota\Helpers\Core\Response;

describe('Response', function() {
    
    describe('basic functionality', function() {
        
        it('should create response with data and status code', function() {
            $response = new Response(['message' => 'test'], 201);
            expect($response->getData())->toBe(['message' => 'test']);
            expect($response->getStatusCode())->toBe(201);
        });
        
        it('should default to 200 status code', function() {
            $response = new Response();
            expect($response->getStatusCode())->toBe(200);
        });
        
        it('should set and get data', function() {
            $response = new Response();
            $response->setData(['key' => 'value']);
            expect($response->getData())->toBe(['key' => 'value']);
        });
        
        it('should set and get status code', function() {
            $response = new Response();
            $response->setStatusCode(404);
            expect($response->getStatusCode())->toBe(404);
        });
        
    });
    
    describe('headers', function() {
        
        it('should set and get headers', function() {
            $response = new Response();
            $response->setHeader('X-Custom', 'test-value');
            expect($response->getHeader('X-Custom'))->toBe('test-value');
        });
        
        it('should return null for missing headers', function() {
            $response = new Response();
            expect($response->getHeader('Missing'))->toBeNull();
        });
        
        it('should set content type', function() {
            $response = new Response();
            $response->setContentType('text/html');
            expect($response->getHeader('Content-Type'))->toBe('text/html');
        });
        
    });
    
    describe('static factory methods', function() {
        
        it('should create JSON response', function() {
            $response = Response::json(['data' => 'test'], 201);
            expect($response->getData())->toBe(['data' => 'test']);
            expect($response->getStatusCode())->toBe(201);
            expect($response->getHeader('Content-Type'))->toBe('application/json');
        });
        
        it('should create HTML response', function() {
            $response = Response::html('<h1>Test</h1>');
            expect($response->getData()['html'])->toBe('<h1>Test</h1>');
            expect($response->getHeader('Content-Type'))->toBe('text/html');
        });
        
        it('should create CSV response', function() {
            $data = [['name', 'age'], ['John', 30], ['Jane', 25]];
            $response = Response::csv($data, 'users.csv');
            expect($response->getData()['csv_data'])->toBe($data);
            expect($response->getHeader('Content-Type'))->toBe('text/csv');
            expect($response->getHeader('Content-Disposition'))->toContain('users.csv');
        });
        
        it('should create error response', function() {
            $response = Response::error('Not found', 404);
            expect($response->getData()['error'])->toBe('Not found');
            expect($response->getData()['code'])->toBe(404);
            expect($response->getStatusCode())->toBe(404);
        });
        
        it('should create redirect response', function() {
            $response = Response::redirect('/dashboard', 302);
            expect($response->getData()['redirect'])->toBe('/dashboard');
            expect($response->getStatusCode())->toBe(302);
            expect($response->getHeader('Location'))->toBe('/dashboard');
        });
        
    });
    
    describe('response body generation', function() {
        
        it('should generate JSON body', function() {
            $response = Response::json(['message' => 'test']);
            $body = $response->getBody();
            $decoded = json_decode($body, true);
            expect($decoded['message'])->toBe('test');
        });
        
        it('should generate HTML body', function() {
            $response = Response::html('<h1>Test</h1>');
            $body = $response->getBody();
            expect($body)->toBe('<h1>Test</h1>');
        });
        
        it('should generate CSV body', function() {
            $data = [['name' => 'John', 'age' => 30], ['name' => 'Jane', 'age' => 25]];
            $response = Response::csv($data);
            $body = $response->getBody();
            expect($body)->toContain('name,age');
            expect($body)->toContain('John,30');
            expect($body)->toContain('Jane,25');
        });
        
    });
    
    describe('status checking', function() {
        
        it('should identify successful responses', function() {
            expect((new Response([], 200))->isSuccessful())->toBe(true);
            expect((new Response([], 201))->isSuccessful())->toBe(true);
            expect((new Response([], 299))->isSuccessful())->toBe(true);
            expect((new Response([], 300))->isSuccessful())->toBe(false);
            expect((new Response([], 404))->isSuccessful())->toBe(false);
        });
        
        it('should identify error responses', function() {
            expect((new Response([], 400))->isError())->toBe(true);
            expect((new Response([], 404))->isError())->toBe(true);
            expect((new Response([], 500))->isError())->toBe(true);
            expect((new Response([], 200))->isError())->toBe(false);
            expect((new Response([], 300))->isError())->toBe(false);
        });
        
    });
    
    describe('data manipulation', function() {
        
        it('should add data to response', function() {
            $response = new Response(['existing' => 'value']);
            $response->addData('new', 'data');
            expect($response->getData()['existing'])->toBe('value');
            expect($response->getData()['new'])->toBe('data');
        });
        
        it('should remove data from response', function() {
            $response = new Response(['keep' => 'this', 'remove' => 'this']);
            $response->removeData('remove');
            expect(array_key_exists('keep', $response->getData()))->toBe(true);
            expect(array_key_exists('remove', $response->getData()))->toBe(false);
        });
        
    });
    
    describe('HTMX support', function() {
        
        it('should set HTMX headers', function() {
            $response = new Response();
            $response->setHtmxHeaders(['Trigger' => 'refresh', 'Push-Url' => '/new-url']);
            expect($response->getHeader('HX-Trigger'))->toBe('refresh');
            expect($response->getHeader('HX-Push-Url'))->toBe('/new-url');
        });
        
        it('should set HTMX trigger', function() {
            $response = new Response();
            $response->setHtmxTrigger('dataUpdated');
            expect($response->getHeader('HX-Trigger'))->toBe('dataUpdated');
        });
        
        it('should set HTMX trigger with data', function() {
            $response = new Response();
            $response->setHtmxTrigger('dataUpdated', ['id' => 123]);
            $trigger = $response->getHeader('HX-Trigger');
            $decoded = json_decode($trigger, true);
            expect($decoded['dataUpdated']['id'])->toBe(123);
        });
        
        it('should set HTMX redirect', function() {
            $response = new Response();
            $response->setHtmxRedirect('/dashboard');
            expect($response->getHeader('HX-Redirect'))->toBe('/dashboard');
        });
        
    });

    describe('file responses with content detection', function() {

        it('should create file response with explicit MIME type', function() {
            $content = 'test content';
            $mimeType = 'text/plain';
            $response = Response::file($content, $mimeType);

            expect($response->getData())->toBe(['file_content' => $content]);
            expect($response->getHeader('Content-Type'))->toBe($mimeType);
        });

        it('should detect CSS content and set correct MIME type', function() {
            $cssContent = 'body { color: red; font-size: 14px; }';
            $response = Response::file($cssContent, 'application/octet-stream', 200, [], 'test.css');

            expect($response->getHeader('Content-Type'))->toBe('text/css');
            expect($response->getHeader('X-Content-Detection'))->toContain('css');
        });

        it('should detect JavaScript content and set correct MIME type', function() {
            $jsContent = 'function test() { console.log("hello"); }';
            $response = Response::file($jsContent, 'application/octet-stream', 200, [], 'test.js');

            expect($response->getHeader('Content-Type'))->toBe('application/javascript');
            expect($response->getHeader('X-Content-Detection'))->toContain('javascript');
        });

        it('should detect JSON content and set correct MIME type', function() {
            $jsonContent = '{"name": "test", "value": 123}';
            $response = Response::file($jsonContent, 'application/octet-stream', 200, [], 'test.json');

            expect($response->getHeader('Content-Type'))->toBe('application/json');
            expect($response->getHeader('X-Content-Detection'))->toContain('json');
        });

        it('should detect HTML content and set correct MIME type', function() {
            $htmlContent = '<html><head><title>Test</title></head><body><p>Hello</p></body></html>';
            $response = Response::file($htmlContent, 'application/octet-stream', 200, [], 'test.html');

            expect($response->getHeader('Content-Type'))->toBe('text/html');
            expect($response->getHeader('X-Content-Detection'))->toContain('html');
        });

        it('should preserve explicit MIME type when not default', function() {
            $content = 'test content';
            $explicitMimeType = 'application/custom';
            $response = Response::file($content, $explicitMimeType);

            expect($response->getHeader('Content-Type'))->toBe($explicitMimeType);
            expect($response->getHeader('X-Content-Detection'))->toBe(null);
        });

        it('should include additional headers', function() {
            $content = 'test';
            $headers = [
                'Cache-Control' => 'no-cache',
                'X-Custom-Header' => 'custom-value'
            ];
            $response = Response::file($content, 'text/plain', 200, $headers);

            expect($response->getHeader('Cache-Control'))->toBe('no-cache');
            expect($response->getHeader('X-Custom-Header'))->toBe('custom-value');
        });
    });

});
