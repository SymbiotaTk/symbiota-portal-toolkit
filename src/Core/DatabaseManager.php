<?php

namespace Symbiota\Helpers\Core;

use mysqli;
use Exception;

/**
 * Database Manager
 * 
 * Handles database connection detection, configuration parsing, and connection management.
 * Supports multiple connection types (read/write) and various configuration sources.
 */
class DatabaseManager
{
    private ?string $configPath = null;
    private ?array $config = null;
    private array $connections = [];
    private array $sqliteConnections = []; // Store SQLite PDO connections
    private bool $isAvailable = false;
    
    public function __construct(?string $configPath = null, ?string $connectionUri = null)
    {
        if ($connectionUri) {
            $this->parseConnectionUri($connectionUri);
        } else {
            // Use configuration state to determine database path
            $config = Configuration::getInstance();
            if ($config) {
                $state = $config->getConfigurationState();

                switch ($state) {
                    case 'cfTesting':
                        $this->configPath = $config->getTestingDbConnectionPath();
                        break;
                    case 'cfProduction':
                        $symbiotaDir = $config->detectSymbiotaDirectory();
                        if ($symbiotaDir) {
                            $this->configPath = $symbiotaDir . '/config/dbconnection.php';
                        }
                        break;
                    case 'cfStatic':
                    default:
                        $this->configPath = null; // No database in static mode
                        break;
                }
            } else {
                $this->configPath = $configPath ?? $this->detectConfigPath();
            }

            if ($this->configPath && file_exists($this->configPath)) {
                $this->loadConfig();
            } else {
                // No database configuration available - offline mode
                $this->isAvailable = false;
            }
        }
    }

    /**
     * Detect database configuration file path using elegant directory traversal
     * Searches systematically from Helpers runtime directory upward
     */
    private function detectConfigPath(): ?string
    {
        // Get the Helpers application runtime directory
        $helpersDir = dirname(__DIR__, 2); // Go up from src/Core to Helpers root

        // Generate search paths using dirname() with increasing levels
        $searchPaths = [];

        // Search up to 5 directory levels from Helpers root
        for ($level = 0; $level <= 5; $level++) {
            $baseDir = ($level === 0) ? $helpersDir : dirname($helpersDir, $level);

            // Try different config file locations at each level
            $configPaths = [
                'config/dbconnection.php',
                'dbconnection.php',
                'dev/dbconnection.php'
            ];

            foreach ($configPaths as $configPath) {
                $fullPath = $baseDir . DIRECTORY_SEPARATOR . $configPath;
                $searchPaths[] = $fullPath;
            }
        }

        // Search for existing config file
        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        return null;
    }

    /**
     * Get Symbiota portal root directory from config file location
     */
    public function getSymbiotaPortalRoot(): ?string
    {
        if (!$this->configPath) {
            return null;
        }

        // If config is in config/dbconnection.php, the parent is the portal root
        $configDir = dirname($this->configPath);
        if (basename($configDir) === 'config') {
            return dirname($configDir);
        }

        // If config is directly in portal root (dbconnection.php)
        return $configDir;
    }

    /**
     * Calculate portal web path relative to web root
     * Similar to Symbiota Installer project calculation
     */
    public function getPortalWebPath(): string
    {
        $portalRoot = $this->getSymbiotaPortalRoot();
        if (!$portalRoot) {
            return '/portal'; // Fallback to default
        }

        // Get document root from server
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (empty($documentRoot)) {
            return '/portal'; // Fallback if no document root
        }

        // Calculate relative path from document root to portal
        $documentRoot = realpath($documentRoot);
        $portalRoot = realpath($portalRoot);

        if ($documentRoot && $portalRoot) {
            // Check if portal is within document root
            if (strpos($portalRoot, $documentRoot) === 0) {
                $relativePath = substr($portalRoot, strlen($documentRoot));
                $relativePath = str_replace('\\', '/', $relativePath); // Normalize for Windows
                return $relativePath ?: '/';
            }
        }

        return '/portal'; // Fallback
    }

    /**
     * Parse connection URI using UriParser (mariadb://user:password@host:3306/database)
     */
    private function parseConnectionUri(string $uri): void
    {
        $parser = UriParser::parse($uri);

        if (!$parser->getProtocol() || !$parser->getOffset()) {
            throw new \InvalidArgumentException("Invalid connection URI format. Expected: mariadb://user:password@host:3306/database");
        }

        // SQLite has different requirements (no host/username needed)
        if ($parser->getProtocol() === 'sqlite') {
            $this->config = [
                'driver' => 'sqlite',
                'database' => ltrim($parser->getOffset(), '/'),
                'charset' => 'utf8mb4'
            ];
        } else {
            // Other database types require host and username
            if (!$parser->getHost() || !$parser->getUsername()) {
                throw new \InvalidArgumentException("Invalid connection URI format. Expected: mariadb://user:password@host:3306/database");
            }

            $this->config = [
                'host' => $parser->getHost(),
                'port' => $parser->getPort() ?? 3306,
                'username' => $parser->getUsername(),
                'password' => $parser->getPassword() ?? '',
                'database' => ltrim($parser->getOffset(), '/'),
                'charset' => 'utf8mb4',
                'engine' => 'InnoDB'
            ];
        }

        $this->isAvailable = true;
    }

    /**
     * Load database configuration from file
     */
    private function loadConfig(): void
    {
        if (!$this->configPath || !file_exists($this->configPath)) {
            $this->isAvailable = false;
            return;
        }

        try {
            // Use static parser to avoid class loading issues
            $staticConfig = $this->parseConfigStatically($this->configPath);

            if (!empty($staticConfig)) {
                $this->config = $staticConfig;
                $this->isAvailable = true;
                return;
            }

            // Fallback to dynamic loading for compatibility
            $this->loadConfigDynamically();

        } catch (Exception $e) {
            error_log("Database config loading failed: " . $e->getMessage());
            $this->isAvailable = false;
        }
    }

    /**
     * Parse configuration statically without loading classes
     */
    private function parseConfigStatically(string $configPath): array
    {
        if (!class_exists('Symbiota\Helpers\Core\DbConnectionParser')) {
            return [];
        }

        try {
            return \Symbiota\Helpers\Core\DbConnectionParser::parseFile($configPath);
        } catch (Exception $e) {
            error_log("Static config parsing failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load configuration dynamically (fallback for compatibility)
     */
    private function loadConfigDynamically(): void
    {
        // Try to load as array return format first
        $result = require_once $this->configPath;
        if (is_array($result)) {
            $this->config = $result;
        } else {
            // If not an array, check if MySQLiConnectionFactory class exists (Symbiota format)
            if (class_exists('MySQLiConnectionFactory')) {
                $symbiotaConfig = $this->parseSymbiotaConfig();
                // Only use Symbiota config if it's not empty (valid)
                if (!empty($symbiotaConfig)) {
                    $this->config = $symbiotaConfig;
                } else {
                    $this->config = [];
                }
            } else {
                $this->config = [];
            }
        }

        $this->isAvailable = !empty($this->config);
    }
    
    /**
     * Parse Symbiota-style MySQLiConnectionFactory configuration
     */
    private function parseSymbiotaConfig(): array
    {
        // Check if MySQLiConnectionFactory class exists in global namespace
        if (!class_exists('MySQLiConnectionFactory', false)) {
            return [];
        }

        // Use reflection to access static property safely
        try {
            $reflection = new \ReflectionClass('MySQLiConnectionFactory');
            if (!$reflection->hasProperty('SERVERS')) {
                return [];
            }

            $serversProperty = $reflection->getProperty('SERVERS');
            $serversProperty->setAccessible(true);
            $servers = $serversProperty->getValue();

            if (!is_array($servers)) {
                return [];
            }

            $config = [];
            foreach ($servers as $server) {
                $type = $server['type'] ?? 'readonly';
                $config[$type] = [
                    'host' => $server['host'] ?? 'localhost',
                    'username' => $server['username'] ?? '',
                    'password' => $server['password'] ?? '',
                    'database' => $server['database'] ?? '',
                    'port' => (int)($server['port'] ?? 3306),
                    'charset' => $server['charset'] ?? 'utf8'
                ];
            }

            return $config;

        } catch (Exception $e) {
            error_log("Failed to parse Symbiota config: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get database connection (ALWAYS READ ONLY for security)
     */
    public function getConnection(string $type = 'readonly'): ?mysqli
    {
        if (!$this->isAvailable) {
            return null;
        }
        
        // Return existing connection if available
        if (isset($this->connections[$type])) {
            // Test if connection is still alive by attempting a simple query
            try {
                $this->connections[$type]->query('SELECT 1');
                return $this->connections[$type];
            } catch (\Exception $e) {
                unset($this->connections[$type]);
            }
        }
        
        // Create new connection
        $serverConfig = $this->config[$type] ?? $this->config['readonly'] ?? null;
        if (!$serverConfig) {
            return null;
        }
        
        try {
            if (version_compare(PHP_VERSION, '8.1', '>=')) {
                mysqli_report(MYSQLI_REPORT_OFF);
            }
            
            $connection = new mysqli(
                $serverConfig['host'],
                $serverConfig['username'],
                $serverConfig['password'],
                $serverConfig['database'],
                $serverConfig['port']
            );
            
            if ($connection->connect_error) {
                throw new Exception("Connection failed: " . $connection->connect_error);
            }
            
            // Set charset
            if (isset($serverConfig['charset']) && $serverConfig['charset']) {
                if (!$connection->set_charset($serverConfig['charset'])) {
                    throw new Exception('Error loading character set ' . $serverConfig['charset'] . ': ' . $connection->error);
                }
            }

            // SECURITY: Force ALL connections to read-only mode
            // Helpers should never modify database data
            $connection->query("SET SESSION TRANSACTION READ ONLY");
            // Additional safety: disable autocommit to prevent accidental writes
            $connection->autocommit(false);

            $this->connections[$type] = $connection;
            return $connection;
            
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Test database connection
     */
    public function testConnection(string $type = 'readonly'): array
    {
        $result = [
            'available' => $this->isAvailable,
            'config_path' => $this->configPath,
            'connection_type' => $type,
            'status' => 'unknown'
        ];
        
        if (!$this->isAvailable) {
            $result['status'] = 'no_config';
            $result['message'] = 'No database configuration found';
            return $result;
        }
        
        $connection = $this->getConnection($type);
        if ($connection) {
            $result['status'] = 'connected';
            $result['message'] = 'Database connection successful';
            $result['server_info'] = $connection->server_info;
            $result['host_info'] = $connection->host_info;
        } else {
            $result['status'] = 'failed';
            $result['message'] = 'Database connection failed';
        }
        
        return $result;
    }
    
    /**
     * Get available connection types
     */
    public function getAvailableTypes(): array
    {
        if (!$this->isAvailable) {
            return [];
        }
        
        return array_keys($this->config);
    }
    
    /**
     * Check if database is available
     */
    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    /**
     * Check if testing database connection is available
     */
    public static function isTestingConnectionAvailable(): bool
    {
        $config = Configuration::getInstance();
        if (!$config) {
            return false;
        }

        $state = $config->getConfigurationState();

        switch ($state) {
            case 'cfTesting':
                $testingDbPath = $config->getTestingDbConnectionPath();
                if (!$testingDbPath || !file_exists($testingDbPath)) {
                    return false;
                }

                // Try to create a test connection
                try {
                    $testManager = new self($testingDbPath);
                    return $testManager->isAvailable();
                } catch (Exception $e) {
                    return false;
                }

            case 'cfProduction':
                // In production, check if Symbiota database is available
                return self::isProductionConnectionAvailable();

            case 'cfStatic':
            default:
                return false; // No database available in static mode
        }
    }

    /**
     * Check if production database connection is available
     */
    public static function isProductionConnectionAvailable(): bool
    {
        $config = Configuration::getInstance();
        if (!$config || !$config->isSymbiotaEnvironmentDetected()) {
            return false;
        }

        $symbiotaDir = $config->detectSymbiotaDirectory();
        if (!$symbiotaDir) {
            return false;
        }

        $dbConnectionPath = $symbiotaDir . '/config/dbconnection.php';
        if (!file_exists($dbConnectionPath)) {
            return false;
        }

        // Try to create a production connection
        try {
            $prodManager = new self($dbConnectionPath);
            return $prodManager->isAvailable();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Verify database connectivity with detailed status
     */
    public function verifyConnectivity(): array
    {
        $status = [
            'available' => $this->isAvailable,
            'config_found' => $this->configPath !== null,
            'config_path' => $this->configPath,
            'connection_test' => 'not_tested',
            'can_connect' => false,
            'error' => null
        ];

        if (!$this->isAvailable) {
            $status['error'] = 'No database configuration available';
            return $status;
        }

        // Test actual connection
        try {
            $connection = $this->getConnection('readonly');
            if ($connection) {
                $result = $connection->query('SELECT 1 as test');
                if ($result) {
                    $status['connection_test'] = 'success';
                    $status['can_connect'] = true;
                } else {
                    $status['connection_test'] = 'query_failed';
                    $status['error'] = 'Test query failed: ' . $connection->error;
                }
            } else {
                $status['connection_test'] = 'connection_failed';
                $status['error'] = 'Could not establish database connection';
            }
        } catch (Exception $e) {
            $status['connection_test'] = 'exception';
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Quick connectivity check (for testing environments)
     */
    public function canConnect(): bool
    {
        if (!$this->isAvailable) {
            return false;
        }

        try {
            $connection = $this->getConnection('readonly');
            if ($connection) {
                $result = $connection->query('SELECT 1');
                return $result !== false;
            }
        } catch (Exception $e) {
            // Connection failed
        }

        return false;
    }
    
    /**
     * Get configuration path
     */
    public function getConfigPath(): ?string
    {
        return $this->configPath;
    }
    
    /**
     * Close all connections
     */
    public function closeConnections(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection instanceof mysqli) {
                $connection->close();
            }
        }
        $this->connections = [];
    }
    
    /**
     * Create database manager from CLI arguments and INI file
     */
    public static function fromCliArgs(array $args): self
    {
        $configPath = null;
        $connectionUri = null;

        // Check for CLI arguments
        foreach ($args as $i => $arg) {
            $nextIndex = (int)$i + 1;
            if ($arg === '--dbconnection' && isset($args[$nextIndex])) {
                $configPath = $args[$nextIndex];
            } elseif (strpos($arg, '--dbconnection=') === 0) {
                $configPath = substr($arg, 15);
            } elseif ($arg === '--db-uri' && isset($args[$nextIndex])) {
                $connectionUri = $args[$nextIndex];
            } elseif (strpos($arg, '--db-uri=') === 0) {
                $connectionUri = substr($arg, 9);
            }
        }

        // Database configuration should be provided via CLI arguments or config.php
        // INI files are no longer supported for security reasons

        return new self($configPath, $connectionUri);
    }

    /**
     * Get SQLite PDO connection for a named database
     *
     * Retrieves SQLite database path from Configuration and returns a PDO connection.
     * Supports caching of connections for reuse.
     *
     * @param string $name Database name (e.g., 'images_cache', 'images_log')
     * @return \PDO|null PDO connection or null if database not configured/available
     */
    public function getSqliteConnection(string $name): ?\PDO
    {
        // Return existing connection if available
        if (isset($this->sqliteConnections[$name])) {
            return $this->sqliteConnections[$name];
        }

        // Get database path from Configuration
        $config = Configuration::getInstance();
        if (!$config) {
            return null;
        }

        // Try different configuration paths for the database
        $dbPath = null;

        // Try components.images.{name}_db first
        $dbPath = $config->get("components.images.{$name}_db");

        // Fallback to mod.images.{name}_db (legacy config format)
        if (empty($dbPath)) {
            $dbPath = $config->get("mod.images.{$name}_db");
        }

        // Fallback to images.{name}_db
        if (empty($dbPath)) {
            $dbPath = $config->get("images.{$name}_db");
        }

        if (empty($dbPath) || !file_exists($dbPath)) {
            return null;
        }

        try {
            $pdo = new \PDO("sqlite:$dbPath");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Cache the connection
            $this->sqliteConnections[$name] = $pdo;

            return $pdo;
        } catch (\Exception $e) {
            error_log("Failed to connect to SQLite database '$name' at $dbPath: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute a query with progress spinner for long-running operations
     */
    public function queryWithProgress(string $sql, string $message = 'Executing query...', string $type = 'readonly'): ?\mysqli_result
    {
        $connection = $this->getConnection($type);
        if (!$connection) {
            return null;
        }

        $spinner = new ProgressSpinner();
        $spinner->start($message);

        try {
            // Start the query
            $result = $connection->query($sql, MYSQLI_ASYNC);

            // Poll for completion with spinner updates
            do {
                $spinner->tick();
                usleep(100000); // 100ms
                $links = $errors = $reject = [$connection];
                $ready = mysqli_poll($links, $errors, $reject, 0, 100000); // 100ms timeout
            } while ($ready === 0);

            if ($ready === false) {
                $spinner->fail('Query failed');
                return null;
            }

            $result = $connection->reap_async_query();

            if ($result === false) {
                $spinner->fail('Query error: ' . $connection->error);
                return null;
            }

            $spinner->succeed('Query completed');
            return $result;

        } catch (\Exception $e) {
            $spinner->fail('Query failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute a prepared statement with progress spinner and SQL injection protection
     */
    public function preparedQueryWithProgress(
        string $sql,
        array $parameters = [],
        string $types = '',
        string $message = 'Executing query...',
        string $type = 'readonly'
    ): ?\mysqli_result {
        $connection = $this->getConnection($type);
        if (!$connection) {
            return null;
        }

        $spinner = new ProgressSpinner();
        $spinner->start($message);

        try {
            // Prepare statement
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                $spinner->fail('Query preparation failed');
                throw new \Exception("Query preparation failed: " . $connection->error);
            }

            // Bind parameters if any exist
            if (!empty($parameters)) {
                if (empty($types)) {
                    // Auto-detect types if not provided
                    $types = $this->detectParameterTypes($parameters);
                }
                $stmt->bind_param($types, ...$parameters);
            }

            // Execute statement
            $result = $stmt->execute();
            if (!$result) {
                $spinner->fail('Query execution failed');
                $stmt->close();
                throw new \Exception("Query execution failed: " . $stmt->error);
            }

            // Get result set
            $result = $stmt->get_result();
            $stmt->close();

            $spinner->succeed('Query completed');
            return $result;

        } catch (\Exception $e) {
            $spinner->fail('Query failed: ' . $e->getMessage());
            if (isset($stmt)) {
                $stmt->close();
            }
            return null;
        }
    }

    /**
     * Build secure WHERE clause for filtering with prepared statement placeholders
     */
    public function buildSecureFilterClause(array $filters, array $validFields): array
    {
        if (empty($filters)) {
            return [
                'clause' => '',
                'parameters' => [],
                'types' => ''
            ];
        }

        $conditions = [];
        $parameters = [];
        $typesList = [];

        foreach ($filters as $filterSpec) {
            $filterResult = $this->parseSecureFilterSpec($filterSpec, $validFields);
            if ($filterResult['condition']) {
                $conditions[] = $filterResult['condition'];
                $parameters = array_merge($parameters, $filterResult['parameters']);
                $typesList[] = $filterResult['types'];
            }
        }

        $types = implode('', $typesList);

        if (empty($conditions)) {
            return [
                'clause' => '',
                'parameters' => [],
                'types' => ''
            ];
        }

        return [
            'clause' => 'AND (' . implode(' AND ', $conditions) . ')',
            'parameters' => $parameters,
            'types' => $types
        ];
    }

    /**
     * Parse individual filter specification securely using prepared statement placeholders
     */
    private function parseSecureFilterSpec(string $filterSpec, array $validFields): array
    {
        // Parse format: value:matchType:logicType:fieldName
        $parts = explode(':', $filterSpec);
        $value = $parts[0] ?? '';
        $matchType = $parts[1] ?? 'exact';
        $logicType = $parts[2] ?? 'and';
        $fieldName = $parts[3] ?? '';

        if (empty($value)) {
            return [
                'condition' => '',
                'parameters' => [],
                'types' => ''
            ];
        }

        // Build field conditions with placeholders
        $fieldConditions = [];
        $parameters = [];
        $typesList = [];

        $fieldsToSearch = !empty($fieldName) && in_array($fieldName, $validFields)
            ? [$fieldName]
            : $validFields;

        foreach ($fieldsToSearch as $field) {
            // Validate field name (whitelist approach)
            if (!in_array($field, $validFields)) {
                continue;
            }

            // Get table alias (assume 'c' for collections, can be parameterized)
            $tableAlias = $this->getTableAliasForField($field);

            switch ($matchType) {
                case 'like':
                    $fieldConditions[] = "LOWER({$tableAlias}.{$field}) LIKE LOWER(?)";
                    $parameters[] = '%' . $value . '%';
                    $typesList[] = 's'; // string type
                    break;
                case 'exact':
                default:
                    $fieldConditions[] = "{$tableAlias}.{$field} = ?";
                    $parameters[] = $value;
                    $typesList[] = 's'; // string type
                    break;
            }
        }

        $types = implode('', $typesList);

        if (empty($fieldConditions)) {
            return [
                'condition' => '',
                'parameters' => [],
                'types' => ''
            ];
        }

        // Join field conditions with OR (search across multiple fields)
        $condition = '(' . implode(' OR ', $fieldConditions) . ')';

        return [
            'condition' => $condition,
            'parameters' => $parameters,
            'types' => $types
        ];
    }

    /**
     * Build ORDER BY clause for sorting (safe - no user input in field names)
     */
    public function buildSortClause(array $sorts, array $validFields, string $defaultSort = ''): string
    {
        if (empty($sorts)) {
            return $defaultSort ?: 'ORDER BY c.collectionName ASC';
        }

        $orderClauses = [];

        foreach ($sorts as $sortSpec) {
            $clause = $this->parseSortSpec($sortSpec, $validFields);
            if ($clause) {
                $orderClauses[] = $clause;
            }
        }

        if (empty($orderClauses)) {
            return $defaultSort ?: 'ORDER BY c.collectionName ASC';
        }

        return 'ORDER BY ' . implode(', ', $orderClauses);
    }

    /**
     * Parse individual sort specification
     */
    private function parseSortSpec(string $sortSpec, array $validFields): string
    {
        // Parse format: field:direction
        $parts = explode(':', $sortSpec);
        $field = $parts[0] ?? '';
        $direction = strtoupper($parts[1] ?? 'ASC');

        if (empty($field) || !in_array($field, $validFields)) {
            return '';
        }

        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        // Get table alias for field
        $tableAlias = $this->getTableAliasForField($field);

        return "{$tableAlias}.{$field} {$direction}";
    }

    /**
     * Auto-detect parameter types for prepared statements
     */
    private function detectParameterTypes(array $parameters): string
    {
        $typesList = [];
        foreach ($parameters as $param) {
            if (is_int($param)) {
                $typesList[] = 'i';
            } elseif (is_float($param)) {
                $typesList[] = 'd';
            } else {
                $typesList[] = 's';
            }
        }
        return implode('', $typesList);
    }

    /**
     * Get table alias for field (can be extended for different models)
     */
    private function getTableAliasForField(string $field): string
    {
        // Default to 'c' for collections table
        // This can be made configurable per model if needed
        return 'c';
    }

    /**
     * Validate database configuration
     */
    public function validateConfiguration(): array
    {
        $errors = [];
        $config = $this->config ?? [];

        // Check required fields
        $required = ['host', 'username', 'database'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

/**
 * Helper function to check if path is absolute
 */
function is_absolute_path(string $path): bool
{
    return $path[0] === '/' || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:/i', $path));
}
