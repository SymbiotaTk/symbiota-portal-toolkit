<?php

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\ContentDetector;

/**
 * Output Handler for consistent response formatting across CLI and HTTP
 * 
 * Provides unified output handling with template support, proper headers,
 * and format-specific rendering for both command line and web interfaces.
 */
class OutputHandler
{
    private TemplateEngine $templateEngine;
    private bool $isCli;
    private array $defaultHeaders = [];

    public function __construct(TemplateEngine $templateEngine, ?bool $isCli = null)
    {
        $this->templateEngine = $templateEngine;
        $this->isCli = $isCli ?? Environment::isCli();

        // Set default headers for HTTP responses
        if (!$this->isCli) {
            $this->defaultHeaders = [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Powered-By' => 'Symbiota Portal Helpers v2.0'
            ];
        }
    }

    /**
     * Get the output format from various sources with intelligent content detection
     */
    private function getOutputFormat(array $data): string
    {
        // Check CLI arguments for --format parameter
        global $argv;
        if (isset($argv)) {
            foreach ($argv as $arg) {
                if (preg_match('/^--format=(.+)$/', $arg, $matches)) {
                    $format = strtolower($matches[1]);
                    if (in_array($format, ['text', 'json', 'html', 'auto'])) {
                        return $format === 'auto' ? $this->detectContentFormat($data) : $format;
                    }
                }
            }
        }

        // Check if format is specified in the response data
        if (isset($data['format'])) {
            $format = strtolower($data['format']);
            if (in_array($format, ['text', 'json', 'html', 'auto'])) {
                return $format === 'auto' ? $this->detectContentFormat($data) : $format;
            }
        }

        // Check query parameters for format
        if (isset($_GET['format'])) {
            $format = strtolower($_GET['format']);
            if (in_array($format, ['text', 'json', 'html', 'auto'])) {
                return $format === 'auto' ? $this->detectContentFormat($data) : $format;
            }
        }

        // Use intelligent content detection for CLI, default to json for HTTP
        return $this->isCli ? $this->detectContentFormat($data) : 'json';
    }

    /**
     * Detect appropriate output format based on content using ContentDetector
     */
    private function detectContentFormat(array $data): string
    {
        // Check if we have content to analyze
        if (isset($data['content']) && is_string($data['content'])) {
            $detection = ContentDetector::detectContentType($data['content']);

            // Map detected content types to output formats
            if (ContentDetector::isCliTextType($detection['type'])) {
                return 'text';
            }

            if (ContentDetector::isStructuredDataType($detection['type'])) {
                return $this->isCli ? 'text' : 'json';
            }

            if (ContentDetector::isWebRenderableType($detection['type'])) {
                return $this->isCli ? 'text' : 'html';
            }
        }

        // Check data type for structured responses
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'error':
                case 'cli':
                case 'help':
                    return 'text';
                case 'json':
                case 'success':
                    return $this->isCli ? 'text' : 'json';
                case 'html':
                case 'fragment':
                    return 'html';
            }
        }

        // Default fallback
        return $this->isCli ? 'text' : 'json';
    }

    /**
     * Check if we should output pure JSON (no extra formatting)
     * @deprecated Use getOutputFormat() instead
     */
    private function shouldOutputPureJson(Response $response): bool
    {
        return $this->getOutputFormat($response->getData()) === 'json';
    }

    /**
     * Check if running in CLI mode
     */
    public function isCli(): bool
    {
        return $this->isCli;
    }

    /**
     * Output a response using appropriate formatting for CLI or HTTP
     */
    public function output(Response $response): void
    {
        if ($this->isCli) {
            $this->outputCli($response);
        } else {
            $this->outputHttp($response);
        }
    }

    /**
     * Normalize indentation for CLI output
     * Converts leading spaces to tabs (4 spaces = 1 tab)
     * This improves readability when piping through less/more
     */
    private function normalizeCliIndentation(string $content): string
    {
        $lines = explode("\n", $content);
        $normalized = [];

        foreach ($lines as $line) {
            // Count leading spaces
            $leadingSpaces = 0;
            $length = strlen($line);

            for ($i = 0; $i < $length; $i++) {
                if ($line[$i] === ' ') {
                    $leadingSpaces++;
                } else {
                    break;
                }
            }

            // Convert groups of 4 spaces to tabs
            if ($leadingSpaces > 0) {
                $tabs = str_repeat("\t", intdiv($leadingSpaces, 4));
                $remainingSpaces = str_repeat(' ', $leadingSpaces % 4);
                $restOfLine = substr($line, $leadingSpaces);
                $normalized[] = $tabs . $remainingSpaces . $restOfLine;
            } else {
                $normalized[] = $line;
            }
        }

        return implode("\n", $normalized);
    }

    /**
     * Output response for CLI interface
     */
    private function outputCli(Response $response): void
    {
        $data = $response->getData();
        $format = $this->getOutputFormat($data);

        // Handle format-specific output
        switch ($format) {
            case 'json':
                // For JSON format, output structured data when available
                $jsonData = $this->prepareStructuredJsonData($data);
                echo json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                return;

            case 'html':
                // For HTML format, output content directly
                if (isset($data['content'])) {
                    echo $data['content'];
                } else {
                    echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>\n";
                }
                return;

            case 'text':
                // For text format, always output content directly (with normalization)
                if (isset($data['content'])) {
                    if (is_array($data['content'])) {
                        echo json_encode($data['content'], JSON_PRETTY_PRINT);
                    } else {
                        echo $this->normalizeCliIndentation($data['content']);
                    }
                    return;
                }
                break;
        }

        // Handle different response types for text output (fallback)
        $type = $data['type'] ?? 'unknown';

        $output = '';
        switch ($type) {
            case 'help':
                $output = $this->renderCliHelp($data);
                break;

            case 'dashboard':
                $output = $this->renderCliDashboard($data);
                break;

            case 'error':
                $output = $this->renderCliError($data);
                break;

            case 'cli':
            case 'success':
                // Direct content output (plain text)
                if (isset($data['content'])) {
                    $output = $data['content'];
                } else {
                    $output = $this->formatJson($data);
                }
                break;

            default:
                // For unknown types, check if we have content to output as text
                if (isset($data['content'])) {
                    $output = $data['content'];
                } else {
                    // Fallback to JSON for complex data
                    $output = $this->formatJson($data);
                }
                break;
        }

        // Normalize indentation for better readability in pagers (less/more)
        echo $this->normalizeCliIndentation($output);
    }

    /**
     * Output response for HTTP interface
     */
    private function outputHttp(Response $response): void
    {
        $data = $response->getData();
        $format = $this->getOutputFormat($data);

        // Check if headers have already been sent to avoid conflicts
        if (!headers_sent()) {
            // Set status code
            http_response_code($response->getStatusCode());

            // Set headers - response headers take precedence over defaults
            $responseHeaders = $response->getHeaders();

            // Start with defaults, but let response headers override them
            $headers = $this->defaultHeaders;

            // Override Content-Type based on format
            if ($format === 'html') {
                $headers['Content-Type'] = 'text/html; charset=utf-8';
            } elseif ($format === 'json') {
                $headers['Content-Type'] = 'application/json; charset=utf-8';
            }

            foreach ($responseHeaders as $name => $value) {
                $headers[$name] = $value; // Response headers override defaults
            }

            foreach ($headers as $name => $value) {
                header($this->templateEngine->sprintf('%s: %s', $name, $value));
            }
        } else {
            // Headers already sent - log this for debugging but continue
            error_log("OutputHandler: Headers already sent, skipping header output");
        }

        // Output body based on format
        if ($format === 'html') {
            // For HTML responses, output the content directly
            if (isset($data['content'])) {
                echo $data['content'];
            } else {
                echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>\n";
            }
        } else {
            // For other responses, use the response body
            echo $response->getBody();
        }
    }

    /**
     * Render CLI help output using template
     */
    private function renderCliHelp(array $data): string
    {
        // If we have pre-formatted content, just return it
        if (isset($data['content'])) {
            return $data['content'];
        }

        // Otherwise use template-based formatting (for legacy support)
        $variables = [
            'message' => $data['message'] ?? 'Help',
            'usage_list' => $this->formatListItems($data['usage'] ?? []),
            'examples_list' => $this->formatListItems($data['examples'] ?? []),
            'database_options_list' => $this->formatListItems($data['database_options'] ?? []),
            'database_status_info' => $this->formatDatabaseStatus($data['database_status'] ?? [])
        ];

        return $this->templateEngine->template('help', TemplateFormat::CLI, $variables);
    }

    /**
     * Render CLI dashboard output using template
     */
    private function renderCliDashboard(array $data): string
    {
        $variables = [
            'title' => $data['title'] ?? 'Dashboard',
            'version' => $data['version'] ?? '2.0',
            'components_list' => $this->formatListItems($data['components'] ?? [], '  - ')
        ];

        return $this->templateEngine->template('dashboard', TemplateFormat::CLI, $variables);
    }

    /**
     * Render CLI error output using template
     */
    private function renderCliError(array $data): string
    {
        $variables = [
            'error' => $data['error'] ?? 'Unknown error',
            'code' => $data['code'] ?? 500,
            'details' => $data['details'] ?? ''
        ];

        return $this->templateEngine->template('error', TemplateFormat::CLI, $variables);
    }

    /**
     * Format array items as a list with optional prefix
     */
    private function formatListItems(array $items, string $prefix = '  '): string
    {
        if (empty($items)) {
            return '  (none)';
        }

        return implode("\n", array_map(
            fn($item) => $prefix . $item,
            $items
        ));
    }

    /**
     * Format database status information
     */
    private function formatDatabaseStatus(array $status): string
    {
        if (empty($status)) {
            return '  No database information available';
        }

        $lines = [];
        foreach ($status as $key => $value) {
            $lines[] = $this->templateEngine->sprintf('  %s: %s', $key, $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Prepare structured JSON data for output
     * Prioritizes structured data over plain text content when available
     */
    private function prepareStructuredJsonData(array $data): array
    {
        // If we have structured data (like collections, users, etc.), prioritize that
        $structuredKeys = ['collections', 'users', 'summary', 'items', 'results'];
        $hasStructuredData = false;

        foreach ($structuredKeys as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                $hasStructuredData = true;
                break;
            }
        }

        if ($hasStructuredData) {
            // Return structured data with metadata
            $result = [
                'type' => $data['type'] ?? 'success',
                'format' => 'json'
            ];

            // Add summary information if available
            if (isset($data['summary'])) {
                $result['summary'] = $data['summary'];
            }

            // Add the main structured data
            foreach ($structuredKeys as $key) {
                if (isset($data[$key])) {
                    $result[$key] = $data[$key];
                }
            }

            // Add additional metadata fields
            $metadataKeys = ['collection_id', 'user_id', 'backup_id', 'status', 'timestamp'];
            foreach ($metadataKeys as $key) {
                if (isset($data[$key])) {
                    $result[$key] = $data[$key];
                }
            }

            // Include content as fallback for compatibility
            if (isset($data['content'])) {
                // Split text content into lines for better JSON structure
                $lines = explode("\n", $data['content']);
                // Remove empty lines at the beginning and end
                $lines = array_filter($lines, function($line, $index) use ($lines) {
                    // Keep non-empty lines, or empty lines that are not at the start/end
                    return trim($line) !== '' || ($index > 0 && $index < count($lines) - 1);
                }, ARRAY_FILTER_USE_BOTH);

                $result['_text_content'] = array_values($lines);
                $result['_text_content_raw'] = $data['content']; // Keep original for backward compatibility
            }

            return $result;
        }

        // Fallback to original data structure
        return $data;
    }

    /**
     * Format data as pretty JSON
     */
    private function formatJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Create HTML dashboard using template
     */
    public function createHtmlDashboard(string $title, string $version, array $components): string
    {
        $componentLinks = [];
        foreach ($components as $component) {
            $componentName = htmlspecialchars($component);
            $componentLinks[] = $this->templateEngine->sprintf(
                '        <li><a href="?/%s">%s</a></li>',
                $component,
                $componentName
            );
        }

        $variables = [
            'title' => htmlspecialchars($title),
            'version' => htmlspecialchars($version),
            'component_links' => implode("\n", $componentLinks)
        ];

        return $this->templateEngine->template('dashboard/main', TemplateFormat::HTML, $variables);
    }

    /**
     * Set default headers for HTTP responses
     */
    public function setDefaultHeaders(array $headers): void
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);
    }

    /**
     * Get the template engine instance
     */
    public function getTemplateEngine(): TemplateEngine
    {
        return $this->templateEngine;
    }
}
