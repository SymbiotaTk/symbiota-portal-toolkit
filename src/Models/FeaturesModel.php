<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\TemplateFormat;

/**
 * Features Model - System Information and Documentation
 * 
 * Displays system information, CLI usage examples, and API endpoints
 * that were previously shown on the main dashboard.
 */
class FeaturesModel extends Model
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Set public actions for features module
        $this->publicActions = ['index', 'help', 'info', 'cli-examples', 'api-endpoints'];
    }

    /**
     * Get the model's routing resource ID
     */
    public static function getModelId(): string
    {
        return 'features';
    }

    /**
     * Get the module type classification
     */
    public static function getModuleType(): ModuleType
    {
        return ModuleType::UTILITY;
    }

    /**
     * Get help markdown for CLI
     */
    public static function getHelpMarkdown(): string
    {
        return <<<HELP
# Features Model

Display system information, CLI usage examples, and API endpoint documentation.

## Usage

```bash
php index.php features
```

## Description

The Features model provides a comprehensive overview of:
- System information (PHP version, memory usage, etc.)
- CLI usage examples for all available commands
- API endpoint documentation with examples
- Interactive web interface for system administration

This replaces the system information that was previously displayed on the main dashboard.

## Examples

```bash
# View features page
php index.php features

# Get JSON data
php index.php features:json

# Get HTML fragment
php index.php features:html
```
HELP;
    }

    /**
     * Get display name
     */
    public static function getDisplayName(): string
    {
        return 'System Features';
    }

    /**
     * Get description
     */
    public static function getDescription(): string
    {
        return 'System information, CLI usage examples, and API endpoint documentation';
    }

    /**
     * Get icon class
     */
    public static function getIconClass(): string
    {
        return 'fas fa-info-circle';
    }

    /**
     * Execute the requested action - implements Model base class method
     */
    protected function executeAction(string $action, array $route, array $params): array
    {
        switch ($action) {
            case 'index':
            default:
                return $this->showFeaturesData();
        }
    }

    /**
     * Handle request (legacy method)
     */
    public function handleRequest(Request $request): Response
    {
        $route = $request->getRoute();

        // Default to showing features overview
        if (empty($route['elements']) || count($route['elements']) === 1) {
            return $this->showFeatures($request);
        }

        return Response::notFound('Features page not found');
    }

    /**
     * Show features data (for router)
     */
    private function showFeaturesData(): array
    {
        // Get system information
        $systemInfo = $this->getSystemInformation();

        return [
            'type' => 'features',
            'title' => 'System Features',
            'system_info' => $systemInfo,
            'script_name' => $this->getScriptNameForExamples(),
            'content' => 'System Features page content will be rendered by template'
        ];
    }

    /**
     * Show features page with system information, CLI usage, and API endpoints
     */
    private function showFeatures(Request $request): Response
    {
        $templateEngine = new TemplateEngine();
        
        // Get system information
        $systemInfo = $this->getSystemInformation();
        
        $featuresData = [
            'type' => 'features',
            'title' => 'System Features',
            'system_info' => $systemInfo,
            'script_name' => $this->getScriptNameForExamples()
        ];

        if ($request->isJson() || $request->isAjax()) {
            return Response::json($featuresData);
        }

        // Render HTML page
        $templateEngine->setGlobalVariables([
            'app_url_prefix' => $request->getAppUrlPrefix(),
            'base_url' => $request->getBasePath()
        ]);

        $content = $templateEngine->template('features/content', TemplateFormat::HTML, $featuresData);

        // Wrap in layout
        $fullPage = $templateEngine->template('dashboard/layout', TemplateFormat::HTML, [
            'page_title' => 'System Features - Symbiota Portal Helpers',
            'content' => $content,
            'features_active' => 'active',
            'navigation_items' => '',
            'breadcrumb_items' => '<li class="breadcrumb-item active">System Features</li>',
            'php_version' => PHP_VERSION
        ]);

        return Response::html($fullPage);
    }

    /**
     * Get system information (overrides base class with enhanced functionality)
     */
    protected function getSystemInformation(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'total_components' => 5, // Hardcoded for now
            'total_services' => 3, // Hardcoded for now
            'total_libraries' => 5, // Hardcoded for now
            'memory_usage' => (string)round((float)memory_get_usage(true) / 1024.0 / 1024.0, 2) . ' MB',
            'peak_memory' => (string)round((float)memory_get_peak_usage(true) / 1024.0 / 1024.0, 2) . ' MB',
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
        ];
    }

    // getScriptNameForExamples method removed - now inherited from Model base class

    /**
     * Get help text for CLI
     */
    public static function getHelpText(): string
    {
        return "Display system features, CLI usage examples, and API documentation";
    }
}
