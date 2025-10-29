<?php
/**
 * Info Model
 *
 * Provides a simple endpoint to display LICENSE.md file.
 *
 * Endpoint: /portal/tk/?/info
 *
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;

class InfoModel extends Model
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Info endpoint is always public - no authentication required
        $this->requiresAuth = false;
        $this->publicActions = ['*']; // All actions are public
    }

    /**
     * Get model ID
     */
    public static function getModelId(): string
    {
        return 'info';
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
     * Get navigation info for this model
     */
    public static function getNavigationInfo(): array
    {
        return [
            'id' => self::getModelId(),
            'display_name' => 'License Info',
            'description' => 'Display LICENSE.md file',
            'icon_class' => 'bi-info-circle',
            'module_type' => self::getModuleType(),
            'priority' => self::getPriority(),
            'enabled' => true,
            'http_accessible' => true
        ];
    }

    /**
     * Get help markdown for CLI generation
     */
    public static function getHelpMarkdown(): string
    {
        return <<<'MARKDOWN'
# Info Model

Displays the LICENSE.md file content.

## Endpoint

**URL**: /portal/tk/?/info
**Method**: GET
**Returns**: Plain text license content

## Usage

### Web Browser

```
http://localhost:8080/portal/tk/?/info
```

### CLI

```bash
php index.php info
```

## License File

The license file is located at:
```
tk/LICENSE.md
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
        // Get the LICENSE.md file path
        // This file is in src/Models/, so go up two levels to get tk root
        $tkRoot = dirname(dirname(__DIR__));
        $licensePath = $tkRoot . '/LICENSE.md';

        // Check if file exists
        if (!file_exists($licensePath)) {
            return $this->errorResponse('LICENSE.md file not found at: ' . $licensePath, 404);
        }

        // Read the license file
        $licenseContent = file_get_contents($licensePath);

        if ($licenseContent === false) {
            return $this->errorResponse('Failed to read LICENSE.md file', 500);
        }

        // Get file info for headers
        $fileSize = filesize($licensePath);
        $lastModified = filemtime($licensePath);

        // Return file response (similar to StaticContentModel)
        return [
            'type' => 'file',
            'content' => $licenseContent,
            'mime_type' => 'text/plain; charset=utf-8',
            'file_size' => $fileSize,
            'last_modified' => $lastModified,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Length' => $fileSize,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
                'ETag' => '"' . md5($licenseContent) . '"'
            ]
        ];
    }
}
