<?php

namespace Symbiota\Helpers\Core;

/**
 * Template format enumeration
 * 
 * Defines the available template formats and their corresponding
 * directory structure and file extensions.
 */
enum TemplateFormat: string
{
    case CLI = 'cli';
    case HTML = 'html';
    case SQL = 'sql';
    case TEXT = 'text';
    case JSON = 'json';
    case XML = 'xml';
    case CSV = 'csv';

    /**
     * Get the file extension for this format
     */
    public function getExtension(): string
    {
        return match($this) {
            self::CLI => 'txt',
            self::HTML => 'html',
            self::SQL => 'sql',
            self::TEXT => 'txt',
            self::JSON => 'json',
            self::XML => 'xml',
            self::CSV => 'csv',
        };
    }

    /**
     * Get the subdirectory for this format
     */
    public function getDirectory(): string
    {
        return $this->value;
    }

    /**
     * Get the MIME type for this format
     */
    public function getMimeType(): string
    {
        return match($this) {
            self::CLI => 'text/plain',
            self::HTML => 'text/html',
            self::SQL => 'text/plain',
            self::TEXT => 'text/plain',
            self::JSON => 'application/json',
            self::XML => 'application/xml',
            self::CSV => 'text/csv',
        };
    }
}
