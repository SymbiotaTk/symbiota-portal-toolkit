<?php

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Ext\SymbAuth;
use Symbiota\Helpers\Core\TemplateFormat;

// Create a concrete test implementation
class TestModel extends Model {
    protected string $name = 'test-model';
    protected string $description = 'Test model for unit testing';
    protected ModuleType $moduleType = ModuleType::COMPONENT;
    protected bool $requiresAuth = true;
    protected array $publicActions = ['index', 'help', 'public-test'];

    public static function getModelId(): string {
        return 'test-model';
    }

    public static function getHelpMarkdown(): string {
        return "Test model for unit testing.\n\n## Parameters\n- `param1` - Test parameter (required)\n- `param2` - Optional parameter (optional)\n\n## Usage\n- test-model param1 value\n- test-model param1 value param2 value\n\n## Examples\n- test-model hello\n- test-model hello world";
    }

    public static function getModuleType(): ModuleType {
        return ModuleType::COMPONENT;
    }

    protected function executeAction(string $action, array $route, array $params): array {
        switch ($action) {
            case 'test-success':
                return $this->successResponse('Test successful');
            case 'test-error':
                return $this->errorResponse('Test error', 400);
            case 'test-htmx':
                return $this->htmxResponse('<div>HTMX content</div>');
            case 'test-html':
                return $this->htmlResponse('<h1>HTML content</h1>');
            case 'test-json':
                return $this->jsonResponse(['status' => 'ok', 'data' => 'test']);
            case 'test-exception':
                throw new \Exception('Test exception');
            case 'test-auth-required':
                return $this->successResponse('Authenticated action');
            case 'public-test':
                return $this->successResponse('Public action');
            default:
                return $this->successResponse('Default action');
        }
    }

    // Expose protected methods for testing
    public function testIsPublicAction(string $action): bool {
        return $this->isPublicAction($action);
    }

    public function testCheckAuthentication(): array|bool {
        return $this->checkAuthentication();
    }

    public function testGetAuthStatus(): array {
        return $this->getAuthStatus();
    }

    public function testHasRequiredPermissions(array $authStatus): bool {
        return $this->hasRequiredPermissions($authStatus);
    }

    public function testIsDevelopmentEnvironment(): bool {
        return $this->isDevelopmentEnvironment();
    }

    public function testIsFileTypeAllowed(string $filename): bool {
        return $this->isFileTypeAllowed($filename);
    }

    public function testEnsureDirectory(string $path): bool {
        return $this->ensureDirectory($path);
    }

    public function getTestWorkingDir(): string {
        return $this->getWorkingDir();
    }

    public function getTestStorageDir(): string {
        return $this->getStorageDir();
    }

    public function getTestModelConfig(string $key, $default = null) {
        return $this->getModelConfig($key, $default);
    }

    public function testIsActionInProgress(string $action, array $params = []): bool {
        return $this->isActionInProgress($action, $params);
    }

    public function testIsActionThrottled(string $action, array $params = []): array|bool {
        return $this->isActionThrottled($action, $params);
    }

    public function testHandleThrottledAction(string $action, array $params = []): array|false {
        return $this->handleThrottledAction($action, $params);
    }

    public function testRenderTemplateWithErrorHandling(string $name, TemplateFormat $format, array $variables = []): string {
        return $this->renderTemplateWithErrorHandling($name, $format, $variables);
    }

    public function testServeStaticFile(string $fileType, string $filePath): array {
        return $this->serveStaticFile($fileType, $filePath);
    }

    public function testIsStaticFileTypeAllowed(string $extension): bool {
        return $this->isStaticFileTypeAllowed($extension);
    }

    public function testGetMimeTypeForExtension(string $extension): string {
        return $this->getMimeTypeForExtension($extension);
    }

    public function testGenerateUniqueFilename(string $directory, string $filename): string {
        return $this->generateUniqueFilename($directory, $filename);
    }

    public function testValidateFileUpload(array $file): array {
        return $this->validateFileUpload($file);
    }

    public function testGetRegistryPath(?string $filename = null): string {
        return $this->getRegistryPath($filename);
    }

    public function testLoadRegistry(?string $filename = null): array {
        return $this->loadRegistry($filename);
    }

    public function testSaveRegistry(array $data, ?string $filename = null): bool {
        return $this->saveRegistry($data, $filename);
    }

    public function testFormatFileSize(int $bytes): string {
        return $this->formatFileSize($bytes);
    }

    public function testGetSystemInformation(): array {
        return $this->getSystemInformation();
    }
}

describe('Model', function() {

    beforeEach(function() {
        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../../fixtures/templates',
            'app' => [
                'debug' => [
                    'enabled' => true,
                    'testing_symbiota_uid' => 123
                ]
            ],
            'components' => [
                'test-model' => [
                    'working_path' => sprintf('%s/test_model_work', sys_get_temp_dir()),
                    'storage_path' => sprintf('%s/test_model_storage', sys_get_temp_dir()),
                    'allowed_file_types' => 'jpg,png,pdf'
                ]
            ]
        ];
        $this->model = new TestModel($this->config);
    });

    afterEach(function() {
        // Clean up test directories recursively
        $removeDirectoryRecursively = function($dir) use (&$removeDirectoryRecursively) {
            if (!is_dir($dir)) {
                return;
            }

            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $removeDirectoryRecursively($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        };

        $dirs = [
            sprintf('%s/test_model_work', sys_get_temp_dir()),
            sprintf('%s/test_model_storage', sys_get_temp_dir())
        ];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $removeDirectoryRecursively($dir);
            }
        }
    });

    describe('constructor and initialization', function() {
        it('should initialize with configuration', function() {
            expect($this->model)->toBeAnInstanceOf(TestModel::class);
            expect($this->model)->toBeAnInstanceOf(Model::class);
        });

        /* it('should setup working and storage directories', function() {
            $workingDir = $this->model->getTestWorkingDir();
            $storageDir = $this->model->getTestStorageDir();

            expect(is_dir($workingDir))->toBe(true);
            expect(is_dir($storageDir))->toBe(true);
        });
         */

        it('should load model configuration', function() {
            $workingPath = $this->model->getTestModelConfig('working_path');
            $storagePath = $this->model->getTestModelConfig('storage_path');
            $allowedTypes = $this->model->getTestModelConfig('allowed_file_types');

            expect($workingPath)->toBe(sprintf('%s/test_model_work', sys_get_temp_dir()));
            expect($storagePath)->toBe(sprintf('%s/test_model_storage', sys_get_temp_dir()));
            expect($allowedTypes)->toBe('jpg,png,pdf');
        });
    });

    describe('authentication and authorization', function() {
        describe('isPublicAction', function() {
            it('should identify public actions correctly', function() {
                expect($this->model->testIsPublicAction('index'))->toBe(true);
                expect($this->model->testIsPublicAction('help'))->toBe(true);
                expect($this->model->testIsPublicAction('public-test'))->toBe(true);
                expect($this->model->testIsPublicAction('private-action'))->toBe(false);
            });
        });

        describe('getAuthStatus', function() {
            it('should return testing auth status in development', function() {
                $authStatus = $this->model->testGetAuthStatus();

                expect($authStatus['authenticated'])->toBe(true);
                expect($authStatus['uid'])->toBe(123);
                expect($authStatus['testing'])->toBe(true);
            });
        });

        describe('isDevelopmentEnvironment', function() {
            it('should detect development environment', function() {
                expect($this->model->testIsDevelopmentEnvironment())->toBe(true);
            });
        });

        describe('hasRequiredPermissions', function() {
            it('should allow authenticated users by default', function() {
                $authStatus = ['authenticated' => true, 'uid' => 123];
                expect($this->model->testHasRequiredPermissions($authStatus))->toBe(true);
            });

            it('should deny unauthenticated users', function() {
                $authStatus = ['authenticated' => false];
                expect($this->model->testHasRequiredPermissions($authStatus))->toBe(false);
            });
        });
    });

    describe('request handling', function() {
        describe('handleAction', function() {
            it('should handle public actions without authentication', function() {
                $response = $this->model->handleAction('public-test', [], []);

                expect($response['type'])->toBe('success');
                expect($response['content'])->toBe('Public action');
                expect($response['status_code'])->toBe(200);
            });

            it('should handle authenticated actions in development mode', function() {
                $response = $this->model->handleAction('test-auth-required', [], []);

                expect($response['type'])->toBe('success');
                expect($response['content'])->toBe('Authenticated action');
            });

            it('should handle exceptions gracefully', function() {
                $response = $this->model->handleAction('test-exception', [], []);

                expect($response['type'])->toBe('error');
                expect($response['status_code'])->toBe(500);
                expect($response['message'])->toContain('Test exception');
            });
        });
    });

    describe('response methods', function() {
        describe('successResponse', function() {
            it('should create success response', function() {
                $response = $this->model->handleAction('test-success', [], []);

                expect($response['type'])->toBe('success');
                expect($response['content'])->toBe('Test successful');
                expect($response['status_code'])->toBe(200);
            });
        });

        describe('errorResponse', function() {
            it('should create error response', function() {
                $response = $this->model->handleAction('test-error', [], []);

                expect($response['type'])->toBe('error');
                expect($response['message'])->toBe('Test error');
                expect($response['status_code'])->toBe(400);
            });
        });

        describe('htmxResponse', function() {
            it('should create HTMX response', function() {
                $response = $this->model->handleAction('test-htmx', [], []);

                expect($response['type'])->toBe('htmx');
                expect($response['content'])->toBe('<div>HTMX content</div>');
                expect($response['status_code'])->toBe(200);
            });
        });

        describe('htmlResponse', function() {
            it('should create HTML response', function() {
                $response = $this->model->handleAction('test-html', [], []);

                expect($response['type'])->toBe('html');
                expect($response['content'])->toBe('<h1>HTML content</h1>');
                expect($response['status_code'])->toBe(200);
            });
        });

        describe('jsonResponse', function() {
            it('should create JSON response', function() {
                $response = $this->model->handleAction('test-json', [], []);

                expect($response['type'])->toBe('json');
                expect($response['status_code'])->toBe(200);

                $data = json_decode($response['content'], true);
                expect($data['status'])->toBe('ok');
                expect($data['data'])->toBe('test');
            });
        });
    });

    describe('file management', function() {
        describe('isFileTypeAllowed', function() {
            it('should check allowed file types', function() {
                expect($this->model->testIsFileTypeAllowed('test.jpg'))->toBe(true);
                expect($this->model->testIsFileTypeAllowed('test.png'))->toBe(true);
                expect($this->model->testIsFileTypeAllowed('test.pdf'))->toBe(true);
                expect($this->model->testIsFileTypeAllowed('test.txt'))->toBe(false);
                expect($this->model->testIsFileTypeAllowed('test.exe'))->toBe(false);
            });
        });

        describe('ensureDirectory', function() {
            it('should create directories if they do not exist', function() {
                $testDir = sys_get_temp_dir() . '/test_ensure_dir';

                // Ensure directory doesn't exist
                if (is_dir($testDir)) {
                    rmdir($testDir);
                }

                $result = $this->model->testEnsureDirectory($testDir);

                expect($result)->toBe(true);
                expect(is_dir($testDir))->toBe(true);

                // Clean up
                rmdir($testDir);
            });

            it('should return true for existing directories', function() {
                $testDir = sys_get_temp_dir();
                $result = $this->model->testEnsureDirectory($testDir);

                expect($result)->toBe(true);
            });
        });
    });

    describe('configuration management', function() {
        describe('getModelConfig', function() {
            it('should return configuration values', function() {
                $workingPath = $this->model->getTestModelConfig('working_path');
                $defaultValue = $this->model->getTestModelConfig('nonexistent', 'default');

                expect($workingPath)->toBe(sprintf('%s/test_model_work', sys_get_temp_dir()));
                expect($defaultValue)->toBe('default');
            });
        });
    });

    describe('throttling and progress management', function() {
        describe('isActionInProgress', function() {
            it('should return false by default', function() {
                expect($this->model->testIsActionInProgress('test-action'))->toBe(false);
            });
        });

        describe('isActionThrottled', function() {
            it('should return false by default', function() {
                expect($this->model->testIsActionThrottled('test-action'))->toBe(false);
            });
        });

        describe('handleThrottledAction', function() {
            it('should return false when not throttled', function() {
                $result = $this->model->testHandleThrottledAction('test-action');
                expect($result)->toBe(false);
            });
        });
    });

    describe('template rendering', function() {
        describe('renderTemplateWithErrorHandling', function() {
            it('should handle template errors gracefully', function() {
                $result = $this->model->testRenderTemplateWithErrorHandling('nonexistent/template', TemplateFormat::HTML, []);
                expect($result)->toContain('Template Error');
            });
        });
    });

    describe('static file handling', function() {
        describe('isStaticFileTypeAllowed', function() {
            it('should allow common static file types', function() {
                expect($this->model->testIsStaticFileTypeAllowed('css'))->toBe(true);
                expect($this->model->testIsStaticFileTypeAllowed('js'))->toBe(true);
                expect($this->model->testIsStaticFileTypeAllowed('png'))->toBe(true);
                expect($this->model->testIsStaticFileTypeAllowed('jpg'))->toBe(true);
                expect($this->model->testIsStaticFileTypeAllowed('exe'))->toBe(false);
                expect($this->model->testIsStaticFileTypeAllowed('php'))->toBe(false);
            });
        });

        describe('getMimeTypeForExtension', function() {
            it('should return correct MIME types', function() {
                expect($this->model->testGetMimeTypeForExtension('css'))->toBe('text/css');
                expect($this->model->testGetMimeTypeForExtension('js'))->toBe('application/javascript');
                expect($this->model->testGetMimeTypeForExtension('png'))->toBe('image/png');
                expect($this->model->testGetMimeTypeForExtension('unknown'))->toBe('application/octet-stream');
            });
        });

        describe('generateUniqueFilename', function() {
            it('should generate unique filenames', function() {
                $testDir = sprintf('%s/test_unique_files', sys_get_temp_dir());
                $this->model->testEnsureDirectory($testDir);

                // Create a test file
                file_put_contents(sprintf('%s/test.txt', $testDir), 'content');

                $uniqueName = $this->model->testGenerateUniqueFilename($testDir, 'test.txt');
                expect($uniqueName)->toBe('test_1.txt');

                // Clean up
                unlink(sprintf('%s/test.txt', $testDir));
                rmdir($testDir);
            });
        });
    });

    describe('registry management', function() {
        describe('registry operations', function() {
            it('should save and load registry data', function() {
                $testData = ['key1' => 'value1', 'key2' => 'value2'];

                $saved = $this->model->testSaveRegistry($testData, 'test_registry.json');
                expect($saved)->toBe(true);

                $loaded = $this->model->testLoadRegistry('test_registry.json');
                expect($loaded)->toBe($testData);

                // Clean up
                $registryPath = $this->model->testGetRegistryPath('test_registry.json');
                if (file_exists($registryPath)) {
                    unlink($registryPath);
                }
            });

            it('should return empty array for non-existent registry', function() {
                $loaded = $this->model->testLoadRegistry('nonexistent.json');
                expect($loaded)->toBe([]);
            });
        });
    });

    describe('utility functions', function() {
        describe('formatFileSize', function() {
            it('should format file sizes correctly', function() {
                expect($this->model->testFormatFileSize(1024))->toBe('1.00 KB');
                expect($this->model->testFormatFileSize(1048576))->toBe('1.00 MB');
                expect($this->model->testFormatFileSize(1073741824))->toBe('1.00 GB');
                expect($this->model->testFormatFileSize(0))->toBe('0.00 B');
            });
        });

        describe('getSystemInformation', function() {
            it('should return system information', function() {
                $info = $this->model->testGetSystemInformation();

                expect($info)->toBeA('array');
                expect($info)->toContainKey('php_version');
                expect($info)->toContainKey('memory_usage');
                expect($info)->toContainKey('peak_memory');
                expect($info['php_version'])->toBe(PHP_VERSION);
            });
        });
    });
});
