<?php
/**
 * Symbiota Portal Toolkit - Markdown Content Renderer
 *
 * This file renders Markdown content from tk/includes/content/ directory.
 *
 * Usage:
 *   <?php
 *   $markdown_file = 'frontpage/newsbar';  // Relative to tk/includes/content/
 *   include($SERVER_ROOT.'/tk/includes/markdown_content.php');
 *   ?>
 *
 * Optional parameters (set before including):
 *   $markdown_file = 'frontpage/newsbar';  // Required: path to markdown file (without .md extension)
 *   $markdown_lang = 'en';                 // Optional: language code (default: 'en')
 *   $markdown_class = 'newsbar-content';   // Optional: CSS class for wrapper div
 *
 * The file will look for: tk/includes/content/{$markdown_file}.{$markdown_lang}.md
 * If not found, falls back to: tk/includes/content/{$markdown_file}.en.md
 *
 * Author: Philip J Anders <anders2@illinois.edu>
 * License: NCSA
 */

use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\Environment;

// ============================================================================
// FUNCTION DEFINITIONS (must be defined before use)
// ============================================================================

/**
 * Convert Markdown to HTML
 * Basic implementation supporting common Markdown features
 *
 * @param string $markdown Markdown content
 * @return string HTML content
 */
if (!function_exists('convertMarkdownToHtml')) {
function convertMarkdownToHtml(string $markdown): string
{
    $html = $markdown;

    // Headers (must be done before other conversions)
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

    // Horizontal rules
    $html = preg_replace('/^---$/m', '<hr>', $html);

    // Bold and italic
    $html = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

    // Links with target="_blank" - [text](url){:target="_blank"}
    $html = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)\{:target="_blank"\}/', '<a href="$2" target="_blank">$1</a>', $html);

    // Regular links - [text](url)
    $html = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $html);

    // Process lists
    $html = processMarkdownLists($html);

    // Paragraphs - convert double newlines to paragraph breaks
    $html = preg_replace('/\n\n+/', '</p><p>', $html);
    $html = '<p>' . $html . '</p>';

    // Clean up empty paragraphs
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);

    // Clean up paragraphs around block elements
    $html = preg_replace('/<p>\s*(<h[1-6]>)/', '$1', $html);
    $html = preg_replace('/(<\/h[1-6]>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<hr>)/', '$1', $html);
    $html = preg_replace('/(<hr>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<ul>)/', '$1', $html);
    $html = preg_replace('/(<\/ul>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<ol>)/', '$1', $html);
    $html = preg_replace('/(<\/ol>)\s*<\/p>/', '$1', $html);

    return $html;
}
} // End function_exists('convertMarkdownToHtml')

/**
 * Process Markdown lists (unordered and ordered)
 *
 * @param string $text Text with Markdown lists
 * @return string Text with HTML lists
 */
if (!function_exists('processMarkdownLists')) {
function processMarkdownLists(string $text): string
{
    // Split into lines
    $lines = explode("\n", $text);
    $result = [];
    $inList = false;
    $listType = null;

    foreach ($lines as $line) {
        // Trim the line but preserve it for empty line detection
        $trimmedLine = trim($line);

        // Check for unordered list item (- or *)
        if (preg_match('/^[\-\*]\s+(.+)$/', $trimmedLine, $matches)) {
            if (!$inList || $listType !== 'ul') {
                if ($inList) {
                    $result[] = '</' . $listType . '>';
                }
                $result[] = '<ul>';
                $inList = true;
                $listType = 'ul';
            }
            $result[] = '<li>' . $matches[1] . '</li>';
        }
        // Check for ordered list item (1. 2. etc)
        else if (preg_match('/^\d+\.\s+(.+)$/', $trimmedLine, $matches)) {
            if (!$inList || $listType !== 'ol') {
                if ($inList) {
                    $result[] = '</' . $listType . '>';
                }
                $result[] = '<ol>';
                $inList = true;
                $listType = 'ol';
            }
            $result[] = '<li>' . $matches[1] . '</li>';
        }
        // Empty line or non-list content
        else {
            // Only close list if we hit non-empty, non-list content
            if ($inList && $trimmedLine !== '') {
                $result[] = '</' . $listType . '>';
                $inList = false;
                $listType = null;
            }
            // Preserve the line (even if empty, for paragraph processing)
            if ($trimmedLine !== '' || !$inList) {
                $result[] = $line;
            }
        }
    }

    // Close any open list
    if ($inList) {
        $result[] = '</' . $listType . '>';
    }

    return implode("\n", $result);
}
} // End function_exists('processMarkdownLists')

// ============================================================================
// MAIN EXECUTION CODE
// ============================================================================

// Get parameters or use defaults
$markdownFile = isset($markdown_file) ? $markdown_file : null;
$markdownLang = isset($markdown_lang) ? $markdown_lang : 'en';
$markdownClass = isset($markdown_class) ? $markdown_class : 'markdown-content';

if (!$markdownFile) {
    echo '<div class="alert alert-warning">Markdown file not specified. Set $markdown_file before including markdown_content.php</div>';
    return;
}

try {
    // Determine includes directory
    $includesDir = __DIR__;

    // Build file path with language
    $filePath = $includesDir . '/content/' . $markdownFile . '.' . $markdownLang . '.md';

    // Fallback to English if language-specific file doesn't exist
    if (!file_exists($filePath)) {
        $filePath = $includesDir . '/content/' . $markdownFile . '.en.md';
    }

    // Check if file exists
    if (!file_exists($filePath)) {
        echo '<div class="alert alert-info">Content not available: ' . htmlspecialchars($markdownFile) . '</div>';
        return;
    }

    // Read markdown content
    $markdownContent = file_get_contents($filePath);

    if ($markdownContent === false) {
        echo '<div class="alert alert-warning">Unable to read content file</div>';
        return;
    }

    // Simple Markdown to HTML conversion
    // This is a basic implementation - for more features, consider using a library like Parsedown
    $html = convertMarkdownToHtml($markdownContent);

    // Output with wrapper div
    echo '<div class="' . htmlspecialchars($markdownClass) . '">';
    echo $html;
    echo '</div>';

} catch (Exception $e) {
    error_log('Markdown content error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo '<div class="alert alert-warning">Unable to load content: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
