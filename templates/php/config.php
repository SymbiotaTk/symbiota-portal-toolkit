<?php
/**
 * Symbiota Portal Helpers v2.0 - Secure Configuration Template
 * 
 * This is the MOST SECURE configuration method for HTTP usage.
 * 
 * SECURITY BENEFITS:
 * - Cannot be accessed directly via HTTP (returns PHP code, not config data)
 * - Works for both HTTP requests and CLI usage
 * - Supports environment variable integration
 * - Allows conditional configuration logic
 * - No command-line arguments needed
 * 
 * SETUP:
 * 1. Copy this file to: config.php (in the same directory as index.php)
 * 2. Customize the settings below
 * 3. The file will be automatically loaded for both HTTP and CLI usage
 * 
 * ALTERNATIVE LOCATIONS:
 * - For maximum security, place outside web root and use CLI: -c /path/to/config.php
 * - For production, use environment variables to override these settings
 */

return [
    'app' => [
        'name' => 'Symbiota Portal Helpers',
        'version' => '2.0.0',

        // Portal navigation configuration
        'portal_navigation_text' => 'Return to portal',
        'portal_url' => '../',  // Relative path to parent Symbiota portal

        // Environment path variables for Symbiota integration
        'symbdir' => $_ENV['SYMBDIR'] ?? '/var/www/html/symbiota',  // Full path to Symbiota installation
        'webdir' => $_ENV['WEBDIR'] ?? '/var/www/html',             // Full path to web root
        // SYMBCLIENTURL is calculated automatically from symbdir - webdir
        // Example: If symbdir="/var/www/html/symbiota" and webdir="/var/www/html"
        //          then SYMBCLIENTURL="/symbiota" (relative URL from web root)

        // Debug mode - use environment variable or set directly
        'debug' => $_ENV['SYMBIOTA_DEBUG'] ?? false,
        
        // Disable specific components for HTTP access (CLI access remains available)
        // This is perfect for securing sensitive operations from web access
        'disabled_components' => [
            'backup',    // Disable backup component for web interface
            'upload',    // Disable upload component for web interface
            // 'genbank',   // Uncomment to disable genbank component
            // 'images',    // Uncomment to disable images component
        ],
    ],
    
    'database' => [
        // Database connection file - use environment variable or secure path
        'dbconnection' => $_ENV['SYMBIOTA_DB_CONNECTION'] ?? 'config/dbconnection.php',
        'timeout' => 30,
        'read_only' => true,
    ],
    
    'security' => [
        'require_authentication' => true,
        'api_keys_enabled' => true,
        'rate_limiting' => true,
    ],
    
    'storage' => [
        // CRITICAL: All paths must be outside web space for security
        'base_path' => $_ENV['SYMBIOTA_STORAGE_PATH'] ?? '/var/symbiota/helpers',
        'working_path' => $_ENV['SYMBIOTA_WORKING_PATH'] ?? '/var/symbiota/helpers_work',
    ],
    
    'performance' => [
        // PHP memory limit for large operations (cache-get-source, cache-build-eav)
        // Set to '-1' for unlimited, or use values like '512M', '1G', '2G'
        // Recommended: 512M for datasets < 1M records, 1G for 1M-10M, 2G for > 10M
        'memory_limit' => $_ENV['SYMBIOTA_MEMORY_LIMIT'] ?? '512M',

        // Maximum execution time for long-running operations (in seconds)
        // Set to 0 for unlimited
        'max_execution_time' => $_ENV['SYMBIOTA_MAX_EXECUTION_TIME'] ?? 0,
    ],

    'components' => [
        'genbank' => [
            'enabled' => true,
            'max_records_per_export' => 1000,
        ],
        'backup' => [
            'enabled' => true,
            'storage_path' => '/var/symbiota/backups',
            'working_path' => '/var/symbiota/backup_work',
        ],
        'upload' => [
            'enabled' => true,
            'storage_path' => '/var/symbiota/uploads',
            'working_path' => '/var/symbiota/upload_work',
        ],
        'images' => [
            'enabled' => true,
            'images_per_page' => 50,

            // Memory limit specifically for images operations (overrides global performance.memory_limit)
            // Useful if images operations need more memory than other components
            'memory_limit' => $_ENV['SYMBIOTA_IMAGES_MEMORY_LIMIT'] ?? null,
        ],
    ],
];

/*
 * INI FORMAT ALTERNATIVE:
 *
 * If you prefer INI format, use a HEREDOC like this:
 *
 * $CONFIG =<<<INI
 * [app]
 * name = "Symbiota Portal Helpers"
 * version = "2.0.0"
 *
 * [performance]
 * memory_limit = "512M"
 * max_execution_time = 0
 *
 * [mod.images]
 * images_per_page = 50
 * memory_limit = "1G"
 * INI;
 *
 * USAGE EXAMPLES:
 *
 * HTTP Access (automatic):
 *   {base_url|http://localhost}{app_url_prefix|/helpers/?/}genbank/occid/12345
 *
 * CLI Access (automatic):
 *   php index.php genbank occid 12345
 * 
 * Environment Variable Override:
 *   SYMBIOTA_DISABLED_COMPONENTS="backup,upload" php index.php --html-output
 * 
 * Test Configuration:
 *   php index.php --test-config
 * 
 * SECURITY NOTES:
 * - This file cannot be accessed via HTTP (returns PHP code)
 * - Place outside web root for maximum security
 * - Use environment variables for production deployments
 * - Disabled components are blocked for HTTP but work in CLI
 */
