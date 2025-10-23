<?php

use Symbiota\Helpers\Core\DiscoveringRouter;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\OutputHandler;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\Environment;

describe('DiscoveringRouter', function() {
    
    beforeEach(function() {
        $this->config = new Configuration([
            'app' => ['name' => 'Test App'],
            'templates_path' => __DIR__ . '/../../templates'
        ]);

        $this->discovery = new ModelDiscovery([__DIR__ . '/../../src/Models']);

        // Create a proper TemplateEngine instance for testing
        $this->templateEngine = new TemplateEngine(__DIR__ . '/../../templates');
        $this->outputHandler = new OutputHandler($this->templateEngine, false); // Force HTTP mode for testing

        $this->router = new DiscoveringRouter($this->discovery, $this->outputHandler, [
            'app' => ['name' => 'Test App'],
            'templates_path' => __DIR__ . '/../../templates'
        ]);
    });
    
    describe('constructor', function() {
        it('should create instance with dependencies', function() {
            expect($this->router)->toBeAnInstanceOf(DiscoveringRouter::class);
        });
    });
    
    describe('utility methods', function() {
        describe('getDiscovery', function() {
            it('should return discovery service', function() {
                $discovery = $this->router->getDiscovery();
                expect($discovery)->toBeAnInstanceOf(ModelDiscovery::class);
            });
        });

        describe('getAvailableModels', function() {
            it('should return available models', function() {
                $models = $this->router->getAvailableModels();
                expect($models)->toBeA('array');
            });
        });

        describe('modelExists', function() {
            it('should check if model exists', function() {
                $exists = $this->router->modelExists('genbank');
                expect($exists)->toBeA('boolean');
            });
        });

        describe('isModelEnabled', function() {
            it('should check if model is enabled', function() {
                $enabled = $this->router->isModelEnabled('genbank');
                expect($enabled)->toBeA('boolean');
            });
        });
    });
    
});
