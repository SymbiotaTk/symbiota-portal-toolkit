<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\DatabaseManager;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\ProgressSpinner;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\UriParser;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Ext\SymbAuth;
use mysqli;
use Exception;

/**
 * Upload Model - Chunked file upload with authorization system
 * 
 * Provides functionality for:
 * - Registry-based user authorization
 * - Chunked file uploads with progress tracking
 * - Session-based file organization
 * - CLI user management
 * - Symbiota authentication integration
 */
class UploadModel extends Model
{
    // Properties removed - now handled by Model base class

    private string $outputDir;
    private string $registryFile;
    private int $chunkSizeKb;
    private int $maxFileSizeMb;
    // $allowedFileTypes removed - now inherited from Model base class
    private int $uploadTimeout;
    private int $parallelUploads;
    private bool $parallelChunkUploads;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Use Configuration singleton for upload-specific settings
        $configuration = Configuration::getInstance();
        $uploadConfig = $configuration ?
            ($configuration->get('components.upload') ?? $configuration->get('upload') ?? []) :
            ($config['components']['upload'] ?? $config['upload'] ?? []);
        $this->outputDir = $uploadConfig['output_dir'] ?? sys_get_temp_dir() . '/symbiota_uploads';
        $this->registryFile = $uploadConfig['registry_file'] ?? sys_get_temp_dir() . '/upload_registry.json';
        $this->chunkSizeKb = $uploadConfig['chunk_size_kb'] ?? 1024;
        $this->maxFileSizeMb = $uploadConfig['max_file_size_mb'] ?? 500;
        // Parse allowed file types from string or use array
        $allowedTypes = $uploadConfig['allowed_file_types'] ?? ['jpg', 'jpeg', 'png', 'pdf', 'zip'];
        if (is_string($allowedTypes)) {
            $this->allowedFileTypes = array_map('trim', explode(',', $allowedTypes));
        } else {
            $this->allowedFileTypes = $allowedTypes;
        }
        $this->uploadTimeout = $uploadConfig['upload_timeout'] ?? 300;
        $this->parallelUploads = $uploadConfig['parallel_uploads'] ?? 1;
        $this->parallelChunkUploads = filter_var(
            $uploadConfig['parallel_chunk_uploads'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $this->parallelUploads = $uploadConfig['parallel_uploads'] ?? 1;
        $this->parallelChunkUploads = filter_var(
            $uploadConfig['parallel_chunk_uploads'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        // Override working/storage directories to use configured output_dir
        // This prevents creating upload_working/upload_storage in application directory
        $this->workingDir = $this->outputDir . '/working';
        $this->storageDir = $this->outputDir . '/storage';

        // Ensure directories exist
        $this->ensureDirectories();

        // Set public actions for this model
        $this->publicActions = ['index', 'help', 'status'];
    }

    /**
     * Get the model's routing resource ID
     */
    #[Override]
    public static function getModelId(): string
    {
        return 'upload';
    }

    /**
     * Get the model's help documentation in markdown format
     */
    #[Override]
    public static function getHelpMarkdown(): string
    {
        // Get the entry file name dynamically
        $entryFile = basename(ApplicationPaths::applicationFilename());

        // Load help content from template
        $templatePath = sprintf('%s/cli/help/upload_model.txt', ApplicationPaths::templatesDirectory());
        if (!file_exists($templatePath)) {
            return 'Help documentation not found.';
        }

        $helpContent = file_get_contents($templatePath);
        if ($helpContent === false) {
            return 'Error reading help documentation.';
        }

        // Use TemplateEngine for variable interpolation
        $templateEngine = new TemplateEngine();
        return $templateEngine->render($helpContent, ['entry_file' => $entryFile]);
    }

    /**
     * Get the module type classification
     */
    #[Override]
    public static function getModuleType(): ModuleType
    {
        return ModuleType::COMPONENT;
    }

    /**
     * Get available actions for this model
     */
    public function getAvailableActions(): array
    {
        return [
            'index' => 'Main upload interface',
            'users' => 'List authorized users',
            'add-user' => 'Add user to registry',
            'remove-user' => 'Remove user from registry',
            'list-user-files' => 'List files for user',
            'init-session' => 'Initialize upload session with file list',
            'chunk' => 'Handle chunked upload',
            'complete' => 'Complete chunked upload',
            'sessions' => 'Get session reports',
            'status' => 'Show upload configuration and status',
            'debug-log' => 'Show debug log',
            'php-info' => 'Show PHP upload configuration',
            'test-chunk' => 'Test chunk upload endpoint',
            'test-upload' => 'Simple test upload (bypasses auth)',
            'help' => 'Show help information'
        ];
    }

    /**
     * Execute the requested action - implements base class abstract method
     */
    #[Override]
    protected function executeAction(string $action, array $route, array $params): array
    {
        // Handle CLI flag overrides
        if (Environment::isCli()) {
            $this->handleCliFlags($params);
        }

        // Check for response format modifier
        $format = $this->getResponseFormat($params);

        // Merge route elements into params as 'args' for compatibility
        // If params already has 'args', preserve it; otherwise use route
        $mergedParams = $params;
        if (!isset($params['args']) || empty($params['args'])) {
            $mergedParams['args'] = $route;
        }

        switch ($action) {
            case 'index':
            case '':
                return $this->getMainPage($format);
            case 'users':
                return $this->listUsers($mergedParams);
            case 'add-user':
                return $this->addUser($mergedParams);
            case 'remove-user':
                return $this->removeUser($mergedParams);
            case 'list-user-files':
                return $this->listUserFiles($mergedParams);
            case 'init-session':
                return $this->initializeSession($mergedParams);
            case 'chunk':
                return $this->handleChunkUpload($mergedParams);
            case 'complete':
                return $this->completeUpload($mergedParams);
            case 'sessions':
                return $this->getSessionReports($mergedParams, $format);
            case 'status':
                return $this->getUploadStatus($mergedParams);
            case 'debug-log':
                return $this->getDebugLog($mergedParams);
            case 'php-info':
                return $this->getPhpInfo($mergedParams);
            case 'test-chunk':
                return $this->testChunkEndpoint($mergedParams);
            case 'test-upload':
                return $this->handleTestUpload($mergedParams);
            case 'help':
                return $this->getHelp();
            default:
                return [
                    'type' => 'error',
                    'message' => sprintf("Unknown action: %s", $action),
                    'status_code' => 404
                ];
        }
    }

    /**
     * Handle CLI flag overrides
     */
    private function handleCliFlags(array $params): void
    {
        // Override registry file if specified
        if (isset($params['registry-file']) || isset($params['r'])) {
            $this->registryFile = $params['registry-file'] ?? $params['r'];
        }

        // Override output directory if specified
        if (isset($params['output-dir']) || isset($params['d'])) {
            $this->outputDir = $params['output-dir'] ?? $params['d'];
            $this->ensureDirectories();
        }
    }

    /**
     * Get main upload page
     */
    private function getMainPage(?string $format = null): array
    {
        // Check authentication
        $authStatus = $this->getAuthStatus();
        if (!$authStatus['authenticated']) {
            // Return JSON response for API requests
            if ($format === 'json') {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            // Show HTML login required message for web requests
            $mainContent = $this->renderTemplate('upload/login_required', TemplateFormat::HTML, [
                'app_url_prefix' => $this->getAppUrlPrefix(),
                'login_message' => 'You must be logged in to access the upload module.'
            ]);

            return [
                'type' => 'html',
                'content' => $mainContent
            ];
        }

        $uid = $authStatus['uid'];

        // Get user information for display
        $userInfo = $this->getUserInfo($uid);
        $userDisplay = $userInfo ?
            sprintf("%s (%s)", $userInfo['full_name'], $userInfo['email']) :
            sprintf("User %d", $uid);

        // Check if user is authorized
        $isAuthorized = $this->isUserAuthorized($uid);

        if (!$isAuthorized) {
            $accessMessage = sprintf(
                "You do not have access to upload files. Please contact the administrator to request upload access for user %s.",
                $userDisplay
            );

            // Return JSON response for API requests
            if ($format === 'json') {
                return [
                    'type' => 'error',
                    'message' => 'Access denied',
                    'details' => $accessMessage,
                    'user_id' => $uid,
                    'user_display' => $userDisplay,
                    'status_code' => 403
                ];
            }

            // Show HTML dashboard with access message for web requests
            $mainContent = $this->renderTemplate('upload/no_access', TemplateFormat::HTML, [
                'user_display' => $userDisplay,
                'uid' => $uid,
                'access_message' => $accessMessage,
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]);

            return [
                'type' => 'html',
                'content' => $mainContent
            ];
        }

        // Return JSON response for API requests
        if ($format === 'json') {
            return [
                'type' => 'success',
                'message' => 'Upload interface available',
                'user_id' => $uid,
                'user_display' => $userDisplay,
                'user_info' => $userInfo,
                'upload_config' => [
                    'chunk_size_bytes' => $this->chunkSizeKb * 1024,
                    'chunk_size_kb' => $this->chunkSizeKb,
                    'allowed_file_types' => $this->allowedFileTypes,
                    'upload_timeout_seconds' => $this->uploadTimeout,
                    'max_file_size' => ini_get('upload_max_filesize'),
                    'max_post_size' => ini_get('post_max_size')
                ],
                'endpoints' => [
                    'chunk_upload' => sprintf('%supload/chunk', $this->getAppUrlPrefix()),
                    'complete_upload' => sprintf('%supload/complete', $this->getAppUrlPrefix()),
                    'session_status' => sprintf('%supload/sessions', $this->getAppUrlPrefix())
                ]
            ];
        }

        // Get upload interface content for authorized users (HTML)
        // Format allowed types for DropzoneJS (needs .ext,.ext format)
        $dropzoneTypes = '.' . implode(',.', $this->allowedFileTypes);

        // Debug the app_url_prefix value
        $appUrlPrefix = $this->getAppUrlPrefix();
        $this->logDebug('Template variables', [
            'upload_url_prefix' => $appUrlPrefix,
            'chunk_size' => $this->chunkSizeKb * 1024,
            'allowed_types' => $dropzoneTypes,
            'upload_timeout' => $this->uploadTimeout
        ]);

        $templateVars = [
            'app_url_prefix' => $appUrlPrefix,  // Override global variable
            'upload_url_prefix' => $appUrlPrefix,  // Also provide alternative name
            'chunk_size' => $this->chunkSizeKb * 1024,
            'max_file_size' => $this->maxFileSizeMb,
            'allowed_types' => $dropzoneTypes,
            'upload_timeout' => $this->uploadTimeout,
            'parallel_uploads' => $this->parallelUploads,
            'parallel_chunk_uploads' => $this->parallelChunkUploads ? 'true' : 'false'
        ];

        $this->logDebug('About to render template with variables', $templateVars);

        $mainContent = $this->renderTemplate('upload/content', TemplateFormat::HTML, $templateVars);

        // Check if variables were actually replaced
        $hasUnreplacedVars = strpos($mainContent, '{app_url_prefix}') !== false;
        $this->logDebug('Template rendering result', [
            'content_length' => strlen($mainContent),
            'has_unreplaced_app_url_prefix' => $hasUnreplacedVars,
            'first_100_chars' => substr($mainContent, 0, 100)
        ]);

        return [
            'type' => 'html',
            'content' => $mainContent
        ];
    }

    /**
     * List authorized users
     */
    private function listUsers(array $params): array
    {
        $registry = $this->loadRegistry();
        
        if (Environment::isCli()) {
            $output = "\nAuthorized Users:\n" . str_repeat("=", 50) . "\n";
            
            if (empty($registry['users'])) {
                $output .= "No authorized users found.\n";
            } else {
                foreach ($registry['users'] as $uid => $userData) {
                    $output .= sprintf("UID: %s | Username: %s | Added: %s\n",
                        $uid,
                        $userData['username'] ?? 'Unknown',
                        $userData['added_date'] ?? 'Unknown'
                    );
                }
            }
            
            return [
                'type' => 'success',
                'content' => $output
            ];
        }

        return [
            'type' => 'json',
            'content' => json_encode($registry['users'])
        ];
    }

    /**
     * Add user to registry
     */
    private function addUser(array $params): array
    {
        $userIdentifier = $params['args'][0] ?? '';
        
        if (empty($userIdentifier)) {
            return [
                'type' => 'error',
                'message' => 'Username or UID required',
                'status_code' => 400
            ];
        }

        // Determine if it's a UID or username
        $uid = is_numeric($userIdentifier) ? (int)$userIdentifier : null;
        $username = !is_numeric($userIdentifier) ? $userIdentifier : null;

        // If username provided, try to get UID from database
        if ($username && !$uid) {
            $uid = $this->getUserIdByUsername($username);
            if (!$uid) {
                return [
                    'type' => 'error',
                    'message' => "User '{$username}' not found in database",
                    'status_code' => 404
                ];
            }
        }

        // If UID provided, try to get username from database
        if ($uid && !$username) {
            $username = $this->getUsernameById($uid);
        }

        $registry = $this->loadRegistry();
        $registry['users'][$uid] = [
            'username' => $username ?? "uid_{$uid}",
            'added_date' => date('Y-m-d H:i:s'),
            'added_by' => $this->getAuthStatus()['uid'] ?? 'cli'
        ];

        $this->saveRegistry($registry);
        $this->ensureUserDirectory($uid);

        $message = "User {$uid}" . ($username ? " ({$username})" : "") . " added to upload registry";
        
        return [
            'type' => 'success',
            'message' => $message
        ];
    }

    /**
     * Remove user from registry
     */
    private function removeUser(array $params): array
    {
        $userIdentifier = $params['args'][0] ?? '';
        
        if (empty($userIdentifier)) {
            return [
                'type' => 'error',
                'message' => 'Username or UID required',
                'status_code' => 400
            ];
        }

        $registry = $this->loadRegistry();
        
        // Find user in registry
        $uid = null;
        if (is_numeric($userIdentifier)) {
            $uid = (int)$userIdentifier;
        } else {
            // Search by username
            foreach ($registry['users'] as $registryUid => $userData) {
                if (($userData['username'] ?? '') === $userIdentifier) {
                    $uid = $registryUid;
                    break;
                }
            }
        }

        if (!$uid || !isset($registry['users'][$uid])) {
            return [
                'type' => 'error',
                'message' => "User '{$userIdentifier}' not found in registry",
                'status_code' => 404
            ];
        }

        $username = $registry['users'][$uid]['username'] ?? '';
        unset($registry['users'][$uid]);
        $this->saveRegistry($registry);

        $message = "User {$uid}" . ($username ? " ({$username})" : "") . " removed from upload registry";
        
        return [
            'type' => 'success',
            'message' => $message
        ];
    }

    /**
     * List files for a user
     */
    private function listUserFiles(array $params): array
    {
        $userIdentifier = $params['args'][0] ?? '';
        
        if (empty($userIdentifier)) {
            return [
                'type' => 'error',
                'message' => 'Username or UID required',
                'status_code' => 400
            ];
        }

        // Get UID
        $uid = is_numeric($userIdentifier) ? (int)$userIdentifier : $this->getUserIdByUsername($userIdentifier);
        
        if (!$uid) {
            return [
                'type' => 'error',
                'message' => "User '{$userIdentifier}' not found",
                'status_code' => 404
            ];
        }

        $userDir = $this->outputDir . '/' . $uid;
        $files = [];
        
        if (is_dir($userDir)) {
            $sessions = glob($userDir . '/*', GLOB_ONLYDIR);
            foreach ($sessions as $sessionDir) {
                if (basename($sessionDir) === 'chunks') continue;
                
                $sessionFiles = glob($sessionDir . '/*');
                foreach ($sessionFiles as $file) {
                    if (is_file($file)) {
                        $files[] = [
                            'session' => basename($sessionDir),
                            'filename' => basename($file),
                            'size' => filesize($file),
                            'date' => date('Y-m-d H:i:s', filemtime($file))
                        ];
                    }
                }
            }
        }

        if (Environment::isCli()) {
            $output = "\nFiles for User {$uid}:\n" . str_repeat("=", 50) . "\n";
            
            if (empty($files)) {
                $output .= "No files found.\n";
            } else {
                foreach ($files as $file) {
                    $output .= sprintf("Session: %s | File: %s | Size: %s | Date: %s\n",
                        $file['session'],
                        $file['filename'],
                        $this->formatFileSize($file['size']),
                        $file['date']
                    );
                }
            }
            
            return [
                'type' => 'success',
                'content' => $output
            ];
        }

        return [
            'type' => 'json',
            'content' => json_encode($files)
        ];
    }

    // getAuthStatus method removed - now inherited from Model base class

    // isDevelopmentEnvironment method removed - now inherited from Model base class

    /**
     * Check if user is authorized
     */
    private function isUserAuthorized(int $uid): bool
    {
        $registry = $this->loadRegistry();
        return isset($registry['users'][$uid]);
    }

    /**
     * Override base class registry path to use UploadModel's custom registry file
     */
    #[Override]
    protected function getRegistryPath(?string $filename = null): string
    {
        if ($filename !== null && $filename !== '') {
            // If a specific filename is provided, use it in the same directory as the main registry
            $registryDir = dirname($this->registryFile);
            return sprintf('%s/%s', $registryDir, $filename);
        }

        // Use the UploadModel's custom registry file path
        return $this->registryFile;
    }

    /**
     * Ensure user directory exists
     */
    private function ensureUserDirectory(int $uid): void
    {
        $userDir = $this->outputDir . '/' . $uid;
        $chunksDir = $userDir . '/chunks';
        
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        
        if (!is_dir($chunksDir)) {
            mkdir($chunksDir, 0755, true);
        }
    }

    /**
     * Ensure base directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->outputDir)) {
            @mkdir($this->outputDir, 0755, true);
        }

        // Ensure registry file directory exists
        $registryDir = dirname($this->registryFile);
        if (!is_dir($registryDir)) {
            @mkdir($registryDir, 0755, true);
        }
    }

    /**
     * Get user ID by username using Symbiota database
     */
    private function getUserIdByUsername(string $username): ?int
    {
        try {
            $connection = $this->getConnection();
            if (!$connection) {
                return null;
            }

            // Load SQL from template
            $sql = $this->loadSqlTemplate('upload/user_by_username');
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                return null;
            }

            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (int)$row['uid'];
            }

            $stmt->close();
            return null;
        } catch (Exception $e) {
            error_log("UploadModel: Error getting user ID by username: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get username by user ID using Symbiota database
     */
    private function getUsernameById(int $uid): ?string
    {
        try {
            $connection = $this->getConnection();
            if (!$connection) {
                return null;
            }

            $sql = $this->loadSqlTemplate('upload/user_details');
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                return null;
            }

            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row['username'];
            }

            $stmt->close();
            return null;
        } catch (Exception $e) {
            error_log("UploadModel: Error getting username by ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user information (firstname, lastname, email) by UID
     */
    private function getUserInfo(int $uid): ?array
    {
        try {
            $connection = $this->getConnection();
            if (!$connection) {
                return null;
            }

            // Use the same SQL template as BackupModel
            $userSql = $this->renderTemplate('upload/user_info', TemplateFormat::SQL, ['uid' => $uid]);
            $userResult = $connection->query($userSql);

            if (!$userResult || $userResult->num_rows === 0) {
                return null;
            }

            $userInfo = $userResult->fetch_assoc();
            return [
                'uid' => (int)$userInfo['uid'],
                'username' => $userInfo['username'],
                'firstname' => $userInfo['firstname'] ?? '',
                'lastname' => $userInfo['lastname'] ?? '',
                'email' => $userInfo['email'] ?? '',
                'full_name' => trim(($userInfo['firstname'] ?? '') . ' ' . ($userInfo['lastname'] ?? ''))
            ];
        } catch (Exception $e) {
            error_log("UploadModel: Error getting user info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate session ID based on client IP and ISO timestamp with milliseconds
     * Format: <REMOTE-IP>-YYYYMMDDTHH:mm:ss.msec
     * Example: 127.0.0.1-20250926T030100.0001
     */
    private function generateSessionId(): string
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $microtime = microtime(true);
        $timestamp = (int)floor($microtime);
        $msec = sprintf('%04d', round(($microtime - (float)$timestamp) * 10000.0));

        // Format: YYYYMMDDTHH:mm:ss.msec
        $dateTime = date('Ymd\THis', $timestamp);

        return "{$clientIp}-{$dateTime}.{$msec}";
    }

    /**
     * Log transfer activity for session reports
     */
    private function logTransfer(int $uid, string $sessionId, string $action, array $data): void
    {
        $logDir = $this->outputDir . '/' . $uid . '/' . $sessionId;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/transfer.log';
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'data' => $data
        ];

        $logLine = json_encode($logEntry) . "\n";
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get transfer logs for a session (for accordion reports)
     */
    private function getTransferLogs(int $uid, string $sessionId): array
    {
        $logFile = $this->outputDir . '/' . $uid . '/' . $sessionId . '/transfer.log';

        if (!file_exists($logFile)) {
            return [];
        }

        $logs = [];
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $logs[] = $entry;
            }
        }

        return $logs;
    }



    /**
     * Handle test upload (DISABLED for security)
     */
    private function handleTestUpload(array $params): array
    {
        return [
            'type' => 'error',
            'message' => 'Test upload endpoint has been disabled for security',
            'status_code' => 403
        ];

        // Check for file upload
        if (!isset($_FILES['file'])) {
            return [
                'type' => 'error',
                'message' => 'No file uploaded',
                'available_files' => array_keys($_FILES)
            ];
        }

        $uploadedFile = $_FILES['file'];
        $filename = $params['filename'] ?? $_POST['filename'] ?? $uploadedFile['name'] ?? 'test-file';

        // Use test user 525 for simplicity
        $uid = 525;
        $uuid = $params['dzuuid'] ?? $_POST['dzuuid'] ?? 'test-' . time();

        // Create test directory
        $testDir = $this->outputDir . '/' . $uid . '/' . $uuid;
        if (!is_dir($testDir)) {
            mkdir($testDir, 0755, true);
        }

        $finalFile = $testDir . '/' . $filename;

        if (move_uploaded_file($uploadedFile['tmp_name'], $finalFile)) {
            $this->logDebug('Test upload success', [
                'filename' => $filename,
                'size' => filesize($finalFile),
                'path' => $finalFile
            ]);

            return [
                'type' => 'success',
                'message' => 'Test upload successful',
                'filename' => $filename,
                'size' => filesize($finalFile),
                'path' => $finalFile,
                'uuid' => $uuid
            ];
        } else {
            $error = error_get_last();
            return [
                'type' => 'error',
                'message' => 'Failed to save file',
                'error' => $error['message'] ?? 'Unknown error',
                'upload_info' => $uploadedFile
            ];
        }
    }

    /**
     * Test chunk upload endpoint with curl examples
     */
    private function testChunkEndpoint(array $params): array
    {
        $scriptName = static::getScriptName();

        $examples = [
            "# Test chunk upload endpoint with curl",
            "",
            "# 1. Create a test file",
            "echo 'This is test content for chunk upload testing.' > test.txt",
            "",
            "# 2. Test single chunk upload (small file)",
            "curl -X POST 'http://localhost:8877/?/upload/chunk' \\",
            "  -F 'file=@test.txt' \\",
            "  -F 'filename=test.txt' \\",
            "  -F 'filesize=50' \\",
            "  -F 'dzuuid=test-uuid-123' \\",
            "  -F 'dzchunkindex=0' \\",
            "  -F 'dztotalchunkcount=1' \\",
            "  -F 'dzchunksize=50' \\",
            "  -F 'dztotalfilesize=50'",
            "",
            "# 3. Test multi-chunk upload (simulate large file)",
            "# First chunk:",
            "curl -X POST 'http://localhost:8877/?/upload/chunk' \\",
            "  -F 'file=@test.txt' \\",
            "  -F 'filename=largefile.txt' \\",
            "  -F 'filesize=100' \\",
            "  -F 'dzuuid=test-uuid-456' \\",
            "  -F 'dzchunkindex=0' \\",
            "  -F 'dztotalchunkcount=2' \\",
            "  -F 'dzchunksize=50' \\",
            "  -F 'dztotalfilesize=100'",
            "",
            "# Second chunk (final):",
            "curl -X POST 'http://localhost:8877/?/upload/chunk' \\",
            "  -F 'file=@test.txt' \\",
            "  -F 'filename=largefile.txt' \\",
            "  -F 'filesize=50' \\",
            "  -F 'dzuuid=test-uuid-456' \\",
            "  -F 'dzchunkindex=1' \\",
            "  -F 'dztotalchunkcount=2' \\",
            "  -F 'dzchunksize=50' \\",
            "  -F 'dztotalfilesize=100'",
            "",
            "# 4. Test with verbose output to see headers",
            "curl -v -X POST 'http://localhost:8877/?/upload/chunk' \\",
            "  -F 'file=@test.txt' \\",
            "  -F 'filename=verbose-test.txt' \\",
            "  -F 'dzuuid=test-uuid-789' \\",
            "  -F 'dzchunkindex=0' \\",
            "  -F 'dztotalchunkcount=1'",
            "",
            "# 5. Test endpoint availability",
            "curl -X GET 'http://localhost:8877/?/upload/chunk'",
            "",
            "# 6. Check debug log after tests",
            "{$scriptName} upload debug-log",
            "",
            "# 7. Check uploaded files",
            "{$scriptName} upload list-user-files 525",
            "",
            "# Expected responses:",
            "# - Success: {\"type\":\"success\",\"message\":\"File uploaded successfully\"}",
            "# - Error: {\"type\":\"error\",\"message\":\"Error description\"}",
            "",
            "# Notes:",
            "# - Make sure user 525 is registered: {$scriptName} upload add-user 525",
            "# - Files will be stored in: dev/test/myco/uploads/525/",
            "# - Debug log will be at: dev/test/myco/data/upload_debug.log"
        ];

        return [
            'type' => 'success',
            'content' => implode("\n", $examples)
        ];
    }

    /**
     * Get PHP upload configuration info
     */
    private function getPhpInfo(array $params): array
    {
        $uploadSettings = [
            'file_uploads' => ini_get('file_uploads') ? 'On' : 'Off',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'tmp_dir_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
            'output_dir_writable' => is_writable($this->outputDir),
            'registry_dir_writable' => is_writable(dirname($this->registryFile))
        ];

        if (Environment::isCli()) {
            $output = ["PHP Upload Configuration", str_repeat("=", 50)];
            foreach ($uploadSettings as $key => $value) {
                $output[] = sprintf("  %-20s: %s", $key, is_bool($value) ? ($value ? 'YES' : 'NO') : $value);
            }
            return [
                'type' => 'success',
                'content' => implode("\n", $output)
            ];
        }

        return [
            'type' => 'success',
            'content' => sprintf('<pre>%s</pre>', json_encode($uploadSettings, JSON_PRETTY_PRINT))
        ];
    }

    /**
     * Get debug log contents
     */
    private function getDebugLog(array $params): array
    {
        $logDir = dirname($this->registryFile);
        $logFile = $logDir . '/upload_debug.log';

        if (!file_exists($logFile)) {
            return [
                'type' => 'success',
                'content' => 'No debug log found. Upload debug log will be created when uploads are attempted.'
            ];
        }

        $logContent = file_get_contents($logFile);
        $lines = explode("\n", $logContent);

        // Get last 100 lines for readability
        $recentLines = array_slice($lines, -100);
        $recentContent = implode("\n", $recentLines);

        if (Environment::isCli()) {
            return [
                'type' => 'success',
                'content' => "Upload Debug Log (last 100 lines):\n" . str_repeat("=", 50) . "\n" . $recentContent
            ];
        }

        return [
            'type' => 'success',
            'content' => sprintf('<pre>%s</pre>', htmlspecialchars($recentContent))
        ];
    }

    /**
     * Log debug information to file (only in development)
     */
    private function logDebug(string $message, array $context = []): void
    {
        // Only log debug information in development environment
        if (!$this->isDevelopmentEnvironment()) {
            return;
        }

        $logDir = dirname($this->registryFile);
        $logFile = $logDir . '/upload_debug.log';

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf("[%s] %s", $timestamp, $message);

        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_PRETTY_PRINT);
        }

        $logEntry .= "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    // formatFileSize method removed - now inherited from Model base class

    /**
     * Initialize upload session with file list
     */
    private function initializeSession(array $params): array
    {
        // Get authentication status
        $authStatus = $this->getAuthStatus();
        if (!$authStatus['authenticated']) {
            return [
                'type' => 'error',
                'message' => 'Authentication required',
                'status_code' => 401
            ];
        }

        $uid = $authStatus['uid'];

        // Check if user is authorized
        if (!$this->isUserAuthorized($uid)) {
            return [
                'type' => 'error',
                'message' => 'Upload access denied',
                'status_code' => 403
            ];
        }

        // Get file list from request
        $files = $params['files'] ?? $_POST['files'] ?? [];
        if (empty($files)) {
            return [
                'type' => 'error',
                'message' => 'File list is required',
                'status_code' => 400
            ];
        }

        // Parse files if it's a JSON string
        if (is_string($files)) {
            $files = json_decode($files, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'type' => 'error',
                    'message' => 'Invalid file list format',
                    'status_code' => 400
                ];
            }
        }

        // Use provided session ID or generate a new one
        $sessionId = $params['session_id'] ?? $_POST['session_id'] ?? $this->generateSessionId();

        // Create session directory
        $userDir = $this->outputDir . '/' . $uid;
        $sessionDir = $userDir . '/' . $sessionId;

        if (!is_dir($sessionDir)) {
            $created = mkdir($sessionDir, 0755, true);
            if (!$created) {
                return [
                    'type' => 'error',
                    'message' => 'Failed to create session directory',
                    'status_code' => 500
                ];
            }
        }

        // Create session metadata file
        $sessionMeta = [
            'session_id' => $sessionId,
            'created_at' => date('Y-m-d H:i:s'),
            'uid' => $uid,
            'files' => $files,
            'status' => 'initialized'
        ];

        $metaFile = $sessionDir . '/session.json';
        file_put_contents($metaFile, json_encode($sessionMeta, JSON_PRETTY_PRINT));

        // Log session initialization
        $this->logTransfer($uid, $sessionId, 'session_init', [
            'file_count' => count($files),
            'files' => array_column($files, 'name')
        ]);

        $this->logDebug('Session initialized', [
            'session_id' => $sessionId,
            'file_count' => count($files),
            'session_dir' => $sessionDir
        ]);

        return [
            'type' => 'success',
            'message' => 'Session initialized successfully',
            'session_id' => $sessionId,
            'file_count' => count($files),
            'session_dir' => $sessionDir
        ];
    }



    /**
     * Handle chunked upload
     */
    private function handleChunkUpload(array $params): array
    {
        // Only allow POST requests for security
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return [
                'type' => 'error',
                'message' => 'Only POST requests allowed',
                'status_code' => 405
            ];
        }

        // Log the incoming request for debugging
        $this->logDebug('Chunk upload request', [
            'params' => $params,
            'post' => $_POST,
            'files' => array_keys($_FILES),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);

        // DropzoneJS sends these parameters for chunked uploads
        $chunkIndex = $params['dzchunkindex'] ?? $_POST['dzchunkindex'] ?? 0;
        $totalChunks = $params['dztotalchunkcount'] ?? $_POST['dztotalchunkcount'] ?? 1;
        $filename = $params['filename'] ?? $_POST['filename'] ?? '';
        $uuid = $params['dzuuid'] ?? $_POST['dzuuid'] ?? null;
        $chunkSize = $params['dzchunksize'] ?? $_POST['dzchunksize'] ?? 0;
        $totalFileSize = $params['dztotalfilesize'] ?? $_POST['dztotalfilesize'] ?? 0;

        // If no session ID provided, generate one (fallback for direct uploads)
        if (empty($uuid)) {
            $uuid = $this->generateSessionId();
            $this->logDebug('Generated new session ID for direct upload', ['session_id' => $uuid]);
        }

        if (empty($filename)) {
            $this->logDebug('Upload error: Filename is required');
            return [
                'type' => 'error',
                'message' => 'Filename is required',
                'status_code' => 400
            ];
        }

        // Validate file extension (case-insensitive)
        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$this->isAllowedFileType($fileExtension)) {
            return [
                'type' => 'error',
                'message' => sprintf('File type ".%s" is not allowed. Allowed types: %s',
                    $fileExtension, implode(', ', $this->allowedFileTypes)),
                'status_code' => 415
            ];
        }

        // Validate file size (check total file size if provided)
        if ($totalFileSize > 0) {
            $maxSizeBytes = $this->maxFileSizeMb * 1024 * 1024;

            if ($totalFileSize > $maxSizeBytes) {
                return [
                    'type' => 'error',
                    'message' => sprintf('File size (%s) exceeds maximum allowed size (%dMB)',
                        $this->formatFileSize($totalFileSize), $this->maxFileSizeMb),
                    'status_code' => 413
                ];
            }
        }

        // Check authentication
        $authStatus = $this->getAuthStatus();
        $this->logDebug('Authentication status', $authStatus);

        if (!$authStatus['authenticated']) {
            $this->logDebug('Upload error: Authentication required');
            return [
                'type' => 'error',
                'message' => 'Authentication required',
                'status_code' => 401
            ];
        }

        $uid = $authStatus['uid'];

        // Check if user is authorized
        $isAuthorized = $this->isUserAuthorized($uid);
        $this->logDebug('Authorization check', ['uid' => $uid, 'authorized' => $isAuthorized]);

        if (!$isAuthorized) {
            $this->logDebug('Upload error: Upload access denied');
            return [
                'type' => 'error',
                'message' => 'Upload access denied',
                'status_code' => 403
            ];
        }

        // Create session directory structure using UUID as session ID
        $userDir = $this->outputDir . '/' . $uid;
        $sessionDir = $userDir . '/' . $uuid;
        $chunksDir = $sessionDir . '/chunks';

        $this->logDebug('Directory setup', [
            'userDir' => $userDir,
            'sessionDir' => $sessionDir,
            'chunksDir' => $chunksDir
        ]);

        if (!is_dir($chunksDir)) {
            $created = mkdir($chunksDir, 0755, true);
            $this->logDebug('Created chunks directory', ['path' => $chunksDir, 'success' => $created]);
        }

        // Check for duplicate filename and generate unique name if needed
        $originalFilename = $filename;
        $filename = $this->generateUniqueFilename($sessionDir, $filename);

        // Handle the uploaded chunk - DropzoneJS uses 'file' as parameter name
        if (!isset($_FILES['file'])) {
            $this->logDebug('Upload error: No file data received', ['available_files' => array_keys($_FILES)]);
            return [
                'type' => 'error',
                'message' => 'No chunk data received',
                'status_code' => 400
            ];
        }

        $uploadedFile = $_FILES['file'];
        $this->logDebug('Uploaded file info', $uploadedFile);

        $chunkFile = $chunksDir . '/' . $filename . '.part' . $chunkIndex;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $chunkFile)) {
            $error = error_get_last();
            $this->logDebug('Failed to move uploaded file', [
                'from' => $uploadedFile['tmp_name'],
                'to' => $chunkFile,
                'error' => $error,
                'tmp_exists' => file_exists($uploadedFile['tmp_name']),
                'dir_writable' => is_writable($chunksDir)
            ]);
            return [
                'type' => 'error',
                'message' => 'Failed to save chunk: ' . ($error['message'] ?? 'Unknown error'),
                'status_code' => 500
            ];
        }

        $chunkSize = filesize($chunkFile);

        // Log the chunk upload with detailed information
        $this->logTransfer($uid, $uuid, 'chunk_upload', [
            'filename' => $filename,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
            'chunk_path' => $chunkFile,
            'total_file_size' => $totalFileSize
        ]);

        // Detailed debug logging for chunk transfer
        $this->logDebug('Chunk saved successfully', [
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_file' => $chunkFile,
            'chunk_size' => $chunkSize,
            'chunk_size_formatted' => $this->formatFileSize($chunkSize),
            'progress_percent' => round(($chunkIndex + 1) / $totalChunks * 100, 2)
        ]);

        // Check if this is the last chunk
        $isLastChunk = ($chunkIndex == $totalChunks - 1);

        if ($isLastChunk) {
            $this->logDebug('Last chunk received, starting file assembly', [
                'filename' => $filename,
                'total_chunks' => $totalChunks,
                'session_id' => $uuid
            ]);

            // Automatically assemble the file when last chunk is received
            $assembleResult = $this->assembleChunkedFile($uid, $uuid, $filename, $totalChunks, $originalFilename);
            if ($assembleResult['type'] === 'error') {
                return $assembleResult;
            }

            $response = [
                'type' => 'success',
                'message' => sprintf("File '%s' uploaded successfully", $filename),
                'session_id' => $uuid,
                'file_size' => $assembleResult['file_size'],
                'completed' => true
            ];

            // Include filename change notification if applicable
            if ($filename !== $originalFilename) {
                $response['filename_changed'] = true;
                $response['original_filename'] = $originalFilename;
                $response['final_filename'] = $filename;
                $response['message'] = sprintf("File uploaded as '%s' (renamed from '%s' to avoid duplicate)",
                    $filename, $originalFilename);
            }

            return $response;
        }

        return [
            'type' => 'success',
            'message' => sprintf("Chunk %d of %d uploaded", $chunkIndex + 1, $totalChunks),
            'session_id' => $uuid,
            'chunk_index' => $chunkIndex,
            'completed' => false
        ];
    }

    /**
     * Assemble chunked file from individual chunks
     */
    private function assembleChunkedFile(int $uid, string $uuid, string $filename, int $totalChunks, string $originalFilename = ''): array
    {
        $startTime = microtime(true);
        $sessionDir = $this->outputDir . '/' . $uid . '/' . $uuid;
        $chunksDir = $sessionDir . '/chunks';
        $finalFile = $sessionDir . '/' . $filename;

        $this->logDebug('Starting file assembly', [
            'session_dir' => $sessionDir,
            'chunks_dir' => $chunksDir,
            'final_file' => $finalFile,
            'total_chunks' => $totalChunks
        ]);

        // Verify all chunks exist
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunksDir . '/' . $filename . '.part' . $i;
            if (!file_exists($chunkFile)) {
                $this->logDebug('Missing chunk during assembly', [
                    'chunk_index' => $i,
                    'chunk_file' => $chunkFile,
                    'total_chunks' => $totalChunks
                ]);
                return [
                    'type' => 'error',
                    'message' => sprintf("Missing chunk %d", $i),
                    'status_code' => 400
                ];
            }
        }

        // Assemble chunks into final file
        $finalHandle = fopen($finalFile, 'wb');
        if (!$finalHandle) {
            $this->logDebug('Failed to create final file', [
                'final_file' => $finalFile,
                'dir_writable' => is_writable($sessionDir)
            ]);
            return [
                'type' => 'error',
                'message' => 'Failed to create final file',
                'status_code' => 500
            ];
        }

        $totalSize = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunksDir . '/' . $filename . '.part' . $i;
            $chunkData = file_get_contents($chunkFile);
            fwrite($finalHandle, $chunkData);
            $totalSize += strlen($chunkData);
            unlink($chunkFile); // Clean up chunk

            // Log progress every 100 chunks or on last chunk
            if ($i % 100 === 0 || $i === $totalChunks - 1) {
                $this->logDebug('Assembly progress', [
                    'chunks_assembled' => $i + 1,
                    'total_chunks' => $totalChunks,
                    'progress_percent' => round(($i + 1) / $totalChunks * 100, 2),
                    'bytes_written' => $totalSize
                ]);
            }
        }
        fclose($finalHandle);

        // Remove chunks directory
        rmdir($chunksDir);

        $assemblyTime = microtime(true) - $startTime;

        // Log the completed upload
        $this->logTransfer($uid, $uuid, 'upload_complete', [
            'filename' => $filename,
            'total_chunks' => $totalChunks,
            'file_size' => $totalSize,
            'final_path' => $finalFile,
            'assembly_time_seconds' => round($assemblyTime, 2)
        ]);

        $this->logDebug('File assembly completed', [
            'final_file' => $finalFile,
            'file_size' => $totalSize,
            'file_size_formatted' => $this->formatFileSize($totalSize),
            'total_chunks' => $totalChunks,
            'assembly_time' => round($assemblyTime, 2) . 's',
            'chunks_per_second' => round($totalChunks / $assemblyTime, 2)
        ]);

        // Check if session is complete
        $this->checkSessionCompletion($uid, $uuid);

        return [
            'type' => 'success',
            'file_size' => $totalSize
        ];
    }

    /**
     * Complete chunked upload
     */
    private function completeUpload(array $params): array
    {
        $filename = $params['filename'] ?? '';
        $sessionId = $params['sessionId'] ?? '';
        $totalChunks = $params['totalChunks'] ?? 1;

        if (empty($filename) || empty($sessionId)) {
            return [
                'type' => 'error',
                'message' => 'Filename and session ID are required',
                'status_code' => 400
            ];
        }

        $uid = $GLOBALS['SYMB_UID'] ?? null;
        if (!$uid) {
            return [
                'type' => 'error',
                'message' => 'Authentication required',
                'status_code' => 401
            ];
        }

        $sessionDir = $this->outputDir . '/' . $uid . '/' . $sessionId;
        $chunksDir = $sessionDir . '/chunks';
        $finalFile = $sessionDir . '/' . $filename;

        // Verify all chunks exist
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunksDir . '/' . $filename . '.part' . $i;
            if (!file_exists($chunkFile)) {
                return [
                    'type' => 'error',
                    'message' => "Missing chunk {$i}",
                    'status_code' => 400
                ];
            }
        }

        // Assemble chunks into final file
        $finalHandle = fopen($finalFile, 'wb');
        if (!$finalHandle) {
            return [
                'type' => 'error',
                'message' => 'Failed to create final file',
                'status_code' => 500
            ];
        }

        $totalSize = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunksDir . '/' . $filename . '.part' . $i;
            $chunkData = file_get_contents($chunkFile);
            fwrite($finalHandle, $chunkData);
            $totalSize += strlen($chunkData);
            unlink($chunkFile); // Clean up chunk
        }
        fclose($finalHandle);

        // Remove chunks directory
        rmdir($chunksDir);

        // Log the completed upload
        $this->logTransfer($uid, $sessionId, 'upload_complete', [
            'filename' => $filename,
            'total_chunks' => $totalChunks,
            'file_size' => $totalSize
        ]);

        // Check if session is complete
        $this->checkSessionCompletion($uid, $sessionId);

        $response = [
            'type' => 'success',
            'message' => "File '{$filename}' uploaded successfully",
            'file_size' => $totalSize,
            'session_id' => $sessionId
        ];

        // Include filename change notification if applicable
        if ($originalFilename !== '' && $filename !== $originalFilename) {
            $response['filename_changed'] = true;
            $response['original_filename'] = $originalFilename;
            $response['final_filename'] = $filename;
            $response['message'] = sprintf("File uploaded as '%s' (renamed from '%s' to avoid duplicate)",
                $filename, $originalFilename);
        }

        return $response;
    }

    /**
     * Check if session is complete and optionally rename folder
     */
    private function checkSessionCompletion(int $uid, string $sessionId): void
    {
        $sessionDir = $this->outputDir . '/' . $uid . '/' . $sessionId;
        $sessionMetaFile = $sessionDir . '/session.json';

        // Check if session metadata exists
        if (!file_exists($sessionMetaFile)) {
            return; // No session metadata, skip completion check
        }

        $sessionMeta = json_decode(file_get_contents($sessionMetaFile), true);
        if (!$sessionMeta || !isset($sessionMeta['files'])) {
            return;
        }

        $expectedFiles = $sessionMeta['files'];
        $uploadedFiles = [];

        // Get list of uploaded files (excluding internal files)
        $files = glob($sessionDir . '/*');
        foreach ($files as $file) {
            $filename = basename($file);
            if (is_file($file) && !in_array($filename, ['transfer.log', 'session.json'])) {
                $uploadedFiles[] = $filename;
            }
        }

        // Check if all expected files have been uploaded
        $expectedFileNames = array_column($expectedFiles, 'name');
        $allFilesUploaded = count($expectedFileNames) === count($uploadedFiles);

        foreach ($expectedFileNames as $expectedFile) {
            if (!in_array($expectedFile, $uploadedFiles)) {
                $allFilesUploaded = false;
                break;
            }
        }

        if ($allFilesUploaded) {
            // Update session status to complete
            $sessionMeta['status'] = 'complete';
            $sessionMeta['completed_at'] = date('Y-m-d H:i:s');
            file_put_contents($sessionMetaFile, json_encode($sessionMeta, JSON_PRETTY_PRINT));

            // Log session completion
            $this->logTransfer($uid, $sessionId, 'session_complete', [
                'expected_files' => count($expectedFileNames),
                'uploaded_files' => count($uploadedFiles),
                'total_size' => array_sum(array_map('filesize', array_filter($files, 'is_file')))
            ]);

            $this->logDebug('Session completed', [
                'session_id' => $sessionId,
                'files_uploaded' => count($uploadedFiles),
                'expected_files' => count($expectedFileNames)
            ]);
        }
    }

    /**
     * Check if file extension is allowed (case-insensitive)
     */
    private function isAllowedFileType(string $extension): bool
    {
        // Convert both the extension and allowed types to lowercase for comparison
        $extension = strtolower($extension);
        $allowedTypes = array_map('strtolower', $this->allowedFileTypes);

        return in_array($extension, $allowedTypes);
    }

    // generateUniqueFilename method removed - now inherited from Model base class

    /**
     * Get upload configuration and status
     */
    private function getUploadStatus(array $params): array
    {
        $status = [
            'configuration' => [
                'output_dir' => $this->outputDir,
                'registry_file' => $this->registryFile,
                'chunk_size_kb' => $this->chunkSizeKb,
                'allowed_file_types' => $this->allowedFileTypes,
                'upload_timeout' => $this->uploadTimeout
            ],
            'paths' => [
                'output_dir_absolute' => realpath($this->outputDir) ?: $this->outputDir,
                'registry_file_absolute' => realpath($this->registryFile) ?: $this->registryFile,
                'output_dir_exists' => is_dir($this->outputDir),
                'registry_file_exists' => file_exists($this->registryFile)
            ],
            'registry_info' => []
        ];

        // Get registry information if file exists
        if (file_exists($this->registryFile)) {
            $registry = $this->loadRegistry();
            $status['registry_info'] = [
                'total_users' => count($registry['users'] ?? []),
                'users' => array_keys($registry['users'] ?? []),
                'created' => $registry['created'] ?? 'unknown',
                'last_modified' => date('Y-m-d H:i:s', filemtime($this->registryFile))
            ];
        }

        // Get directory information
        if (is_dir($this->outputDir)) {
            $userDirs = [];
            $totalFiles = 0;
            $totalSize = 0;

            foreach (scandir($this->outputDir) as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = sprintf("%s/%s", $this->outputDir, $item);
                if (is_dir($path) && is_numeric($item)) {
                    $fileCount = $this->countFilesInDirectory($path);
                    $dirSize = $this->getDirectorySize($path);
                    $userDirs[$item] = [
                        'file_count' => $fileCount,
                        'size_bytes' => $dirSize,
                        'size_formatted' => $this->formatFileSize($dirSize)
                    ];
                    $totalFiles += $fileCount;
                    $totalSize += $dirSize;
                }
            }

            $status['storage_info'] = [
                'user_directories' => $userDirs,
                'total_files' => $totalFiles,
                'total_size_bytes' => $totalSize,
                'total_size_formatted' => $this->formatFileSize($totalSize)
            ];
        }

        if (Environment::isCli()) {
            $output = ["Upload Module Status", str_repeat("=", 50)];

            $output[] = "\nConfiguration:";
            foreach ($status['configuration'] as $key => $value) {
                $displayValue = is_array($value) ? implode(', ', $value) : $value;
                $output[] = sprintf("  %-20s: %s", $key, $displayValue);
            }

            $output[] = "\nPaths:";
            foreach ($status['paths'] as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'YES' : 'NO') : $value;
                $output[] = sprintf("  %-20s: %s", $key, $displayValue);
            }

            if (!empty($status['registry_info'])) {
                $output[] = "\nRegistry Information:";
                foreach ($status['registry_info'] as $key => $value) {
                    if ($key === 'users') {
                        $output[] = sprintf("  %-20s: %s", $key, implode(', ', $value));
                    } else {
                        $output[] = sprintf("  %-20s: %s", $key, $value);
                    }
                }
            }

            if (!empty($status['storage_info'])) {
                $output[] = "\nStorage Information:";
                $output[] = sprintf("  %-20s: %s", 'total_files', $status['storage_info']['total_files']);
                $output[] = sprintf("  %-20s: %s", 'total_size', $status['storage_info']['total_size_formatted']);

                if (!empty($status['storage_info']['user_directories'])) {
                    $output[] = "\nUser Directories:";
                    foreach ($status['storage_info']['user_directories'] as $uid => $info) {
                        $output[] = sprintf("  User %-15s: %d files, %s",
                            $uid, $info['file_count'], $info['size_formatted']);
                    }
                }
            }

            return [
                'type' => 'success',
                'content' => implode("\n", $output)
            ];
        }

        return [
            'type' => 'json',
            'content' => json_encode($status, JSON_PRETTY_PRINT)
        ];
    }

    /**
     * Count files in directory recursively
     */
    private function countFilesInDirectory(string $dir): int
    {
        $count = 0;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $dir): int
    {
        $size = 0;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
        return $size;
    }

    /**
     * Get help information
     */
    private function getHelp(): array
    {
        if (Environment::isCli()) {
            return [
                'type' => 'success',
                'content' => static::generateCliHelp()
            ];
        }

        return [
            'type' => 'html',
            'content' => $this->renderTemplate('upload/help', TemplateFormat::HTML, [
                'help_content' => static::getHelpMarkdown()
            ])
        ];
    }

    /**
     * Get response format from params or route
     */
    private function getResponseFormat(array $params): ?string
    {
        // Check for format in params first
        if (isset($params['format'])) {
            return $params['format'];
        }

        // Check for modifier in URI (handled by router)
        if (isset($_GET['format'])) {
            return $_GET['format'];
        }

        return null;
    }

    /**
     * Get session reports for accordion display
     */
    private function getSessionReports(array $params, ?string $format = null): array
    {
        // Use the same authentication pattern as BackupModel
        $authStatus = $this->getAuthStatus();
        if (!$authStatus['authenticated']) {
            return [
                'type' => 'error',
                'message' => 'Authentication required',
                'status_code' => 401
            ];
        }

        $uid = $authStatus['uid'];

        // Get user information
        $userInfo = $this->getUserInfo($uid);
        $userDisplay = $userInfo ?
            "{$userInfo['full_name']} ({$userInfo['email']})" :
            "User {$uid}";

        $userDir = $this->outputDir . '/' . $uid;
        $sessions = [];

        if (is_dir($userDir)) {
            $sessionDirs = glob($userDir . '/*', GLOB_ONLYDIR);

            foreach ($sessionDirs as $sessionDir) {
                $sessionId = basename($sessionDir);
                if ($sessionId === 'chunks') continue; // Skip chunks directory

                $logs = $this->getTransferLogs($uid, $sessionId);
                $files = glob($sessionDir . '/*');
                $sessionFiles = [];

                foreach ($files as $file) {
                    $filename = basename($file);
                    // Filter out internal files
                    if (is_file($file) && !in_array($filename, ['transfer.log', 'session.json'])) {
                        $sessionFiles[] = [
                            'filename' => $filename,
                            'size' => filesize($file),
                            'date' => date('Y-m-d H:i:s', filemtime($file))
                        ];
                    }
                }

                $sessions[] = [
                    'session_id' => $sessionId,
                    'files' => $sessionFiles,
                    'logs' => $logs,
                    'file_count' => count($sessionFiles),
                    'total_size' => array_sum(array_column($sessionFiles, 'size'))
                ];
            }
        }

        // Sort sessions by most recent first (handle both new and old formats)
        usort($sessions, function($a, $b) {
            $timeA = $this->extractTimestampFromSessionId($a['session_id']);
            $timeB = $this->extractTimestampFromSessionId($b['session_id']);
            return $timeB <=> $timeA; // Most recent first
        });

        if (Environment::isCli()) {
            $output = "\nUpload Sessions for {$userDisplay}:\n" . str_repeat("=", 50) . "\n";

            if (empty($sessions)) {
                $output .= "No upload sessions found.\n";
            } else {
                foreach ($sessions as $session) {
                    $output .= sprintf("Session: %s | Files: %d | Total Size: %s\n",
                        $session['session_id'],
                        $session['file_count'],
                        $this->formatFileSize((int)$session['total_size'])
                    );
                }
            }

            return [
                'type' => 'success',
                'content' => $output
            ];
        }

        // Return HTML fragment for HTMX requests
        if ($format === 'htmx') {
            // Generate HTML for sessions
            $sessionsHtml = $this->generateSessionsHtml($sessions);

            $historyContent = $this->renderTemplate('upload/history', TemplateFormat::HTML, [
                'user_info' => $userInfo,
                'user_display' => $userDisplay,
                'sessions_html' => $sessionsHtml,
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]);

            return [
                'type' => 'html',
                'content' => $historyContent
            ];
        }

        return [
            'type' => 'json',
            'content' => json_encode([
                'user_info' => $userInfo,
                'user_display' => $userDisplay,
                'sessions' => $sessions
            ])
        ];
    }

    /**
     * Generate HTML for sessions accordion
     */
    private function generateSessionsHtml(array $sessions): string
    {
        if (empty($sessions)) {
            return '
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x mb-2"></i>
                    <p class="mb-0">No upload history found.</p>
                    <small>Files you upload will appear here.</small>
                </div>
            ';
        }

        $html = '';
        foreach ($sessions as $session) {
            $sessionId = htmlspecialchars($session['session_id']);
            $fileCount = $session['file_count'];
            $totalSize = $this->formatFileSize($session['total_size']);

            // Extract session date from session ID (format: IP-timestamp-msec)
            $sessionDate = $this->extractSessionDate($sessionId);
            $sessionStatus = $this->getSessionStatus($session);

            $html .= sprintf('
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-%s">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#collapse-%s"
                                aria-expanded="false"
                                aria-controls="collapse-%s">
                            <div class="d-flex justify-content-between w-100 me-3">
                                <div>
                                    <span class="fw-bold">%s</span>
                                    <br>
                                    <small class="text-muted">%s</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-%s">%s</span>
                                    <br>
                                    <small class="text-muted">%d files, %s</small>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapse-%s"
                         class="accordion-collapse collapse"
                         aria-labelledby="heading-%s">
                        <div class="accordion-body">
            ', $sessionId, $sessionId, $sessionId, $sessionDate, $sessionId,
               $sessionStatus['color'], $sessionStatus['label'], $fileCount, $totalSize,
               $sessionId, $sessionId);

            // Add transfer information section
            $html .= $this->generateTransferInfoHtml($session);

            // Add files table
            if (!empty($session['files'])) {
                $html .= '
                    <h6 class="mt-4 mb-3">
                        <i class="fas fa-file-alt me-2"></i>Files Uploaded
                    </h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Filename</th>
                                    <th>Size</th>
                                    <th>Upload Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                ';

                foreach ($session['files'] as $file) {
                    $filename = htmlspecialchars($file['filename']);
                    $size = $this->formatFileSize($file['size']);
                    $date = htmlspecialchars($file['date']);
                    $fileStatus = $this->getFileUploadStatus($session, $file['filename']);

                    $html .= sprintf('
                        <tr>
                            <td>
                                <i class="fas fa-file me-2 text-muted"></i>
                                %s
                            </td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>
                                <span class="badge bg-%s">
                                    <i class="fas fa-%s me-1"></i>%s
                                </span>
                            </td>
                        </tr>
                    ', $filename, $size, $date,
                       $fileStatus['color'], $fileStatus['icon'], $fileStatus['label']);
                }

                $html .= '
                            </tbody>
                        </table>
                    </div>
                ';
            } else {
                $html .= '<p class="text-muted mb-0">No files in this session.</p>';
            }

            $html .= '
                        </div>
                    </div>
                </div>
            ';
        }

        return $html;
    }

    /**
     * Extract readable date from session ID
     */
    private function extractSessionDate(string $sessionId): string
    {
        // New format: IP-YYYYMMDDTHH:mm:ss.msec
        // Old format: IP-timestamp-msec (for backward compatibility)

        if (preg_match('/^[^-]+-(\d{8}T\d{6})\.(\d{4})$/', $sessionId, $matches)) {
            // New format: 127.0.0.1-20250926T030100.0001
            $dateTimeStr = $matches[1];
            $msec = $matches[2];

            // Parse YYYYMMDDTHH:mm:ss format
            $year = substr($dateTimeStr, 0, 4);
            $month = substr($dateTimeStr, 4, 2);
            $day = substr($dateTimeStr, 6, 2);
            $hour = substr($dateTimeStr, 9, 2);
            $minute = substr($dateTimeStr, 11, 2);
            $second = substr($dateTimeStr, 13, 2);

            $timestamp = mktime((int)$hour, (int)$minute, (int)$second, (int)$month, (int)$day, (int)$year);
            return date('M j, Y g:i:s A', $timestamp) . ".{$msec}";
        } else {
            // Old format: IP-timestamp-msec (backward compatibility)
            $parts = explode('-', $sessionId);
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                $timestamp = (int)$parts[1];
                return date('M j, Y g:i A', $timestamp);
            }
        }

        return 'Unknown Date';
    }

    /**
     * Extract timestamp for sorting from session ID
     */
    private function extractTimestampFromSessionId(string $sessionId): int|false
    {
        // New format: IP-YYYYMMDDTHH:mm:ss.msec
        if (preg_match('/^[^-]+-(\d{8}T\d{6})\.(\d{4})$/', $sessionId, $matches)) {
            $dateTimeStr = $matches[1];
            $msec = $matches[2];

            // Parse YYYYMMDDTHH:mm:ss format
            $year = (int)substr($dateTimeStr, 0, 4);
            $month = (int)substr($dateTimeStr, 4, 2);
            $day = (int)substr($dateTimeStr, 6, 2);
            $hour = (int)substr($dateTimeStr, 9, 2);
            $minute = (int)substr($dateTimeStr, 11, 2);
            $second = (int)substr($dateTimeStr, 13, 2);

            return mktime($hour, $minute, $second, $month, $day, $year);
        } else {
            // Old format: IP-timestamp-msec (backward compatibility)
            $parts = explode('-', $sessionId);
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                return (int)$parts[1];
            }
        }

        return 0; // Default for unknown formats
    }

    /**
     * Get session status based on transfer logs
     */
    private function getSessionStatus(array $session): array
    {
        $logs = $session['logs'] ?? [];
        $fileCount = $session['file_count'] ?? 0;

        if (empty($logs)) {
            return ['label' => 'No Activity', 'color' => 'secondary'];
        }

        // Count completed uploads
        $completedUploads = 0;
        foreach ($logs as $log) {
            if ($log['action'] === 'upload_complete') {
                $completedUploads++;
            }
        }

        if ($completedUploads === $fileCount && $fileCount > 0) {
            return ['label' => 'Complete', 'color' => 'success'];
        } elseif ($completedUploads > 0) {
            return ['label' => 'Partial', 'color' => 'warning'];
        } else {
            return ['label' => 'In Progress', 'color' => 'info'];
        }
    }

    /**
     * Generate transfer information HTML
     */
    private function generateTransferInfoHtml(array $session): string
    {
        $logs = $session['logs'] ?? [];
        $sessionId = $session['session_id'];

        if (empty($logs)) {
            return '
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No transfer activity recorded for this session.
                </div>
            ';
        }

        // Analyze transfer logs
        $transferStats = $this->analyzeTransferLogs($logs);

        $html = '
            <div class="row mb-4">
                <div class="col-md-6">
                    <h6 class="mb-3">
                        <i class="fas fa-chart-line me-2"></i>Transfer Statistics
                    </h6>
                    <div class="card bg-light">
                        <div class="card-body p-3">
        ';

        $html .= sprintf('
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="fw-bold text-primary">%d</div>
                                    <small class="text-muted">Total Chunks</small>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-success">%d</div>
                                    <small class="text-muted">Completed</small>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-info">%s</div>
                                    <small class="text-muted">Duration</small>
                                </div>
                            </div>
        ', $transferStats['total_chunks'], $transferStats['completed_uploads'], $transferStats['duration']);

        $html .= '
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-3">
                        <i class="fas fa-clock me-2"></i>Session Timeline
                    </h6>
                    <div class="timeline-container" style="max-height: 200px; overflow-y: auto;">
        ';

        // Add timeline entries
        foreach (array_slice($logs, -5) as $log) { // Show last 5 log entries
            $time = date('H:i:s', strtotime($log['timestamp']));
            $action = $this->formatLogAction($log);

            $html .= sprintf('
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-secondary me-2">%s</span>
                            <small class="text-muted">%s</small>
                        </div>
            ', $time, $action);
        }

        $html .= '
                    </div>
                </div>
            </div>
        ';

        return $html;
    }

    /**
     * Get file upload status from transfer logs
     */
    private function getFileUploadStatus(array $session, string $filename): array
    {
        $logs = $session['logs'] ?? [];

        foreach ($logs as $log) {
            if ($log['action'] === 'upload_complete' &&
                isset($log['data']['filename']) &&
                $log['data']['filename'] === $filename) {
                return ['label' => 'Complete', 'color' => 'success', 'icon' => 'check'];
            }
        }

        // Check for chunk uploads
        foreach ($logs as $log) {
            if ($log['action'] === 'chunk_upload' &&
                isset($log['data']['filename']) &&
                $log['data']['filename'] === $filename) {
                return ['label' => 'Partial', 'color' => 'warning', 'icon' => 'clock'];
            }
        }

        return ['label' => 'Unknown', 'color' => 'secondary', 'icon' => 'question'];
    }

    /**
     * Analyze transfer logs for statistics
     */
    private function analyzeTransferLogs(array $logs): array
    {
        $totalChunks = 0;
        $completedUploads = 0;
        $startTime = null;
        $endTime = null;

        foreach ($logs as $log) {
            $timestamp = strtotime($log['timestamp']);

            if ($startTime === null || $timestamp < $startTime) {
                $startTime = $timestamp;
            }
            if ($endTime === null || $timestamp > $endTime) {
                $endTime = $timestamp;
            }

            if ($log['action'] === 'chunk_upload') {
                $totalChunks++;
            } elseif ($log['action'] === 'upload_complete') {
                $completedUploads++;
            }
        }

        $duration = 'Unknown';
        if ($startTime && $endTime) {
            $diff = $endTime - $startTime;
            if ($diff < 60) {
                $duration = $diff . 's';
            } else {
                $duration = gmdate('i:s', $diff);
            }
        }

        return [
            'total_chunks' => $totalChunks,
            'completed_uploads' => $completedUploads,
            'duration' => $duration,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
    }

    /**
     * Format log action for display
     */
    private function formatLogAction(array $log): string
    {
        $action = $log['action'];
        $data = $log['data'] ?? [];

        switch ($action) {
            case 'chunk_upload':
                $filename = $data['filename'] ?? 'unknown';
                $chunk = $data['chunk_index'] ?? 0;
                $total = $data['total_chunks'] ?? 0;
                return "Chunk {$chunk}/{$total} for {$filename}";

            case 'upload_complete':
                $filename = $data['filename'] ?? 'unknown';
                $size = isset($data['file_size']) ? $this->formatFileSize($data['file_size']) : 'unknown size';
                return "Completed {$filename} ({$size})";

            default:
                return ucfirst(str_replace('_', ' ', $action));
        }
    }

    /**
     * Load SQL template with fallback to inline SQL
     */
    private function loadSqlTemplate(string $templateName, array $variables = []): string
    {
        try {
            // Try to load from file first
            return ApplicationPaths::loadSqlTemplate('upload', $templateName, $variables);
        } catch (\RuntimeException $e) {
            // Fallback to inline SQL based on template name
            return $this->getInlineSql($templateName);
        }
    }

    /**
     * Get inline SQL as fallback when template files don't exist
     */
    private function getInlineSql(string $templateName): string
    {
        switch ($templateName) {
            case 'upload/user_by_username':
            case 'user_by_username':
                return "SELECT uid, username FROM users WHERE username = ?";
            case 'upload/user_details':
            case 'user_details':
                return "SELECT username, firstname, lastname, email FROM users WHERE uid = ?";
            case 'upload/user_info':
            case 'user_info':
                return "SELECT uid, username, firstname, lastname, email FROM users WHERE uid = ?";
            default:
                throw new \RuntimeException("Unknown SQL template: {$templateName}");
        }
    }
}
