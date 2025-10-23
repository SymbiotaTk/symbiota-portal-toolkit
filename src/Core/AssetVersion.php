<?php

namespace Symbiota\Helpers\Core;

/**
 * Asset Version Manager
 * 
 * Provides cache-busting version strings for static assets (CSS, JS, images).
 * Uses file modification time or manual version strings to force browser cache refresh.
 */
class AssetVersion
{
    /**
     * Manual version string for all assets
     * Increment this when you want to force a cache refresh across all assets
     * Format: YYYYMMDDNN (year, month, day, revision number)
     */
    private static string $globalVersion = '2024101901';

    /**
     * Get version string for an asset
     * 
     * @param string|null $assetPath Optional path to asset file (relative to templates directory)
     * @param bool $useFileTime Whether to use file modification time instead of global version
     * @return string Version string suitable for query parameter
     */
    public static function get(?string $assetPath = null, bool $useFileTime = false): string
    {
        // If no asset path provided or file time not requested, return global version
        if (!$assetPath || !$useFileTime) {
            return self::$globalVersion;
        }

        // Try to get file modification time
        $templatesPath = ApplicationPaths::templatesDirectory();
        $fullPath = $templatesPath . '/' . ltrim($assetPath, '/');

        if (file_exists($fullPath)) {
            $mtime = filemtime($fullPath);
            if ($mtime !== false) {
                return (string)$mtime;
            }
        }

        // Fallback to global version if file not found
        return self::$globalVersion;
    }

    /**
     * Get global version string
     * 
     * @return string Current global version
     */
    public static function getGlobal(): string
    {
        return self::$globalVersion;
    }

    /**
     * Set global version string
     * Useful for testing or dynamic version management
     * 
     * @param string $version New version string
     */
    public static function setGlobal(string $version): void
    {
        self::$globalVersion = $version;
    }

    /**
     * Generate version query parameter
     * 
     * @param string|null $assetPath Optional path to asset file
     * @param bool $useFileTime Whether to use file modification time
     * @return string Query parameter string (e.g., "?v=2024101901")
     */
    public static function query(?string $assetPath = null, bool $useFileTime = false): string
    {
        return '?v=' . self::get($assetPath, $useFileTime);
    }

    /**
     * Build versioned asset URL
     * 
     * @param string $assetUrl Asset URL (e.g., "css/images/zoom.css")
     * @param string $urlPrefix URL prefix (e.g., "/?/")
     * @param bool $useFileTime Whether to use file modification time
     * @return string Complete versioned URL
     */
    public static function url(string $assetUrl, string $urlPrefix = '/?/', bool $useFileTime = false): string
    {
        $url = rtrim($urlPrefix, '/') . '/' . ltrim($assetUrl, '/');
        return $url . self::query($assetUrl, $useFileTime);
    }

    /**
     * Increment global version
     * Useful for automated deployment scripts
     * 
     * @return string New version string
     */
    public static function increment(): string
    {
        // Parse current version
        if (preg_match('/^(\d{8})(\d{2})$/', self::$globalVersion, $matches)) {
            $date = $matches[1];
            $revision = (int)$matches[2];
            
            $today = date('Ymd');
            
            // If same day, increment revision; otherwise reset to 01
            if ($date === $today) {
                $revision++;
                self::$globalVersion = $today . sprintf('%02d', $revision);
            } else {
                self::$globalVersion = $today . '01';
            }
        } else {
            // Invalid format, reset to today
            self::$globalVersion = date('Ymd') . '01';
        }

        return self::$globalVersion;
    }
}

