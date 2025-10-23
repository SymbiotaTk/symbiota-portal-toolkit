<?php

use Symbiota\Helpers\Core\OutputHandler;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\Response;

describe('OutputHandler', function() {
    beforeEach(function() {
        $this->templateEngine = new TemplateEngine();
        $this->cliHandler = new OutputHandler($this->templateEngine, true);
        $this->httpHandler = new OutputHandler($this->templateEngine, false);
    });

    describe('CLI output', function() {
        it('should format help responses', function() {
            $response = Response::json([
                'type' => 'help',
                'message' => 'Test Help',
                'usage' => ['command1', 'command2'],
                'examples' => ['example1', 'example2'],
                'database_options' => ['option1'],
                'database_status' => ['status' => 'ok']
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('=== Test Help ===');
            expect($output)->toContain('Usage:');
            expect($output)->toContain('command1');
            expect($output)->toContain('Examples:');
            expect($output)->toContain('example1');
        });

        it('should format dashboard responses', function() {
            $response = Response::json([
                'type' => 'dashboard',
                'title' => 'Test Dashboard',
                'version' => '1.0',
                'components' => ['comp1', 'comp2']
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('=== Test Dashboard v1.0 ===');
            expect($output)->toContain('Available Components:');
            expect($output)->toContain('- comp1');
            expect($output)->toContain('- comp2');
        });

        it('should format error responses', function() {
            $response = Response::json([
                'type' => 'error',
                'error' => 'Test error message',
                'code' => 500
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('ERROR: Test error message');
            expect($output)->toContain('Code: 500');
        });

        it('should output plain text for CLI type responses', function() {
            $response = Response::json([
                'type' => 'cli',
                'content' => 'This is plain CLI output'
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('This is plain CLI output');
        });

        it('should output plain text for success type responses', function() {
            $response = Response::json([
                'type' => 'success',
                'content' => 'Operation completed successfully'
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('Operation completed successfully');
        });

        it('should respect format parameter for text output', function() {
            $response = Response::json([
                'type' => 'cli',
                'content' => 'Test content',
                'format' => 'text'
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('Test content');
        });

        it('should respect format parameter for JSON output', function() {
            $response = Response::json([
                'type' => 'cli',
                'content' => 'Test content',
                'format' => 'json'
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('"type": "cli"');
            expect($output)->toContain('"content": "Test content"');
        });

        it('should format JSON for other response types', function() {
            $response = Response::json([
                'type' => 'data',
                'results' => ['item1', 'item2']
            ]);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('"type": "data"');
            expect($output)->toContain('"results"');
        });
    });

    describe('HTTP output', function() {
        it('should handle response body generation', function() {
            $response = Response::json(['test' => 'data']);

            // Test the body generation without actually outputting
            expect($response->getBody())->toContain('"test": "data"');
        });

        it('should handle HTML response body generation', function() {
            $response = Response::html('<h1>Test HTML</h1>');

            // Test the body generation without actually outputting
            expect($response->getBody())->toBe('<h1>Test HTML</h1>');
        });
    });

    describe('HTML dashboard creation', function() {
        it('should create HTML dashboard', function() {
            $html = $this->httpHandler->createHtmlDashboard(
                'Test App',
                '2.0',
                ['genbank', 'upload']
            );

            expect($html)->toContain('<!DOCTYPE html>');
            expect($html)->toContain('<title>Test App</title>');
            expect($html)->toContain('<h1>Test App <small>v2.0</small></h1>');
            expect($html)->toContain('<a href="?/genbank">genbank</a>');
            expect($html)->toContain('<a href="?/upload">upload</a>');
        });

        it('should escape HTML in dashboard content', function() {
            $html = $this->httpHandler->createHtmlDashboard(
                'Test <script>',
                '2.0',
                ['test&component']
            );

            expect($html)->toContain('Test &lt;script&gt;');
            expect($html)->toContain('test&amp;component');
        });
    });

    describe('template engine integration', function() {
        it('should provide access to template engine', function() {
            $engine = $this->cliHandler->getTemplateEngine();
            expect($engine)->toBeAnInstanceOf(TemplateEngine::class);
        });

        it('should use template engine for formatting', function() {
            $this->templateEngine->setGlobalVariables(['app_name' => 'TestApp']);

            // Test template rendering directly
            $result = $this->templateEngine->render('Hello {app_name}!', []);
            expect($result)->toBe('Hello TestApp!');

            // Test that the template engine is accessible
            $engine = $this->cliHandler->getTemplateEngine();
            expect($engine)->toBeAnInstanceOf(TemplateEngine::class);
        });
    });

    describe('response handling', function() {
        it('should handle different response types', function() {
            $response = Response::json(['test' => 'data']);

            // Test response properties without outputting
            expect($response->getStatusCode())->toBe(200);
            expect($response->getBody())->toContain('"test": "data"');
        });
    });

    describe('CLI output formatting', function() {
        it('should default to text format for CLI', function() {
            $data = ['type' => 'cli', 'content' => 'test content'];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('test content');
        });

        it('should output JSON when explicitly requested', function() {
            $data = ['type' => 'cli', 'content' => 'test content', 'format' => 'json'];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toContain('"type": "cli"');
            expect($output)->toContain('"content": "test content"');
        });

        it('should output structured JSON for collections data', function() {
            $data = [
                'type' => 'cli',
                'content' => 'Collections list...',
                'collections' => [
                    ['collid' => 1, 'collectionname' => 'Test Collection', 'admins' => []]
                ],
                'summary' => ['total_collections' => 1],
                'format' => 'json'
            ];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);
            expect(isset($decoded['collections']))->toBe(true);
            expect(isset($decoded['summary']))->toBe(true);
            expect(count($decoded['collections']))->toBe(1);
            expect($decoded['summary']['total_collections'])->toBe(1);
        });

        it('should output structured JSON for users data', function() {
            $data = [
                'type' => 'cli',
                'content' => 'Users list...',
                'users' => [
                    ['uid' => 123, 'username' => 'testuser', 'registered' => true]
                ],
                'summary' => ['total_users' => 1],
                'format' => 'json'
            ];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);
            expect(isset($decoded['users']))->toBe(true);
            expect(isset($decoded['summary']))->toBe(true);
            expect(count($decoded['users']))->toBe(1);
            expect($decoded['users'][0]['registered'])->toBe(true);
        });

        it('should output text when explicitly requested', function() {
            $data = ['type' => 'cli', 'content' => 'test content', 'format' => 'text'];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('test content');
        });

        it('should handle success type responses', function() {
            $data = ['type' => 'success', 'content' => 'operation completed'];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            expect($output)->toBe('operation completed');
        });

        it('should split text content into lines for JSON format', function() {
            $data = [
                'type' => 'cli',
                'content' => "Line 1\nLine 2\nLine 3",
                'collections' => [['collid' => 1]],
                'format' => 'json'
            ];
            $response = Response::json($data);

            ob_start();
            $this->cliHandler->output($response);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);
            expect(isset($decoded['_text_content']))->toBe(true);
            expect(is_array($decoded['_text_content']))->toBe(true);
            expect(count($decoded['_text_content']))->toBe(3);
            expect($decoded['_text_content'][0])->toBe('Line 1');
            expect($decoded['_text_content'][1])->toBe('Line 2');
            expect($decoded['_text_content'][2])->toBe('Line 3');
        });
    });
});
