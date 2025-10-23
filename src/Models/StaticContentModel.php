<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Response;
use Symbiota\Helpers\Core\ContentDetector;

/**
 * Static Content Delivery Model
 * 
 * Serves static files (CSS, JS, images, etc.) from the templates directory
 * Supports routes like /?/css/main.css, /?/js/main.js, /?/images/logo.png
 */
class StaticContentModel extends Model
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Static content is always public - no authentication required
        $this->requiresAuth = false;
        $this->publicActions = ['*']; // All actions are public for static content
    }

    /**
     * Get model identifier for routing
     */
    public static function getModelId(): string
    {
        return 'css|js|assets';
    }

    /**
     * Get the module type classification
     */
    public static function getModuleType(): ModuleType
    {
        return ModuleType::SERVICE;
    }

    /**
     * Get model priority for routing (higher = more priority)
     */
    public static function getPriority(): int
    {
        return 50; // Lower priority than specific models
    }

    /**
     * Check if model is enabled
     */
    public static function isEnabled(): bool
    {
        return true;
    }

    /**
     * Get help markdown for CLI generation
     */
    public static function getHelpMarkdown(): string
    {
        // Get the entry file name dynamically
        $entryFile = basename($_SERVER['SCRIPT_NAME'] ?? 'helpers.php');

        // Use TemplateEngine for proper variable interpolation
        $templateEngine = new \Symbiota\Helpers\Core\TemplateEngine();

        // Set global variables including dynamic URL prefix
        $templateEngine->setGlobalVariables([
            'app_file' => $entryFile,
            'base_url' => 'http://localhost:8888',
            'app_url_prefix' => '/?/'
        ]);

        try {
            return $templateEngine->template('help/static_content_model', \Symbiota\Helpers\Core\TemplateFormat::CLI, [
                'entry_file' => $entryFile
            ]);
        } catch (\Exception $e) {
            return 'Help documentation not found: ' . $e->getMessage();
        }
    }

    /**
     * Get help information with embedded markdown
     */
    public static function getHelpInfo(): array
    {
        return static::parseHelpMarkdown(static::getHelpMarkdown());
    }

    /**
     * Execute the requested action - implements Model base class method
     */
    protected function executeAction(string $action, array $route, array $params): array
    {
        // For static content routing like /?/css/theme.css
        // The route elements contain: [0] => css, [1] => theme.css
        // The action parameter is the filename (theme.css)

        $elements = $route['elements'] ?? [];

        if (empty($elements)) {
            return $this->errorResponse('No static file path specified', 400);
        }

        // First element is the file type (css, js, etc.)
        $fileType = $elements[0]['name'] ?? '';

        if (empty($fileType)) {
            return $this->errorResponse('No file type specified', 400);
        }

        // Build file path from remaining elements
        $pathElements = [];
        for ($i = 1; $i < count($elements); $i++) {
            $pathElements[] = $elements[$i]['name'];
        }

        if (empty($pathElements)) {
            return $this->errorResponse('No file specified', 400);
        }

        // Build file path
        $filePath = implode('/', $pathElements);

        return $this->serveStaticFile($fileType, $filePath);
    }

    /**
     * Serve a static file from the templates directory (overrides base class with enhanced functionality)
     */
    protected function serveStaticFile(string $fileType, string $filePath): array
    {
        // Security: Prevent directory traversal
        if (strpos($filePath, '..') !== false || strpos($filePath, '\\') !== false) {
            return $this->errorResponse('Invalid file path', 400);
        }

        // Map file type to directory
        $directoryMap = [
            'css' => 'css',
            'js' => 'js',
            'assets' => 'assets'
        ];

        $directory = $directoryMap[$fileType] ?? $fileType;
        
        // Build full file path
        $templatesPath = $this->config['templates_path'] ?? __DIR__ . '/../../templates';
        $fullPath = realpath($templatesPath . '/' . $directory . '/' . $filePath);
        $templatesRealPath = realpath($templatesPath);
        
        // Security: Ensure file is within templates directory
        if (!$fullPath || !$templatesRealPath || strpos($fullPath, $templatesRealPath) !== 0) {
            return $this->errorResponse('File not found', 404);
        }

        // Check if file exists and is readable
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return $this->errorResponse('File not found', 404);
        }

        // Get file info
        $fileExtension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $fileSize = filesize($fullPath);
        $lastModified = filemtime($fullPath);

        // Validate file type
        if (!$this->isAllowedFileType($fileExtension)) {
            return $this->errorResponse('File type not allowed', 403);
        }

        // Read file content
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return $this->errorResponse('Failed to read file', 500);
        }

        // Use ContentDetector for intelligent MIME type detection
        $detection = ContentDetector::detectContentType($content, basename($fullPath));
        $mimeType = $detection['mime_type'];

        // Fallback to extension-based detection if confidence is low
        if ($detection['confidence'] < 50) {
            $mimeType = $this->getMimeType($fileExtension);
        }

        // Return file response with appropriate headers
        return [
            'type' => 'file',
            'content' => $content,
            'mime_type' => $mimeType,
            'content_type' => $detection['type'],
            'detection_confidence' => $detection['confidence'],
            'file_size' => $fileSize,
            'last_modified' => $lastModified,
            'file_path' => $filePath,
            'headers' => [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
                'ETag' => '"' . md5($content) . '"',
                'X-Content-Detection' => $detection['type'] . ' (' . $detection['confidence'] . '%)'
            ]
        ];
    }

    /**
     * Get MIME type for file extension
     */
    private function getMimeType(string $extension): string
    {
        $mimeTypes = [
            // Text files
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            
            // Images
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            
            // Fonts
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            
            // Documents
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            
            // Archives
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            
            // Audio/Video
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'audio/ogg'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Check if file type is allowed
     */
    private function isAllowedFileType(string $extension): bool
    {
        $allowedTypes = [
            'css', 'js', 'json', 'xml', 'txt', 'html', 'htm',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'pdf', 'zip', 'tar', 'gz',
            'mp3', 'mp4', 'webm', 'ogg'
        ];

        return in_array($extension, $allowedTypes);
    }

    // errorResponse method removed - now inherited from Model base class
}
