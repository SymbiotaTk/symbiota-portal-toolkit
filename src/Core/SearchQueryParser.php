<?php

/**
 * Symbiota Portal Toolkit - Search Query Parser
 *
 * Shared query parsing logic for EAV and Hybrid search models.
 * Handles field aliases, multi-field queries, and query normalization.
 *
 * @package   Symbiota
 * @author    Super Developer <superdev@one.com>
 * @author    Augment Agent (AI Assistant)
 * @copyright 2025
 * @license   NCSA
 * @version   2.0
 * @since     2025-10-21
 */

namespace Symbiota\Helpers\Core;

/**
 * SearchQueryParser - Shared query parsing logic for EAV and Hybrid search models
 *
 * Parses search queries in the format:
 * - Freetext: "amanita"
 * - Field-specific: "family:Liceaceae"
 * - Numeric with operator: "decimalLatitude:>40.5"
 * - Date with operator: "eventDate:>2000"
 * - Date range: "eventDate:1970-1980"
 * 
 * Resolves field aliases from config (e.g., "taxon" -> "sciname")
 * Returns field metadata (table, type, strategy) from config
 */
class SearchQueryParser
{
    /** @var array Configuration from index_config.ini */
    private array $config;
    
    /** @var array Field aliases (alias => actual_field_name) */
    private array $aliases;
    
    /** @var array Field metadata cache (field_name => metadata) */
    private array $fieldMetadataCache = [];
    
    /**
     * Constructor
     * 
     * @param array $config Configuration from EavIndexing::parseConfig()
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        
        // Load aliases from config
        $this->aliases = [];
        if (isset($config['aliases'])) {
            foreach ($config['aliases'] as $alias => $target) {
                $this->aliases[strtolower($alias)] = $target;
            }
        }
        
        // Build field metadata cache
        $this->buildFieldMetadataCache();
    }
    
    /**
     * Parse search query into structured format
     *
     * @param string $query Search query
     * @return array Parsed query with keys: type, field, value, operator
     */
    public function parseQuery(string $query): array
    {
        $query = trim($query);

        // Check for field:value pattern
        if (preg_match('/^([a-z]+):(.+)$/i', $query, $matches)) {
            $fieldName = strtolower($matches[1]);
            $value = trim($matches[2]);

            // Check for multi-field aliases (special handling)
            $multiFields = $this->resolveMultiFieldAlias($fieldName);
            if ($multiFields) {
                // Multi-field search (e.g., collection searches collectionName OR collectionCode OR institutionCode)
                return [
                    'type' => 'multi_field',
                    'fields' => $multiFields,
                    'value' => strtolower(trim($value)),
                    'operator' => 'LIKE'
                ];
            }

            // Resolve single-field alias
            $fieldName = $this->resolveAlias($fieldName);

            // Get field metadata
            $metadata = $this->getFieldMetadata($fieldName);

            if (!$metadata) {
                // Unknown field - treat as freetext
                return [
                    'type' => 'freetext',
                    'field' => null,
                    'value' => $query,
                    'operator' => null
                ];
            }

            // Use the original field name from metadata (preserves case)
            $fieldName = $metadata['name'];

            // Determine query type based on field type and value format
            $type = $metadata['type'];

            if ($type === 'numeric') {
                return $this->parseNumericQuery($fieldName, $value);
            } elseif ($type === 'date') {
                return $this->parseDateQuery($fieldName, $value);
            } else {
                // Text field
                return [
                    'type' => 'field',
                    'field' => $fieldName,
                    'value' => strtolower(trim($value)),
                    'operator' => 'LIKE'
                ];
            }
        }

        // No field prefix - freetext search
        return [
            'type' => 'freetext',
            'field' => null,
            'value' => $query,
            'operator' => null
        ];
    }
    
    /**
     * Parse numeric field query
     * 
     * @param string $fieldName Field name
     * @param string $value Value with optional operator
     * @return array Parsed query
     */
    private function parseNumericQuery(string $fieldName, string $value): array
    {
        // Check for operator prefix
        if (preg_match('/^([<>=]+)(.+)$/', $value, $matches)) {
            $operator = $matches[1];
            $numValue = trim($matches[2]);
        } else {
            $operator = '=';
            $numValue = $value;
        }
        
        // Convert to numeric
        if (strpos($numValue, '.') !== false) {
            $numValue = (float) $numValue;
        } else {
            $numValue = (int) $numValue;
        }
        
        return [
            'type' => 'numeric',
            'field' => $fieldName,
            'value' => $numValue,
            'operator' => $operator
        ];
    }
    
    /**
     * Parse date field query
     * 
     * @param string $fieldName Field name
     * @param string $value Value with optional operator or range
     * @return array Parsed query
     */
    private function parseDateQuery(string $fieldName, string $value): array
    {
        // Check for range (e.g., "1970-1980")
        if (preg_match('/^(\d{4})-(\d{4})$/', $value, $matches)) {
            return [
                'type' => 'date_range',
                'field' => $fieldName,
                'value' => [$matches[1], $matches[2]],
                'operator' => 'BETWEEN'
            ];
        }
        
        // Check for operator prefix
        if (preg_match('/^([<>=]+)(.+)$/', $value, $matches)) {
            $operator = $matches[1];
            $dateValue = trim($matches[2]);
        } else {
            $operator = 'LIKE';
            $dateValue = $value;
        }
        
        return [
            'type' => 'date',
            'field' => $fieldName,
            'value' => $dateValue,
            'operator' => $operator
        ];
    }
    
    /**
     * Get field metadata from config
     *
     * @param string $fieldName Field name (can be alias)
     * @return array|null Field metadata or null if not found
     */
    public function getFieldMetadata(string $fieldName): ?array
    {
        // Resolve alias first
        $fieldName = $this->resolveAlias($fieldName);

        // Check cache (case-insensitive)
        $fieldNameLower = strtolower($fieldName);
        foreach ($this->fieldMetadataCache as $cachedName => $metadata) {
            if (strtolower($cachedName) === $fieldNameLower) {
                return $metadata;
            }
        }

        return null;
    }
    
    /**
     * Resolve multi-field alias (aliases that search multiple fields with OR logic)
     *
     * @param string $fieldName Field name or alias
     * @return array|null Array of field names if multi-field alias, null otherwise
     */
    public function resolveMultiFieldAlias(string $fieldName): ?array
    {
        $fieldNameLower = strtolower($fieldName);

        // Multi-field aliases - search multiple fields with OR logic
        $multiFieldAliases = [
            // Collection: searches all collection-related fields
            'collection' => ['collectionName', 'collectionCode', 'institutionCode'],

            // Location: searches all geographic fields
            'location' => ['country', 'stateProvince', 'county', 'municipality', 'locality'],

            // Place: alternative to location
            'place' => ['country', 'stateProvince', 'county', 'municipality', 'locality'],

            // Geography: alternative to location
            'geography' => ['country', 'stateProvince', 'county', 'municipality', 'locality'],

            // Taxon: searches scientific name fields (binomial/species name)
            // This searches sciname, scientificName, and sciName (case variations)
            'taxon' => ['sciname', 'scientificName', 'sciName'],

            // Taxonomy: searches all taxonomic name fields (broader than taxon)
            'taxonomy' => ['family', 'genus', 'scientificName', 'sciname'],
        ];

        return $multiFieldAliases[$fieldNameLower] ?? null;
    }

    /**
     * Resolve field alias to actual field name
     *
     * @param string $fieldName Field name or alias
     * @return string Actual field name
     */
    public function resolveAlias(string $fieldName): string
    {
        $fieldNameLower = strtolower($fieldName);

        if (isset($this->aliases[$fieldNameLower])) {
            return $this->aliases[$fieldNameLower];
        }

        return $fieldName;
    }
    
    /**
     * Build field metadata cache from config
     * 
     * @return void
     */
    private function buildFieldMetadataCache(): void
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
                    
                    // Skip foreign key and root columns (not searchable)
                    if (($parsed['fk'] ?? false) || ($parsed['root'] ?? false)) {
                        continue;
                    }
                    
                    // Store metadata
                    $this->fieldMetadataCache[$parsed['name']] = [
                        'name' => $parsed['name'],
                        'table' => $tableName,
                        'type' => $parsed['type'],
                        'strategy' => $parsed['strategy'] ?? 'whole',
                        'display' => $parsed['display'] ?? false
                    ];
                }
            }
        }
    }
}

