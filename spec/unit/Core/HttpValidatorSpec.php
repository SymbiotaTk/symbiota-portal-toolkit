<?php

use Symbiota\Helpers\Core\HttpValidator;

describe('HttpValidator', function() {
    
    describe('URL validation', function() {
        describe('isValidUrl', function() {
            it('should validate HTTP URLs', function() {
                expect(HttpValidator::isValidUrl('http://example.com'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://www.example.com'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://example.com/path'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://example.com:8080'))->toBe(true);
            });
            
            it('should validate HTTPS URLs', function() {
                expect(HttpValidator::isValidUrl('https://example.com'))->toBe(true);
                expect(HttpValidator::isValidUrl('https://www.example.com'))->toBe(true);
                expect(HttpValidator::isValidUrl('https://example.com/path'))->toBe(true);
                expect(HttpValidator::isValidUrl('https://example.com:443'))->toBe(true);
            });
            
            it('should reject invalid URLs', function() {
                expect(HttpValidator::isValidUrl('not-a-url'))->toBe(false);
                expect(HttpValidator::isValidUrl('ftp://example.com'))->toBe(false);
                expect(HttpValidator::isValidUrl('javascript:alert(1)'))->toBe(false);
                expect(HttpValidator::isValidUrl(''))->toBe(false);
                expect(HttpValidator::isValidUrl('http://'))->toBe(false);
            });
            
            it('should handle edge cases', function() {
                expect(HttpValidator::isValidUrl('http://localhost'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://127.0.0.1'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://[::1]'))->toBe(true); // IPv6
                expect(HttpValidator::isValidUrl('http://example.com?query=value'))->toBe(true);
                expect(HttpValidator::isValidUrl('http://example.com#fragment'))->toBe(true);
            });
        });
    });
    
    describe('HTTP method validation', function() {
        describe('isValidHttpMethod', function() {
            it('should validate standard HTTP methods', function() {
                expect(HttpValidator::isValidHttpMethod('GET'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('POST'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('PUT'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('DELETE'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('PATCH'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('HEAD'))->toBe(true);
                expect(HttpValidator::isValidHttpMethod('OPTIONS'))->toBe(true);
            });
            
            it('should be case sensitive', function() {
                expect(HttpValidator::isValidHttpMethod('get'))->toBe(false);
                expect(HttpValidator::isValidHttpMethod('post'))->toBe(false);
                expect(HttpValidator::isValidHttpMethod('Get'))->toBe(false);
                expect(HttpValidator::isValidHttpMethod('POST'))->toBe(true);
            });
            
            it('should reject invalid methods', function() {
                expect(HttpValidator::isValidHttpMethod('INVALID'))->toBe(false);
                expect(HttpValidator::isValidHttpMethod(''))->toBe(false);
                expect(HttpValidator::isValidHttpMethod('123'))->toBe(false);
                expect(HttpValidator::isValidHttpMethod('GET POST'))->toBe(false);
            });
        });
    });
    
    describe('header validation', function() {
        describe('isValidHeaderName', function() {
            it('should validate standard header names', function() {
                expect(HttpValidator::isValidHeaderName('Content-Type'))->toBe(true);
                expect(HttpValidator::isValidHeaderName('Authorization'))->toBe(true);
                expect(HttpValidator::isValidHeaderName('X-Custom-Header'))->toBe(true);
                expect(HttpValidator::isValidHeaderName('User-Agent'))->toBe(true);
            });
            
            it('should reject invalid header names', function() {
                expect(HttpValidator::isValidHeaderName(''))->toBe(false);
                expect(HttpValidator::isValidHeaderName('Invalid Header'))->toBe(false); // Space
                expect(HttpValidator::isValidHeaderName('Invalid:Header'))->toBe(false); // Colon
                expect(HttpValidator::isValidHeaderName("Invalid\nHeader"))->toBe(false); // Newline
            });
        });
        
        describe('isValidHeaderValue', function() {
            it('should validate standard header values', function() {
                expect(HttpValidator::isValidHeaderValue('application/json'))->toBe(true);
                expect(HttpValidator::isValidHeaderValue('Bearer token123'))->toBe(true);
                expect(HttpValidator::isValidHeaderValue('text/html; charset=utf-8'))->toBe(true);
                expect(HttpValidator::isValidHeaderValue(''))->toBe(true); // Empty is valid
            });
            
            it('should reject invalid header values', function() {
                expect(HttpValidator::isValidHeaderValue("value\nwith\nnewlines"))->toBe(false);
                expect(HttpValidator::isValidHeaderValue("value\rwith\rcarriage"))->toBe(false);
                expect(HttpValidator::isValidHeaderValue("value\x00with\x00null"))->toBe(false);
            });
        });
    });
    
    describe('status code validation', function() {
        describe('isValidStatusCode', function() {
            it('should validate standard status codes', function() {
                expect(HttpValidator::isValidStatusCode(200))->toBe(true);
                expect(HttpValidator::isValidStatusCode(404))->toBe(true);
                expect(HttpValidator::isValidStatusCode(500))->toBe(true);
                expect(HttpValidator::isValidStatusCode(301))->toBe(true);
            });
            
            it('should validate status code ranges', function() {
                // 1xx Informational
                expect(HttpValidator::isValidStatusCode(100))->toBe(true);
                expect(HttpValidator::isValidStatusCode(199))->toBe(true);
                
                // 2xx Success
                expect(HttpValidator::isValidStatusCode(200))->toBe(true);
                expect(HttpValidator::isValidStatusCode(299))->toBe(true);
                
                // 3xx Redirection
                expect(HttpValidator::isValidStatusCode(300))->toBe(true);
                expect(HttpValidator::isValidStatusCode(399))->toBe(true);
                
                // 4xx Client Error
                expect(HttpValidator::isValidStatusCode(400))->toBe(true);
                expect(HttpValidator::isValidStatusCode(499))->toBe(true);
                
                // 5xx Server Error
                expect(HttpValidator::isValidStatusCode(500))->toBe(true);
                expect(HttpValidator::isValidStatusCode(599))->toBe(true);
            });
            
            it('should reject invalid status codes', function() {
                expect(HttpValidator::isValidStatusCode(99))->toBe(false);   // Too low
                expect(HttpValidator::isValidStatusCode(600))->toBe(false);  // Too high
                expect(HttpValidator::isValidStatusCode(0))->toBe(false);
                expect(HttpValidator::isValidStatusCode(-1))->toBe(false);
            });
        });
    });
    
    describe('content type validation', function() {
        describe('isValidContentType', function() {
            it('should validate standard content types', function() {
                expect(HttpValidator::isValidContentType('text/html'))->toBe(true);
                expect(HttpValidator::isValidContentType('application/json'))->toBe(true);
                expect(HttpValidator::isValidContentType('image/png'))->toBe(true);
                expect(HttpValidator::isValidContentType('text/plain'))->toBe(true);
            });
            
            it('should validate content types with parameters', function() {
                expect(HttpValidator::isValidContentType('text/html; charset=utf-8'))->toBe(true);
                expect(HttpValidator::isValidContentType('application/json; charset=utf-8'))->toBe(true);
                expect(HttpValidator::isValidContentType('multipart/form-data; boundary=something'))->toBe(true);
            });
            
            it('should reject invalid content types', function() {
                expect(HttpValidator::isValidContentType(''))->toBe(false);
                expect(HttpValidator::isValidContentType('invalid'))->toBe(false);
                expect(HttpValidator::isValidContentType('text/'))->toBe(false);
                expect(HttpValidator::isValidContentType('/html'))->toBe(false);
            });
        });
    });
    
    describe('parameter validation', function() {
        describe('sanitizeInput', function() {
            it('should sanitize basic input', function() {
                $input = '<script>alert("xss")</script>';
                $sanitized = HttpValidator::sanitizeInput($input);
                
                expect($sanitized)->not->toContain('<script>');
                expect($sanitized)->not->toContain('alert');
            });
            
            it('should preserve safe content', function() {
                $input = 'Hello World 123';
                $sanitized = HttpValidator::sanitizeInput($input);
                
                expect($sanitized)->toBe($input);
            });
            
            it('should handle empty input', function() {
                expect(HttpValidator::sanitizeInput(''))->toBe('');
                expect(HttpValidator::sanitizeInput(null))->toBe('');
            });
        });
        
        describe('validateParameterName', function() {
            it('should validate parameter names', function() {
                expect(HttpValidator::validateParameterName('param1'))->toBe(true);
                expect(HttpValidator::validateParameterName('param_name'))->toBe(true);
                expect(HttpValidator::validateParameterName('paramName'))->toBe(true);
                expect(HttpValidator::validateParameterName('param-name'))->toBe(true);
            });
            
            it('should reject invalid parameter names', function() {
                expect(HttpValidator::validateParameterName(''))->toBe(false);
                expect(HttpValidator::validateParameterName('123param'))->toBe(false); // Starts with number
                expect(HttpValidator::validateParameterName('param name'))->toBe(false); // Space
                expect(HttpValidator::validateParameterName('param@name'))->toBe(false); // Special char
            });
        });
    });
    
    describe('security validation', function() {
        describe('isSafeRedirectUrl', function() {
            it('should allow safe relative URLs', function() {
                expect(HttpValidator::isSafeRedirectUrl('/path/to/page'))->toBe(true);
                expect(HttpValidator::isSafeRedirectUrl('path/to/page'))->toBe(true);
                expect(HttpValidator::isSafeRedirectUrl('./page'))->toBe(true);
            });
            
            it('should allow same-origin URLs', function() {
                $_SERVER['HTTP_HOST'] = 'example.com';
                
                expect(HttpValidator::isSafeRedirectUrl('http://example.com/path'))->toBe(true);
                expect(HttpValidator::isSafeRedirectUrl('https://example.com/path'))->toBe(true);
            });
            
            it('should reject external URLs', function() {
                $_SERVER['HTTP_HOST'] = 'example.com';
                
                expect(HttpValidator::isSafeRedirectUrl('http://evil.com/path'))->toBe(false);
                expect(HttpValidator::isSafeRedirectUrl('https://evil.com/path'))->toBe(false);
                expect(HttpValidator::isSafeRedirectUrl('//evil.com/path'))->toBe(false);
            });
            
            it('should reject javascript URLs', function() {
                expect(HttpValidator::isSafeRedirectUrl('javascript:alert(1)'))->toBe(false);
                expect(HttpValidator::isSafeRedirectUrl('data:text/html,<script>'))->toBe(false);
            });
        });
        
        describe('detectXssAttempt', function() {
            it('should detect script tags', function() {
                expect(HttpValidator::detectXssAttempt('<script>alert(1)</script>'))->toBe(true);
                expect(HttpValidator::detectXssAttempt('<SCRIPT>alert(1)</SCRIPT>'))->toBe(true);
            });
            
            it('should detect javascript URLs', function() {
                expect(HttpValidator::detectXssAttempt('javascript:alert(1)'))->toBe(true);
                expect(HttpValidator::detectXssAttempt('JAVASCRIPT:alert(1)'))->toBe(true);
            });
            
            it('should detect event handlers', function() {
                expect(HttpValidator::detectXssAttempt('onload="alert(1)"'))->toBe(true);
                expect(HttpValidator::detectXssAttempt('onclick="alert(1)"'))->toBe(true);
            });
            
            it('should allow safe content', function() {
                expect(HttpValidator::detectXssAttempt('Hello World'))->toBe(false);
                expect(HttpValidator::detectXssAttempt('user@example.com'))->toBe(false);
                expect(HttpValidator::detectXssAttempt('Price: $10.99'))->toBe(false);
            });
        });
    });
});
