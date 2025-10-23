<?php

namespace Symbiota\Helpers\Core;

/**
 * Text normalization utilities for EAV indexing
 * 
 * Consolidates whitespace handling, tokenization, and date normalization
 * to avoid code duplication across the application.
 */
class TextNormalizer
{
    /**
     * Normalize whitespace: trim, collapse multiple spaces, remove tabs/newlines
     * 
     * This handles problematic whitespace that can cause TSV parsing errors:
     * - Tabs, newlines, carriage returns → converted to spaces
     * - Multiple consecutive spaces → collapsed to single space
     * - Leading/trailing whitespace → removed
     * 
     * @param string $text Input text with potentially problematic whitespace
     * @return string Normalized text with clean whitespace
     */
    public static function normalizeWhitespace(string $text): string
    {
        // Replace tabs, newlines, carriage returns with spaces
        $text = str_replace(["\t", "\n", "\r"], ' ', $text);
        
        // Collapse multiple spaces into one
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim leading/trailing whitespace
        return trim($text);
    }
    
    /**
     * Tokenize text by whitespace after normalization (split strategy)
     *
     * Splits text into individual words/tokens for indexing.
     * Handles whitespace normalization automatically.
     *
     * @param string $text Input text to tokenize
     * @return array Array of tokens (empty array if text is empty)
     */
    public static function tokenize(string $text): array
    {
        $normalized = self::normalizeWhitespace($text);
        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $normalized);

        // Filter out empty tokens (shouldn't happen after normalization, but be safe)
        return array_filter($tokens, function($token) {
            return $token !== '';
        });
    }

    /**
     * Tokenize text by keeping entire value as single token (whole strategy)
     *
     * Useful for scientific names, catalog numbers, or other values that should
     * be kept intact for exact matching.
     *
     * @param string $text Text to tokenize
     * @return array Array with single token (or empty array if text is empty)
     */
    public static function tokenizeWhole(string $text): array
    {
        $normalized = self::normalizeWhitespace($text);

        if ($normalized === '') {
            return [];
        }

        return [$normalized];
    }

    /**
     * Tokenize text using specified strategy
     *
     * @param string $text Text to tokenize
     * @param string|null $strategy Tokenization strategy ('split', 'whole', or null for default)
     * @return array Array of tokens
     * @throws \InvalidArgumentException If strategy is invalid
     */
    public static function tokenizeByStrategy(string $text, ?string $strategy): array
    {
        // Default to whole strategy (keep values intact unless explicitly marked :split)
        if ($strategy === null || $strategy === '') {
            $strategy = 'whole';
        }

        return match($strategy) {
            'split' => self::tokenize($text),
            'whole' => self::tokenizeWhole($text),
            default => throw new \InvalidArgumentException("Invalid tokenization strategy: $strategy")
        };
    }
    
    /**
     * Normalize date to YYYY-MM-DD format
     * 
     * Accepts various date formats and converts to standard ISO format.
     * Returns null if date cannot be parsed.
     * 
     * @param string|null $date Input date in any format recognized by strtotime()
     * @return string|null Normalized date in YYYY-MM-DD format, or null if invalid
     */
    public static function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        
        // Normalize whitespace first
        $date = self::normalizeWhitespace($date);
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        
        return date('Y-m-d', $timestamp);
    }
    
    /**
     * Check if a value is numeric
     * 
     * More robust than is_numeric() - handles edge cases like:
     * - Empty strings
     * - Whitespace-only strings
     * - Scientific notation
     * 
     * @param mixed $value Value to check
     * @return bool True if value is numeric
     */
    public static function isNumeric($value): bool
    {
        if ($value === null) {
            return false;
        }
        
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return false;
            }
        }
        
        return is_numeric($value);
    }
    
    /**
     * Convert value to float, handling edge cases
     * 
     * @param mixed $value Value to convert
     * @return float|null Float value, or null if not numeric
     */
    public static function toFloat($value): ?float
    {
        if (!self::isNumeric($value)) {
            return null;
        }
        
        return (float) $value;
    }
    
    /**
     * Check if a column should be excluded from indexing
     * 
     * @param string $columnDef Column definition from config (e.g., "url:text:exclude")
     * @return bool True if column should be excluded
     */
    public static function isExcluded(string $columnDef): bool
    {
        $parts = explode(':', $columnDef);
        return isset($parts[2]) && $parts[2] === 'exclude';
    }
    
    /**
     * Parse column definition from config
     *
     * Format: "column_name:type[:flag][:strategy]"
     * Examples:
     *   - "sciname:text:whole" - text column with whole tokenization (indexed)
     *   - "locality:text:split" - text column with split tokenization (indexed)
     *   - "url:text:display" - display-only column (stored in Entities, not indexed)
     *   - "mediaID:numeric:root" - root entity ID (already selected, not indexed separately)
     *   - "occid:numeric:fk" - foreign key column (used for JOINs only, not indexed)
     *   - "url:text:exclude" - excluded column (deprecated, use :display)
     *   - "decimalLatitude:numeric" - numeric column (no strategy)
     *
     * @param string $columnDef Column definition string
     * @return array ['name' => string, 'type' => string, 'display' => bool, 'fk' => bool, 'root' => bool, 'exclude' => bool, 'strategy' => string|null]
     */
    public static function parseColumnDef(string $columnDef): array
    {
        $parts = explode(':', $columnDef);

        $name = $parts[0] ?? '';
        $type = $parts[1] ?? 'text';
        $display = false;
        $fk = false;  // Foreign key flag
        $root = false;  // Root entity ID flag
        $exclude = false;  // Deprecated
        $strategy = null;

        // Parse optional flags (display, fk, root, exclude, strategy)
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i] === 'display') {
                $display = true;
            } elseif ($parts[$i] === 'fk') {
                $fk = true;
            } elseif ($parts[$i] === 'root') {
                $root = true;
            } elseif ($parts[$i] === 'exclude') {
                $exclude = true;  // Deprecated, treat as display
                $display = true;
            } elseif (in_array($parts[$i], ['split', 'whole'])) {
                $strategy = $parts[$i];
            }
        }

        // Default strategy for indexed text/date columns (if not specified)
        // Changed to 'whole' - only split if explicitly marked :split
        if (!$display && !$fk && !$root && $strategy === null && in_array($type, ['text', 'date'])) {
            $strategy = 'whole';
        }

        return [
            'name' => $name,
            'type' => $type,
            'display' => $display,
            'fk' => $fk,
            'root' => $root,
            'exclude' => $exclude,  // Deprecated, kept for backward compatibility
            'strategy' => $strategy
        ];
    }
}

