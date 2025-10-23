<?php

namespace Symbiota\Helpers\Core;

/**
 * Content Detection Service
 * 
 * Intelligent content type detection using scoring-based analysis
 * Supports PHP, HTML, CSS, JavaScript, JSON, XML, Markdown, YAML, SQL, Shell scripts
 * Provides confidence levels and MIME type mapping for accurate HTTP headers
 */
class ContentDetector
{
    /**
     * Content type to MIME type mapping
     */
    private const MIME_TYPE_MAP = [
        'html' => 'text/html',
        'css' => 'text/css',
        'javascript' => 'application/javascript',
        'json' => 'application/json',
        'markdown' => 'text/markdown',
        'ini' => 'text/plain',
        'text' => 'text/plain'
    ];

    /**
     * File extension to content type mapping
     */
    private const EXTENSION_MAP = [
        'html' => 'html', 'htm' => 'html',
        'css' => 'css',
        'js' => 'javascript', 'mjs' => 'javascript',
        'json' => 'json',
        'md' => 'markdown', 'markdown' => 'markdown',
        'ini' => 'ini', 'conf' => 'ini', 'config' => 'ini'
    ];

    /**
     * Detect content type from content and optional filename
     * 
     * @param string $content The content to analyze
     * @param string $filename Optional filename for extension hints
     * @return array Detection result with type, confidence, and scores
     */
    public static function detectContentType(string $content, string $filename = ''): array
    {
        // First check file extension as a hint
        $extension = $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
        
        // Clean content for analysis
        $content = trim($content);
        $firstLine = strtok($content, "\n");
        
        // Initialize scoring system
        $scores = [
            'html' => 0,
            'css' => 0,
            'javascript' => 0,
            'json' => 0,
            'markdown' => 0,
            'ini' => 0
        ];
        
        // Extension hints (lower weight)
        if (isset(self::EXTENSION_MAP[$extension])) {
            $scores[self::EXTENSION_MAP[$extension]] += 10;
        }
        
        // Content-based detection
        self::detectHTML($content, $scores);
        self::detectCSS($content, $scores);
        self::detectJavaScript($content, $scores);
        self::detectJSON($content, $scores);
        self::detectMarkdown($content, $firstLine, $scores);
        self::detectINI($content, $scores);
        
        // Find highest score
        $maxScore = max($scores);
        $detectedType = array_search($maxScore, $scores);

        // If no significant score, default to text
        if ($maxScore <= 0) {
            $detectedType = 'text';
        }

        // Calculate confidence
        $confidence = min(100, ($maxScore / 50) * 100);

        return [
            'type' => $detectedType ?: 'text',
            'confidence' => round($confidence, 1),
            'scores' => $scores,
            'mime_type' => self::getMimeType($detectedType ?: 'text'),
            'extension' => $extension
        ];
    }

    /**
     * Get MIME type for detected content type
     */
    public static function getMimeType(string $contentType): string
    {
        return self::MIME_TYPE_MAP[$contentType] ?? 'text/plain';
    }

    /**
     * Detect file type from file path
     */
    public static function detectFileType(string $filepath): array
    {
        if (!file_exists($filepath)) {
            return [
                'error' => 'File not found',
                'type' => 'unknown',
                'confidence' => 0
            ];
        }
        
        $content = file_get_contents($filepath);
        $filename = basename($filepath);
        
        // First check MIME type using fileinfo
        if (extension_loaded('fileinfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($filepath);
            
            // If it's a text file or unknown, analyze content
            if (strpos($mimeType, 'text/') === 0 || $mimeType === 'application/octet-stream') {
                return self::detectContentType($content, $filename);
            }
            
            // Return MIME type for non-text files
            return [
                'type' => $mimeType,
                'confidence' => 100,
                'method' => 'mime_type',
                'mime_type' => $mimeType,
                'extension' => pathinfo($filepath, PATHINFO_EXTENSION)
            ];
        }
        
        // Fallback to content analysis if fileinfo not available
        return self::detectContentType($content, $filename);
    }

    /**
     * Check if content type should be treated as CLI text output
     */
    public static function isCliTextType(string $contentType): bool
    {
        return in_array($contentType, ['text', 'markdown', 'ini']);
    }

    /**
     * Check if content type should be treated as structured data
     */
    public static function isStructuredDataType(string $contentType): bool
    {
        return in_array($contentType, ['json']);
    }

    /**
     * Check if content type is web-renderable
     */
    public static function isWebRenderableType(string $contentType): bool
    {
        return in_array($contentType, ['html', 'css', 'javascript', 'json']);
    }

    /**
     * INI file detection patterns
     */
    private static function detectINI(string $content, array &$scores): void
    {
        // INI files have key=value pairs and sections
        if (preg_match('/^\[.*\]$/m', $content)) $scores['ini'] += 25; // Sections like [section]
        if (preg_match('/^\w+\s*=\s*.+$/m', $content)) $scores['ini'] += 20; // key=value pairs
        if (preg_match('/^;\s*.*$/m', $content)) $scores['ini'] += 15; // Comments starting with ;
        if (preg_match('/^#\s*.*$/m', $content)) $scores['ini'] += 10; // Comments starting with #
    }

    /**
     * HTML detection patterns
     */
    private static function detectHTML(string $content, array &$scores): void
    {
        if (preg_match('/<html|<head|<body|<div|<p\s|<span/i', $content)) {
            $scores['html'] += 30;
        }
        if (preg_match('/<\/\w+>/', $content)) $scores['html'] += 20;
        if (preg_match('/<!DOCTYPE/i', $content)) $scores['html'] += 25;
        if (preg_match('/<meta|<link|<script|<style/i', $content)) $scores['html'] += 15;
    }

    /**
     * CSS detection patterns
     */
    private static function detectCSS(string $content, array &$scores): void
    {
        if (preg_match('/\w+\s*\{[^}]*\}/', $content)) $scores['css'] += 25;
        if (preg_match('/@media|@import|@keyframes/', $content)) $scores['css'] += 20;
        if (preg_match('/:\s*[^;]+;/', $content)) $scores['css'] += 15;
        if (preg_match('/color\s*:|font-|margin|padding|border/', $content)) $scores['css'] += 10;
    }

    /**
     * JavaScript detection patterns
     */
    private static function detectJavaScript(string $content, array &$scores): void
    {
        if (preg_match('/function\s*\(|=>\s*{|var\s+\w+|let\s+\w+|const\s+\w+/', $content)) {
            $scores['javascript'] += 25;
        }
        if (preg_match('/console\.|document\.|window\./', $content)) $scores['javascript'] += 20;
        if (preg_match('/getElementById|addEventListener|querySelector/', $content)) $scores['javascript'] += 20;
        if (preg_match('/\$\(.*\)|jQuery/', $content)) $scores['javascript'] += 15;
    }

    /**
     * JSON detection patterns
     */
    private static function detectJSON(string $content, array &$scores): void
    {
        if (preg_match('/^\s*[\{\[]/', $content) && preg_match('/[\}\]]\s*$/', $content)) {
            if (json_decode($content) !== null) {
                $scores['json'] += 40;
            }
        }
    }

    /**
     * Markdown detection patterns
     */
    private static function detectMarkdown(string $content, string $firstLine, array &$scores): void
    {
        if (preg_match('/^#{1,6}\s+/', $firstLine)) $scores['markdown'] += 25;
        if (preg_match('/^\*\s+|^\-\s+|^\d+\.\s+/m', $content)) $scores['markdown'] += 15;
        if (preg_match('/\[.*\]\(.*\)/', $content)) $scores['markdown'] += 20;
        if (preg_match('/```|~~~/', $content)) $scores['markdown'] += 15;
        if (preg_match('/\*\*.*\*\*|__.*__/', $content)) $scores['markdown'] += 10;
    }
}
