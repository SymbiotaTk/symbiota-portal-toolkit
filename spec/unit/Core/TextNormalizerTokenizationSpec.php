<?php

use Symbiota\Helpers\Core\TextNormalizer;

describe('TextNormalizer - Tokenization Strategies', function() {
    
    describe('tokenize() - split strategy (default)', function() {
        
        it('should split text by whitespace', function() {
            $result = TextNormalizer::tokenize('Agaricus campestris');
            expect($result)->toBe(['Agaricus', 'campestris']);
        });
        
        it('should handle multiple spaces', function() {
            $result = TextNormalizer::tokenize('San   Francisco    Bay');
            expect($result)->toBe(['San', 'Francisco', 'Bay']);
        });
        
        it('should normalize whitespace before splitting', function() {
            $result = TextNormalizer::tokenize("  Agaricus\t\tcampestris\n  ");
            expect($result)->toBe(['Agaricus', 'campestris']);
        });
        
        it('should return empty array for empty string', function() {
            $result = TextNormalizer::tokenize('');
            expect($result)->toBe([]);
        });
        
        it('should return empty array for whitespace-only string', function() {
            $result = TextNormalizer::tokenize('   ');
            expect($result)->toBe([]);
        });
        
    });
    
    describe('tokenizeWhole() - whole strategy', function() {
        
        it('should keep entire value as single token', function() {
            $result = TextNormalizer::tokenizeWhole('Agaricus campestris');
            expect($result)->toBe(['Agaricus campestris']);
        });
        
        it('should normalize whitespace but keep as single token', function() {
            $result = TextNormalizer::tokenizeWhole("  Agaricus\t\tcampestris\n  ");
            expect($result)->toBe(['Agaricus campestris']);
        });
        
        it('should return empty array for empty string', function() {
            $result = TextNormalizer::tokenizeWhole('');
            expect($result)->toBe([]);
        });
        
        it('should return empty array for whitespace-only string', function() {
            $result = TextNormalizer::tokenizeWhole('   ');
            expect($result)->toBe([]);
        });
        
        it('should preserve internal spacing after normalization', function() {
            $result = TextNormalizer::tokenizeWhole('Agaricus   campestris   var.   alba');
            expect($result)->toBe(['Agaricus campestris var. alba']);
        });
        
    });
    
    describe('tokenizeByStrategy() - unified interface', function() {
        
        it('should use split strategy when specified', function() {
            $result = TextNormalizer::tokenizeByStrategy('Agaricus campestris', 'split');
            expect($result)->toBe(['Agaricus', 'campestris']);
        });
        
        it('should use whole strategy when specified', function() {
            $result = TextNormalizer::tokenizeByStrategy('Agaricus campestris', 'whole');
            expect($result)->toBe(['Agaricus campestris']);
        });
        
        it('should default to whole strategy when strategy is null', function() {
            $result = TextNormalizer::tokenizeByStrategy('Agaricus campestris', null);
            expect($result)->toBe(['Agaricus campestris']);  // Changed default to whole
        });

        it('should default to whole strategy when strategy is empty string', function() {
            $result = TextNormalizer::tokenizeByStrategy('Agaricus campestris', '');
            expect($result)->toBe(['Agaricus campestris']);  // Changed default to whole
        });
        
        it('should throw exception for invalid strategy', function() {
            $closure = function() {
                TextNormalizer::tokenizeByStrategy('test', 'invalid');
            };
            expect($closure)->toThrow(new InvalidArgumentException());
        });
        
    });
    
    describe('parseColumnDef() - with tokenization strategy', function() {
        
        it('should parse column with whole strategy', function() {
            $result = TextNormalizer::parseColumnDef('sciname:text:whole');
            expect($result)->toBe([
                'name' => 'sciname',
                'type' => 'text',
                'display' => false,
                'fk' => false,
                'root' => false,
                'exclude' => false,
                'strategy' => 'whole'
            ]);
        });

        it('should parse column with split strategy', function() {
            $result = TextNormalizer::parseColumnDef('locality:text:split');
            expect($result)->toBe([
                'name' => 'locality',
                'type' => 'text',
                'display' => false,
                'fk' => false,
                'root' => false,
                'exclude' => false,
                'strategy' => 'split'
            ]);
        });

        it('should default to whole strategy when not specified', function() {
            $result = TextNormalizer::parseColumnDef('catalogNumber:text');
            expect($result)->toBe([
                'name' => 'catalogNumber',
                'type' => 'text',
                'display' => false,
                'fk' => false,
                'root' => false,
                'exclude' => false,
                'strategy' => 'whole'  // Changed default from split to whole
            ]);
        });

        it('should parse column with exclude flag and strategy', function() {
            $result = TextNormalizer::parseColumnDef('url:text:exclude:whole');
            expect($result)->toBe([
                'name' => 'url',
                'type' => 'text',
                'display' => true,  // :exclude treated as :display
                'fk' => false,
                'root' => false,
                'exclude' => true,
                'strategy' => 'whole'
            ]);
        });

        it('should handle numeric columns (no strategy)', function() {
            $result = TextNormalizer::parseColumnDef('decimalLatitude:numeric');
            expect($result)->toBe([
                'name' => 'decimalLatitude',
                'type' => 'numeric',
                'display' => false,
                'fk' => false,
                'root' => false,
                'exclude' => false,
                'strategy' => null
            ]);
        });

        it('should handle date columns with default whole strategy', function() {
            $result = TextNormalizer::parseColumnDef('eventDate:date');
            expect($result)->toBe([
                'name' => 'eventDate',
                'type' => 'date',
                'display' => false,
                'fk' => false,
                'root' => false,
                'exclude' => false,
                'strategy' => 'whole'  // Changed default from split to whole
            ]);
        });
        
    });
    
});

