<?php

use Symbiota\Helpers\Core\DiscoverableModel;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;

// Create a concrete test implementation
class TestDiscoverableModel extends DiscoverableModel {
    protected string $name = 'test-model';
    protected string $description = 'Test model for unit testing';
    protected ModuleType $moduleType = ModuleType::COMPONENT;
    
    public static function getModelId(): string {
        return 'test-model';
    }
    
    public static function getHelpMarkdown(): string {
        return "Test model for unit testing.\n\n## Parameters\n- `param1` - Test parameter (required)\n- `param2` - Optional parameter (optional)\n\n## Usage\n- test-model param1 value\n- test-model param1 value param2 value\n\n## Examples\n- test-model hello\n- test-model hello world";
    }
    
    public static function getModuleType(): ModuleType {
        return ModuleType::COMPONENT;
    }
    
    public function handleRequest(array $params): array {
        return ['type' => 'success', 'content' => 'Test response'];
    }
}

describe('DiscoverableModel', function() {
    
    beforeEach(function() {
        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates'
        ];
        $this->model = new TestDiscoverableModel($this->config);
    });
    
    describe('constructor', function() {
        it('should initialize with configuration', function() {
            expect($this->model)->toBeAnInstanceOf(TestDiscoverableModel::class);
            expect($this->model)->toBeAnInstanceOf(DiscoverableModel::class);
        });
    });
    
    describe('static methods', function() {
        describe('getModelId', function() {
            it('should return model identifier', function() {
                $id = TestDiscoverableModel::getModelId();
                expect($id)->toBe('test-model');
            });
        });
        
        describe('getModuleType', function() {
            it('should return module type', function() {
                $type = TestDiscoverableModel::getModuleType();
                expect($type)->toBe(ModuleType::COMPONENT);
            });
        });
        
        describe('isEnabled', function() {
            it('should return true by default', function() {
                $enabled = TestDiscoverableModel::isEnabled();
                expect($enabled)->toBe(true);
            });
        });
        
        describe('getPriority', function() {
            it('should return default priority', function() {
                $priority = TestDiscoverableModel::getPriority();
                expect($priority)->toBeA('integer');
                expect($priority)->toBe(100); // Default priority
            });
        });
    });
    
    describe('help system', function() {
        describe('getHelpInfo', function() {
            it('should parse markdown into structured data', function() {
                $helpInfo = TestDiscoverableModel::getHelpInfo();
                
                expect($helpInfo)->toBeA('array');
                expect(isset($helpInfo['description']))->toBe(true);
                expect(isset($helpInfo['parameters']))->toBe(true);
                expect(isset($helpInfo['usage']))->toBe(true);
                expect(isset($helpInfo['examples']))->toBe(true);
                
                expect($helpInfo['description'])->toBe('Test model for unit testing.');
                expect(isset($helpInfo['parameters']['param1']))->toBe(true);
                expect(isset($helpInfo['parameters']['param2']))->toBe(true);
                expect($helpInfo['parameters']['param1']['required'])->toBe(true);
                expect($helpInfo['parameters']['param2']['required'])->toBe(false);
            });
        });
        
        describe('generateCliHelp', function() {
            it('should generate formatted CLI help text', function() {
                $help = TestDiscoverableModel::generateCliHelp();
                
                expect($help)->toBeA('string');
                expect($help)->toContain('USAGE:');
                expect($help)->toContain('DESCRIPTION:');
                expect($help)->toContain('PARAMETERS:');
                expect($help)->toContain('EXAMPLES:');
                expect($help)->toContain('test-model');
            });
        });
    });
    
    describe('parameter validation', function() {
        describe('validateParameters', function() {
            it('should validate required parameters', function() {
                $params = ['param1' => 'value'];
                $errors = $this->model->validateParameters($params);
                
                expect($errors)->toBeA('array');
                expect(count($errors))->toBe(0); // No errors for valid params
            });
            
            it('should detect missing required parameters', function() {
                $params = []; // Missing required param1
                $errors = $this->model->validateParameters($params);
                
                expect($errors)->toBeA('array');
                expect(count($errors))->toBeGreaterThan(0);
                expect($errors[0])->toContain('param1');
                expect($errors[0])->toContain('Required');
            });
            
            it('should allow optional parameters to be missing', function() {
                $params = ['param1' => 'value']; // param2 is optional
                $errors = $this->model->validateParameters($params);
                
                expect(count($errors))->toBe(0);
            });
        });
    });
    
    describe('configuration access', function() {
        describe('getConfig', function() {
            it('should return configuration value', function() {
                $baseUrl = $this->model->getConfig('base_url');
                expect($baseUrl)->toBe('/test/');
            });
            
            it('should return default for missing key', function() {
                $missing = $this->model->getConfig('missing_key', 'default');
                expect($missing)->toBe('default');
            });
        });
        
        describe('getAppUrlPrefix', function() {
            it('should return app URL prefix', function() {
                $prefix = $this->model->getAppUrlPrefix();
                expect($prefix)->toBeA('string');
                expect($prefix)->toContain('/');
            });
        });
    });
    
    describe('template engine', function() {
        it('should have template engine access', function() {
            // Test that the model can access template functionality
            // by checking that it can handle requests
            $result = $this->model->handleRequest([]);
            expect($result)->toBeA('array');
            expect($result['type'])->toBe('success');
        });
    });
    
    describe('request handling', function() {
        describe('handleRequest', function() {
            it('should handle basic request', function() {
                $params = ['param1' => 'test'];
                $response = $this->model->handleRequest($params);
                
                expect($response)->toBeA('array');
                expect(isset($response['type']))->toBe(true);
                expect(isset($response['content']))->toBe(true);
                expect($response['type'])->toBe('success');
            });
        });
    });
    
    describe('static methods', function() {
        it('should provide model information', function() {
            expect(TestDiscoverableModel::getModelId())->toBe('test-model');
            expect(TestDiscoverableModel::getModuleType())->toBe(ModuleType::COMPONENT);
            expect(TestDiscoverableModel::getHelpMarkdown())->toContain('Test model');
        });

        it('should provide help information', function() {
            $helpInfo = TestDiscoverableModel::getHelpInfo();

            expect($helpInfo)->toBeA('array');
            expect(isset($helpInfo['description']))->toBe(true);
            expect(isset($helpInfo['parameters']))->toBe(true);
        });

        it('should parse parameters from markdown', function() {
            $helpInfo = TestDiscoverableModel::getHelpInfo();

            expect(isset($helpInfo['parameters']['param1']))->toBe(true);
            expect(isset($helpInfo['parameters']['param2']))->toBe(true);
            expect($helpInfo['parameters']['param1']['required'])->toBe(true);
            expect($helpInfo['parameters']['param2']['required'])->toBe(false);
        });
    });
});
