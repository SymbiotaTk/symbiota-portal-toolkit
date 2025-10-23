<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Interfaces\ImagesSearchInterface;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateFormat;
use PDO;
use Exception;

/**
 * Images Model Hybrid - Hybrid Search Plugin
 *
 * Hybrid search model combining lightweight autocomplete cache with direct MySQL queries.
 * Implements ImagesSearchInterface as a plugin for ImagesModel delegation.
 * Optimized for large datasets (> 500K records).
 *
 * Architecture:
 * - autocompleteFields() - Returns indexed field suggestions from config
 * - autocompleteValues() - Queries lightweight SQLite autocomplete cache
 * - search() - Delegates to ImagesModel router for MySQL direct queries
 * - isAvailable() - Checks if autocomplete cache exists
 * - getSearchMode() - Returns 'hybrid'
 *
 * The hybrid model uses a lightweight SQLite cache ONLY for autocomplete.
 * Actual search queries are executed directly against MySQL by the ImagesModel router.
 *
 * @version 2.0.0
 * @author Symbiota Portal Helpers
 */
class ImagesModelHybrid implements ImagesSearchInterface
{
    private array $config;
    private string $configPath;
    private string $cacheDbPath;
    private string $sourceDbPath;
    private ?PDO $cacheDb = null;

    /**
     * Constructor
     *
     * @param array $params Configuration parameters
     * @throws Exception If config file not found
     */
    public function __construct(array $params)
    {
        $this->configPath = $params['config_path'] ?? '';
        $this->cacheDbPath = $params['cache_db_path'] ?? '';
        $this->sourceDbPath = $params['source_db_path'] ?? '';

        if (empty($this->configPath) || !file_exists($this->configPath)) {
            throw new Exception("Config file not found: {$this->configPath}");
        }

        // Load configuration
        $this->config = parse_ini_file($this->configPath, true);
    }

    /**
     * Set source database path (for testing with fixtures)
     *
     * @param string $path Path to source database
     * @return self
     */
    public function setSourceDbPath(string $path): self
    {
        $this->sourceDbPath = $path;
        return $this;
    }

    /**
     * Search for images using hybrid model
     *
     * In production, this would query MySQL directly for high-cardinality fields.
     * For testing, we use the same SQLite EAV cache to demonstrate interchangeability.
     *
     * @param array $params Search parameters
     * @return array Response array with type and content
     */
    public function search(array $params): array
    {
        try {
            // Get cache database path from config
            $dbPath = $params['db-path'] ?? $this->cacheDbPath;

            // Open database in read-only mode
            $db = new PDO(sprintf('sqlite:%s', $dbPath), null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);

            // Attach source.db if available (for querying display fields)
            if (!empty($this->sourceDbPath) && file_exists($this->sourceDbPath)) {
                $db->exec("ATTACH DATABASE '{$this->sourceDbPath}' AS source");
            }

            // Parse query parameters
            $query = $params['query'] ?? $params['search'] ?? '';
            $queryAnd = $params['queryAnd'] ?? [];
            $queryOr = $params['queryOr'] ?? [];
            $limit = (int)($params['limit'] ?? 20);
            $offset = (int)($params['offset'] ?? 0);

            // Normalize queryAnd and queryOr to arrays (CLI may pass as strings)
            if (!is_array($queryAnd)) {
                $queryAnd = [$queryAnd];
            }
            if (!is_array($queryOr)) {
                $queryOr = [$queryOr];
            }

            // Use centralized format detection from Environment singleton
            $format = $params['format'] ?? \Symbiota\Helpers\Core\Environment::getInstance()->getResponseFormat();
            $isCli = ($format === 'cli');

            // Validate that we have at least one query
            if (empty($query) && empty($queryAnd) && empty($queryOr)) {
                if ($isCli) {
                    return [
                        'type' => 'error',
                        'message' => 'Search query is required',
                        'status_code' => 400
                    ];
                } else {
                    return [
                        'type' => 'htmx',
                        'content' => '<div class="alert alert-warning">No search query provided.</div>'
                    ];
                }
            }

            // For testing purposes, use the same EAV search logic
            // In production, this would query MySQL directly
            $entities = $this->searchEavIndex($db, $query, $queryAnd, $queryOr, $limit, $offset);

            // Convert entities to images
            $images = $this->convertEavEntitiesToImages($entities);

            // Build load more URL
            $nextOffset = $offset + $limit;
            $queryParams = [
                'query' => $query,
                'limit' => $limit,
                'offset' => $nextOffset
            ];

            if (!empty($queryAnd)) {
                foreach ($queryAnd as $andQuery) {
                    $queryParams['queryAnd'][] = $andQuery;
                }
            }
            if (!empty($queryOr)) {
                foreach ($queryOr as $orQuery) {
                    $queryParams['queryOr'][] = $orQuery;
                }
            }

            $queryString = http_build_query($queryParams);
            $loadMoreUrl = $this->getAppUrlPrefix() . 'images/search?' . $queryString;

            // Check format and return appropriate response
            if ($isCli) {
                // CLI format: return simple table
                return $this->formatCliSearchResults($images, $query);
            } else {
                // HTMX format: render with load more button
                return $this->renderImagesWithLoadMore($images, $limit, $offset, $loadMoreUrl, $query);
            }

        } catch (Exception $e) {
            $errorMsg = sprintf('Hybrid search failed: %s', $e->getMessage());

            return [
                'type' => 'htmx',
                'content' => '<div class="alert alert-danger">' . htmlspecialchars($errorMsg) . '</div>'
            ];
        }
    }

    /**
     * Get autocomplete field suggestions
     *
     * @param array $params Parameters (q => query string)
     * @return array Response array with type and content
     */
    public function autocompleteFields(array $params): array
    {
        $query = strtolower($params['q'] ?? $params['query'] ?? '');

        // Check if user has typed "field:value" pattern
        // If so, switch to value autocomplete for special fields
        if (preg_match('/^(collection):(.*)$/i', $query, $matches)) {
            $field = strtolower($matches[1]);
            $searchValue = $matches[2];

            // Special handling for collection field - search across multiple columns
            if ($field === 'collection' && strlen($searchValue) >= 2) {
                return $this->autocompleteCollectionValues($searchValue);
            } else if ($field === 'collection') {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="autocomplete-message">Type at least 2 characters after collection:...</div>'
                ];
            }
        }

        // Check for other field:value patterns and delegate to value autocomplete
        if (preg_match('/^([a-z]+):(.+)$/i', $query, $matches)) {
            $field = strtolower($matches[1]);
            $searchValue = $matches[2];

            if (strlen($searchValue) >= 2) {
                // Delegate to autocompleteValues
                return $this->autocompleteValues([
                    'field' => $field,
                    'q' => $searchValue,
                    'db-path' => $params['db-path'] ?? null
                ]);
            } else {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="autocomplete-message">Type at least 2 characters...</div>'
                ];
            }
        }

        // Load field aliases from config
        if (!file_exists($this->configPath)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Field configuration not found</div>'
            ];
        }

        $config = parse_ini_file($this->configPath, true);
        $aliases = $config['aliases'] ?? [];

        // Build suggestions array
        $suggestions = [];
        $addedFields = []; // Track which fields we've already added to avoid duplicates

        // First, add all aliases
        foreach ($aliases as $alias => $actualField) {
            // Match against alias or actual field name
            if (empty($query) ||
                strpos(strtolower($alias), $query) !== false ||
                strpos(strtolower($actualField), $query) !== false) {

                $description = $this->getFieldDescription($alias, $actualField);

                $suggestions[] = [
                    'field' => $alias,
                    'maps_to' => $actualField,
                    'description' => $description,
                    'is_alias' => true
                ];

                $addedFields[strtolower($alias)] = true;
            }
        }

        // Second, add all actual attribute names from the EAV cache
        $dbPath = $params['db-path'] ?? $this->cacheDbPath;
        if (file_exists($dbPath)) {
            try {
                $db = new PDO('sqlite:' . $dbPath, null, null, [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
                ]);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Get all searchable attributes
                // Hybrid schema now uses standardized EAV-compatible schema:
                // ColumnName (not AttributeName), DataType (not FieldType), TokenStrategy
                $sql = "
                    SELECT DISTINCT
                        ColumnName as FieldName,
                        DataType,
                        TokenStrategy
                    FROM Attributes
                    ORDER BY FieldName ASC
                ";

                $stmt = $db->query($sql);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fieldName = $row['FieldName'];
                    $tokenStrategy = $row['TokenStrategy'] ?? '';
                    $fieldLower = strtolower($fieldName);

                    // Skip display-only fields
                    if ($tokenStrategy === 'display') {
                        continue;
                    }

                    // Skip if already added as alias
                    if (isset($addedFields[$fieldLower])) {
                        continue;
                    }

                    // Match against field name
                    if (empty($query) || strpos($fieldLower, $query) !== false) {
                        $suggestions[] = [
                            'field' => $fieldName,
                            'maps_to' => $fieldName,
                            'description' => "Search by $fieldName",
                            'is_alias' => false
                        ];

                        $addedFields[$fieldLower] = true;
                    }
                }
            } catch (Exception $e) {
                // Silently fail - just return aliases
            }
        }

        if (empty($suggestions)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">No matching fields found</div>'
            ];
        }

        // Generate HTML with onclick handlers (matching EAV implementation)
        $html = '';
        foreach ($suggestions as $suggestion) {
            $field = htmlspecialchars($suggestion['field']);
            $mapsToHtml = $suggestion['is_alias']
                ? sprintf('<div class="autocomplete-alias">(alias for %s)</div>', htmlspecialchars($suggestion['maps_to']))
                : '';

            $html .= sprintf(
                '<div class="autocomplete-item"
                     style="cursor: pointer;"
                     data-field="%s"
                     onclick="
                         const input = document.getElementById(\'eav-search-input\');
                         input.value = \'%s:\';
                         input.focus();
                         this.parentElement.style.display = \'none\';
                         htmx.trigger(input, \'keyup\');
                     ">
                    <div class="autocomplete-field">%s</div>
                    %s
                    <div class="autocomplete-description">%s</div>
                </div>',
                $field,
                $field,
                $field,
                $mapsToHtml,
                htmlspecialchars($suggestion['description'])
            );
        }

        return [
            'type' => 'htmx',
            'content' => $html
        ];
    }

    /**
     * Get autocomplete value suggestions
     *
     * @param array $params Parameters (field => field name, q => query string)
     * @return array Response array with type and content
     */
    public function autocompleteValues(array $params): array
    {
        $field = $params['field'] ?? '';
        $query = $params['q'] ?? $params['query'] ?? '';
        $limit = (int)($params['limit'] ?? 10);

        if (empty($field)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Field parameter required</div>'
            ];
        }

        if (strlen($query) < 2) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Type at least 2 characters...</div>'
            ];
        }

        // Get the actual field name from alias
        $config = parse_ini_file($this->configPath, true);
        $aliases = $config['aliases'] ?? [];
        $actualField = $aliases[$field] ?? $field;

        // Get cache database path
        $dbPath = $params['db-path'] ?? $this->cacheDbPath;

        if (!file_exists($dbPath)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Search index not available</div>'
            ];
        }

        try {
            $db = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Try specialized autocomplete cache first (if available)
            // Note: For testing with EAV cache, this will return empty and fall back to EAV query
            $values = [];
            $table = $this->getAutocompleteTable($field);
            if (!empty($table)) {
                // Check if the specialized table exists
                $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
                if ($tableCheck && $tableCheck->fetch()) {
                    $values = $this->queryAutocompleteCache($field, $query);
                }
            }

            // Fallback to EAV index if specialized cache doesn't exist or has no results
            if (empty($values)) {
                // Query EAV index directly (case-insensitive field name matching)
                $sql = "
                    SELECT
                        vt.ValueText as value,
                        COUNT(DISTINCT eav.Eid) as count
                    FROM EAV eav
                    JOIN Attributes attr ON eav.Aid = attr.Aid
                    JOIN ValuesText vt ON eav.Vid = vt.Vid
                    WHERE LOWER(attr.ColumnName) = LOWER(:field)
                    AND LOWER(vt.ValueText) LIKE LOWER(:query)
                    GROUP BY vt.ValueText
                    ORDER BY count DESC, vt.ValueText ASC
                    LIMIT :limit
                ";

                $stmt = $db->prepare($sql);
                $stmt->bindValue(':field', $actualField, PDO::PARAM_STR);
                $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                $values = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $values[] = [
                        'value' => $row['value'],
                        'count' => (int)$row['count']
                    ];
                }
            }

            if (empty($values)) {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="autocomplete-message">No suggestions found</div>'
                ];
            }

            // Generate HTMX HTML with onclick handlers (matching EAV implementation)
            $html = '';
            foreach ($values as $value) {
                $fieldEscaped = htmlspecialchars($field);
                $valueEscaped = htmlspecialchars($value['value']);
                $html .= sprintf(
                    '<div class="autocomplete-item"
                         style="cursor: pointer;"
                         data-field="%s"
                         data-value="%s"
                         onclick="addFilter(\'%s\', \'%s\'); document.getElementById(\'eav-search-input\').value = \'\'; this.parentElement.style.display = \'none\';">
                        <div class="autocomplete-value">%s</div>
                        <div class="autocomplete-count">%d %s</div>
                    </div>',
                    $fieldEscaped,
                    $valueEscaped,
                    $fieldEscaped,
                    $valueEscaped,
                    $valueEscaped,
                    $value['count'],
                    $value['count'] === 1 ? 'image' : 'images'
                );
            }

            return [
                'type' => 'htmx',
                'content' => $html
            ];
        } catch (Exception $e) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Error: ' . htmlspecialchars($e->getMessage()) . '</div>'
            ];
        }
    }

    /**
     * Get cache information
     *
     * @param array $params Parameters
     * @return array Response array
     */
    public function getInfo(array $params = []): array
    {
        if (!$this->isAvailable()) {
            return [
                'type' => 'error',
                'content' => 'Hybrid autocomplete cache not found'
            ];
        }

        return [
            'type' => 'success',
            'content' => 'Hybrid autocomplete cache available'
        ];
    }

    /**
     * Check if hybrid cache is available
     *
     * @return bool True if cache exists
     */
    public function isAvailable(): bool
    {
        return !empty($this->cacheDbPath) && file_exists($this->cacheDbPath);
    }

    /**
     * Get search mode identifier
     *
     * @return string 'hybrid'
     */
    public function getSearchMode(): string
    {
        return 'hybrid';
    }

    /**
     * Get indexed fields from config
     *
     * @return array Field name => description
     */
    private function getIndexedFields(): array
    {
        $fields = [];

        // Parse config for indexed fields
        foreach ($this->config as $section => $data) {
            if ($section === '_config' || $section === 'aliases') {
                continue;
            }

            if (isset($data['columns'])) {
                foreach ($data['columns'] as $column) {
                    // Parse column definition: "field:type:strategy"
                    $parts = explode(':', $column);
                    $fieldName = $parts[0];
                    $fieldType = $parts[1] ?? 'text';

                    // Skip display-only and numeric fields
                    if (isset($parts[2]) && $parts[2] === 'display') {
                        continue;
                    }
                    if ($fieldType === 'numeric') {
                        continue;
                    }

                    $fields[$fieldName] = ucfirst($fieldName);
                }
            }
        }

        // Add aliases
        if (isset($this->config['aliases'])) {
            foreach ($this->config['aliases'] as $alias => $target) {
                if (isset($fields[$target])) {
                    $fields[$alias] = $fields[$target];
                }
            }
        }

        return $fields;
    }

    /**
     * Query autocomplete cache for values
     *
     * @param string $field Field name
     * @param string $query Query string
     * @return array Array of values with counts
     */
    private function queryAutocompleteCache(string $field, string $query): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $db = $this->getCacheConnection();

        // Determine which autocomplete table to query
        $table = $this->getAutocompleteTable($field);
        if (empty($table)) {
            return [];
        }

        // Handle special aliases that map to multiple source fields
        // 'taxon' should search across all taxonomy fields (family, genus, sciname)
        $sourceFieldCondition = $this->getSourceFieldCondition($field);

        // Query cache with case-insensitive LIKE (prefix match for index efficiency)
        // Filter by source_field to ensure we only get values from the requested field
        // (e.g., family values when searching family, not sciname values)
        // Note: Using prefix match (query%) instead of substring (%query%) allows SQLite
        // to use the index efficiently, improving autocomplete performance
        $sql = "
            SELECT
                {$this->getValueColumn($table)} as value,
                image_count as count
            FROM {$table}
            WHERE LOWER({$this->getValueColumn($table)}) LIKE LOWER(:query)
            AND {$sourceFieldCondition}
            ORDER BY image_count DESC, value ASC
            LIMIT 10
        ";

        $stmt = $db->prepare($sql);
        $params = [':query' => $query . '%'];

        // Add field parameter if using simple equality check
        if (strpos($sourceFieldCondition, ':field') !== false) {
            $params[':field'] = $field;
        }

        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get source_field WHERE condition for autocomplete queries
     * Handles special aliases that map to multiple source fields
     *
     * @param string $field Field name or alias
     * @return string SQL WHERE condition
     */
    private function getSourceFieldCondition(string $field): string
    {
        // Special aliases that search across multiple source fields
        $multiFieldAliases = [
            'taxon' => "LOWER(source_field) IN ('family', 'genus', 'sciname', 'sciname')",
            'collection' => "LOWER(source_field) IN ('collectionname', 'collectioncode', 'institutioncode')",
            'location' => "LOWER(source_field) IN ('country', 'stateprovince', 'county', 'municipality')"
        ];

        // Check if this is a multi-field alias
        $lowerField = strtolower($field);
        if (isset($multiFieldAliases[$lowerField])) {
            return $multiFieldAliases[$lowerField];
        }

        // Default: exact match on source_field
        return "LOWER(source_field) = LOWER(:field)";
    }

    /**
     * Get autocomplete table name for field
     *
     * @param string $field Field name
     * @return string Table name or empty string
     */
    private function getAutocompleteTable(string $field): string
    {
        // Map fields to autocomplete tables
        // autocomplete_taxon aggregates: sciname, scientificName, family, genus
        // autocomplete_collection aggregates: collectionName, collectionCode, institutionCode
        // autocomplete_location aggregates: county, country, stateProvince, municipality
        // autocomplete_date aggregates: eventDate (years)
        $tableMap = [
            'sciname' => 'autocomplete_taxon',
            'sciName' => 'autocomplete_taxon',
            'scientificName' => 'autocomplete_taxon',
            'taxon' => 'autocomplete_taxon',  // Alias for sciname
            'family' => 'autocomplete_taxon',
            'genus' => 'autocomplete_taxon',
            'collectionName' => 'autocomplete_collection',
            'collectionCode' => 'autocomplete_collection',
            'institutionCode' => 'autocomplete_collection',
            'collid' => 'autocomplete_collection',
            'collection' => 'autocomplete_collection',  // Alias
            'county' => 'autocomplete_location',
            'country' => 'autocomplete_location',
            'stateProvince' => 'autocomplete_location',
            'state' => 'autocomplete_location',  // Alias for stateProvince
            'eventDate' => 'autocomplete_date',
            'date' => 'autocomplete_date'  // Alias for eventDate
        ];

        return $tableMap[$field] ?? '';
    }

    /**
     * Get value column name for autocomplete table
     *
     * @param string $table Table name
     * @return string Column name
     */
    private function getValueColumn(string $table): string
    {
        $columnMap = [
            'autocomplete_taxon' => 'taxon_name',
            'autocomplete_collection' => 'collection_value',
            'autocomplete_location' => 'location_value',
            'autocomplete_date' => 'date_value'
        ];

        return $columnMap[$table] ?? 'value';
    }

    /**
     * Get cache database connection
     *
     * @return PDO
     */
    private function getCacheConnection(): PDO
    {
        if (!$this->cacheDb) {
            $this->cacheDb = new PDO(
                'sqlite:' . $this->cacheDbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            // Attach source.db if available (for querying display fields)
            if (!empty($this->sourceDbPath) && file_exists($this->sourceDbPath)) {
                $this->cacheDb->exec("ATTACH DATABASE '{$this->sourceDbPath}' AS source");
            }
        }

        return $this->cacheDb;
    }

    // ========================================================================
    // Search Helper Methods (for testing - in production would use MySQL)
    // ========================================================================

    /**
     * Search the EAV index for entities matching the query
     * NOTE: In production, this would query MySQL directly
     */
    private function searchEavIndex(PDO $db, string $query, array $queryAnd, array $queryOr, int $limit, int $offset): array
    {
        // Collect all entity IDs from different query types
        $entityIdSets = [];

        // For AND/OR queries, we need ALL matching Eids to perform intersection/union correctly
        // Use PHP_INT_MAX to effectively remove the limit
        $unlimitedQueryLimit = PHP_INT_MAX;
        $queryLimit = (!empty($queryAnd) || !empty($queryOr)) ? $unlimitedQueryLimit : ($limit + $offset);

        if (!empty($query)) {
            $entityIdSets['main'] = $this->executeEavQuery($db, $query, $queryLimit);
        }

        if (!empty($queryAnd)) {
            foreach ($queryAnd as $andQuery) {
                // Must fetch ALL matching Eids for intersection to work correctly
                $results = $this->executeEavQuery($db, $andQuery, $unlimitedQueryLimit);
                $entityIdSets['and_' . md5($andQuery)] = $results;
            }
        }

        if (!empty($queryOr)) {
            $orEntityIds = [];
            foreach ($queryOr as $orQuery) {
                // Must fetch ALL matching Eids for union to work correctly
                $orResults = $this->executeEavQuery($db, $orQuery, $unlimitedQueryLimit);
                $orEntityIds = array_merge($orEntityIds, $orResults);
            }
            if (!empty($orEntityIds)) {
                $entityIdSets['or'] = array_unique($orEntityIds);
            }
        }

        if (empty($entityIdSets)) {
            return [];
        }

        // Start with first set
        $finalEntityIds = array_shift($entityIdSets);

        // Intersect with remaining sets (AND logic)
        foreach ($entityIdSets as $key => $entityIds) {
            if (str_starts_with($key, 'and_')) {
                $finalEntityIds = array_intersect($finalEntityIds, $entityIds);
            } elseif ($key === 'or') {
                $finalEntityIds = array_unique(array_merge($finalEntityIds, $entityIds));
            }
        }

        if (empty($finalEntityIds)) {
            return [];
        }

        // Apply limit to entity IDs before loading
        $limitedEntityIds = array_slice($finalEntityIds, $offset, $limit);
        return $this->getEavEntities($db, $limitedEntityIds);
    }

    /**
     * Resolve field alias to actual field name(s)
     * Returns array of field names (multiple for multi-field aliases like 'taxon')
     *
     * @param string $fieldName Field name or alias
     * @return array Array of actual field names
     */
    private function resolveFieldAlias(string $fieldName): array
    {
        // Multi-field aliases that search across multiple fields
        // Note: Only include fields that are actually indexed in hybrid cache
        $multiFieldAliases = [
            'taxon' => ['family', 'genus'],  // sciname excluded (not indexed in hybrid)
            'collection' => ['collectionName', 'collectionCode', 'institutionCode'],
            'location' => ['country', 'stateProvince', 'county']
        ];

        $lowerField = strtolower($fieldName);

        // Check if this is a multi-field alias
        if (isset($multiFieldAliases[$lowerField])) {
            return $multiFieldAliases[$lowerField];
        }

        // Check if this is a single-field alias from config
        if (isset($this->config['aliases'])) {
            $aliases = $this->config['aliases'];
            if (isset($aliases[$fieldName])) {
                return [$aliases[$fieldName]];
            }
        }

        // Not an alias, return as-is
        return [$fieldName];
    }

    /**
     * Execute a single EAV query and return entity IDs
     */
    private function executeEavQuery(PDO $db, string $query, int $limit): array
    {
        // Parse field:value syntax
        $parts = explode(':', $query, 2);
        if (count($parts) === 2) {
            $fieldName = trim($parts[0]);
            $searchValue = trim($parts[1]);

            // Resolve aliases and handle multi-field searches
            $fieldNames = $this->resolveFieldAlias($fieldName);

            // Build WHERE clause for single or multiple fields
            if (count($fieldNames) === 1) {
                // Single field search (use substring match for flexibility)
                $sql = "SELECT DISTINCT e.Eid
                        FROM Entities e
                        JOIN EAV eav ON e.Eid = eav.Eid
                        JOIN Attributes a ON eav.Aid = a.Aid
                        JOIN ValuesText v ON eav.Vid = v.Vid
                        WHERE LOWER(a.ColumnName) = LOWER(:fieldName)
                        AND LOWER(v.ValueText) LIKE LOWER(:search)
                        LIMIT :limit";

                $stmt = $db->prepare($sql);
                $stmt->bindValue(':fieldName', $fieldNames[0], PDO::PARAM_STR);
                $stmt->bindValue(':search', '%' . $searchValue . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } else {
                // Multi-field search (e.g., taxon searches across family, genus)
                // Use substring match for flexibility
                $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));
                $sql = "SELECT DISTINCT e.Eid
                        FROM Entities e
                        JOIN EAV eav ON e.Eid = eav.Eid
                        JOIN Attributes a ON eav.Aid = a.Aid
                        JOIN ValuesText v ON eav.Vid = v.Vid
                        WHERE LOWER(a.ColumnName) IN (" . $placeholders . ")
                        AND LOWER(v.ValueText) LIKE LOWER(?)
                        LIMIT ?";

                $stmt = $db->prepare($sql);
                $params = array_map('strtolower', $fieldNames);
                $params[] = '%' . $searchValue . '%';
                $params[] = $limit;
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            }
        } else {
            // Keyword search (case-insensitive, substring match)
            $sql = "SELECT DISTINCT e.Eid
                    FROM Entities e
                    JOIN EAV eav ON e.Eid = eav.Eid
                    JOIN ValuesText v ON eav.Vid = v.Vid
                    WHERE LOWER(v.ValueText) LIKE LOWER(:search)
                    LIMIT :limit";

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':search', '%' . $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    }

    /**
     * Get full entity data from EAV index
     *
     * Queries indexed fields from cache and display fields from attached source.db
     */
    private function getEavEntities(PDO $db, array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

        // Get base entity data from Entities table
        // Hybrid schema: Eid, mediaID, occid (no URL fields stored here)
        $displaySql = sprintf("
            SELECT Eid, mediaID, occid
            FROM Entities
            WHERE Eid IN (%s)
        ", $placeholders);

        $stmt = $db->prepare($displaySql);
        $stmt->execute($entityIds);
        $displayRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize entities with base fields
        $entities = [];
        foreach ($displayRows as $row) {
            $entities[$row['Eid']] = $row;
        }

        // Query EAV data (indexed fields from cache)
        // Note: Hybrid schema doesn't have VidOrder (no :split tokenization support)
        $sql = sprintf("
            SELECT
                e.Eid,
                a.ColumnName,
                a.TokenStrategy,
                COALESCE(v.ValueText, CAST(eav.ValueNumber AS TEXT)) as Value
            FROM Entities e
            JOIN EAV eav ON e.Eid = eav.Eid
            JOIN Attributes a ON eav.Aid = a.Aid
            LEFT JOIN ValuesText v ON eav.Vid = v.Vid
            WHERE e.Eid IN (%s)
            ORDER BY e.Eid, a.ColumnName
        ", $placeholders);

        $stmt = $db->prepare($sql);
        $stmt->execute($entityIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add EAV attributes to entities
        foreach ($rows as $row) {
            $eid = $row['Eid'];
            $columnName = $row['ColumnName'];
            $value = $row['Value'];

            // Hybrid schema only supports :whole tokenization
            $entities[$eid][$columnName] = $value;
        }

        // Query display fields from attached source.db (if available)
        // This includes url, originalUrl, thumbnailUrl and other non-indexed fields
        if (!empty($this->sourceDbPath) && file_exists($this->sourceDbPath)) {
            // Get mediaIDs for querying source.db
            $mediaIds = array_column($entities, 'mediaID');

            if (!empty($mediaIds)) {
                $mediaPlaceholders = implode(',', array_fill(0, count($mediaIds), '?'));

                // Query display fields from source.db
                $sourceSql = sprintf("
                    SELECT
                        m.mediaID,
                        m.url,
                        m.originalUrl,
                        m.thumbnailUrl
                    FROM source.media m
                    WHERE m.mediaID IN (%s)
                ", $mediaPlaceholders);

                $stmt = $db->prepare($sourceSql);
                $stmt->execute($mediaIds);
                $sourceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Map source data back to entities by mediaID
                $sourceDataByMediaId = [];
                foreach ($sourceRows as $row) {
                    $sourceDataByMediaId[$row['mediaID']] = $row;
                }

                // Add source data to entities
                foreach ($entities as &$entity) {
                    $mediaID = $entity['mediaID'];
                    if (isset($sourceDataByMediaId[$mediaID])) {
                        $sourceData = $sourceDataByMediaId[$mediaID];
                        $entity['url'] = $sourceData['url'] ?? '';
                        $entity['originalUrl'] = $sourceData['originalUrl'] ?? '';
                        $entity['thumbnailUrl'] = $sourceData['thumbnailUrl'] ?? '';
                    }
                }
                unset($entity); // Break reference
            }
        }

        return array_values($entities);
    }

    /**
     * Convert EAV entities to image format
     * Hybrid schema stores: mediaID, occid in Entities table
     * URLs and other fields are in EAV attributes
     */
    private function convertEavEntitiesToImages(array $entities): array
    {
        $images = [];

        foreach ($entities as $entity) {
            // Hybrid schema: mediaID is in Entities table
            $mediaID = $entity['mediaID'] ?? $entity['Eid'] ?? 0;
            $mediaID = (int)$mediaID;

            $images[] = [
                'mediaID' => $mediaID,
                'url' => $entity['url'] ?? '',
                'originalUrl' => $entity['originalUrl'] ?? $entity['url'] ?? '',
                'thumbnailUrl' => $entity['thumbnailUrl'] ?? $entity['url'] ?? '',
                'occid' => $entity['occid'] ?? null,
                'sciName' => $entity['sciname'] ?? $entity['sciName'] ?? null,
                'collectionName' => $entity['collectionName'] ?? null,
                'catalogNumber' => $entity['catalogNumber'] ?? null,
                'eventDate' => $entity['eventDate'] ?? null,
            ];
        }

        return $images;
    }

    /**
     * Render images with load more button
     */
    private function renderImagesWithLoadMore(array $images, int $limit, int $offset, string $loadMoreUrl, ?string $query = null): array
    {
        // Check if no results found
        if (empty($images) && $offset === 0) {
            $noResultsHtml = sprintf(
                '<div class="no-results">No images found%s</div>',
                $query ? ' for: ' . htmlspecialchars($query) : ''
            );

            return [
                'type' => 'htmx',
                'content' => $noResultsHtml
            ];
        }

        // Generate images HTML using template
        $imageItems = [];
        $appUrlPrefix = $this->getAppUrlPrefix();

        foreach ($images as $image) {
            $imageItems[] = $this->renderTemplate('image_item', TemplateFormat::HTML, [
                'mediaID' => $image['mediaID'] ?? '',
                'thumbnailUrl' => htmlspecialchars($image['thumbnailUrl'] ?? $image['url'] ?? ''),
                'url' => htmlspecialchars($image['url'] ?? ''),
                'filename' => htmlspecialchars($image['filename'] ?? ''),
                'caption' => htmlspecialchars($image['caption'] ?? ''),
                'occid' => htmlspecialchars($image['occid'] ?? ''),
                'app_url_prefix' => $appUrlPrefix,
                'caption_display' => !empty($image['caption']) ?
                    sprintf('<div><i class="fas fa-image me-1"></i>%s</div>', htmlspecialchars($image['caption'])) : ''
            ]);
        }

        $imagesHtml = implode("\n", $imageItems);

        // Generate load more button if we got a full batch
        $loadMoreHtml = '';
        if (count($images) >= $limit) {
            $loadMoreHtml = $this->renderTemplate('load_more_button', TemplateFormat::HTML, [
                'app_url_prefix' => $appUrlPrefix,
                'search_url' => $loadMoreUrl
            ]);
        }

        return [
            'type' => 'htmx',
            'content' => $this->renderTemplate('image_grid', TemplateFormat::HTML, [
                'images_html' => $imagesHtml,
                'load_more_html' => $loadMoreHtml
            ])
        ];
    }

    /**
     * Format search results for CLI output
     */
    private function formatCliSearchResults(array $images, ?string $query = null): array
    {
        if (empty($images)) {
            $message = $query ? "No images found for: {$query}" : "No images found";
            return [
                'type' => 'cli',
                'content' => $message
            ];
        }

        // Build CLI table
        $output = [];
        if ($query) {
            $output[] = "Search results for: {$query}";
            $output[] = str_repeat('=', 80);
        }

        $output[] = sprintf(
            "%-10s %-10s %-50s %-30s",
            'MediaID',
            'OccID',
            'URL',
            'Thumbnail'
        );
        $output[] = str_repeat('-', 80);

        foreach ($images as $image) {
            $output[] = sprintf(
                "%-10s %-10s %-50s %-30s",
                $image['mediaID'] ?? '',
                $image['occid'] ?? '',
                substr($image['url'] ?? '', 0, 50),
                substr($image['thumbnailUrl'] ?? '', 0, 30)
            );
        }

        $output[] = '';
        $output[] = sprintf('Total: %d images', count($images));

        return [
            'type' => 'cli',
            'content' => implode("\n", $output)
        ];
    }

    /**
     * Get app URL prefix for templates
     */
    private function getAppUrlPrefix(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/?/';
        $parser = new \Symbiota\Helpers\Core\UriParser($requestUri);
        $fullUrl = $parser->getAppUrlPrefix();

        if (str_contains($fullUrl, '://')) {
            $parts = parse_url($fullUrl);
            return ($parts['path'] ?? '') . '?/';
        }

        return $fullUrl;
    }

    /**
     * Get human-readable description for a field
     *
     * @param string $alias Field alias
     * @param string $actualField Actual field name
     * @return string Field description
     */
    private function getFieldDescription(string $alias, string $actualField): string
    {
        $descriptions = [
            'date' => 'Event date (supports <, >, <=, >=, ranges)',
            'taxon' => 'Scientific name or taxon',
            'genus' => 'Genus name',
            'family' => 'Family name',
            'state' => 'State or province',
            'country' => 'Country name',
            'locality' => 'Locality description',
            'county' => 'County name',
            'municipality' => 'Municipality name',
            'collection' => 'Collection name, code, or institution (searches all)',
            'collectioncode' => 'Collection code',
            'collid' => 'Collection ID (numeric)',
            'catalog' => 'Catalog number',
            'occid' => 'Occurrence ID',
            'photographer' => 'Photographer name',
            'owner' => 'Image owner',
            'copyright' => 'Copyright holder',
            'rights' => 'Usage rights',
            'type' => 'Image type/format',
            'tag' => 'Image tag',
            'notes' => 'Image notes',
        ];

        return $descriptions[$alias] ?? "Search by $actualField";
    }

    /**
     * Autocomplete collection values (searches across multiple collection fields)
     *
     * @param string $query Search query
     * @return array Response array
     */
    private function autocompleteCollectionValues(string $query): array
    {
        // For now, delegate to autocompleteValues with collectionName field
        // In production, this would search across collectionName, collectionCode, and institutionCode
        return $this->autocompleteValues([
            'field' => 'collectionName',
            'q' => $query
        ]);
    }

    /**
     * Render template using TemplateEngine
     */
    private function renderTemplate(string $name, $format, array $variables = []): string
    {
        $templatesPath = ApplicationPaths::templatesDirectory();
        $templateEngine = new \Symbiota\Helpers\Core\TemplateEngine($templatesPath);

        // Prepend 'images/' to template name for model-specific templates
        $templatePath = sprintf('images/%s', $name);

        return $templateEngine->template($templatePath, $format, $variables);
    }
}
