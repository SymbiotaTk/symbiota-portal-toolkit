<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateEngine;

/**
 * Status Model
 *
 * System utility for displaying application configuration status and Symbiota integration details.
 * This is a hUtility module - provides system administration endpoints but not displayed in main UI.
 */
class StatusModel extends Model
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Status is a public utility - no authentication required
        $this->requiresAuth = false;
        $this->publicActions = ['index'];
    }

    /**
     * Get the model's routing resource ID
     */
    public static function getModelId(): string
    {
        return 'status';
    }

    /**
     * Get the module type classification
     */
    public static function getModuleType(): ModuleType
    {
        return ModuleType::UTILITY;
    }

    /**
     * Get display name
     */
    public static function getDisplayName(): string
    {
        return 'Application Status';
    }

    /**
     * Get description
     */
    public static function getDescription(): string
    {
        return 'System status and configuration viewer';
    }

    /**
     * Get icon class
     */
    public static function getIconClass(): string
    {
        return 'fas fa-server';
    }

    /**
     * Execute the requested action - implements Model base class method
     */
    protected function executeAction(string $action, array $route, array $params): array
    {
        switch ($action) {
            case 'index':
            default:
                return $this->index($params);
        }
    }

    /**
     * Show application status and configuration
     */
    public function index(array $params): array
    {
        $config = Configuration::getInstance();

        $output = [];
        $output[] = "Symbiota Portal Helpers - Application Status";
        $output[] = str_repeat("=", 80);
        $output[] = "";

        // Application Info
        $output[] = "Application Information:";
        $output[] = "  Name: " . ($config->get('app.name') ?? 'Symbiota Portal Helpers');
        $output[] = "  Version: " . ($config->get('app.version') ?? '2.0.0');
        $output[] = "";

        // Path Variables
        $output[] = "Path Variables (Auto-Detected):";
        $internal = $config->get('_internal') ?? [];
        
        $pathVars = [
            'appdir' => 'APPDIR',
            'symbdir' => 'SYMBDIR',
            'webroot' => 'WEBROOT',
            'symbclienturl' => 'SYMBCLIENTURL',
            'symbtempdirroot' => 'SYMBTEMPDIRROOT'
        ];

        foreach ($pathVars as $key => $label) {
            $value = $internal[$key] ?? 'NOT DETECTED';
            $output[] = sprintf("  %-20s %s", $label . ':', $value);
        }
        $output[] = "";

        // Symbiota Integration
        $symbDir = $internal['symbdir'] ?? '';
        if (!empty($symbDir)) {
            $output[] = "Symbiota Integration:";
            $output[] = "  Status: DETECTED";
            $output[] = "  Portal Directory: " . $symbDir;
            
            // Check for symbini.php
            $symbiniPath = $symbDir . '/config/symbini.php';
            if (file_exists($symbiniPath)) {
                $output[] = "  symbini.php: FOUND";
                
                // Extract globals from symbini.php
                $globals = $this->extractSymbiniGlobals($symbiniPath);
                if (!empty($globals)) {
                    $output[] = "";
                    $output[] = "  Symbiota Globals (from symbini.php):";
                    foreach ($globals as $var => $value) {
                        $displayValue = is_string($value) ? $value : json_encode($value);
                        if (strlen($displayValue) > 60) {
                            $displayValue = substr($displayValue, 0, 57) . '...';
                        }
                        $output[] = sprintf("    %-25s %s", '$' . $var . ':', $displayValue);
                    }
                }
            } else {
                $output[] = "  symbini.php: NOT FOUND";
            }
        } else {
            $output[] = "Symbiota Integration:";
            $output[] = "  Status: NOT DETECTED";
            $output[] = "  Note: Running standalone (not in Symbiota portal)";
        }
        $output[] = "";

        // Configuration Aliases
        $output[] = "Configuration Variable Aliases:";
        $output[] = "  {APPDIR}         - Application directory";
        $output[] = "  {SYMBDIR}        - Symbiota portal directory";
        $output[] = "  {WEBROOT}        - Web server document root";
        $output[] = "  {SYMBCLIENTURL}  - Relative URL to Symbiota client";
        $output[] = "  {SYMBTEMPDIRROOT} - Symbiota temp directory (from \$TEMP_DIR_ROOT)";
        $output[] = "";

        // Module Status
        $output[] = "Module Status:";
        $modules = ['backup', 'genbank', 'images', 'taxonomy-report', 'upload'];
        foreach ($modules as $module) {
            $disabled = $config->get("mod.$module.disable") ?? false;
            $status = $disabled ? 'DISABLED' : 'ENABLED';
            $output[] = sprintf("  %-20s %s", ucfirst($module) . ':', $status);
        }
        $output[] = "";

        // Database Status
        $output[] = "Database Configuration:";

        // Use Configuration's detection methods
        $dbConnection = $config->getProductionDbConnectionPath();
        if ($dbConnection) {
            $output[] = "  Connection File: " . $dbConnection;
            $output[] = "  Status: DETECTED";
        } else {
            $output[] = "  Connection File: NOT DETECTED";
            $output[] = "  Status: NOT CONFIGURED";
        }

        // Database is ALWAYS read-only for security (enforced by DatabaseManager)
        // Individual models can override this in their own configuration if needed
        $output[] = "  Read-Only Mode: YES (enforced by DatabaseManager)";
        $output[] = "";

        return [
            'type' => 'text',
            'content' => implode("\n", $output)
        ];
    }

    /**
     * Detect database connection file
     */
    private function detectDatabaseConnection(string $symbDir): ?string
    {
        if (empty($symbDir)) {
            return null;
        }

        // Check standard Symbiota location
        $dbConnectionPath = $symbDir . '/config/dbconnection.php';
        if (file_exists($dbConnectionPath)) {
            return $dbConnectionPath;
        }

        // Check alternative locations
        $alternativePaths = [
            $symbDir . '/dbconnection.php',
            $symbDir . '/dev/dbconnection.php'
        ];

        foreach ($alternativePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Extract global variables from symbini.php
     */
    private function extractSymbiniGlobals(string $symbiniPath): array
    {
        $globals = [];

        try {
            $content = file_get_contents($symbiniPath);
            if ($content === false) {
                return [];
            }

            // Extract common Symbiota globals
            $patterns = [
                'TEMP_DIR_ROOT' => '/\$TEMP_DIR_ROOT\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'SERVER_ROOT' => '/\$SERVER_ROOT\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'CLIENT_ROOT' => '/\$CLIENT_ROOT\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'LOG_PATH' => '/\$LOG_PATH\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'IMAGE_ROOT_PATH' => '/\$IMAGE_ROOT_PATH\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'IMAGE_ROOT_URL' => '/\$IMAGE_ROOT_URL\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'DEFAULT_LANG' => '/\$DEFAULT_LANG\s*=\s*[\'"]([^\'"]+)[\'"]/',
                'PORTAL_NAME' => '/\$PORTAL_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/',
            ];

            foreach ($patterns as $var => $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $globals[$var] = $matches[1];
                }
            }

        } catch (\Exception $e) {
            error_log("Failed to extract symbini.php globals: " . $e->getMessage());
        }

        return $globals;
    }

    /**
     * Get help markdown for CLI
     */
    public static function getHelpMarkdown(): string
    {
        // Get the entry file name dynamically
        $entryFile = basename(ApplicationPaths::applicationFilename());

        // Load help content from template
        $templatePath = sprintf('%s/cli/help/status_model.txt', ApplicationPaths::templatesDirectory());
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
}

