<?php

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;

/**
 * Template Engine for consistent string interpolation and output formatting
 *
 * Provides centralized template processing with placeholder substitution,
 * organized template loading by format, and consistent formatting across CLI and HTTP responses.
 */
class TemplateEngine
{
    private array $templates = [];
    private array $globalVariables = [];
    private string $templatesBasePath;

    public function __construct(?string $templatesBasePath = null)
    {
        $this->templatesBasePath = $templatesBasePath ?? ApplicationPaths::templatesDirectory();
    }

    /**
     * Load and render a template by name and format
     *
     * @param string $name Template name (without extension)
     * @param TemplateFormat $format Template format (determines subdirectory and extension)
     * @param array $variables Variables for interpolation
     * @return string Rendered template
     */
    public function template(string $name, TemplateFormat $format, array $variables = []): string
    {
        $templateKey = $this->sprintf('%s.%s', $format->value, $name);

        // Check if template is already loaded
        if (!isset($this->templates[$templateKey])) {
            $this->loadTemplate($name, $format);
        }

        // Render with variables
        return $this->render($this->templates[$templateKey], $variables);
    }

    /**
     * Load a template from file system
     */
    private function loadTemplate(string $name, TemplateFormat $format): void
    {
        $templateKey = $this->sprintf('%s.%s', $format->value, $name);
        $filePath = $this->sprintf(
            '%s/%s/%s.%s',
            $this->templatesBasePath,
            $format->getDirectory(),
            $name,
            $format->getExtension()
        );

        if (!file_exists($filePath)) {
            throw new \RuntimeException($this->sprintf(
                'Template file not found: %s',
                $filePath
            ));
        }

        $this->templates[$templateKey] = file_get_contents($filePath);
    }

    /**
     * Register a template with a name
     */
    public function registerTemplate(string $name, string $template): void
    {
        $this->templates[$name] = $template;
    }

    /**
     * Set global variables available to all templates
     */
    public function setGlobalVariables(array $variables): void
    {
        // Always include asset_version as a global variable
        if (!isset($variables['asset_version'])) {
            $variables['asset_version'] = AssetVersion::getGlobal();
        }

        $this->globalVariables = array_merge($this->globalVariables, $variables);
    }

    /**
     * Get a global variable value
     */
    public function getGlobalVariable(string $key, $default = null)
    {
        return $this->globalVariables[$key] ?? $default;
    }

    /**
     * Get global variables
     */
    public function getGlobalVariables(): array
    {
        return $this->globalVariables;
    }

    /**
     * Render a template with variables
     * 
     * @param string $template Template string or registered template name
     * @param array $variables Variables for interpolation
     * @return string Rendered template
     */
    public function render(string $template, array $variables = []): string
    {
        // Check if it's a registered template name
        if (isset($this->templates[$template])) {
            $template = $this->templates[$template];
        }

        // Merge with global variables (local variables take precedence)
        $allVariables = array_merge($this->globalVariables, $variables);

        // Replace placeholders with variables
        return $this->interpolate($template, $allVariables);
    }

    /**
     * Interpolate variables into template using placeholder syntax
     *
     * Supports multiple placeholder formats:
     * - {variable} - Simple variable substitution
     * - {variable|default} - Variable with default value
     * - {variable|filter:arg} - Variable with filter (future extension)
     */
    private function interpolate(string $template, array $variables): string
    {
        // Use a more specific regex that matches template variables but not CSS
        // Template variables should contain only letters, numbers, underscores, and pipes
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*(?:\|[^}]*)?)\}/',
            function ($matches) use ($variables) {
                $placeholder = $matches[1];

                // Handle default values: {variable|default}
                if (strpos($placeholder, '|') !== false) {
                    [$varName, $default] = explode('|', $placeholder, 2);
                    return $variables[trim($varName)] ?? $default;
                }

                // Simple variable substitution
                return $variables[trim($placeholder)] ?? $matches[0];
            },
            $template
        );
    }

    /**
     * Format string using sprintf with better error handling
     */
    public function sprintf(string $format, ...$args): string
    {
        try {
            return sprintf($format, ...$args);
        } catch (\ArgumentCountError $e) {
            // Fallback to simple concatenation if sprintf fails
            return $format . ' [SPRINTF_ERROR: ' . implode(', ', $args) . ']';
        } catch (\ValueError $e) {
            // Fallback for value errors
            return $format . ' [SPRINTF_ERROR: ' . implode(', ', $args) . ']';
        } catch (\Throwable $e) {
            // Catch any other errors
            return $format . ' [SPRINTF_ERROR: ' . $e->getMessage() . ']';
        }
    }

    /**
     * Load templates from a directory
     */
    public function loadTemplatesFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Load templates from multiple subdirectories and file extensions
        $patterns = [
            $directory . '/*.template',
            $directory . '/html/*.html',
            $directory . '/cli/*.txt',
            $directory . '/sql/*.sql'
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                $pathInfo = pathinfo($file);
                $name = $pathInfo['filename'];
                $content = file_get_contents($file);
                if ($content !== false) {
                    $this->registerTemplate($name, $content);
                }
            }
        }
    }

    /**
     * Get all registered template names
     */
    public function getTemplateNames(): array
    {
        return array_keys($this->templates);
    }

    /**
     * Check if a template is registered
     */
    public function hasTemplate(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    /**
     * Remove a registered template
     */
    public function unregisterTemplate(string $name): void
    {
        unset($this->templates[$name]);
    }

    /**
     * Clear all templates and global variables
     */
    public function clear(): void
    {
        $this->templates = [];
        $this->globalVariables = [];
    }
}
