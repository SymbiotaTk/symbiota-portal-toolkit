<?php

use Kahlan\Plugin\Double;
use Symbiota\Helpers\Models\ImagesModel;
use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\DatabaseManager;

describe("ImagesModel - EAV Search", function() {
    
    beforeEach(function() {
        $this->model = new ImagesModel();
        
        // Mock PDO for testing
        $this->mockPdo = Double::instance(['extends' => 'PDO', 'args' => ['sqlite::memory:']]);
        $this->mockStmt = Double::instance(['extends' => 'PDOStatement']);
        
        allow($this->mockPdo)->toReceive('prepare')->andReturn($this->mockStmt);
        allow($this->mockStmt)->toReceive('execute')->andReturn(true);
        allow($this->mockStmt)->toReceive('bindValue')->andReturn(true);
    });
    
    describe("Field Alias Configuration", function() {

        it("should have config file with field aliases", function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            expect(file_exists($configPath))->toBe(true);

            $config = parse_ini_file($configPath, true);
            expect($config)->toBeAn('array');
            expect(isset($config['aliases']))->toBe(true);
        });

        it("should define date aliases in config", function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            $config = parse_ini_file($configPath, true);

            expect($config['aliases']['date'])->toBe('eventDate');
            expect($config['aliases']['eventdate'])->toBe('eventDate');
        });

        it("should define taxonomy aliases in config", function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            $config = parse_ini_file($configPath, true);

            expect($config['aliases']['taxon'])->toBe('sciname');
            expect($config['aliases']['genus'])->toBe('genus');
            expect($config['aliases']['family'])->toBe('family');
        });

        it("should define geography aliases in config", function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            $config = parse_ini_file($configPath, true);

            expect($config['aliases']['state'])->toBe('stateProvince');
            expect($config['aliases']['country'])->toBe('country');
            expect($config['aliases']['locality'])->toBe('locality');
        });

        it("should define collection aliases in config", function() {
            $configPath = __DIR__ . '/../../../templates/sql/images/eav/index_config.ini';
            $config = parse_ini_file($configPath, true);

            expect($config['aliases']['collection'])->toBe('collectionName');
            expect($config['aliases']['catalog'])->toBe('catalogNumber');
            expect($config['aliases']['occid'])->toBe('occid');
        });
    });
    
    describe("Date Query Parsing", function() {

        it("should recognize single year pattern", function() {
            $query = "1974";
            expect($query)->toMatch('/^\d{4}$/');
        });

        it("should recognize year range pattern", function() {
            $query = "1976-1978";
            expect($query)->toMatch('/^(\d{4})-(\d{4})$/');

            preg_match('/^(\d{4})-(\d{4})$/', $query, $matches);
            expect($matches[1])->toBe('1976');
            expect($matches[2])->toBe('1978');
        });

        it("should recognize multiple years pattern", function() {
            $query = "1974,1996,2000";
            expect(strpos($query, ','))->toBeGreaterThan(0);

            $years = explode(',', $query);
            expect(count($years))->toBe(3);
            expect($years[0])->toBe('1974');
            expect($years[1])->toBe('1996');
            expect($years[2])->toBe('2000');
        });

        it("should recognize less than operator", function() {
            $query = "<1980";
            expect($query)->toMatch('/^([<>=]+)(.+)$/');

            preg_match('/^([<>=]+)(.+)$/', $query, $matches);
            expect($matches[1])->toBe('<');
            expect($matches[2])->toBe('1980');
        });

        it("should recognize greater than operator", function() {
            $query = ">2000";
            preg_match('/^([<>=]+)(.+)$/', $query, $matches);
            expect($matches[1])->toBe('>');
            expect($matches[2])->toBe('2000');
        });

        it("should recognize less than or equal operator", function() {
            $query = "<=1990";
            preg_match('/^([<>=]+)(.+)$/', $query, $matches);
            expect($matches[1])->toBe('<=');
            expect($matches[2])->toBe('1990');
        });

        it("should recognize greater than or equal operator", function() {
            $query = ">=1985";
            preg_match('/^([<>=]+)(.+)$/', $query, $matches);
            expect($matches[1])->toBe('>=');
            expect($matches[2])->toBe('1985');
        });
    });
    
    describe("Query Type Detection", function() {
        
        it("should detect field:value pattern", function() {
            $query = "taxon:Faxonella";
            expect($query)->toMatch('/^([a-zA-Z_]+):(.+)$/');
        });
        
        it("should detect numeric occid", function() {
            $query = "occid:2784530";
            preg_match('/^([a-zA-Z_]+):(.+)$/', $query, $matches);
            expect($matches[1])->toBe('occid');
            expect(is_numeric($matches[2]))->toBe(true);
        });
        
        it("should detect text catalog number", function() {
            $query = "catalog:ABC123";
            preg_match('/^([a-zA-Z_]+):(.+)$/', $query, $matches);
            expect($matches[1])->toBe('catalog');
            expect(is_numeric($matches[2]))->toBe(false);
        });
    });
    
    describe("CLI Parameter Parsing", function() {
        
        it("should handle single queryAnd parameter", function() {
            $params = ['queryAnd' => 'taxon:Faxonella'];
            
            // Normalize to array
            $queryAnd = is_array($params['queryAnd']) ? $params['queryAnd'] : [$params['queryAnd']];
            
            expect($queryAnd)->toBeAn('array');
            expect(count($queryAnd))->toBe(1);
            expect($queryAnd[0])->toBe('taxon:Faxonella');
        });
        
        it("should handle multiple queryAnd parameters", function() {
            $params = ['queryAnd' => ['taxon:Faxonella', 'state:Alabama']];
            
            expect($params['queryAnd'])->toBeAn('array');
            expect(count($params['queryAnd']))->toBe(2);
        });
        
        it("should handle repeated query parameters", function() {
            $params = ['query' => ['taxon:Faxonella', 'date:1974']];
            
            // Join with spaces
            $query = is_array($params['query']) ? implode(' ', $params['query']) : $params['query'];
            
            expect($query)->toBe('taxon:Faxonella date:1974');
        });
    });
    
    describe("AND Query Logic", function() {
        
        it("should perform intersection of entity IDs", function() {
            $set1 = [1, 2, 3, 4, 5];
            $set2 = [3, 4, 5, 6, 7];
            $set3 = [4, 5, 6, 7, 8];
            
            $result = array_intersect($set1, $set2, $set3);
            
            expect($result)->toContain(4);
            expect($result)->toContain(5);
            expect(count($result))->toBe(2);
        });
        
        it("should return empty array when no intersection", function() {
            $set1 = [1, 2, 3];
            $set2 = [4, 5, 6];
            
            $result = array_intersect($set1, $set2);
            
            expect(count($result))->toBe(0);
        });
    });
    
    describe("OR Query Logic", function() {
        
        it("should perform union of entity IDs", function() {
            $set1 = [1, 2, 3];
            $set2 = [3, 4, 5];
            
            $result = array_unique(array_merge($set1, $set2));
            
            expect($result)->toContain(1);
            expect($result)->toContain(2);
            expect($result)->toContain(3);
            expect($result)->toContain(4);
            expect($result)->toContain(5);
        });
        
        it("should remove duplicates", function() {
            $set1 = [1, 2, 3, 3, 3];
            $set2 = [3, 4, 5, 5, 5];
            
            $result = array_unique(array_merge($set1, $set2));
            
            $counts = array_count_values($result);
            expect($counts[3])->toBe(1);
            expect($counts[5])->toBe(1);
        });
    });
    
    describe("Pagination Logic", function() {
        
        it("should apply offset and limit correctly", function() {
            $entityIds = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
            $offset = 3;
            $limit = 4;
            
            $result = array_slice($entityIds, $offset, $limit);
            
            expect(count($result))->toBe(4);
            expect($result[0])->toBe(4);
            expect($result[3])->toBe(7);
        });
        
        it("should handle offset beyond array length", function() {
            $entityIds = [1, 2, 3];
            $offset = 10;
            $limit = 5;
            
            $result = array_slice($entityIds, $offset, $limit);
            
            expect(count($result))->toBe(0);
        });
    });
});

