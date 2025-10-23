<?php

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;

/**
 * Model Discovery Service
 *
 * Automatically discovers models by scanning the file system
 * and matching routing resource IDs, eliminating manual registries.
 */
class ModelDiscovery
{
    private array $discoveredModels = [];
    private array $modelPaths = [];
    private bool $scanned = false;
    private ?Configuration $config;

    public function __construct(array $modelPaths = [], ?Configuration $config = null)
    {
        $this->modelPaths = $modelPaths ?: [
            __DIR__ . '/../Models',
            __DIR__ . '/../Components'
        ];
        $this->config = $config;
    }

    /**
     * Discover all available models
     */
    public function discoverModels(): array
    {
        if ($this->scanned) {
            return $this->discoveredModels;
        }

        $this->discoveredModels = [];

        foreach ($this->modelPaths as $path) {
            if (is_dir($path)) {
                $this->scanDirectory($path);
            }
        }

        // Sort by priority
        uasort($this->discoveredModels, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        $this->scanned = true;
        return $this->discoveredModels;
    }

    /**
     * Scan directory for model files
     */
    private function scanDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->analyzeFile($file->getPathname());
            }
        }
    }

    /**
     * Analyze PHP file for discoverable models
     */
    private function analyzeFile(string $filePath): void
    {
        try {
            // Get classes before including the file
            $classesBefore = get_declared_classes();
            require_once $filePath;
            $classesAfter = get_declared_classes();

            // Get newly loaded classes
            $newClasses = array_diff($classesAfter, $classesBefore);

            // If no new classes were loaded, try to find the expected class based on file name
            if (empty($newClasses)) {
                $expectedClassName = $this->getExpectedClassNameFromFile($filePath);
                if ($expectedClassName && class_exists($expectedClassName)) {
                    $newClasses = [$expectedClassName];
                }
            }

            foreach ($newClasses as $className) {
                if (is_subclass_of($className, DiscoverableModel::class)) {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->isAbstract()) {
                        $this->registerDiscoveredModel($className, $filePath);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log the error but continue discovery
            error_log("ModelDiscovery: Failed to analyze file {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Get expected class name from file path
     */
    private function getExpectedClassNameFromFile(string $filePath): ?string
    {
        $fileName = basename($filePath, '.php');

        // Convert file name to expected class name
        // e.g., GenBankModel.php -> Symbiota\Helpers\Models\GenBankModel
        $expectedClassName = 'Symbiota\\Helpers\\Models\\' . $fileName;

        return $expectedClassName;
    }

    /**
     * Register a discovered model
     */
    private function registerDiscoveredModel(string $className, string $filePath): void
    {
        try {
            $modelId = $className::getModelId();
            $helpInfo = $className::getHelpInfo();
            
            $this->discoveredModels[$modelId] = [
                'id' => $modelId,
                'class' => $className,
                'file' => $filePath,
                'enabled' => $className::isEnabled(),
                'priority' => $className::getPriority(),
                'description' => $helpInfo['description'] ?? '',
                'parameters' => $helpInfo['parameters'] ?? [],
                'usage' => $helpInfo['usage'] ?? [],
                'examples' => $helpInfo['examples'] ?? [],
                'permissions' => $helpInfo['permissions'] ?? []
            ];
        } catch (\Exception $e) {
            error_log("Failed to register model {$className}: " . $e->getMessage());
        }
    }

    /**
     * Get model by resource ID
     */
    public function getModel(string $modelId): ?array
    {
        $models = $this->discoverModels();

        // Check direct match first
        if (isset($models[$modelId])) {
            return $models[$modelId];
        }

        // Check for pattern matches (e.g., 'css|js|images' patterns)
        foreach ($models as $id => $model) {
            if (strpos($id, '|') !== false) {
                $patterns = explode('|', $id);
                if (in_array($modelId, $patterns)) {
                    return $model;
                }
            }
        }

        return null;
    }

    /**
     * Get all enabled models
     */
    public function getEnabledModels(): array
    {
        $models = $this->discoverModels();
        return array_filter($models, fn($model) => $model['enabled']);
    }

    /**
     * Check if model exists
     */
    public function modelExists(string $modelId): bool
    {
        $models = $this->discoverModels();

        // Check direct match first
        if (isset($models[$modelId])) {
            return true;
        }

        // Check for pattern matches (e.g., 'css|js|images' patterns)
        foreach ($models as $id => $model) {
            if (strpos($id, '|') !== false) {
                $patterns = explode('|', $id);
                if (in_array($modelId, $patterns)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if model is enabled
     */
    public function isModelEnabled(string $modelId): bool
    {
        $model = $this->getModel($modelId);
        return $model && $model['enabled'];
    }

    /**
     * Check if model is accessible via HTTP (not disabled for HTTP access)
     */
    public function isModelAccessibleViaHttp(string $modelId): bool
    {
        if (!$this->isModelEnabled($modelId)) {
            return false;
        }

        if (!$this->config) {
            return true;
        }

        return !$this->config->isComponentDisabledForHttp($modelId);
    }

    /**
     * Create model instance
     */
    public function createModelInstance(string $modelId, array $config = []): ?DiscoverableModel
    {
        $model = $this->getModel($modelId);
        
        if (!$model || !$model['enabled']) {
            return null;
        }

        $className = $model['class'];
        return new $className($config);
    }

    /**
     * Get the current script name dynamically
     */
    private function getScriptName(): string
    {
        // Get the script filename from $_SERVER
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? 'helpers.php';

        // Extract just the filename (not the full path)
        $scriptName = basename($scriptPath);

        // Fallback to helpers.php if we can't determine the script name
        return $scriptName ?: 'helpers.php';
    }

    /**
     * Generate global help with discovered models
     */
    public function generateGlobalHelp(): string
    {
        $models = $this->getEnabledModels();
        $appFile = $this->getScriptName();

        // Generate models list
        $modelsList = [];
        if (empty($models)) {
            $modelsList[] = "  No models available";
        } else {
            foreach ($models as $model) {
                $modelsList[] = sprintf("  %-15s %s", $model['id'], $model['description']);
            }
        }

        // Load template and interpolate variables
        $templateEngine = new TemplateEngine();

        // Set global variables including dynamic URL prefix
        $templateEngine->setGlobalVariables([
            'app_file' => $appFile,
            'base_url' => 'http://localhost:8888',
            'app_url_prefix' => '/?/'
        ]);

        return $templateEngine->template('help/discovery', TemplateFormat::CLI, [
            'app_file' => $appFile,
            'models_list' => implode("\n", $modelsList)
        ]);
    }

    /**
     * Clear discovery cache
     */
    public function clearCache(): void
    {
        $this->discoveredModels = [];
        $this->scanned = false;
    }

    /**
     * Add additional model search path
     */
    public function addModelPath(string $path): void
    {
        if (!in_array($path, $this->modelPaths)) {
            $this->modelPaths[] = $path;
            $this->clearCache(); // Force re-scan
        }
    }

    /**
     * Get models by type
     */
    public function getModelsByType(ModuleType $type): array
    {
        $this->discoverModels();
        $models = [];

        foreach ($this->discoveredModels as $modelInfo) {
            $modelClass = $modelInfo['class'];
            if ($modelClass::getModuleType() === $type) {
                $models[] = [
                    'id' => $modelInfo['id'],
                    'class' => $modelClass,
                    'type' => $type,
                    'enabled' => $modelInfo['enabled'],
                    'description' => $modelInfo['description']
                ];
            }
        }

        return $models;
    }

    /**
     * Get displayable components (for UI)
     */
    public function getDisplayableComponents(): array
    {
        return $this->getModelsByType(ModuleType::COMPONENT);
    }

    /**
     * Get displayable components filtered for HTTP access
     */
    public function getDisplayableComponentsForHttp(): array
    {
        $components = $this->getDisplayableComponents();

        if (!$this->config) {
            return $components;
        }

        // Filter out components disabled for HTTP access
        return array_filter($components, function($component) {
            return !$this->config->isComponentDisabledForHttp($component['id']);
        });
    }

    /**
     * Get service endpoints
     */
    public function getServices(): array
    {
        return $this->getModelsByType(ModuleType::SERVICE);
    }

    /**
     * Get internal libraries
     */
    public function getLibraries(): array
    {
        return $this->getModelsByType(ModuleType::LIBRARY);
    }
}
