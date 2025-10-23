<?php

use Symbiota\Helpers\Core\Request;

describe('Request', function() {
    
    describe('basic functionality', function() {
        
        it('should handle GET parameters', function() {
            $request = new Request(['name' => 'test', 'id' => '123']);
            expect($request->get('name'))->toBe('test');
            expect($request->get('id'))->toBe('123');
            expect($request->get('missing', 'default'))->toBe('default');
        });
        
        it('should handle POST parameters', function() {
            $request = new Request([], ['title' => 'Test Title', 'content' => 'Test Content']);
            expect($request->post('title'))->toBe('Test Title');
            expect($request->post('content'))->toBe('Test Content');
            expect($request->post('missing', 'default'))->toBe('default');
        });
        
        it('should handle input parameters from both GET and POST', function() {
            $request = new Request(['get_param' => 'get_value'], ['post_param' => 'post_value']);
            expect($request->input('get_param'))->toBe('get_value');
            expect($request->input('post_param'))->toBe('post_value');
        });
        
        it('should prioritize POST over GET for input', function() {
            $request = new Request(['param' => 'get_value'], ['param' => 'post_value']);
            expect($request->input('param'))->toBe('post_value');
        });
        
    });
    
    describe('HTTP method detection', function() {
        
        it('should detect GET method', function() {
            $request = new Request([], [], [], ['REQUEST_METHOD' => 'GET']);
            expect($request->getMethod())->toBe('GET');
            expect($request->isMethod('GET'))->toBe(true);
            expect($request->isMethod('POST'))->toBe(false);
        });
        
        it('should detect POST method', function() {
            $request = new Request([], [], [], ['REQUEST_METHOD' => 'POST']);
            expect($request->getMethod())->toBe('POST');
            expect($request->isMethod('POST'))->toBe(true);
        });
        
        it('should default to GET if no method specified', function() {
            $request = new Request();
            expect($request->getMethod())->toBe('GET');
        });
        
    });
    
    describe('URI handling', function() {
        
        it('should get request URI', function() {
            $request = new Request([], [], [], ['REQUEST_URI' => '/?/genbank/data']);
            expect($request->getUri())->toBe('/?/genbank/data');
        });
        
        it('should handle missing URI', function() {
            $request = new Request();
            expect($request->getUri())->toBe('');
        });
        
    });
    
    describe('header parsing', function() {
        
        it('should parse HTTP headers', function() {
            $server = [
                'HTTP_CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_HX_REQUEST' => 'true'
            ];
            $request = new Request([], [], [], $server);
            
            expect($request->header('content-type'))->toBe('application/json');
            expect($request->header('x-requested-with'))->toBe('XMLHttpRequest');
            expect($request->header('hx-request'))->toBe('true');
        });
        
        it('should handle content-type and content-length', function() {
            $server = [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '123'
            ];
            $request = new Request([], [], [], $server);
            
            expect($request->header('content-type'))->toBe('application/json');
            expect($request->header('content-length'))->toBe('123');
        });
        
        it('should return default for missing headers', function() {
            $request = new Request();
            expect($request->header('missing', 'default'))->toBe('default');
        });
        
    });
    
    describe('request type detection', function() {
        
        it('should detect AJAX requests', function() {
            $server = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
            $request = new Request([], [], [], $server);
            expect($request->isAjax())->toBe(true);
        });
        
        it('should detect HTMX requests', function() {
            $server = ['HTTP_HX_REQUEST' => 'true'];
            $request = new Request([], [], [], $server);
            expect($request->isHtmx())->toBe(true);
        });
        
        it('should detect JSON content type', function() {
            $server = ['CONTENT_TYPE' => 'application/json'];
            $request = new Request([], [], [], $server);
            expect($request->isJson())->toBe(true);
        });
        
        it('should get HTMX target', function() {
            $server = ['HTTP_HX_TARGET' => '#content'];
            $request = new Request([], [], [], $server);
            expect($request->getHtmxTarget())->toBe('#content');
        });
        
    });
    
    describe('file handling', function() {
        
        it('should handle uploaded files', function() {
            $files = [
                'upload' => [
                    'name' => 'test.csv',
                    'type' => 'text/csv',
                    'size' => 1024,
                    'tmp_name' => '/tmp/upload123'
                ]
            ];
            $request = new Request([], [], $files);
            
            $file = $request->file('upload');
            expect($file['name'])->toBe('test.csv');
            expect($file['type'])->toBe('text/csv');
        });
        
        it('should return null for missing files', function() {
            $request = new Request();
            expect($request->file('missing'))->toBeNull();
        });
        
    });
    
    describe('validation', function() {
        
        it('should validate required fields', function() {
            $request = new Request(['name' => 'test'], ['email' => 'test@example.com']);
            
            $errors = $request->validate(['name', 'email']);
            expect($errors)->toBeEmpty();
            
            $errors = $request->validate(['name', 'email', 'missing']);
            expect($errors)->toHaveLength(1);
            expect($errors[0])->toContain('missing');
        });
        
        it('should validate empty values as missing', function() {
            $request = new Request(['name' => '']);
            $errors = $request->validate(['name']);
            expect($errors)->toHaveLength(1);
        });
        
    });
    
    describe('sanitization', function() {
        
        it('should sanitize string values', function() {
            $request = new Request(['name' => '<script>alert("test")</script>']);
            $sanitized = $request->sanitize('name', 'string');
            expect($sanitized)->not->toContain('<script>');
        });
        
        it('should validate integer values', function() {
            $request = new Request(['id' => '123']);
            expect($request->sanitize('id', 'int'))->toBe(123);
            
            $request = new Request(['id' => 'invalid']);
            expect($request->sanitize('id', 'int'))->toBe(false);
        });
        
        it('should validate email values', function() {
            $request = new Request(['email' => 'test@example.com']);
            expect($request->sanitize('email', 'email'))->toBe('test@example.com');
            
            $request = new Request(['email' => 'invalid-email']);
            expect($request->sanitize('email', 'email'))->toBe(false);
        });
        
    });
    
});
