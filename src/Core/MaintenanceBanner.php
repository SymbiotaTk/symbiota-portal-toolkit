<?php
/**
 * Maintenance Banner Component
 *
 * Renders a maintenance mode banner with markdown support for display
 * on both Symbiota portal and toolkit pages.
 *
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */

namespace Symbiota\Helpers\Core;

class MaintenanceBanner
{
    /**
     * Check if maintenance mode is enabled
     */
    public static function isEnabled(): bool
    {
        $config = Configuration::getInstance();
        if ($config === null) {
            return false;
        }
        return (bool) $config->get('app.maintenance.enabled', false);
    }

    /**
     * Get maintenance banner HTML
     *
     * @return string HTML for maintenance banner, or empty string if disabled
     */
    public static function getHtml(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        $config = Configuration::getInstance();
        $message = $config->get('app.maintenance.message', 'System maintenance in progress.');
        $style = $config->get('app.maintenance.style', 'warning');

        // Convert markdown to HTML
        $htmlMessage = self::markdownToHtml($message);

        // Map style to Bootstrap alert classes
        $alertClass = self::getAlertClass($style);

        return self::renderBanner($htmlMessage, $alertClass);
    }

    /**
     * Get maintenance banner HTML for Symbiota portal
     * (Simplified version without Bootstrap dependencies)
     *
     * @return string HTML for maintenance banner, or empty string if disabled
     */
    public static function getPortalHtml(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        $config = Configuration::getInstance();
        $message = $config->get('app.maintenance.message', 'System maintenance in progress.');
        $style = $config->get('app.maintenance.style', 'warning');

        // Convert markdown to HTML
        $htmlMessage = self::markdownToHtml($message);

        // Map style to colors
        $colors = self::getStyleColors($style);

        return self::renderPortalBanner($htmlMessage, $colors);
    }

    /**
     * Convert simple markdown to HTML
     * Supports: [text](url), **bold**, *italic*
     */
    private static function markdownToHtml(string $markdown): string
    {
        // Convert links: [text](url) -> <a href="url">text</a>
        $html = preg_replace(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            '<a href="$2">$1</a>',
            $markdown
        );

        // Convert bold: **text** -> <strong>text</strong>
        $html = preg_replace(
            '/\*\*([^\*]+)\*\*/',
            '<strong>$1</strong>',
            $html
        );

        // Convert italic: *text* -> <em>text</em>
        $html = preg_replace(
            '/\*([^\*]+)\*/',
            '<em>$1</em>',
            $html
        );

        return $html;
    }

    /**
     * Map style name to Bootstrap alert class
     */
    private static function getAlertClass(string $style): string
    {
        $validStyles = ['info', 'warning', 'danger', 'success'];
        $style = in_array($style, $validStyles) ? $style : 'warning';
        return 'alert-' . $style;
    }

    /**
     * Map style name to color scheme for portal banner
     */
    private static function getStyleColors(string $style): array
    {
        $colorSchemes = [
            'info' => [
                'bg' => '#d1ecf1',
                'border' => '#bee5eb',
                'text' => '#0c5460'
            ],
            'warning' => [
                'bg' => '#fff3cd',
                'border' => '#ffeaa7',
                'text' => '#856404'
            ],
            'danger' => [
                'bg' => '#f8d7da',
                'border' => '#f5c6cb',
                'text' => '#721c24'
            ],
            'success' => [
                'bg' => '#d4edda',
                'border' => '#c3e6cb',
                'text' => '#155724'
            ]
        ];

        return $colorSchemes[$style] ?? $colorSchemes['warning'];
    }

    /**
     * Render banner HTML for toolkit (Bootstrap-based)
     */
    private static function renderBanner(string $message, string $alertClass): string
    {
        return <<<HTML
<div class="alert {$alertClass} mb-0 rounded-0 text-center" role="alert" style="border-left: none; border-right: none; border-top: none;">
    <i class="fas fa-exclamation-triangle me-2"></i>
    {$message}
</div>
HTML;
    }

    /**
     * Render banner HTML for Symbiota portal (standalone CSS)
     */
    private static function renderPortalBanner(string $message, array $colors): string
    {
        $bg = htmlspecialchars($colors['bg']);
        $border = htmlspecialchars($colors['border']);
        $text = htmlspecialchars($colors['text']);

        return <<<HTML
<div style="background-color: {$bg}; border-bottom: 3px solid {$border}; color: {$text}; padding: 12px 20px; text-align: center; font-size: 14px; line-height: 1.5; margin: 0;">
    <strong>⚠</strong> {$message}
</div>
HTML;
    }

    /**
     * Get maintenance banner as PHP include snippet for Symbiota portal
     *
     * @return string PHP code snippet to include in portal header
     */
    public static function getPortalIncludeSnippet(): string
    {
        return <<<'PHP'
<?php
// Maintenance Banner - Auto-generated by Symbiota Portal Toolkit
if (file_exists(__DIR__ . '/tk/vendor/autoload.php')) {
    require_once __DIR__ . '/tk/vendor/autoload.php';
    if (class_exists('\Symbiota\Helpers\Core\MaintenanceBanner')) {
        echo \Symbiota\Helpers\Core\MaintenanceBanner::getPortalHtml();
    }
}
?>
PHP;
    }
}
