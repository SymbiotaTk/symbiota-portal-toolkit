<?php

namespace Symbiota\Helpers\Core;

use Exception;

/**
 * EAV Indexing Utilities
 *
 * Core utility functions for EAV (Entity-Attribute-Value) indexing.
 * Provides configuration parsing, field classification, and helper methods
 * for building EAV indexes.
 *
 * @version 2.0.0
 * @author Symbiota Portal Helpers
 */
class EavIndexing
{
    /**
     * Parse INI configuration file
     *
     * @param string $iniPath Path to INI configuration file
     * @return array Parsed configuration array
     * @throws Exception If file doesn't exist or is invalid
     */
    public static function parseConfig(string $iniPath): array
    {
        if (!file_exists($iniPath)) {
            throw new Exception(sprintf('Configuration file not found: %s', $iniPath));
        }

        // Suppress warnings and handle parse errors
        $config = @parse_ini_file($iniPath, true);

        if ($config === false || empty($config)) {
            throw new Exception('Failed to parse configuration file or file is empty');
        }

        return $config;
    }

    /**
     * Get display fields from configuration
     *
     * Display fields are marked with :display flag and are stored in the
     * Entities table but not indexed in the EAV structure.
     *
     * @param array $config Parsed configuration array
     * @return array Array of display fields with 'table', 'name', 'type'
     */
    public static function getDisplayFields(array $config): array
    {
        $displayFields = [];

        // Get root table from config
        $rootTable = $config['_config']['root_table'] ?? null;
        if (!$rootTable) {
            return $displayFields;
        }

        // Only process root table
        if (!isset($config[$rootTable]['columns'])) {
            return $displayFields;
        }

        foreach ($config[$rootTable]['columns'] as $columnDef) {
            $column = TextNormalizer::parseColumnDef($columnDef);

            if ($column['display'] ?? false) {
                $displayFields[] = [
                    'table' => $rootTable,
                    'name' => $column['name'],
                    'type' => $column['type']
                ];
            }
        }

        return $displayFields;
    }

    /**
     * Get indexed fields from configuration
     *
     * Indexed fields are text/date fields that are NOT marked as display fields.
     * These fields will be tokenized and stored in the EAV structure.
     *
     * @param array $config Parsed configuration array
     * @return array Array of indexed fields with 'table', 'name', 'type', 'strategy'
     */
    public static function getIndexedFields(array $config): array
    {
        $indexedFields = [];

        foreach ($config as $tableName => $tableConfig) {
            // Skip _config section
            if ($tableName === '_config') {
                continue;
            }

            if (!isset($tableConfig['columns'])) {
                continue;
            }

            foreach ($tableConfig['columns'] as $columnDef) {
                $column = TextNormalizer::parseColumnDef($columnDef);

                // Skip display fields, foreign key fields, and root ID fields
                if (($column['display'] ?? false) || ($column['fk'] ?? false) || ($column['root'] ?? false)) {
                    continue;
                }

                // Only include text and date fields (not numeric)
                if (in_array($column['type'], ['text', 'date'])) {
                    $indexedFields[] = [
                        'table' => $tableName,
                        'name' => $column['name'],
                        'type' => $column['type'],
                        'strategy' => $column['strategy'] ?? 'whole'
                    ];
                }
            }
        }

        return $indexedFields;
    }

    /**
     * Get numeric fields from configuration
     *
     * Numeric fields are stored directly in EAV.ValueNumber column,
     * not tokenized into ValuesText.
     *
     * @param array $config Parsed configuration array
     * @return array Array of numeric fields with 'table', 'name', 'type'
     */
    public static function getNumericFields(array $config): array
    {
        $numericFields = [];

        foreach ($config as $tableName => $tableConfig) {
            // Skip _config section
            if ($tableName === '_config') {
                continue;
            }

            if (!isset($tableConfig['columns'])) {
                continue;
            }

            foreach ($tableConfig['columns'] as $columnDef) {
                $column = TextNormalizer::parseColumnDef($columnDef);

                // Skip display fields, foreign key fields, and root ID fields
                if (($column['display'] ?? false) || ($column['fk'] ?? false) || ($column['root'] ?? false)) {
                    continue;
                }

                if ($column['type'] === 'numeric') {
                    $numericFields[] = [
                        'table' => $tableName,
                        'name' => $column['name'],
                        'type' => $column['type']
                    ];
                }
            }
        }

        return $numericFields;
    }

    /**
     * Get SQL column type for a field type
     *
     * Maps field types to SQLite column types.
     *
     * @param string $type Field type (text, numeric, date)
     * @return string SQL column type
     */
    public static function getSqlType(string $type): string
    {
        return match($type) {
            'numeric' => 'REAL',
            'date' => 'TEXT',
            'text' => 'TEXT',
            default => 'TEXT'
        };
    }
}

