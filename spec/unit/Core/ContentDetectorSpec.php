<?php

use Symbiota\Helpers\Core\ContentDetector;

describe('ContentDetector', function() {
    
    describe('detectContentType', function() {

        it('should detect HTML content correctly', function() {
            $htmlContent = '<!DOCTYPE html><html><head><title>Test</title></head><body><p>Hello</p></body></html>';
            $result = ContentDetector::detectContentType($htmlContent, 'test.html');
            
            expect($result['type'])->toBe('html');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('text/html');
        });
        
        it('should detect CSS content correctly', function() {
            $cssContent = 'body { color: red; font-size: 14px; margin: 10px; } @media screen { .test { padding: 5px; } }';
            $result = ContentDetector::detectContentType($cssContent, 'test.css');
            
            expect($result['type'])->toBe('css');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('text/css');
        });
        
        it('should detect JavaScript content correctly', function() {
            $jsContent = 'function hello() { console.log("Hello"); document.getElementById("test"); } const x = 5;';
            $result = ContentDetector::detectContentType($jsContent, 'test.js');
            
            expect($result['type'])->toBe('javascript');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('application/javascript');
        });
        
        it('should detect JSON content correctly', function() {
            $jsonContent = '{"name": "John", "age": 30, "city": "New York", "active": true}';
            $result = ContentDetector::detectContentType($jsonContent, 'test.json');
            
            expect($result['type'])->toBe('json');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('application/json');
        });
        
        it('should detect Markdown content correctly', function() {
            $markdownContent = '# Header\n\nThis is **bold** text with [links](http://example.com)\n\n```php\necho "code";\n```';
            $result = ContentDetector::detectContentType($markdownContent, 'test.md');
            
            expect($result['type'])->toBe('markdown');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('text/markdown');
        });
        
        it('should detect INI content correctly', function() {
            $iniContent = "[database]\nhost = localhost\nport = 3306\n; This is a comment\nuser = admin";
            $result = ContentDetector::detectContentType($iniContent, 'test.ini');

            expect($result['type'])->toBe('ini');
            expect($result['confidence'])->toBeGreaterThan(50);
            expect($result['mime_type'])->toBe('text/plain');
        });

        it('should fallback to text for unknown content', function() {
            $unknownContent = 'This is just plain text with no special formatting or syntax.';
            $result = ContentDetector::detectContentType($unknownContent, 'test.txt');

            expect($result['type'])->toBe('text');
            expect($result['mime_type'])->toBe('text/plain');
        });

        it('should handle content without extension hints', function() {
            $unknownContent = 'This is just plain text with no special formatting or syntax.';
            $result = ContentDetector::detectContentType($unknownContent, '');

            expect($result['type'])->toBe('text');
            expect($result['mime_type'])->toBe('text/plain');
        });
        
        it('should use extension hints when content is ambiguous', function() {
            $ambiguousContent = 'test content';
            $result = ContentDetector::detectContentType($ambiguousContent, 'test.css');

            expect($result['type'])->toBe('css');
        });
    });
    
    describe('content type classification', function() {
        
        it('should correctly identify CLI text types', function() {
            expect(ContentDetector::isCliTextType('text'))->toBe(true);
            expect(ContentDetector::isCliTextType('markdown'))->toBe(true);
            expect(ContentDetector::isCliTextType('ini'))->toBe(true);
            expect(ContentDetector::isCliTextType('html'))->toBe(false);
            expect(ContentDetector::isCliTextType('json'))->toBe(false);
        });

        it('should correctly identify structured data types', function() {
            expect(ContentDetector::isStructuredDataType('json'))->toBe(true);
            expect(ContentDetector::isStructuredDataType('html'))->toBe(false);
            expect(ContentDetector::isStructuredDataType('text'))->toBe(false);
        });

        it('should correctly identify web-renderable types', function() {
            expect(ContentDetector::isWebRenderableType('html'))->toBe(true);
            expect(ContentDetector::isWebRenderableType('css'))->toBe(true);
            expect(ContentDetector::isWebRenderableType('javascript'))->toBe(true);
            expect(ContentDetector::isWebRenderableType('json'))->toBe(true);
            expect(ContentDetector::isWebRenderableType('text'))->toBe(false);
            expect(ContentDetector::isWebRenderableType('ini'))->toBe(false);
        });
    });
    
    describe('MIME type mapping', function() {
        
        it('should return correct MIME types for all supported content types', function() {
            expect(ContentDetector::getMimeType('html'))->toBe('text/html');
            expect(ContentDetector::getMimeType('css'))->toBe('text/css');
            expect(ContentDetector::getMimeType('javascript'))->toBe('application/javascript');
            expect(ContentDetector::getMimeType('json'))->toBe('application/json');
            expect(ContentDetector::getMimeType('markdown'))->toBe('text/markdown');
            expect(ContentDetector::getMimeType('ini'))->toBe('text/plain');
            expect(ContentDetector::getMimeType('text'))->toBe('text/plain');
        });
        
        it('should fallback to text/plain for unknown types', function() {
            expect(ContentDetector::getMimeType('unknown'))->toBe('text/plain');
            expect(ContentDetector::getMimeType(''))->toBe('text/plain');
        });
    });
});
