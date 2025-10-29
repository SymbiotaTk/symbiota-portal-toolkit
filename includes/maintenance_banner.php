<?php
/**
 * Maintenance Banner Include for Symbiota Portal
 *
 * Add this snippet to header.php immediately after the opening <body> tag:
 *
 * <?php
 * $TK_MAINTENANCE = $SERVER_ROOT . '/tk/includes/maintenance_banner.php';
 * if (is_file($TK_MAINTENANCE)) {
 *     include_once($TK_MAINTENANCE);
 *     if (function_exists('tk_render_maintenance_banner')) {
 *         tk_render_maintenance_banner();
 *     }
 * }
 * ?>
 *
 * Configuration in tk/config.php:
 *
 * [app.maintenance]
 * enabled = true
 * message = "System maintenance in progress. [Contact support](mailto:admin@example.com)"
 * style = "warning"  ; Options: info, warning, danger, success
 */

/**
 * Render maintenance banner using toolkit Configuration singleton
 *
 * This function uses the toolkit's Configuration and Environment singletons
 * to dynamically load and display the maintenance banner.
 *
 * @return void
 */
function tk_render_maintenance_banner(): void
{
    // Determine toolkit directory from this file's location
    // This file is in tk/static/, so go up one level to get tk root
    $tkRoot = dirname(__DIR__);
    $tkAutoloader = $tkRoot . '/vendor/autoload.php';

    if (!file_exists($tkAutoloader)) {
        return; // Toolkit not available, silently skip
    }

    require_once $tkAutoloader;

    // Check if Configuration class is available
    if (!class_exists('Symbiota\Helpers\Core\Configuration')) {
        return; // Configuration not available, silently skip
    }

    // Get Configuration singleton instance - initialize if needed
    $config = \Symbiota\Helpers\Core\Configuration::getInstance();

    // If Configuration is not initialized, try to initialize it
    if (!$config) {
        $configFile = $tkRoot . '/config.php';
        if (!file_exists($configFile)) {
            return; // No config file, silently skip
        }

        try {
            $config = \Symbiota\Helpers\Core\Configuration::initialize($configFile);
        } catch (\Exception $e) {
            return; // Failed to initialize, silently skip
        }
    }

    if (!$config) {
        return; // Still not initialized, silently skip
    }

    // Get maintenance settings from configuration
    $maintenanceEnabled = $config->get('app.maintenance.enabled', false);
    $maintenanceMessage = $config->get('app.maintenance.message', '');
    $maintenanceStyle = $config->get('app.maintenance.style', 'warning');

    // Only display if enabled and message is not empty
    if (!$maintenanceEnabled || empty($maintenanceMessage)) {
        return;
    }

    // Map style to colors (since Bootstrap is not available in Symbiota)
    $bgColor = '#fff3cd';
    $borderColor = '#ffc107';
    $textColor = '#856404';

    switch ($maintenanceStyle) {
        case 'info':
            $bgColor = '#d1ecf1';
            $borderColor = '#17a2b8';
            $textColor = '#0c5460';
            break;
        case 'warning':
            $bgColor = '#fff3cd';
            $borderColor = '#ffc107';
            $textColor = '#856404';
            break;
        case 'danger':
            $bgColor = '#f8d7da';
            $borderColor = '#dc3545';
            $textColor = '#721c24';
            break;
        case 'success':
            $bgColor = '#d4edda';
            $borderColor = '#28a745';
            $textColor = '#155724';
            break;
    }

    // Convert markdown-style links to HTML
    $messageHtml = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $maintenanceMessage);

    // Output the banner with complete styling (no Bootstrap dependency)
    ?>
<style>
.maintenance-banner {
    margin: 0 !important;
    padding: 0.75rem 1.25rem !important;
    border: 0 !important;
    border-bottom: 1px solid <?= $borderColor ?> !important;
    border-radius: 0 !important;
    background-color: <?= $bgColor ?> !important;
    color: <?= $textColor ?> !important;
    text-align: center !important;
    font-weight: 500 !important;
    font-size: 1rem !important;
    line-height: 1.5 !important;
}
.maintenance-banner a {
    color: <?= $textColor ?> !important;
    text-decoration: underline !important;
    font-weight: 600 !important;
}
.maintenance-banner a:hover {
    opacity: 0.8 !important;
    text-decoration: underline !important;
}
</style>
<div class="maintenance-banner" role="alert">
    <?= $messageHtml ?>
</div>
    <?php
}
