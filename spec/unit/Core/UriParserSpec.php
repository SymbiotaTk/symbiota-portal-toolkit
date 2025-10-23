<?php

use Symbiota\Helpers\Core\UriParser;

describe('UriParser', function() {
    
    describe('HTTP URIs', function() {
        
        it('should parse simple HTTP URI with resource', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank');
            
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
            expect($parser->getModifier())->toBeNull();
            expect($parser->getQueryString())->toBeNull();
            expect($parser->getHash())->toBeNull();
        });

        it('should parse HTTP URI with complex resource path', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank/devForm');
            
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank/devForm');
            expect($parser->getElementNames())->toBe(['genbank', 'devForm']);
        });

        it('should parse HTTP URI with modifier', function() {
            $parser = UriParser::parse('http://localhost:8888/?/genbank/collections:json');
            
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8888);
            expect($parser->getOffset())->toBe('');
            expect($parser->getResource())->toBe('/genbank/collections:json');
            expect($parser->getElementNames())->toBe(['genbank', 'collections']);
            expect($parser->getModifier())->toBe('json');
        });

        it('should parse HTTP URI with query string', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank?collid=10&format=html');

            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
            expect($parser->getQueryString())->toBe('collid=10&format=html');
        });

        it('should parse HTTP URI with query string using & separator (autocomplete pattern)', function() {
            $parser = UriParser::parse('http://localhost:8080/portal/tk/?/images/autocomplete-fields&q=fam');

            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/portal/tk');
            expect($parser->getResource())->toBe('/images/autocomplete-fields');
            expect($parser->getElementNames())->toBe(['images', 'autocomplete-fields']);
            expect($parser->getQueryString())->toBe('q=fam');
        });

        it('should parse HTTP URI with hash fragment', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank#results');
            
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
            expect($parser->getHash())->toBe('results');
        });

        it('should parse HTTPS URI without port', function() {
            $parser = UriParser::parse('https://example.com/portal/?/genbank/search');
            
            expect($parser->getProtocol())->toBe('https');
            expect($parser->getHost())->toBe('example.com');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('/portal');
            expect($parser->getResource())->toBe('/genbank/search');
            expect($parser->getElementNames())->toBe(['genbank', 'search']);
        });

    });

    describe('Database Connection URIs', function() {

        it('should parse MySQL connection URI with credentials', function() {
            $parser = UriParser::parse('mysql://user:password@localhost:3306/database');

            expect($parser->getProtocol())->toBe('mysql');
            expect($parser->getUsername())->toBe('user');
            expect($parser->getPassword())->toBe('password');
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(3306);
            expect($parser->getOffset())->toBe('/database');
            expect($parser->isDatabaseUri())->toBe(true);
            expect($parser->isHttpUri())->toBe(false);
        });

        it('should parse PostgreSQL connection URI with username only', function() {
            $parser = UriParser::parse('pgsql://user@localhost:5432/mydb');

            expect($parser->getProtocol())->toBe('pgsql');
            expect($parser->getUsername())->toBe('user');
            expect($parser->getPassword())->toBeNull();
            expect($parser->getHost())->toBe('localhost');
            expect($parser->getPort())->toBe(5432);
            expect($parser->getOffset())->toBe('/mydb');
            expect($parser->isDatabaseUri())->toBe(true);
        });

        it('should parse SQLite connection URI', function() {
            $parser = UriParser::parse('sqlite:///path/to/database.db');
            
            expect($parser->getProtocol())->toBe('sqlite');
            expect($parser->getHost())->toBe('');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('/path/to/database.db');
            expect($parser->isDatabaseUri())->toBe(true);
        });

    });

    describe('Authentication in URIs', function() {

        it('should parse HTTP URI with username and password', function() {
            $parser = UriParser::parse('https://username:password@example.com:8080/myco/help/?/genbank');

            expect($parser->getProtocol())->toBe('https');
            expect($parser->getUsername())->toBe('username');
            expect($parser->getPassword())->toBe('password');
            expect($parser->getHost())->toBe('example.com');
            expect($parser->getPort())->toBe(8080);
            expect($parser->getOffset())->toBe('/myco/help');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
        });

        it('should parse HTTP URI with username only', function() {
            $parser = UriParser::parse('https://username@example.com/portal/?/genbank/search');

            expect($parser->getProtocol())->toBe('https');
            expect($parser->getUsername())->toBe('username');
            expect($parser->getPassword())->toBeNull();
            expect($parser->getHost())->toBe('example.com');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('/portal');
            expect($parser->getResource())->toBe('/genbank/search');
            expect($parser->getElementNames())->toBe(['genbank', 'search']);
        });

        it('should parse complex URI with auth, query, and hash', function() {
            $parser = UriParser::parse('https://user:pass@host.com/offset/?/resource/ele1/ele2:modifier?a=b&c=d#hash');

            expect($parser->getProtocol())->toBe('https');
            expect($parser->getUsername())->toBe('user');
            expect($parser->getPassword())->toBe('pass');
            expect($parser->getHost())->toBe('host.com');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('/offset');
            expect($parser->getResource())->toBe('/resource/ele1/ele2:modifier');
            expect($parser->getElementNames())->toBe(['resource', 'ele1', 'ele2']);
            expect($parser->getModifier())->toBe('modifier');
            expect($parser->getQueryString())->toBe('a=b&c=d');
            expect($parser->getHash())->toBe('hash');
        });

        it('should handle malformed auth with special characters', function() {
            $parser = UriParser::parse('https://user%40domain:p%40ssw0rd@example.com/path/?/resource');

            expect($parser->getProtocol())->toBe('https');
            expect($parser->getUsername())->toBe('user%40domain');
            expect($parser->getPassword())->toBe('p%40ssw0rd');
            expect($parser->getHost())->toBe('example.com');
            expect($parser->getOffset())->toBe('/path');
            expect($parser->getResource())->toBe('/resource');
        });

        it('should handle empty password', function() {
            $parser = UriParser::parse('https://username:@example.com/path');

            expect($parser->getProtocol())->toBe('https');
            expect($parser->getUsername())->toBe('username');
            expect($parser->getPassword())->toBe('');
            expect($parser->getHost())->toBe('example.com');
            expect($parser->getOffset())->toBe('/path');
        });

    });

    describe('Path-only URIs', function() {
        
        it('should parse root resource path', function() {
            $parser = UriParser::parse('/?/genbank');

            // After normalization, path-only URIs get internal protocol
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('internal');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('');
            expect($parser->getResource())->toBe('/genbank');
            expect($parser->getElementNames())->toBe(['genbank']);
        });

        it('should parse complex resource path with modifier', function() {
            $parser = UriParser::parse('/?/genbank/catalogNumber/163172:json');

            // After normalization, path-only URIs get internal protocol
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('internal');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('');
            expect($parser->getResource())->toBe('/genbank/catalogNumber/163172:json');
            expect($parser->getElementNames())->toBe(['genbank', 'catalogNumber', '163172']);
            expect($parser->getModifier())->toBe('json');
        });

        it('should parse help path', function() {
            $parser = UriParser::parse('/?/help');

            // After normalization, path-only URIs get internal protocol
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('internal');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('');
            expect($parser->getResource())->toBe('/help');
            expect($parser->getElementNames())->toBe(['help']);
        });

        it('should parse empty resource path', function() {
            $parser = UriParser::parse('/?/');

            // After normalization, path-only URIs get internal protocol
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('internal');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBe('');
            expect($parser->getResource())->toBe('/');
            expect($parser->getElementNames())->toBe([]);
        });

    });

    describe('URL building methods', function() {
        
        it('should build base URL for HTTP URI', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank');
            
            expect($parser->getBaseUrl())->toBe('http://localhost:8080/myco/help');
        });

        it('should build app URL prefix for HTTP URI', function() {
            $parser = UriParser::parse('http://localhost:8080/myco/help/?/genbank');
            
            expect($parser->getAppUrlPrefix())->toBe('http://localhost:8080/myco/help/?/');
        });

        it('should build base URL for root deployment', function() {
            $parser = UriParser::parse('http://localhost:8888/?/genbank');
            
            expect($parser->getBaseUrl())->toBe('http://localhost:8888');
            expect($parser->getAppUrlPrefix())->toBe('http://localhost:8888/?/');
        });

        it('should build base URL for path-only URI', function() {
            $parser = UriParser::parse('/?/genbank');

            // After normalization, path-only URIs get internal protocol
            expect($parser->getBaseUrl())->toBe('http://internal');
            expect($parser->getAppUrlPrefix())->toBe('http://internal/?/');
        });

    });

    describe('element parsing', function() {
        
        it('should parse elements with detailed information', function() {
            $parser = UriParser::parse('/?/genbank/catalogNumber/163172:json');
            
            $elements = $parser->getElements();
            expect(count($elements))->toBe(3);
            
            expect($elements[0]['name'])->toBe('genbank');
            expect($elements[0]['index'])->toBe(0);
            expect($elements[0]['modifier'])->toBeNull();
            
            expect($elements[1]['name'])->toBe('catalogNumber');
            expect($elements[1]['index'])->toBe(1);
            expect($elements[1]['modifier'])->toBeNull();
            
            expect($elements[2]['name'])->toBe('163172');
            expect($elements[2]['index'])->toBe(2);
            expect($elements[2]['modifier'])->toBe('json');
        });

    });

    describe('edge cases', function() {
        
        it('should handle empty URI', function() {
            $parser = UriParser::parse('');
            
            expect($parser->getProtocol())->toBeNull();
            expect($parser->getHost())->toBeNull();
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBeNull();
            expect($parser->getResource())->toBeNull();
            expect($parser->getElementNames())->toBe([]);
        });

        it('should handle URI with only protocol', function() {
            $parser = UriParser::parse('http://');
            
            expect($parser->getProtocol())->toBe('http');
            expect($parser->getHost())->toBe('');
            expect($parser->getPort())->toBeNull();
            expect($parser->getOffset())->toBeNull();
        });

        it('should preserve original URI', function() {
            $original = 'http://localhost:8080/myco/help/?/genbank/devForm#results';
            $parser = UriParser::parse($original);
            
            expect($parser->getOriginal())->toBe($original);
        });

    });

});
