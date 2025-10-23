<?php
/**
 * Symbiota Portal Helpers v2.0
 * Main Entry Point
 *
 * Handles both HTTP requests and CLI usage:
 * - HTTP: https://dev.local/portal/helpers/?/genbank/catalogNumber/163172&collid[]=2
 * - CLI: php index.php genbank/catalogNumber/163172&collid[]=2
 */

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug mode - set to true to enable debugging output
$DEBUG_MODE = false;

/**
 * Debug output function
 */
function debugOutput($label, $data, $force = false) {
    global $DEBUG_MODE;
    if (!$DEBUG_MODE && !$force) return;

    echo "<div style='background: #f0f0f0; border: 1px solid #ccc; margin: 10px; padding: 10px; font-family: monospace;'>";
    echo "<strong style='color: #d63384;'>DEBUG: $label</strong><br>";
    echo "<pre style='margin: 5px 0; white-space: pre-wrap;'>";
    if (is_array($data) || is_object($data)) {
        print_r($data);
    } else {
        var_dump($data);
    }
    echo "</pre></div>";
}

/**
 * Global function to get application filename
 * Returns the basename of the main entry point file for help content, etc.
 */
function appfile(): string
{
    return basename(__FILE__);
}

/**
 * Generate example config.php content from template file
 *
 * @param bool $includeDev Include development/testing examples
 * @return string PHP code with $CONFIG heredoc
 */
function generateExampleConfigPhp(bool $includeDev = false): string
{
    // Select template file based on --dev flag
    $templateFile = $includeDev
        ? __DIR__ . '/templates/cli/example-config-php-dev.txt'
        : __DIR__ . '/templates/cli/example-config-php-production.txt';

    // Load template content
    if (!file_exists($templateFile)) {
        return "<?php\n// ERROR: Template file not found: {$templateFile}\n";
    }

    return file_get_contents($templateFile);
}

// Load autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Symbiota\Helpers\Core\DiscoveringRouter;
use Symbiota\Helpers\Core\ModelDiscovery;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\Request;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\DatabaseManager;
use Symbiota\Helpers\Core\TemplateEngine;
use Symbiota\Helpers\Core\OutputHandler;
use Symbiota\Helpers\Core\HttpValidator;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Ext\SymbAuth;

try {
    // Debug: Show all server variables
    debugOutput("SERVER Variables", $_SERVER);
    debugOutput("GET Variables", $_GET);
    debugOutput("POST Variables", $_POST);
    debugOutput("REQUEST_URI", $_SERVER['REQUEST_URI'] ?? 'NOT SET');
    debugOutput("SCRIPT_NAME", $_SERVER['SCRIPT_NAME'] ?? 'NOT SET');
    debugOutput("PATH_INFO", $_SERVER['PATH_INFO'] ?? 'NOT SET');
    debugOutput("QUERY_STRING", $_SERVER['QUERY_STRING'] ?? 'NOT SET');

    // Determine execution environment using centralized detection
    $isCli = Environment::isCli();
    debugOutput("Environment Detection", [
        'isCli' => $isCli,
        'PHP_SAPI' => php_sapi_name(),
        'argc' => $argc ?? 'NOT SET',
        'argv' => $argv ?? 'NOT SET'
    ]);

    // For HTTP requests, validate and normalize the request
    if (!$isCli) {
        debugOutput("HTTP Request Processing", "Starting HTTP validation...");

        $httpValidation = HttpValidator::validateAndNormalize();
        debugOutput("HTTP Validation Result", $httpValidation);

        if (!$httpValidation['valid']) {
            // Send error response for invalid HTTP requests
            debugOutput("HTTP Validation Failed", $httpValidation, true);
            http_response_code($httpValidation['status'] ?? 400);
            echo json_encode([
                'error' => $httpValidation['reason'],
                'status' => $httpValidation['status'] ?? 400
            ]);
            exit;
        }

        // Log the request for debugging
        HttpValidator::logRequest('Request validated and normalized');
    }

    // Authentication is now initialized via SymbAuth::initialize($config) above
    debugOutput("Authentication", SymbAuth::getAuthStatus());

    /**
     * Global authentication function for application components
     *
     * @return array Authentication object with user info and permissions
     */
    function app_symbiota_auth(): array {
        return SymbAuth::getAuthStatus();
    }

    // Check for --html-output flag early
    $hasHtmlOutput = $isCli && in_array('--html-output', $argv ?? []);
    debugOutput("CLI Flags", [
        'hasHtmlOutput' => $hasHtmlOutput,
        'argv' => $argv ?? []
    ]);

    // Load configuration using secure methods
    $configFile = null;

    // Method 1: Check for config.php file (most secure for HTTP)
    $phpConfigFile = __DIR__ . '/config.php';
    if (file_exists($phpConfigFile)) {
        $configFile = $phpConfigFile;
    }

    // Method 2: Check for CLI configuration file argument (CLI only)
    if ($isCli && isset($argv)) {
        foreach ($argv as $i => $arg) {
            if ($arg === '-c' && isset($argv[$i + 1])) {
                $configFile = $argv[$i + 1];
                // Make relative paths relative to current directory
                if (!str_starts_with($configFile, '/')) {
                    $configFile = __DIR__ . '/' . $configFile;
                }
                break;
            } elseif (str_starts_with($arg, '--config=')) {
                $configFile = substr($arg, 9);
                // Make relative paths relative to current directory
                if (!str_starts_with($configFile, '/')) {
                    $configFile = __DIR__ . '/' . $configFile;
                }
                break;
            }
        }
    }

    // INI files are no longer supported for security reasons
    // Use config.php instead

    debugOutput("Configuration", [
        'configFile' => $configFile,
        'exists' => $configFile ? file_exists($configFile) : false,
        'readable' => $configFile ? is_readable($configFile) : false,
        'method' => $configFile ? (str_ends_with($configFile, '.php') ? 'PHP' : 'INI') : 'defaults'
    ]);

    $config = Configuration::initialize($configFile);
    debugOutput("Config Loaded", $config->getAll());

    // Initialize template engine and output handler
    debugOutput("Template Engine Initialization", "Loading templates from: " . __DIR__ . '/templates');
    $templateEngine = new TemplateEngine();
    $templateEngine->loadTemplatesFromDirectory(__DIR__ . '/templates');
    // Determine base URL and app URL prefix dynamically
    $baseUrl = 'http://localhost:8888';
    $appUrlPrefix = '/?/';

    // In HTTP context, try to get actual values
    if (!$isCli && isset($_SERVER['REQUEST_URI'])) {
        $parser = new \Symbiota\Helpers\Core\UriParser($_SERVER['REQUEST_URI']);
        $fullUrl = $parser->getAppUrlPrefix();
        if (str_contains($fullUrl, '://')) {
            $parts = parse_url($fullUrl);
            $appUrlPrefix = ($parts['path'] ?? '') . '?/';
            $baseUrl = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
    }

    $templateEngine->setGlobalVariables([
        'app_name' => $config->get('app.name', 'Symbiota Portal Helpers'),
        'app_version' => $config->get('app.version', '2.0.0'),
        'repository_url' => $config->get('app.repository_url', 'https://github.com/symbiota/portal-toolkit'),
        'base_url' => $baseUrl,
        'app_url_prefix' => $appUrlPrefix
    ]);

    // For CLI with --html-output, treat as HTTP for output purposes
    $outputHandler = new OutputHandler($templateEngine, $isCli && !$hasHtmlOutput);

    // Initialize database manager (with CLI args support)
    $dbManager = $isCli ? DatabaseManager::fromCliArgs($argv ?? []) : new DatabaseManager();

    // Initialize SymbAuth with database manager and configuration
    SymbAuth::initialize($dbManager, $config);

    // Set up authentication based on configuration
    $testingUid = $config->get('app.debug.testing_symbiota_uid');
    if (!empty($testingUid)) {
        SymbAuth::setUser((int)$testingUid);
    }

    // Initialize components with self-discovery (reuse existing templateEngine)
    $discovery = new ModelDiscovery([], $config);
    debugOutput("Model Discovery", [
        'discoveredModels' => $discovery->getEnabledModels(),
        'displayableComponents' => $discovery->getDisplayableComponents(),
        'displayableComponentsForHttp' => $discovery->getDisplayableComponentsForHttp(),
        'disabledHttpComponents' => $config->getDisabledHttpComponents(),
        'hasHtmlOutput' => $hasHtmlOutput
    ]);

    $router = new DiscoveringRouter($discovery, $outputHandler, $config->getAll(), $config, $hasHtmlOutput);

    // Handle CLI behavior: show help by default, unless --html-output is specified
    if ($isCli) {
        $hasHelp = in_array('--help', $argv ?? []) || in_array('-h', $argv ?? []);
        $hasSpecialFlag = in_array('--example-ini', $argv ?? []) || in_array('--example-configs', $argv ?? []) || in_array('--example-config-php', $argv ?? []) || in_array('--test-config', $argv ?? []) || in_array('--version', $argv ?? []);
        $hasModel = count($argv ?? []) > 1 && !str_starts_with($argv[1] ?? '', '--');

        // Show help by default if no model specified and no special flags and no --html-output flag
        // OR if global help is explicitly requested (--help without a model)
        if (!$hasHtmlOutput && !$hasSpecialFlag && (!$hasModel || ($hasHelp && !$hasModel))) {
            $helpText = $discovery->generateGlobalHelp();

            // Normalize indentation for better readability in pagers (less/more)
            // Convert leading spaces to tabs (2 spaces = 1 tab for help text)
            $lines = explode("\n", $helpText);
            $normalized = [];
            foreach ($lines as $line) {
                $leadingSpaces = 0;
                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    if ($line[$i] === ' ') {
                        $leadingSpaces++;
                    } else {
                        break;
                    }
                }
                if ($leadingSpaces > 0) {
                    $tabs = str_repeat("\t", intdiv($leadingSpaces, 2));
                    $remainingSpaces = str_repeat(' ', $leadingSpaces % 2);
                    $restOfLine = substr($line, $leadingSpaces);
                    $normalized[] = $tabs . $remainingSpaces . $restOfLine;
                } else {
                    $normalized[] = $line;
                }
            }

            echo implode("\n", $normalized);
            exit(0);
        }

        // If --html-output is specified, remove it from argv and continue with HTML output
        if ($hasHtmlOutput) {
            $argv = array_filter($argv, function($arg) {
                return $arg !== '--html-output';
            });
            $argv = array_values($argv); // Re-index array
        }
    }

    // Handle example INI output
    if ($isCli && in_array('--example-ini', $argv ?? [])) {
        echo Configuration::getExampleIni();
        exit(0);
    }

    // Handle --example-config-php flag
    if ($isCli && in_array('--example-config-php', $argv ?? [])) {
        // Check for --dev flag to include development/testing examples
        $includeDev = in_array('--dev', $argv ?? []);

        // Generate INI content wrapped in heredoc format
        $configTemplate = generateExampleConfigPhp($includeDev);

        // Check if this is being piped to a file (no TTY)
        $isOutputToFile = !stream_isatty(STDOUT);

        if ($isOutputToFile) {
            // Direct output for file redirection
            echo $configTemplate;
        } else {
            // Formatted output for terminal display
            echo "Symbiota Portal Toolkit - config.php Template\n";
            echo "==============================================\n\n";
            echo "Copy the following content to: config.php (in application root)\n";
            echo "This file will be automatically loaded for both HTTP and CLI usage.\n\n";
            echo "Example usage:\n";
            echo "  php index.php --example-config-php > config.php\n";
            echo "  php index.php --example-config-php --dev > config-dev.php  # Include dev/test examples\n";
            echo "  # Edit config.php to customize your settings\n";
            echo "  php index.php images search --query='taxon:lactarius'\n\n";
            echo "--- config.php content ---\n";
            echo $configTemplate;
        }

        exit(0);
    }

    // Handle secure configuration examples
    if ($isCli && in_array('--example-configs', $argv ?? [])) {
        $examples = Configuration::getExampleConfigurations();

        echo "Symbiota Portal Helpers v2.0 - Secure Configuration Examples\n";
        echo "=============================================================\n\n";

        echo "1. config.php File (MOST SECURE for HTTP - Recommended)\n";
        echo "   Save as: config.php (in application root)\n";
        echo "   Usage: Automatically loaded for both HTTP and CLI\n";
        echo "   Security: Cannot be accessed via HTTP, works for web interface\n";
        echo "   Generate: php index.php --example-config-php > config.php\n\n";
        echo $examples['php'] . "\n\n";

        echo "2. Environment Variables (Most Secure for Production)\n";
        echo "   Usage: Set environment variables (overrides all other config)\n";
        echo "   Perfect for: Docker, Kubernetes, production deployments\n\n";
        echo $examples['environment'] . "\n\n";

        echo "3. CLI Configuration Files (Secure for CLI)\n";
        echo "   Save as: /secure/path/config.php (outside web root)\n";
        echo "   Usage: php index.php -c /secure/path/config.php\n";
        echo "   Security: CLI only, not accessible via HTTP\n\n";
        echo $examples['php'] . "\n\n";

        echo "4. Security Note\n";
        echo "   INI files are no longer supported for security reasons.\n";
        echo "   Use config.php exclusively for all configuration.\n\n";

        exit(0);
    }

    // Handle configuration test
    if ($isCli && in_array('--test-config', $argv ?? [])) {
        $results = $config->testConfiguration();
        echo "Configuration Test Results:\n";
        echo "==========================\n\n";

        foreach ($results as $test) {
            $status = $test['status'] === 'pass' ? '✓ PASS' : '✗ FAIL';
            echo sprintf("%-20s %s\n", $test['name'] . ':', $status);
            echo sprintf("%-20s %s\n", '', $test['message']);

            if (!empty($test['warnings'])) {
                foreach ($test['warnings'] as $warning) {
                    echo sprintf("%-20s ⚠ WARNING: %s\n", '', $warning);
                }
            }
            echo "\n";
        }

        // Exit with error code if any tests failed
        $hasFailures = array_reduce($results, function($carry, $test) {
            return $carry || $test['status'] === 'fail';
        }, false);

        exit($hasFailures ? 1 : 0);
    }

    // Create request object
    debugOutput("Request Creation", "Creating request object...");
    $request = $isCli ? Request::fromCli($argv ?? []) : Request::fromGlobals();

    debugOutput("Request Object", [
        'method' => $request->getMethod(),
        'path' => $request->getPath(),
        'basePath' => $request->getBasePath(),
        'appUrlPrefix' => $request->getAppUrlPrefix(),
        'route' => $request->getRoute(),
        'isJson' => $request->isJson(),
        'isAjax' => $request->isAjax(),
        'allInput' => $request->getAllInput()
    ]);

    // Route request through self-discovering router
    debugOutput("Router Processing", "Handling request through router...");
    $response = $router->handleRequest($request);

    // Output response using consistent handler
    $outputHandler->output($response);

} catch (Exception $e) {
    debugOutput("EXCEPTION CAUGHT", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], true);

    $errorResponse = Response::error($e->getMessage(), 500);
    $outputHandler = $outputHandler ?? new OutputHandler(new TemplateEngine());
    $outputHandler->output($errorResponse);
    exit(1);
}
