<?php
/**
 * Configuration Manager
 *
 * Handles application configuration with component enable/disable functionality.
 *
 * PATHS AND DIRECTORIES:
 * - APPDIR: Application root directory (auto-detected from __DIR__)
 * - SYMBDIR: Symbiota installation directory (parent of APPDIR)
 * - WEBROOT: Web-accessible root directory
 * - SYMBCLIENTURL: Base URL for Symbiota client access
 * - templates/: Template directories for HTML, SQL, CSS, JS, and text templates
 * - cache/: Cache directory for temporary files and data
 * - logs/: Log directory for application logs
 *
 * GLOBAL VARIABLES:
 * - $config: Main configuration array containing all settings
 * - $defaultConfig: Default configuration values
 * - $instance: Singleton instance of Configuration class
 *
 * STATE ENUMS:
 * - Component states: 'enabled', 'disabled', 'development'
 * - Environment modes: 'production', 'development', 'testing'
 * - Database modes: 'readonly', 'readwrite', 'maintenance'
 * - Cache states: 'enabled', 'disabled', 'flush'
 *
 * DEFAULT PARAMETERS:
 * - components.*.enabled: true (components enabled by default)
 * - database.connection_timeout: 30 seconds
 * - cache.default_ttl: 3600 seconds (1 hour)
 * - security.csrf_protection: true
 * - logging.level: 'info'
 * - upload.max_file_size: 10MB
 * - images.per_request: 30
 * - backup.retention_days: 30
 *
 * TEMPLATE LOCATIONS:
 * - HTML templates: templates/html/<component>/
 * - SQL templates: templates/sql/<component>/
 * - CSS templates: templates/css/<component>/
 * - JS templates: templates/js/<component>/
 * - Text templates: templates/text/<component>/
 *
 * DATABASE PARAMETERS:
 * - host: Database server hostname
 * - port: Database server port (default: 3306)
 * - username: Database username
 * - password: Database password
 * - database: Database name
 * - charset: Character set (default: utf8mb4)
 * - engine: Database engine (default: InnoDB)
 * - connection_timeout: Connection timeout in seconds
 * - read_timeout: Read timeout in seconds
 * - write_timeout: Write timeout in seconds
 *
 * SECURITY SETTINGS:
 * - csrf_protection: Enable/disable CSRF protection
 * - session_security: Session security level
 * - password_hashing: Password hashing algorithm
 * - encryption_key: Application encryption key
 * - ssl_required: Require SSL connections
 * - rate_limiting: API rate limiting settings
 * - input_validation: Input validation rules
 * - file_upload_restrictions: File upload security restrictions
 * - authentication_timeout: Authentication session timeout
 * - password_complexity: Password complexity requirements
 */

namespace Symbiota\Helpers\Core;

final class Configuration
{
    private array $config = [];
    private array $defaultConfig = [];
    private static ?Configuration $instance = null;

    public function __construct($config = [])
    {
        $this->defaultConfig = $this->getDefaultConfiguration();

        if (is_string($config)) {
            // Load configuration using secure methods
            $config = $this->loadSecureConfiguration($config);
        } elseif (!is_array($config)) {
            $config = [];
        }

        // Apply environment variable overrides
        $config = $this->applyEnvironmentOverrides($config);

        // Store path variables for debugging/reference
        $pathVariables = $this->getPathVariables();
        $config['_internal'] = [
            'appdir' => $pathVariables['APPDIR'],
            'symbdir' => $pathVariables['SYMBDIR'],
            'webroot' => $pathVariables['WEBROOT'],
            'symbclienturl' => $pathVariables['SYMBCLIENTURL'],
            'symbtempdirroot' => $pathVariables['SYMBTEMPDIRROOT']
        ];

        $this->config = $this->mergeConfigArrays($this->defaultConfig, $config);

        // Set as singleton instance
        self::$instance = $this;
    }

    /**
     * Get the singleton instance
     */
    public static function getInstance(): ?Configuration
    {
        return self::$instance;
    }

    /**
     * Initialize the global configuration (called from index.php)
     */
    public static function initialize($config = []): Configuration
    {
        if (self::$instance === null) {
            // Convert dot-notation keys to nested arrays if needed
            if (is_array($config)) {
                $config = self::convertDotNotationToNested($config);
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Convert dot-notation keys to nested arrays
     */
    private static function convertDotNotationToNested(array $config): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (strpos($key, '.') !== false) {
                // This is a dot-notation key, convert it
                $keys = explode('.', $key);
                $current = &$result;

                foreach ($keys as $k) {
                    if (!isset($current[$k]) || !is_array($current[$k])) {
                        $current[$k] = [];
                    }
                    $current = &$current[$k];
                }

                // Set the final value
                $current = $value;
            } else {
                // Regular key, just copy it
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Reset the singleton instance (for testing purposes)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Load configuration using secure methods
     */
    private function loadSecureConfiguration(string $configPath): array
    {
        // Method 1: Try to load as PHP configuration file first (most secure)
        if (str_ends_with($configPath, '.php')) {
            return $this->loadFromPhpFile($configPath);
        }

        // Method 2: Check if it's a reference file pointing to external config
        if (file_exists($configPath) && filesize($configPath) < 1024) {
            $content = trim(file_get_contents($configPath));
            if (str_starts_with($content, 'source:')) {
                $externalPath = trim(substr($content, 7));
                return $this->loadSecureConfiguration($externalPath);
            }
        }

        // Method 3: Load from INI file (less secure, but supported)
        return $this->loadFromIniFile($configPath);
    }

    /**
     * Load configuration from PHP file (most secure)
     */
    private function loadFromPhpFile(string $phpFile): array
    {
        if (!file_exists($phpFile)) {
            return [];
        }

        // Ensure it's a PHP file to prevent arbitrary file inclusion
        if (!str_ends_with($phpFile, '.php')) {
            throw new \RuntimeException("PHP configuration file must have .php extension: {$phpFile}");
        }

        // Include the PHP file and capture variables
        $CONFIG = null;
        $result = include $phpFile;

        // If the file returns an array, use it directly (legacy format)
        if (is_array($result)) {
            return $result;
        }

        // Check for $CONFIG variable with INI heredoc (new format)
        // The variable should be defined in the included file's scope
        if (isset($CONFIG) && is_string($CONFIG)) {
            return $this->parseIniString($CONFIG);
        }

        // If $CONFIG wasn't captured, try to extract it from the file directly
        // This handles cases where the variable isn't in the global scope
        $fileContent = file_get_contents($phpFile);
        if (preg_match('/\$CONFIG\s*=\s*<<<\s*INI\s*\n(.*?)\nINI;/s', $fileContent, $matches)) {
            return $this->parseIniString($matches[1]);
        }

        // If no valid configuration found, throw error
        throw new \RuntimeException("PHP configuration file must return an array or define \$CONFIG variable: {$phpFile}");
    }

    /**
     * Load configuration from INI file
     */
    private function loadFromIniFile(string $iniFile): array
    {
        if (!file_exists($iniFile)) {
            return []; // Return empty array if file doesn't exist, will use defaults
        }

        $iniContent = file_get_contents($iniFile);
        if ($iniContent === false) {
            throw new \RuntimeException("Failed to read INI file: {$iniFile}");
        }

        return $this->parseIniString($iniContent);
    }

    /**
     * Parse INI string with namespace prefix support
     */
    private function parseIniString(string $iniContent): array
    {
        // Process environment variable substitutions
        $iniContent = $this->processEnvironmentVariables($iniContent);

        // Strip // comments (convert to ; comments for INI compatibility)
        $iniContent = $this->stripDoubleSlashComments($iniContent);

        $iniData = parse_ini_string($iniContent, true);
        if ($iniData === false) {
            throw new \RuntimeException("Failed to parse INI content");
        }

        return $this->processNamespacedIni($iniData);
    }

    /**
     * Strip // comments from INI content (convert to ; comments)
     * This allows using // for inline comments which is more familiar to developers
     */
    private function stripDoubleSlashComments(string $iniContent): string
    {
        $lines = explode("\n", $iniContent);
        $result = [];

        foreach ($lines as $line) {
            // Find // outside of quoted strings
            $inQuotes = false;
            $quoteChar = null;
            $commentPos = false;

            for ($i = 0; $i < strlen($line); $i++) {
                $char = $line[$i];

                // Track quote state
                if (($char === '"' || $char === "'") && ($i === 0 || $line[$i - 1] !== '\\')) {
                    if (!$inQuotes) {
                        $inQuotes = true;
                        $quoteChar = $char;
                    } elseif ($char === $quoteChar) {
                        $inQuotes = false;
                        $quoteChar = null;
                    }
                }

                // Find // outside quotes
                if (!$inQuotes && $i < strlen($line) - 1 && $line[$i] === '/' && $line[$i + 1] === '/') {
                    $commentPos = $i;
                    break;
                }
            }

            // If we found a // comment, convert it to ; comment
            if ($commentPos !== false) {
                $beforeComment = substr($line, 0, $commentPos);
                $comment = substr($line, $commentPos + 2); // Skip the //
                $result[] = $beforeComment . ';' . $comment;
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Process environment variable substitutions and path variables in INI content
     */
    private function processEnvironmentVariables(string $iniContent): string
    {
        // First, replace {{ _ENV['VAR'] }} with actual environment values
        $iniContent = preg_replace_callback('/\{\{\s*_ENV\[\'([^\']+)\'\]\s*\}\}/', function($matches) {
            $envVar = $matches[1];
            $value = $_ENV[$envVar] ?? '';
            return $value;
        }, $iniContent);

        // Then, replace path variables {APPDIR}, {SYMBDIR}, {WEBROOT}
        $pathVariables = $this->getPathVariables();
        foreach ($pathVariables as $variable => $value) {
            $iniContent = str_replace('{' . $variable . '}', $value, $iniContent);
        }

        return $iniContent;
    }

    /**
     * Get available path variables for INI substitution
     */
    private function getPathVariables(): array
    {
        $variables = [];

        // {APPDIR} - Application directory (where index.php/helpers.php is located)
        $variables['APPDIR'] = $this->detectApplicationDirectory();

        // {SYMBDIR} - Symbiota portal directory (if detected)
        $variables['SYMBDIR'] = $this->detectSymbiotaDirectory();

        // {WEBROOT} - Web server document root
        $variables['WEBROOT'] = $this->detectWebRoot();

        // {SYMBCLIENTURL} - Calculated relative path from web root to Symbiota client
        $variables['SYMBCLIENTURL'] = $this->calculateSymbiotaClientUrl($variables['SYMBDIR'], $variables['WEBROOT']);

        // {SYMBTEMPDIRROOT} - Symbiota $TEMP_DIR_ROOT from symbini.php
        $variables['SYMBTEMPDIRROOT'] = $this->extractSymbiotaTempDir($variables['SYMBDIR']);

        return $variables;
    }

    /**
     * Get application directory (public method)
     */
    public function getAppDirectory(): string
    {
        return $this->detectApplicationDirectory();
    }

    /**
     * Detect application directory
     */
    private function detectApplicationDirectory(): string
    {
        // Use the directory where the main script is located
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (!empty($scriptPath)) {
            $appDir = dirname(realpath($scriptPath));
            if ($appDir && is_dir($appDir)) {
                return $appDir;
            }
        }

        // Fallback to current working directory
        return getcwd() ?: '/var/www/html';
    }

    /**
     * Detect Symbiota portal directory
     */
    public function detectSymbiotaDirectory(): string
    {
        $appDir = $this->detectApplicationDirectory();

        // Common Symbiota portal locations relative to app
        $possiblePaths = [
            dirname($appDir),                    // Parent directory
            dirname($appDir) . '/portal',        // Sibling portal directory
            $appDir . '/../portal',              // Relative portal
            '/var/www/html/portal',              // Standard portal location
            '/var/www/portal',                   // Alternative portal location
        ];

        foreach ($possiblePaths as $path) {
            $realPath = realpath($path);
            if ($realPath && is_dir($realPath)) {
                // Check if it looks like a Symbiota portal (has config directory)
                if (is_dir($realPath . '/config') || file_exists($realPath . '/config/dbconnection.php')) {
                    return $realPath;
                }
            }
        }

        // If no Symbiota directory found, return app directory as fallback
        return $appDir;
    }

    /**
     * Detect web server document root
     */
    private function detectWebRoot(): string
    {
        // Try to get document root from server variables
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot) && is_dir($docRoot)) {
            return realpath($docRoot);
        }

        // Fallback to common web root locations
        $commonRoots = [
            '/var/www/html',
            '/var/www',
            '/usr/share/nginx/html',
            '/home/*/public_html'
        ];

        foreach ($commonRoots as $root) {
            if (is_dir($root)) {
                return realpath($root);
            }
        }

        // Final fallback
        return '/var/www/html';
    }

    /**
     * Calculate Symbiota client URL from the difference between SYMBDIR and WEBROOT
     *
     * @param string $symbDir Full path to Symbiota installation directory
     * @param string $webRoot Full path to web server document root
     * @return string Relative URL path from web root to Symbiota client
     */
    private function calculateSymbiotaClientUrl(string $symbDir, string $webRoot): string
    {
        // Handle empty or invalid paths
        if (empty($symbDir) || empty($webRoot)) {
            return '';
        }

        // Normalize paths (remove trailing slashes, resolve symlinks)
        $symbDir = rtrim(realpath($symbDir) ?: $symbDir, '/');
        $webRoot = rtrim(realpath($webRoot) ?: $webRoot, '/');

        // If SYMBDIR is not under WEBROOT, return empty (not web accessible)
        if (strpos($symbDir, $webRoot) !== 0) {
            return '';
        }

        // Calculate relative path
        $relativePath = substr($symbDir, strlen($webRoot));

        // Remove leading slash and ensure proper URL format
        $relativePath = ltrim($relativePath, '/');

        // Return as URL path (with leading slash for absolute URL reference)
        return empty($relativePath) ? '/' : '/' . $relativePath;
    }

    /**
     * Extract $TEMP_DIR_ROOT from Symbiota's symbini.php
     *
     * Uses regex parsing to extract the temp directory path without executing symbini.php.
     * This avoids interfering with session management and global state.
     *
     * Supports multiple patterns:
     * - Direct assignment: $TEMP_DIR_ROOT = '/path';
     * - Constant-based: define('__TEMPDIRROOT__', '/path'); $TEMP_DIR_ROOT = __TEMPDIRROOT__;
     *
     * @param string $symbDir Full path to Symbiota installation directory
     * @return string Path to temp directory, or empty string if not found
     */
    private function extractSymbiotaTempDir(string $symbDir): string
    {
        if (empty($symbDir)) {
            return '';
        }

        $symbiniPath = $symbDir . '/config/symbini.php';
        if (!file_exists($symbiniPath)) {
            return '';
        }

        try {
            $content = file_get_contents($symbiniPath);
            if ($content === false) {
                return '';
            }

            // Strategy 1: Match direct assignment: $TEMP_DIR_ROOT = '/var/www/temp/symb';
            // Handles both single and double quotes
            if (preg_match('/\$TEMP_DIR_ROOT\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                return $matches[1];
            }

            // Strategy 2: Match constant-based assignment: $TEMP_DIR_ROOT = __TEMPDIRROOT__;
            // First, check if it's assigned from a constant
            if (preg_match('/\$TEMP_DIR_ROOT\s*=\s*__TEMPDIRROOT__/', $content)) {
                // Then extract the constant definition: define('__TEMPDIRROOT__', '/var/www/temp/symb');
                if (preg_match('/define\s*\(\s*[\'"]__TEMPDIRROOT__[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    return $matches[1];
                }
            }

            return '';
        } catch (\Exception $e) {
            error_log("Failed to extract TEMP_DIR_ROOT from symbini.php: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Resolve path with variable substitution and absolute/relative detection
     *
     * @param string $path Path that may contain variables and be relative or absolute
     * @return string Resolved absolute path
     */
    public function resolvePath(string $path): string
    {
        // First, substitute path variables
        $pathVariables = $this->getPathVariables();
        foreach ($pathVariables as $variable => $value) {
            $path = str_replace('{' . $variable . '}', $value, $path);
        }

        // Check if path is absolute (starts with /)
        if (strpos($path, '/') === 0) {
            return $path; // Already absolute
        }

        // Check if path starts with a letter (relative path)
        if (preg_match('/^[a-zA-Z]/', $path)) {
            // Relative path - resolve relative to application directory
            $appDir = $this->detectApplicationDirectory();
            return $appDir . '/' . $path;
        }

        // If path doesn't match expected patterns, treat as relative
        $appDir = $this->detectApplicationDirectory();
        return $appDir . '/' . $path;
    }

    /**
     * Process namespaced INI sections (e.g., [app.database], [mod.backup])
     */
    private function processNamespacedIni(array $iniData): array
    {
        $config = [];

        foreach ($iniData as $section => $values) {
            // Convert INI string values to appropriate types
            $values = $this->convertIniValues($values);

            if (strpos($section, '.') !== false) {
                // Handle namespaced sections like [app.database] or [mod.backup]
                $parts = explode('.', $section, 2);
                $namespace = $parts[0];
                $subsection = $parts[1];

                if ($namespace === 'mod') {
                    // Module configuration goes under 'components'
                    if (!isset($config['components'])) {
                        $config['components'] = [];
                    }
                    if (!isset($config['components'][$subsection])) {
                        $config['components'][$subsection] = [];
                    }

                    // Handle disable attribute
                    if (isset($values['disable']) && $values['disable']) {
                        if (!isset($config['app'])) {
                            $config['app'] = [];
                        }
                        if (!isset($config['app']['disabled_components'])) {
                            $config['app']['disabled_components'] = [];
                        }
                        $config['app']['disabled_components'][] = $subsection;
                    }
                    unset($values['disable']); // Remove disable from component config

                    // Keep registry_file as is (new format)
                    $config['components'][$subsection] = array_merge($config['components'][$subsection], $values);
                } else {
                    // App configuration
                    if (!isset($config[$namespace])) {
                        $config[$namespace] = [];
                    }
                    if (!isset($config[$namespace][$subsection])) {
                        $config[$namespace][$subsection] = [];
                    }
                    $config[$namespace][$subsection] = array_merge($config[$namespace][$subsection], $values);
                }
            } else {
                // Handle legacy sections without namespace
                if (in_array($section, ['backup', 'genbank', 'images', 'taxonomy-report', 'upload'])) {
                    // Legacy module sections
                    if (!isset($config['components'])) {
                        $config['components'] = [];
                    }
                    if (!isset($config['components'][$section])) {
                        $config['components'][$section] = [];
                    }

                    // Rename data_file to registry_file for new format consistency
                    if (isset($values['data_file'])) {
                        $values['registry_file'] = $values['data_file'];
                        unset($values['data_file']);
                    }

                    $config['components'][$section] = array_merge($config['components'][$section], $values);
                } else {
                    // App-level sections
                    if (!isset($config[$section])) {
                        $config[$section] = [];
                    }
                    $config[$section] = array_merge($config[$section], $values);
                }
            }
        }

        return $config;
    }

    /**
     * Convert INI string values to appropriate PHP types
     */
    private function convertIniValues(array $values): array
    {
        $converted = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $converted[$key] = $this->convertIniValues($value);
            } else {
                $converted[$key] = $this->convertIniValue($value);
            }
        }

        return $converted;
    }

    /**
     * Convert a single INI value to appropriate PHP type
     */
    private function convertIniValue(string $value)
    {
        // Handle boolean values
        $lowerValue = strtolower(trim($value));
        if (in_array($lowerValue, ['true', 'on', 'yes', '1'])) {
            return true;
        }
        if (in_array($lowerValue, ['false', 'off', 'no', '0', ''])) {
            return false;
        }

        // Handle numeric values
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            } else {
                return (int) $value;
            }
        }

        // Return as string (remove quotes if present)
        return trim($value, '"\'');
    }

    /**
     * Apply environment variable overrides
     */
    private function applyEnvironmentOverrides(array $config): array
    {
        // Override disabled components from environment
        $envDisabled = getenv('SYMBIOTA_DISABLED_COMPONENTS');
        if ($envDisabled !== false) {
            $disabledComponents = array_filter(array_map('trim', explode(',', $envDisabled)));
            if (!empty($disabledComponents)) {
                $config['app']['disabled_components'] = $disabledComponents;
            }
        }

        // Override database connection from environment
        $envDbConnection = getenv('SYMBIOTA_DB_CONNECTION');
        if ($envDbConnection !== false) {
            $config['database']['dbconnection'] = $envDbConnection;
        }

        // Override debug mode from environment
        $envDebug = getenv('SYMBIOTA_DEBUG');
        if ($envDebug !== false) {
            $config['app']['debug'] = filter_var($envDebug, FILTER_VALIDATE_BOOLEAN);
        }

        return $config;
    }

    /**
     * Get configuration value
     */
    public function get(string $key, $default = null)
    {
        return $this->getNestedValue($this->config, $key, $default);
    }

    /**
     * Check if testing environment is configured
     */
    public function hasTestingEnvironment(): bool
    {
        return !empty($this->get('app.debug.testing_symbiota_dbconnection'));
    }

    /**
     * Check if testing environment should be offline
     */
    public function isTestingOffline(): bool
    {
        if (!$this->hasTestingEnvironment()) {
            return true; // No testing config = offline
        }

        $dbConnectionPath = $this->getTestingDbConnectionPath();
        return $dbConnectionPath === null;
    }

    /**
     * Get configuration state phase
     */
    public function getConfigurationState(): string
    {
        // cfTesting - testing_ attributes enabled in config.php
        if ($this->hasTestingEnvironment()) {
            return 'cfTesting';
        }

        // cfProduction - Symbiota environment detected
        if ($this->isSymbiotaEnvironmentDetected()) {
            return 'cfProduction';
        }

        // cfStatic - no database/Symbiota, functional tests only
        return 'cfStatic';
    }

    /**
     * Check if Symbiota production environment is detected
     */
    public function isSymbiotaEnvironmentDetected(): bool
    {
        $symbiotaDir = $this->detectSymbiotaDirectory();
        if (!$symbiotaDir) {
            return false;
        }

        // Check for required Symbiota files
        $requiredFiles = [
            $symbiotaDir . '/config/dbconnection.php',
            $symbiotaDir . '/config/symbini.php',
        ];

        $requiredDirs = [
            $symbiotaDir . '/classes',
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                return false;
            }
        }

        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get production database connection path
     */
    public function getProductionDbConnectionPath(): ?string
    {
        $symbiotaDir = $this->detectSymbiotaDirectory();
        if (!$symbiotaDir) {
            return null;
        }

        // Check standard location
        $dbConnectionPath = $symbiotaDir . '/config/dbconnection.php';
        if (file_exists($dbConnectionPath)) {
            return $dbConnectionPath;
        }

        // Check alternative locations
        $alternativePaths = [
            $symbiotaDir . '/dbconnection.php',
            $symbiotaDir . '/dev/dbconnection.php'
        ];

        foreach ($alternativePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get testing database connection path
     */
    public function getTestingDbConnectionPath(): ?string
    {
        $testingDbConnection = $this->get('app.debug.testing_symbiota_dbconnection');
        if (empty($testingDbConnection)) {
            return null;
        }

        // Build absolute path from current working directory
        $absolutePath = realpath($testingDbConnection);
        if ($absolutePath && file_exists($absolutePath)) {
            return $absolutePath;
        }

        // Try relative to application directory
        $appDir = $this->getPathVariables()['APPDIR'];
        $relativePath = $appDir . '/' . $testingDbConnection;
        if (file_exists($relativePath)) {
            return realpath($relativePath);
        }

        return null;
    }

    /**
     * Get testing Symbiota source path
     */
    public function getTestingSymbiotaSource(): ?string
    {
        $testingSource = $this->get('app.debug.testing_symbiota_source');
        if (empty($testingSource)) {
            return null;
        }

        // Build absolute path from current working directory
        $absolutePath = realpath($testingSource);
        if ($absolutePath && is_dir($absolutePath)) {
            return $absolutePath;
        }

        // Try relative to application directory
        $appDir = $this->getPathVariables()['APPDIR'];
        $relativePath = $appDir . '/' . $testingSource;
        if (is_dir($relativePath)) {
            return realpath($relativePath);
        }

        return null;
    }

    /**
     * Get testing Symbiota class path
     */
    public function getTestingSymbiotaClassPath(): ?string
    {
        // Use the new testing_symbiota_classpath configuration
        $testingClassPath = $this->get('app.debug.testing_symbiota_classpath');
        if (!empty($testingClassPath)) {
            $absolutePath = realpath($testingClassPath);
            if ($absolutePath && is_dir($absolutePath) && file_exists($absolutePath . '/DwcArchiverCore.php')) {
                return $absolutePath;
            }
        }

        // Fallback to old method for backward compatibility
        $sourcePath = $this->getTestingSymbiotaSource();
        if (!$sourcePath) {
            return null;
        }

        $classPath = $sourcePath . '/classes';
        if (is_dir($classPath) && file_exists($classPath . '/DwcArchiverCore.php')) {
            return $classPath;
        }

        return null;
    }

    /**
     * Get production Symbiota class path (auto-detected)
     */
    public function getProductionSymbiotaClassPath(): ?string
    {
        $symbiotaDir = $this->detectSymbiotaDirectory();
        if (!$symbiotaDir) {
            return null;
        }

        $classPath = $symbiotaDir . '/classes';
        if (is_dir($classPath) && file_exists($classPath . '/DwcArchiverCore.php')) {
            return $classPath;
        }

        return null;
    }

    /**
     * Get appropriate Symbiota class path based on configuration state
     */
    public function getSymbiotaClassPath(): ?string
    {
        $state = $this->getConfigurationState();

        switch ($state) {
            case 'cfTesting':
                return $this->getTestingSymbiotaClassPath();
            case 'cfProduction':
                return $this->getProductionSymbiotaClassPath();
            case 'cfStatic':
            default:
                return null; // No Symbiota classes available
        }
    }

    /**
     * Get testing Symbiota symbini path
     */
    public function getTestingSymbiniPath(): ?string
    {
        $testingSymbini = $this->get('app.debug.testing_symbiota_symbini');
        if (empty($testingSymbini)) {
            return null;
        }

        // Build absolute path from current working directory
        $absolutePath = realpath($testingSymbini);
        if ($absolutePath && file_exists($absolutePath)) {
            return $absolutePath;
        }

        // Try relative to application directory
        $appDir = $this->getPathVariables()['APPDIR'];
        $relativePath = $appDir . '/' . $testingSymbini;
        if (file_exists($relativePath)) {
            return realpath($relativePath);
        }

        return null;
    }

    /**
     * Set configuration value
     */
    public function set(string $key, $value): void
    {
        $this->setNestedValue($this->config, $key, $value);
    }
    
    /**
     * Check if component is enabled
     */
    public function isComponentEnabled(string $component): bool
    {
        return $this->get("components.{$component}.enabled", false);
    }
    
    /**
     * Enable component
     */
    public function enableComponent(string $component): void
    {
        $this->set("components.{$component}.enabled", true);
    }
    
    /**
     * Disable component
     */
    public function disableComponent(string $component): void
    {
        $this->set("components.{$component}.enabled", false);
    }

    /**
     * Check if component is disabled for HTTP access
     */
    public function isComponentDisabledForHttp(string $component): bool
    {
        $disabledComponents = $this->get('app.disabled_components', []);
        return in_array($component, $disabledComponents);
    }

    /**
     * Disable component for HTTP access only (CLI remains available)
     */
    public function disableComponentForHttp(string $component): void
    {
        $disabledComponents = $this->get('app.disabled_components', []);
        if (!in_array($component, $disabledComponents)) {
            $disabledComponents[] = $component;
            $this->set('app.disabled_components', $disabledComponents);
        }
    }

    /**
     * Enable component for HTTP access
     */
    public function enableComponentForHttp(string $component): void
    {
        $disabledComponents = $this->get('app.disabled_components', []);
        $disabledComponents = array_filter($disabledComponents, fn($c) => $c !== $component);
        $this->set('app.disabled_components', array_values($disabledComponents));
    }

    /**
     * Get list of components disabled for HTTP access
     */
    public function getDisabledHttpComponents(): array
    {
        return $this->get('app.disabled_components', []);
    }
    
    /**
     * Get component configuration
     */
    public function getComponentConfig(string $component): array
    {
        return $this->get("components.{$component}", []);
    }
    
    /**
     * Validate entire configuration
     */
    public function validate(): array
    {
        $errors = [];
        
        // Validate security settings
        if (!$this->get('security.require_authentication', true)) {
            $errors[] = 'Authentication should be required for security';
        }
        
        // Validate file paths are outside web space
        $storagePath = $this->get('storage.base_path');
        if ($storagePath && $this->isPathInWebSpace($storagePath)) {
            $errors[] = 'Storage path must be outside web space for security';
        }
        
        return $errors;
    }
    
    /**
     * Get all configuration
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Get example configurations for different security levels
     */
    public static function getExampleConfigurations(): array
    {
        return [
            'php' => self::getExamplePhpConfig(),
            'reference' => self::getExampleReferenceConfig(),
            'ini' => self::getExampleIniConfig(),
            'environment' => self::getExampleEnvironmentConfig()
        ];
    }

    /**
     * Get example INI configuration (legacy method)
     */
    public static function getExampleIni(): string
    {
        return self::getExampleIniConfig();
    }

    /**
     * Get example PHP configuration (most secure)
     */
    public static function getExamplePhpConfig(): string
    {
        return <<<'PHP'
<?php
/**
 * Symbiota Portal Helpers v2.0 - Secure Configuration
 *
 * This PHP configuration file is the most secure option as it:
 * - Cannot be accessed directly via HTTP (returns PHP code, not config data)
 * - Supports complex data structures and logic
 * - Can include environment variable checks
 * - Allows conditional configuration
 *
 * Save as: config/secure-config.php (outside web root)
 * Usage: php index.php -c config/secure-config.php
 */

return [
    'app' => [
        'name' => 'Symbiota Portal Helpers',
        'version' => '2.0.0',
        'debug' => false,

        // Disable specific components for HTTP access (CLI access remains available)
        'disabled_components' => [
            'backup',    // Disable backup component for web access
            // 'upload',    // Uncomment to disable upload component
            // 'genbank',   // Uncomment to disable genbank component
        ],
    ],

    'database' => [
        // Use environment variable or fallback to relative path
        'dbconnection' => $_ENV['SYMBIOTA_DB_CONNECTION'] ?? '../config/dbconnection.php',
        'timeout' => 30,
        'read_only' => true,
    ],

    'security' => [
        'require_authentication' => true,
        'api_keys_enabled' => true,
        'rate_limiting' => true,
    ],

    'storage' => [
        'base_path' => $_ENV['SYMBIOTA_STORAGE_PATH'] ?? '/var/temp/helpers',
        'working_path' => $_ENV['SYMBIOTA_WORKING_PATH'] ?? '/var/temp/helpers_work',
    ],
];
PHP;
    }

    /**
     * Get example reference configuration
     */
    public static function getExampleReferenceConfig(): string
    {
        return <<<'REF'
source: /etc/symbiota/secure-config.ini
REF;
    }

    /**
     * Get example INI configuration (less secure - avoid in production)
     */
    public static function getExampleIniConfig(): string
    {
        return <<<'INI'
; Symbiota Portal Helpers v2.0 Configuration
;
; Path Variables Available:
; {APPDIR}         - Application directory (where index.php/helpers.php is located)
; {SYMBDIR}        - Symbiota portal directory (auto-detected)
; {WEBROOT}        - Web server document root
; {SYMBCLIENTURL}  - Relative URL path from web root to Symbiota client (calculated from SYMBDIR - WEBROOT)
; {SYMBTEMPDIRROOT} - Symbiota $TEMP_DIR_ROOT from symbini.php (auto-extracted)
;
; Path Types:
; /absolute/path - Absolute paths start with /
; relative/path  - Relative paths start with letter, resolved from {APPDIR}
;
; WARNING: INI files in web space are a security risk!
; Consider using PHP config files or external references instead.
;
; Use config.php instead of INI files for security

[app]
name = "Symbiota Portal Helpers"
version = "2.0.0"
debug = false

; Disable specific components for HTTP access (CLI access remains available)
; Uncomment and add component IDs to disable them for web interface
; disabled_components[] = "backup"
; disabled_components[] = "genbank"

[database]
; Use path variables for flexible configuration
; Relative path (resolved from {APPDIR})
dbconnection = "config/dbconnection.php"
; Or use Symbiota portal path
; dbconnection = "{SYMBDIR}/config/dbconnection.php"
; Or absolute path
; dbconnection = "/etc/symbiota/dbconnection.php"
timeout = 30
read_only = true

[security]
require_authentication = false
api_keys_enabled = false
rate_limiting = false

[storage]
; Relative paths (resolved from {APPDIR})
base_path = "storage"
working_path = "storage/work"
; Or use absolute paths outside web space
; base_path = "/var/temp/helpers"
; working_path = "/var/temp/helpers_work"
; Or use path variables
; base_path = "{APPDIR}/../storage"
; working_path = "{SYMBDIR}/temp"

[mod.backup]
; Example backup configuration with path variables
; output_dir = "{APPDIR}/backups"           ; Relative to app
; output_dir = "{SYMBDIR}/temp/backups"     ; In Symbiota temp
; output_dir = "/var/backups/symbiota"      ; Absolute path
output_dir = "backups"
registry_file = "backup_registry.json"
threshold_hours = 24
retention_days = 7
http_post = false
INI;
    }

    /**
     * Get example environment configuration
     */
    public static function getExampleEnvironmentConfig(): string
    {
        return <<<'ENV'
# Symbiota Portal Helpers v2.0 - Environment Configuration
#
# Set these environment variables to override configuration settings
# This is the most secure method for production deployments
#
# Usage examples:
#   export SYMBIOTA_DISABLED_COMPONENTS="backup,upload"
#   php index.php genbank occid 12345
#
#   SYMBIOTA_DEBUG=true php index.php --test-config

# Disable components for HTTP access (comma-separated list)
SYMBIOTA_DISABLED_COMPONENTS=backup,upload

# Database connection file path
SYMBIOTA_DB_CONNECTION=/etc/symbiota/dbconnection.php

# Debug mode (true/false)
SYMBIOTA_DEBUG=false

# Storage paths
SYMBIOTA_STORAGE_PATH=/var/temp/helpers
SYMBIOTA_WORKING_PATH=/var/temp/helpers_work

# Database connection URI (alternative to dbconnection file)
# SYMBIOTA_DB_URI=mysql://user:pass@localhost/symbiota_db
ENV;
    }

    /**
     * Test configuration and file system access
     */
    public function testConfiguration(): array
    {
        $results = [];

        // Test data directory access
        $dataPath = $this->get('storage.data_path', '/var/temp/data');
        $results['data_directory'] = $this->testDirectoryAccess($dataPath, 'Data Directory');

        // Test base storage path
        $basePath = $this->get('storage.base_path', '/var/temp/helpers');
        $results['base_storage'] = $this->testDirectoryAccess($basePath, 'Base Storage');

        // Test working directory
        $workingPath = $this->get('storage.working_path', '/var/temp/helpers_work');
        $results['working_directory'] = $this->testDirectoryAccess($workingPath, 'Working Directory');

        // Test if paths are outside web space
        $results['security_check'] = $this->testSecurityPaths();

        return $results;
    }

    /**
     * Test directory access (read/write permissions)
     */
    private function testDirectoryAccess(string $path, string $name): array
    {
        $result = [
            'name' => $name,
            'path' => $path,
            'exists' => false,
            'readable' => false,
            'writable' => false,
            'status' => 'fail',
            'message' => ''
        ];

        if (!file_exists($path)) {
            $result['message'] = "Directory does not exist: {$path}";
            return $result;
        }

        $result['exists'] = true;

        if (!is_dir($path)) {
            $result['message'] = "Path exists but is not a directory: {$path}";
            return $result;
        }

        $result['readable'] = is_readable($path);
        $result['writable'] = is_writable($path);

        if (!$result['readable']) {
            $result['message'] = "Directory is not readable: {$path}";
            return $result;
        }

        if (!$result['writable']) {
            $result['message'] = "Directory is not writable: {$path}";
            return $result;
        }

        // Test actual write access
        $testFile = $path . '/test_write_' . uniqid() . '.tmp';
        if (@file_put_contents($testFile, 'test') === false) {
            $result['message'] = "Cannot write to directory: {$path}";
            return $result;
        }

        @unlink($testFile);

        $result['status'] = 'pass';
        $result['message'] = "Directory is accessible and writable";
        return $result;
    }

    /**
     * Test that storage paths are outside web space
     */
    private function testSecurityPaths(): array
    {
        $result = [
            'name' => 'Security Path Check',
            'status' => 'pass',
            'message' => 'All storage paths are outside web space',
            'warnings' => []
        ];

        // Get document root if available
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (empty($docRoot)) {
            $result['warnings'][] = 'Cannot determine document root - unable to verify paths are outside web space';
            return $result;
        }

        $docRoot = realpath($docRoot);
        $paths = [
            'data_path' => $this->get('storage.data_path', '/var/temp/data'),
            'base_path' => $this->get('storage.base_path', '/var/temp/helpers'),
            'working_path' => $this->get('storage.working_path', '/var/temp/helpers_work')
        ];

        foreach ($paths as $name => $path) {
            $realPath = realpath($path);
            if ($realPath && strpos($realPath, $docRoot) === 0) {
                $result['status'] = 'fail';
                $result['message'] = "SECURITY RISK: Storage paths are inside web space";
                $result['warnings'][] = "{$name} ({$path}) is inside document root ({$docRoot})";
            }
        }

        return $result;
    }

    /**
     * Load configuration from file
     */
    public static function fromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Configuration file not found: {$filePath}");
        }
        
        $config = require $filePath;
        if (!is_array($config)) {
            throw new \RuntimeException("Configuration file must return an array");
        }
        
        return new self($config);
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfiguration(): array
    {
        return [
            'app' => [
                'name' => 'Symbiota Portal Helpers',
                'version' => '2.0.0',
                'debug' => false,
                'disabled_components' => []  // Array of component IDs to disable for HTTP access
            ],
            'security' => [
                'require_authentication' => true,
                'api_keys_enabled' => true,
                'rate_limiting' => true
            ],
            'storage' => [
                'base_path' => '/var/temp/helpers',
                'working_path' => '/var/temp/helpers_work'
            ],
            'performance' => [
                'memory_limit' => '512M',
                'max_execution_time' => 0
            ],
            'components' => [
                'genbank' => [
                    'enabled' => true,
                    'max_records_per_export' => 1000
                ],
                'taxonomy-report' => [
                    'enabled' => true,
                    'max_collections_per_report' => 10
                ],
                'upload' => [
                    'enabled' => true,
                    'storage_path' => '/var/temp/uploads',
                    'working_path' => '/var/temp/upload_work'
                ],
                'backup' => [
                    'enabled' => true,
                    'storage_path' => '/var/temp/backups',
                    'working_path' => '/var/temp/backup_work',
                    'registry_file' => 'backup.json',
                    'http_post' => false,
                    'site_salt' => 'change_this_in_production'
                ],
                'images' => [
                    'enabled' => true,
                    'images_per_page' => 50
                ]
            ]
        ];
    }
    
    /**
     * Merge configuration arrays properly (non-recursive for scalar values)
     */
    private function mergeConfigArrays(array $default, array $config): array
    {
        $result = $default;

        foreach ($config as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->mergeConfigArrays($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get nested configuration value
     */
    private function getNestedValue(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Set nested configuration value
     */
    private function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;
        
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }
        
        $current = $value;
    }
    
    /**
     * Check if path is in web space
     */
    private function isPathInWebSpace(string $path): bool
    {
        $webRoots = ['/var/www', '/usr/share/nginx', '/home/*/public_html'];
        
        foreach ($webRoots as $webRoot) {
            if (strpos($path, $webRoot) === 0) {
                return true;
            }
        }
        
        return false;
    }
}
