<?php

use Symbiota\Helpers\Core\EavIndexing;

describe('EavIndexing', function() {
    
    describe('::parseConfig()', function() {
        
        beforeEach(function() {
            // Create temporary test config file
            $this->testConfigPath = sys_get_temp_dir() . '/test_eav_config_' . uniqid() . '.ini';
        });
        
        afterEach(function() {
            if (file_exists($this->testConfigPath)) {
                unlink($this->testConfigPath);
            }
        });
        
        it('should parse a valid INI configuration file', function() {
            $configContent = <<<INI
[_config]
root_table = media
root_id_column = mediaID

[media]
relationship = ""
columns[] = mediaID:text:whole
columns[] = url:text:display
columns[] = occid:text:whole
INI;
            file_put_contents($this->testConfigPath, $configContent);
            
            $config = EavIndexing::parseConfig($this->testConfigPath);

            expect($config)->toBeAn('array');
            expect(isset($config['_config']))->toBe(true);
            expect(isset($config['media']))->toBe(true);
            expect($config['_config']['root_table'])->toBe('media');
            expect($config['_config']['root_id_column'])->toBe('mediaID');
        });
        
        it('should throw exception if config file does not exist', function() {
            $closure = function() {
                EavIndexing::parseConfig('/nonexistent/path/config.ini');
            };
            
            expect($closure)->toThrow(new Exception());
        });
        
        it('should throw exception if config file is empty', function() {
            file_put_contents($this->testConfigPath, '');
            
            $closure = function() {
                EavIndexing::parseConfig($this->testConfigPath);
            };
            
            expect($closure)->toThrow(new Exception());
        });
        
        it('should throw exception if config file is invalid INI', function() {
            file_put_contents($this->testConfigPath, 'invalid [ ini content');
            
            $closure = function() {
                EavIndexing::parseConfig($this->testConfigPath);
            };
            
            expect($closure)->toThrow(new Exception());
        });
    });
    
    describe('::getDisplayFields()', function() {
        
        it('should extract display fields from config', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'url:text:display',
                        'originalUrl:text:display',
                        'thumbnailUrl:text:display',
                        'occid:text:whole'
                    ]
                ]
            ];
            
            $displayFields = EavIndexing::getDisplayFields($config);
            
            expect($displayFields)->toBeAn('array');
            expect($displayFields)->toHaveLength(3);
            expect($displayFields[0]['name'])->toBe('url');
            expect($displayFields[0]['type'])->toBe('text');
            expect($displayFields[1]['name'])->toBe('originalUrl');
            expect($displayFields[2]['name'])->toBe('thumbnailUrl');
        });
        
        it('should return empty array if no display fields', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'occid:text:whole'
                    ]
                ]
            ];
            
            $displayFields = EavIndexing::getDisplayFields($config);
            
            expect($displayFields)->toBeAn('array');
            expect($displayFields)->toHaveLength(0);
        });
        
        it('should only extract display fields from root table', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'url:text:display'
                    ]
                ],
                'omoccurrences' => [
                    'relationship' => 'media.occid = omoccurrences.occid',
                    'columns' => [
                        'catalogNumber:text:display'  // Should be ignored
                    ]
                ]
            ];
            
            $displayFields = EavIndexing::getDisplayFields($config);
            
            expect($displayFields)->toHaveLength(1);
            expect($displayFields[0]['name'])->toBe('url');
        });
    });
    
    describe('::getIndexedFields()', function() {
        
        it('should extract indexed text fields from config', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'url:text:display',
                        'occid:text:whole',
                        'recordedBy:text:split'
                    ]
                ]
            ];
            
            $indexedFields = EavIndexing::getIndexedFields($config);
            
            expect($indexedFields)->toBeAn('array');
            expect($indexedFields)->toHaveLength(3);
            expect($indexedFields[0]['name'])->toBe('mediaID');
            expect($indexedFields[0]['strategy'])->toBe('whole');
            expect($indexedFields[1]['name'])->toBe('occid');
            expect($indexedFields[2]['name'])->toBe('recordedBy');
            expect($indexedFields[2]['strategy'])->toBe('split');
        });
        
        it('should exclude display fields from indexed fields', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'url:text:display',
                        'occid:text:whole'
                    ]
                ]
            ];
            
            $indexedFields = EavIndexing::getIndexedFields($config);
            
            expect($indexedFields)->toHaveLength(2);
            expect($indexedFields[0]['name'])->toBe('mediaID');
            expect($indexedFields[1]['name'])->toBe('occid');
        });
    });
    
    describe('::getNumericFields()', function() {
        
        it('should extract numeric fields from config', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'sortsequence:numeric'
                    ]
                ],
                'omoccurrences' => [
                    'relationship' => 'media.occid = omoccurrences.occid',
                    'columns' => [
                        'decimalLatitude:numeric',
                        'decimalLongitude:numeric'
                    ]
                ]
            ];
            
            $numericFields = EavIndexing::getNumericFields($config);
            
            expect($numericFields)->toBeAn('array');
            expect($numericFields)->toHaveLength(3);
            expect($numericFields[0]['name'])->toBe('sortsequence');
            expect($numericFields[0]['type'])->toBe('numeric');
            expect($numericFields[1]['name'])->toBe('decimalLatitude');
            expect($numericFields[2]['name'])->toBe('decimalLongitude');
        });
        
        it('should return empty array if no numeric fields', function() {
            $config = [
                '_config' => [
                    'root_table' => 'media',
                    'root_id_column' => 'mediaID'
                ],
                'media' => [
                    'relationship' => '',
                    'columns' => [
                        'mediaID:text:whole',
                        'url:text:display'
                    ]
                ]
            ];
            
            $numericFields = EavIndexing::getNumericFields($config);
            
            expect($numericFields)->toBeAn('array');
            expect($numericFields)->toHaveLength(0);
        });
    });
    
    describe('::getSqlType()', function() {
        
        it('should return TEXT for text type', function() {
            expect(EavIndexing::getSqlType('text'))->toBe('TEXT');
        });
        
        it('should return REAL for numeric type', function() {
            expect(EavIndexing::getSqlType('numeric'))->toBe('REAL');
        });
        
        it('should return TEXT for date type', function() {
            expect(EavIndexing::getSqlType('date'))->toBe('TEXT');
        });
        
        it('should return TEXT for unknown type', function() {
            expect(EavIndexing::getSqlType('unknown'))->toBe('TEXT');
        });
    });
});

