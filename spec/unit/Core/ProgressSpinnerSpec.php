<?php

use Symbiota\Helpers\Core\ProgressSpinner;

describe('ProgressSpinner', function() {
    
    describe('constructor', function() {
        it('should detect CLI mode automatically', function() {
            $spinner = new ProgressSpinner();
            expect($spinner)->toBeAnInstanceOf(ProgressSpinner::class);
        });
        
        it('should accept explicit CLI mode', function() {
            $spinner = new ProgressSpinner(true);
            expect($spinner)->toBeAnInstanceOf(ProgressSpinner::class);
        });
        
        it('should accept explicit non-CLI mode', function() {
            $spinner = new ProgressSpinner(false);
            expect($spinner)->toBeAnInstanceOf(ProgressSpinner::class);
        });
    });
    
    describe('CLI operations', function() {
        beforeEach(function() {
            $this->spinner = new ProgressSpinner(true); // Force CLI mode
        });
        
        it('should start and stop without errors', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->stop();
            })->not->toThrow();
        });
        
        it('should handle success completion', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->succeed('Success!');
            })->not->toThrow();
        });
        
        it('should handle failure completion', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->fail('Failed!');
            })->not->toThrow();
        });
        
        it('should handle warning completion', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->warn('Warning!');
            })->not->toThrow();
        });
        
        it('should handle info completion', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->info('Info!');
            })->not->toThrow();
        });
        
        it('should handle message updates', function() {
            expect(function() {
                $this->spinner->start('Initial...');
                $this->spinner->update('Updated...');
                $this->spinner->succeed('Done!');
            })->not->toThrow();
        });
        
        it('should handle tick updates', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->tick();
                $this->spinner->tick();
                $this->spinner->succeed('Done!');
            })->not->toThrow();
        });
        
        it('should handle progress bar', function() {
            expect(function() {
                $this->spinner->progressBar(0, 10, 'Starting');
                $this->spinner->progressBar(5, 10, 'Halfway');
                $this->spinner->progressBar(10, 10, 'Complete');
            })->not->toThrow();
        });
    });
    
    describe('non-CLI operations', function() {
        beforeEach(function() {
            $this->spinner = new ProgressSpinner(false); // Force non-CLI mode
        });
        
        it('should silently handle operations in non-CLI mode', function() {
            expect(function() {
                $this->spinner->start('Testing...');
                $this->spinner->update('Updated...');
                $this->spinner->tick();
                $this->spinner->succeed('Done!');
            })->not->toThrow();
        });
        
        it('should handle progress bar in non-CLI mode', function() {
            expect(function() {
                $this->spinner->progressBar(5, 10, 'Testing');
            })->not->toThrow();
        });
    });
    
    describe('static during method', function() {
        it('should execute callback and return result', function() {
            $result = ProgressSpinner::during(function() {
                return 'test result';
            }, 'Processing...', false); // Force non-CLI for testing
            
            expect($result)->toBe('test result');
        });
        
        it('should handle exceptions in callback', function() {
            expect(function() {
                ProgressSpinner::during(function() {
                    throw new Exception('Test error');
                }, 'Processing...', false);
            })->toThrow(new Exception('Test error'));
        });
    });
});
