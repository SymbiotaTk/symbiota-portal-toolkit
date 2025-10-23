<?php

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;

/**
 * Base class for self-discovering models with embedded help documentation
 */
abstract class DiscoverableModel
{
    protected array $config;
    protected ?DatabaseManager $dbManager = null;
    protected ?TemplateEngine $templateEngine = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get the model's routing resource ID
     * This should match the URL path component (e.g., 'genbank', 'taxonomy-report')
     */
    abstract public static function getModelId(): string;

    /**
     * Get the model's help documentation in markdown format
     * Used for CLI help generation and parameter validation
     */
    abstract public static function getHelpMarkdown(): string;

    /**
     * Get the module type classification
     * Must be implemented by each model to define its type
     */
    abstract public static function getModuleType(): ModuleType;

    /**
     * Get navigation display information for UI
     * Override in subclasses to customize display
     */
    public static function getNavigationInfo(): array
    {
        $modelId = static::getModelId();
        $helpInfo = static::getHelpInfo();

        // Default icon mapping
        $iconMap = [
            'genbank' => 'fas fa-dna',
            'taxonomy-report' => 'fas fa-sitemap',
            'images' => 'fas fa-images',
            'backup' => 'fas fa-archive',
            'upload' => 'fas fa-cloud-upload-alt',
        ];

        // Default display name (convert kebab-case to Title Case)
        $displayName = ucwords(str_replace('-', ' ', $modelId));

        return [
            'id' => $modelId,
            'display_name' => $displayName,
            'icon_class' => $iconMap[$modelId] ?? 'fas fa-cog',
            'description' => $helpInfo['description'] ?? '',
            'enabled' => static::isEnabled(),
            'module_type' => static::getModuleType()
        ];
    }

    /**
     * Get the current script name dynamically
     */
    public static function getScriptName(): string
    {
        // Get the script filename from $_SERVER
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? 'helpers.php';

        // Extract just the filename (not the full path)
        $scriptName = basename($scriptPath);

        // Fallback to helpers.php if we can't determine the script name
        return $scriptName ?: 'helpers.php';
    }

    /**
     * Get parsed help information from markdown
     */
    public static function getHelpInfo(): array
    {
        $markdown = static::getHelpMarkdown();
        // Replace hardcoded script names with dynamic detection
        $scriptName = static::getScriptName();
        $markdown = str_replace(['helpers.php', 'index.php'], $scriptName, $markdown);
        return static::parseHelpMarkdown($markdown);
    }

    /**
     * Parse help markdown into structured data
     */
    protected static function parseHelpMarkdown(string $markdown): array
    {
        $info = ['description' => '', 'usage' => [], 'parameters' => [], 'examples' => [], 'permissions' => []];
        $lines = explode("\n", $markdown);
        $section = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/^## (.+)$/', $line, $m)) {
                $section = strtolower($m[1]);
            } elseif ($section === null && empty($info['description'])) {
                $info['description'] = $line;
            } elseif ($section === 'parameters' && preg_match('/^- `([^`]+)`\s*(.*)$/', $line, $m)) {
                $required = str_contains($m[2], '(required)');
                $type = str_contains($m[2], 'integer') ? 'integer' : (str_contains($m[2], 'array') ? 'array' : 'string');
                $info['parameters'][$m[1]] = ['description' => preg_replace('/\([^)]*\)/', '', $m[2]), 'required' => $required, 'type' => $type];
            } elseif (in_array($section, ['usage', 'examples', 'permissions']) && preg_match('/^- (.+)$/', $line, $m)) {
                $info[$section][] = $m[1];
            }
        }

        return $info;
    }

    /**
     * Validate request parameters against help documentation
     */
    public function validateParameters(array $params): array
    {
        $helpInfo = static::getHelpInfo();
        $errors = [];

        // Check required parameters
        foreach ($helpInfo['parameters'] as $paramName => $paramInfo) {
            if ($paramInfo['required'] && !isset($params[$paramName])) {
                $errors[] = "Required parameter '{$paramName}' is missing";
            }
        }

        // Validate parameter types
        foreach ($params as $paramName => $value) {
            if (isset($helpInfo['parameters'][$paramName])) {
                $paramInfo = $helpInfo['parameters'][$paramName];
                $errors = array_merge($errors, $this->validateParameterType($paramName, $value, $paramInfo['type']));
            }
        }

        return $errors;
    }

    /**
     * Validate individual parameter type
     */
    protected function validateParameterType(string $name, $value, string $expectedType): array
    {
        $errors = [];

        switch ($expectedType) {
            case 'integer':
                if (!is_numeric($value)) {
                    $errors[] = "Parameter '{$name}' must be an integer";
                }
                break;
            case 'boolean':
                if (!in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'])) {
                    $errors[] = "Parameter '{$name}' must be a boolean (true/false)";
                }
                break;
            case 'array':
                if (!is_array($value)) {
                    $errors[] = "Parameter '{$name}' must be an array";
                }
                break;
        }

        return $errors;
    }

    /**
     * Generate CLI help text from markdown
     */
    public static function generateCliHelp(): string
    {
        $helpInfo = static::getHelpInfo();
        $modelId = static::getModelId();
        $scriptName = static::getScriptName();

        // Build sections
        $usageSection = '';
        if (!empty($helpInfo['usage'])) {
            $usageLines = ["USAGE PATTERNS:"];
            foreach ($helpInfo['usage'] as $usage) {
                $usageLines[] = "  {$usage}";
            }
            $usageSection = implode("\n", $usageLines) . "\n";
        }

        $parametersSection = '';
        if (!empty($helpInfo['parameters'])) {
            $paramLines = ["PARAMETERS:"];
            foreach ($helpInfo['parameters'] as $name => $info) {
                $required = $info['required'] ? ' (required)' : ' (optional)';
                $paramLines[] = sprintf("  %-20s %s%s", $name, $info['description'], $required);
            }
            $parametersSection = implode("\n", $paramLines) . "\n";
        }

        $examplesSection = '';
        if (!empty($helpInfo['examples'])) {
            $exampleLines = ["EXAMPLES:"];
            foreach ($helpInfo['examples'] as $example) {
                $exampleLines[] = "  {$example}";
            }
            $examplesSection = implode("\n", $exampleLines) . "\n";
        }

        // Load template and interpolate variables
        $templateEngine = new TemplateEngine();

        return $templateEngine->template('help/discoverable_model', TemplateFormat::CLI, [
            'script_name' => $scriptName,
            'model_id' => $modelId,
            'description' => $helpInfo['description'],
            'usage_section' => $usageSection,
            'parameters_section' => $parametersSection,
            'examples_section' => $examplesSection
        ]);
    }

    /**
     * Check if model is enabled based on file system location
     */
    public static function isEnabled(): bool
    {
        $reflection = new \ReflectionClass(static::class);
        $filename = $reflection->getFileName();
        
        // Model is enabled if its file exists and is readable
        return $filename && is_readable($filename);
    }

    /**
     * Get model priority for ordering (lower = higher priority)
     */
    public static function getPriority(): int
    {
        return 100; // Default priority, override in subclasses
    }

    /**
     * Get database connection (lazy loading)
     */
    protected function getConnection(string $type = 'readonly'): ?\mysqli
    {
        if (!$this->dbManager) {
            $configPath = $this->config['database_config_path'] ?? null;
            $this->dbManager = new DatabaseManager($configPath);
        }
        return $this->dbManager->getConnection($type);
    }

    /**
     * Check if database is available
     */
    public function isDatabaseAvailable(): bool
    {
        if (!$this->dbManager) {
            $configPath = $this->config['database_config_path'] ?? null;
            $this->dbManager = new DatabaseManager($configPath);
        }
        return $this->dbManager->isAvailable();
    }

    /**
     * Render template (lazy loading)
     */
    protected function renderTemplate(string $name, TemplateFormat $format, array $variables = []): string
    {
        if (!$this->templateEngine) {
            $templatesPath = $this->config['templates_path'] ?? null;
            $this->templateEngine = new TemplateEngine($templatesPath);

            // Set global template variables including base URL
            // Note: Local variables should override these in template rendering
            $this->templateEngine->setGlobalVariables([
                'app_name' => $this->config['app_name'] ?? 'Symbiota Portal Helpers',
                'app_version' => '2.0',
                'base_url' => $this->config['base_url'] ?? '/',
                'app_url_prefix' => $this->getAppUrlPrefix()  // Use dynamic method instead of static config
            ]);
        }
        return $this->templateEngine->template($name, $format, $variables);
    }

    /**
     * Get configuration value
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get application URL prefix for templates
     * Centralized implementation to avoid duplication across models
     */
    public function getAppUrlPrefix(): string
    {
        // First check if it's already in config (from global template variables)
        if (isset($this->config['app_url_prefix'])) {
            return $this->config['app_url_prefix'];
        }

        // Use UriParser for consistent URL generation
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/?/';
        $parser = new UriParser($requestUri);
        $fullUrl = $parser->getAppUrlPrefix();

        // For Symbiota environment, we need to preserve the full path including client root
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
}
