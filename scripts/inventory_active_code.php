<?php
/**
 * Inventory Active Code
 * 
 * Analyzes all models to find:
 * - Active CLI commands (from switch/case statements)
 * - Active HTTP endpoints (from handleAction methods)
 * - Active methods being called
 * - SQL templates being used
 * - Tests covering functionality
 * 
 * Usage: php scripts/inventory_active_code.php [--model=images] [--format=json|markdown]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\Configuration;

class CodeInventory
{
    private array $inventory = [];
    private array $sqlTemplates = [];
    private array $tests = [];
    
    public function __construct()
    {
        $this->inventory = [
            'models' => [],
            'sql_templates' => [],
            'tests' => [],
            'summary' => []
        ];
    }
    
    /**
     * Analyze all models or a specific model
     */
    public function analyze(?string $modelFilter = null): void
    {
        $config = Configuration::initialize();
        $discovery = new ModelDiscovery([], $config);
        $models = $discovery->discoverModels();
        
        foreach ($models as $modelId => $modelInfo) {
            if ($modelFilter && $modelId !== $modelFilter) {
                continue;
            }
            
            echo "Analyzing model: {$modelId}...\n";
            $this->analyzeModel($modelId, $modelInfo);
        }
        
        // Analyze SQL templates
        echo "Analyzing SQL templates...\n";
        $this->analyzeSqlTemplates();
        
        // Analyze tests
        echo "Analyzing Kahlan tests...\n";
        $this->analyzeTests();
        
        // Generate summary
        $this->generateSummary();
    }
    
    /**
     * Analyze a single model
     */
    private function analyzeModel(string $modelId, array $modelInfo): void
    {
        $className = $modelInfo['class'];
        $filePath = $modelInfo['file'];
        
        if (!file_exists($filePath)) {
            return;
        }
        
        $content = file_get_contents($filePath);
        
        // Find all CLI commands from switch/case statements
        $commands = $this->extractCommands($content);
        
        // Find all public methods
        $methods = $this->extractMethods($content);
        
        // Find SQL template references
        $sqlRefs = $this->extractSqlReferences($content, $modelId);
        
        // Find method calls to identify active code
        $methodCalls = $this->extractMethodCalls($content);
        
        $this->inventory['models'][$modelId] = [
            'class' => $className,
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
        
        // Pattern to match case statements
        // Matches: case 'command': or case "command":
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
        
        // Pattern to match public function declarations
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
        
        // Pattern to match SQL template loading
        // Matches: SqlTemplateParser, executeSqlFile, loadSqlTemplate, etc.
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
     * Extract method calls to identify active code
     */
    private function extractMethodCalls(string $content): array
    {
        $calls = [];
        
        // Pattern to match $this->methodName( calls
        if (preg_match_all("/\\\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/", $content, $matches)) {
            $calls = array_count_values($matches[1]);
            arsort($calls);
        }
        
        return $calls;
    }
    
    /**
     * Analyze SQL templates directory
     */
    private function analyzeSqlTemplates(): void
    {
        $sqlDir = __DIR__ . '/../templates/sql';
        
        if (!is_dir($sqlDir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sqlDir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $relativePath = str_replace($sqlDir . '/', '', $file->getPathname());
                $this->inventory['sql_templates'][$relativePath] = [
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                ];
            }
        }
    }
    
    /**
     * Analyze Kahlan test files
     */
    private function analyzeTests(): void
    {
        $specDir = __DIR__ . '/../spec';
        
        if (!is_dir($specDir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($specDir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Spec.php')) {
                $relativePath = str_replace($specDir . '/', '', $file->getPathname());
                $content = file_get_contents($file->getPathname());
                
                // Count test cases
                $testCount = substr_count($content, "it('") + substr_count($content, 'it("');
                
                // Extract tested methods/commands
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
        
        // Look for method calls in tests
        if (preg_match_all("/->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/", $content, $matches)) {
            $methods = array_unique($matches[1]);
        }
        
        return $methods;
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
            'total_test_cases' => 0
        ];
        
        foreach ($this->inventory['models'] as $model) {
            $this->inventory['summary']['total_commands'] += count($model['commands']);
            $this->inventory['summary']['total_methods'] += count($model['methods']);
        }
        
        foreach ($this->inventory['tests'] as $test) {
            $this->inventory['summary']['total_test_cases'] += $test['test_count'];
        }
    }
    
    /**
     * Output results
     */
    public function output(string $format = 'markdown'): void
    {
        if ($format === 'json') {
            echo json_encode($this->inventory, JSON_PRETTY_PRINT);
        } else {
            $this->outputMarkdown();
        }
    }
    
    /**
     * Output as markdown
     */
    private function outputMarkdown(): void
    {
        echo "# Code Inventory Report\n\n";
        echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Summary
        echo "## Summary\n\n";
        foreach ($this->inventory['summary'] as $key => $value) {
            echo "- " . ucwords(str_replace('_', ' ', $key)) . ": {$value}\n";
        }
        echo "\n";
        
        // Models
        echo "## Models\n\n";
        foreach ($this->inventory['models'] as $modelId => $model) {
            echo "### {$modelId}\n\n";
            echo "- **File**: `{$model['file']}`\n";
            echo "- **Enabled**: " . ($model['enabled'] ? 'Yes' : 'No') . "\n";
            echo "- **Lines**: {$model['line_count']}\n";
            echo "- **Commands**: " . count($model['commands']) . "\n";
            echo "- **Methods**: " . count($model['methods']) . "\n";
            echo "- **SQL References**: " . count($model['sql_references']) . "\n\n";
            
            if (!empty($model['commands'])) {
                echo "**Commands**:\n";
                foreach ($model['commands'] as $cmd) {
                    echo "- `{$cmd}`\n";
                }
                echo "\n";
            }
        }
    }
}

// Parse CLI arguments
$modelFilter = null;
$format = 'markdown';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--model=')) {
        $modelFilter = substr($arg, 8);
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    }
}

// Run inventory
$inventory = new CodeInventory();
$inventory->analyze($modelFilter);
$inventory->output($format);

