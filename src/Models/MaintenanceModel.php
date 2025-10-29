<?php
/**
 * Maintenance Banner Model
 * 
 * Provides a simple endpoint to display maintenance mode banner.
 * This is not a full component - just a display endpoint.
 * 
 * Endpoint: /portal/tk/?/maintenance
 * 
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\MaintenanceBanner;

class MaintenanceModel extends Model
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Maintenance banner is always public - no authentication required
        $this->requiresAuth = false;
        $this->publicActions = ['*']; // All actions are public
    }

    /**
     * Get model ID
     */
    public static function getModelId(): string
    {
        return 'maintenance';
    }

    /**
     * Get the module type classification
     */
    public static function getModuleType(): ModuleType
    {
        return ModuleType::UTILITY; // Not displayed in navigation
    }

    /**
     * Get model priority for routing (higher = more priority)
     */
    public static function getPriority(): int
    {
        return 100; // High priority for direct endpoint access
    }

    /**
     * Check if model is enabled
     */
    public static function isEnabled(): bool
    {
        return true; // Always enabled (returns empty if maintenance mode is off)
    }

    /**
     * Get help markdown for CLI generation
     */
    public static function getHelpMarkdown(): string
    {
        return <<<'MARKDOWN'
# Maintenance Banner

Displays maintenance mode banner when enabled in config.php.

## Endpoint

**URL**: /portal/tk/?/maintenance  
**Method**: GET  
**Returns**: HTML banner or empty string if disabled

## Configuration

Edit `config.php`:

```ini
[app.maintenance]
enabled = true
message = "System maintenance in progress. [Contact support](mailto:admin@example.com)"
style = "warning"  ; Options: info, warning, danger, success
```

## Portal Integration

Add to `/portal/index.php` after opening `<body>` tag:

```php
<?php include_once($SERVER_ROOT . '/tk/includes/maintenance_banner.php'); ?>
```

## Toolkit Integration

Banner automatically displays on all toolkit pages when enabled.

## Message Formatting

Supports markdown syntax:
- **Bold**: `**text**`
- *Italic*: `*text*`
- Links: `[text](url)`

## Examples

```bash
# Test endpoint
curl http://localhost/portal/tk/?/maintenance

# Enable maintenance mode
# Edit config.php and set enabled = true

# Different styles
style = "info"     # Blue banner
style = "warning"  # Yellow banner (default)
style = "danger"   # Red banner
style = "success"  # Green banner
```
MARKDOWN;
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
        // Check if maintenance mode is enabled
        if (!MaintenanceBanner::isEnabled()) {
            // Return empty HTML response if disabled
            return [
                'type' => 'html-partial',  // Use html-partial to avoid layout wrapper
                'content' => '',
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate'
                ]
            ];
        }

        // Get the banner HTML (portal version with standalone CSS)
        $bannerHtml = MaintenanceBanner::getPortalHtml();

        // Return banner HTML without layout wrapper
        return [
            'type' => 'html-partial',  // Use html-partial to avoid layout wrapper
            'content' => $bannerHtml,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate'
            ]
        ];
    }
}
