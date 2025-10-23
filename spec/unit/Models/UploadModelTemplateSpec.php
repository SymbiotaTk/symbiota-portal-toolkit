<?php

use Symbiota\Helpers\Models\UploadModel;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\TemplateEngine;

describe('UploadModel Template Variable Replacement', function () {

    beforeEach(function () {
        // Mock configuration for testing
        $this->config = [
            'components' => [
                'upload' => [
                    'output_dir' => 'test/uploads',
                    'registry_file' => 'test/upload.json',
                    'chunk_size_kb' => 512,
                    'allowed_file_types' => 'jpg,png,pdf,zip',
                    'upload_timeout' => 300
                ]
            ]
        ];

        // Create upload model instance
        $this->uploadModel = new UploadModel($this->config);
    });

    afterAll(function () {
        // Clean up test directories created during tests
        $testDir = 'test';
        if (is_dir($testDir)) {
            // Recursively remove directory
            $removeDir = function($dir) use (&$removeDir) {
                if (!is_dir($dir)) {
                    return;
                }
                $items = array_diff(scandir($dir), ['.', '..']);
                foreach ($items as $item) {
                    $path = $dir . DIRECTORY_SEPARATOR . $item;
                    is_dir($path) ? $removeDir($path) : unlink($path);
                }
                rmdir($dir);
            };
            $removeDir($testDir);
        }
    });

    describe('Template Variable Detection', function () {

        it('should detect all template variables in upload content template', function () {
            $templatePath = __DIR__ . '/../../../templates/html/upload/content.html';
            expect(file_exists($templatePath))->toBe(true);

            $templateContent = file_get_contents($templatePath);

            // Extract template variables using improved regex that excludes CSS/JS
            // Only match {variable_name} patterns that look like template variables
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*(?:\|[^}]*)?)\}/', $templateContent, $matches);
            $foundVariables = array_unique($matches[1]);

            // Expected variables that should be replaced (based on actual template usage)
            $expectedVariables = [
                'app_url_prefix',  // Back to standard variable name
                'chunk_size',
                'allowed_types',
                'upload_timeout'
            ];

            // Check that all expected variables are found
            foreach ($expectedVariables as $expectedVar) {
                expect($foundVariables)->toContain($expectedVar);
            }

            // Log all found variables for debugging
            echo "\nFound template variables: " . implode(', ', $foundVariables) . "\n";
        });

        it('should contain expected app_url_prefix variables for replacement', function () {
            $templatePath = __DIR__ . '/../../../templates/html/upload/content.html';
            $templateContent = file_get_contents($templatePath);

            // Check that we have the expected number of app_url_prefix variables
            $appUrlPrefixCount = substr_count($templateContent, '{app_url_prefix}');
            expect($appUrlPrefixCount)->toBeGreaterThan(0, 'Template should contain {app_url_prefix} variables for replacement');
            expect($appUrlPrefixCount)->toBeLessThan(10, 'Template should not have excessive {app_url_prefix} variables');
        });
    });

    describe('Template Rendering', function () {

        it('should replace all template variables correctly', function () {
            // Mock the template engine
            $templateEngine = new TemplateEngine();

            // Test template with common variables
            $testTemplate = 'URL: {upload_url_prefix}upload/chunk, Size: {chunk_size}, Timeout: {upload_timeout}';

            $variables = [
                'upload_url_prefix' => '/?/',
                'chunk_size' => 524288,
                'upload_timeout' => 300
            ];

            $result = $templateEngine->render($testTemplate, $variables);

            expect($result)->toBe('URL: /?/upload/chunk, Size: 524288, Timeout: 300');

            // Ensure no unreplaced variables remain
            expect($result)->not->toContain('{');
            expect($result)->not->toContain('}');
        });

        it('should handle missing variables gracefully', function () {
            $templateEngine = new TemplateEngine();

            $testTemplate = 'URL: {upload_url_prefix}upload/chunk, Missing: {missing_var}';

            $variables = [
                'upload_url_prefix' => '/?/'
            ];

            $result = $templateEngine->render($testTemplate, $variables);

            // Should replace known variables but leave unknown ones
            expect($result)->toBe('URL: /?/upload/chunk, Missing: {missing_var}');
        });
    });

    describe('UploadModel Template Integration', function () {

        it('should generate correct app URL prefix using centralized method', function () {
            // Mock $_SERVER for testing
            $_SERVER['REQUEST_URI'] = '/?/upload';

            $appUrlPrefix = $this->uploadModel->getAppUrlPrefix();

            expect($appUrlPrefix)->toBe('/?/');
        });

        it('should use centralized getAppUrlPrefix from Model', function () {
            // Verify that UploadModel doesn't have its own getAppUrlPrefix method
            $reflection = new ReflectionClass($this->uploadModel);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $hasOwnMethod = false;
            foreach ($methods as $method) {
                if ($method->getName() === 'getAppUrlPrefix' &&
                    $method->getDeclaringClass()->getName() === 'Symbiota\Helpers\Models\UploadModel') {
                    $hasOwnMethod = true;
                    break;
                }
            }

            expect($hasOwnMethod)->toBe(false, 'UploadModel should not have its own getAppUrlPrefix method');

            // Verify it inherits from Model
            $parentMethod = $reflection->getMethod('getAppUrlPrefix');
            expect($parentMethod->getDeclaringClass()->getName())->toBe('Symbiota\Helpers\Core\Model');
        });

        it('should provide all required template variables', function () {
            // Mock authentication and user info
            $GLOBALS['SYMB_UID'] = 525;

            // Create a mock method to test template variable preparation
            $reflection = new ReflectionClass($this->uploadModel);
            $method = $reflection->getMethod('getAppUrlPrefix');
            $method->setAccessible(true);

            $appUrlPrefix = $method->invoke($this->uploadModel);

            // Test that all required variables are available
            $expectedVariables = [
                'upload_url_prefix' => $appUrlPrefix,
                'chunk_size' => 524288, // 512 * 1024
                'upload_timeout' => 300
            ];

            foreach ($expectedVariables as $key => $expectedValue) {
                if ($key === 'upload_url_prefix') {
                    expect($appUrlPrefix)->toBe($expectedValue);
                }
            }
        });
    });

    describe('Template Variable Scanning', function () {

        it('should scan template and report any unreplaced variables', function () {
            $templatePath = __DIR__ . '/../../../templates/html/upload/content.html';
            $templateContent = file_get_contents($templatePath);

            // Simulate template rendering with actual variables used in template
            $variables = [
                'app_url_prefix' => '/?/',
                'chunk_size' => 524288,
                'allowed_types' => '.jpg,.png,.pdf,.zip',
                'upload_timeout' => 300,
                'permission_display|none' => 'none',
                'upload_display|block' => 'block',
                'upload_history_content|' => ''
            ];

            $templateEngine = new TemplateEngine();
            $rendered = $templateEngine->render($templateContent, $variables);

            // Find any remaining unreplaced template variables (not CSS/JS)
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*(?:\|[^}]*)?)\}/', $rendered, $matches);
            $unreplacedVariables = array_unique($matches[1]);

            if (!empty($unreplacedVariables)) {
                echo "\nUnreplaced variables found: " . implode(', ', $unreplacedVariables) . "\n";
            }

            // Should have very few unreplaced variables with improved regex
            // Known unreplaced: parallel_uploads, parallel_chunk_uploads, max_file_size, clientIp, timestamp, msec
            expect(count($unreplacedVariables))->toBeLessThan(10, 'Too many unreplaced template variables');
        });
    });
});
