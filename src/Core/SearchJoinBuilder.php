<?php

/**
 * Symbiota Portal Toolkit - Search JOIN Builder
 *
 * Shared JOIN building logic for EAV and Hybrid search models.
 * Dynamically builds MySQL JOIN clauses based on queried fields.
 *
 * @package   Symbiota
 * @author    Philip J Anders <anders2@illinois.edu>
 * @author    Augment Agent (AI Assistant)
 * @copyright 2025
 * @license   NCSA
 * @version   2.0
 * @since     2025-10-21
 */

namespace Symbiota\Helpers\Core;

/**
 * SearchJoinBuilder - Shared JOIN building logic for EAV and Hybrid search models
 *
 * Builds MySQL JOIN clauses based on which fields are queried.
 * Maps fields to tables and tables to aliases.
 * Determines required JOINs based on table relationships.
 *
 * Table hierarchy (for images module):
 * - media (root, alias: m)
 *   └─ omoccurrences (alias: o) via m.occid = o.occid
 *      ├─ omcollections (alias: c) via o.collid = c.collID
 *      └─ taxa (alias: t) via o.tidInterpreted = t.tid
 */
class SearchJoinBuilder
{
    /** @var array Configuration from index_config.ini */
    private array $config;

    /** @var array Field-to-table mapping (field_name => table_name) */
    private array $fieldToTable = [];

    /** @var array Table-to-alias mapping */
    private const TABLE_ALIASES = [
        'media' => 'm',
        'omoccurrences' => 'o',
        'omcollections' => 'c',
        'taxa' => 't'
    ];

    /** @var array Table JOIN definitions */
    private const TABLE_JOINS = [
        'omoccurrences' => [
            'parent' => 'media',
            'join' => 'LEFT JOIN omoccurrences o ON m.occid = o.occid'
        ],
        'omcollections' => [
            'parent' => 'omoccurrences',
            'join' => 'LEFT JOIN omcollections c ON o.collid = c.collID',
            'requires' => ['omoccurrences'] // Must join omoccurrences first
        ],
        'taxa' => [
            'parent' => 'omoccurrences',
            'join' => 'LEFT JOIN taxa t ON o.tidInterpreted = t.tid',
            'requires' => ['omoccurrences'] // Must join omoccurrences first
        ]
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration from EavIndexing::parseConfig()
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Build field-to-table mapping
        $this->buildFieldToTableMapping();
    }

    /**
     * Build JOIN clauses for given fields
     *
     * @param array $fields List of field names
     * @return string JOIN clauses (empty string if no JOINs needed)
     */
    public function buildJoinsForFields(array $fields): string
    {
        // Determine which tables are needed
        $tables = [];
        foreach ($fields as $field) {
            $table = $this->getTableForField($field);
            if ($table && $table !== 'media') {
                $tables[$table] = true;
            }
        }

        if (empty($tables)) {
            return '';
        }

        // Add required parent tables
        $allTables = $this->addRequiredTables(array_keys($tables));

        // Build JOINs in correct order
        $joins = [];
        $joinOrder = ['omoccurrences', 'omcollections', 'taxa'];

        foreach ($joinOrder as $table) {
            if (isset($allTables[$table])) {
                $joins[] = self::TABLE_JOINS[$table]['join'];
            }
        }

        return implode("\n", $joins);
    }

    /**
     * Get table name for a field
     *
     * @param string $fieldName Field name
     * @return string|null Table name or null if not found
     */
    public function getTableForField(string $fieldName): ?string
    {
        // Try exact match first
        if (isset($this->fieldToTable[$fieldName])) {
            return $this->fieldToTable[$fieldName];
        }

        // Fall back to case-insensitive match
        $fieldNameLower = strtolower($fieldName);
        foreach ($this->fieldToTable as $field => $table) {
            if (strtolower($field) === $fieldNameLower) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Get table alias
     *
     * @param string $tableName Table name
     * @return string|null Table alias or null if not found
     */
    public function getTableAlias(string $tableName): ?string
    {
        return self::TABLE_ALIASES[$tableName] ?? null;
    }

    /**
     * Get qualified column name (table_alias.column_name)
     *
     * @param string $fieldName Field name
     * @return string|null Qualified column name or null if not found
     */
    public function getQualifiedColumn(string $fieldName): ?string
    {
        $table = $this->getTableForField($fieldName);
        if (!$table) {
            return null;
        }

        $alias = $this->getTableAlias($table);
        if (!$alias) {
            return null;
        }

        return $alias . '.' . $fieldName;
    }

    /**
     * Build field-to-table mapping from config
     *
     * @return void
     */
    private function buildFieldToTableMapping(): void
    {
        foreach ($this->config as $tableName => $tableConfig) {
            // Skip special sections
            if ($tableName === '_config' || $tableName === 'aliases') {
                continue;
            }

            // Process columns
            if (isset($tableConfig['columns'])) {
                foreach ($tableConfig['columns'] as $columnDef) {
                    $parsed = TextNormalizer::parseColumnDef($columnDef);

                    // Map field to table
                    $this->fieldToTable[$parsed['name']] = $tableName;
                }
            }
        }
    }

    /**
     * Add required parent tables for given tables
     *
     * @param array $tables List of table names
     * @return array All tables including required parents
     */
    private function addRequiredTables(array $tables): array
    {
        $allTables = [];

        foreach ($tables as $table) {
            $allTables[$table] = true;

            // Add required parent tables
            if (isset(self::TABLE_JOINS[$table]['requires'])) {
                foreach (self::TABLE_JOINS[$table]['requires'] as $requiredTable) {
                    $allTables[$requiredTable] = true;
                }
            }
        }

        return $allTables;
    }
}
