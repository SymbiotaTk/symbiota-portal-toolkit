<?php
/**
 * Response Class
 * 
 * Handles HTTP response generation and formatting.
 * Supports JSON, HTML, CSV, and other response types.
 */

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\ContentDetector;

class Response
{
    private array $data = [];
    private int $statusCode = 200;
    private array $headers = [];
    private string $contentType = 'application/json';
    
    public function __construct(array $data = [], int $statusCode = 200)
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->setDefaultHeaders();
    }
    
    /**
     * Set response data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
    
    /**
     * Get response data
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * Set status code
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }
    
    /**
     * Get status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * Set header
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * Get header
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * Set content type
     */
    public function setContentType(string $contentType): self
    {
        $this->contentType = $contentType;
        $this->setHeader('Content-Type', $contentType);
        return $this;
    }
    
    /**
     * Create JSON response
     */
    public static function json(array $data, int $statusCode = 200): self
    {
        $response = new self($data, $statusCode);
        $response->setContentType('application/json');
        return $response;
    }
    
    /**
     * Create HTML response
     */
    public static function html(string $html, int $statusCode = 200): self
    {
        $response = new self(['html' => $html], $statusCode);
        $response->setContentType('text/html');
        return $response;
    }
    
    /**
     * Create CSV response
     */
    public static function csv(array $data, string $filename = 'export.csv'): self
    {
        $response = new self(['csv_data' => $data], 200);
        $response->setContentType('text/csv');
        $response->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        return $response;
    }

    /**
     * Create file response with intelligent content detection
     */
    public static function file(string $content, string $mimeType = 'application/octet-stream', int $statusCode = 200, array $headers = [], string $filename = ''): self
    {
        // Use ContentDetector if MIME type is default and we have content to analyze
        if ($mimeType === 'application/octet-stream' && !empty($content)) {
            $detection = ContentDetector::detectContentType($content, $filename);
            $mimeType = $detection['mime_type'];

            // Add detection info to headers for debugging
            $headers['X-Content-Detection'] = $detection['type'] . ' (' . $detection['confidence'] . '%)';
        }

        $response = new self(['file_content' => $content], $statusCode);
        $response->setContentType($mimeType);

        // Set additional headers
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * Create file download response from file path
     */
    public static function download(string $filePath, string $filename = '', string $mimeType = 'application/octet-stream'): self
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return self::error('File not found or not readable', 404);
        }

        $fileSize = filesize($filePath);
        $actualFilename = $filename ?: basename($filePath);

        $response = new self(['file_path' => $filePath], 200);
        $response->setContentType($mimeType);
        $response->setHeader('Content-Disposition', "attachment; filename=\"{$actualFilename}\"");
        $response->setHeader('Content-Length', (string)$fileSize);
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        $response->setHeader('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');

        return $response;
    }

    /**
     * Create error response
     */
    public static function error(string $message, int $statusCode = 500): self
    {
        return new self([
            'error' => $message,
            'code' => $statusCode
        ], $statusCode);
    }
    
    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self(['redirect' => $url], $statusCode);
        $response->setHeader('Location', $url);
        return $response;
    }
    
    /**
     * Send response to browser
     */
    public function send(): void
    {
        // Only send headers if we're in a web environment and headers haven't been sent
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            // Set status code
            http_response_code($this->statusCode);

            // Finalize headers based on environment and content
            $this->finalizeHeaders();

            // Send headers
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        // Send body
        echo $this->getBody();
    }

    /**
     * Finalize headers based on environment and content
     */
    private function finalizeHeaders(): void
    {
        // Always update Date header to current time
        $this->headers['Date'] = gmdate('D, d M Y H:i:s T');

        // Set content length if not already set
        if (!isset($this->headers['Content-Length'])) {
            $body = $this->getBody();
            $this->headers['Content-Length'] = (string)strlen($body);
        }

        // Set appropriate cache headers based on content type
        if (!isset($this->headers['Cache-Control'])) {
            if (strpos($this->contentType, 'text/css') === 0 ||
                strpos($this->contentType, 'application/javascript') === 0 ||
                strpos($this->contentType, 'image/') === 0) {
                // Cache static assets
                $this->headers['Cache-Control'] = 'public, max-age=3600';
            } else {
                // Don't cache dynamic content
                $this->headers['Cache-Control'] = 'no-cache, must-revalidate';
            }
        }

        // Ensure proper charset for text content
        if (strpos($this->contentType, 'text/') === 0 && strpos($this->contentType, 'charset=') === false) {
            $this->headers['Content-Type'] = $this->contentType . '; charset=UTF-8';
        }
    }
    
    /**
     * Get response content (alias for getBody for compatibility)
     */
    public function getContent(): string
    {
        return $this->getBody();
    }

    /**
     * Get response body
     */
    public function getBody(): string
    {
        // Handle file content directly
        if (isset($this->data['file_content'])) {
            return $this->data['file_content'];
        }

        // Handle file path - read and return file contents
        if (isset($this->data['file_path'])) {
            $filePath = $this->data['file_path'];
            if (file_exists($filePath) && is_readable($filePath)) {
                return file_get_contents($filePath);
            }
            return '';
        }

        switch ($this->contentType) {
            case 'application/json':
                return json_encode($this->data, JSON_PRETTY_PRINT);

            case 'text/html':
                return $this->data['html'] ?? '';

            case 'text/csv':
                return $this->generateCsv($this->data['csv_data'] ?? []);

            case 'text/plain':
                return $this->data['text'] ?? print_r($this->data, true);

            default:
                return json_encode($this->data);
        }
    }
    
    /**
     * Check if response is successful
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
    
    /**
     * Check if response is error
     */
    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }
    
    /**
     * Add data to response
     */
    public function addData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }
    
    /**
     * Remove data from response
     */
    public function removeData(string $key): self
    {
        unset($this->data[$key]);
        return $this;
    }
    
    /**
     * Set HTMX-specific headers
     */
    public function setHtmxHeaders(array $htmxHeaders): self
    {
        foreach ($htmxHeaders as $name => $value) {
            $this->setHeader("HX-{$name}", $value);
        }
        return $this;
    }
    
    /**
     * Set HTMX trigger
     */
    public function setHtmxTrigger(string $event, array $data = []): self
    {
        $trigger = empty($data) ? $event : json_encode([$event => $data]);
        return $this->setHeader('HX-Trigger', $trigger);
    }
    
    /**
     * Set HTMX redirect
     */
    public function setHtmxRedirect(string $url): self
    {
        return $this->setHeader('HX-Redirect', $url);
    }
    
    /**
     * Generate CSV content
     */
    private function generateCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Write header row if data is associative
        if (is_array($data[0])) {
            fputcsv($output, array_keys($data[0]), ',', '"', '\\');
        }
        
        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, is_array($row) ? $row : [$row], ',', '"', '\\');
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Set default headers
     */
    private function setDefaultHeaders(): void
    {
        $this->headers = [
            'Content-Type' => $this->contentType,
            'Date' => gmdate('D, d M Y H:i:s T'),
            'X-Powered-By' => 'Symbiota Portal Helpers v2.0',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Expires' => 'Mon, 26 Jul 1997 05:00:00 GMT',
            'Server' => 'Symbiota Portal Helpers/2.0'
        ];
    }
}
