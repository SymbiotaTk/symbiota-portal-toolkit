<?php

use Symbiota\Helpers\Core\TemplateEngine;

describe('TemplateEngine', function() {
    beforeEach(function() {
        $this->engine = new TemplateEngine();
    });

    describe('template registration', function() {
        it('should register and render templates', function() {
            $this->engine->registerTemplate('test', 'Hello {name}!');
            
            $result = $this->engine->render('test', ['name' => 'World']);
            expect($result)->toBe('Hello World!');
        });

        it('should check if template exists', function() {
            $this->engine->registerTemplate('exists', 'content');
            
            expect($this->engine->hasTemplate('exists'))->toBe(true);
            expect($this->engine->hasTemplate('missing'))->toBe(false);
        });

        it('should unregister templates', function() {
            $this->engine->registerTemplate('temp', 'content');
            expect($this->engine->hasTemplate('temp'))->toBe(true);
            
            $this->engine->unregisterTemplate('temp');
            expect($this->engine->hasTemplate('temp'))->toBe(false);
        });
    });

    describe('variable interpolation', function() {
        it('should interpolate simple variables', function() {
            $template = 'Hello {name}, you are {age} years old';
            $variables = ['name' => 'John', 'age' => 30];
            
            $result = $this->engine->render($template, $variables);
            expect($result)->toBe('Hello John, you are 30 years old');
        });

        it('should handle default values', function() {
            $template = 'Hello {name|Guest}, welcome to {site|our site}';
            $variables = ['name' => 'John'];
            
            $result = $this->engine->render($template, $variables);
            expect($result)->toBe('Hello John, welcome to our site');
        });

        it('should leave unknown placeholders unchanged', function() {
            $template = 'Hello {name}, {unknown} placeholder';
            $variables = ['name' => 'John'];
            
            $result = $this->engine->render($template, $variables);
            expect($result)->toBe('Hello John, {unknown} placeholder');
        });
    });

    describe('global variables', function() {
        it('should use global variables', function() {
            $this->engine->setGlobalVariables(['site' => 'MyApp']);

            $result = $this->engine->render('Welcome to {site}!', []);
            expect($result)->toBe('Welcome to MyApp!');
        });

        it('should prioritize local over global variables', function() {
            $this->engine->setGlobalVariables(['name' => 'Global']);

            $result = $this->engine->render('Hello {name}!', ['name' => 'Local']);
            expect($result)->toBe('Hello Local!');
        });

        it('should handle app_url_prefix variable replacement correctly', function() {
            // Test the specific issue we're seeing with app_url_prefix
            $this->engine->setGlobalVariables(['app_url_prefix' => '/?/']);

            $template = 'URL: {app_url_prefix}upload/chunk';
            $result = $this->engine->render($template, []);
            expect($result)->toBe('URL: /?/upload/chunk');
            expect($result)->not->toContain('{app_url_prefix}');
        });

        it('should override global app_url_prefix with local variable', function() {
            // Test that local variables override global ones for app_url_prefix
            $this->engine->setGlobalVariables(['app_url_prefix' => '/global/']);

            $template = 'URL: {app_url_prefix}upload/chunk';
            $result = $this->engine->render($template, ['app_url_prefix' => '/local/']);
            expect($result)->toBe('URL: /local/upload/chunk');
            expect($result)->not->toContain('{app_url_prefix}');
        });
    });

    describe('sprintf functionality', function() {
        it('should format strings with sprintf', function() {
            $result = $this->engine->sprintf('Hello %s, you have %d messages', 'John', 5);
            expect($result)->toBe('Hello John, you have 5 messages');
        });

        it('should handle sprintf errors gracefully', function() {
            $result = $this->engine->sprintf('Hello %s %s', 'John');
            expect($result)->toContain('SPRINTF_ERROR');
        });
    });

    describe('template management', function() {
        it('should get template names', function() {
            $this->engine->registerTemplate('template1', 'content1');
            $this->engine->registerTemplate('template2', 'content2');
            
            $names = $this->engine->getTemplateNames();
            expect($names)->toContain('template1');
            expect($names)->toContain('template2');
        });

        it('should clear all templates and variables', function() {
            $this->engine->registerTemplate('test', 'content');
            $this->engine->setGlobalVariables(['var' => 'value']);
            
            $this->engine->clear();
            
            expect($this->engine->hasTemplate('test'))->toBe(false);
            expect($this->engine->getTemplateNames())->toBeEmpty();
        });
    });

    describe('inline template rendering', function() {
        it('should render inline templates without registration', function() {
            $template = 'Hello {name}!';
            $result = $this->engine->render($template, ['name' => 'World']);
            expect($result)->toBe('Hello World!');
        });
    });

    describe('CSS and template variable separation', function() {
        it('should not replace CSS blocks as template variables', function() {
            // Template with CSS that contains curly braces
            $template = '<style>.class{box-sizing:border-box;color:red}</style><p>Hello {name}!</p>';
            $result = $this->engine->render($template, ['name' => 'World']);

            // CSS should remain unchanged, only template variables should be replaced
            expect($result)->toBe('<style>.class{box-sizing:border-box;color:red}</style><p>Hello World!</p>');
            expect($result)->toContain('box-sizing:border-box');
            expect($result)->toContain('color:red');
            expect($result)->not->toContain('{name}');
        });

        it('should handle complex CSS with multiple properties', function() {
            $cssTemplate = '.dropzone{position:relative;display:block}.preview{width:120px;margin:0.5em}';
            $template = "<style>$cssTemplate</style>URL: {app_url_prefix}upload";

            $result = $this->engine->render($template, ['app_url_prefix' => '/?/']);

            // CSS should be preserved exactly
            expect($result)->toContain($cssTemplate);
            // Template variable should be replaced
            expect($result)->toContain('URL: /?/upload');
            expect($result)->not->toContain('{app_url_prefix}');
        });
    });
});
