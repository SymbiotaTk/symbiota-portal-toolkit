<?php

namespace Symbiota\Helpers\Ext;

use Symbiota\Helpers\Core\DatabaseManager;
use Exception;

/**
 * Symbiota Authentication Extension
 *
 * Handles authentication for Symbiota portal integration.
 * Queries users and userroles tables to get real user data.
 * In development/testing, can use hardcoded test users.
 */
class SymbAuth
{
    private static ?array $currentUser = null;
    private static bool $testMode = false;
    private static ?DatabaseManager $dbManager = null;
    private static $config = null;

    // Test users for development
    private static array $testUsers = [
        433 => [
            'uid' => 433,
            'username' => 'superadmin',
            'firstname' => 'Super',
            'lastname' => 'Administrator',
            'email' => 'superadmin@example.com',
            'role' => 'SuperAdmin',
            'permissions' => ['backup', 'upload', 'admin'],
            'collections' => [] // SuperAdmin has access to all collections
        ],
        525 => [
            'uid' => 525,
            'username' => 'colladmin',
            'firstname' => 'Collection',
            'lastname' => 'Administrator',
            'email' => 'colladmin@example.com',
            'role' => 'CollAdmin',
            'permissions' => ['backup', 'upload'],
            'collections' => [1, 2, 3] // CollAdmin has access to specific collections
        ]
    ];

    /**
     * Initialize SymbAuth with database manager and configuration
     */
    public static function initialize(?DatabaseManager $dbManager = null, $config = null): void
    {
        self::$dbManager = $dbManager;
        self::$config = $config;

        // Initialize Symbiota environment if we're in a Symbiota context
        self::initializeSymbiotaEnvironment();

        // Auto-detect user from Symbiota session or testing configuration
        self::autoDetectUser();
    }

    /**
     * Initialize Symbiota environment if available
     */
    private static function initializeSymbiotaEnvironment(): void
    {
        try {
            // Set critical globals immediately to prevent undefined variable warnings
            global $LANG_TAG, $DEFAULT_LANG, $AVAILABLE_LANGS, $USER_RIGHTS, $PARAMS_ARR, $SYMB_UID, $IS_ADMIN, $USERNAME, $USER_DISPLAY_NAME;

            // Set essential variables as defaults if not already set by symbini.php
            if (!isset($LANG_TAG)) $LANG_TAG = 'en';
            if (!isset($DEFAULT_LANG)) $DEFAULT_LANG = 'en';
            if (!isset($AVAILABLE_LANGS)) $AVAILABLE_LANGS = ['en'];
            if (!isset($USER_RIGHTS)) $USER_RIGHTS = [];
            if (!isset($PARAMS_ARR)) $PARAMS_ARR = [];
            if (!isset($SYMB_UID)) $SYMB_UID = 0;
            if (!isset($IS_ADMIN)) $IS_ADMIN = 0;
            if (!isset($USERNAME)) $USERNAME = '';
            if (!isset($USER_DISPLAY_NAME)) $USER_DISPLAY_NAME = '';

            // Check if we're in a Symbiota environment by looking for SymbInitEnv
            $symbInitEnvPath = __DIR__ . '/SymbInitEnv.php';
            if (file_exists($symbInitEnvPath)) {
                require_once $symbInitEnvPath;

                // Try to initialize Symbiota environment
                if (function_exists('initializeSymbiotaEnvironment')) {
                    initializeSymbiotaEnvironment();
                }
            }
        } catch (Exception $e) {
            // Silently continue if Symbiota environment initialization fails
            // This allows the system to work in both Symbiota and standalone environments
            error_log("SymbAuth: Symbiota environment initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Auto-detect user from Symbiota session or testing configuration
     */
    private static function autoDetectUser(): void
    {
        // First, check for Symbiota session environment
        $symbiotaUid = self::getSymbiotaSessionUid();
        if ($symbiotaUid) {
            self::setUser($symbiotaUid);
            return;
        }

        // Fall back to testing UID in configuration
        if (self::$config) {
            $testingUid = self::$config->get('app.debug.testing_symbiota_uid');
            if ($testingUid) {
                self::setUser($testingUid);
            }
        }
    }

    /**
     * Get UID from Symbiota session environment
     * Checks for global $SYMB_UID, SymbiotaCrumb cookie, or session variables
     */
    private static function getSymbiotaSessionUid(): ?int
    {
        // First priority: Check for Symbiota global variable $SYMB_UID
        global $SYMB_UID;
        if (isset($SYMB_UID) && is_numeric($SYMB_UID)) {
            return (int)$SYMB_UID;
        }

        // Second priority: Check for SymbiotaCrumb cookie (primary Symbiota authentication)
        if (isset($_COOKIE['SymbiotaCrumb'])) {
            try {
                // Decrypt the cookie using Symbiota's encryption method
                $decryptedData = self::decryptSymbiotaCrumb($_COOKIE['SymbiotaCrumb']);
                if ($decryptedData) {
                    $tokenArr = json_decode($decryptedData, true);
                    if (is_array($tokenArr) && count($tokenArr) >= 2) {
                        $username = $tokenArr[0] ?? null;
                        $token = $tokenArr[1] ?? null;

                        if ($username && $token) {
                            // Query database to get UID from username
                            return self::getUidFromUsername($username);
                        }
                    }
                }
            } catch (Exception $e) {
                // Log error but continue to fallback methods
                error_log("SymbAuth: Error decrypting SymbiotaCrumb: " . $e->getMessage());
            }
        }

        // Third priority: Check for PHP session variables (only if session already exists)
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Check for common Symbiota session variables
            if (isset($_SESSION['uid']) && is_numeric($_SESSION['uid'])) {
                return (int)$_SESSION['uid'];
            }

            // Check for alternative session variable names
            if (isset($_SESSION['SYMB_UID']) && is_numeric($_SESSION['SYMB_UID'])) {
                return (int)$_SESSION['SYMB_UID'];
            }
        }

        return null;
    }

    /**
     * Decrypt SymbiotaCrumb cookie using Symbiota's encryption method
     */
    private static function decryptSymbiotaCrumb(string $encryptedData): ?string
    {
        // Try to use Symbiota's Encryption class if available
        if (class_exists('Encryption')) {
            try {
                $decrypted = \Encryption::decrypt($encryptedData);
                if ($decrypted && $decrypted !== $encryptedData) {
                    return $decrypted;
                }
            } catch (Exception $e) {
                error_log("SymbAuth: Symbiota decryption failed: " . $e->getMessage());
            }
        }

        // Fallback: Try basic decryption methods for testing
        // Try JSON decode first (in case it's not encrypted in testing)
        $decoded = json_decode($encryptedData, true);
        if (is_array($decoded)) {
            return $encryptedData;
        }

        // Try base64 decode
        $base64Decoded = base64_decode($encryptedData, true);
        if ($base64Decoded !== false) {
            $jsonDecoded = json_decode($base64Decoded, true);
            if (is_array($jsonDecoded)) {
                return $base64Decoded;
            }
        }

        return null;
    }

    /**
     * Get UID from username by querying the database
     */
    private static function getUidFromUsername(string $username): ?int
    {
        if (!self::$dbManager || !self::$dbManager->isAvailable()) {
            // Silently return null if database is not available
            // This is normal in standalone/offline mode
            return null;
        }

        try {
            // Use DatabaseManager's getConnection method to get mysqli object
            $connection = self::$dbManager->getConnection('readonly');
            if (!$connection) {
                error_log("SymbAuth: Failed to get database connection");
                return null;
            }

            // Load SQL from template
            $sqlTemplate = dirname(__DIR__, 2) . '/templates/sql/symbiota/user_by_username.sql';
            if (!file_exists($sqlTemplate)) {
                error_log("SymbAuth: SQL template not found: $sqlTemplate");
                return null;
            }
            $sql = file_get_contents($sqlTemplate);
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                error_log("SymbAuth: Failed to prepare statement: " . $connection->error);
                return null;
            }

            $stmt->bind_param('ss', $username, $username);
            if (!$stmt->execute()) {
                error_log("SymbAuth: Failed to execute statement: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            if (!$result) {
                error_log("SymbAuth: Failed to get result: " . $stmt->error);
                $stmt->close();
                return null;
            }

            if ($row = $result->fetch_assoc()) {
                $uid = (int)$row['uid'];
                $stmt->close();
                error_log("SymbAuth: Found UID $uid for username '$username'");
                return $uid;
            }

            $stmt->close();
            error_log("SymbAuth: No user found for username '$username'");
        } catch (Exception $e) {
            error_log("SymbAuth: Error querying UID for username '$username': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Set user by UID (for testing or production)
     */
    public static function setUser(?int $uid): bool
    {
        if ($uid === null) {
            self::$currentUser = null;
            self::$testMode = false;
            return true;
        }

        // Try to get user from database first
        if (self::$dbManager) {
            $user = self::getUserFromDatabase($uid);
            if ($user) {
                self::$currentUser = $user;
                self::$testMode = false;
                return true;
            }
        }

        // Fall back to test users
        if (isset(self::$testUsers[$uid])) {
            self::$currentUser = self::$testUsers[$uid];
            self::$testMode = true;
            return true;
        }

        return false;
    }

    /**
     * Get user from database
     */
    private static function getUserFromDatabase(int $uid): ?array
    {
        try {
            $connection = self::$dbManager->getConnection();
            if (!$connection) {
                error_log("SymbAuth: Failed to get database connection in getUserFromDatabase");
                return null;
            }

            // Query user information - load SQL from template
            $userSqlTemplate = dirname(__DIR__, 2) . '/templates/sql/symbiota/user_details.sql';
            if (!file_exists($userSqlTemplate)) {
                error_log("SymbAuth: SQL template not found: $userSqlTemplate");
                return null;
            }
            $userSql = file_get_contents($userSqlTemplate);
            $stmt = $connection->prepare($userSql);
            if (!$stmt) {
                error_log("SymbAuth: Failed to prepare user query: " . $connection->error);
                return null;
            }

            $stmt->bind_param('i', $uid);
            if (!$stmt->execute()) {
                error_log("SymbAuth: Failed to execute user query: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            if (!$result) {
                error_log("SymbAuth: Failed to get user result: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                return null;
            }

            // Query user roles and collections - load SQL from template
            $rolesSqlTemplate = dirname(__DIR__, 2) . '/templates/sql/symbiota/user_roles.sql';
            if (!file_exists($rolesSqlTemplate)) {
                error_log("SymbAuth: SQL template not found: $rolesSqlTemplate");
                return null;
            }
            $rolesSql = file_get_contents($rolesSqlTemplate);
            $stmt = $connection->prepare($rolesSql);
            if (!$stmt) {
                error_log("SymbAuth: Failed to prepare roles query: " . $connection->error);
                return null;
            }

            $stmt->bind_param('i', $uid);
            if (!$stmt->execute()) {
                error_log("SymbAuth: Failed to execute roles query: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            if (!$result) {
                error_log("SymbAuth: Failed to get roles result: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $roles = [];
            $permissions = [];
            $collections = [];

            while ($row = $result->fetch_assoc()) {
                $roles[] = $row['role'];

                // Determine permissions and collections based on role
                if ($row['role'] === 'SuperAdmin') {
                    $permissions = array_merge($permissions, ['backup', 'upload', 'admin']);
                } elseif ($row['role'] === 'CollAdmin') {
                    $permissions = array_merge($permissions, ['backup', 'upload']);
                    // Add collection ID if specified
                    if (!empty($row['collid'])) {
                        $collections[] = (int)$row['collid'];
                    }
                }
            }

            // Determine primary role
            $primaryRole = in_array('SuperAdmin', $roles) ? 'SuperAdmin' :
                          (count($collections) > 0 ? 'CollAdmin' : 'User');

            return [
                'uid' => (int)$user['uid'],
                'username' => $user['username'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'email' => $user['email'],
                'role' => $primaryRole,
                'permissions' => array_unique($permissions),
                'collections' => $collections
            ];

        } catch (Exception $e) {
            error_log("SymbAuth database error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get current user
     */
    public static function getCurrentUser(): ?array
    {
        return self::$currentUser;
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        return self::$currentUser !== null;
    }

    /**
     * Check if user has specific permission
     */
    public static function hasPermission(string $permission): bool
    {
        if (!self::$currentUser) {
            return false;
        }

        return in_array($permission, self::$currentUser['permissions'] ?? []);
    }

    /**
     * Check if user can access backup functionality
     */
    public static function canAccessBackup(): bool
    {
        return self::hasPermission('backup');
    }

    /**
     * Check if user can access upload functionality
     */
    public static function canAccessUpload(): bool
    {
        return self::hasPermission('upload');
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin(): bool
    {
        return self::hasPermission('admin');
    }

    /**
     * Check if user can access specific collection
     */
    public static function canAccessCollection(int $collectionId): bool
    {
        if (!self::$currentUser) {
            return false;
        }

        // SuperAdmin can access all collections
        if (self::isAdmin()) {
            return true;
        }

        // Check if user has access to this specific collection
        return in_array($collectionId, self::$currentUser['collections'] ?? []);
    }

    /**
     * Get collections user can access
     */
    public static function getAccessibleCollections(): array
    {
        if (!self::$currentUser) {
            return [];
        }

        // SuperAdmin can access all collections (return empty array to indicate "all")
        if (self::isAdmin()) {
            return [];
        }

        return self::$currentUser['collections'] ?? [];
    }

    /**
     * Get display name for user
     */
    public static function getDisplayName(): string
    {
        if (!self::$currentUser) {
            return 'Guest';
        }

        $name = trim((self::$currentUser['firstname'] ?? '') . ' ' . (self::$currentUser['lastname'] ?? ''));
        return !empty($name) ? $name : (self::$currentUser['username'] ?? 'User');
    }

    /**
     * Get greeting for navigation
     */
    public static function getGreeting(): string
    {
        if (!self::$currentUser) {
            return '';
        }

        $displayName = self::getDisplayName();
        $email = self::$currentUser['email'] ?? '';
        $role = self::$currentUser['role'] ?? '';

        $greeting = $displayName;
        if (!empty($email)) {
            $greeting .= " ({$email})";
        }

        // Add role suffix
        if ($role === 'SuperAdmin') {
            $greeting .= ' [su]';
        }

        return $greeting;
    }

    /**
     * Check if we're in testing mode
     */
    public static function isTestingMode(): bool
    {
        // If we have a Symbiota session, we're not in testing mode
        if (self::getSymbiotaSessionUid()) {
            return false;
        }

        // Check if any testing configuration is in use
        if (self::$config) {
            $testingKeys = [
                'app.debug.testing_symbiota_uid',
                'testing_symbiota_uid'
            ];

            foreach ($testingKeys as $key) {
                if (!empty(self::$config->get($key))) {
                    return true;
                }
            }
        }

        return self::$testMode;
    }

    /**
     * Alias for isTestingMode() for backward compatibility
     */
    public static function isTestMode(): bool
    {
        return self::isTestingMode();
    }

    /**
     * Get authentication status for templates
     */
    public static function getAuthStatus(): array
    {
        $user = self::getCurrentUser();

        return [
            'authenticated' => $user !== null,
            'user' => $user,
            'uid' => $user['uid'] ?? null,
            'username' => $user['username'] ?? null,
            'display_name' => self::getDisplayName(),
            'greeting' => self::getGreeting(),
            'can_backup' => self::canAccessBackup(),
            'can_upload' => self::canAccessUpload(),
            'is_admin' => self::isAdmin(),
            'is_testing' => self::isTestingMode(),
            'accessible_collections' => self::getAccessibleCollections()
        ];
    }

    /**
     * Get available test users
     */
    public static function getTestUsers(): array
    {
        return self::$testUsers;
    }

    /**
     * Debug method to test SymbiotaCrumb cookie parsing
     * This can be called from a test endpoint to verify cookie handling
     */
    public static function debugSymbiotaCrumb(): array
    {
        $debug = [
            'has_cookie' => isset($_COOKIE['SymbiotaCrumb']),
            'cookie_value' => $_COOKIE['SymbiotaCrumb'] ?? null,
            'session_uid' => self::getSymbiotaSessionUid(),
            'current_user' => self::$currentUser,
            'is_testing' => self::isTestingMode()
        ];

        if (isset($_COOKIE['SymbiotaCrumb'])) {
            $debug['decrypt_attempt'] = self::decryptSymbiotaCrumb($_COOKIE['SymbiotaCrumb']);
        }

        return $debug;
    }

    /**
     * Check if we're running in a Symbiota environment
     */
    public static function isSymbiotaEnvironment(): bool
    {
        return class_exists('Encryption') ||
               class_exists('MySQLiConnectionFactory') ||
               defined('SERVER_ROOT');
    }

    /**
     * Enable test mode with specific user
     */
    public static function enableTestMode(int $uid = 433): void
    {
        self::$testMode = true;
        self::$currentUser = self::$testUsers[$uid] ?? self::$testUsers[433];
    }

    /**
     * Disable test mode
     */
    public static function disableTestMode(): void
    {
        self::$testMode = false;
        self::$currentUser = null;
    }
}
