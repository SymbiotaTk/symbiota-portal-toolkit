<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\DiscoverableModel;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateEngine;

/**
 * Code Inventory Model
 * 
 * Analyzes codebase to identify active functionality:
 * - CLI commands and HTTP endpoints
 * - Active methods and their usage
 * - SQL templates and their references
 * - Test coverage
 * - Unused code candidates for removal
 */
class InventoryModel extends DiscoverableModel
{
    private array $inventory = [];
    
    public static function getModelId(): string
    {
        return 'inventory';
    }
    
    public static function getModuleType(): ModuleType
    {
        return ModuleType::SERVICE;
    }

    /**
     * Inventory is CLI-only for security reasons
     */
    public static function isAccessibleViaHttp(): bool
    {
        return false;
    }
    
    public static function getHelpMarkdown(): string
    {
        // Get the entry file name dynamically
        $entryFile = static::getScriptName();

        // Load help content from template
        $templatePath = sprintf('%s/cli/help/inventory_model.txt', ApplicationPaths::templatesDirectory());
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
    
    /**
     * Handle action routing
     */
    public function handleAction(string $action, array $route, array $params): array
    {
        switch ($action) {
            case 'index':
            case '':
            case 'help':
                return $this->getHelp();
            
            case 'analyze':
                return $this->analyzeCode($params);
            
            case 'unused':
                return $this->findUnused($params);
            
            case 'coverage':
                return $this->showCoverage($params);
            
            case 'sql-templates':
                return $this->analyzeSqlTemplates($params);
            
            default:
                return [
                    'type' => 'error',
                    'message' => "Unknown action: {$action}. Use 'help' to see available commands."
                ];
        }
    }
    
    /**
     * Analyze code to find active functionality
     */
    private function analyzeCode(array $params): array
    {
        $modelFilter = $params['model'] ?? null;
        $format = $params['format'] ?? 'markdown';
        $includeUnused = isset($params['include-unused']);
        $sqlOnly = isset($params['sql-only']);
        $testsOnly = isset($params['tests-only']);
        
        $this->inventory = [
            'models' => [],
            'sql_templates' => [],
            'tests' => [],
            'summary' => []
        ];
        
        if (!$testsOnly) {
            $this->analyzeModels($modelFilter);
        }
        
        if (!$testsOnly && !$sqlOnly) {
            $this->scanSqlTemplates();
        }
        
        if (!$sqlOnly) {
            $this->scanTests();
        }
        
        if ($includeUnused) {
            $this->identifyUnused();
        }
        
        $this->generateSummary();
        
        return $this->formatOutput($format);
    }
    
    /**
     * Analyze all models or specific model
     */
    private function analyzeModels(?string $modelFilter): void
    {
        $config = Configuration::getInstance();
        $discovery = new ModelDiscovery([], $config);
        $models = $discovery->discoverModels();
        
        foreach ($models as $modelId => $modelInfo) {
            if ($modelFilter && $modelId !== $modelFilter) {
                continue;
            }
            
            $this->analyzeModel($modelId, $modelInfo);
        }
    }
    
    /**
     * Analyze a single model
     */
    private function analyzeModel(string $modelId, array $modelInfo): void
    {
        $filePath = $modelInfo['file'];
        
        if (!file_exists($filePath)) {
            return;
        }
        
        $content = file_get_contents($filePath);
        
        // Extract commands from switch/case statements
        $commands = $this->extractCommands($content);
        
        // Extract public methods
        $methods = $this->extractMethods($content);
        
        // Extract SQL template references
        $sqlRefs = $this->extractSqlReferences($content, $modelId);
        
        // Extract method calls to identify active code
        $methodCalls = $this->extractMethodCalls($content);
        
        $this->inventory['models'][$modelId] = [
            'class' => $modelInfo['class'],
            'file' => $filePath,
            'enabled' => $modelInfo['enabled'],
            'commands' => $commands,
            'methods' => $methods,
            'sql_references' => $sqlRefs,
            'method_calls' => $methodCalls,
            'line_count' => substr_count($content, "\n")
        ];
    }
    
    /**
     * Extract commands from switch/case statements
     */
    private function extractCommands(string $content): array
    {
        $commands = [];
        
        // Match case statements: case 'command': or case "command":
        if (preg_match_all("/case\s+['\"]([^'\"]+)['\"]\s*:/", $content, $matches)) {
            foreach ($matches[1] as $command) {
                if ($command !== 'default' && $command !== '') {
                    $commands[] = $command;
                }
            }
        }
        
        return array_unique($commands);
    }
    
    /**
     * Extract public methods
     */
    private function extractMethods(string $content): array
    {
        $methods = [];
        
        if (preg_match_all("/public\s+(?:static\s+)?function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/", $content, $matches)) {
            $methods = $matches[1];
        }
        
        return $methods;
    }
    
    /**
     * Extract SQL template references
     */
    private function extractSqlReferences(string $content, string $modelId): array
    {
        $refs = [];
        
        $patterns = [
            "/SqlTemplateParser.*?['\"]([^'\"]+\.sql)['\"]/",
            "/executeSqlFile.*?['\"]([^'\"]+\.sql)['\"]/",
            "/loadSqlTemplate.*?['\"]([^'\"]+\.sql)['\"]/",
            "/file_get_contents.*?['\"]([^'\"]+\.sql)['\"]/",
            "/'sql\\/[^']+\\/([^']+\.sql)'/",
            "/\"sql\\/[^\"]+\\/([^\"]+\.sql)\"/",
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $sqlFile) {
                    $refs[] = $sqlFile;
                }
            }
        }
        
        return array_unique($refs);
    }
    
    /**
     * Extract method calls
     */
    private function extractMethodCalls(string $content): array
    {
        $calls = [];
        
        if (preg_match_all("/\\\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/", $content, $matches)) {
            $calls = array_count_values($matches[1]);
            arsort($calls);
        }
        
        return $calls;
    }
    
    /**
     * Scan SQL templates directory
     */
    private function scanSqlTemplates(): void
    {
        $sqlDir = __DIR__ . '/../../templates/sql';

        if (!is_dir($sqlDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sqlDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $relativePath = str_replace($sqlDir . '/', '', $file->getPathname());
                $this->inventory['sql_templates'][$relativePath] = [
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    'used_by' => []
                ];
            }
        }

        // Cross-reference with model SQL references
        foreach ($this->inventory['models'] as $modelId => $model) {
            foreach ($model['sql_references'] as $sqlRef) {
                foreach ($this->inventory['sql_templates'] as $templatePath => &$template) {
                    if (str_contains($templatePath, $sqlRef) || str_contains($sqlRef, basename($templatePath))) {
                        $template['used_by'][] = $modelId;
                    }
                }
            }
        }
    }

    /**
     * Scan test files
     */
    private function scanTests(): void
    {
        $specDir = __DIR__ . '/../../spec';

        if (!is_dir($specDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($specDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Spec.php')) {
                $relativePath = str_replace($specDir . '/', '', $file->getPathname());
                $content = file_get_contents($file->getPathname());

                // Count test cases
                $testCount = substr_count($content, "it('") + substr_count($content, 'it("');

                // Extract tested methods
                $testedMethods = $this->extractTestedMethods($content);

                $this->inventory['tests'][$relativePath] = [
                    'path' => $file->getPathname(),
                    'test_count' => $testCount,
                    'tested_methods' => $testedMethods,
                    'size' => $file->getSize()
                ];
            }
        }
    }

    /**
     * Extract methods being tested
     */
    private function extractTestedMethods(string $content): array
    {
        $methods = [];

        if (preg_match_all("/->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/", $content, $matches)) {
            $methods = array_unique($matches[1]);
        }

        return $methods;
    }

    /**
     * Identify unused code
     */
    private function identifyUnused(): void
    {
        $this->inventory['unused'] = [
            'methods' => [],
            'sql_templates' => [],
            'commands' => []
        ];

        // Find unused methods (methods not called anywhere)
        foreach ($this->inventory['models'] as $modelId => $model) {
            $unusedMethods = [];
            foreach ($model['methods'] as $method) {
                // Skip magic methods and standard interface methods
                if (str_starts_with($method, '__') ||
                    in_array($method, ['handleAction', 'getModelId', 'getHelpMarkdown', 'getModuleType'])) {
                    continue;
                }

                // Check if method is called
                $isCalled = false;
                foreach ($this->inventory['models'] as $checkModel) {
                    if (isset($checkModel['method_calls'][$method])) {
                        $isCalled = true;
                        break;
                    }
                }

                if (!$isCalled) {
                    $unusedMethods[] = $method;
                }
            }

            if (!empty($unusedMethods)) {
                $this->inventory['unused']['methods'][$modelId] = $unusedMethods;
            }
        }

        // Find unused SQL templates
        foreach ($this->inventory['sql_templates'] as $templatePath => $template) {
            if (empty($template['used_by'])) {
                $this->inventory['unused']['sql_templates'][] = $templatePath;
            }
        }
    }

    /**
     * Generate summary statistics
     */
    private function generateSummary(): void
    {
        $this->inventory['summary'] = [
            'total_models' => count($this->inventory['models']),
            'total_commands' => 0,
            'total_methods' => 0,
            'total_sql_templates' => count($this->inventory['sql_templates']),
            'total_tests' => count($this->inventory['tests']),
            'total_test_cases' => 0,
            'unused_methods' => 0,
            'unused_sql_templates' => 0
        ];

        foreach ($this->inventory['models'] as $model) {
            $this->inventory['summary']['total_commands'] += count($model['commands']);
            $this->inventory['summary']['total_methods'] += count($model['methods']);
        }

        foreach ($this->inventory['tests'] as $test) {
            $this->inventory['summary']['total_test_cases'] += $test['test_count'];
        }

        if (isset($this->inventory['unused'])) {
            foreach ($this->inventory['unused']['methods'] as $methods) {
                $this->inventory['summary']['unused_methods'] += count($methods);
            }
            $this->inventory['summary']['unused_sql_templates'] = count($this->inventory['unused']['sql_templates'] ?? []);
        }
    }

    /**
     * Format output
     */
    private function formatOutput(string $format): array
    {
        switch ($format) {
            case 'json':
                return [
                    'type' => 'json',
                    'content' => json_encode($this->inventory, JSON_PRETTY_PRINT)
                ];

            case 'html':
                return [
                    'type' => 'html',
                    'content' => $this->formatHtml()
                ];

            case 'markdown':
            default:
                return [
                    'type' => 'cli',
                    'content' => $this->formatMarkdown()
                ];
        }
    }

    /**
     * Format output as markdown
     */
    private function formatMarkdown(): string
    {
        $output = "# Code Inventory Report\n\n";
        $output .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        // Summary
        $output .= "## Summary\n\n";
        foreach ($this->inventory['summary'] as $key => $value) {
            $output .= "- " . ucwords(str_replace('_', ' ', $key)) . ": {$value}\n";
        }
        $output .= "\n";

        // Models
        $output .= "## Models\n\n";
        foreach ($this->inventory['models'] as $modelId => $model) {
            $output .= "### {$modelId}\n\n";
            $output .= "- **File**: `{$model['file']}`\n";
            $output .= "- **Enabled**: " . ($model['enabled'] ? 'Yes' : 'No') . "\n";
            $output .= "- **Lines**: {$model['line_count']}\n";
            $output .= "- **Commands**: " . count($model['commands']) . "\n";
            $output .= "- **Methods**: " . count($model['methods']) . "\n";
            $output .= "- **SQL References**: " . count($model['sql_references']) . "\n\n";

            if (!empty($model['commands'])) {
                $output .= "**Commands**:\n";
                foreach ($model['commands'] as $cmd) {
                    $output .= "- `{$cmd}`\n";
                }
                $output .= "\n";
            }
        }

        // Unused code
        if (isset($this->inventory['unused'])) {
            $output .= "## Potentially Unused Code\n\n";

            if (!empty($this->inventory['unused']['methods'])) {
                $output .= "### Unused Methods\n\n";
                foreach ($this->inventory['unused']['methods'] as $modelId => $methods) {
                    $output .= "**{$modelId}**:\n";
                    foreach ($methods as $method) {
                        $output .= "- `{$method}()`\n";
                    }
                    $output .= "\n";
                }
            }

            if (!empty($this->inventory['unused']['sql_templates'])) {
                $output .= "### Unused SQL Templates\n\n";
                foreach ($this->inventory['unused']['sql_templates'] as $template) {
                    $output .= "- `{$template}`\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Format output as HTML
     */
    private function formatHtml(): string
    {
        $html = "<h1>Code Inventory Report</h1>";
        $html .= "<p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p>";

        // Summary
        $html .= "<h2>Summary</h2><ul>";
        foreach ($this->inventory['summary'] as $key => $value) {
            $html .= "<li>" . ucwords(str_replace('_', ' ', $key)) . ": <strong>{$value}</strong></li>";
        }
        $html .= "</ul>";

        // Models
        $html .= "<h2>Models</h2>";
        foreach ($this->inventory['models'] as $modelId => $model) {
            $html .= "<h3>{$modelId}</h3>";
            $html .= "<ul>";
            $html .= "<li><strong>Enabled:</strong> " . ($model['enabled'] ? 'Yes' : 'No') . "</li>";
            $html .= "<li><strong>Lines:</strong> {$model['line_count']}</li>";
            $html .= "<li><strong>Commands:</strong> " . count($model['commands']) . "</li>";
            $html .= "<li><strong>Methods:</strong> " . count($model['methods']) . "</li>";
            $html .= "</ul>";

            if (!empty($model['commands'])) {
                $html .= "<p><strong>Commands:</strong></p><ul>";
                foreach ($model['commands'] as $cmd) {
                    $html .= "<li><code>{$cmd}</code></li>";
                }
                $html .= "</ul>";
            }
        }

        return $html;
    }

    /**
     * Find unused code
     */
    private function findUnused(array $params): array
    {
        $modelFilter = $params['model'] ?? null;
        $createPlan = isset($params['create-plan']);

        // Run analysis with unused detection
        $params['include-unused'] = true;
        $this->analyzeCode($params);

        if ($createPlan) {
            return $this->createRemovalPlan($modelFilter);
        }

        return $this->formatOutput($params['format'] ?? 'markdown');
    }

    /**
     * Create removal plan
     */
    private function createRemovalPlan(?string $modelFilter): array
    {
        $plan = "# Code Removal Plan\n\n";
        $plan .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $plan .= "## Instructions\n\n";
        $plan .= "1. Review the list below carefully\n";
        $plan .= "2. Rename files to .REMOVE extension\n";
        $plan .= "3. Run all Kahlan tests\n";
        $plan .= "4. If tests pass, delete .REMOVE files\n";
        $plan .= "5. If tests fail, restore files and update this analysis\n\n";

        $plan .= "## Files to Rename\n\n";

        if (!empty($this->inventory['unused']['sql_templates'])) {
            $plan .= "### SQL Templates\n\n";
            $plan .= "```bash\n";
            foreach ($this->inventory['unused']['sql_templates'] as $template) {
                $fullPath = __DIR__ . '/../../templates/sql/' . $template;
                $plan .= "mv '{$fullPath}' '{$fullPath}.REMOVE'\n";
            }
            $plan .= "```\n\n";
        }

        return [
            'type' => 'cli',
            'content' => $plan
        ];
    }

    /**
     * Show test coverage
     */
    private function showCoverage(array $params): array
    {
        $modelFilter = $params['model'] ?? null;
        $missingOnly = isset($params['missing-only']);

        $this->analyzeModels($modelFilter);
        $this->scanTests();

        $coverage = $this->calculateCoverage();

        return [
            'type' => 'cli',
            'content' => $this->formatCoverage($coverage, $missingOnly)
        ];
    }

    /**
     * Calculate test coverage
     */
    private function calculateCoverage(): array
    {
        $coverage = [];

        foreach ($this->inventory['models'] as $modelId => $model) {
            $testedMethods = [];

            // Find which methods are tested
            foreach ($this->inventory['tests'] as $testPath => $test) {
                if (str_contains($testPath, ucfirst($modelId))) {
                    $testedMethods = array_merge($testedMethods, $test['tested_methods']);
                }
            }

            $testedMethods = array_unique($testedMethods);
            $untestedMethods = array_diff($model['methods'], $testedMethods);

            $coverage[$modelId] = [
                'total_methods' => count($model['methods']),
                'tested_methods' => count($testedMethods),
                'untested_methods' => array_values($untestedMethods),
                'coverage_percent' => count($model['methods']) > 0
                    ? round((count($testedMethods) / count($model['methods'])) * 100, 1)
                    : 0
            ];
        }

        return $coverage;
    }

    /**
     * Format coverage report
     */
    private function formatCoverage(array $coverage, bool $missingOnly): string
    {
        $output = "# Test Coverage Report\n\n";

        foreach ($coverage as $modelId => $data) {
            if ($missingOnly && empty($data['untested_methods'])) {
                continue;
            }

            $output .= "## {$modelId}\n\n";
            $output .= "- Coverage: {$data['coverage_percent']}%\n";
            $output .= "- Tested: {$data['tested_methods']}/{$data['total_methods']} methods\n\n";

            if (!empty($data['untested_methods'])) {
                $output .= "**Untested methods:**\n";
                foreach ($data['untested_methods'] as $method) {
                    $output .= "- `{$method}()`\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Analyze SQL templates
     */
    private function analyzeSqlTemplates(array $params): array
    {
        $unusedOnly = isset($params['unused-only']);
        $modelFilter = $params['model'] ?? null;

        $this->analyzeModels($modelFilter);
        $this->scanSqlTemplates();

        $output = "# SQL Templates Report\n\n";

        foreach ($this->inventory['sql_templates'] as $templatePath => $template) {
            if ($unusedOnly && !empty($template['used_by'])) {
                continue;
            }

            if ($modelFilter) {
                $matchesModel = false;
                foreach ($template['used_by'] as $usedBy) {
                    if ($usedBy === $modelFilter) {
                        $matchesModel = true;
                        break;
                    }
                }
                if (!$matchesModel && !empty($template['used_by'])) {
                    continue;
                }
            }

            $output .= "## {$templatePath}\n\n";
            $output .= "- Size: " . number_format($template['size']) . " bytes\n";
            $output .= "- Modified: {$template['modified']}\n";
            $output .= "- Used by: " . (empty($template['used_by']) ? 'NONE (unused)' : implode(', ', $template['used_by'])) . "\n\n";
        }

        return [
            'type' => 'cli',
            'content' => $output
        ];
    }

    /**
     * Get help
     */
    private function getHelp(): array
    {
        return [
            'type' => 'cli',
            'content' => static::generateCliHelp()
        ];
    }
}
