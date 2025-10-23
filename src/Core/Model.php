<?php

namespace Symbiota\Helpers\Core;

use Exception;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Ext\SymbAuth;

/**
 * Base Model class - Common functionality for all models
 * 
 * Provides shared functionality for:
 * - Authentication and authorization
 * - HTTP request/response handling
 * - CLI operations
 * - Template rendering
 * - File management
 * - Database connections
 * - Configuration management
 * - Error handling and validation
 */
abstract class Model extends DiscoverableModel
{
    // Authentication and authorization
    protected ?array $currentUser = null;
    protected bool $requiresAuth = true;
    protected array $publicActions = ['index', 'help'];

    // Request handling
    protected string $requestMethod = 'GET';
    protected array $requestParams = [];
    protected array $routeParams = [];

    // Response handling
    protected array $responseHeaders = [];
    protected int $statusCode = 200;

    // File management
    protected ?string $workingDir;
    protected ?string $storageDir;
    protected array $allowedFileTypes = [];

    // Configuration
    protected array $modelConfig = [];

    // Module type specific settings
    protected bool $isService = false;
    protected bool $isUtility = false;
    protected bool $isComponent = false;
    protected bool $isLibrary = false;
    
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->initializeModel();
        $this->loadModelConfiguration();
        // Don't call setupDirectories() here - let child classes handle it
        // This prevents creating unnecessary /tmp/<model>_work and /tmp/<model>_storage
        // directories when child classes override workingDir/storageDir
    }

    /**
     * Initialize model-specific settings
     */
    protected function initializeModel(): void
    {
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Use Symbiota temp directory if available, otherwise fall back to system temp
        $configuration = Configuration::getInstance();
        $tempDir = $configuration ? $configuration->get('_internal.symbtempdirroot') : null;
        if (empty($tempDir)) {
            $tempDir = sys_get_temp_dir();
        }

        $this->workingDir = sprintf('%s/%s_work', $tempDir, static::getModelId());
        $this->storageDir = sprintf('%s/%s_storage', $tempDir, static::getModelId());

        // Set module type flags for easier conditional logic
        $moduleType = static::getModuleType();
        $this->isService = $moduleType === ModuleType::SERVICE;
        $this->isUtility = $moduleType === ModuleType::UTILITY;
        $this->isComponent = $moduleType === ModuleType::COMPONENT;
        $this->isLibrary = $moduleType === ModuleType::LIBRARY;

        // Adjust authentication requirements based on module type
        if ($this->isService) {
            $this->requiresAuth = false; // Services typically don't require auth
            $this->publicActions = ['index']; // All service actions are public
        } elseif ($this->isUtility) {
            $this->publicActions = ['index', 'help', 'info']; // Utilities may have more public actions
        }
    }

    /**
     * Load model-specific configuration
     */
    protected function loadModelConfiguration(): void
    {
        $modelId = static::getModelId();
        $this->modelConfig = $this->config['components'][$modelId] ?? $this->config[$modelId] ?? [];

        // Override directories if configured
        if (isset($this->modelConfig['working_path'])) {
            $this->workingDir = $this->modelConfig['working_path'];
        }
        if (isset($this->modelConfig['storage_path'])) {
            $this->storageDir = $this->modelConfig['storage_path'];
        }

        // Load allowed file types
        $allowedTypes = $this->modelConfig['allowed_file_types'] ?? [];
        if (is_string($allowedTypes)) {
            $this->allowedFileTypes = array_map('trim', explode(',', $allowedTypes));
        } else {
            $this->allowedFileTypes = $allowedTypes;
        }
    }

    /**
     * Set PHP memory limit from configuration
     *
     * Checks for memory_limit in this order:
     * 1. Model-specific config (components.<model>.memory_limit)
     * 2. Global performance config (performance.memory_limit)
     * 3. Current PHP setting (no change)
     *
     * @param string|null $operation Optional operation name for logging
     * @return string Previous memory limit value
     */
    protected function setMemoryLimitFromConfig(?string $operation = null): string
    {
        $oldLimit = ini_get('memory_limit');

        // Check model-specific memory limit first
        $memoryLimit = $this->modelConfig['memory_limit'] ?? null;

        // Fall back to global performance setting
        if ($memoryLimit === null) {
            $memoryLimit = $this->config['performance']['memory_limit'] ?? null;
        }

        // If no config setting, keep current limit
        if ($memoryLimit === null) {
            return $oldLimit;
        }

        // Set the new limit
        $result = ini_set('memory_limit', $memoryLimit);

        if ($result === false) {
            error_log("Warning: Failed to set memory_limit to {$memoryLimit}");
            return $oldLimit;
        }

        // Log the change if in CLI mode
        if (Environment::isCli() && $operation) {
            echo "Memory limit for {$operation}: {$oldLimit} → {$memoryLimit}\n";
        }

        return $oldLimit;
    }

    /**
     * Set PHP max execution time from configuration
     *
     * @param string|null $operation Optional operation name for logging
     * @return int Previous max_execution_time value
     */
    protected function setMaxExecutionTimeFromConfig(?string $operation = null): int
    {
        $oldTime = (int)ini_get('max_execution_time');

        // Check global performance setting
        $maxTime = $this->config['performance']['max_execution_time'] ?? null;

        // If no config setting, keep current value
        if ($maxTime === null) {
            return $oldTime;
        }

        // Set the new limit
        $result = set_time_limit((int)$maxTime);

        if ($result === false) {
            error_log("Warning: Failed to set max_execution_time to {$maxTime}");
            return $oldTime;
        }

        // Log the change if in CLI mode
        if (Environment::isCli() && $operation) {
            $displayTime = $maxTime == 0 ? 'unlimited' : "{$maxTime}s";
            echo "Max execution time for {$operation}: {$oldTime}s → {$displayTime}\n";
        }

        return $oldTime;
    }

    /**
     * Setup required directories with proper error handling
     *
     * This method is NOT called automatically in the constructor to prevent
     * creating unnecessary /tmp/<model>_work and /tmp/<model>_storage directories
     * when child classes override workingDir/storageDir.
     *
     * Child classes should call this explicitly if they need these directories,
     * or implement their own ensureDirectories() method.
     */
    protected function setupDirectories(): void
    {
        $directories = [
            'working' => $this->workingDir,
            'storage' => $this->storageDir
        ];

        foreach ($directories as $type => $dir) {
            if ($dir === null) {
                continue; // Skip if directory is not configured
            }

            if (!is_dir($dir)) {
                // First, check if parent directory is writable
                $parentDir = dirname($dir);
                if (!is_writable($parentDir)) {
                    // Try fallback locations in order of preference
                    $fallbackPaths = [
                        sprintf('./tmp/%s_%s', static::getModelId(), $type),
                        sprintf('./%s_%s', static::getModelId(), $type),
                        sprintf('/tmp/%s_%s_%s', static::getModelId(), $type, uniqid())
                    ];

                    $created = false;
                    foreach ($fallbackPaths as $fallbackDir) {
                        $fallbackParent = dirname($fallbackDir);
                        if (is_writable($fallbackParent) || $fallbackParent === '.') {
                            if (@mkdir($fallbackDir, 0755, true)) {
                                // Update the property to point to successful fallback
                                if ($type === 'working') {
                                    $this->workingDir = $fallbackDir;
                                } else {
                                    $this->storageDir = $fallbackDir;
                                }
                                $created = true;
                                break;
                            }
                        }
                    }

                    // If no fallback worked, disable directory-dependent features
                    if (!$created) {
                        if ($type === 'working') {
                            $this->workingDir = null;
                        } else {
                            $this->storageDir = null;
                        }
                    }
                } else {
                    // Parent is writable, try to create the directory
                    @mkdir($dir, 0755, true);
                }
            }
        }
    }

    /**
     * Check if working directory is available and writable
     */
    protected function isWorkingDirAvailable(): bool
    {
        return $this->workingDir !== null && is_dir($this->workingDir) && is_writable($this->workingDir);
    }

    /**
     * Check if storage directory is available and writable
     */
    protected function isStorageDirAvailable(): bool
    {
        return $this->storageDir !== null && is_dir($this->storageDir) && is_writable($this->storageDir);
    }

    /**
     * Main request handler with authentication and error handling
     */
    public function handleAction(string $action, array $route, array $params): array
    {
        try {


            $this->routeParams = $route;
            $this->requestParams = $params;
            
            // Check authentication for protected actions
            if ($this->requiresAuth && !$this->isPublicAction($action)) {
                $authResult = $this->checkAuthentication();
                if ($authResult !== true) {
                    return $authResult;
                }
            }
            
            // Validate parameters
            $validationErrors = $this->validateActionParameters($action, $params);
            if (!empty($validationErrors)) {
                return $this->errorResponse('Validation failed: ' . implode(', ', $validationErrors), 400);
            }
            
            // Handle the action
            return $this->executeAction($action, $route, $params);
            
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check if action is public (doesn't require authentication)
     */
    protected function isPublicAction(string $action): bool
    {
        return in_array($action, $this->publicActions);
    }

    /**
     * Check authentication and authorization
     */
    protected function checkAuthentication(): array|bool
    {
        // Skip authentication in CLI mode
        if (Environment::isCli()) {
            return true;
        }
        
        // Get authentication status
        $authStatus = $this->getAuthStatus();
        
        if (!$authStatus['authenticated']) {
            return $this->errorResponse('Authentication required', 401);
        }
        
        // Check model-specific permissions
        if (!$this->hasRequiredPermissions($authStatus)) {
            return $this->errorResponse('Access denied. Insufficient permissions.', 403);
        }
        
        $this->currentUser = $authStatus['user'];
        return true;
    }

    /**
     * Get authentication status
     */
    protected function getAuthStatus(): array
    {
        // Check for testing mode
        $testingUid = $this->config['app']['debug']['testing_symbiota_uid'] ?? null;
        if ($testingUid && $this->isDevelopmentEnvironment()) {
            return [
                'authenticated' => true,
                'uid' => (int)$testingUid,
                'user' => ['uid' => (int)$testingUid, 'username' => 'test_user'],
                'testing' => true
            ];
        }
        
        return SymbAuth::getAuthStatus();
    }

    /**
     * Check if user has required permissions for this model
     */
    protected function hasRequiredPermissions(array $authStatus): bool
    {
        // Default implementation - override in specific models
        return $authStatus['authenticated'];
    }

    /**
     * Check if we're in development environment
     */
    protected function isDevelopmentEnvironment(): bool
    {
        return ($this->config['app']['debug']['enabled'] ?? false) ||
               (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost');
    }

    /**
     * Validate parameters for specific action
     */
    protected function validateActionParameters(string $action, array $params): array
    {
        // Skip validation for test actions and public actions
        if (str_starts_with($action, 'test-') || $this->isPublicAction($action)) {
            return [];
        }

        // Default implementation - override in specific models for custom validation
        return $this->validateParameters($params);
    }

    /**
     * Execute the requested action - must be implemented by child classes
     */
    abstract protected function executeAction(string $action, array $route, array $params): array;

    /**
     * Handle exceptions and return appropriate error response
     */
    protected function handleException(\Exception $e): array
    {
        // Log the exception in development
        if ($this->isDevelopmentEnvironment()) {
            error_log(sprintf("Model Exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
        }

        // Handle different exception types appropriately
        if ($e instanceof \InvalidArgumentException) {
            // Validation errors should return 400 with the original message
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->errorResponse(sprintf('Internal server error: %s', $e->getMessage()), 500);
    }

    /**
     * Create standardized error response
     */
    protected function errorResponse(string $message, int $statusCode = 400, array $additionalData = []): array
    {
        $this->statusCode = $statusCode;

        $response = [
            'type' => 'error',
            'message' => $message,
            'status_code' => $statusCode
        ];

        // Merge additional data
        if (!empty($additionalData)) {
            $response = array_merge($response, $additionalData);
        }

        return $response;
    }

    /**
     * Create standardized success response
     */
    protected function successResponse($content, string $type = 'success'): array
    {
        return [
            'type' => $type,
            'content' => $content,
            'status_code' => 200
        ];
    }

    /**
     * Create HTMX response
     */
    protected function htmxResponse(string $content): array
    {
        return [
            'type' => 'htmx',
            'content' => $content,
            'status_code' => 200
        ];
    }

    /**
     * Create HTML response
     */
    protected function htmlResponse(string $content, ?string $template = null): array
    {
        $response = [
            'type' => 'html',
            'content' => $content,
            'status_code' => 200
        ];
        
        if ($template) {
            $response['template'] = $template;
        }
        
        return $response;
    }

    /**
     * Create JSON response
     */
    protected function jsonResponse(array $data): array
    {
        return [
            'type' => 'json',
            'content' => json_encode($data),
            'status_code' => 200
        ];
    }

    /**
     * Get model configuration value
     */
    protected function getModelConfig(string $key, $default = null)
    {
        return $this->modelConfig[$key] ?? $default;
    }

    /**
     * Get working directory path
     */
    protected function getWorkingDir(): ?string
    {
        return $this->workingDir;
    }

    /**
     * Get storage directory path
     */
    protected function getStorageDir(): ?string
    {
        return $this->storageDir;
    }

    /**
     * Get current user
     */
    protected function getCurrentUser(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Get current user ID
     */
    protected function getCurrentUserId(): ?int
    {
        return $this->currentUser['uid'] ?? null;
    }

    /**
     * Check if file type is allowed
     */
    protected function isFileTypeAllowed(string $filename): bool
    {
        if (empty($this->allowedFileTypes)) {
            return true; // No restrictions
        }
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedFileTypes);
    }

    /**
     * Ensure directory exists and is writable
     */
    protected function ensureDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                return false;
            }
        }
        
        return is_writable($path);
    }

    /**
     * Clean up temporary files
     */
    protected function cleanup(): void
    {
        // Override in child classes for specific cleanup needs
    }

    /**
     * Check if action is currently in progress (for throttling)
     */
    protected function isActionInProgress(string $action, array $params = []): bool
    {
        // Default implementation - override in specific models
        return false;
    }

    /**
     * Check if action is throttled (time-based restrictions)
     */
    protected function isActionThrottled(string $action, array $params = []): array|bool
    {
        // Default implementation - override in specific models
        return false;
    }

    /**
     * Get throttle status message
     */
    protected function getThrottleMessage(string $action, array $params = []): string
    {
        return sprintf("Action '%s' is currently throttled. Please try again later.", $action);
    }

    /**
     * Handle throttled action request
     */
    protected function handleThrottledAction(string $action, array $params = []): array|false
    {
        if ($this->isActionInProgress($action, $params)) {
            return $this->errorResponse(sprintf("Action '%s' is currently in progress.", $action), 409);
        }

        $throttleInfo = $this->isActionThrottled($action, $params);
        if ($throttleInfo !== false) {
            $message = is_array($throttleInfo) && isset($throttleInfo['message'])
                ? $throttleInfo['message']
                : $this->getThrottleMessage($action, $params);

            return $this->errorResponse($message, 429);
        }

        return false; // Not throttled
    }

    /**
     * Render template with model-specific path and error handling
     * Automatically prepends model name to template path for model-specific templates
     */
    protected function renderTemplate(string $name, TemplateFormat $format, array $variables = []): string
    {
        // Get model ID for template path
        $modelId = static::getModelId();

        // For certain global templates, don't prepend model name
        $globalTemplates = ['layout', 'help/discoverable_model', 'help/discovery', 'dashboard/navigation_item'];

        // Check if this is a global template or already includes a path
        $isGlobalTemplate = in_array($name, $globalTemplates) || str_contains($name, '/');

        // Prepend model name for model-specific templates
        $templatePath = $isGlobalTemplate ? $name : sprintf('%s/%s', $modelId, $name);

        // Call parent renderTemplate method
        return parent::renderTemplate($templatePath, $format, $variables);
    }

    /**
     * Render template with error handling
     */
    protected function renderTemplateWithErrorHandling(string $name, TemplateFormat $format, array $variables = []): string
    {
        try {
            return $this->renderTemplate($name, $format, $variables);
        } catch (\Exception $e) {
            if ($this->isDevelopmentEnvironment()) {
                return sprintf("Template Error: %s", $e->getMessage());
            }
            return "Template rendering failed";
        }
    }

    /**
     * Get CLI help response
     */
    protected function getCliHelpResponse(): array
    {
        return [
            'type' => 'cli',
            'content' => static::generateCliHelp()
        ];
    }

    /**
     * Get default index response based on environment
     */
    protected function getDefaultIndexResponse(): array
    {
        if (Environment::isCli()) {
            return $this->getCliHelpResponse();
        } else {
            return $this->getMainPageResponse();
        }
    }

    /**
     * Get main page response - override in specific models
     */
    protected function getMainPageResponse(): array
    {
        if ($this->isUtility) {
            return $this->getUtilityPageResponse();
        } elseif ($this->isService) {
            return $this->getServiceResponse();
        } else {
            return $this->getComponentPageResponse();
        }
    }

    /**
     * Get component page response
     */
    protected function getComponentPageResponse(): array
    {
        return $this->htmlResponse(
            $this->renderTemplateWithErrorHandling(
                sprintf('%s/content', static::getModelId()),
                TemplateFormat::HTML,
                ['app_url_prefix' => $this->getAppUrlPrefix()]
            )
        );
    }

    /**
     * Get utility page response (system information, features, etc.)
     */
    protected function getUtilityPageResponse(): array
    {
        $data = $this->getUtilityData();

        return [
            'type' => static::getModelId(),
            'title' => static::getDisplayName(),
            'content' => $this->renderTemplateWithErrorHandling(
                sprintf('%s/content', static::getModelId()),
                TemplateFormat::HTML,
                array_merge($data, ['app_url_prefix' => $this->getAppUrlPrefix()])
            )
        ];
    }

    /**
     * Get service response (for static content, APIs, etc.)
     */
    protected function getServiceResponse(): array
    {
        return $this->errorResponse('Service endpoint requires specific parameters', 400);
    }

    /**
     * Get utility data - override in utility models
     */
    protected function getUtilityData(): array
    {
        return [
            'system_info' => $this->getSystemInformation(),
            'script_name' => $this->getScriptNameForExamples()
        ];
    }

    /**
     * Get system information (common for utilities)
     */
    protected function getSystemInformation(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage' => sprintf('%.2f MB', round((float)memory_get_usage(true) / 1024.0 / 1024.0, 2)),
            'peak_memory' => sprintf('%.2f MB', round((float)memory_get_peak_usage(true) / 1024.0 / 1024.0, 2)),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'Unknown'
        ];
    }

    /**
     * Get script name for CLI examples
     */
    protected function getScriptNameForExamples(): string
    {
        return basename($_SERVER['SCRIPT_NAME'] ?? 'helpers.php');
    }

    /**
     * Serve static file (for SERVICE modules)
     */
    protected function serveStaticFile(string $fileType, string $filePath): array
    {
        // Security: Prevent directory traversal
        if (strpos($filePath, '..') !== false || strpos($filePath, '\\') !== false) {
            return $this->errorResponse('Invalid file path', 400);
        }

        // Map file type to directory
        $directoryMap = [
            'css' => 'css',
            'js' => 'js',
            'assets' => 'assets',
            'images' => 'images'
        ];

        $directory = $directoryMap[$fileType] ?? $fileType;

        // Build full file path using ApplicationPaths
        $templatesPath = $this->config['templates_path'] ?? ApplicationPaths::templatesDirectory();
        $fullPath = realpath(sprintf('%s/%s/%s', $templatesPath, $directory, $filePath));
        $templatesRealPath = realpath($templatesPath);

        // Security: Ensure file is within templates directory
        if (!$fullPath || !$templatesRealPath || strpos($fullPath, $templatesRealPath) !== 0) {
            return $this->errorResponse('File not found', 404);
        }

        // Check if file exists and is readable
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return $this->errorResponse('File not found', 404);
        }

        // Get file info
        $fileExtension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $fileSize = filesize($fullPath);
        $lastModified = filemtime($fullPath);

        // Validate file type
        if (!$this->isStaticFileTypeAllowed($fileExtension)) {
            return $this->errorResponse('File type not allowed', 403);
        }

        // Read file content
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return $this->errorResponse('Failed to read file', 500);
        }

        $mimeType = $this->getMimeTypeForExtension($fileExtension);

        // Return file response with appropriate headers
        return [
            'type' => 'file',
            'content' => $content,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'last_modified' => $lastModified,
            'file_path' => $filePath,
            'headers' => [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', $lastModified)),
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => sprintf('"%s"', md5($content))
            ]
        ];
    }

    /**
     * Check if static file type is allowed
     */
    protected function isStaticFileTypeAllowed(string $extension): bool
    {
        $allowedTypes = [
            'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
            'woff', 'woff2', 'ttf', 'eot', 'json', 'xml', 'txt'
        ];

        return in_array(strtolower($extension), $allowedTypes);
    }

    /**
     * Get MIME type for file extension
     */
    protected function getMimeTypeForExtension(string $extension): string
    {
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'xml' => 'application/xml',
            'txt' => 'text/plain'
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Generate unique filename to prevent duplicates
     */
    protected function generateUniqueFilename(string $directory, string $filename): string
    {
        $pathInfo = pathinfo($filename);
        $baseName = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? sprintf('.%s', $pathInfo['extension']) : '';

        $counter = 1;
        $newFilename = $filename;

        while (file_exists(sprintf('%s/%s', $directory, $newFilename))) {
            $newFilename = sprintf('%s_%d%s', $baseName, $counter, $extension);
            $counter++;
        }

        return $newFilename;
    }

    /**
     * Validate file upload
     */
    protected function validateFileUpload(array $file): array
    {
        $errors = [];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed';
            return $errors;
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid file upload';
            return $errors;
        }

        $filename = $file['name'] ?? '';
        if (empty($filename)) {
            $errors[] = 'Filename is required';
            return $errors;
        }

        if (!$this->isFileTypeAllowed($filename)) {
            $errors[] = 'File type not allowed';
            return $errors;
        }

        return $errors;
    }

    /**
     * Get registry file path for model-specific data
     */
    protected function getRegistryPath(?string $filename = null): string
    {
        $registryDir = sprintf('%s/registry', $this->getStorageDir());
        $this->ensureDirectory($registryDir);

        if ($filename) {
            return sprintf('%s/%s', $registryDir, $filename);
        }

        return sprintf('%s/%s_registry.json', $registryDir, static::getModelId());
    }

    /**
     * Load registry data
     */
    protected function loadRegistry(?string $filename = null): array
    {
        $registryPath = $this->getRegistryPath($filename);

        if (!file_exists($registryPath)) {
            return [];
        }

        $content = file_get_contents($registryPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save registry data
     */
    protected function saveRegistry(array $data, ?string $filename = null): bool
    {
        $registryPath = $this->getRegistryPath($filename);
        $content = json_encode($data, JSON_PRETTY_PRINT);

        return file_put_contents($registryPath, $content) !== false;
    }

    /**
     * Get collection access for current user (common pattern)
     */
    protected function getUserCollectionAccess(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }

        // This would typically query the database for user's collection permissions
        // For now, return empty array - override in specific models
        return [];
    }

    /**
     * Check if user has collection access
     */
    protected function hasCollectionAccess(int $collectionId): bool
    {
        $access = $this->getUserCollectionAccess();
        return in_array($collectionId, $access);
    }

    /**
     * Get database connection (common pattern)
     */
    protected function getDatabaseConnection(string $type = 'default')
    {
        // Use parent's connection method if available
        if (method_exists(parent::class, 'getConnection')) {
            return $this->getConnection($type);
        }

        // Fallback - override in specific models that need database access
        return null;
    }

    /**
     * Execute SQL query with error handling
     */
    protected function executeQuery(string $sql, array $params = [], string $connectionType = 'readonly'): array|false
    {
        try {
            $connection = $this->getDatabaseConnection($connectionType);
            if (!$connection) {
                return false;
            }

            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                return false;
            }

            $result = $stmt->execute($params);
            if (!$result) {
                return false;
            }

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            if ($this->isDevelopmentEnvironment()) {
                error_log(sprintf("Database query error: %s", $e->getMessage()));
            }
            return false;
        }
    }

    /**
     * Format file size for display
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = (int)floor(($bytes ? log((float)$bytes) : 0.0) / log(1024.0));
        $pow = min($pow, count($units) - 1);

        $bytesFloat = (float)$bytes / (float)pow(1024.0, (float)$pow);

        return sprintf('%.2f %s', round($bytesFloat, 2), $units[$pow]);
    }

    /**
     * Get progress information for long-running operations
     */
    protected function getProgressInfo(string $operationId): array
    {
        // Check if working directory is available
        if (!$this->isWorkingDirAvailable()) {
            return ['status' => 'not_found'];
        }

        $progressFile = sprintf('%s/progress_%s.json', $this->getWorkingDir(), $operationId);

        if (!file_exists($progressFile)) {
            return ['status' => 'not_found'];
        }

        $content = file_get_contents($progressFile);
        if ($content === false) {
            return ['status' => 'error'];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : ['status' => 'error'];
    }

    /**
     * Update progress information
     */
    protected function updateProgress(string $operationId, array $progressData): bool
    {
        // Check if working directory is available
        if (!$this->isWorkingDirAvailable()) {
            return false;
        }

        $workingDir = $this->getWorkingDir();

        // Ensure directory exists
        if (!$this->ensureDirectory($workingDir)) {
            return false;
        }

        $progressFile = sprintf('%s/progress_%s.json', $workingDir, $operationId);
        $content = json_encode($progressData, JSON_PRETTY_PRINT);

        return file_put_contents($progressFile, $content) !== false;
    }

    /**
     * Start progress tracking for an operation
     */
    protected function startProgress(string $message = 'Starting operation...', ?string $operationId = null): string
    {
        if ($operationId === null) {
            $operationId = uniqid('op_', true);
        }

        $progressData = [
            'status' => 'running',
            'message' => $message,
            'progress' => 0,
            'started_at' => time(),
            'operation_id' => $operationId
        ];

        $this->updateProgress($operationId, $progressData);
        return $operationId;
    }

    /**
     * Update progress with new message and percentage
     */
    protected function updateProgressMessage(string $operationId, string $message, ?int $progress = null): bool
    {
        $currentData = $this->getProgressInfo($operationId);
        if ($currentData['status'] === 'not_found') {
            return false;
        }

        $currentData['message'] = $message;
        if ($progress !== null) {
            $currentData['progress'] = max(0, min(100, $progress));
        }
        $currentData['updated_at'] = time();

        return $this->updateProgress($operationId, $currentData);
    }

    /**
     * Mark progress as successful
     */
    protected function succeedProgress(string $operationId, string $message = 'Operation completed successfully'): bool
    {
        $currentData = $this->getProgressInfo($operationId);
        if ($currentData['status'] === 'not_found') {
            return false;
        }

        $currentData['status'] = 'completed';
        $currentData['message'] = $message;
        $currentData['progress'] = 100;
        $currentData['completed_at'] = time();

        return $this->updateProgress($operationId, $currentData);
    }

    /**
     * Mark progress as failed
     */
    protected function failProgress(string $operationId, string $message = 'Operation failed'): bool
    {
        $currentData = $this->getProgressInfo($operationId);
        if ($currentData['status'] === 'not_found') {
            return false;
        }

        $currentData['status'] = 'failed';
        $currentData['message'] = $message;
        $currentData['failed_at'] = time();

        return $this->updateProgress($operationId, $currentData);
    }

    /**
     * Build secure filter clause for database queries
     */
    protected function buildSecureFilterClause(array $filter, array $validFields): array
    {
        $clauses = [];
        $parameters = [];
        $types = '';

        foreach ($filter as $field => $value) {
            if (!in_array($field, $validFields)) {
                continue; // Skip invalid fields for security
            }

            if (is_array($value)) {
                // Handle array values (IN clause)
                $placeholders = str_repeat('?,', count($value) - 1) . '?';
                $clauses[] = sprintf('%s IN (%s)', $field, $placeholders);
                $parameters = array_merge($parameters, $value);
                $types .= str_repeat('s', count($value));
            } else {
                // Handle single values
                $clauses[] = sprintf('%s = ?', $field);
                $parameters[] = $value;
                $types .= 's';
            }
        }

        return [
            'clause' => empty($clauses) ? '1=1' : implode(' AND ', $clauses),
            'parameters' => $parameters,
            'types' => $types
        ];
    }

    /**
     * Build secure sort clause for database queries
     */
    protected function buildSortClause(array $sort, array $validFields): string
    {
        $clauses = [];

        foreach ($sort as $field => $direction) {
            if (!in_array($field, $validFields)) {
                continue; // Skip invalid fields for security
            }

            $direction = strtoupper($direction);
            if (!in_array($direction, ['ASC', 'DESC'])) {
                $direction = 'ASC'; // Default to ASC for invalid directions
            }

            $clauses[] = sprintf('%s %s', $field, $direction);
        }

        return empty($clauses) ? '' : 'ORDER BY ' . implode(', ', $clauses);
    }

    /**
     * Log error message
     */
    protected function logError(string $message, array $context = []): void
    {
        if ($this->isDevelopmentEnvironment()) {
            $contextStr = empty($context) ? '' : sprintf(' Context: %s', json_encode($context));
            error_log(sprintf('[%s] %s%s', static::getModelId(), $message, $contextStr));
        }
    }

    /**
     * Check if database is available (respects test configuration)
     */
    public function isDatabaseAvailable(): bool
    {
        // Respect test configuration for offline scenarios
        if (isset($this->config['database_available']) && $this->config['database_available'] === false) {
            return false;
        }

        // Fall back to parent implementation
        return parent::isDatabaseAvailable();
    }

    /**
     * Get database connectivity status with detailed information
     */
    protected function getDatabaseStatus(): array
    {
        // Check test configuration first
        if (isset($this->config['database_available']) && $this->config['database_available'] === false) {
            return [
                'available' => false,
                'reason' => 'test_mode',
                'message' => 'Database disabled for testing',
                'can_connect' => false
            ];
        }

        // Check if we have a database manager
        if (!isset($this->databaseManager) || !$this->databaseManager) {
            // Try to initialize database manager
            try {
                $this->databaseManager = new DatabaseManager();
            } catch (Exception $e) {
                return [
                    'available' => false,
                    'reason' => 'no_manager',
                    'message' => 'Database manager not available: ' . $e->getMessage(),
                    'can_connect' => false
                ];
            }
        }

        // Get detailed connectivity status
        $status = $this->databaseManager->verifyConnectivity();

        return [
            'available' => $status['available'],
            'reason' => $status['available'] ? 'connected' : 'connection_failed',
            'message' => $status['error'] ?? 'Database connection verified',
            'can_connect' => $status['can_connect'],
            'details' => $status
        ];
    }

    /**
     * Execute database operation with fallback to fixtures
     */
    protected function executeWithFallback(string $operation, callable $databaseCallback, callable $fixtureCallback): array
    {
        $dbStatus = $this->getDatabaseStatus();

        if ($dbStatus['can_connect']) {
            try {
                return $databaseCallback();
            } catch (Exception $e) {
                // Database operation failed, fall back to fixtures if in test mode
                if ($this->isTestingMode()) {
                    return $fixtureCallback();
                }

                return $this->errorResponse(
                    sprintf('Database operation failed: %s', $e->getMessage()),
                    500,
                    ['operation' => $operation, 'database_error' => $e->getMessage()]
                );
            }
        }

        // Database not available, use fixtures if in test mode
        if ($this->isTestingMode()) {
            return $fixtureCallback();
        }

        // Return database unavailable error
        return $this->errorResponse(
            $dbStatus['message'],
            503,
            ['operation' => $operation, 'database_status' => $dbStatus]
        );
    }

    /**
     * Check if we're in testing mode
     */
    protected function isTestingMode(): bool
    {
        return isset($this->config['testing_mode']) && $this->config['testing_mode'] === true;
    }

    /**
     * Simple warning progress method (for backward compatibility)
     */
    protected function warnProgress(string $message = 'Warning occurred'): void
    {
        if ($this->isDevelopmentEnvironment()) {
            error_log(sprintf('[%s] WARNING: %s', static::getModelId(), $message));
        }
    }

    /**
     * Simple info progress method (for backward compatibility)
     */
    protected function infoProgress(string $message = 'Information'): void
    {
        if ($this->isDevelopmentEnvironment()) {
            error_log(sprintf('[%s] INFO: %s', static::getModelId(), $message));
        }
    }

    /**
     * CLI response wrapper
     */
    protected function cliResponse(string $content, int $exitCode = 0): array
    {
        return [
            'type' => 'cli',
            'content' => $content,
            'exit_code' => $exitCode
        ];
    }

    /**
     * Get app URL prefix for templates
     *
     * Wraps UriParser functionality for model-specific use
     */
    public function getAppUrlPrefix(): string
    {
        // Use UriParser for consistent URL generation
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/?/';
        $parser = new UriParser($requestUri);
        $fullUrl = $parser->getAppUrlPrefix();

        // Extract just the path part for templates (remove protocol://host:port)
        if (str_contains($fullUrl, '://')) {
            $parts = parse_url($fullUrl);
            return ($parts['path'] ?? '') . '?/';
        }

        return $fullUrl;
    }

    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
