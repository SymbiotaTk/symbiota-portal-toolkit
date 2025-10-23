<?php

namespace Symbiota\Helpers\Core;

/**
 * Application path utilities for consistent file and directory resolution
 */
final class ApplicationPaths
{
    /**
     * Get the main application entry file path
     *
     * @return string The absolute path to the main application file
     */
    public static function applicationFilename(): string
    {
        // Try to detect the actual script being run
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? null;

        // Special case: When running Kahlan tests, use the project root instead of vendor/bin
        if ($scriptPath && (str_contains($scriptPath, 'vendor/bin/kahlan') || str_contains($scriptPath, 'vendor\\bin\\kahlan'))) {
            // Get the directory containing the src folder and default to helpers.php
            $srcDir = dirname(__DIR__);
            $appDir = dirname($srcDir);
            return sprintf('%s/helpers.php', $appDir);
        }

        if ($scriptPath && file_exists($scriptPath)) {
            return $scriptPath;
        }

        // Fallback: Get the directory containing the src folder and default to helpers.php
        $srcDir = dirname(__DIR__);
        $appDir = dirname($srcDir);

        return sprintf('%s/helpers.php', $appDir);
    }

    /**
     * Get the main application directory
     * 
     * @return string The absolute path to the application directory
     */
    public static function applicationDirectory(): string
    {
        return dirname(self::applicationFilename());
    }

    /**
     * Get the templates directory path
     *
     * @return string The absolute path to the templates directory
     */
    public static function templatesDirectory(): string
    {
        return sprintf('%s/templates', self::applicationDirectory());
    }

    /**
     * Get the SQL templates directory path
     *
     * @return string The absolute path to the SQL templates directory
     */
    public static function sqlTemplatesDirectory(): string
    {
        return sprintf('%s/sql', self::templatesDirectory());
    }

    /**
     * Get the HTML templates directory path
     *
     * @return string The absolute path to the HTML templates directory
     */
    public static function htmlTemplatesDirectory(): string
    {
        return sprintf('%s/html', self::templatesDirectory());
    }

    /**
     * Get a specific SQL template file path
     *
     * @param string $module The module name (e.g., 'genbank', 'backup')
     * @param string $template The template name (e.g., 'catalog_search', 'occid_search')
     * @return string The absolute path to the SQL template file
     */
    public static function sqlTemplate(string $module, string $template): string
    {
        return sprintf('%s/%s/%s.sql', self::sqlTemplatesDirectory(), $module, $template);
    }

    /**
     * Get a specific HTML template file path
     *
     * @param string $module The module name (e.g., 'genbank', 'backup')
     * @param string $template The template name (e.g., 'clear_results', 'dev_form')
     * @return string The absolute path to the HTML template file
     */
    public static function htmlTemplate(string $module, string $template): string
    {
        return sprintf('%s/%s/%s.html', self::htmlTemplatesDirectory(), $module, $template);
    }

    /**
     * Load content from a template file
     *
     * @param string $templatePath The absolute path to the template file
     * @param array $variables Optional variables to replace in the template
     * @return string The template content with variables replaced
     * @throws \RuntimeException If the template file is not found
     */
    public static function loadTemplate(string $templatePath, array $variables = []): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: {$templatePath}");
        }

        $content = file_get_contents($templatePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read template file: {$templatePath}");
        }

        // Replace variables in the format {{variable_name}} and {{variable_name|default}}
        if (!empty($variables)) {
            foreach ($variables as $key => $value) {
                // Replace {{key}} format
                $content = str_replace('{{' . $key . '}}', $value, $content);

                // Replace {{key|default}} format - find and replace with actual value
                $pattern = '/\{\{' . preg_quote($key, '/') . '\|([^}]*)\}\}/';
                $content = preg_replace($pattern, $value, $content);
            }
        }

        // Handle remaining {{variable|default}} patterns by using the default value
        $content = preg_replace_callback('/\{\{([^|}]+)\|([^}]*)\}\}/', function($matches) {
            return $matches[2]; // Return the default value
        }, $content);

        return $content;
    }

    /**
     * Load SQL template content
     * 
     * @param string $module The module name
     * @param string $template The template name
     * @param array $variables Optional variables to replace in the template
     * @return string The SQL template content
     */
    public static function loadSqlTemplate(string $module, string $template, array $variables = []): string
    {
        return self::loadTemplate(self::sqlTemplate($module, $template), $variables);
    }

    /**
     * Get the cache directory path
     *
     * @return string The absolute path to the cache directory
     */
    public static function cacheDirectory(): string
    {
        $appDir = dirname(dirname(__DIR__));
        return $appDir . '/cache';
    }

    /**
     * Load HTML template content
     *
     * @param string $module The module name
     * @param string $template The template name
     * @param array $variables Optional variables to replace in the template
     * @return string The HTML template content
     */
    public static function loadHtmlTemplate(string $module, string $template, array $variables = []): string
    {
        return self::loadTemplate(self::htmlTemplate($module, $template), $variables);
    }
}
