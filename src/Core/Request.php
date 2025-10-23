<?php
/**
 * Request Class
 * 
 * Handles HTTP request data parsing and validation.
 * Provides clean interface for accessing request parameters.
 */

namespace Symbiota\Helpers\Core;

class Request
{
    private array $get = [];
    private array $post = [];
    private array $files = [];
    private array $server = [];
    private array $headers = [];
    private string $method = 'GET';
    private string $uri = '';
    private ?string $body = null;
    
    public function __construct(?array $get = null, ?array $post = null, ?array $files = null, ?array $server = null)
    {
        $this->get = $get ?? $_GET ?? [];
        $this->post = $post ?? $_POST ?? [];
        $this->files = $files ?? $_FILES ?? [];
        $this->server = $server ?? $_SERVER ?? [];

        $this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->server['REQUEST_URI'] ?? '';

        // Parse query string from URI if using custom /?/ pattern
        $this->parseCustomQueryString();

        // Debug output for Request construction
        if (function_exists('debugOutput')) {
            debugOutput("Request Constructor", [
                'REQUEST_METHOD' => $this->server['REQUEST_METHOD'] ?? 'NOT SET',
                'REQUEST_URI' => $this->server['REQUEST_URI'] ?? 'NOT SET',
                'SCRIPT_NAME' => $this->server['SCRIPT_NAME'] ?? 'NOT SET',
                'PATH_INFO' => $this->server['PATH_INFO'] ?? 'NOT SET',
                'QUERY_STRING' => $this->server['QUERY_STRING'] ?? 'NOT SET',
                'HTTP_HOST' => $this->server['HTTP_HOST'] ?? 'NOT SET',
                'SERVER_NAME' => $this->server['SERVER_NAME'] ?? 'NOT SET',
                'parsed_method' => $this->method,
                'parsed_uri' => $this->uri
            ]);
        }

        $this->parseHeaders();
        $this->parseBody();
    }
    
    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return $this->method;
    }
    
    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get the base path for the application using UriParser
     * Uses consistent offset detection based on SCRIPT_NAME
     */
    public function getBasePath(): string
    {
        // Use UriParser's consistent offset detection
        // This is always accessible and based purely on SCRIPT_NAME
        return UriParser::getApplicationOffset();
    }

    /**
     * Get the application URL prefix (base path + '?/')
     */
    public function getAppUrlPrefix(): string
    {
        $basePath = $this->getBasePath();

        // If base path is empty (root deployment), ensure we start with /
        if (empty($basePath)) {
            return '/?/';
        }

        return $basePath . (str_ends_with($basePath, '/') ? '' : '/') . '?/';
    }

    /**
     * Get the requested format from URL (e.g., :json, :html)
     */
    public function getFormat(): ?string
    {
        $route = $this->getRoute();
        return $route['format'] ?? null;
    }
    
    /**
     * Get GET parameter
     */
    public function get(string $key, $default = null)
    {
        return $this->get[$key] ?? $default;
    }
    
    /**
     * Get POST parameter
     */
    public function post(string $key, $default = null)
    {
        return $this->post[$key] ?? $default;
    }
    
    /**
     * Get parameter from GET or POST
     */
    public function input(string $key, $default = null)
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }
    
    /**
     * Get all GET parameters
     */
    public function getAllGet(): array
    {
        return $this->get;
    }
    
    /**
     * Get all POST parameters
     */
    public function getAllPost(): array
    {
        return $this->post;
    }
    
    /**
     * Get all input parameters (GET + POST)
     */
    public function getAllInput(): array
    {
        return array_merge($this->get, $this->post);
    }

    /**
     * Get input parameter (alias for input method)
     */
    public function getInput(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->getAllInput();
        }
        return $this->input($key, $default);
    }

    /**
     * Parse route from URI using UriParser
     */
    public function getRoute(): array
    {
        $uri = $this->getUri();
        $scriptName = $this->server['SCRIPT_NAME'] ?? '';

        // Build URI for parsing - combine script context with request URI
        // BUT preserve the /?/ pattern which is our custom routing format
        $parseUri = $uri;
        if ($scriptName && !str_contains($uri, '://') && !str_contains($uri, '?/')) {
            // For relative URIs, we need to determine the base from script name
            // Skip this logic if URI contains our custom /?/ pattern
            $scriptDir = dirname($scriptName);
            if ($scriptDir !== '.' && $scriptDir !== '/') {
                // Check if URI already includes the script directory
                if (!str_starts_with($uri, $scriptDir)) {
                    $parseUri = $scriptDir . $uri;
                }
            }
        }

        // Debug output for route parsing
        if (function_exists('debugOutput')) {
            debugOutput("Route Parsing", [
                'original_uri' => $uri,
                'script_name' => $scriptName,
                'script_dir' => dirname($scriptName),
                'parse_uri' => $parseUri,
                'uri_contains_protocol' => str_contains($uri, '://'),
                'uri_starts_with_script_dir' => str_starts_with($uri, dirname($scriptName))
            ]);
        }

        $parser = UriParser::parse($parseUri);
        $elements = $parser->getElements();

        $route = [
            'elements' => $elements,
            'query' => $this->getAllGet(),
            'format' => $parser->getModifier()
        ];

        // Debug output for parsed route
        if (function_exists('debugOutput')) {
            debugOutput("Parsed Route", [
                'elements' => $elements,
                'query' => $this->getAllGet(),
                'format' => $parser->getModifier(),
                'parser_details' => [
                    'protocol' => $parser->getProtocol(),
                    'host' => $parser->getHost(),
                    'offset' => $parser->getOffset(),
                    'resource' => $parser->getResource(),
                    'query_string' => $parser->getQueryString()
                ]
            ]);
        }

        return $route;
    }

    /**
     * Get the path portion of the request (after base path)
     */
    public function getPath(): string
    {
        $route = $this->getRoute();
        $elements = $route['elements'] ?? [];

        if (empty($elements)) {
            return '/';
        }

        if (empty($elements)) {
            return '/';
        }

        $pathParts = [];
        foreach ($elements as $element) {
            $pathParts[] = $element['name'] ?? '';
        }

        return '/' . implode('/', $pathParts);
    }

    /**
     * Get uploaded file
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
    
    /**
     * Get all uploaded files
     */
    public function getAllFiles(): array
    {
        return $this->files;
    }
    
    /**
     * Get server variable
     */
    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }
    
    /**
     * Get header value
     */
    public function header(string $name, $default = null)
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }
    
    /**
     * Get all headers
     */
    public function getAllHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * Get request body
     */
    public function getBody(): ?string
    {
        return $this->body;
    }
    
    /**
     * Get JSON decoded body
     */
    public function getJsonBody(): ?array
    {
        if ($this->body && $this->isJson()) {
            return json_decode($this->body, true);
        }
        return null;
    }
    
    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return strpos($contentType, 'application/json') !== false;
    }
    
    /**
     * Check if request is AJAX
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }
    
    /**
     * Check if request is HTMX
     */
    public function isHtmx(): bool
    {
        return !empty($this->header('hx-request'));
    }
    
    /**
     * Get HTMX target element
     */
    public function getHtmxTarget(): ?string
    {
        return $this->header('hx-target');
    }
    
    /**
     * Check if request method matches
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }
    
    /**
     * Validate required parameters
     */
    public function validate(array $required): array
    {
        $errors = [];
        $input = $this->getAllInput();
        
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                $errors[] = "Required field '{$field}' is missing";
            }
        }
        
        return $errors;
    }
    
    /**
     * Sanitize input value
     */
    public function sanitize(string $key, string $type = 'string')
    {
        $value = $this->input($key);
        
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT);
            case 'float':
                return filter_var($value, FILTER_VALIDATE_FLOAT);
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL);
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL);
            case 'string':
            default:
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Parse HTTP headers
     */
    private function parseHeaders(): void
    {
        $this->headers = [];
        
        foreach ($this->server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $this->headers[$headerName] = $value;
            }
        }
        
        // Add content-type and content-length if available
        if (isset($this->server['CONTENT_TYPE'])) {
            $this->headers['content-type'] = $this->server['CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $this->headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }
    }
    
    /**
     * Parse request body
     */
    private function parseBody(): void
    {
        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $this->body = file_get_contents('php://input');

            // If it's JSON, merge into post data
            if ($this->isJson() && $this->body) {
                $jsonData = json_decode($this->body, true);
                if (is_array($jsonData)) {
                    $this->post = array_merge($this->post, $jsonData);
                }
            }
        }
    }

    /**
     * Create Request from global variables
     */
    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_FILES, $_SERVER);
    }

    /**
     * Detect the application offset dynamically from the environment
     */
    private static function detectOffset(): string
    {
        // For HTTP requests, use SCRIPT_NAME to detect the actual deployment path
        if (!Environment::isCli()) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!empty($scriptName)) {
                $dir = dirname($scriptName);
                return $dir === '/' ? '' : $dir;
            }
        }

        // For CLI, we need a different approach since we don't have HTTP context
        // Check for environment variable or config that specifies the web offset
        if (Environment::isCli()) {
            // 1. Check for explicit environment variable
            $envOffset = $_ENV['WEB_OFFSET'] ?? $_SERVER['WEB_OFFSET'] ?? null;
            if ($envOffset !== null) {
                return $envOffset === '/' ? '' : $envOffset;
            }

            // 2. Check for a config file that might specify the deployment path
            if (file_exists('config/deployment.php')) {
                $config = include 'config/deployment.php';
                if (isset($config['web_offset'])) {
                    return $config['web_offset'] === '/' ? '' : $config['web_offset'];
                }
            }

            // 3. For CLI, default to empty offset (assume web root deployment)
            // This allows the application to work in any deployment scenario
            return '';
        }

        // Default: no offset (application at web root)
        return '';
    }

    /**
     * Create Request from CLI arguments
     */
    public static function fromCli(array $argv): self
    {
        $get = [];
        $post = [];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '',
            'HTTP_HOST' => 'localhost'
        ];
        $files = [];

        // Parse CLI arguments
        if (count($argv) > 1) {
            // First pass: collect all non-option arguments for route building
            $routeParts = [];
            $parameterArgs = [];
            $i = 1;

            // Define known boolean flags that don't take values
            $booleanFlags = ['help', 'h', 'version', 'v', 'dry-run', 'n', 'remove-all', 'html-output', 'debug'];

            // Separate route parts from parameter arguments
            while ($i < count($argv)) {
                $arg = $argv[$i];

                if (strpos($arg, '-') === 0) {
                    // This is a parameter, collect it and any following value
                    $parameterArgs[] = $arg;

                    // Extract the flag name for boolean check
                    $flagName = ltrim($arg, '-');
                    if (strpos($flagName, '=') !== false) {
                        $flagName = substr($flagName, 0, strpos($flagName, '='));
                    }

                    // Check if this parameter expects a value (not for boolean flags)
                    if (strpos($arg, '=') === false &&
                        !in_array($flagName, $booleanFlags) &&
                        $i + 1 < count($argv) &&
                        strpos($argv[$i + 1], '-') !== 0) {
                        $i++; // Move to the value
                        $parameterArgs[] = $argv[$i];
                    }
                } else {
                    // This is a route part
                    $routeParts[] = $arg;
                }
                $i++;
            }

            // Set the route - convert CLI format to internal URI format
            if (!empty($routeParts)) {
                $route = implode('/', $routeParts);
                $offset = self::detectOffset();
                // Use internal protocol format for UriParser compatibility
                $server['REQUEST_URI'] = 'http://internal' . $offset . '/?/' . ltrim($route, '/');
            }

            // Parse parameter arguments
            for ($i = 0; $i < count($parameterArgs); $i++) {
                $arg = $parameterArgs[$i];

                if (strpos($arg, '--') === 0) {
                    // Handle --key=value or --key value or --key
                    $arg = substr($arg, 2);
                    if (strpos($arg, '=') !== false) {
                        [$key, $value] = explode('=', $arg, 2);
                    } else {
                        $key = $arg;
                        // Check if next argument is a value (not starting with -)
                        if ($i + 1 < count($parameterArgs) && strpos($parameterArgs[$i + 1], '-') !== 0) {
                            $value = $parameterArgs[$i + 1];
                            $i++; // Skip the next argument since we consumed it as a value
                        } else {
                            $value = true; // Boolean flag
                        }
                    }

                    // Handle array parameters (ending with [])
                    if (str_ends_with($key, '[]')) {
                        $baseKey = substr($key, 0, -2);
                        if (!isset($get[$baseKey])) {
                            $get[$baseKey] = [];
                        }
                        $get[$baseKey][] = $value;
                    } elseif (in_array($key, ['sort', 'filter']) && $value !== true) {
                        // Legacy support for comma-separated values
                        if (!isset($get[$key])) {
                            $get[$key] = [];
                        }
                        $values = explode(',', $value);
                        $get[$key] = array_merge($get[$key], $values);
                    } elseif (in_array($key, ['queryAnd', 'queryOr', 'query']) && isset($get[$key])) {
                        // Handle repeated parameters for queryAnd, queryOr, query
                        // Convert to array if not already
                        if (!is_array($get[$key])) {
                            $get[$key] = [$get[$key]];
                        }
                        $get[$key][] = $value;
                    } else {
                        $get[$key] = $value;
                    }
                } elseif (strpos($arg, '-') === 0) {
                    // Handle -k=value or -k value or -k
                    $arg = substr($arg, 1);
                    if (strpos($arg, '=') !== false) {
                        [$key, $value] = explode('=', $arg, 2);
                    } else {
                        $key = $arg;
                        // Check if next argument is a value (not starting with -)
                        if ($i + 1 < count($parameterArgs) && strpos($parameterArgs[$i + 1], '-') !== 0) {
                            $value = $parameterArgs[$i + 1];
                            $i++; // Skip the next argument since we consumed it as a value
                        } else {
                            $value = true; // Boolean flag
                        }
                    }
                    $get[$key] = $value;
                } else {
                    // This shouldn't happen in our new parsing logic, but handle it gracefully
                    $get['unknown_arg'] = $arg;
                }
            }
        }

        return new self($get, $post, $files, $server);
    }

    /**
     * Parse query string from custom /?/ URI pattern
     */
    private function parseCustomQueryString(): void
    {
        // Only parse if we have a URI and it contains our custom pattern
        if (empty($this->uri) || !str_contains($this->uri, '?/')) {
            return;
        }

        try {
            $parser = UriParser::parse($this->uri);
            $queryString = $parser->getQueryString();

            if ($queryString) {
                // Parse the query string into parameters
                parse_str($queryString, $parsedParams);

                // Merge with existing GET parameters, giving priority to parsed params
                $this->get = array_merge($this->get, $parsedParams);
            }
        } catch (\Exception $e) {
            // If parsing fails, continue with original GET parameters
            error_log('Failed to parse custom query string: ' . $e->getMessage());
        }
    }
}
