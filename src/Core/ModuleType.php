<?php

namespace Symbiota\Helpers\Core;

/**
 * Module type classification enum
 *
 * Defines the different types of modules in the system:
 * - hComponent: User-facing modules displayed as buttons/tabs in UI
 * - hService: Backend services providing endpoints (css, js, assets, etc.)
 * - hLibrary: Internal libraries not directly accessible but may be reported as loaded
 * - hUtility: System utility modules displayed in navigation (features, admin, etc.)
 */
enum ModuleType: string
{
    case COMPONENT = 'hComponent';
    case SERVICE = 'hService';
    case LIBRARY = 'hLibrary';
    case UTILITY = 'hUtility';
    
    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        return match($this) {
            self::COMPONENT => 'User-facing component displayed in UI',
            self::SERVICE => 'Backend service providing endpoints',
            self::LIBRARY => 'Internal library for system functionality',
            self::UTILITY => 'System utility module for administration'
        };
    }
    
    /**
     * Check if module type should be displayed in UI
     */
    public function isDisplayable(): bool
    {
        return $this === self::COMPONENT;
    }
    
    /**
     * Check if module type provides endpoints
     */
    public function providesEndpoints(): bool
    {
        return $this === self::SERVICE || $this === self::COMPONENT || $this === self::UTILITY;
    }
    
    /**
     * Check if module type is internal only
     */
    public function isInternal(): bool
    {
        return $this === self::LIBRARY;
    }
    
    /**
     * Get all module types
     */
    public static function all(): array
    {
        return [
            self::COMPONENT,
            self::SERVICE,
            self::LIBRARY,
            self::UTILITY
        ];
    }
    
    /**
     * Get displayable module types
     */
    public static function displayable(): array
    {
        return array_filter(self::all(), fn($type) => $type->isDisplayable());
    }
    
    /**
     * Get service module types
     */
    public static function services(): array
    {
        return array_filter(self::all(), fn($type) => $type === self::SERVICE);
    }
}
