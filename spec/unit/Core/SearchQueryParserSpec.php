<?php

namespace spec\Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\SearchQueryParser;
use Symbiota\Helpers\Core\EavIndexing;

describe('SearchQueryParser', function() {
    
    beforeEach(function() {
        // Load test config (use the actual config from templates)
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
        $this->config = EavIndexing::parseConfig($this->configPath);

        // Create parser
        $this->parser = new SearchQueryParser($this->config);
    });
    
    describe('parseQuery', function() {
        
        it('parses freetext query (no prefix)', function() {
            $result = $this->parser->parseQuery('amanita');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('freetext');
            expect($result['value'])->toBe('amanita');
            expect($result['field'])->toBeNull();
        });
        
        it('parses field-specific query (field:value)', function() {
            $result = $this->parser->parseQuery('family:Liceaceae');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('field');
            expect($result['field'])->toBe('family');
            expect($result['value'])->toBe('liceaceae'); // Normalized to lowercase
        });
        
        it('resolves field aliases', function() {
            $result = $this->parser->parseQuery('taxon:amanita');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('field');
            expect($result['field'])->toBe('sciname'); // 'taxon' is alias for 'sciname'
            expect($result['value'])->toBe('amanita');
        });

        it('resolves multi-field aliases (collection)', function() {
            $result = $this->parser->parseQuery('collection:CUP');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('multi_field');
            expect($result['fields'])->toBe(['collectionName', 'collectionCode', 'institutionCode']);
            expect($result['value'])->toBe('cup'); // Normalized to lowercase
            expect($result['operator'])->toBe('LIKE');
        });

        it('resolves multi-field aliases (location)', function() {
            $result = $this->parser->parseQuery('location:Illinois');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('multi_field');
            expect($result['fields'])->toBe(['country', 'stateProvince', 'county', 'municipality', 'locality']);
            expect($result['value'])->toBe('illinois');
            expect($result['operator'])->toBe('LIKE');
        });

        it('resolves multi-field aliases (place)', function() {
            $result = $this->parser->parseQuery('place:Chicago');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('multi_field');
            expect($result['fields'])->toBe(['country', 'stateProvince', 'county', 'municipality', 'locality']);
            expect($result['value'])->toBe('chicago');
            expect($result['operator'])->toBe('LIKE');
        });

        it('resolves multi-field aliases (taxonomy)', function() {
            $result = $this->parser->parseQuery('taxonomy:Amanita');

            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('multi_field');
            expect($result['fields'])->toBe(['family', 'genus', 'scientificName', 'sciname']);
            expect($result['value'])->toBe('amanita');
            expect($result['operator'])->toBe('LIKE');
        });
        
        it('handles numeric field queries', function() {
            $result = $this->parser->parseQuery('occid:12345');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('numeric');
            expect($result['field'])->toBe('occid');
            expect($result['value'])->toBe(12345);
            expect($result['operator'])->toBe('=');
        });
        
        it('handles numeric field with operator', function() {
            $result = $this->parser->parseQuery('decimalLatitude:>40.5');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('numeric');
            expect($result['field'])->toBe('decimalLatitude');
            expect($result['value'])->toBe(40.5);
            expect($result['operator'])->toBe('>');
        });
        
        it('handles date field queries', function() {
            $result = $this->parser->parseQuery('eventDate:1974');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('date');
            expect($result['field'])->toBe('eventDate');
            expect($result['value'])->toBe('1974');
            expect($result['operator'])->toBe('LIKE');
        });
        
        it('handles date field with operator', function() {
            $result = $this->parser->parseQuery('date:>2000');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('date');
            expect($result['field'])->toBe('eventDate'); // 'date' is alias for 'eventDate'
            expect($result['value'])->toBe('2000');
            expect($result['operator'])->toBe('>');
        });
        
        it('handles date range queries', function() {
            $result = $this->parser->parseQuery('eventDate:1970-1980');
            
            expect($result)->toBeAn('array');
            expect($result['type'])->toBe('date_range');
            expect($result['field'])->toBe('eventDate');
            expect($result['value'])->toBeAn('array');
            expect($result['value'][0])->toBe('1970');
            expect($result['value'][1])->toBe('1980');
        });
        
        it('normalizes search values (lowercase, trim)', function() {
            $result = $this->parser->parseQuery('family:  LICEACEAE  ');
            
            expect($result['value'])->toBe('liceaceae');
        });
        
    });
    
    describe('getFieldMetadata', function() {
        
        it('returns field metadata from config', function() {
            $metadata = $this->parser->getFieldMetadata('family');
            
            expect($metadata)->toBeAn('array');
            expect($metadata['table'])->toBe('omoccurrences');
            expect($metadata['type'])->toBe('text');
            expect($metadata['strategy'])->toBe('whole');
        });
        
        it('returns null for unknown field', function() {
            $metadata = $this->parser->getFieldMetadata('unknownfield');
            
            expect($metadata)->toBeNull();
        });
        
        it('resolves aliases before lookup', function() {
            $metadata = $this->parser->getFieldMetadata('taxon');
            
            expect($metadata)->toBeAn('array');
            expect($metadata['table'])->toBe('omoccurrences');
            expect($metadata['name'])->toBe('sciname');
        });
        
    });
    
    describe('resolveAlias', function() {
        
        it('resolves known alias', function() {
            $resolved = $this->parser->resolveAlias('taxon');
            expect($resolved)->toBe('sciname');
        });
        
        it('returns original field if not an alias', function() {
            $resolved = $this->parser->resolveAlias('family');
            expect($resolved)->toBe('family');
        });
        
        it('is case-insensitive', function() {
            $resolved = $this->parser->resolveAlias('TAXON');
            expect($resolved)->toBe('sciname');
        });
        
    });
    
});

