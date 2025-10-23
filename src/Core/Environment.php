<?php

namespace Symbiota\Helpers\Core;

/**
 * Environment Singleton - Centralized environment and request context evaluation
 *
 * Provides unified access to:
 * - Request type (CLI vs HTTP)
 * - Response format modifiers (:json, :htmx, etc.)
 * - Authentication context
 * - Environment mode (testing vs production)
 * - Symbiota integration context
 *
 * ENVIRONMENT VARIABLES:
 * - TK_ENV: Application environment mode ('production', 'development', 'testing')
 * - TK_DEBUG: Enable debug mode (true/false)
 * - TK_LOG_LEVEL: Logging level ('debug', 'info', 'warning', 'error')
 * - TK_DATABASE_URL: Database connection URL
 * - TK_CACHE_DRIVER: Cache driver ('file', 'redis', 'memcached')
 * - TK_SESSION_DRIVER: Session driver ('file', 'database', 'redis')
 * - TK_MAIL_DRIVER: Mail driver ('smtp', 'sendmail', 'log')
 * - TK_ENCRYPTION_KEY: Application encryption key
 * - TK_APP_URL: Application base URL
 * - TK_SYMBIOTA_URL: Symbiota installation URL
 * - TK_UPLOAD_MAX_SIZE: Maximum upload file size
 * - TK_BACKUP_RETENTION: Backup retention period in days
 * - TK_RATE_LIMIT: API rate limit per minute
 * - TK_CSRF_PROTECTION: Enable CSRF protection (true/false)
 * - TK_SSL_REQUIRED: Require SSL connections (true/false)
 * - TK_MAINTENANCE_MODE: Enable maintenance mode (true/false)
 *
 * ENVIRONMENT SOURCES (in order of precedence):
 * 1. Runtime environment variables ($_ENV, getenv())
 * 2. .env file in application root
 * 3. System environment variables
 * 4. Configuration file defaults
 * 5. Hard-coded application defaults
 *
 * PRECEDENCE RULES:
 * - Environment variables override configuration file settings
 * - CLI arguments override environment variables
 * - Runtime setters override all other sources
 * - Testing environment overrides production settings
 * - Development mode enables additional debugging features
 * - Production mode enforces security restrictions
 *
 * DETECTION METHODS:
 * - CLI detection: php_sapi_name() === 'cli' || defined('STDIN')
 * - HTTP detection: isset($_SERVER['REQUEST_METHOD'])
 * - AJAX detection: $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
 * - HTMX detection: isset($_SERVER['HTTP_HX_REQUEST'])
 * - Testing detection: TK_ENV === 'testing' || defined('PHPUNIT_RUNNING')
 * - Production detection: TK_ENV === 'production'
 * - Symbiota integration: Symbiota constants and globals present
 *
 * REQUEST CONTEXT VARIABLES:
 * - $isCli: Running in command line interface
 * - $isHttp: Running in HTTP/web context
 * - $isTesting: Running in test environment
 * - $isProduction: Running in production environment
 * - $hasSymbiotaIntegration: Symbiota portal integration available
 * - $requiresAuthentication: Current request requires authentication
 * - $responseFormat: Expected response format ('html', 'json', 'htmx', 'cli')
 * - $requestModifiers: Request format modifiers and flags
 */
class Environment
{
    /**
     * Environment types (backward compatibility)
     */
    const CLI = 'cli';
    const HTTP = 'http';
    const EMBEDDED = 'embedded';
    const UNKNOWN = 'unknown';

    private static ?Environment $instance = null;
    private static ?string $detectedEnvironment = null; // Backward compatibility

    private bool $isCli;
    private bool $isHttp;
    private bool $isTesting;
    private bool $isProduction;
    private bool $hasSymbiotaIntegration;
    private bool $requiresAuthentication;
    private string $responseFormat;
    private array $requestModifiers;
    private ?Configuration $config;
    
    /**
     * Private constructor - singleton pattern
     */
    private function __construct()
    {
        $this->config = Configuration::getInstance();
        $this->detectEnvironment();
        $this->detectRequestContext();
        $this->detectResponseFormat();
        $this->detectAuthenticationRequirements();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): Environment
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }



    /**
     * Detect the current execution environment (backward compatibility)
     *
     * @return string One of the Environment constants
     */
    public static function detect(): string
    {
        if (self::$detectedEnvironment !== null) {
            return self::$detectedEnvironment;
        }

        // Primary detection: PHP SAPI
        $sapi = php_sapi_name();

        // Debug output for environment detection
        if (function_exists('debugOutput')) {
            debugOutput("Environment Detection Details", [
                'php_sapi_name' => $sapi,
                'SERVER_REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'NOT SET',
                'SERVER_HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'NOT SET',
                'argc_exists' => isset($GLOBALS['argc']),
                'argv_exists' => isset($GLOBALS['argv']),
                'STDIN_defined' => defined('STDIN'),
                'all_server_keys' => array_keys($_SERVER)
            ]);
        }

        if ($sapi === 'cli') {
            self::$detectedEnvironment = self::CLI;
        } elseif (in_array($sapi, ['apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'litespeed'])) {
            self::$detectedEnvironment = self::HTTP;
        } elseif ($sapi === 'embed') {
            self::$detectedEnvironment = self::EMBEDDED;
        } else {
            // Fallback detection for edge cases
            if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_METHOD'])) {
                self::$detectedEnvironment = self::HTTP;
            } elseif (defined('STDIN') || isset($GLOBALS['argv'])) {
                self::$detectedEnvironment = self::CLI;
            } else {
                self::$detectedEnvironment = self::UNKNOWN;
            }
        }
        
        return self::$detectedEnvironment;
    }
    
    /**
     * Check if running in CLI environment
     * 
     * @return bool
     */
    public static function isCli(): bool
    {
        return self::detect() === self::CLI;
    }
    
    /**
     * Check if running in HTTP environment
     * 
     * @return bool
     */
    public static function isHttp(): bool
    {
        return self::detect() === self::HTTP;
    }
    
    /**
     * Check if running in embedded environment
     * 
     * @return bool
     */
    public static function isEmbedded(): bool
    {
        return self::detect() === self::EMBEDDED;
    }
    
    /**
     * Get environment information for debugging
     * 
     * @return array
     */
    public static function getInfo(): array
    {
        return [
            'environment' => self::detect(),
            'sapi' => php_sapi_name(),
            'has_http_host' => isset($_SERVER['HTTP_HOST']),
            'has_request_method' => isset($_SERVER['REQUEST_METHOD']),
            'has_stdin' => defined('STDIN'),
            'has_argv' => isset($GLOBALS['argv']),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null
        ];
    }

    /**
     * Detect core environment context (singleton method)
     */
    private function detectEnvironment(): void
    {
        // Detect CLI vs HTTP
        $this->isCli = php_sapi_name() === 'cli';
        $this->isHttp = !$this->isCli;

        // Detect testing vs production environment
        $this->isTesting = $this->config && $this->config->hasTestingEnvironment();
        $this->isProduction = !$this->isTesting;

        // Detect Symbiota integration
        $this->hasSymbiotaIntegration = $this->detectSymbiotaIntegration();
    }

    /**
     * Detect request context and modifiers
     */
    private function detectRequestContext(): void
    {
        $this->requestModifiers = [];

        if ($this->isHttp) {
            // Parse URL for modifiers like :json, :htmx
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $this->parseRequestModifiers($requestUri);
        } elseif ($this->isCli) {
            // Parse CLI arguments for modifiers
            global $argv;
            $this->parseCliModifiers($argv ?? []);
        }
    }

    /**
     * Parse HTTP request modifiers from URI
     */
    private function parseRequestModifiers(string $uri): void
    {
        // Look for modifiers like /?/genbank/collections:htmx
        if (preg_match('/([^\/]+):([^\/\?]+)/', $uri, $matches)) {
            $this->requestModifiers['format'] = $matches[2];
        }

        // Check for HTMX headers
        if (isset($_SERVER['HTTP_HX_REQUEST'])) {
            $this->requestModifiers['htmx'] = true;
        }

        // Check Accept header for JSON
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'application/json') !== false) {
            $this->requestModifiers['json'] = true;
        }
    }

    /**
     * Parse CLI modifiers from arguments
     */
    private function parseCliModifiers(array $argv): void
    {
        foreach ($argv as $arg) {
            if (strpos($arg, '--format=') === 0) {
                $this->requestModifiers['format'] = substr($arg, 9);
            } elseif ($arg === '--json') {
                $this->requestModifiers['json'] = true;
            } elseif ($arg === '--verbose') {
                $this->requestModifiers['verbose'] = true;
            }
        }
    }

    /**
     * Detect response format based on context
     */
    private function detectResponseFormat(): void
    {
        // Priority order: explicit modifier > HTMX > JSON > CLI > HTML
        if (isset($this->requestModifiers['format'])) {
            $this->responseFormat = $this->requestModifiers['format'];
        } elseif (isset($this->requestModifiers['htmx'])) {
            $this->responseFormat = 'htmx';
        } elseif (isset($this->requestModifiers['json'])) {
            $this->responseFormat = 'json';
        } elseif ($this->isCli) {
            $this->responseFormat = 'cli';
        } else {
            $this->responseFormat = 'html';
        }
    }

    /**
     * Detect authentication requirements
     */
    private function detectAuthenticationRequirements(): void
    {
        // Default to no authentication required
        $this->requiresAuthentication = false;

        // Check if current request requires authentication
        if ($this->isHttp) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            // Add logic to determine if URI requires authentication
            $this->requiresAuthentication = $this->isRestrictedEndpoint($requestUri);
        }
    }

    /**
     * Check if endpoint requires authentication
     */
    private function isRestrictedEndpoint(string $uri): bool
    {
        $restrictedPatterns = [
            '/backup/',
            '/upload/',
            '/admin/',
            '/config/'
        ];

        foreach ($restrictedPatterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect Symbiota integration context
     */
    private function detectSymbiotaIntegration(): bool
    {
        if ($this->isTesting && $this->config) {
            // In testing, check for testing_symbiota_source
            return $this->config->getTestingSymbiotaSource() !== null;
        } else {
            // In production, check for actual Symbiota installation
            return $this->detectProductionSymbiota();
        }
    }

    /**
     * Detect production Symbiota installation
     */
    private function detectProductionSymbiota(): bool
    {
        // Check common Symbiota paths
        $symbiotaPaths = [
            '../classes/DbConnection.php',
            '../../classes/DbConnection.php',
            '../../../classes/DbConnection.php'
        ];

        foreach ($symbiotaPaths as $path) {
            if (file_exists($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset cached detection and singleton instance (for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$detectedEnvironment = null;
    }
    
    /**
     * Force environment detection (for testing)
     * 
     * @param string $environment
     * @return void
     */
    public static function forceEnvironment(string $environment): void
    {
        if (!in_array($environment, [self::CLI, self::HTTP, self::EMBEDDED, self::UNKNOWN])) {
            throw new \InvalidArgumentException("Invalid environment: {$environment}");
        }
        
        self::$detectedEnvironment = $environment;
    }
    
    /**
     * Get all valid environment constants
     *
     * @return array
     */
    public static function getValidEnvironments(): array
    {
        return [self::CLI, self::HTTP, self::EMBEDDED, self::UNKNOWN];
    }

    // === Singleton API Methods ===

    /**
     * Check if running in testing environment
     */
    public function isTesting(): bool
    {
        return $this->isTesting;
    }

    /**
     * Check if running in production environment
     */
    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    /**
     * Check if Symbiota integration is available
     */
    public function hasSymbiotaIntegration(): bool
    {
        return $this->hasSymbiotaIntegration;
    }

    /**
     * Check if current request requires authentication
     */
    public function requiresAuthentication(): bool
    {
        return $this->requiresAuthentication;
    }

    /**
     * Get response format
     */
    public function getResponseFormat(): string
    {
        return $this->responseFormat;
    }

    /**
     * Get all request modifiers
     */
    public function getRequestModifiers(): array
    {
        return $this->requestModifiers;
    }

    /**
     * Check if specific modifier is present
     */
    public function hasModifier(string $modifier): bool
    {
        return isset($this->requestModifiers[$modifier]);
    }

    /**
     * Get modifier value
     */
    public function getModifier(string $modifier, $default = null)
    {
        return $this->requestModifiers[$modifier] ?? $default;
    }

    /**
     * Check if response should be JSON
     */
    public function shouldReturnJson(): bool
    {
        return in_array($this->responseFormat, ['json', 'cli']);
    }

    /**
     * Check if response should be HTMX
     */
    public function shouldReturnHtmx(): bool
    {
        return $this->responseFormat === 'htmx';
    }

    /**
     * Check if response should be HTML
     */
    public function shouldReturnHtml(): bool
    {
        return $this->responseFormat === 'html';
    }

    /**
     * Get environment summary for debugging
     */
    public function getSummary(): array
    {
        return [
            'request_type' => $this->isCli ? 'cli' : 'http',
            'environment' => $this->isTesting ? 'testing' : 'production',
            'response_format' => $this->responseFormat,
            'symbiota_integration' => $this->hasSymbiotaIntegration,
            'requires_authentication' => $this->requiresAuthentication,
            'modifiers' => $this->requestModifiers
        ];
    }
}
