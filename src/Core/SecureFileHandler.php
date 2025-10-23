<?php
/**
 * Secure File Handler
 * 
 * Handles file operations outside web space for security.
 * Provides secure access to uploads, backups, and generated files.
 */

namespace Symbiota\Helpers\Core;

class SecureFileHandler
{
    private string $baseStoragePath;
    private string $workingPath;
    private array $allowedExtensions = [];
    
    public function __construct(array $config = [])
    {
        $this->baseStoragePath = $config['storage_path'] ?? sys_get_temp_dir() . '/symbiota_helpers';
        $this->workingPath = $config['working_path'] ?? sys_get_temp_dir() . '/symbiota_helpers_work';
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['zip', 'csv', 'txt', 'json'];
        
        $this->ensureDirectories();
    }
    
    /**
     * Get secure storage path for component
     */
    public function getStoragePath(string $component): string
    {
        $path = $this->baseStoragePath . '/' . $component;
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        return $path;
    }
    
    /**
     * Get working directory for component
     */
    public function getWorkingPath(string $component): string
    {
        $path = $this->workingPath . '/' . $component;
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        return $path;
    }
    
    /**
     * Store file securely
     */
    public function storeFile(string $component, string $filename, string $content): string
    {
        $this->validateFilename($filename);
        $storagePath = $this->getStoragePath($component);
        $filePath = $storagePath . '/' . $filename;
        
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to store file: {$filename}");
        }
        
        chmod($filePath, 0600); // Read/write for owner only
        return $filePath;
    }
    
    /**
     * Retrieve file content
     */
    public function getFileContent(string $component, string $filename): string
    {
        $this->validateFilename($filename);
        $filePath = $this->getStoragePath($component) . '/' . $filename;
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filename}");
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filename}");
        }
        
        return $content;
    }
    
    /**
     * Check if file exists
     */
    public function fileExists(string $component, string $filename): bool
    {
        $this->validateFilename($filename);
        return file_exists($this->getStoragePath($component) . '/' . $filename);
    }
    
    /**
     * Delete file
     */
    public function deleteFile(string $component, string $filename): bool
    {
        $this->validateFilename($filename);
        $filePath = $this->getStoragePath($component) . '/' . $filename;
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return true;
    }
    
    /**
     * List files in component directory
     */
    public function listFiles(string $component): array
    {
        $storagePath = $this->getStoragePath($component);
        $files = [];
        
        if (is_dir($storagePath)) {
            $iterator = new \DirectoryIterator($storagePath);
            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isDot()) {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime()
                    ];
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Validate filename for security
     */
    private function validateFilename(string $filename): void
    {
        // Check for directory traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
            throw new \InvalidArgumentException("Invalid filename: {$filename}");
        }
        
        // Check extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new \InvalidArgumentException("File extension not allowed: {$extension}");
        }
    }
    
    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->baseStoragePath)) {
            mkdir($this->baseStoragePath, 0700, true);
        }
        
        if (!is_dir($this->workingPath)) {
            mkdir($this->workingPath, 0700, true);
        }
    }
}
