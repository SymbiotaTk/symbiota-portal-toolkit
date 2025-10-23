<?php

namespace Symbiota\Helpers\Core;

/**
 * Static Database Connection Parser
 * 
 * Parses dbconnection.php files without loading classes or executing code.
 * Useful for testing and configuration validation.
 */
class DbConnectionParser
{
    /**
     * Parse dbconnection.php file content statically
     * 
     * @param string $filePath Path to dbconnection.php file
     * @return array Parsed connection configuration
     */
    public static function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        return self::parseContent($content);
    }

    /**
     * Parse dbconnection.php content string
     * 
     * @param string $content File content
     * @return array Parsed connection configuration
     */
    public static function parseContent(string $content): array
    {
        // Remove PHP tags and comments
        $content = self::cleanContent($content);

        // Try to extract SERVERS array using regex
        $servers = self::extractServersArray($content);
        
        if (empty($servers)) {
            return [];
        }

        // Convert to standardized format
        return self::normalizeServers($servers);
    }

    /**
     * Clean PHP content for parsing
     */
    private static function cleanContent(string $content): string
    {
        // Remove PHP opening/closing tags
        $content = preg_replace('/<\?php\s*/', '', $content);
        $content = preg_replace('/\?\>\s*$/', '', $content);
        
        // Remove single-line comments
        $content = preg_replace('/\/\/.*$/m', '', $content);
        
        // Remove multi-line comments
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        return $content;
    }

    /**
     * Extract SERVERS array from content
     */
    private static function extractServersArray(string $content): array
    {
        // Pattern to match the SERVERS array
        $pattern = '/\$SERVERS\s*=\s*array\s*\((.*?)\);/s';
        
        if (!preg_match($pattern, $content, $matches)) {
            return [];
        }

        $arrayContent = $matches[1];
        
        // Parse individual server arrays
        $serverPattern = '/array\s*\((.*?)\)/s';
        preg_match_all($serverPattern, $arrayContent, $serverMatches);
        
        $servers = [];
        foreach ($serverMatches[1] as $serverContent) {
            $server = self::parseServerArray($serverContent);
            if (!empty($server)) {
                $servers[] = $server;
            }
        }
        
        return $servers;
    }

    /**
     * Parse individual server array content
     */
    private static function parseServerArray(string $content): array
    {
        $server = [];
        
        // Pattern to match key => value pairs
        $pattern = '/[\'"](\w+)[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2];
                $server[$key] = $value;
            }
        }
        
        return $server;
    }

    /**
     * Normalize servers to standard format
     */
    private static function normalizeServers(array $servers): array
    {
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
    }

    /**
     * Validate parsed configuration
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];
        
        if (empty($config)) {
            $errors[] = 'No database configuration found';
            return $errors;
        }
        
        foreach ($config as $type => $server) {
            if (empty($server['host'])) {
                $errors[] = "Missing host for {$type} connection";
            }
            if (empty($server['database'])) {
                $errors[] = "Missing database for {$type} connection";
            }
            if (empty($server['username'])) {
                $errors[] = "Missing username for {$type} connection";
            }
            if (!is_numeric($server['port']) || $server['port'] < 1 || $server['port'] > 65535) {
                $errors[] = "Invalid port for {$type} connection";
            }
        }
        
        return $errors;
    }

    /**
     * Get connection info summary
     */
    public static function getConnectionSummary(string $filePath): array
    {
        $config = self::parseFile($filePath);
        $errors = self::validateConfig($config);
        
        $summary = [
            'file_path' => $filePath,
            'file_exists' => file_exists($filePath),
            'connections' => [],
            'valid' => empty($errors),
            'errors' => $errors
        ];
        
        foreach ($config as $type => $server) {
            $summary['connections'][$type] = [
                'host' => $server['host'],
                'database' => $server['database'],
                'port' => $server['port'],
                'charset' => $server['charset'],
                'has_credentials' => !empty($server['username'])
            ];
        }
        
        return $summary;
    }

    /**
     * Extract connection values for specific type
     */
    public static function getConnectionValues(string $filePath, string $type = 'readonly'): array
    {
        $config = self::parseFile($filePath);
        return $config[$type] ?? [];
    }

    /**
     * Convert parsed config to connection string
     */
    public static function toConnectionString(array $config, string $type = 'readonly'): string
    {
        $server = $config[$type] ?? null;
        if (!$server) {
            return '';
        }

        $protocol = 'mariadb'; // Default to MariaDB/MySQL
        $host = $server['host'] ?? 'localhost';
        $port = $server['port'] ?? 3306;
        $username = $server['username'] ?? '';
        $password = $server['password'] ?? '';
        $database = $server['database'] ?? '';

        // Build connection string
        $connectionString = "{$protocol}://";

        if ($username) {
            $connectionString .= urlencode($username);
            if ($password) {
                $connectionString .= ':' . urlencode($password);
            }
            $connectionString .= '@';
        }

        $connectionString .= $host;

        if ($port && $port != 3306) {
            $connectionString .= ":{$port}";
        }

        if ($database) {
            $connectionString .= '/' . urlencode($database);
        }

        return $connectionString;
    }

    /**
     * Get connection string from dbconnection.php file
     */
    public static function getConnectionStringFromFile(string $filePath, string $type = 'readonly'): string
    {
        $config = self::parseFile($filePath);
        return self::toConnectionString($config, $type);
    }

    /**
     * Create DatabaseManager instance using connection string from dbconnection.php
     */
    public static function createDatabaseManagerFromFile(string $filePath, string $type = 'readonly'): ?\Symbiota\Helpers\Core\DatabaseManager
    {
        $connectionString = self::getConnectionStringFromFile($filePath, $type);

        if (empty($connectionString)) {
            return null;
        }

        try {
            return new \Symbiota\Helpers\Core\DatabaseManager(null, $connectionString);
        } catch (\Exception $e) {
            error_log("Failed to create DatabaseManager: " . $e->getMessage());
            return null;
        }
    }
}
