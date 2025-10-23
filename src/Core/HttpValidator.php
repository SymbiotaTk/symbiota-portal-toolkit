<?php
/**
 * HTTP Validator Module
 * 
 * Validates and normalizes HTTP requests to conform with the CLI framework.
 * Converts web-specific URL formats to standard internal routing.
 */

namespace Symbiota\Helpers\Core;

class HttpValidator
{
    /**
     * Validate and normalize HTTP request for framework compatibility
     */
    public static function validateAndNormalize(): array
    {
        // Only process HTTP requests
        if (php_sapi_name() === 'cli') {
            return ['valid' => false, 'reason' => 'CLI requests do not need HTTP validation'];
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Parse the original URL using UriParser
        $parser = UriParser::parse($requestUri);
        $path = $parser->getOffset() ?? '/';
        $resource = $parser->getResource();
        $query = $parser->getQueryString() ?? '';

        // Analyze the special /?/ format used by Symbiota helpers
        // But do NOT modify any environmental variables - UriParser should handle this
        $normalizedPath = $resource ? $resource : $path;
        $normalizedQuery = $query;

        // Environmental variables should remain untouched
        // The UriParser will provide consistent offset detection based on SCRIPT_NAME
        // and proper resource/element parsing from the original REQUEST_URI

        // Validate HTTP method
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'])) {
            return [
                'valid' => false,
                'reason' => 'Unsupported HTTP method: ' . $method,
                'status' => 405
            ];
        }

        // Validate content type for POST requests
        if ($method === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $validContentTypes = [
                'application/x-www-form-urlencoded',
                'multipart/form-data',
                'application/json',
                'text/plain'
            ];
            
            $isValidContentType = false;
            foreach ($validContentTypes as $validType) {
                if (strpos($contentType, $validType) === 0) {
                    $isValidContentType = true;
                    break;
                }
            }
            
            if (!$isValidContentType && !empty($contentType)) {
                return [
                    'valid' => false,
                    'reason' => 'Unsupported content type: ' . $contentType,
                    'status' => 415
                ];
            }
        }

        return [
            'valid' => true,
            'method' => $method,
            'original_uri' => $requestUri,
            'normalized_uri' => $_SERVER['REQUEST_URI'],
            'path' => $normalizedPath,
            'query' => $normalizedQuery
        ];
    }

    /**
     * Normalize URL path from various formats to standard internal format
     * @deprecated Use UriParser directly instead
     */
    private static function normalizePath(string $path, string $query): string
    {
        // This method is deprecated - UriParser handles normalization
        $uri = $path . ($query ? '?' . $query : '');
        $parser = UriParser::parse($uri);

        return $parser->getResource() ?? $parser->getOffset() ?? '/';
    }

    /**
     * Normalize query parameters
     * @deprecated Use UriParser directly instead
     */
    private static function normalizeQuery(string $path, string $query): string
    {
        // This method is deprecated - UriParser handles normalization
        $uri = $path . ($query ? '?' . $query : '');
        $parser = UriParser::parse($uri);

        return $parser->getQueryString() ?? '';
    }

    /**
     * Convert HTTP request to CLI-compatible arguments using UriParser
     */
    public static function httpToCliArgs(): array
    {
        $validation = self::validateAndNormalize();

        if (!$validation['valid']) {
            return [];
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $parser = UriParser::parse($requestUri);
        $elements = $parser->getElementNames();

        // Convert path elements to CLI arguments
        $args = [];

        if (empty($elements)) {
            // Root request - show dashboard
            $args[] = 'dashboard';
        } else {
            // Add model and action from parsed elements
            foreach ($elements as $element) {
                $args[] = $element;
            }
        }

        // Add query parameters as CLI options
        foreach ($_GET as $key => $value) {
            if (is_array($value)) {
                $args[] = "--{$key}=" . implode(',', $value);
            } else {
                $args[] = "--{$key}={$value}";
            }
        }

        // Add POST data as CLI options
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                $args[] = "--{$key}=" . implode(',', $value);
            } else {
                $args[] = "--{$key}={$value}";
            }
        }

        return $args;
    }

    /**
     * Check if request is AJAX/HTMX
     */
    public static function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ||
               !empty($_SERVER['HTTP_HX_REQUEST']);
    }

    /**
     * Check if request expects JSON response
     */
    public static function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false ||
               strpos($accept, 'text/json') !== false;
    }

    /**
     * Get client IP address
     */
    public static function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Log HTTP request for debugging
     */
    public static function logRequest(string $message = ''): void
    {
        if (defined('DEBUG_HTTP') && DEBUG_HTTP) {
            $logData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
                'ip' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
                'message' => $message
            ];

            error_log('HTTP_DEBUG: ' . json_encode($logData));
        }
    }

    /**
     * Validate URL format
     */
    public static function isValidUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Use filter_var for basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Only allow HTTP and HTTPS schemes
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https']);
    }

    /**
     * Validate HTTP method
     */
    public static function isValidHttpMethod(string $method): bool
    {
        $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        return in_array($method, $validMethods, true);
    }

    /**
     * Validate HTTP header name
     */
    public static function isValidHeaderName(string $name): bool
    {
        if (empty($name)) {
            return false;
        }

        // Header names must not contain spaces, colons, or control characters
        return !preg_match('/[\s:[:cntrl:]]/', $name);
    }

    /**
     * Validate HTTP header value
     */
    public static function isValidHeaderValue(string $value): bool
    {
        // Header values must not contain control characters (except tab)
        return !preg_match('/[[:cntrl:]]/', str_replace("\t", '', $value));
    }

    /**
     * Validate HTTP status code
     */
    public static function isValidStatusCode(int $code): bool
    {
        return $code >= 100 && $code <= 599;
    }

    /**
     * Validate content type
     */
    public static function isValidContentType(string $contentType): bool
    {
        if (empty($contentType)) {
            return false;
        }

        // Basic content type format: type/subtype[; parameters]
        $parts = explode(';', $contentType, 2);
        $mainType = trim($parts[0]);

        if (empty($mainType)) {
            return false;
        }

        // Must contain exactly one slash
        $typeParts = explode('/', $mainType);
        if (count($typeParts) !== 2) {
            return false;
        }

        // Both type and subtype must be non-empty
        return !empty(trim($typeParts[0])) && !empty(trim($typeParts[1]));
    }

    /**
     * Sanitize input by removing potentially dangerous content
     */
    public static function sanitizeInput(?string $input): string
    {
        if ($input === null) {
            return '';
        }

        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Remove script tags and their content completely
        $input = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $input);

        // Remove other potentially dangerous content
        $input = preg_replace('/javascript:/i', '', $input);
        $input = preg_replace('/on\w+\s*=/i', '', $input);

        // Escape remaining HTML
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($input);
    }

    /**
     * Validate parameter name
     */
    public static function validateParameterName(string $name): bool
    {
        if (empty($name)) {
            return false;
        }

        // Parameter names should be alphanumeric with underscores and hyphens
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name);
    }

    /**
     * Check if URL is safe for redirects (prevents open redirect attacks)
     */
    public static function isSafeRedirectUrl(string $url, ?string $allowedHost = null): bool
    {
        if (empty($url)) {
            return false;
        }

        // Check for dangerous schemes first (even without ://)
        $lowerUrl = strtolower($url);
        if (strpos($lowerUrl, 'javascript:') === 0 || strpos($lowerUrl, 'data:') === 0) {
            return false;
        }

        // Allow relative URLs (not starting with protocol)
        if (strpos($url, '://') === false) {
            // Reject protocol-relative URLs (starting with //)
            if (strpos($url, '//') === 0) {
                return false;
            }
            return true;
        }

        // Parse the URL
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // Reject javascript: and data: schemes
        if (isset($parsed['scheme'])) {
            $scheme = strtolower($parsed['scheme']);
            if (!in_array($scheme, ['http', 'https'])) {
                return false;
            }
        }

        // If allowedHost is specified, check if host matches
        if ($allowedHost && isset($parsed['host'])) {
            return strtolower($parsed['host']) === strtolower($allowedHost);
        }

        // If no allowedHost specified, check against current host
        if (isset($parsed['host']) && isset($_SERVER['HTTP_HOST'])) {
            return strtolower($parsed['host']) === strtolower($_SERVER['HTTP_HOST']);
        }

        return false;
    }

    /**
     * Detect potential XSS attempts
     */
    public static function detectXssAttempt(string $input): bool
    {
        $input = strtolower($input);

        // Check for script tags
        if (strpos($input, '<script') !== false) {
            return true;
        }

        // Check for javascript: URLs
        if (strpos($input, 'javascript:') !== false) {
            return true;
        }

        // Check for common event handlers
        $eventHandlers = ['onload', 'onclick', 'onmouseover', 'onerror', 'onsubmit'];
        foreach ($eventHandlers as $handler) {
            if (strpos($input, $handler) !== false) {
                return true;
            }
        }

        return false;
    }
}
