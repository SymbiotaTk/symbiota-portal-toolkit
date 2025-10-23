<?php

use Symbiota\Helpers\Core\Environment;

describe('Environment', function() {
    
    beforeEach(function() {
        // Reset environment detection before each test
        Environment::reset();
    });
    
    describe('Environment detection', function() {
        
        it('should detect CLI environment correctly', function() {
            // Force CLI environment for testing
            Environment::forceEnvironment(Environment::CLI);
            
            expect(Environment::detect())->toBe(Environment::CLI);
            expect(Environment::isCli())->toBe(true);
            expect(Environment::isHttp())->toBe(false);
            expect(Environment::isEmbedded())->toBe(false);
        });
        
        it('should detect HTTP environment correctly', function() {
            Environment::forceEnvironment(Environment::HTTP);
            
            expect(Environment::detect())->toBe(Environment::HTTP);
            expect(Environment::isCli())->toBe(false);
            expect(Environment::isHttp())->toBe(true);
            expect(Environment::isEmbedded())->toBe(false);
        });
        
        it('should detect embedded environment correctly', function() {
            Environment::forceEnvironment(Environment::EMBEDDED);
            
            expect(Environment::detect())->toBe(Environment::EMBEDDED);
            expect(Environment::isCli())->toBe(false);
            expect(Environment::isHttp())->toBe(false);
            expect(Environment::isEmbedded())->toBe(true);
        });
        
        it('should handle unknown environment', function() {
            Environment::forceEnvironment(Environment::UNKNOWN);
            
            expect(Environment::detect())->toBe(Environment::UNKNOWN);
            expect(Environment::isCli())->toBe(false);
            expect(Environment::isHttp())->toBe(false);
            expect(Environment::isEmbedded())->toBe(false);
        });
        
        it('should cache detection result', function() {
            Environment::forceEnvironment(Environment::CLI);
            
            $first = Environment::detect();
            $second = Environment::detect();
            
            expect($first)->toBe($second);
            expect($first)->toBe(Environment::CLI);
        });
        
        it('should reset cached detection', function() {
            Environment::forceEnvironment(Environment::CLI);
            expect(Environment::detect())->toBe(Environment::CLI);
            
            Environment::reset();
            // After reset, it should detect the actual environment
            $detected = Environment::detect();
            expect($detected)->toBeA('string');
        });
    });
    
    describe('Environment information', function() {
        
        it('should provide comprehensive environment info', function() {
            $info = Environment::getInfo();
            
            expect($info)->toBeA('array');
            expect(isset($info['environment']))->toBe(true);
            expect(isset($info['sapi']))->toBe(true);
            expect(isset($info['has_http_host']))->toBe(true);
            expect(isset($info['has_request_method']))->toBe(true);
            expect(isset($info['has_stdin']))->toBe(true);
            expect(isset($info['has_argv']))->toBe(true);
            
            expect($info['environment'])->toBeA('string');
            expect($info['sapi'])->toBeA('string');
            expect($info['has_http_host'])->toBeA('boolean');
            expect($info['has_request_method'])->toBeA('boolean');
            expect($info['has_stdin'])->toBeA('boolean');
            expect($info['has_argv'])->toBeA('boolean');
        });
    });
    
    describe('Environment constants', function() {
        
        it('should provide valid environment constants', function() {
            $environments = Environment::getValidEnvironments();
            
            expect($environments)->toBeA('array');
            expect(count($environments))->toBe(4);
            expect(in_array(Environment::CLI, $environments))->toBe(true);
            expect(in_array(Environment::HTTP, $environments))->toBe(true);
            expect(in_array(Environment::EMBEDDED, $environments))->toBe(true);
            expect(in_array(Environment::UNKNOWN, $environments))->toBe(true);
        });
        
        it('should reject invalid environment in forceEnvironment', function() {
            expect(function() {
                Environment::forceEnvironment('invalid');
            })->toThrow();
        });
    });
    
    describe('Real environment detection', function() {
        
        it('should detect actual CLI environment when running tests', function() {
            Environment::reset();
            
            // When running via Kahlan CLI, should detect CLI
            $detected = Environment::detect();
            expect($detected)->toBe(Environment::CLI);
            expect(Environment::isCli())->toBe(true);
        });
    });
    
    describe('Environment consistency', function() {
        
        it('should maintain consistent detection across multiple calls', function() {
            Environment::reset();
            
            $detections = [];
            for ($i = 0; $i < 5; $i++) {
                $detections[] = Environment::detect();
            }
            
            // All detections should be the same
            $unique = array_unique($detections);
            expect(count($unique))->toBe(1);
        });
        
        it('should provide consistent boolean checks', function() {
            Environment::forceEnvironment(Environment::CLI);
            
            expect(Environment::isCli())->toBe(true);
            expect(Environment::isHttp())->toBe(false);
            expect(Environment::isEmbedded())->toBe(false);
            
            Environment::forceEnvironment(Environment::HTTP);
            
            expect(Environment::isCli())->toBe(false);
            expect(Environment::isHttp())->toBe(true);
            expect(Environment::isEmbedded())->toBe(false);
        });
    });
});
