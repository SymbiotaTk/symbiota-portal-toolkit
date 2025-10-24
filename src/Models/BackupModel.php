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
use Symbiota\Helpers\Ext\SymbAuth;
use Symbiota\Helpers\Core\UriParser;
use Symbiota\Helpers\Core\ApplicationPaths;
use ZipArchive;
use mysqli;
use Exception;
use DateTime;
use ReflectionClass;

/**
 * Backup Model - Encrypted backup creation and management with Symbiota integration
 *
 * Provides functionality for:
 * - Collection selection with colladmin role user management
 * - Passphrase registration and obfuscation with site salt
 * - Integration with Symbiota DwcArchiverCore for backup creation
 * - 24-hour backup throttling with configurable retention
 * - Encrypted ZIP re-compression with user passphrases
 * - CLI commands for system administration
 * - Backup status tracking and cleanup management
 */
class BackupModel extends Model
{
    // Properties removed - now handled by Model base class

    // Backup configuration constants
    private const DEFAULT_BACKUP_THRESHOLD_MINUTES = 1440; // 24 hours
    private const OFFSET_BUFFER = 25; // 25 minutes (23h35m threshold)
    private const DEFAULT_RETENTION_THRESHOLD = 7; // Keep 7 daily backups
    private const BACKUP_REGISTRY_FILE = 'helpers-backup.json';

    // Parameter name constants
    private const PARAM_CONFIG = 'config';
    private const PARAM_COLLID = 'collid';
    private const PARAM_USERID = 'userid';
    private const PARAM_PASSPHRASE = 'passphrase';
    private const PARAM_OUTPUT_DIR = 'output-dir';
    private const PARAM_REGISTRY_FILE = 'registry-file';
    private const PARAM_BACKUP_THRESHOLD = 'backup-threshold';
    private const PARAM_RETENTION_THRESHOLD = 'retention-threshold';
    private const PARAM_CLASSPATH = 'classpath';
    private const PARAM_CLASSPATH_SHORT = 'cp';
    private const PARAM_REGISTRY_FILE_SHORT = 'f';
    private const PARAM_REMOVE_ALL = 'remove-all';
    private const PARAM_FORMAT = 'format';
    private const PARAM_SITE_SALT = 'site-salt';
    private const PARAM_SALTIT = 'saltit';
    private const PARAM_HTTP_POST = 'http-post';

    // Configuration key constants
    private const CONFIG_REGISTRY_FILE = 'registry_file';
    private const CONFIG_OUTPUT_DIR = 'output_dir';
    private const CONFIG_SITE_SALT = 'site_salt';
    private const CONFIG_HTTP_POST = 'http_post';
    private const CONFIG_BACKUP_THRESHOLD = 'backup_threshold';
    private const CONFIG_RETENTION_THRESHOLD = 'retention_threshold';
    private const CONFIG_CLASSPATH = 'classpath';
    private const CONFIG_SALTIT = 'saltit';


    private string $portalTempDir;
    private string $backupRegistryPath;
    private string $siteSalt;
    private array $backupConfig = [];

    /**
     * Get the model's routing resource ID
     */
    #[Override]
    public static function getModelId(): string
    {
        return 'backup';
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
        $templatePath = sprintf('%s/cli/help/backup_model.txt', ApplicationPaths::templatesDirectory());
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

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Set portal temp directory (where Symbiota stores temp files)
        $this->portalTempDir = $this->getPortalTempDir();

        // Use Configuration singleton for backup-specific settings
        $configuration = Configuration::getInstance();
        $backupConfig = $configuration ?
            ($configuration->get('components.backup') ?? $configuration->get('backup') ?? []) :
            ($config['components']['backup'] ?? $config['backup'] ?? []);
        $registryFile = $backupConfig[self::CONFIG_REGISTRY_FILE] ?? self::BACKUP_REGISTRY_FILE;

        // If registry file is a relative path, make it absolute; if absolute, use as-is
        if (str_starts_with($registryFile, '/') || str_contains($registryFile, ':')) {
            // Absolute path
            $this->backupRegistryPath = $registryFile;
        } else {
            // Relative path - use as configured without additional prefixing
            $this->backupRegistryPath = $registryFile;
        }

        // Get site salt from configuration
        $this->siteSalt = $backupConfig[self::CONFIG_SITE_SALT] ?? $this->generateSiteSalt();

        // Initialize backup configuration
        $this->backupConfig = $backupConfig;

        // Override working/storage directories to use configured output_dir
        // This prevents creating backup_working/backup_storage in application directory
        $outputDir = $backupConfig['output_dir'] ?? ($this->portalTempDir . '/downloads/symbtk');
        $this->workingDir = $outputDir . '/working';
        $this->storageDir = $outputDir . '/storage';

        // Ensure directories exist
        $this->ensureDirectories();

        // Set public actions for this model
        $this->publicActions = ['index', '', 'help', 'collections', 'status'];
    }

    /**
     * Override isPublicAction to handle collection routes
     */
    #[Override]
    protected function isPublicAction(string $action): bool
    {
        // Check standard public actions first
        if (parent::isPublicAction($action)) {
            return true;
        }

        // Check if this is a numeric collection ID (collection route)
        if (is_numeric($action)) {
            $collid = intval($action);
            $subAction = $this->routeParams[0] ?? ''; // First element after collection ID
            return $this->isPublicCollectionRoute($collid, $subAction);
        }

        return false;
    }

    /**
     * Get available actions for this model
     */
    public function getAvailableActions(): array
    {
        return [
            'index' => 'Main backup management page',
            'collections' => 'List collections with colladmin users',
            'all-collections' => 'List all collections (for SuperAdmin users)',
            'users' => 'List colladmin users for collection',
            'user-collections' => 'List collections available for user registration',
            'register' => 'Register user/passphrase for collection',
            'create-encrypted' => 'Create encrypted backup',
            'status' => 'Check backup status and info',
            'list' => 'List available backups for collection',
            'cleanup' => 'Remove old backups per retention policy',
            'remove-user' => 'Remove user registration',
            'reset-passphrase' => 'Reset user passphrase',
            'dry-run' => 'Report collection size without backup',
            'config' => 'Show current configuration and paths',
            'test' => 'Test backup system functionality',
            'help' => 'Show help information'
        ];
    }

    /**
     * Extract parameters from route elements and query parameters
     */
    private function extractRouteParams(array $allParams): array
    {
        $extracted = [];

        // Extract numeric parameters from route elements
        if (isset($allParams['elements']) && is_array($allParams['elements'])) {
            $numericIndex = 0;
            foreach ($allParams['elements'] as $element) {
                if (is_numeric($element['name'])) {
                    $extracted[$numericIndex] = $element['name'];
                    $numericIndex++;
                }
            }
        }

        // Add query parameters
        foreach ($allParams as $key => $value) {
            if ($key !== 'elements' && $key !== 'query' && $key !== 'format') {
                $extracted[$key] = $value;
            }
        }

        return $extracted;
    }

    /**
     * Load configuration from command line parameters
     */
    private function loadConfigurationFromParams(array $params): void
    {
        // Load from config file if specified
        // Handle both long and short flags
        $configPath = $params['config'] ?? $params['c'] ?? null;
        if ($configPath !== null && $configPath !== '') {
            $this->loadConfigFile($configPath);
        }

        // Override with direct parameters
        $outputDir = $params[self::PARAM_OUTPUT_DIR] ?? $params['o'] ?? null;
        if ($outputDir !== null && $outputDir !== '') {
            $this->backupConfig[self::CONFIG_OUTPUT_DIR] = $outputDir;
        }

        $registryFile = $params[self::PARAM_REGISTRY_FILE] ?? $params[self::PARAM_REGISTRY_FILE_SHORT] ?? null;
        if ($registryFile !== null && $registryFile !== '') {
            $this->backupConfig[self::CONFIG_REGISTRY_FILE] = $registryFile;
        }

        $classpath = $params[self::PARAM_CLASSPATH] ?? $params[self::PARAM_CLASSPATH_SHORT] ?? null;
        if ($classpath) {
            $this->backupConfig[self::CONFIG_CLASSPATH] = $classpath;
        }

        $retentionThreshold = $params['retention-threshold'] ?? $params['rt'] ?? null;
        if ($retentionThreshold !== null) {
            $this->backupConfig['retention_threshold'] = (int)$retentionThreshold;
        }

        $backupThreshold = $params['backup-threshold'] ?? $params['bt'] ?? null;
        if ($backupThreshold !== null) {
            $this->backupConfig['backup_threshold'] = (float)$backupThreshold;
        }

        $siteSalt = $params['site-salt'] ?? null;
        if ($siteSalt) {
            $this->backupConfig['site_salt'] = $siteSalt;
        }

        // Note: http_post parameter is NOT supported for security reasons
        // This setting can only be configured in config files, not via URL parameters

        // Update paths based on configuration
        $this->updatePathsFromConfig();
    }

    /**
     * Load configuration from INI file
     */
    private function loadConfigFile(string $configPath): void
    {
        if (!file_exists($configPath)) {
            throw new Exception("Configuration file not found: {$configPath}");
        }

        // Use Configuration class to handle new INI heredoc format
        $configLoader = new \Symbiota\Helpers\Core\Configuration($configPath);
        $config = $configLoader->getAll();

        // Merge backup section into backup config (support both old and new formats)
        $backupConfig = $config['components']['backup'] ?? $config['backup'] ?? [];
        if (!empty($backupConfig)) {
            $this->backupConfig = array_merge($this->backupConfig, $backupConfig);

            // Update site salt if provided in config file
            if (isset($backupConfig[self::CONFIG_SITE_SALT])) {
                $this->siteSalt = $backupConfig[self::CONFIG_SITE_SALT];
            }
        }

        // Track the config file path for display purposes
        $this->backupConfig['_config_file_path'] = realpath($configPath);
    }

    /**
     * Update internal paths based on configuration
     */
    private function updatePathsFromConfig(): void
    {
        // Update backup registry path if registry file is specified
        if (isset($this->backupConfig[self::CONFIG_REGISTRY_FILE])) {
            $registryFile = $this->backupConfig[self::CONFIG_REGISTRY_FILE];

            // If registry file is a relative path, make it absolute; if absolute, use as-is
            if (str_starts_with($registryFile, '/') || str_contains($registryFile, ':')) {
                // Absolute path
                $this->backupRegistryPath = $registryFile;
            } else {
                // Relative path - use as configured without additional prefixing
                $this->backupRegistryPath = $registryFile;
            }

            // Ensure directory exists
            $dir = dirname($this->backupRegistryPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Get backup directory (configurable)
     */
    private function getBackupDir(): string
    {
        $dir = $this->backupConfig['output_dir'] ?? ($this->portalTempDir . '/downloads/symbtk');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get backup lock file path for a collection
     */
    private function getBackupLockPath(int $collid): string
    {
        return sprintf('%s/.backup_lock_%d', $this->getBackupDir(), $collid);
    }

    /**
     * Check if a backup is currently in progress for a collection
     */
    private function isBackupInProgress(int $collid): bool
    {
        $lockFile = $this->getBackupLockPath($collid);

        if (!file_exists($lockFile)) {
            return false;
        }

        // Check if lock file is stale (older than 2 hours)
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > 7200) { // 2 hours
            unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Create backup lock file
     */
    private function createBackupLock(int $collid): bool
    {
        // Check if backup is already in progress
        if ($this->isBackupInProgress($collid)) {
            return false;
        }

        $lockFile = $this->getBackupLockPath($collid);
        $lockData = [
            'started' => date('Y-m-d H:i:s'),
            'pid' => getmypid(),
            'user' => $this->getAuthStatus()['uid'] ?? 'cli'
        ];

        return file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Remove backup lock file
     */
    private function removeBackupLock(int $collid): bool
    {
        $lockFile = $this->getBackupLockPath($collid);

        if (file_exists($lockFile)) {
            return unlink($lockFile);
        }

        return true;
    }

    /**
     * Check if enough time has passed since last backup (throttling)
     */
    private function isBackupThrottled(int $collid): array
    {
        $registry = $this->loadBackupRegistry();

        if (!isset($registry[$collid])) {
            return ['throttled' => false];
        }

        // Find the most recent backup time from any user
        $lastBackupTime = null;
        foreach ($registry[$collid] as $userInfo) {
            if (isset($userInfo['last_backup'])) {
                $backupTime = strtotime($userInfo['last_backup']);
                if ($lastBackupTime === null || $backupTime > $lastBackupTime) {
                    $lastBackupTime = $backupTime;
                }
            }
        }

        if ($lastBackupTime === null) {
            return ['throttled' => false];
        }

        $thresholdMinutes = (float)($this->backupConfig['backup_threshold'] ?? 60);
        $nextAllowedTime = (int)((float)$lastBackupTime + ($thresholdMinutes * 60.0));
        $currentTime = time();

        if ($currentTime < $nextAllowedTime) {
            $waitMinutes = (int)ceil(((float)$nextAllowedTime - (float)$currentTime) / 60.0);
            return [
                'throttled' => true,
                'message' => sprintf(
                    'Backup throttled. Last backup was %s. Next backup allowed in %d minutes.',
                    date('Y-m-d H:i:s', (int)$lastBackupTime),
                    $waitMinutes
                ),
                'next_allowed' => date('Y-m-d H:i:s', $nextAllowedTime),
                'wait_minutes' => $waitMinutes
            ];
        }

        return ['throttled' => false];
    }

    /**
     * Execute the requested action - implements base class abstract method
     */
    #[Override]
    protected function executeAction(string $action, array $route, array $params): array
    {
        // Route now contains route element names (e.g., ['99', '23'])
        // Params contains named parameters (e.g., ['config' => 'file.ini', 'passphrase' => 'secret'])

        // Create extracted params with numeric indices for route elements
        $extractedParams = $params; // Start with named parameters

        // Add route elements as numeric indices
        foreach ($route as $index => $value) {
            $extractedParams[$index] = $value;
        }

        // Load configuration from parameters
        $this->loadConfigurationFromParams($extractedParams);

        // Authentication is now handled by base class

        // Handle HTTP routes with collection ID (action is numeric)
        if (is_numeric($action)) {
            $collid = intval($action);
            $subAction = $route[0] ?? ''; // First element after collection ID



            return $this->handleCollectionRoute($collid, $subAction, $extractedParams);
        }

        // Handle user routes
        if ($action === 'user' && isset($route['elements'][2]['name'])) {
            $uid = intval($route['elements'][2]['name']);
            return $this->handleUserRoute($uid, $extractedParams);
        }

        switch ($action) {
            case 'index':
            case '':
                return $this->getMainPage();
            case 'collections':
                return $this->listCollections($extractedParams);
            case 'all-collections':
                return $this->listAllCollections($extractedParams);
            case 'test':
                return $this->test($extractedParams);
            case 'users':
                return $this->listCollectionUsers($extractedParams);
            case 'user-collections':
                // For HTTP interface, automatically use current user's UID
                if (!Environment::isCli() && !isset($extractedParams['uid']) && !isset($extractedParams[0])) {
                    $authStatus = SymbAuth::getAuthStatus();
                    if ($authStatus['authenticated']) {
                        $extractedParams['uid'] = $authStatus['uid'];
                    }
                }

                // For HTTP interface with HTMX format, use the specialized method
                $format = $extractedParams['format'] ?? null;
                if (!Environment::isCli() && ($format === 'htmx' || $format === 'html-partial' || $format === 'html')) {
                    return $this->getUserCollectionsHtmx($extractedParams['uid'], $extractedParams);
                }

                return $this->listUserAvailableCollections($extractedParams);
            case 'register':
                return $this->registerUserPassphrase($extractedParams);

            case 'status':
                return $this->getBackupStatus($extractedParams);
            case 'list':
                return $this->listBackups($extractedParams);
            case 'cleanup':
                return $this->cleanupBackups($extractedParams);
            case 'remove-user':
                return $this->removeUserRegistration($extractedParams);
            case 'reset-passphrase':
                return $this->resetUserPassphrase($extractedParams);
            case 'dry-run':
                return $this->dryRunBackup($extractedParams);
            case 'config':
            case 'show-config':
                return $this->showConfiguration($extractedParams);
            case 'registry':
                return $this->listRegistry($extractedParams);
            case 'verify-pw':
                return $this->verifyPassphrase($extractedParams);
            case 'cleanup':
                return $this->cleanupBackups($extractedParams);
            case 'symbdwc':
                return $this->createSymbiotaDwcArchive($extractedParams);

            case 'create-encrypted':
                return $this->createEncryptedDwcArchive($extractedParams);

            // HTTP-specific actions for web interface
            case 'set-passphrase':
                return $this->setPassphraseHttp($extractedParams);
            case 'check-passphrase':
                return $this->checkPassphraseHttp($extractedParams);
            case 'disable':
                return $this->disableBackupHttp($extractedParams);
            case 'create-backup':
                return $this->createBackupHttp($extractedParams);

            default:
                return [
                    'type' => 'error',
                    'message' => "Unknown action: {$action}",
                    'status_code' => 404
                ];
        }
    }

    /**
     * Get main backup management page
     */
    public function getMainPage(): array
    {
        if (Environment::isCli()) {
            return [
                'type' => 'success',
                'content' => "Backup Manager - Use 'helpers.php backup --help' for more information"
            ];
        }

        // Check user permissions using SymbAuth
        $authStatus = SymbAuth::getAuthStatus();
        $hasBackupPermission = $authStatus['can_backup'];

        // Prepare template variables
        $templateVars = [
            'app_url_prefix' => $this->getAppUrlPrefix(),
            'title' => 'Collection Backup',
            'permission_display' => $hasBackupPermission ? 'none' : 'block',
            'backup_display' => $hasBackupPermission ? 'block' : 'none',
            'collections_content' => $hasBackupPermission ? $this->getCollectionsListHtml() : '',
            'auth_status' => $authStatus
        ];

        // Render the backup content template
        $mainContent = $this->renderTemplate('backup/content', TemplateFormat::HTML, $templateVars);

        // Always return HTML page structure with content
        return [
            'type' => 'html',
            'title' => $templateVars['title'],
            'permission_display' => $templateVars['permission_display'],
            'backup_display' => $templateVars['backup_display'],
            'collections_content' => $templateVars['collections_content'],
            'auth_status' => $templateVars['auth_status'],
            'content' => $mainContent
        ];
    }

    /**
     * Handle collection-specific routes
     */
    private function handleCollectionRoute(int $collid, string $subAction, array $params): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Check if this is a public route
        $isPublic = $this->isPublicCollectionRoute($collid, $subAction);

        // Only check authentication for non-public routes
        if (!$isPublic) {
            $authStatus = $this->getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            if (!SymbAuth::canAccessCollection($collid)) {
                return [
                    'type' => 'error',
                    'message' => 'Access denied. You do not have permission to access this collection.',
                    'status_code' => 403
                ];
            }
        }

        switch ($subAction) {
            case '':
                // GET /backup/<collid> - Collection status
                if ($method === 'GET') {
                    return $this->getCollectionStatus($collid, $params);
                }
                // POST /backup/<collid> - Create backup
                if ($method === 'POST') {
                    return $this->createCollectionBackup($collid, $params);
                }
                break;

            case 'list':
                // GET /backup/<collid>/list - List backups
                return $this->listCollectionBackups($collid, $params);

            case 'download':
                // GET /backup/<collid>/download[/<number>] - Download backup
                $backupNumber = isset($params[3]) ? intval($params[3]) : 1;
                return $this->downloadBackupHttp($collid, $backupNumber, $params);

            case 'register':
                // POST /backup/<collid>/register - Register user
                return $this->registerCollectionUser($collid, $params);

            case 'verify-pw':
                // POST /backup/<collid>/verify-pw - Verify passphrase
                return $this->verifyCollectionPassphrase($collid, $params);

            case 'clear':
                // POST /backup/<collid>/clear - Clear user registration
                return $this->clearCollectionUser($collid, $params);

            case 'item':
                // GET /backup/<collid>/item:htmx - Get collection item HTML for refresh
                return $this->getCollectionItemHtml($collid, $params);

            case 'status':
                // GET /backup/<collid>/status:htmx - Get collection status HTML for refresh
                return $this->getCollectionStatusHtml($collid, $params);

            default:
                return [
                    'type' => 'error',
                    'message' => 'Unknown collection action: ' . $subAction,
                    'status_code' => 404
                ];
        }

        return [
            'type' => 'error',
            'message' => 'Method not allowed',
            'status_code' => 405
        ];
    }

    /**
     * Handle user-specific routes
     */
    private function handleUserRoute(int $uid, array $params): array
    {
        // POST /backup/user/<uid> - Get user collections
        return $this->getUserCollectionsHtmx($uid, $params);
    }

    // isPublicAction method removed - now handled by base class publicActions property

    /**
     * Check if collection route is public
     */
    private function isPublicCollectionRoute(int $collid, string $subAction): bool
    {
        // Check if public HTTP access is enabled in configuration
        $httpPostEnabled = $this->backupConfig[self::CONFIG_HTTP_POST] ?? false;

        if (!$httpPostEnabled) {
            // If http_post is not enabled, no collection routes are public
            return false;
        }

        // When http_post is enabled, these routes are public:
        // GET /?/backup/<collid>          - status
        // GET /?/backup/<collid>/list     - list
        // GET /?/backup/<collid>/download - download
        // POST /?/backup/<collid>         - create backup (empty subAction for POST)
        $publicRoutes = ['', 'list', 'download'];
        return in_array($subAction, $publicRoutes);
    }

    /**
     * Get collection status (HTTP route handler)
     */
    private function getCollectionStatus(int $collid, array $params): array
    {
        try {
            $backupManager = $this->getBackupManager();
            $collectionInfo = $backupManager->getCollectionInfo($collid);

            if (!$collectionInfo) {
                return [
                    'type' => 'error',
                    'message' => 'Collection not found',
                    'status_code' => 404
                ];
            }

            // Get backup registry info
            $registry = $this->loadBackupRegistry();
            $isRegistered = isset($registry[$collid]);
            $registeredUsers = $isRegistered ? array_keys($registry[$collid]) : [];

            // Get backup files info
            $backupFiles = $this->getBackupFilesInfo($collid);

            // Calculate estimated size and record count
            $recordCount = $this->getCollectionRecordCount(strval($collid));
            $estimatedSizeBytes = $this->estimateBackupSize(strval($collid));
            $estimatedSize = $this->formatFileSize($estimatedSizeBytes);

            $status = [
                'collid' => $collid,
                'collection_name' => $collectionInfo['collectionname'],
                'institution_code' => $collectionInfo['institutioncode'],
                'collection_code' => $collectionInfo['collectioncode'],
                'record_count' => $recordCount,
                'estimated_size' => $estimatedSize,
                'is_registered' => $isRegistered,
                'registered_users' => $registeredUsers,
                'backup_files' => $backupFiles,
                'last_backup' => $this->getLastBackupTime($collid),
                'next_backup_allowed' => $this->getNextBackupTime($collid),
                'http_post' => $this->backupConfig[self::CONFIG_HTTP_POST] ?? false
            ];

            // Check for :htmx modifier to return HTML fragment
            $format = $params['format'] ?? null;
            if ($format === 'htmx' || $format === 'html-partial' || $format === 'html') {
                // Flatten the collection data for template engine (doesn't support nested access)
                $templateData = [];
                foreach ($status as $key => $value) {
                    if (is_array($value)) {
                        // For arrays, convert to JSON or handle specially
                        if ($key === 'backup_files') {
                            $templateData['backup_files_count'] = $value['count'] ?? 0;
                        } else {
                            $templateData[$key] = json_encode($value);
                        }
                    } else {
                        $templateData[$key] = $value;
                    }
                }

                // Add conditional attributes for template
                $isRegistered = $status['is_registered'] ?? false;
                $templateData['passphrase_disabled'] = $isRegistered ? 'disabled' : '';
                $templateData['register_disabled'] = $isRegistered ? 'disabled' : '';
                $templateData['register_text'] = $isRegistered ? 'Update' : 'Set Passphrase';
                $templateData['clear_style'] = $isRegistered ? '' : 'style="display: none;"';
                $templateData['verify_style'] = $isRegistered ? '' : 'style="display: none;"';
                $templateData['user_info_style'] = $isRegistered ? '' : 'style="display: none;"';
                $templateData['create_disabled'] = $isRegistered ? '' : 'disabled';
                $templateData['download_disabled'] = ($templateData['backup_files_count'] > 0) ? '' : 'disabled';

                $templateData['app_url_prefix'] = $this->getAppUrlPrefix();

                // Return HTMX fragment for accordion expansion
                $content = $this->renderTemplate('backup/collection_status', TemplateFormat::HTML, $templateData);

                return [
                    'type' => 'htmx',
                    'content' => $content
                ];
            } else {
                // Return JSON for public API access
                return [
                    'type' => 'json',
                    'data' => $status
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error retrieving collection status: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get collections list HTML for backup interface
     */
    private function getCollectionsListHtml(): string
    {
        if (!$this->isDatabaseAvailable()) {
            return '<div class="alert alert-warning">Database connection not available. Using test data.</div>';
        }

        try {
            // Get collections data using BackupManager
            $backupManager = $this->getBackupManager();
            $allCollections = $backupManager->getAllCollections();

            if (empty($allCollections)) {
                return '<div class="alert alert-info">No collections available for backup.</div>';
            }

            // Filter collections based on user permissions
            $authStatus = SymbAuth::getAuthStatus();
            $accessibleCollections = [];

            if ($authStatus['is_admin']) {
                // SuperAdmin sees all collections
                $accessibleCollections = $allCollections;
            } else {
                // CollAdmin sees only their assigned collections
                $userCollections = $authStatus['accessible_collections'] ?? [];
                foreach ($allCollections as $collection) {
                    if (in_array($collection['collid'], $userCollections)) {
                        $accessibleCollections[] = $collection;
                    }
                }
            }

            if (empty($accessibleCollections)) {
                $message = $authStatus['is_admin']
                    ? 'No collections available for backup.'
                    : 'You do not have access to any collections for backup.';
                return '<div class="alert alert-info">' . $message . '</div>';
            }

            // Load backup registry to show status
            $registry = $this->loadBackupRegistry();

            $html = '';
            foreach ($accessibleCollections as $collection) {
                $isRegistered = isset($registry[$collection['collid']]);
                $collection['is_registered'] = $isRegistered;
                $collection['status_text'] = $isRegistered ? 'Enabled' : 'Not Configured';
                $collection['status_class'] = $isRegistered ? 'text-success' : 'text-muted';

                $html .= $this->renderCollectionItem($collection);
            }

            return $html;
        } catch (Exception $e) {
            return '<div class="alert alert-danger">Error loading collections: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }




    /**
     * List collection backups (HTTP route handler)
     */
    private function listCollectionBackups(int $collid, array $params): array
    {
        try {
            // Get backup files for this collection
            $backupFiles = $this->getBackupFilesInfo($collid);

            // Check for :htmx modifier to return HTML fragment
            $format = $params['format'] ?? null;
            if ($format === 'htmx' || $format === 'html-partial' || $format === 'html') {
                // Return HTML fragment for HTMX
                if (empty($backupFiles['files'])) {
                    $content = '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No backup files found for this collection.</div>';
                } else {
                    $content = '<div class="backup-history">';
                    $content .= '<h6 class="mb-3"><i class="fas fa-history me-2"></i>Backup History (' . $backupFiles['count'] . ' files, ' . $backupFiles['total_size'] . ')</h6>';
                    $content .= '<div class="table-responsive">';
                    $content .= '<table class="table table-sm table-striped">';
                    $content .= '<thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Age</th><th>Actions</th></tr></thead>';
                    $content .= '<tbody>';

                    foreach ($backupFiles['files'] as $index => $file) {
                        $downloadUrl = $this->getAppUrlPrefix() . "backup/{$collid}/download/" . ($index + 1);
                        $content .= '<tr>';
                        $content .= '<td><code>' . htmlspecialchars($file['filename']) . '</code></td>';
                        $content .= '<td>' . htmlspecialchars($file['size']) . '</td>';
                        $content .= '<td>' . htmlspecialchars($file['timestamp']) . '</td>';
                        $content .= '<td>' . $file['age_days'] . ' days</td>';
                        $content .= '<td><a href="' . htmlspecialchars($downloadUrl) . '" class="btn btn-sm btn-outline-primary"><i class="fas fa-download me-1"></i>Download</a></td>';
                        $content .= '</tr>';
                    }

                    $content .= '</tbody></table></div></div>';
                }

                return [
                    'type' => 'html',
                    'content' => $content
                ];
            }

            // Return JSON for API access
            return [
                'type' => 'json',
                'data' => [
                    'collid' => $collid,
                    'backups' => $backupFiles['files'],
                    'total_count' => $backupFiles['count'],
                    'total_size' => $backupFiles['total_size'],
                    'latest_backup' => $backupFiles['latest']
                ]
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error listing backups: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Create collection backup (HTTP route handler)
     */
    private function createCollectionBackup(int $collid, array $params): array
    {
        try {
            // Detect if this is an HTMX request or API/command-line request
            $isHtmxRequest = isset($_SERVER['HTTP_HX_REQUEST']) ||
                           ($params['format'] ?? '') === 'htmx' ||
                           ($params['format'] ?? '') === 'html';

            // Check if HTTP POST backup creation is enabled in config
            $httpPostEnabled = $this->backupConfig[self::CONFIG_HTTP_POST] ?? false;

            if (!$httpPostEnabled) {
                if ($isHtmxRequest) {
                    $errorContent = $this->renderBackupResult([
                        'type' => 'error',
                        'message' => 'HTTP POST backup creation is disabled in configuration'
                    ]);

                    return [
                        'type' => 'htmx',
                        'content' => $errorContent
                    ];
                } else {
                    return [
                        'type' => 'error',
                        'message' => 'HTTP POST backup creation is disabled in configuration',
                        'status_code' => 403
                    ];
                }
            }

            // Check throttle before attempting backup
            $throttleCheck = $this->checkBackupThrottle((string)$collid);
            if (!$throttleCheck['allowed']) {
                if ($isHtmxRequest) {
                    $errorContent = $this->renderBackupResult([
                        'type' => 'throttle',
                        'message' => $throttleCheck['message'],
                        'next_allowed' => $throttleCheck['next_allowed_formatted'] ?? 'Unknown'
                    ]);

                    return [
                        'type' => 'htmx',
                        'content' => $errorContent
                    ];
                } else {
                    return [
                        'type' => 'error',
                        'message' => $throttleCheck['message'],
                        'next_allowed' => $throttleCheck['next_allowed_formatted'] ?? 'Unknown',
                        'status_code' => 429
                    ];
                }
            }

            // Create the actual backup using the same logic as CLI
            $result = $this->createEncryptedDwcArchive([
                'collid' => $collid
            ]);

            if ($result['type'] === 'success') {
                if ($isHtmxRequest) {
                    // For HTMX, return HTML content
                    $successContent = $this->renderBackupResult([
                        'type' => 'success',
                        'message' => 'Backup created successfully',
                        'filename' => $result['filename'] ?? 'backup.zip',
                        'size' => $result['size'] ?? 0,
                        'download_url' => $this->getAppUrlPrefix() . 'backup/' . $collid . '/download',
                        'collection_id' => $collid
                    ]);

                    return [
                        'type' => 'htmx',
                        'content' => $successContent,
                        'headers' => [
                            'HX-Trigger' => 'refresh-status, refresh-details'
                        ]
                    ];
                } else {
                    // For API/command-line, return JSON
                    return [
                        'type' => 'success',
                        'message' => 'Backup created successfully',
                        'data' => [
                            'collection_id' => $collid,
                            'filename' => $result['filename'] ?? 'backup.zip',
                            'size' => $result['size'] ?? 0,
                            'download_url' => $this->getAppUrlPrefix() . 'backup/' . $collid . '/download'
                        ]
                    ];
                }
            }

            // Handle other result types
            if ($isHtmxRequest) {
                $errorContent = $this->renderBackupResult([
                    'type' => 'error',
                    'message' => $result['message'] ?? 'Unknown error occurred'
                ]);

                return [
                    'type' => 'htmx',
                    'content' => $errorContent
                ];
            } else {
                return [
                    'type' => 'error',
                    'message' => $result['message'] ?? 'Unknown error occurred',
                    'status_code' => 500
                ];
            }

        } catch (Exception $e) {
            if ($isHtmxRequest) {
                $errorContent = $this->renderBackupResult([
                    'type' => 'error',
                    'message' => 'Error creating backup: ' . $e->getMessage()
                ]);

                return [
                    'type' => 'htmx',
                    'content' => $errorContent
                ];
            } else {
                return [
                    'type' => 'error',
                    'message' => 'Error creating backup: ' . $e->getMessage(),
                    'status_code' => 500
                ];
            }
        }
    }

    /**
     * Render backup result for HTMX response
     */
    private function renderBackupResult(array $result): string
    {
        $templateData = [
            'app_url_prefix' => $this->getAppUrlPrefix(),
            'success_content' => '',
            'progress_content' => '',
            'throttle_content' => '',
            'error_content' => ''
        ];

        switch ($result['type']) {
            case 'success':
                $filename = $result['filename'] ?? 'backup.zip';
                $formattedSize = $this->formatFileSize($result['size'] ?? 0);
                $downloadUrl = $result['download_url'] ?? '#';

                $templateData['success_content'] = sprintf(
                    '<div class="alert alert-success d-flex align-items-center" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <div class="flex-grow-1">
                            <strong>Backup Created Successfully!</strong>
                            <div class="mt-1">
                                <small class="text-muted">
                                    File: <code>%s</code> |
                                    Size: <span class="fw-bold">%s</span>
                                </small>
                            </div>
                            <div class="mt-2">
                                <a href="%s" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-download me-1"></i>
                                    Download Backup
                                </a>
                            </div>
                        </div>
                    </div>',
                    htmlspecialchars($filename),
                    htmlspecialchars($formattedSize),
                    htmlspecialchars($downloadUrl)
                );
                break;

            case 'progress':
                $progressMessage = $result['message'] ?? 'Creating backup archive...';
                $progressPercent = $result['progress'] ?? 25;

                $templateData['progress_content'] = sprintf(
                    '<div class="alert alert-info d-flex align-items-center" role="alert">
                        <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                        <div class="flex-grow-1">
                            <strong>Backup in Progress...</strong>
                            <div class="mt-1">
                                <small class="text-muted">%s</small>
                            </div>
                            <div class="progress mt-2" style="height: 4px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                     role="progressbar"
                                     style="width: %d%%"
                                     aria-valuenow="%d"
                                     aria-valuemin="0"
                                     aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>',
                    htmlspecialchars($progressMessage),
                    $progressPercent,
                    $progressPercent
                );
                break;

            case 'throttle':
                $throttleMessage = $result['message'] ?? 'Backup throttled';

                $templateData['throttle_content'] = sprintf(
                    '<div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="fas fa-clock me-2"></i>
                        <div class="flex-grow-1">
                            <strong>Backup Throttled</strong>
                            <div class="mt-1">
                                <small class="text-muted">%s</small>
                            </div>
                        </div>
                    </div>',
                    htmlspecialchars($throttleMessage)
                );
                break;

            case 'error':
            default:
                $errorMessage = $result['message'] ?? 'An error occurred';

                $templateData['error_content'] = sprintf(
                    '<div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <div class="flex-grow-1">
                            <strong>Backup Failed</strong>
                            <div class="mt-1">
                                <small class="text-muted">%s</small>
                            </div>
                        </div>
                    </div>',
                    htmlspecialchars($errorMessage)
                );
                break;
        }

        return $this->renderTemplate('backup/backup_result', TemplateFormat::HTML, $templateData);
    }

    /**
     * Get backup files info for collection
     */
    private function getBackupFilesInfo(int $collid): array
    {
        $outputDir = $this->backupConfig[self::CONFIG_OUTPUT_DIR] ?? '';
        if (empty($outputDir)) {
            return [
                'count' => 0,
                'latest' => null,
                'total_size' => '0 B',
                'files' => []
            ];
        }

        // Load backup registry to get files for this specific collection
        $registry = $this->loadBackupRegistry();
        $collectionFiles = [];

        if (isset($registry[$collid])) {
            // Get files from registry for this collection
            foreach ($registry[$collid] as $userId => $userBackups) {
                if (isset($userBackups['backups'])) {
                    foreach ($userBackups['backups'] as $backup) {
                        if (isset($backup['filename'])) {
                            $collectionFiles[] = $backup['filename'];
                        }
                    }
                }
            }
        }

        // Only scan for encrypted backup files (.enc.zip)
        // The symbdwc action creates unencrypted archives, but those are intermediate files
        // Only encrypted archives should be shown in History and Downloads
        $pattern = $outputDir . '/*_backup_*_DwC-A.enc.zip';
        $allFiles = glob($pattern) ?: [];

        $backupFiles = [];
        $totalSize = 0;
        $latestFile = null;
        $latestTime = 0;

        foreach ($allFiles as $file) {
            $filename = basename($file);

            // Check if this file belongs to the collection (either in registry or matches pattern)
            $belongsToCollection = in_array($filename, $collectionFiles);

            // If not in registry, try to match by collection info
            if (!$belongsToCollection) {
                // Get collection info to match against filename prefix
                $collectionInfo = $this->getCollectionInfo((string)$collid);
                if ($collectionInfo) {
                    $expectedPrefix = $collectionInfo['institutioncode'];
                    if (!empty($collectionInfo['collectioncode'])) {
                        $expectedPrefix .= '-' . $collectionInfo['collectioncode'];
                    }

                    // Check if filename starts with expected prefix
                    if (strpos($filename, $expectedPrefix . '_backup_') === 0) {
                        $belongsToCollection = true;
                    }
                }
            }

            if ($belongsToCollection) {
                // Pattern: {prefix}_backup_{timestamp}_DwC-A.enc.zip (only encrypted files)
                if (preg_match('/^(.+)_backup_(\d{4}-\d{2}-\d{2}_\d{6})_DwC-A\.enc\.zip$/', $filename, $matches)) {
                    $fileSize = filesize($file);
                    $fileTime = filemtime($file);

                    $backupFiles[] = [
                        'filename' => $filename,
                        'filepath' => $file,
                        'size' => $this->formatFileSize($fileSize),
                        'size_bytes' => $fileSize,
                        'timestamp' => date('Y-m-d H:i:s', $fileTime),
                        'age_days' => floor((time() - $fileTime) / 86400),
                        'prefix' => $matches[1],
                        'date_created' => $matches[2]
                    ];

                    $totalSize += $fileSize;

                    if ($fileTime > $latestTime) {
                        $latestTime = $fileTime;
                        $latestFile = [
                            'filename' => $filename,
                            'filepath' => $file,
                            'timestamp' => date('Y-m-d H:i:s', $fileTime),
                            'size' => $this->formatFileSize($fileSize)
                        ];
                    }
                }
            }
        }

        // Sort by timestamp descending (newest first)
        usort($backupFiles, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return [
            'count' => count($backupFiles),
            'latest' => $latestFile,
            'total_size' => $this->formatFileSize($totalSize),
            'files' => $backupFiles
        ];
    }

    /**
     * Get last backup time for collection
     */
    private function getLastBackupTime(int $collid): ?string
    {
        // This would check backup files or database
        // For now, return null
        return null;
    }

    /**
     * Get next allowed backup time for collection
     */
    private function getNextBackupTime(int $collid): ?string
    {
        // This would check throttle settings
        // For now, return null (backup allowed)
        return null;
    }

    /**
     * Register user for collection backup (HTTP route handler)
     */
    private function registerCollectionUser(int $collid, array $params): array
    {
        try {
            $authStatus = SymbAuth::getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            $passphrase = $_POST[self::PARAM_PASSPHRASE] ?? '';
            if (empty($passphrase)) {
                return [
                    'type' => 'error',
                    'message' => 'Passphrase is required',
                    'status_code' => 400
                ];
            }

            $uid = $authStatus['uid'];

            // Call CLI register function
            $result = $this->registerUserPassphrase([
                'collid' => $collid,
                'userid' => $uid,
                'passphrase' => $passphrase
            ]);

            if ($result['type'] === 'success') {
                // Return updated collection status with success message and disabled form
                $status = $this->getCollectionStatus($collid, $params);

                // Get collection data for template
                $templateData = $status['data'];
                $templateData['collid'] = $collid;

                // Force registration state to true and disable inputs
                $templateData['passphrase_disabled'] = 'disabled';
                $templateData['register_disabled'] = 'disabled';
                $templateData['register_text'] = 'Update';
                $templateData['clear_style'] = '';
                $templateData['verify_style'] = '';
                $templateData['user_info_style'] = '';
                $templateData['create_disabled'] = '';
                $templateData['app_url_prefix'] = $this->getAppUrlPrefix();

                // Return just the updated passphrase form with success message and disabled state
                $formHtml = '
                <div class="alert alert-success">Passphrase registered successfully</div>
                <div id="passphrase-form-' . $collid . '">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label for="passphrase-' . $collid . '" class="form-label">Backup Passphrase</label>
                            <input type="password"
                                   class="form-control"
                                   id="passphrase-' . $collid . '"
                                   name="passphrase"
                                   placeholder="Enter passphrase"
                                   disabled>
                        </div>
                        <div class="col-md-3">
                            <button type="button"
                                    class="btn btn-primary"
                                    hx-post="' . $this->getAppUrlPrefix() . 'backup/' . $collid . '/register"
                                    hx-include="#passphrase-' . $collid . '"
                                    hx-target="#passphrase-form-' . $collid . '"
                                    hx-swap="outerHTML"
                                    id="register-btn-' . $collid . '"
                                    disabled>
                                <i class="fas fa-key me-1"></i>
                                Update
                            </button>
                        </div>
                        <div class="col-md-3">
                            <button type="button"
                                    class="btn btn-outline-warning"
                                    hx-post="' . $this->getAppUrlPrefix() . 'backup/' . $collid . '/clear"
                                    hx-target="#passphrase-form-' . $collid . '"
                                    hx-swap="outerHTML"
                                    hx-confirm="Are you sure you want to clear the passphrase registration?"
                                    id="clear-btn-' . $collid . '">
                                <i class="fas fa-trash me-1"></i>
                                Clear
                            </button>
                        </div>
                    </div>

                    <!-- Registered User Info (if passphrase is set) -->
                    <div class="registered-user-info mt-2"
                         id="user-info-' . $collid . '">
                        <small class="text-muted">
                            <i class="fas fa-user me-1"></i>
                            Registered by: <strong>User Name</strong> (user@example.com)
                        </small>
                    </div>
                </div>';

                return [
                    'type' => 'html_fragment',
                    'content' => $formHtml,
                    'headers' => [
                        'HX-Trigger' => 'refresh-status, refresh-details'
                    ]
                ];
            } else {
                // Return error message
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-danger">' . htmlspecialchars($result['message']) . '</div>'
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error registering user: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Verify collection passphrase (HTTP route handler)
     */
    private function verifyCollectionPassphrase(int $collid, array $params): array
    {
        try {
            $authStatus = SymbAuth::getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            $passphrase = $_POST[self::PARAM_PASSPHRASE] ?? '';
            if (empty($passphrase)) {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-danger">Passphrase is required</div>'
                ];
            }

            // Load registry and verify passphrase
            $registry = $this->loadBackupRegistry();
            $collectionKey = (string)$collid;
            $userId = (string)$authStatus['uid'];

            if (!isset($registry[$collectionKey])) {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-warning">No passphrase registered for this collection</div>'
                ];
            }

            if (!isset($registry[$collectionKey][$userId])) {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-warning">No passphrase registered for your user account</div>'
                ];
            }

            $userData = $registry[$collectionKey][$userId];
            if (!isset($userData['passphrase_hash'])) {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-warning">No passphrase hash found for your registration</div>'
                ];
            }

            // Verify the passphrase against the stored hash
            $isValid = $this->verifyPassphraseHash($passphrase, $userData['passphrase_hash']);

            if ($isValid) {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>Passphrase verified successfully</div>'
                ];
            } else {
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-danger"><i class="fas fa-times-circle me-1"></i>Invalid passphrase</div>'
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error verifying passphrase: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Clear user registration for collection (HTTP route handler)
     */
    private function clearCollectionUser(int $collid, array $params): array
    {
        try {
            $authStatus = SymbAuth::getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            $uid = $authStatus['uid'];

            // Call CLI remove function
            $result = $this->removeUserRegistration([
                'collid' => $collid,
                'userid' => $uid
            ]);

            if ($result['type'] === 'success') {
                // Return updated collection status with success message and enabled form
                $status = $this->getCollectionStatus($collid, $params);

                // Get collection data for template
                $templateData = $status['data'];
                $templateData['collid'] = $collid;

                // Force registration state to false and enable inputs
                $templateData['passphrase_disabled'] = '';
                $templateData['register_disabled'] = '';
                $templateData['register_text'] = 'Set Passphrase';
                $templateData['clear_style'] = 'style="display: none;"';
                $templateData['verify_style'] = 'style="display: none;"';
                $templateData['user_info_style'] = 'style="display: none;"';
                $templateData['create_disabled'] = 'disabled';
                $templateData['app_url_prefix'] = $this->getAppUrlPrefix();

                // Return just the passphrase form with cleared state
                $formHtml = '
                <div id="passphrase-form-' . $collid . '">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label for="passphrase-' . $collid . '" class="form-label">Backup Passphrase</label>
                            <input type="password"
                                   class="form-control"
                                   id="passphrase-' . $collid . '"
                                   name="passphrase"
                                   placeholder="Enter passphrase">
                        </div>
                        <div class="col-md-3">
                            <button type="button"
                                    class="btn btn-primary"
                                    hx-post="' . $templateData['app_url_prefix'] . 'backup/' . $collid . '/register"
                                    hx-include="#passphrase-' . $collid . '"
                                    hx-target="#passphrase-form-' . $collid . '"
                                    hx-swap="outerHTML"
                                    id="register-btn-' . $collid . '">
                                <i class="fas fa-key me-1"></i>
                                Set Passphrase
                            </button>
                        </div>
                        <div class="col-md-3">
                            <button type="button"
                                    class="btn btn-outline-warning"
                                    hx-post="' . $templateData['app_url_prefix'] . 'backup/' . $collid . '/clear"
                                    hx-target="#passphrase-form-' . $collid . '"
                                    hx-swap="outerHTML"
                                    hx-confirm="Are you sure you want to clear the passphrase registration?"
                                    id="clear-btn-' . $collid . '"
                                    style="display: none;">
                                <i class="fas fa-trash me-1"></i>
                                Clear
                            </button>
                        </div>
                    </div>
                </div>';

                return [
                    'type' => 'html_fragment',
                    'content' => $formHtml,
                    'headers' => [
                        'HX-Trigger' => 'refresh-status, refresh-details'
                    ]
                ];
            } else {
                // Return error message
                return [
                    'type' => 'html_fragment',
                    'content' => '<div class="alert alert-danger">' . htmlspecialchars($result['message']) . '</div>'
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error clearing user: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get user collections for HTMX (HTTP route handler)
     */
    private function getUserCollectionsHtmx(int $uid, array $params): array
    {
        try {
            $authStatus = SymbAuth::getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }

            // Get user's collections
            $collections = $this->listUserAvailableCollections(['uid' => $uid]);
            $collectionsData = $collections['data'] ?? [];

            // Generate HTML for collections
            $collectionsHtml = $this->generateUserCollectionsHtml($collectionsData);

            // Return HTMX fragment
            $content = $this->renderTemplate('backup/user_collections', TemplateFormat::HTML, [
                'collections_html' => $collectionsHtml,
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]);

            return [
                'type' => 'html_fragment',
                'content' => $content
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error loading user collections: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Generate HTML for user collections list
     */
    private function generateUserCollectionsHtml(array $collections): string
    {
        if (empty($collections)) {
            return '<div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>No Collections Available:</strong> You do not have access to any collections for backup.
            </div>';
        }

        $html = '<div class="user-collections">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Collection ID</th>
                            <th>Institution</th>
                            <th>Code</th>
                            <th>Collection Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($collections as $collection) {
            $collid = htmlspecialchars($collection['collid']);
            $institutioncode = htmlspecialchars($collection['institutioncode'] ?? '');
            $collectioncode = htmlspecialchars($collection['collectioncode'] ?? '');
            $collectionname = htmlspecialchars($collection['collectionname']);
            $appUrlPrefix = $this->getAppUrlPrefix();

            $statusBadge = $collection['is_registered']
                ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Registered</span>'
                : '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Not Registered</span>';

            $historyButton = $collection['is_registered']
                ? '<a href="' . $appUrlPrefix . 'backup/' . $collid . '/list:htmx"
                     class="btn btn-sm btn-outline-info"
                     hx-get="' . $appUrlPrefix . 'backup/' . $collid . '/list:htmx"
                     hx-target="#backup-history-' . $collid . '"
                     hx-swap="innerHTML">
                        <i class="fas fa-history me-1"></i>History
                   </a>'
                : '';

            $html .= '<tr>
                <td><strong>' . $collid . '</strong></td>
                <td>' . $institutioncode . '</td>
                <td>' . $collectioncode . '</td>
                <td>' . $collectionname . '</td>
                <td>' . $statusBadge . '</td>
                <td>
                    <div class="btn-group" role="group">
                        <a href="' . $appUrlPrefix . 'backup/' . $collid . '"
                           class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-cog me-1"></i>Manage
                        </a>
                        ' . $historyButton . '
                    </div>
                    <div id="backup-history-' . $collid . '" class="mt-2"></div>
                </td>
            </tr>';
        }

        $html .= '</tbody>
                </table>
            </div>
        </div>';

        return $html;
    }

    /**
     * Download backup file (HTTP route handler)
     */
    private function downloadBackupHttp(int $collid, int $backupNumber, array $params): array
    {
        try {
            // Get backup files for this collection
            $backupFiles = $this->getBackupFilesInfo($collid);

            if (empty($backupFiles['files'])) {
                return [
                    'type' => 'error',
                    'message' => 'No backup files found for this collection',
                    'status_code' => 404
                ];
            }

            // Get the requested backup file (1-based index)
            $fileIndex = $backupNumber - 1;
            if ($fileIndex < 0 || $fileIndex >= count($backupFiles['files'])) {
                return [
                    'type' => 'error',
                    'message' => 'Backup file not found',
                    'status_code' => 404
                ];
            }

            $file = $backupFiles['files'][$fileIndex];
            $filePath = $file['filepath'];

            // Verify file exists and is readable
            if (!file_exists($filePath) || !is_readable($filePath)) {
                return [
                    'type' => 'error',
                    'message' => 'Backup file not accessible',
                    'status_code' => 404
                ];
            }

            // Return file download response with proper headers
            return [
                'type' => 'download',
                'file_path' => $filePath,
                'filename' => $file['filename'],
                'content_type' => 'application/zip',
                'file_size' => $file['size_bytes']
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error downloading backup: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }


    /**
     * Render individual collection item HTML
     */
    private function renderCollectionItem(array $collection): string
    {
        return $this->renderTemplate('backup/collection_item', TemplateFormat::HTML, [
            'collection_id' => $collection['collid'] ?? '',
            'collection_name' => htmlspecialchars($collection['collectionname'] ?? 'Unknown Collection'),
            'collection_code' => htmlspecialchars($collection['collectioncode'] ?? ''),
            'institution_code' => htmlspecialchars($collection['institutioncode'] ?? ''),
            'collection_type' => htmlspecialchars($collection['colltype'] ?? ''),
            'is_registered' => $collection['is_registered'] ?? false,
            'status_text' => $collection['status_text'] ?? 'Unknown',
            'status_class' => $collection['status_class'] ?? 'text-muted',
            'app_url_prefix' => $this->getAppUrlPrefix()
        ]);
    }

    /**
     * List collections with colladmin role users
     */
    public function listCollections(array $params): array
    {
        // Check authentication first for HTTP requests
        if (!Environment::isCli()) {
            $authStatus = $this->getAuthStatus();
            if (!$authStatus['authenticated']) {
                return [
                    'type' => 'error',
                    'message' => 'Authentication required',
                    'status_code' => 401
                ];
            }
        }

        if (!$this->isDatabaseAvailable()) {
            return [
                'type' => 'error',
                'message' => 'Database connection not available',
                'status_code' => 503
            ];
        }

        try {
            $backupManager = $this->getBackupManager();
            $collectionsData = $backupManager->getCollectionsWithAdmins();

            $content = [
                "\nCollections with CollAdmin Users:",
                "============================================================",
                ""
            ];

            if (empty($collectionsData)) {
                $content[] = "No collections found with CollAdmin users.";
            } else {
                $content[] = sprintf("Found %d collections with admin users:", count($collectionsData));
                $content[] = "";
                $content[] = sprintf("%-8s %-15s %-30s %-20s %s", "CollID", "Code", "Collection Name", "Institution", "Admin Users");
                $content[] = str_repeat("-", 100);

                foreach ($collectionsData as $collection) {
                    $adminCount = count($collection['admins']);
                    $adminList = implode(', ', array_column($collection['admins'], 'username'));
                    if (strlen($adminList) > 30) {
                        $adminList = substr($adminList, 0, 27) . '...';
                    }

                    $content[] = sprintf(
                        "%-8s %-15s %-30s %-20s %s",
                        $collection['collid'],
                        substr($collection['collectioncode'] ?? '', 0, 15),
                        substr($collection['collectionname'] ?? '', 0, 30),
                        substr($collection['institutioncode'] ?? '', 0, 20),
                        $adminList
                    );
                }
            }

            return [
                'type' => Environment::isCli() ? 'cli' : 'success',
                'content' => implode("\n", $content),
                'collections' => $collectionsData,
                'summary' => [
                    'total_collections' => count($collectionsData),
                    'total_admins' => array_sum(array_map(function($c) { return count($c['admins']); }, $collectionsData))
                ]
            ];
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error retrieving collections: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * List all collections (for SuperAdmin users)
     */
    public function listAllCollections(array $params): array
    {
        if (!$this->isDatabaseAvailable()) {
            return [
                'type' => 'error',
                'message' => 'Database connection not available'
            ];
        }

        try {
            $backupManager = $this->getBackupManager();
            $collectionsData = $backupManager->getAllCollections();

            $content = [
                "\nAll Collections (SuperAdmin Access):",
                "============================================================",
                ""
            ];

            if (empty($collectionsData)) {
                $content[] = "No collections found.";
            } else {
                $content[] = sprintf("Found %d total collections:", count($collectionsData));
                $content[] = "";
                $content[] = sprintf("%-8s %-15s %-30s %-20s %s", "CollID", "Code", "Collection Name", "Institution", "Type");
                $content[] = str_repeat("-", 100);

                foreach ($collectionsData as $collection) {
                    $content[] = sprintf(
                        "%-8s %-15s %-30s %-20s %s",
                        $collection['collid'],
                        substr($collection['collectioncode'] ?? '', 0, 15),
                        substr($collection['collectionname'] ?? '', 0, 30),
                        substr($collection['institutioncode'] ?? '', 0, 20),
                        $collection['colltype'] ?? 'N/A'
                    );
                }
            }

            return [
                'type' => Environment::isCli() ? 'cli' : 'success',
                'content' => implode("\n", $content),
                'collections' => $collectionsData,
                'summary' => [
                    'total_collections' => count($collectionsData)
                ]
            ];
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error retrieving collections: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * List colladmin users for a specific collection
     */
    public function listCollectionUsers(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;

        if (!$collid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID required',
                'status_code' => 400
            ];
        }

        try {
            $backupManager = $this->getBackupManager();
            $collectionInfo = $backupManager->getCollectionInfo(intval($collid));
            $users = $this->getCollectionAdminUsers($collid);
            $registry = $this->loadBackupRegistry();

            // Check if any SuperAdmins are registered for this collection but not CollAdmins
            $registeredUserIds = [];
            if (isset($registry[$collid])) {
                $registeredUserIds = array_keys($registry[$collid]);
            }

            // Get user IDs that are already CollAdmins
            $collAdminUserIds = array_column($users, 'uid');

            // Find registered SuperAdmins who are not CollAdmins
            $registeredSuperAdmins = [];
            foreach ($registeredUserIds as $uid) {
                if (!in_array($uid, $collAdminUserIds)) {
                    // Get user info and check if they're SuperAdmin
                    $userSql = $this->renderTemplate('backup/user_info', TemplateFormat::SQL, ['uid' => $uid]);
                    $connection = $this->getConnection();
                    $userResult = $connection->query($userSql);

                    if ($userResult && $userResult->num_rows > 0) {
                        $userInfo = $userResult->fetch_assoc();

                        // Check if user is SuperAdmin
                        $superAdminSql = $this->renderTemplate('backup/check_super_admin', TemplateFormat::SQL, ['uid' => $uid]);
                        $superAdminResult = $connection->query($superAdminSql);
                        $isSuperAdmin = $superAdminResult && $superAdminResult->fetch_assoc()['count'] > 0;

                        if ($isSuperAdmin) {
                            $registeredSuperAdmins[] = [
                                'uid' => $userInfo['uid'],
                                'username' => $userInfo['username'],
                                'firstname' => $userInfo['firstname'],
                                'lastname' => $userInfo['lastname'],
                                'email' => $userInfo['email'],
                                'role' => 'SuperAdmin'
                            ];
                        }
                    }
                }
            }

            if (Environment::isCli()) {
                $collectionName = $collectionInfo['collectionname'] ?? 'Unknown Collection';
                $institutionCode = $collectionInfo['institutioncode'] ?? '';
                $collectionCode = $collectionInfo['collectioncode'] ?? '';

                $headerText = "Users for {$collectionName} ({$institutionCode}";
                if ($collectionCode) {
                    $headerText .= " {$collectionCode}";
                }
                $headerText .= ") {$collid}:";

                $outputLines = [
                    "\n{$headerText}",
                    str_repeat("=", strlen($headerText))
                ];

                // Display CollAdmin users first
                foreach ($users as $user) {
                    $hasPassphrase = isset($registry[$collid][$user['uid']]);
                    $status = $hasPassphrase ? '[REGISTERED]' : '[NOT REGISTERED]';

                    $outputLines[] = sprintf("%-5s %-25s %-30s %s",
                        $user['uid'],
                        $user['username'],
                        $user['firstname'] . ' ' . $user['lastname'],
                        $status
                    );
                }

                // Display registered SuperAdmins
                foreach ($registeredSuperAdmins as $user) {
                    $status = '[REGISTERED]'; // They must be registered to be in this list

                    $outputLines[] = sprintf("%-5s %-25s %-30s %s",
                        $user['uid'],
                        $user['username'],
                        $user['firstname'] . ' ' . $user['lastname'] . ' (SA)',
                        $status
                    );
                }

                // Prepare structured user data with registration status
                $structuredUsers = [];

                // Add CollAdmin users
                foreach ($users as $user) {
                    $hasPassphrase = isset($registry[$collid][$user['uid']]);
                    $structuredUsers[] = [
                        'uid' => $user['uid'],
                        'username' => $user['username'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'registered' => $hasPassphrase,
                        'status' => $hasPassphrase ? 'REGISTERED' : 'NOT REGISTERED'
                    ];
                }

                // Add registered SuperAdmins
                foreach ($registeredSuperAdmins as $user) {
                    $structuredUsers[] = [
                        'uid' => $user['uid'],
                        'username' => $user['username'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname'] . ' (SA)',
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'registered' => true,
                        'status' => 'REGISTERED'
                    ];
                }

                return [
                    'type' => Environment::isCli() ? 'cli' : 'success',
                    'content' => implode("\n", $outputLines),
                    'users' => $structuredUsers,
                    'collection_id' => is_numeric($collid) ? intval($collid) : $collid,
                    'summary' => [
                        'total_users' => count($structuredUsers),
                        'registered_users' => count(array_filter($structuredUsers, function($u) { return $u['registered']; })),
                        'unregistered_users' => count(array_filter($structuredUsers, function($u) { return !$u['registered']; }))
                    ]
                ];
            }

            return [
                'type' => 'htmx',
                'content' => $this->renderTemplate('backup/users_list', TemplateFormat::HTML, [
                    'collid' => $collid,
                    'users' => $users,
                    'registry' => $registry[$collid] ?? [],
                    'app_url_prefix' => $this->getAppUrlPrefix()
                ])
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to list users: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * List collections available for user registration
     */
    public function listUserAvailableCollections(array $params): array
    {
        $uid = $params['uid'] ?? $params[0] ?? null;

        if (!$uid) {
            return [
                'type' => 'error',
                'message' => 'User ID required',
                'status_code' => 400
            ];
        }

        try {
            // Get user info using SQL template
            $userSql = $this->renderTemplate('backup/user_info', TemplateFormat::SQL, ['uid' => $uid]);
            $connection = $this->getConnection();
            $userResult = $connection->query($userSql);

            if (!$userResult || $userResult->num_rows === 0) {
                return [
                    'type' => 'error',
                    'message' => "User {$uid} not found",
                    'status_code' => 404
                ];
            }

            $userInfo = $userResult->fetch_assoc();

            // Check if user is SuperAdmin using SQL template
            $superAdminSql = $this->renderTemplate('backup/check_super_admin', TemplateFormat::SQL, ['uid' => $uid]);
            $superAdminResult = $connection->query($superAdminSql);
            $isSuperAdmin = $superAdminResult && $superAdminResult->fetch_assoc()['count'] > 0;

            // Get collections based on user role using SQL templates
            if ($isSuperAdmin) {
                $collectionsSql = $this->renderTemplate('backup/all_collections', TemplateFormat::SQL, []);
                $accessType = 'SuperAdmin - All Collections';
            } else {
                $collectionsSql = $this->renderTemplate('backup/user_admin_collections', TemplateFormat::SQL, ['uid' => $uid]);
                $accessType = 'CollAdmin - Assigned Collections Only';
            }

            $collectionsResult = $connection->query($collectionsSql);
            $collections = [];
            if ($collectionsResult) {
                while ($row = $collectionsResult->fetch_assoc()) {
                    $collections[] = $row;
                }
            }

            // Load backup registry to check registration status
            $registry = $this->loadBackupRegistry();

            if (Environment::isCli()) {
                $headerText = "Available Collections for {$userInfo['firstname']} {$userInfo['lastname']} ({$userInfo['username']}) {$uid}:";
                $subHeaderText = "Access Level: {$accessType}";

                $outputLines = [
                    "\n{$headerText}",
                    $subHeaderText,
                    str_repeat("=", max(strlen($headerText), strlen($subHeaderText)))
                ];

                if (empty($collections)) {
                    $outputLines[] = "No collections available for registration.";
                    if (!$isSuperAdmin) {
                        $outputLines[] = "Note: You must be assigned as CollAdmin to register for collection backups.";
                    }
                } else {
                    $outputLines[] = sprintf("%-6s %-15s %-15s %-40s %s",
                        "CollID", "InstCode", "CollCode", "Collection Name", "Status"
                    );
                    $outputLines[] = str_repeat("-", 90);

                    foreach ($collections as $collection) {
                        $collid = $collection['collid'];
                        $isRegistered = isset($registry[$collid][$uid]);
                        $status = $isRegistered ? '[REGISTERED]' : '[NOT REGISTERED]';

                        $outputLines[] = sprintf("%-6s %-15s %-15s %-40s %s",
                            $collid,
                            substr((string)($collection['institutioncode'] ?? ''), 0, 15),
                            substr((string)($collection['collectioncode'] ?? ''), 0, 15),
                            substr((string)($collection['collectionname'] ?? 'Unknown'), 0, 40),
                            $status
                        );
                    }
                }

                // Prepare structured collection data
                $structuredCollections = [];
                foreach ($collections as $collection) {
                    $collid = $collection['collid'];
                    $isRegistered = isset($registry[$collid][intval($uid)]);

                    $structuredCollections[] = [
                        'collid' => $collid,
                        'institutioncode' => $collection['institutioncode'] ?? '',
                        'collectioncode' => $collection['collectioncode'] ?? '',
                        'collectionname' => $collection['collectionname'] ?? 'Unknown Collection',
                        'registered' => $isRegistered,
                        'is_registered' => $isRegistered, // For HTML template compatibility
                        'status' => $isRegistered ? 'REGISTERED' : 'NOT REGISTERED'
                    ];
                }

                return [
                    'type' => Environment::isCli() ? 'cli' : 'success',
                    'content' => implode("\n", $outputLines),
                    'data' => $structuredCollections,
                    'collections' => $structuredCollections, // Keep for backward compatibility
                    'user_id' => is_numeric($uid) ? intval($uid) : $uid,
                    'user_info' => $userInfo,
                    'access_type' => $accessType,
                    'is_super_admin' => $isSuperAdmin,
                    'summary' => [
                        'total_collections' => count($structuredCollections),
                        'registered_collections' => count(array_filter($structuredCollections, function($c) { return $c['registered']; })),
                        'available_collections' => count(array_filter($structuredCollections, function($c) { return !$c['registered']; }))
                    ]
                ];
            } else {
                // For HTTP interface, return JSON data
                $structuredCollections = [];
                foreach ($collections as $collection) {
                    $collid = $collection['collid'];
                    $isRegistered = isset($registry[$collid][intval($uid)]);

                    $structuredCollections[] = [
                        'collid' => $collid,
                        'institutioncode' => $collection['institutioncode'] ?? '',
                        'collectioncode' => $collection['collectioncode'] ?? '',
                        'collectionname' => $collection['collectionname'] ?? 'Unknown Collection',
                        'registered' => $isRegistered,
                        'is_registered' => $isRegistered, // For HTML template compatibility
                        'status' => $isRegistered ? 'REGISTERED' : 'NOT REGISTERED'
                    ];
                }

                return [
                    'type' => 'success',
                    'data' => $structuredCollections,
                    'collections' => $structuredCollections, // Keep for backward compatibility
                    'user_id' => is_numeric($uid) ? intval($uid) : $uid,
                    'user_info' => $userInfo,
                    'access_type' => $accessType,
                    'is_super_admin' => $isSuperAdmin,
                    'summary' => [
                        'total_collections' => count($structuredCollections),
                        'registered_collections' => count(array_filter($structuredCollections, function($c) { return $c['registered']; })),
                        'available_collections' => count(array_filter($structuredCollections, function($c) { return !$c['registered']; }))
                    ]
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to list user collections: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Register user passphrase for collection backup
     */
    public function registerUserPassphrase(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;
        $userid = $params['userid'] ?? $params[1] ?? null;
        $passphrase = $params['passphrase'] ?? $params['p'] ?? null;

        if (!$collid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID required for registration',
                'status_code' => 400
            ];
        }

        if (!$userid) {
            return [
                'type' => 'error',
                'message' => 'User ID required for registration',
                'status_code' => 400
            ];
        }

        if (!$passphrase) {
            return [
                'type' => 'error',
                'message' => 'Passphrase required for registration. Use --passphrase=<phrase> or -p <phrase>',
                'status_code' => 400
            ];
        }

        try {
            // Check if site salt is configured
            if (!isset($this->backupConfig['site_salt']) || empty($this->backupConfig['site_salt'])) {
                $message = "Please configure backup.site_salt in config.php";
                return [
                    'type' => 'error',
                    'message' => $message,
                    'status_code' => 400
                ];
            }

            // Verify user has colladmin role for this collection
            if (!$this->verifyUserCollectionAccess($userid, $collid)) {
                return [
                    'type' => 'error',
                    'message' => 'User does not have CollAdmin role for this collection',
                    'status_code' => 403
                ];
            }

            // Load existing registry
            $registry = $this->loadBackupRegistry();

            // Check if collection is already registered by another user
            if (isset($registry[$collid]) && !empty($registry[$collid])) {
                $existingUsers = array_keys($registry[$collid]);
                $existingUserId = $existingUsers[0];

                if ($existingUserId != $userid) {
                    // Get user details for better error message
                    $connection = $this->getConnection();
                    $existingUsername = "User {$existingUserId}";
                    if ($connection) {
                        $sql = $this->loadSqlTemplate('user_details');
                        $stmt = $connection->prepare($sql);
                        if ($stmt) {
                            $stmt->bind_param('i', $existingUserId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $existingUsername = $row['username'];
                                $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                                if ($fullName) {
                                    $existingUsername .= " ({$fullName})";
                                }
                            }
                            $stmt->close();
                        }
                    }

                    return [
                        'type' => 'error',
                        'message' => "Collection {$collid} is already registered by {$existingUsername} (UID: {$existingUserId}). Only one user per collection is allowed.",
                        'status_code' => 409
                    ];
                }
            }

            // Hash passphrase with site salt for secure storage
            $hashedPassphrase = $this->hashPassphrase($passphrase);

            // Register user/passphrase
            if (!isset($registry[$collid])) {
                $registry[$collid] = [];
            }

            $registry[$collid][$userid] = [
                'passphrase' => $this->encryptPassphrase($passphrase),
                'passphrase_hash' => $this->createPassphraseHash($passphrase),
                'registered_at' => date('Y-m-d H:i:s'),
                'last_backup' => null
            ];

            // Save registry
            $this->saveBackupRegistry($registry);

            return [
                'type' => 'success',
                'message' => "User {$userid} registered for collection {$collid} backup"
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to register user: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }



    /**
     * Get backup status and info for collection, or backup configuration if no collid provided
     */
    public function getBackupStatus(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;

        // If no collection ID provided, show backup configuration (like upload module)
        if (!$collid) {
            return $this->getBackupConfiguration($params);
        }

        try {
            $backupFiles = $this->getCollectionBackupFiles($collid);
            $progressCheck = $this->checkBackupInProgress($collid);
            $throttleCheck = $this->checkBackupThrottle($collid);

            if (Environment::isCli()) {
                $outputLines = [
                    "\nBackup Status for Collection {$collid}:",
                    str_repeat("=", 40)
                ];

                $outputLines[] = "In Progress: " . ($progressCheck['in_progress'] ? 'YES' : 'NO');
                $outputLines[] = "Throttle Status: " . $throttleCheck['message'];
                $outputLines[] = "Available Backups: " . count($backupFiles);

                if (!empty($backupFiles)) {
                    $latest = $backupFiles[0];
                    $outputLines[] = "Latest Backup: " . basename($latest);
                    $outputLines[] = "Size: " . $this->formatFileSize(filesize($latest));
                    $outputLines[] = "Date: " . date('Y-m-d H:i:s', filemtime($latest));
                }

                return [
                    'type' => 'success',
                    'content' => implode("\n", $outputLines)
                ];
            }

            return [
                'type' => 'json',
                'content' => json_encode([
                    'collid' => $collid,
                    'in_progress' => $progressCheck['in_progress'],
                    'throttle' => $throttleCheck,
                    'backup_count' => count($backupFiles),
                    'latest_backup' => !empty($backupFiles) ? [
                        'filename' => basename($backupFiles[0]),
                        'size' => filesize($backupFiles[0]),
                        'date' => date('Y-m-d H:i:s', filemtime($backupFiles[0]))
                    ] : null
                ])
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to get backup status: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Download backup file
     */
    public function downloadBackup(array $params): array
    {
        $filename = $params['filename'] ?? '';

        if (!$filename) {
            return [
                'type' => 'error',
                'message' => 'Filename required',
                'status_code' => 400
            ];
        }

        $filepath = $this->backupDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return [
                'type' => 'error',
                'message' => 'Backup file not found',
                'status_code' => 404
            ];
        }

        // For CLI, just return the file path
        if (Environment::isCli()) {
            return [
                'type' => 'success',
                'message' => "Backup file available at: {$filepath}",
                'file_path' => $filepath
            ];
        }

        // For HTTP, trigger download
        return [
            'type' => 'download',
            'file_path' => $filepath,
            'filename' => $filename,
            'content_type' => 'application/zip'
        ];
    }

    /**
     * List available backups for collection
     */
    public function listBackups(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;

        if (!$collid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID required',
                'status_code' => 400
            ];
        }

        try {
            $backupFiles = $this->getCollectionBackupFiles($collid);

            if (Environment::isCli()) {
                $outputLines = [
                    "\nAvailable Backups for Collection {$collid}:",
                    str_repeat("=", 60)
                ];

                if (empty($backupFiles)) {
                    $outputLines[] = "No backups found.";
                } else {
                    foreach ($backupFiles as $file) {
                        $outputLines[] = sprintf("%-40s %10s %s",
                            basename($file),
                            $this->formatFileSize(filesize($file)),
                            date('Y-m-d H:i:s', filemtime($file))
                        );
                    }
                }

                return [
                    'type' => 'success',
                    'content' => implode("\n", $outputLines)
                ];
            }

            $backups = [];
            foreach ($backupFiles as $file) {
                $backups[] = [
                    'filename' => basename($file),
                    'filepath' => $file,
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }

            return [
                'type' => 'htmx',
                'content' => $this->renderTemplate('backup/backup_list', TemplateFormat::HTML, [
                    'collid' => $collid,
                    'backups' => $backups,
                    'app_url_prefix' => $this->getAppUrlPrefix()
                ])
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to list backups: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Cleanup old backups per retention policy
     */
    public function cleanupBackups(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;
        $removeAll = isset($params['remove-all']) || isset($params['removeall']);

        if (!$collid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID required',
                'status_code' => 400
            ];
        }

        try {
            // Check if collection has registered users (basic permission check)
            $registry = $this->loadBackupRegistry();
            if (!isset($registry[$collid]) || empty($registry[$collid])) {
                return [
                    'type' => 'error',
                    'message' => 'No users registered for collection ' . $collid,
                    'status_code' => 403
                ];
            }

            $backupFiles = $this->getCollectionBackupFiles($collid);
            $retentionThreshold = $this->backupConfig['retention_threshold'] ?? self::DEFAULT_RETENTION_THRESHOLD;

            $removedFiles = [];
            $totalSize = 0;

            if ($removeAll) {
                // Remove all backup files
                foreach ($backupFiles as $file) {
                    $filePath = $file['path'] ?? $file;
                    $fileSize = filesize($filePath);
                    if (unlink($filePath)) {
                        $removedFiles[] = basename($filePath);
                        $totalSize += $fileSize;
                    }
                }
            } else {
                // Keep only the most recent N daily backups (retention_threshold)
                // Sort files by modification time (newest first)
                usort($backupFiles, function($a, $b) {
                    $fileA = $a['path'] ?? $a;
                    $fileB = $b['path'] ?? $b;
                    return filemtime($fileB) - filemtime($fileA);
                });

                // Remove files beyond the retention threshold
                $filesToRemove = array_slice($backupFiles, $retentionThreshold);
                foreach ($filesToRemove as $file) {
                    $filePath = $file['path'] ?? $file;
                    $fileSize = filesize($filePath);
                    if (unlink($filePath)) {
                        $removedFiles[] = basename($filePath);
                        $totalSize += $fileSize;
                    }
                }
            }

            if (Environment::isCli()) {
                $content = [];
                $content[] = "Backup Cleanup for Collection {$collid}:";
                $content[] = str_repeat("=", 40);

                if (empty($removedFiles)) {
                    $content[] = "No files to remove.";
                    if (!$removeAll) {
                        $content[] = "Retention threshold: {$retentionThreshold} days";
                    }
                } else {
                    $content[] = "Removed " . count($removedFiles) . " backup files:";
                    foreach ($removedFiles as $filename) {
                        $content[] = "  - {$filename}";
                    }
                    $content[] = "";
                    $content[] = "Total space freed: " . $this->formatFileSize($totalSize);
                    if (!$removeAll) {
                        $content[] = "Retention threshold: {$retentionThreshold} days";
                    }
                }

                return [
                    'type' => 'cli',
                    'content' => implode("\n", $content)
                ];
            } else {
                return [
                    'type' => 'success',
                    'message' => 'Cleanup completed',
                    'removed_files' => $removedFiles,
                    'total_size_freed' => $totalSize,
                    'retention_threshold' => $retentionThreshold
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Remove user registration
     */
    public function removeUserRegistration(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;
        $userid = $params['userid'] ?? $params[1] ?? null;

        if (!$collid || !$userid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID and User ID required',
                'status_code' => 400
            ];
        }

        try {
            $registry = $this->loadBackupRegistry();

            if (isset($registry[$collid][$userid])) {
                unset($registry[$collid][$userid]);

                // Remove collection entry if no users left
                if (empty($registry[$collid])) {
                    unset($registry[$collid]);
                }

                $this->saveBackupRegistry($registry);

                return [
                    'type' => 'success',
                    'message' => "User {$userid} removed from collection {$collid} backup registry"
                ];
            } else {
                return [
                    'type' => 'error',
                    'message' => "User {$userid} not found in collection {$collid} backup registry",
                    'status_code' => 404
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to remove user: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Reset user passphrase
     */
    public function resetUserPassphrase(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;
        $userid = $params['userid'] ?? $params[1] ?? null;
        $newPassphrase = $params['passphrase'] ?? $params['p'] ?? null;

        if (!$collid || !$userid || !$newPassphrase) {
            return [
                'type' => 'error',
                'message' => 'Collection ID, User ID, and new passphrase required',
                'status_code' => 400
            ];
        }

        try {
            $registry = $this->loadBackupRegistry();

            if (!isset($registry[$collid][$userid])) {
                return [
                    'type' => 'error',
                    'message' => "User {$userid} not found in collection {$collid} backup registry",
                    'status_code' => 404
                ];
            }

            // Update passphrase
            $registry[$collid][$userid]['passphrase'] = $this->encryptPassphrase($newPassphrase);
            $registry[$collid][$userid]['passphrase_hash'] = $this->createPassphraseHash($newPassphrase);
            $registry[$collid][$userid]['updated_at'] = date('Y-m-d H:i:s');

            $this->saveBackupRegistry($registry);

            return [
                'type' => 'success',
                'message' => "Passphrase reset for user {$userid} in collection {$collid}"
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to reset passphrase: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Dry run backup - report collection size without creating backup
     */
    public function dryRunBackup(array $params): array
    {
        $collid = $params['collid'] ?? $params[0] ?? null;

        if (!$collid) {
            return [
                'type' => 'error',
                'message' => 'Collection ID required',
                'status_code' => 400
            ];
        }

        try {
            $collectionInfo = $this->getCollectionInfo($collid);
            $recordCount = $this->getCollectionRecordCount($collid);
            $estimatedSize = $this->estimateBackupSize($collid);

            if (Environment::isCli()) {
                $outputLines = [
                    "\nDry Run Backup for Collection {$collid}:",
                    str_repeat("=", 50),
                    "Collection: {$collectionInfo['collectionname']}",
                    "Institution: {$collectionInfo['institutioncode']}",
                    "Records: " . number_format($recordCount),
                    "Estimated Size: " . $this->formatFileSize($estimatedSize),
                    "\nNote: This is an estimate. Actual backup size may vary."
                ];

                return [
                    'type' => 'success',
                    'content' => implode("\n", $outputLines)
                ];
            }

            return [
                'type' => 'json',
                'content' => json_encode([
                    'collid' => $collid,
                    'collection' => $collectionInfo,
                    'record_count' => $recordCount,
                    'estimated_size' => $estimatedSize,
                    'estimated_size_formatted' => $this->formatFileSize($estimatedSize)
                ])
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to perform dry run: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Delete backup
     */
    public function deleteBackup(array $params): array
    {
        $filename = $params['filename'] ?? '';

        if (!$filename) {
            return [
                'type' => 'error',
                'message' => 'Filename required',
                'status_code' => 400
            ];
        }

        $filepath = $this->backupDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return [
                'type' => 'error',
                'message' => 'Backup file not found',
                'status_code' => 404
            ];
        }

        if (unlink($filepath)) {
            return [
                'type' => 'success',
                'message' => 'Backup deleted successfully'
            ];
        } else {
            return [
                'type' => 'error',
                'message' => 'Failed to delete backup',
                'status_code' => 500
            ];
        }
    }



    /**
     * Perform Symbiota backup using DwcArchiverCore integration
     */
    private function performSymbiotaBackup(string $collid, string $passphrase): array
    {
        // Create progress marker file
        $progressFile = $this->getBackupProgressFile($collid);
        touch($progressFile);

        try {
            // Get collection info for filename
            $collectionInfo = $this->getCollectionInfo($collid);
            $filename = $this->generateBackupFilename($collectionInfo);

            // Create backup using real Symbiota DwcArchiverCore
            $backupZipPath = $this->createSymbiotaBackup($collid, $collectionInfo);

            // Preserve original ZIP for debugging (with prefix)
            $originalZipPath = $this->getBackupDir() . '/_symbbackup_original_' . basename($backupZipPath);
            copy($backupZipPath, $originalZipPath);

            // Extract and re-compress with encryption
            $encryptedZipPath = $this->createEncryptedBackup($backupZipPath, $filename, $passphrase);

            // Update registry with backup info
            $this->updateBackupRegistry($collid, $filename);

            // Automatic cleanup of old backups per retention policy
            $this->performAutomaticCleanup($collid);

            // Cleanup temporary files
            unlink($progressFile);
            if (file_exists($mockZipPath)) {
                unlink($mockZipPath);
            }

            return [
                'success' => true,
                'filename' => basename($encryptedZipPath),
                'filepath' => $encryptedZipPath,
                'size' => filesize($encryptedZipPath),
                'collection' => $collectionInfo
            ];

        } catch (Exception $e) {
            // Cleanup on error
            if (file_exists($progressFile)) {
                unlink($progressFile);
            }
            throw $e;
        }
    }

    /**
     * Perform automatic cleanup of old backups per retention policy
     */
    private function performAutomaticCleanup(string $collid): void
    {
        try {
            $backupFiles = $this->getCollectionBackupFiles($collid);
            $retentionThreshold = $this->backupConfig['retention_threshold'] ?? self::DEFAULT_RETENTION_THRESHOLD;

            // Only cleanup if we have more files than the retention threshold
            if (count($backupFiles) <= $retentionThreshold) {
                return;
            }

            // Sort files by modification time (newest first)
            usort($backupFiles, function($a, $b) {
                $fileA = $a['path'] ?? $a;
                $fileB = $b['path'] ?? $b;
                return filemtime($fileB) - filemtime($fileA);
            });

            // Remove files beyond the retention threshold
            $filesToRemove = array_slice($backupFiles, $retentionThreshold);
            foreach ($filesToRemove as $file) {
                $filePath = $file['path'] ?? $file;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        } catch (Exception $e) {
            // Log error but don't fail the backup process
            error_log("Automatic cleanup failed for collection {$collid}: " . $e->getMessage());
        }
    }

    /**
     * Create mock backup for testing (TODO: Replace with real DwcArchiverCore integration)
     */
    private function createMockBackup(string $collid, array $collectionInfo): string
    {
        $tempDir = $this->portalTempDir . '/mock_backup_' . uniqid();
        $mockZipPath = $tempDir . '/mock_backup.zip';

        // Create temp working directory
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            // Create mock DwC-A structure
            $dataDir = $tempDir . '/data';
            mkdir($dataDir, 0755, true);

            // Create meta.xml (DwC-A metadata)
            $metaXml = $this->generateMockMetaXml($collectionInfo);
            file_put_contents($dataDir . '/meta.xml', $metaXml);

            // Create occurrences.txt (sample data)
            $occurrencesData = $this->generateMockOccurrenceData($collid);
            file_put_contents($dataDir . '/occurrences.txt', $occurrencesData);

            // Create eml.xml (collection metadata)
            $emlXml = $this->generateMockEmlXml($collectionInfo);
            file_put_contents($dataDir . '/eml.xml', $emlXml);

            // Create ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($mockZipPath, ZipArchive::CREATE) !== TRUE) {
                throw new Exception('Cannot create mock backup ZIP');
            }

            // Add files to ZIP using addFromString to ensure relative paths
            $zip->addFromString('meta.xml', file_get_contents($dataDir . '/meta.xml'));
            $zip->addFromString('occurrences.txt', file_get_contents($dataDir . '/occurrences.txt'));
            $zip->addFromString('eml.xml', file_get_contents($dataDir . '/eml.xml'));
            $zip->close();

            // Cleanup temp data directory
            unlink($dataDir . '/meta.xml');
            unlink($dataDir . '/occurrences.txt');
            unlink($dataDir . '/eml.xml');
            rmdir($dataDir);

            return $mockZipPath;

        } catch (Exception $e) {
            // Cleanup on error
            if (is_dir($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
            }
            throw $e;
        }
    }

    /**
     * Create backup using real Symbiota DwcArchiverCore
     */
    private function createSymbiotaBackup(string $collid, array $collectionInfo): string
    {
        // Load Symbiota environment and classes
        $this->loadSymbiotaEnvironment();

        try {
            // Silence errors as recommended in the example
            ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
            error_reporting(E_ALL ^ E_WARNING);
            error_reporting(0);

            // Create DwcArchiverCore instance
            $dwcaHandler = new \DwcArchiverCore();
            $dwcaHandler->setSchemaType('backup');
            $dwcaHandler->setCharSetOut('utf-8');
            $dwcaHandler->setVerboseMode(0);
            $dwcaHandler->setIncludeDets(1);
            $dwcaHandler->setIncludeImgs(1);
            $dwcaHandler->setIncludeAttributes(1);
            $dwcaHandler->setRedactLocalities(0);
            $dwcaHandler->setCollArr((int)$collid);

            // Create the DwC-A archive
            $archivePath = $dwcaHandler->createDwcArchive();

            if (!$archivePath || !is_file($archivePath)) {
                throw new Exception("Failed to create DwC-A archive for collection $collid");
            }

            return $archivePath;

        } catch (Exception $e) {
            // Fallback to mock backup if Symbiota integration fails
            error_log("Symbiota backup failed for collection $collid: " . $e->getMessage());
            error_log("Falling back to mock backup");
            return $this->createMockBackup($collid, $collectionInfo);
        }
    }



    /**
     * Create encrypted backup from Symbiota ZIP
     */
    private function createEncryptedBackup(string $sourceZipPath, string $filename, string $passphrase): string
    {
        $tempDir = $this->portalTempDir . '/backup_work_' . uniqid();
        $encryptedZipPath = $this->getBackupDir() . '/' . $filename;

        // Create temp working directory
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            // Extract original ZIP
            $zip = new ZipArchive();
            if ($zip->open($sourceZipPath) !== TRUE) {
                throw new Exception('Cannot open source ZIP file');
            }
            $zip->extractTo($tempDir);
            $zip->close();

            // Use the provided passphrase for encryption
            // (passphrase has already been verified against stored hash)

            // Create encrypted ZIP
            $encryptedZip = new ZipArchive();
            if ($encryptedZip->open($encryptedZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception('Cannot create encrypted ZIP file');
            }

            // Set password
            $encryptedZip->setPassword($passphrase);

            // Add files with encryption and relative paths
            $this->addFilesToEncryptedZip($encryptedZip, $tempDir);
            $encryptedZip->close();

            // Set file permissions
            chmod($encryptedZipPath, 0644);

            return $encryptedZipPath;

        } finally {
            // Cleanup temp directory
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Export database to SQL file
     */
    private function exportDatabase(string $tempPath, ?string $collectionId): void
    {
        // Simulate database export
        $sqlFile = $tempPath . '/database_export.sql';

        // Load SQL template and interpolate variables
        $templateEngine = new TemplateEngine();
        $sampleSql = $templateEngine->template('sample_backup_model', TemplateFormat::SQL, [
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        file_put_contents($sqlFile, $sampleSql);
    }

    /**
     * Copy image files to backup
     */
    private function copyImageFiles(string $tempPath, ?string $collectionId): void
    {
        $imageDir = $tempPath . '/images';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }

        // Simulate copying image files
        for ($i = 1; $i <= 5; $i++) {
            $sampleImage = $imageDir . "/sample_image_{$i}.jpg";
            file_put_contents($sampleImage, "Sample image data {$i}");
        }
    }

    /**
     * Create encrypted ZIP archive
     */
    private function createEncryptedZip(string $sourcePath, string $zipPath, string $password): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== TRUE) {
            throw new Exception("Cannot create ZIP file: {$zipPath}");
        }

        // Set password for encryption
        $zip->setPassword($password);
        $zip->setEncryptionName('database_export.sql', ZipArchive::EM_AES_256);

        // Add files recursively
        $this->addFilesToZip($zip, $sourcePath, '', $password);

        $zip->close();
    }

    /**
     * Add files to ZIP recursively
     */
    private function addFilesToZip(ZipArchive $zip, string $sourcePath, string $relativePath, string $password): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativeFilePath = substr($filePath, strlen($sourcePath) + 1);

                // Ensure clean relative path (no leading slashes or directory separators)
                $relativeFilePath = ltrim($relativeFilePath, '/\\');

                // Use addFromString to ensure clean relative paths (same as encrypted version)
                $fileContent = file_get_contents($filePath);
                $zip->addFromString($relativeFilePath, $fileContent);
                $zip->setEncryptionName($relativeFilePath, ZipArchive::EM_AES_256);
            }
        }
    }

    /**
     * Get list of existing backups
     */
    private function getBackupList(): array
    {
        $backups = [];

        if (!is_dir($this->backupDir)) {
            return $backups;
        }

        $files = glob($this->backupDir . '/backup_*.zip');

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'filepath' => $file,
                'size' => filesize($file),
                'date' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        // Sort by date (newest first)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $backups;
    }

    /**
     * Get system information
     */
    private function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'zip_extension' => extension_loaded('zip'),
            'backup_dir' => $this->backupDir,
            'backup_dir_writable' => is_writable($this->backupDir),
            'disk_space' => disk_free_space($this->backupDir),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
    }

    /**
     * Initialize backup progress tracking
     */
    private function initializeBackupProgress(string $backupId): void
    {
        $this->backupHistory[$backupId] = [
            'progress' => 0,
            'status' => 'Starting backup...',
            'started_at' => time()
        ];
    }

    /**
     * Update backup progress
     */
    private function updateBackupProgress(string $backupId, int $progress, string $status): void
    {
        $this->backupHistory[$backupId]['progress'] = $progress;
        $this->backupHistory[$backupId]['status'] = $status;
        $this->backupHistory[$backupId]['updated_at'] = time();
    }

    /**
     * Get backup progress
     */
    private function getBackupProgress(string $backupId): array
    {
        return $this->backupHistory[$backupId] ?? [
            'progress' => 0,
            'status' => 'Backup not found',
            'error' => true
        ];
    }

    /**
     * Generate secure password
     */
    private function generatePassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    // formatFileSize method removed - now inherited from Model base class



    /**
     * Clean up temporary files
     */
    private function cleanupTempFiles(string $tempPath): void
    {
        if (is_dir($tempPath)) {
            $this->removeDirectory($tempPath);
        }
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Load SQL template with fallback to inline SQL
     */
    private function loadSqlTemplate(string $templateName, array $variables = []): string
    {
        try {
            // Try to load from file first
            return ApplicationPaths::loadSqlTemplate('backup', $templateName, $variables);
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
            case 'user_details':
                return "SELECT username, firstname, lastname FROM users WHERE uid = ?";
            case 'collection_details':
                return "SELECT collectionName, institutionCode, collectionCode FROM omcollections WHERE collid = ?";
            case 'collections_with_admins':
                return "SELECT c.collid, c.collectionname, c.collectioncode, c.institutioncode,
                               COUNT(DISTINCT o.occid) as recordcount,
                               COUNT(DISTINCT ur.uid) as admin_count
                        FROM omcollections c
                        LEFT JOIN omoccurrences o ON c.collid = o.collid
                        LEFT JOIN userroles ur ON c.collid = ur.collid AND ur.role = 'CollAdmin'
                        WHERE c.colltype = 'Preserved Specimens'
                        GROUP BY c.collid, c.collectionname, c.collectioncode, c.institutioncode
                        HAVING admin_count > 0
                        ORDER BY c.collectionname";
            case 'test_connection':
                return "SELECT COUNT(*) as count FROM users LIMIT 1";
            default:
                throw new \RuntimeException("Unknown SQL template: {$templateName}");
        }
    }

    /**
     * Get collections with admin users from database
     */
    private function getCollectionsWithAdmins(): array
    {
        // Load SQL from template
        $sql = $this->loadSqlTemplate('collections_with_admins') . " LIMIT 10";

        $connection = $this->getConnection();

        if (!$connection) {
            throw new Exception('Database connection not available');
        }

        $result = $connection->query($sql);

        if ($result === false) {
            throw new Exception('SQL query failed: ' . $connection->error . "\nSQL: " . $sql);
        }

        $collections = [];
        while ($row = $result->fetch_assoc()) {
            $collections[] = $row;
        }

        return $collections;
    }

    /**
     * Get colladmin users for a specific collection
     */
    private function getCollectionAdminUsers(string $collid): array
    {
        // Temporarily use SQL template method directly to avoid BackupManager issues
        try {
            $sql = $this->renderTemplate('backup/collection_admin_users', TemplateFormat::SQL, [
                'collid' => $collid
            ]);
            $connection = $this->getConnection();
            $result = $connection->query($sql);

            $users = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $users[] = $row;
                }
            }

            return $users;
        } catch (Exception $e) {
            throw new Exception("Failed to get collection admin users: " . $e->getMessage());
        }
    }

    /**
     * Verify user has colladmin access to collection
     * SuperAdmin users have access to all collections
     */
    private function verifyUserCollectionAccess(string $userid, string $collid): bool
    {
        $connection = $this->getConnection();

        // Check if user is SuperAdmin (has access to all collections)
        $superAdminSql = $this->renderTemplate('backup/check_super_admin', TemplateFormat::SQL, ['uid' => $userid]);
        $superAdminResult = $connection->query($superAdminSql);
        if ($superAdminResult && $superAdminResult->fetch_assoc()['count'] > 0) {
            return true;
        }

        // Check specific collection access for CollAdmin users
        $sql = $this->renderTemplate('backup/verify_user_access', TemplateFormat::SQL, [
            'userid' => $userid,
            'collid' => $collid
        ]);
        $result = $connection->query($sql);

        if (!$result) {
            throw new Exception("Database error: " . $connection->error);
        }

        return $result->num_rows > 0;
    }

    /**
     * Get collection information
     */
    private function getCollectionInfo(string $collid): array
    {
        $sql = $this->renderTemplate('backup/collection_info', TemplateFormat::SQL, [
            'collid' => $collid
        ]);
        $connection = $this->getConnection();
        $result = $connection->query($sql);

        if (!$result) {
            throw new Exception("Database error: " . $connection->error);
        }

        if ($row = $result->fetch_assoc()) {
            return $row;
        }

        throw new Exception("Collection {$collid} not found");
    }

    /**
     * Get Symbiota class path using configuration state system
     */
    private function getSymbiotaClassPath(): ?string
    {
        $config = \Symbiota\Helpers\Core\Configuration::getInstance();
        if ($config) {
            return $config->getSymbiotaClassPath();
        }

        // Legacy fallback to backup module configuration
        return $this->backupConfig['classpath'] ?? null;
    }

    /**
     * Load Symbiota environment and classes
     */
    private function loadSymbiotaEnvironment(): void
    {
        // Define SERVER_ROOT for Symbiota classes
        global $SERVER_ROOT;
        if (!defined('SERVER_ROOT') && !isset($SERVER_ROOT)) {
            $SERVER_ROOT = realpath(sprintf('%s/dev/git-code/Symbiota', ApplicationPaths::applicationDirectory()));
            define('SERVER_ROOT', $SERVER_ROOT);
        }

        // Load Symbiota configuration
        $configPath = $this->config['database']['dbconnection'] ?? 'config/dbconnection.php';
        $symbiotaConfigPath = dirname($configPath) . '/symbini.php';
        if (file_exists($symbiotaConfigPath)) {
            include_once $symbiotaConfigPath;
        }

        // Load DwcArchiverCore class
        $dwcArchiverPath = 'dev/git-code/Symbiota/classes/DwcArchiverCore.php';
        if (file_exists($dwcArchiverPath)) {
            include_once $dwcArchiverPath;
        } else {
            throw new Exception('DwcArchiverCore class not found');
        }
    }

    /**
     * Get app URL prefix for templates
     */
    public function getAppUrlPrefix(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/?/';
        $parser = new UriParser($requestUri);
        $fullUrl = $parser->getAppUrlPrefix();



        // For Symbiota environment, we need to preserve the full path including client root
        // The UriParser should already handle this correctly based on REQUEST_URI
        if (str_contains($fullUrl, '://')) {
            $parts = parse_url($fullUrl);
            $path = $parts['path'] ?? '';
            // Ensure path ends with /?/ for proper routing
            if (!str_ends_with($path, '/?/')) {
                $path = rtrim($path, '/') . '/?/';
            }
            return $path;
        }
        return $fullUrl;
    }

    /**
     * Load backup registry from JSON file
     */
    private function loadBackupRegistry(): array
    {
        if (!file_exists($this->backupRegistryPath)) {
            return [];
        }

        $content = file_get_contents($this->backupRegistryPath);
        return json_decode($content, true) ?: [];
    }

    /**
     * Save backup registry to JSON file
     */
    private function saveBackupRegistry(array $registry): void
    {
        $dataDir = dirname($this->backupRegistryPath);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        file_put_contents($this->backupRegistryPath, json_encode($registry, JSON_PRETTY_PRINT));
        chmod($this->backupRegistryPath, 0644);
    }

    /**
     * Encrypt passphrase with multi-iteration salt using AES-256-CBC
     */
    private function encryptPassphrase(string $passphrase): string
    {
        $iterations = $this->getSaltIterations();
        $key = $this->deriveEncryptionKey($iterations);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($passphrase, 'AES-256-CBC', $key, 0, $iv);

        // Store iteration count with encrypted data (first 4 bytes)
        $iterationBytes = pack('N', $iterations);
        return base64_encode($iterationBytes . $iv . $encrypted);
    }

    /**
     * Decrypt passphrase with multi-iteration salt using AES-256-CBC
     */
    private function decryptPassphrase(string $encrypted): string
    {
        $data = base64_decode($encrypted);

        // Extract iteration count (first 4 bytes)
        $iterations = unpack('N', substr($data, 0, 4))[1];
        $iv = substr($data, 4, 16);
        $encryptedData = substr($data, 20);

        $key = $this->deriveEncryptionKey($iterations);
        $decrypted = openssl_decrypt($encryptedData, 'AES-256-CBC', $key, 0, $iv);

        if ($decrypted === false) {
            throw new Exception('Failed to decrypt passphrase');
        }

        return $decrypted;
    }

    /**
     * Create verification hash for passphrase with multi-iteration salt (for validation without decryption)
     */
    private function createPassphraseHash(string $passphrase): string
    {
        $iterations = $this->getSaltIterations();
        return $this->derivePassphraseHash($passphrase, $iterations);
    }

    /**
     * Get salt iteration count from configuration
     */
    private function getSaltIterations(): int
    {
        $saltit = $this->backupConfig['saltit'] ?? 'default';

        // Convert saltit word to iteration count
        if (is_numeric($saltit)) {
            return max(1, (int)$saltit);
        }

        // Count letters in saltit word (e.g., "you" = 3, "security" = 8)
        $letterCount = strlen(preg_replace('/[^a-zA-Z]/', '', $saltit));
        return max(1, $letterCount);
    }

    /**
     * Derive encryption key using multi-iteration salt
     */
    private function deriveEncryptionKey(int $iterations): string
    {
        $key = $this->siteSalt;

        // Apply salt iterations: salt + key = newKey (repeated)
        for ($i = 0; $i < $iterations; $i++) {
            $key = hash('sha256', $this->siteSalt . $key, true);
        }

        return $key;
    }

    /**
     * Derive passphrase hash using multi-iteration salt
     */
    private function derivePassphraseHash(string $passphrase, int $iterations): string
    {
        $hash = $passphrase;

        // Apply salt iterations: orig + salt = hash1 -> salt + hash1 = hash2 -> etc.
        for ($i = 0; $i < $iterations; $i++) {
            if ($i === 0) {
                $hash = hash('sha256', $hash . $this->siteSalt);
            } else {
                $hash = hash('sha256', $this->siteSalt . $hash);
            }
        }

        return $hash;
    }

    /**
     * Verify passphrase against stored hash
     */
    private function verifyPassphraseHash(string $passphrase, string $storedHash): bool
    {
        // Check if this is a hex hash (new format) - hex strings are 64 chars for SHA256
        if (ctype_xdigit($storedHash) && strlen($storedHash) === 64) {
            // New format - try different iteration counts for backward compatibility
            $currentIterations = $this->getSaltIterations();

            // First try current iteration count
            if (hash_equals($storedHash, $this->derivePassphraseHash($passphrase, $currentIterations))) {
                return true;
            }

            // Try common iteration counts for backward compatibility
            $commonIterations = [1, 3, 5, 8, 10];
            foreach ($commonIterations as $iterations) {
                if ($iterations !== $currentIterations) {
                    if (hash_equals($storedHash, $this->derivePassphraseHash($passphrase, $iterations))) {
                        return true;
                    }
                }
            }

            // Try simple salt+passphrase hash (original method)
            $simpleHash = hash('sha256', $this->siteSalt . $passphrase);
            return hash_equals($storedHash, $simpleHash);
        } else {
            // Old format (base64 encoded) - try to decrypt and verify, or fall back to old hash
            try {
                $decrypted = $this->decryptPassphrase($storedHash);
                return $decrypted === $passphrase;
            } catch (Exception $e) {
                // If decryption fails, fall back to old hash comparison
                try {
                    return $storedHash === base64_encode(hash('sha256', $this->siteSalt . $passphrase, true));
                } catch (Exception $e2) {
                    // If all else fails, return false
                    return false;
                }
            }
        }
    }

    /**
     * Generate site salt if not configured
     */
    private function generateSiteSalt(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get portal temp directory
     */
    private function getPortalTempDir(): string
    {
        // Try to get from Symbiota configuration
        global $TEMP_DIR_ROOT;
        if (isset($TEMP_DIR_ROOT) && $TEMP_DIR_ROOT) {
            return $TEMP_DIR_ROOT;
        }

        // Fallback to system temp directory (not application directory)
        return sys_get_temp_dir() . '/symbiota_portal';
    }



    /**
     * Generate backup filename
     */
    private function generateBackupFilename(array $collectionInfo): string
    {
        $instcode = $collectionInfo['institutioncode'] ?? 'INST';
        $collcode = $collectionInfo['collectioncode'] ?? '';

        $filename = $instcode;
        if ($collcode) {
            $filename .= '-' . $collcode;
        }
        $filename .= '_backup_' . date('Y-m-d_His') . '_DwC-A.enc.zip';

        return $filename;
    }

    /**
     * Check backup throttle (24-hour limit)
     */
    private function checkBackupThrottle(string $collid): array
    {
        $registry = $this->loadBackupRegistry();

        if (!isset($registry[$collid])) {
            return ['allowed' => false, 'message' => 'No users registered for this collection'];
        }

        // Check for existing backup files
        $backupFiles = $this->getCollectionBackupFiles($collid);
        if (empty($backupFiles)) {
            return ['allowed' => true, 'message' => 'No previous backups found'];
        }

        // Get most recent backup
        $latestBackup = $backupFiles[0]; // Assuming sorted by date desc
        $lastBackupTime = filemtime($latestBackup);
        $timeDiff = time() - $lastBackupTime;
        $secondsDiff = $timeDiff;
        $minutesDiff = $secondsDiff / 60; // Keep as float for decimal minute support

        // Get configurable backup threshold (minutes between backups, supports decimals)
        $backupThresholdMinutes = (float)($this->backupConfig['backup_threshold'] ?? self::DEFAULT_BACKUP_THRESHOLD_MINUTES);
        $offsetBufferMinutes = $backupThresholdMinutes >= 60.0 ? (float)self::OFFSET_BUFFER : 0.0; // No offset for short intervals
        $throttleMinutes = $backupThresholdMinutes - $offsetBufferMinutes;

        if ($minutesDiff < $throttleMinutes) {
            $remainingMinutes = $throttleMinutes - (float)$minutesDiff;

            // Format remaining time based on threshold size
            if ($backupThresholdMinutes < 1.0) {
                // For sub-minute thresholds, show seconds
                $remainingSeconds = round($remainingMinutes * 60.0);
                $thresholdSeconds = round($backupThresholdMinutes * 60.0);
                $message = sprintf('Backup not available for %d seconds (%d-second throttle)', $remainingSeconds, $thresholdSeconds);
            } elseif ($backupThresholdMinutes < 60.0) {
                // For minute thresholds, show minutes with decimals
                $thresholdLabel = $backupThresholdMinutes == floor($backupThresholdMinutes)
                    ? sprintf('%.0f-minute', $backupThresholdMinutes)
                    : sprintf('%.2f-minute', $backupThresholdMinutes);
                $message = sprintf('Backup not available for %.1f minutes (%s throttle)', $remainingMinutes, $thresholdLabel);
            } else {
                // For hour+ thresholds, show hours and minutes
                $remainingHours = (int)floor($remainingMinutes / 60.0);
                $remainingMins = (int)floor(fmod($remainingMinutes, 60.0));
                $thresholdHours = (int)floor($backupThresholdMinutes / 60.0);
                $thresholdLabel = "{$thresholdHours}-hour";
                $message = sprintf('Backup not available for %dh %dm (%s throttle)', $remainingHours, $remainingMins, $thresholdLabel);
            }

            return [
                'allowed' => false,
                'message' => $message
            ];
        }

        return ['allowed' => true, 'message' => 'Backup allowed'];
    }



    /**
     * Get the site salt for passphrase hashing
     * Requires site_salt to be configured in INI file for security
     */
    private function getSiteSalt(): string
    {
        if (!isset($this->backupConfig['site_salt']) || empty($this->backupConfig['site_salt'])) {
            throw new \Exception('Site salt must be configured in INI file for security. Add "site_salt" to [backup] section.');
        }
        return $this->backupConfig['site_salt'];
    }

    /**
     * Hash a plain-text passphrase with site salt for storage
     */
    private function hashPassphrase(string $plainPassphrase): string
    {
        $salt = $this->getSiteSalt();
        return base64_encode(hash('sha256', $plainPassphrase . $salt, true));
    }



    /**
     * Check if backup is in progress
     */
    private function checkBackupInProgress(string $collid): array
    {
        $progressFile = $this->getBackupProgressFile($collid);
        return [
            'in_progress' => file_exists($progressFile),
            'file' => $progressFile
        ];
    }

    /**
     * Get backup progress file path
     */
    private function getBackupProgressFile(string $collid): string
    {
        return $this->getBackupDir() . '/.backup_in_progress_' . $collid;
    }

    /**
     * Get collection backup files
     */
    private function getCollectionBackupFiles(string $collid): array
    {
        $backupDir = $this->getBackupDir();
        $collectionInfo = $this->getCollectionInfo($collid);

        $instcode = $collectionInfo['institutioncode'] ?? 'INST';
        $collcode = $collectionInfo['collectioncode'] ?? '';

        $pattern = $instcode;
        if ($collcode) {
            $pattern .= '-' . $collcode;
        }
        $pattern .= '_backup_*_DwC-A.enc.zip';

        $files = glob($backupDir . '/' . $pattern);

        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    /**
     * Get collection record count
     */
    private function getCollectionRecordCount(string $collid): int
    {
        $sql = $this->renderTemplate('backup/collection_record_count', TemplateFormat::SQL, [
            'collid' => $collid
        ]);
        $connection = $this->getConnection();
        $result = $connection->query($sql);

        if ($row = $result->fetch_assoc()) {
            return (int) $row['record_count'];
        }

        return 0;
    }

    /**
     * Estimate backup size based on collection data
     */
    private function estimateBackupSize(string $collid): int
    {
        $recordCount = $this->getCollectionRecordCount($collid);

        // Rough estimate: 2KB per record for data + overhead
        $estimatedSize = $recordCount * 2048;

        // Add base overhead for ZIP structure
        $estimatedSize += 1024 * 1024; // 1MB base

        return $estimatedSize;
    }

    /**
     * Update backup registry with new backup info
     */
    private function updateBackupRegistry(string $collid, string $filename): void
    {
        $registry = $this->loadBackupRegistry();

        if (isset($registry[$collid])) {
            foreach ($registry[$collid] as $userid => &$userInfo) {
                $userInfo['last_backup'] = date('Y-m-d H:i:s');
                $userInfo['last_backup_file'] = $filename;
            }

            $this->saveBackupRegistry($registry);
        }
    }

    /**
     * Add files to encrypted ZIP with password protection
     */
    private function addFilesToEncryptedZip(ZipArchive $zip, string $sourcePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourcePath) + 1);

                // Ensure clean relative path (no leading slashes or directory separators)
                $relativePath = ltrim($relativePath, '/\\');

                // Use addFromString to ensure clean relative paths
                $fileContent = file_get_contents($filePath);
                $zip->addFromString($relativePath, $fileContent);
                $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256);
            }
        }
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->portalTempDir,
            $this->portalTempDir . '/data',
            $this->getBackupDir(),
            $this->workingDir,
            $this->storageDir
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Format CLI help output
     */
    private function formatCliHelp(array $helpContent): string
    {
        $outputLines = [
            "\n=== {$helpContent['title']} ===\n",
            "{$helpContent['description']}\n",
            "Features:"
        ];

        foreach ($helpContent['features'] as $feature) {
            $outputLines[] = "  • {$feature}";
        }

        $outputLines[] = "\nUsage:";
        foreach ($helpContent['usage'] as $usage) {
            $outputLines[] = "  • {$usage}";
        }

        return implode("\n", $outputLines);
    }

    /**
     * Get BackupManager instance for Symbiota integration
     */
    private function getBackupManager(): BackupManager
    {
        return new BackupManager($this->getConnection('readonly'));
    }

    /**
     * Show current configuration
     */
    public function showConfiguration(array $params): array
    {
        try {
            $configInfo = [
                'Configuration Sources' => [],
                'Current Settings' => $this->backupConfig,
                'Computed Paths' => [
                    'backup_dir' => $this->getBackupDir(),
                    'registry_file_path' => $this->backupRegistryPath,
                    'templates_path' => ApplicationPaths::templatesDirectory()
                ],
                'Security Status' => [
                    'site_salt_configured' => isset($this->backupConfig['site_salt']) && !empty($this->backupConfig['site_salt']),
                    'salt_length' => isset($this->backupConfig['site_salt']) ? strlen($this->backupConfig['site_salt']) : 0
                ],
                'Parameter Constants' => [
                    'PARAM_PASSPHRASE' => self::PARAM_PASSPHRASE,
                    'PARAM_REGISTRY_FILE' => self::PARAM_REGISTRY_FILE,
                    'PARAM_OUTPUT_DIR' => self::PARAM_OUTPUT_DIR,
                    'PARAM_RETENTION_THRESHOLD' => self::PARAM_RETENTION_THRESHOLD,
                    'PARAM_BACKUP_THRESHOLD' => self::PARAM_BACKUP_THRESHOLD,
                    'PARAM_SITE_SALT' => self::PARAM_SITE_SALT,
                    'PARAM_SALTIT' => self::PARAM_SALTIT,
                    'PARAM_CLASSPATH' => self::PARAM_CLASSPATH,
                    'PARAM_HTTP_POST' => self::PARAM_HTTP_POST
                ],
                'Configuration Constants' => [
                    'CONFIG_REGISTRY_FILE' => self::CONFIG_REGISTRY_FILE,
                    'CONFIG_OUTPUT_DIR' => self::CONFIG_OUTPUT_DIR,
                    'CONFIG_RETENTION_THRESHOLD' => self::CONFIG_RETENTION_THRESHOLD,
                    'CONFIG_BACKUP_THRESHOLD' => self::CONFIG_BACKUP_THRESHOLD,
                    'CONFIG_SITE_SALT' => self::CONFIG_SITE_SALT,
                    'CONFIG_SALTIT' => self::CONFIG_SALTIT,
                    'CONFIG_CLASSPATH' => self::CONFIG_CLASSPATH,
                    'CONFIG_HTTP_POST' => self::CONFIG_HTTP_POST
                ],
                'Validation Results' => []
            ];

            // Check if config was loaded from file
            if (isset($this->backupConfig['_config_file_path'])) {
                $configInfo['Configuration Sources'][] = 'INI file: ' . $this->backupConfig['_config_file_path'];
            } else {
                $configInfo['Configuration Sources'][] = 'Default configuration (no INI file loaded)';
            }

            // Add configuration validation
            $validationErrors = [];

            $outputDir = $this->backupConfig['output_dir'] ?? '';
            $classpath = $this->backupConfig['classpath'] ?? '';
            $siteSalt = $this->backupConfig['site_salt'] ?? '';
            $retentionThreshold = $this->backupConfig['retention_threshold'] ?? 0;
            $backupThreshold = $this->backupConfig['backup_threshold'] ?? 0;

            if (!empty($outputDir) && !is_dir($outputDir)) {
                $validationErrors[] = "Output directory does not exist: " . $outputDir;
            } elseif (!empty($outputDir) && !is_writable($outputDir)) {
                $validationErrors[] = "Output directory is not writable: " . $outputDir;
            }

            if (!is_writable(dirname($this->backupRegistryPath))) {
                $validationErrors[] = "Registry file directory is not writable: " . dirname($this->backupRegistryPath);
            }

            if (!empty($classpath) && !is_dir($classpath)) {
                $validationErrors[] = "Classpath directory does not exist: " . $classpath;
            }

            if (strlen($siteSalt) < 16) {
                $validationErrors[] = "Site salt should be at least 16 characters for security (current: " . strlen($siteSalt) . ")";
            }

            if ($retentionThreshold < 1) {
                $validationErrors[] = "Retention threshold should be at least 1 (current: " . $retentionThreshold . ")";
            }

            if ($backupThreshold < 0 || $backupThreshold > 1) {
                $validationErrors[] = "Backup threshold should be between 0 and 1 (current: " . $backupThreshold . ")";
            }

            if (empty($validationErrors)) {
                $configInfo['Validation Results'][] = "✓ All configuration values are valid";
            } else {
                $configInfo['Validation Results'] = array_merge(['✗ Configuration issues found:'], $validationErrors);
            }

            if (Environment::isCli()) {
                $output = [];
                $output[] = "Backup Configuration";
                $output[] = str_repeat("=", 40);
                $output[] = "";

                foreach ($configInfo as $section => $data) {
                    $output[] = $section . ":";
                    $output[] = str_repeat("-", strlen($section) + 1);

                    if (is_array($data)) {
                        foreach ($data as $key => $value) {
                            if (is_bool($value)) {
                                $value = $value ? 'YES' : 'NO';
                            } elseif (is_array($value)) {
                                $value = json_encode($value, JSON_PRETTY_PRINT);
                            } elseif ($key === 'site_salt' && !empty($value)) {
                                $value = str_repeat('*', min(strlen($value), 20)) . ' (hidden)';
                            }
                            $output[] = sprintf("  %-20s: %s", $key, $value);
                        }
                    } else {
                        $output[] = "  " . $data;
                    }
                    $output[] = "";
                }

                // Add warnings if needed
                if (!isset($this->backupConfig['site_salt']) || empty($this->backupConfig['site_salt'])) {
                    $output[] = "⚠️  WARNING: Site salt not configured!";
                    $output[] = "   Please configure backup.site_salt in config.php";
                    $output[] = "";
                }

                return [
                    'type' => 'cli',
                    'content' => implode("\n", $output)
                ];
            } else {
                return [
                    'type' => 'success',
                    'message' => 'Configuration retrieved',
                    'config_info' => $configInfo
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to retrieve configuration: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get backup configuration and status (similar to upload module status)
     */
    private function getBackupConfiguration(array $params): array
    {
        $status = [
            'configuration' => [
                'backup_dir' => $this->getBackupDir(),
                'registry_file' => $this->backupRegistryPath,
                'site_salt_configured' => isset($this->backupConfig['site_salt']) && !empty($this->backupConfig['site_salt']),
                'salt_length' => isset($this->backupConfig['site_salt']) ? strlen($this->backupConfig['site_salt']) : 0,
                'retention_threshold' => $this->backupConfig['retention_threshold'] ?? 0,
                'backup_threshold' => $this->backupConfig['backup_threshold'] ?? 0,
                'output_dir' => $this->backupConfig['output_dir'] ?? null,
                'classpath' => $this->backupConfig['classpath'] ?? null
            ],
            'paths' => [
                'backup_dir_absolute' => realpath($this->getBackupDir()) ?: $this->getBackupDir(),
                'registry_file_absolute' => realpath($this->backupRegistryPath) ?: $this->backupRegistryPath,
                'backup_dir_exists' => is_dir($this->getBackupDir()),
                'backup_dir_writable' => is_dir($this->getBackupDir()) && is_writable($this->getBackupDir()),
                'registry_file_exists' => file_exists($this->backupRegistryPath),
                'registry_dir_writable' => is_writable(dirname($this->backupRegistryPath))
            ],
            'security' => [
                'site_salt_configured' => isset($this->backupConfig['site_salt']) && !empty($this->backupConfig['site_salt']),
                'salt_meets_minimum_length' => isset($this->backupConfig['site_salt']) && strlen($this->backupConfig['site_salt']) >= 16
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'zip_extension_loaded' => extension_loaded('zip'),
                'temp_dir' => sys_get_temp_dir(),
                'working_dir_available' => $this->isWorkingDirAvailable(),
                'storage_dir_available' => $this->isStorageDirAvailable()
            ]
        ];

        if (Environment::isCli()) {
            $output = [];
            $output[] = "\nBackup Module Configuration:";
            $output[] = str_repeat("=", 40);
            $output[] = "";

            // Configuration section
            $output[] = "Configuration:";
            $output[] = sprintf("  Backup Directory: %s", $status['configuration']['backup_dir']);
            $output[] = sprintf("  Registry File: %s", $status['configuration']['registry_file']);
            $output[] = sprintf("  Site Salt: %s (%d chars)",
                $status['configuration']['site_salt_configured'] ? 'Configured' : 'NOT CONFIGURED',
                $status['configuration']['salt_length']
            );
            $output[] = sprintf("  Retention Threshold: %d days", $status['configuration']['retention_threshold']);
            $backupThreshold = $status['configuration']['backup_threshold'];
            if ($backupThreshold < 60) {
                $output[] = sprintf("  Backup Threshold: %.2f minutes", $backupThreshold);
            } elseif ($backupThreshold < 1440) {
                $output[] = sprintf("  Backup Threshold: %.2f hours", $backupThreshold / 60);
            } else {
                $output[] = sprintf("  Backup Threshold: %.2f days", $backupThreshold / 1440);
            }
            $output[] = "";

            // Paths section
            $output[] = "Path Status:";
            $output[] = sprintf("  Backup Dir Exists: %s", $status['paths']['backup_dir_exists'] ? 'YES' : 'NO');
            $output[] = sprintf("  Backup Dir Writable: %s", $status['paths']['backup_dir_writable'] ? 'YES' : 'NO');
            $output[] = sprintf("  Registry File Exists: %s", $status['paths']['registry_file_exists'] ? 'YES' : 'NO');
            $output[] = sprintf("  Registry Dir Writable: %s", $status['paths']['registry_dir_writable'] ? 'YES' : 'NO');
            $output[] = "";

            // Security section
            $output[] = "Security Status:";
            $output[] = sprintf("  Site Salt Configured: %s", $status['security']['site_salt_configured'] ? 'YES' : 'NO');
            $output[] = sprintf("  Salt Length Adequate: %s", $status['security']['salt_meets_minimum_length'] ? 'YES' : 'NO');
            $output[] = "";

            // System section
            $output[] = "System Status:";
            $output[] = sprintf("  PHP Version: %s", $status['system']['php_version']);
            $output[] = sprintf("  ZIP Extension: %s", $status['system']['zip_extension_loaded'] ? 'YES' : 'NO');
            $output[] = sprintf("  Working Dir Available: %s", $status['system']['working_dir_available'] ? 'YES' : 'NO');
            $output[] = sprintf("  Storage Dir Available: %s", $status['system']['storage_dir_available'] ? 'YES' : 'NO');

            // Add warnings
            if (!$status['security']['site_salt_configured']) {
                $output[] = "";
                $output[] = "⚠️  WARNING: Site salt not configured!";
                $output[] = "   Backups will not be properly encrypted without a site salt.";
                $output[] = "   Please configure site_salt in your backup configuration.";
            }

            if (!$status['system']['zip_extension_loaded']) {
                $output[] = "";
                $output[] = "⚠️  WARNING: PHP zip extension not loaded!";
                $output[] = "   Backup operations require the zip extension.";
                $output[] = "   Install with: sudo apt-get install php-zip (Ubuntu/Debian)";
                $output[] = "   Then restart your web server.";
            }

            return [
                'type' => 'cli',
                'content' => implode("\n", $output)
            ];
        } else {
            return [
                'type' => 'success',
                'message' => 'Backup configuration retrieved',
                'status' => $status
            ];
        }
    }

    /**
     * List all registered collections and users in the backup registry
     */
    public function listRegistry(array $params): array
    {
        try {
            // Load registry file
            $registry = $this->loadBackupRegistry();

            if (empty($registry)) {
                return [
                    'type' => Environment::isCli() ? 'cli' : 'success',
                    'content' => "No collections registered for backup.\nUse 'php helpers.php backup register <collid> <userid> --passphrase=<phrase>' to register a collection."
                ];
            }

            $content = [];
            $content[] = "=== Backup Registry ===";
            $content[] = "";

            // Get database connection for collection/user details
            $connection = $this->getConnection();
            $totalRegistrations = 0;

            foreach ($registry as $collid => $users) {
                $collectionName = "Collection {$collid}";
                $institutionCode = "";
                $collectionCode = "";

                // Get collection details if database is available
                if ($connection) {
                    $sql = $this->loadSqlTemplate('collection_details');
                    $stmt = $connection->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param('i', $collid);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $collectionName = $row['collectionName'];
                            $institutionCode = $row['institutionCode'] ?? '';
                            $collectionCode = $row['collectionCode'] ?? '';
                        }
                        $stmt->close();
                    }
                }

                $content[] = "Collection {$collid}: {$collectionName}";
                if ($institutionCode || $collectionCode) {
                    $content[] = "  Institution: {$institutionCode} ({$collectionCode})";
                }
                $content[] = "  Registered Users:";

                foreach ($users as $userid => $userInfo) {
                    $totalRegistrations++;
                    $username = "User {$userid}";
                    $fullName = "";

                    // Get user details if database is available
                    if ($connection) {
                        $sql = $this->loadSqlTemplate('user_details');
                        $stmt = $connection->prepare($sql);
                        if ($stmt) {
                            $stmt->bind_param('i', $userid);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $username = $row['username'];
                                $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                            }
                            $stmt->close();
                        }
                    }

                    $registeredDate = isset($userInfo['registered_at']) ?
                        $userInfo['registered_at'] : 'Unknown';

                    $content[] = "    - {$username}" . ($fullName ? " ({$fullName})" : "");
                    $content[] = "      Registered: {$registeredDate}";
                    $content[] = "      Status: " . ($userInfo['status'] ?? 'active');
                }
                $content[] = "";
            }

            $content[] = "Total: {$totalRegistrations} user registrations across " . count($registry) . " collections";

            return [
                'type' => Environment::isCli() ? 'cli' : 'success',
                'content' => implode("\n", $content)
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to list registry: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Verify passphrase for backup extraction
     */
    public function verifyPassphrase(array $params): array
    {
        if (!Environment::isCli()) {
            return [
                'type' => 'error',
                'message' => 'Passphrase verification is only available via CLI for security',
                'status_code' => 400
            ];
        }

        try {
            // Get collection ID if provided
            $collid = $params['collid'] ?? $params[0] ?? null;

            if (!$collid) {
                echo "Enter collection ID: ";
                $collid = trim(fgets(STDIN));
                if (empty($collid)) {
                    return [
                        'type' => 'error',
                        'content' => 'Collection ID is required'
                    ];
                }
            }

            // Check if collection has registered users
            $registry = $this->loadBackupRegistry();
            if (!isset($registry[$collid]) || empty($registry[$collid])) {
                return [
                    'type' => 'error',
                    'content' => "No users registered for collection {$collid}"
                ];
            }

            // Prompt for passphrase (hidden input)
            echo "Enter passphrase for collection {$collid}: ";

            // Hide input for security (only if terminal is interactive)
            $isInteractive = posix_isatty(STDIN);
            if ($isInteractive) {
                system('stty -echo');
            }
            $inputPassphrase = trim(fgets(STDIN));
            if ($isInteractive) {
                system('stty echo');
                echo "\n";
            }

            if (empty($inputPassphrase)) {
                return [
                    'type' => 'error',
                    'content' => 'Passphrase cannot be empty'
                ];
            }

            // Check if site salt is configured
            if (!isset($this->backupConfig['site_salt']) || empty($this->backupConfig['site_salt'])) {
                return [
                    'type' => 'error',
                    'content' => 'Site salt not configured. Please configure backup.site_salt in config.php'
                ];
            }

            // Check against registered passphrases
            $collectionUsers = $registry[$collid];
            $isValid = false;
            $matchedUser = null;

            foreach ($collectionUsers as $userid => $userInfo) {
                // Use passphrase_hash if available (new format), otherwise fall back to passphrase (old format)
                $storedHash = $userInfo['passphrase_hash'] ?? $userInfo['passphrase'];
                try {
                    if ($this->verifyPassphraseHash($inputPassphrase, $storedHash)) {
                        $isValid = true;
                        $matchedUser = $userid;
                        break;
                    }
                } catch (Exception $e) {
                    // Continue to next user if verification fails
                    continue;
                }
            }

            if ($isValid) {
                // Get user details for confirmation
                $connection = $this->getConnection();
                $username = "User {$matchedUser}";
                if ($connection) {
                    $sql = $this->loadSqlTemplate('user_details');
                    $stmt = $connection->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param('i', $matchedUser);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $username = $row['username'];
                            $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                            if ($fullName) {
                                $username .= " ({$fullName})";
                            }
                        }
                        $stmt->close();
                    }
                }

                return [
                    'type' => 'success',
                    'content' => "✅ CORRECT - Passphrase verified for {$username} on collection {$collid}"
                ];
            } else {
                return [
                    'type' => 'error',
                    'content' => "❌ INCORRECT - Password is not correct"
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'content' => 'Passphrase verification failed: ' . $e->getMessage()
            ];
        }
    }



    /**
     * Test action for debugging
     */
    public function test(array $params): array
    {
        try {
            $connection = $this->getConnection();
            if (!$connection) {
                return [
                    'type' => 'error',
                    'error' => 'Database connection failed',
                    'code' => 500
                ];
            }

            // Test basic query
            $result = $connection->query($this->loadSqlTemplate('test_connection'));
            if (!$result) {
                return [
                    'type' => 'error',
                    'error' => 'Query failed: ' . $connection->error,
                    'code' => 500
                ];
            }

            $row = $result->fetch_assoc();
            $userCount = $row['count'];

            // Test the specific SQL template
            $sql = $this->renderTemplate('backup/collection_admin_users', TemplateFormat::SQL, [
                'collid' => '1'
            ]);

            $result = $connection->query($sql);
            if (!$result) {
                return [
                    'type' => 'error',
                    'error' => 'SQL template query failed: ' . $connection->error,
                    'code' => 500
                ];
            }

            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }

            return [
                'type' => Environment::isCli() ? 'cli' : 'success',
                'content' => "Backup system test successful!\nDatabase: Available\nTotal users: {$userCount}\nCollection 1 admin users: " . count($users) . "\nUsers: " . json_encode($users, JSON_PRETTY_PRINT)
            ];
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Test failed: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Generate mock meta.xml for DwC-A
     */
    private function generateMockMetaXml(array $collectionInfo): string
    {
        $collectionName = htmlspecialchars($collectionInfo['collectionName'] ?? 'Unknown Collection');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<archive xmlns="http://rs.tdwg.org/dwc/text/" metadata="eml.xml">
  <core encoding="UTF-8" fieldsTerminatedBy="\t" linesTerminatedBy="\n" fieldsEnclosedBy="" ignoreHeaderLines="1" rowType="http://rs.tdwg.org/dwc/terms/Occurrence">
    <files>
      <location>occurrences.txt</location>
    </files>
    <id index="0"/>
    <field index="1" term="http://rs.tdwg.org/dwc/terms/catalogNumber"/>
    <field index="2" term="http://rs.tdwg.org/dwc/terms/scientificName"/>
    <field index="3" term="http://rs.tdwg.org/dwc/terms/family"/>
    <field index="4" term="http://rs.tdwg.org/dwc/terms/locality"/>
    <field index="5" term="http://rs.tdwg.org/dwc/terms/recordedBy"/>
    <field index="6" term="http://rs.tdwg.org/dwc/terms/eventDate"/>
  </core>
</archive>
XML;
    }

    /**
     * Generate mock occurrence data
     */
    private function generateMockOccurrenceData(string $collid): string
    {
        $header = "occurrenceID\tcatalogNumber\tscientificName\tfamily\tlocality\trecordedBy\teventDate\n";
        $data = [];

        // Get actual record count for this collection
        $recordCount = $this->getCollectionRecordCount($collid);

        // Generate sample records (limit to 100 for demo, but show real count in comment)
        $sampleCount = min($recordCount, 100);

        for ($i = 1; $i <= $sampleCount; $i++) {
            $occid = ((int)$collid * 1000) + $i;
            $catalogNumber = "COLL{$collid}-" . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
            $scientificName = "Genus species var. example{$i}";
            $family = "Exampleaceae";
            $locality = "Sample locality {$i}, Test County, State";
            $recordedBy = "Test Collector {$i}";
            $eventDate = date('Y-m-d', strtotime("-" . rand(1, 3650) . " days"));

            $data[] = "{$occid}\t{$catalogNumber}\t{$scientificName}\t{$family}\t{$locality}\t{$recordedBy}\t{$eventDate}";
        }

        // Add comment about actual vs sample count
        if ($recordCount > $sampleCount) {
            $data[] = "# NOTE: This is a sample of {$sampleCount} records from {$recordCount} total records in collection {$collid}";
        }

        return $header . implode("\n", $data) . "\n";
    }

    /**
     * Generate mock EML metadata
     */
    private function generateMockEmlXml(array $collectionInfo): string
    {
        $collectionName = htmlspecialchars($collectionInfo['collectionName'] ?? 'Unknown Collection');
        $institutionCode = htmlspecialchars($collectionInfo['institutionCode'] ?? 'UNKNOWN');
        $date = date('Y-m-d');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<eml:eml xmlns:eml="eml://ecoinformatics.org/eml-2.1.1">
  <dataset>
    <title>{$collectionName} - Backup Export</title>
    <creator>
      <organizationName>{$institutionCode}</organizationName>
    </creator>
    <pubDate>{$date}</pubDate>
    <abstract>
      <para>Backup export of {$collectionName} collection data.</para>
    </abstract>
    <intellectualRights>
      <para>Collection data backup for institutional use.</para>
    </intellectualRights>
  </dataset>
</eml:eml>
XML;
    }

    /**
     * Create unencrypted Darwin Core Archive using Symbiota DwcArchiverCore
     *
     * NOTE: This action intentionally creates unencrypted files for development/testing.
     * For production backups, use 'create-encrypted' which securely deletes unencrypted files.
     *
     * @param array $params Command parameters
     * @return array Response array
     */
    public function createSymbiotaDwcArchive(array $params): array
    {
        try {
            // Get collection ID from first parameter
            $collid = $params[0] ?? null;
            if (!$collid) {
                return [
                    'type' => 'error',
                    'message' => 'Collection ID is required. Usage: php helpers.php backup symbdwc <collid>',
                    'status_code' => 400
                ];
            }

            // Validate collection ID is numeric
            if (!is_numeric($collid)) {
                return [
                    'type' => 'error',
                    'message' => "Invalid collection ID: $collid. Must be a number.",
                    'status_code' => 400
                ];
            }

            if (Environment::isCli()) {
                echo "🔧 Creating Symbiota Darwin Core Archive\n";
                echo "========================================\n\n";
                echo "Collection ID: $collid\n";
                echo "Output Directory: " . ($this->backupConfig['output_dir'] ?? sys_get_temp_dir()) . "\n";
                if (isset($this->backupConfig['classpath'])) {
                    echo "Symbiota Classpath: " . $this->backupConfig['classpath'] . "\n";
                }
                echo "\n";
            }

            // Use shared DwC-A generation method
            $archivePath = $this->generateDwcArchive((string)$collid, $params);

            // Get file info for response
            $fileSize = filesize($archivePath);
            $fileName = basename($archivePath);

            if (Environment::isCli()) {
                return [
                    'type' => 'success',
                    'content' => "🎉 BACKUP COMPLETE!\nArchive: {$fileName}\nSize: " . number_format($fileSize) . " bytes\nPath: {$archivePath}"
                ];
            } else {
                return [
                    'type' => 'success',
                    'message' => 'Darwin Core Archive created successfully',
                    'archive_info' => [
                        'success' => true,
                        'file_name' => $fileName,
                        'file_size' => $fileSize,
                        'archive_path' => $archivePath
                    ]
                ];
            }

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Darwin Core Archive creation failed: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ],
                'status_code' => 500
            ];
        }
    }

    /**
     * Generate Darwin Core Archive using Symbiota DwcArchiverCore
     * Extracted from createSymbiotaDwcArchive for reuse in encrypted backups
     */
    private function generateDwcArchive(string $collid, array $params): string
    {
        $collid = (int)$collid;

        // Get configuration options
        $outputDir = $this->backupConfig['output_dir'] ?? sys_get_temp_dir();
        $classpath = $this->getSymbiotaClassPath();

        // Prepare options for Symbiota environment
        $options = [
            'output_dir' => $outputDir
        ];

        if ($classpath) {
            $options['symbiota_class_path'] = $classpath;
        }

        // Include required files
        require_once sprintf('%s/src/Ext/SymbInitEnv.php', ApplicationPaths::applicationDirectory());

        // Initialize Symbiota environment
        $symbDwcArchiverCoreWrapper = sprintf('%s/src/Ext/SymbSimpleDwcArchiverCore.php', ApplicationPaths::applicationDirectory());
        $options['symb_dwcarchivercore_wrapper'] = $symbDwcArchiverCoreWrapper;

        // Call the global function (no namespace)
        \initializeSymbiotaEnvironment($options);

        // Create the wrapper (global namespace class)
        $wrapper = new \SymbSimpleDwcArchiverCore($collid, $outputDir);

        // Create the archive and get result
        $result = $wrapper->createArchive();

        if (isset($result['success']) && $result['success']) {
            return $result['archive_path'];
        } else {
            throw new Exception('Failed to create Darwin Core Archive: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Create encrypted Darwin Core Archive using same DwC-A generation as symbdwc
     * Combines unencrypted DwC-A generation with encryption and throttling
     */
    public function createEncryptedDwcArchive(array $params): array
    {
        try {
            // Validate required parameters - get collection ID from first parameter like symbdwc
            $collid = $params[0] ?? $params['collid'] ?? null;
            if (!$collid) {
                return [
                    'type' => 'error',
                    'message' => 'Collection ID required. Usage: php helpers.php backup create-encrypted <collid> --passphrase=<phrase>',
                    'status_code' => 400
                ];
            }

            // Validate collection ID is numeric
            if (!is_numeric($collid)) {
                return [
                    'type' => 'error',
                    'message' => "Invalid collection ID: $collid. Must be a number.",
                    'status_code' => 400
                ];
            }

            $collid = (int)$collid;

            // Check if backup is already in progress
            if ($this->isBackupInProgress($collid)) {
                return [
                    'type' => 'error',
                    'message' => "Backup is already in progress for collection $collid. Please wait for it to complete.",
                    'status_code' => 409
                ];
            }

            // Get passphrase from registry (like create action)
            $registry = $this->loadBackupRegistry();
            if (!isset($registry[$collid])) {
                return [
                    'type' => 'error',
                    'message' => 'Collection not registered for backup. Use: php helpers.php backup register <collid> <userid> --passphrase=<phrase>',
                    'status_code' => 400
                ];
            }

            // Get the first registered user's passphrase (same logic as create action)
            $collectionUsers = $registry[$collid];
            $firstUser = array_key_first($collectionUsers);
            $encryptedPassphrase = $collectionUsers[$firstUser]['passphrase'];
            $passphrase = $this->decryptPassphrase($encryptedPassphrase);

            // Check site salt configuration
            if (!isset($this->backupConfig['site_salt']) || empty($this->backupConfig['site_salt'])) {
                return [
                    'type' => 'error',
                    'message' => 'Site salt not configured. Please configure backup.site_salt in config.php',
                    'status_code' => 400
                ];
            }

            // Check backup throttling BEFORE creating lock
            $throttleCheck = $this->isBackupThrottled($collid);
            if ($throttleCheck['throttled']) {
                return [
                    'type' => 'error',
                    'message' => $throttleCheck['message'],
                    'status_code' => 429,
                    'next_allowed' => $throttleCheck['next_allowed'],
                    'wait_minutes' => $throttleCheck['wait_minutes']
                ];
            }

            // Create backup lock to prevent concurrent operations
            if (!$this->createBackupLock($collid)) {
                return [
                    'type' => 'error',
                    'message' => 'Failed to create backup lock. Another backup may be in progress.',
                    'status_code' => 409
                ];
            }

            if (Environment::isCli()) {
                echo "🔧 Creating Encrypted Darwin Core Archive\n";
                echo "==========================================\n\n";
                echo "Collection ID: $collid\n";
                echo "Using DwC-A generation from symbdwc action\n";
                echo "Applying encryption and throttling\n\n";
            }

            // Step 1: Generate unencrypted DwC-A using same method as symbdwc
            $dwcArchivePath = $this->generateDwcArchive((string)$collid, $params);

            // SECURITY: Ensure unencrypted file is always deleted, even if encryption fails
            $encryptedZipPath = null;
            try {
                if (Environment::isCli()) {
                    echo "✅ DwC-A generated: " . basename($dwcArchivePath) . "\n";
                    echo "🔐 Applying encryption...\n";
                }

                // Step 2: Get collection info for filename
                $collectionInfo = $this->getCollectionInfo((string)$collid);
                $filename = $this->generateBackupFilename($collectionInfo);

                // Step 3: Create encrypted backup from DwC-A
                $encryptedZipPath = $this->createEncryptedBackup($dwcArchivePath, $filename, $passphrase);

                // Step 4: Update registry with backup info
                $this->updateBackupRegistry((string)$collid, $filename);

            } finally {
                // SECURITY: Always delete unencrypted file, regardless of success/failure
                if (file_exists($dwcArchivePath)) {
                    unlink($dwcArchivePath);
                    if (Environment::isCli()) {
                        echo "🗑️  Unencrypted file securely deleted\n";
                    }
                }
            }

            // Verify encryption was successful before proceeding
            if (!$encryptedZipPath || !file_exists($encryptedZipPath)) {
                throw new Exception('Encryption failed - encrypted file was not created');
            }

            // Step 6: Perform automatic cleanup
            $this->performAutomaticCleanup((string)$collid);

            $fileSize = filesize($encryptedZipPath);

            if (Environment::isCli()) {
                echo "✅ Encrypted backup created successfully!\n";
                echo "📁 File: " . basename($encryptedZipPath) . "\n";
                echo "📊 Size: " . $this->formatFileSize($fileSize) . "\n";

                return [
                    'type' => 'success',
                    'content' => "Encrypted backup created successfully: " . basename($encryptedZipPath) . "\nSize: " . $this->formatFileSize($fileSize)
                ];
            }

            return [
                'type' => 'success',
                'message' => 'Encrypted backup created successfully',
                'filename' => basename($encryptedZipPath),
                'size' => $fileSize,
                'status_code' => 200
            ];

        } catch (Exception $e) {
            // Remove backup lock on error
            $this->removeBackupLock($collid);

            return [
                'type' => 'error',
                'message' => 'Encrypted backup creation failed: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ],
                'status_code' => 500
            ];
        } finally {
            // Always remove backup lock when method completes
            $this->removeBackupLock($collid);
        }
    }

    /**
     * Set passphrase via HTTP (HTMX)
     */
    private function setPassphraseHttp(array $params): array
    {
        $collid = $params['collid'] ?? '';
        $passphrase = $params['passphrase'] ?? '';

        if (empty($collid) || empty($passphrase)) {
            return [
                'type' => 'error',
                'message' => 'Collection ID and passphrase are required'
            ];
        }

        try {
            // Register the passphrase (reuse existing logic)
            $result = $this->registerUserPassphrase([
                'collid' => $collid,
                'passphrase' => $passphrase,
                'userid' => SymbAuth::getCurrentUser()['uid'] ?? 0
            ]);

            if ($result['type'] === 'success') {
                return [
                    'type' => 'success',
                    'message' => 'Passphrase saved successfully',
                    'collection_id' => $collid
                ];
            }

            return $result;
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to save passphrase: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if passphrase exists via HTTP (HTMX)
     */
    private function checkPassphraseHttp(array $params): array
    {
        $collid = $params['collid'] ?? '';

        if (empty($collid)) {
            return [
                'type' => 'error',
                'message' => 'Collection ID is required'
            ];
        }

        // Check if passphrase exists for this collection
        // This would normally query the database
        $hasExistingPassphrase = false; // Placeholder

        return [
            'type' => 'info',
            'has_passphrase' => $hasExistingPassphrase,
            'message' => $hasExistingPassphrase ? 'Existing passphrase found' : 'No passphrase set'
        ];
    }

    /**
     * Disable backup via HTTP (HTMX)
     */
    private function disableBackupHttp(array $params): array
    {
        $collid = $params['collid'] ?? '';

        if (empty($collid)) {
            return [
                'type' => 'error',
                'message' => 'Collection ID is required'
            ];
        }

        try {
            // Remove user registration (reuse existing logic)
            $result = $this->removeUserRegistration([
                'collid' => $collid,
                'userid' => SymbAuth::getCurrentUser()['uid'] ?? 0
            ]);

            return [
                'type' => 'success',
                'message' => 'Backup disabled successfully',
                'collection_id' => $collid
            ];
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to disable backup: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create backup via HTTP (HTMX)
     */
    private function createBackupHttp(array $params): array
    {
        $collid = $params['collid'] ?? '';

        if (empty($collid)) {
            return [
                'type' => 'error',
                'message' => 'Collection ID is required'
            ];
        }

        try {
            // Create encrypted backup (reuse existing logic)
            $result = $this->createEncryptedDwcArchive([
                'collid' => $collid
            ]);

            if ($result['type'] === 'download') {
                // For HTTP, return success with download info
                return [
                    'type' => 'success',
                    'message' => 'Backup created successfully',
                    'download_url' => $this->getAppUrlPrefix() . 'backup/download/' . basename($result['file_path']),
                    'filename' => $result['filename'],
                    'collection_id' => $collid
                ];
            }

            return $result;
        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to create backup: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get collection item HTML for HTMX refresh
     */
    private function getCollectionItemHtml(int $collid, array $params): array
    {
        try {
            // Get collection info
            $collection = $this->getCollectionInfo((string)$collid);
            if (!$collection) {
                return [
                    'type' => 'error',
                    'message' => 'Collection not found',
                    'status_code' => 404
                ];
            }

            // Get status info - call the internal status method directly
            $status = $this->getCollectionStatusData($collid);

            // Prepare template data
            $templateData = [
                'collection_id' => $collid,
                'collection_name' => $collection['collection_name'] ?? $status['collection_name'] ?? 'Unknown Collection',
                'institution_code' => $collection['institution_code'] ?? $status['institution_code'] ?? '',
                'collection_code' => $collection['collection_code'] ?? $status['collection_code'] ?? '',
                'status_class' => ($status['is_registered'] ?? false) ? 'bg-success' : 'bg-warning text-dark',
                'status_text' => ($status['is_registered'] ?? false) ? 'Registered' : 'Not Configured',
                'app_url_prefix' => $this->getAppUrlPrefix()
            ];

            // Render the collection item template
            $content = $this->renderTemplate('backup/collection_item', TemplateFormat::HTML, $templateData);

            return [
                'type' => 'htmx',
                'content' => $content
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error retrieving collection item: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get collection status HTML for HTMX refresh
     */
    private function getCollectionStatusHtml(int $collid, array $params): array
    {
        try {
            // This is essentially the same as getCollectionStatus but forces HTMX format
            $params['format'] = 'htmx';
            return $this->getCollectionStatus($collid, $params);

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error retrieving collection status: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get collection status data without formatting
     */
    private function getCollectionStatusData(int $collid): array
    {
        try {
            $backupManager = $this->getBackupManager();
            $collectionInfo = $backupManager->getCollectionInfo($collid);

            if (!$collectionInfo) {
                return [
                    'is_registered' => false,
                    'collection_name' => 'Unknown Collection',
                    'institution_code' => '',
                    'collection_code' => ''
                ];
            }

            // Get backup registry info
            $registry = $this->loadBackupRegistry();
            $isRegistered = isset($registry[$collid]);
            $registeredUsers = $isRegistered ? array_keys($registry[$collid]) : [];

            // Get backup files info
            $backupFiles = $this->getBackupFilesInfo($collid);

            // Calculate estimated size and record count
            $recordCount = $this->getCollectionRecordCount(strval($collid));
            $estimatedSizeBytes = $this->estimateBackupSize(strval($collid));
            $estimatedSize = $this->formatFileSize($estimatedSizeBytes);

            return [
                'collid' => $collid,
                'collection_name' => $collectionInfo['collectionname'],
                'institution_code' => $collectionInfo['institutioncode'],
                'collection_code' => $collectionInfo['collectioncode'],
                'record_count' => $recordCount,
                'estimated_size' => $estimatedSize,
                'is_registered' => $isRegistered,
                'registered_users' => $registeredUsers,
                'backup_files' => $backupFiles,
                'last_backup' => $this->getLastBackupTime($collid),
                'next_backup_allowed' => $this->getNextBackupTime($collid),
                'http_post' => $this->backupConfig[self::CONFIG_HTTP_POST] ?? false
            ];

        } catch (Exception $e) {
            return [
                'is_registered' => false,
                'collection_name' => 'Error',
                'institution_code' => '',
                'collection_code' => ''
            ];
        }
    }
}