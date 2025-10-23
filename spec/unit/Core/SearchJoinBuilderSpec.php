<?php

namespace spec\Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\SearchJoinBuilder;
use Symbiota\Helpers\Core\EavIndexing;

describe('SearchJoinBuilder', function() {
    
    beforeEach(function() {
        // Load test config
        $this->configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
        $this->config = EavIndexing::parseConfig($this->configPath);
        
        // Create builder
        $this->builder = new SearchJoinBuilder($this->config);
    });
    
    describe('buildJoinsForFields', function() {
        
        it('returns empty string for root table fields only', function() {
            $fields = ['url', 'thumbnailUrl'];
            $joins = $this->builder->buildJoinsForFields($fields);
            
            expect($joins)->toBe('');
        });
        
        it('builds JOIN for omoccurrences table', function() {
            $fields = ['family', 'sciname'];
            $joins = $this->builder->buildJoinsForFields($fields);
            
            expect($joins)->toContain('LEFT JOIN omoccurrences o ON m.occid = o.occid');
            expect($joins)->not->toContain('omcollections');
            expect($joins)->not->toContain('taxa');
        });
        
        it('builds JOINs for omcollections table (includes omoccurrences)', function() {
            $fields = ['collectionName'];
            $joins = $this->builder->buildJoinsForFields($fields);
            
            expect($joins)->toContain('LEFT JOIN omoccurrences o ON m.occid = o.occid');
            expect($joins)->toContain('LEFT JOIN omcollections c ON o.collid = c.collID');
            expect($joins)->not->toContain('taxa');
        });
        
        it('builds JOINs for taxa table (includes omoccurrences)', function() {
            $fields = ['sciName']; // sciName is in taxa table
            $joins = $this->builder->buildJoinsForFields($fields);

            expect($joins)->toContain('LEFT JOIN omoccurrences o ON m.occid = o.occid');
            expect($joins)->toContain('LEFT JOIN taxa t ON o.tidInterpreted = t.tid');
            expect($joins)->not->toContain('omcollections');
        });
        
        it('builds all JOINs for mixed fields', function() {
            $fields = ['family', 'collectionName', 'sciName']; // sciName is in taxa table
            $joins = $this->builder->buildJoinsForFields($fields);

            expect($joins)->toContain('LEFT JOIN omoccurrences o ON m.occid = o.occid');
            expect($joins)->toContain('LEFT JOIN omcollections c ON o.collid = c.collID');
            expect($joins)->toContain('LEFT JOIN taxa t ON o.tidInterpreted = t.tid');
        });
        
        it('deduplicates JOINs (no duplicate omoccurrences)', function() {
            $fields = ['family', 'collectionName'];
            $joins = $this->builder->buildJoinsForFields($fields);
            
            // Should only have one omoccurrences JOIN
            $count = substr_count($joins, 'LEFT JOIN omoccurrences');
            expect($count)->toBe(1);
        });
        
    });
    
    describe('getTableForField', function() {
        
        it('returns media for root table fields', function() {
            $table = $this->builder->getTableForField('url');
            expect($table)->toBe('media');
        });
        
        it('returns omoccurrences for occurrence fields', function() {
            $table = $this->builder->getTableForField('family');
            expect($table)->toBe('omoccurrences');
            
            $table = $this->builder->getTableForField('sciname');
            expect($table)->toBe('omoccurrences');
        });
        
        it('returns omcollections for collection fields', function() {
            $table = $this->builder->getTableForField('collectionName');
            expect($table)->toBe('omcollections');
        });
        
        it('returns taxa for taxon fields', function() {
            $table = $this->builder->getTableForField('sciName'); // sciName is in taxa table
            expect($table)->toBe('taxa');
        });
        
        it('returns null for unknown field', function() {
            $table = $this->builder->getTableForField('unknownfield');
            expect($table)->toBeNull();
        });
        
        it('is case-insensitive', function() {
            $table = $this->builder->getTableForField('FAMILY');
            expect($table)->toBe('omoccurrences');
        });
        
    });
    
    describe('getTableAlias', function() {
        
        it('returns m for media', function() {
            $alias = $this->builder->getTableAlias('media');
            expect($alias)->toBe('m');
        });
        
        it('returns o for omoccurrences', function() {
            $alias = $this->builder->getTableAlias('omoccurrences');
            expect($alias)->toBe('o');
        });
        
        it('returns c for omcollections', function() {
            $alias = $this->builder->getTableAlias('omcollections');
            expect($alias)->toBe('c');
        });
        
        it('returns t for taxa', function() {
            $alias = $this->builder->getTableAlias('taxa');
            expect($alias)->toBe('t');
        });
        
        it('returns null for unknown table', function() {
            $alias = $this->builder->getTableAlias('unknowntable');
            expect($alias)->toBeNull();
        });
        
    });
    
    describe('getQualifiedColumn', function() {
        
        it('returns qualified column name', function() {
            $qualified = $this->builder->getQualifiedColumn('family');
            expect($qualified)->toBe('o.family');
        });
        
        it('returns qualified column for root table', function() {
            $qualified = $this->builder->getQualifiedColumn('url');
            expect($qualified)->toBe('m.url');
        });
        
        it('returns null for unknown field', function() {
            $qualified = $this->builder->getQualifiedColumn('unknownfield');
            expect($qualified)->toBeNull();
        });
        
    });
    
});

