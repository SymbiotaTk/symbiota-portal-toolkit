<?php

namespace Symbiota\Helpers\Core;

/**
 * Custom URI Parser for Symbiota Portal Helpers
 *
 * Handles the custom URI format:
 * protocol:host:port:offset:resource:elements:modifier:query_string:hash
 *
 * Examples:
 * - http://localhost:8080/portal/help/?/genbank/devForm
 * - mysql://user:pass@localhost:3306/database
 * - /?/genbank/catalogNumber/163172&collid[]=2
 */
class UriParser
{
    private string $originalUri;
    private array $parsed;

    public function __construct(string $uri)
    {
        $this->originalUri = $uri;
        $normalizedUri = self::normalizeUri($uri);

        // Debug output for URI parsing
        if (function_exists('debugOutput')) {
            debugOutput("UriParser Construction", [
                'original_uri' => $uri,
                'normalized_uri' => $normalizedUri,
                'detected_offset' => self::detectOffset()
            ]);
        }

        $this->parsed = $this->parseUri($normalizedUri);

        // Debug output for parsed result
        if (function_exists('debugOutput')) {
            debugOutput("UriParser Parsed Result", $this->parsed);
        }
    }

    /**
     * Parse URI into structured components
     */
    private function parseUri(string $uri): array
    {
        $components = [
            'protocol' => null,
            'username' => null,
            'password' => null,
            'host' => null,
            'port' => null,
            'offset' => null,
            'resource' => null,
            'elements' => [],
            'modifier' => null,
            'query_string' => null,
            'hash' => null,
            'original' => $uri
        ];

        // Handle hash fragment first
        if (strpos($uri, '#') !== false) {
            $parts = explode('#', $uri, 2);
            $uri = $parts[0];
            $components['hash'] = $parts[1];
        }

        // Handle query string and our custom /?/ pattern
        if (strpos($uri, '?') !== false) {
            $parts = explode('?', $uri, 2);
            $pathPart = $parts[0];
            $queryPart = $parts[1];

            // Check if this is our custom /?/ pattern
            if (str_starts_with($queryPart, '/')) {
                // This is a resource path, check if there's a query string after it
                if (strpos($queryPart, '?') !== false) {
                    $resourceParts = explode('?', $queryPart, 2);
                    $components['resource'] = $resourceParts[0];
                    $components['query_string'] = $resourceParts[1];
                } elseif (strpos($queryPart, '&') !== false) {
                    // Also handle & as query string separator (e.g., /?/images/autocomplete-fields&q=fam)
                    $resourceParts = explode('&', $queryPart, 2);
                    $components['resource'] = $resourceParts[0];
                    $components['query_string'] = $resourceParts[1];
                } else {
                    $components['resource'] = $queryPart;
                }
                $uri = $pathPart; // Continue parsing the base path
            } else {
                // This is a real query string
                $components['query_string'] = $queryPart;
                $uri = $pathPart;
            }
        }

        // Handle protocol and host
        if (preg_match('#^([a-z][a-z0-9+.-]*):\/\/(.*)#i', $uri, $matches)) {
            $components['protocol'] = strtolower($matches[1]);
            $remaining = $matches[2];

            // Special handling for SQLite and file URIs (sqlite:///path, file:///path)
            if (in_array($components['protocol'], ['sqlite', 'file'])) {
                $components['host'] = '';
                // These URIs have format protocol:///path where /// = :// + empty host + /
                // The remaining part already includes the leading slash
                $pathPart = $remaining;
            } elseif ($this->isDatabaseProtocol($components['protocol'])) {
                // For other database URIs, handle user:pass@host:port/db format
                if (preg_match('#^([^/]+)(/.*)?$#', $remaining, $dbMatches)) {
                    $hostPart = $dbMatches[1];
                    $pathPart = $dbMatches[2] ?? '';

                    // Parse authentication and host:port from hostPart
                    $this->parseHostWithAuth($hostPart, $components);
                } else {
                    $pathPart = $remaining;
                }
            } else {
                // For HTTP URIs, parse user:pass@host:port/path format
                if (preg_match('#^([^/]+)(.*)#', $remaining, $hostMatches)) {
                    $hostPart = $hostMatches[1];
                    $pathPart = $hostMatches[2] ?? '';

                    // Parse authentication and host:port from hostPart
                    $this->parseHostWithAuth($hostPart, $components);
                } else {
                    // Handle edge case like "http://" with empty remaining
                    $components['host'] = $remaining;
                    $pathPart = '';
                }
            }
        } else {
            // No protocol, treat as path-only
            $pathPart = $uri;
        }

        // Parse offset (base path before resource)
        if (!empty($pathPart)) {
            // Remove trailing slash for consistency
            $components['offset'] = rtrim($pathPart, '/');
            // For path-only URIs, preserve root slash if that's all we have
            if ($components['offset'] === '' && !$components['protocol']) {
                $components['offset'] = '/';
            }
        } elseif (!$components['protocol'] && !empty($this->originalUri)) {
            // For path-only URIs without explicit path, default to root
            $components['offset'] = '/';
        }

        // Parse resource elements if we have a resource
        if ($components['resource']) {
            $resourcePath = ltrim($components['resource'], '/');
            if (!empty($resourcePath)) {
                $elements = array_filter(explode('/', $resourcePath));
                
                foreach ($elements as $index => $element) {
                    $elementData = [
                        'name' => $element,
                        'index' => $index,
                        'modifier' => null
                    ];

                    // Check for modifier (e.g., "collections:json")
                    if (strpos($element, ':') !== false) {
                        $parts = explode(':', $element, 2);
                        $elementData['name'] = $parts[0];
                        $elementData['modifier'] = $parts[1];
                        
                        // Set global modifier from last element with modifier
                        $components['modifier'] = $parts[1];
                    }

                    $components['elements'][] = $elementData;
                }
            }
        }

        return $components;
    }

    /**
     * Parse host part with optional authentication credentials
     * Handles formats: user:pass@host:port, user@host:port, host:port
     */
    private function parseHostWithAuth(string $hostPart, array &$components): void
    {
        // Check for authentication credentials (user:pass@host or user@host)
        if (strpos($hostPart, '@') !== false) {
            $parts = explode('@', $hostPart, 2);
            $authPart = $parts[0];
            $hostPortPart = $parts[1];

            // Parse authentication credentials
            if (strpos($authPart, ':') !== false) {
                $authParts = explode(':', $authPart, 2);
                $components['username'] = $authParts[0];
                $components['password'] = $authParts[1];
            } else {
                $components['username'] = $authPart;
            }

            $hostPart = $hostPortPart;
        }

        // Parse host:port
        if (preg_match('#^(.+):(\d+)$#', $hostPart, $portMatches)) {
            $components['host'] = $portMatches[1];
            $components['port'] = (int)$portMatches[2];
        } else {
            $components['host'] = $hostPart;
        }
    }

    /**
     * Get original URI
     */
    public function getOriginal(): string
    {
        return $this->originalUri;
    }

    /**
     * Get protocol (http, https, mysql, etc.)
     */
    public function getProtocol(): ?string
    {
        return $this->parsed['protocol'];
    }

    /**
     * Get username
     */
    public function getUsername(): ?string
    {
        return $this->parsed['username'];
    }

    /**
     * Get password
     */
    public function getPassword(): ?string
    {
        return $this->parsed['password'];
    }

    /**
     * Get host
     */
    public function getHost(): ?string
    {
        return $this->parsed['host'];
    }

    /**
     * Get port
     */
    public function getPort(): ?int
    {
        return $this->parsed['port'];
    }

    /**
     * Get offset (base path before resource)
     */
    public function getOffset(): ?string
    {
        return $this->parsed['offset'];
    }

    /**
     * Get resource path
     */
    public function getResource(): ?string
    {
        return $this->parsed['resource'];
    }

    /**
     * Get parsed elements
     */
    public function getElements(): array
    {
        return $this->parsed['elements'];
    }

    /**
     * Get element names only
     */
    public function getElementNames(): array
    {
        return array_column($this->parsed['elements'], 'name');
    }

    /**
     * Get modifier (format specifier)
     */
    public function getModifier(): ?string
    {
        return $this->parsed['modifier'];
    }

    /**
     * Get query string
     */
    public function getQueryString(): ?string
    {
        return $this->parsed['query_string'];
    }

    /**
     * Get hash fragment
     */
    public function getHash(): ?string
    {
        return $this->parsed['hash'];
    }

    /**
     * Get all parsed components
     */
    public function getAll(): array
    {
        return $this->parsed;
    }

    /**
     * Check if this is a database protocol
     */
    private function isDatabaseProtocol(string $protocol): bool
    {
        return in_array($protocol, ['mysql', 'mysqli', 'pgsql', 'sqlite']);
    }

    /**
     * Check if this is a database connection URI
     */
    public function isDatabaseUri(): bool
    {
        return $this->isDatabaseProtocol($this->getProtocol() ?? '');
    }

    /**
     * Check if this is an HTTP URI
     */
    public function isHttpUri(): bool
    {
        return in_array($this->getProtocol(), ['http', 'https']);
    }

    /**
     * Get base URL (protocol + host + port + offset)
     */
    public function getBaseUrl(): string
    {
        $parts = [];

        if ($this->getProtocol()) {
            $parts[] = $this->getProtocol() . '://';

            if ($this->getHost()) {
                $hostPart = $this->getHost();
                if ($this->getPort()) {
                    $hostPart = sprintf('%s:%s', $hostPart, $this->getPort());
                }
                $parts[] = $hostPart;
            }
        }

        if ($this->getOffset()) {
            $offset = $this->getOffset();
            // For path-only URIs, preserve the root slash
            if (!$this->getProtocol() && $offset === '/') {
                $parts[] = $offset;
            } else {
                // Don't add trailing slash for base URL
                $parts[] = rtrim($offset, '/');
            }
        }

        return implode('', $parts);
    }

    /**
     * Get app URL prefix (base URL + /?/)
     */
    public function getAppUrlPrefix(): string
    {
        $base = $this->getBaseUrl();

        // If base is empty (root deployment), ensure we start with /
        if (empty($base)) {
            return '/?/';
        }

        return $base . (str_ends_with($base, '/') ? '' : '/') . '?/';
    }

    /**
     * Static factory method
     */
    public static function parse(string $uri): self
    {
        return new self($uri);
    }

    /**
     * Get the application offset (publicly accessible)
     * This should be accessible from any src/Core or src/Model code
     */
    public static function getApplicationOffset(): string
    {
        return self::detectOffset();
    }

    /**
     * Get the application offset for testing (forces HTTP mode)
     * @internal For testing purposes only
     */
    public static function getApplicationOffsetForTesting(): string
    {
        return self::detectOffset(true);
    }

    /**
     * Detect the application offset dynamically from the environment
     * @param bool|null $forceHttpMode For testing - force HTTP mode detection
     */
    private static function detectOffset(?bool $forceHttpMode = null): string
    {
        // For HTTP requests (or forced HTTP mode), use SCRIPT_NAME to detect the actual deployment path
        $isHttpMode = $forceHttpMode ?? (php_sapi_name() !== 'cli');

        if ($isHttpMode) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!empty($scriptName)) {
                $dir = dirname($scriptName);
                return $dir === '/' ? '' : $dir;
            }
        }

        // For CLI, we need a different approach since we don't have HTTP context
        // Check for environment variable or config that specifies the web offset
        if (php_sapi_name() === 'cli') {
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
     * Normalize URI for better compatibility
     *
     * @param string $uri
     * @return string
     */
    private static function normalizeUri(string $uri): string
    {
        $offset = self::detectOffset();

        // If URI starts with detected offset/?/ but has no protocol, add internal protocol
        if (!empty($offset) && str_starts_with($uri, $offset . '/?/') && !str_contains($uri, '://')) {
            return 'http://internal' . $uri;
        }

        // If URI starts with /?/ but has no protocol, add internal protocol with detected offset
        if (str_starts_with($uri, '/?/') && !str_contains($uri, '://')) {
            return 'http://internal' . $offset . $uri;
        }

        return $uri;
    }
}
