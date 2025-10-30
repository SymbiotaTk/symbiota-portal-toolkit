<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Interfaces\ImagesSearchInterface;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\UriParser;
use Symbiota\Helpers\Core\SqlTemplateParser;
use PDO;
use Exception;

/**
 * Images Model Flat - Normalized Search Index Plugin
 *
 * Flat search model using normalized tables with comprehensive indexes.
 * Implements ImagesSearchInterface as a plugin for ImagesModel delegation.
 * Optimized for large datasets with sub-second search performance.
 *
 * Architecture (Option B):
 * - Normalized tables: media, omoccurrences, omcollections, taxa
 * - Comprehensive indexes on all searchable fields
 * - JOINs with indexed foreign keys (fast with proper indexes)
 * - Autocomplete tables for fast typeahead
 * - No data duplication (same size as source.db ~3.1GB)
 *
 * Performance characteristics:
 * - Single-field search: ~5-10ms (6M records)
 * - Multi-field AND search: ~30-50ms (6M records)
 * - Database size: ~2.9GB (normalized, no duplication)
 *
 * @version 3.0.0
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */
class ImagesModelFlat implements ImagesSearchInterface
{
    private string $flatIndexDbPath;
    private string $configPath;
    private array $config;
    private array $searchableFields;
    private array $displayFields;
    private string $searchKeyword;
    private ?PDO $db = null;

    /**
     * Constructor
     *
     * @param array $params Configuration parameters
     * @throws Exception If config file not found
     */
    public function __construct(array $params)
    {
        $this->flatIndexDbPath = $params['flat_index_db_path'] ?? '';
        $this->configPath = $params['config_path'] ?? __DIR__ . '/../../templates/sql/images/flat/index_config.ini';

        if (empty($this->configPath) || !file_exists($this->configPath)) {
            throw new Exception("Config file not found: {$this->configPath}");
        }

        // Load configuration
        $this->config = parse_ini_file($this->configPath, true);

        // Parse searchable fields from indexes configuration
        $this->searchableFields = $this->parseSearchableFieldsFromIndexes();
        $this->displayFields = $this->parseFieldsFromConfig('display_fields');

        // Get configurable search keyword (default: 'q')
        // This prevents conflicts with actual database column names
        $this->searchKeyword = $this->getSearchKeyword();
    }

    /**
     * Parse searchable fields from indexes configuration
     *
     * @return array Array of field names WITH table prefixes (e.g., 'o.family', 'c.collectionCode')
     */
    private function parseSearchableFieldsFromIndexes(): array
    {
        $fields = [];
        $addedFields = []; // Track unique field names to avoid duplicates

        if (isset($this->config['indexes']['indexes'])) {
            foreach ($this->config['indexes']['indexes'] as $indexDef) {
                // Parse format: "table.column" or "table.column:type"
                $parts = explode(':', $indexDef);
                $tableColumn = $parts[0];

                // Extract table and column name
                if (strpos($tableColumn, '.') !== false) {
                    list($table, $column) = explode('.', $tableColumn, 2);

                    // Skip foreign key columns (but keep occid - it's searchable!)
                    // Note: occid is searchable for direct occurrence lookup
                    if (in_array($column, ['collid', 'tid', 'tidinterpreted', 'mediaID'])) {
                        continue;
                    }

                    // Map full table names to aliases used in SQL queries
                    $tableAliases = [
                        'media' => 'm',
                        'omoccurrences' => 'o',
                        'omcollections' => 'c',
                        'taxa' => 't'
                    ];

                    $tableAlias = $tableAliases[$table] ?? $table;
                    $fieldWithPrefix = $tableAlias . '.' . $column;

                    // Add unique fields (avoid duplicates like o.sciname appearing twice)
                    if (!in_array($column, $addedFields)) {
                        $fields[] = $fieldWithPrefix;
                        $addedFields[] = $column;
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * Parse fields from config section
     *
     * @param string $section Config section name
     * @return array Array of field names
     */
    private function parseFieldsFromConfig(string $section): array
    {
        $fields = [];
        if (isset($this->config[$section]['fields'])) {
            foreach ($this->config[$section]['fields'] as $fieldDef) {
                // Parse format: "fieldName:type:table"
                $parts = explode(':', $fieldDef);
                $fields[] = $parts[0];
            }
        }
        return $fields;
    }

    /**
     * Get configurable search keyword from config.php
     *
     * This keyword is used for freetext searches (searches without field:value syntax).
     * Default is 'q' to avoid conflicts with actual database column names.
     * Can be configured in config.php: [mod.images] search_keyword = "q"
     *
     * @return string Search keyword (default: 'q')
     */
    private function getSearchKeyword(): string
    {
        try {
            $config = \Symbiota\Helpers\Core\Configuration::getInstance();
            if ($config === null) {
                return 'q';
            }
            $keyword = $config->get('components.images.search_keyword');
            return !empty($keyword) ? $keyword : 'q';
        } catch (\Exception $e) {
            // Fallback to default if config not available
            return 'q';
        }
    }

    /**
     * Search for images using flat denormalized index
     *
     * @param array $params Search parameters
     * @return array Response array with type and content
     */
    public function search(array $params): array
    {
        try {
            // Get database path
            $dbPath = $params['db-path'] ?? $this->flatIndexDbPath;

            if (empty($dbPath) || !file_exists($dbPath)) {
                throw new Exception("Flat index database not found: $dbPath");
            }

            // Open database in read-only mode
            $db = new PDO(sprintf('sqlite:%s', $dbPath), null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);

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

            // Execute search on flat index
            $results = $this->searchFlatIndex($db, $query, $queryAnd, $queryOr, $limit, $offset);

            // Convert results to images format
            $images = $this->convertResultsToImages($results);

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
            $errorMsg = sprintf('Flat search failed: %s', $e->getMessage());

            // Use centralized format detection from Environment singleton
            $format = $params['format'] ?? \Symbiota\Helpers\Core\Environment::getInstance()->getResponseFormat();
            $isCli = ($format === 'cli');

            if ($isCli) {
                return [
                    'type' => 'cli',
                    'content' => "ERROR: $errorMsg\n"
                ];
            } else {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="alert alert-danger">' . htmlspecialchars($errorMsg) . '</div>'
                ];
            }
        }
    }

    /**
     * Search the flat index for matching records (using normalized tables with JOINs)
     *
     * Uses UNION queries for multi-field searches to leverage indexes efficiently.
     * Single-field searches use simple WHERE clauses.
     *
     * @param PDO $db Database connection
     * @param string $query Main query
     * @param array $queryAnd AND queries
     * @param array $queryOr OR queries
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchFlatIndex(PDO $db, string $query, array $queryAnd, array $queryOr, int $limit, int $offset): array
    {
        // Collect all queries (main + AND queries)
        $allQueries = [];
        if (!empty($query)) {
            $allQueries[] = $query;
        }
        $allQueries = array_merge($allQueries, $queryAnd);

        if (empty($allQueries) && empty($queryOr)) {
            return [];
        }

        // Check if main query is a keyword search (use triple inverted index)
        if (!empty($query) && strpos($query, ':') !== false) {
            list($fieldName, $searchTerm) = explode(':', $query, 2);
            $fieldName = strtolower(trim($fieldName));

            // Check if this is the configurable keyword search (default: 'q')
            if ($fieldName === strtolower($this->searchKeyword) && empty($queryOr)) {
                // Use optimized keyword search with triple inverted index
                return $this->searchKeywordOptimized($db, trim($searchTerm), $queryAnd, $limit, $offset);
            }

            if ($fieldName === 'taxon' && empty($queryOr)) {
                // Use optimized taxon search with inverted index
                // Pass additional filters from queryAnd
                return $this->searchTaxonOptimized($db, trim($searchTerm), $queryAnd, $limit, $offset);
            }

            // Check if this is a collection search (optimize by searching collections table first)
            $collectionFields = ['collection', 'collectioncode', 'collectionname', 'institutioncode'];
            if (in_array($fieldName, $collectionFields) && empty($queryOr)) {
                // Use optimized collection search
                return $this->searchCollectionOptimized($db, $fieldName, trim($searchTerm), $queryAnd, $limit, $offset);
            }

            // Check if this is an occid search (use exact match, not LIKE)
            if ($fieldName === 'occid' && empty($queryOr)) {
                return $this->searchByOccidExact($db, trim($searchTerm), $queryAnd, $limit, $offset);
            }

            // Check if this is an occid search (use exact match with indexed lookup - FASTEST!)
            if ($fieldName === 'occid' && empty($queryOr)) {
                return $this->searchByOccidExact($db, trim($searchTerm), $queryAnd, $limit, $offset);
            }

            // Check if this is a single-field occurrence search (optimize by searching occurrences first)
            $occurrenceFields = ['country', 'stateprovince', 'state', 'county', 'locality', 'catalognumber', 'catalog', 'family', 'genus', 'sciname', 'scientificname'];
            if (in_array($fieldName, $occurrenceFields) && empty($queryOr)) {
                // Use optimized occurrence search
                return $this->searchOccurrenceOptimized($db, $fieldName, trim($searchTerm), $queryAnd, $limit, $offset);
            }
        }

        // Check if main query is a keyword search WITHOUT field prefix (e.g., just "maple")
        // This happens when user types freetext without field:value syntax
        if (!empty($query) && strpos($query, ':') === false && empty($queryOr)) {
            // Use optimized keyword search with triple inverted index
            return $this->searchKeywordOptimized($db, trim($query), $queryAnd, $limit, $offset);
        }

        // Check if we need UNION optimization (multi-field search)
        $needsUnion = false;
        foreach ($allQueries as $q) {
            $parsed = $this->parseFieldValueQuery($q);
            if ($parsed && count($parsed['fields']) > 1) {
                $needsUnion = true;
                break;
            }
        }

        // Use UNION optimization for multi-field searches
        if ($needsUnion && count($allQueries) === 1 && empty($queryOr)) {
            return $this->searchWithUnion($db, $allQueries[0], $limit, $offset);
        }

        // Fall back to standard JOIN query for simple searches
        return $this->searchWithJoin($db, $allQueries, $queryOr, $limit, $offset);
    }

    /**
     * Search using optimized strategy for multi-field searches
     *
     * Strategy: For taxon searches, search omoccurrences table first (smaller table),
     * then join to media. Use CONCAT to combine fields for single table scan.
     *
     * @param PDO $db Database connection
     * @param string $query Query string
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchWithUnion(PDO $db, string $query, int $limit, int $offset): array
    {
        $parsed = $this->parseFieldValueQuery($query);
        if (!$parsed || count($parsed['fields']) <= 1) {
            // Single-field search - use standard query
            return $this->searchWithJoin($db, [$query], [], $limit, $offset);
        }

        // Check if this is a taxon search
        $fieldName = '';
        if (strpos($query, ':') !== false) {
            list($fieldName, $searchTerm) = explode(':', $query, 2);
            $fieldName = strtolower(trim($fieldName));
        }

        if ($fieldName === 'taxon') {
            // Optimized taxon search: search omoccurrences first, then join to media
            // Note: This is only called for single taxon query with no filters
            return $this->searchTaxonOptimized($db, trim($searchTerm), [], $limit, $offset);
        }

        // For other multi-field searches, use standard query
        return $this->searchWithJoin($db, [$query], [], $limit, $offset);
    }

    /**
     * Optimized taxon search using inverted index (triple store concept)
     *
     * Strategy:
     * 1. Create temporary table with matching token_ids (indexed lookup)
     * 2. Create temporary table with matching occids via JOIN (indexed)
     * 3. Apply additional filters to temp_matching_occids (if any)
     * 4. Join to media using temporary occid table (all in SQLite, no PHP arrays)
     *
     * This keeps all operations in SQLite using temporary tables instead of
     * passing large arrays through PHP, which is much more efficient.
     *
     * Uses configuration from index_config.ini and SQL templates.
     *
     * @param PDO $db Database connection
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional AND filters to apply
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchTaxonOptimized(PDO $db, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Get inverted index configuration from INI
        $tokenTable = $this->config['inverted_index']['token_table'] ?? 'TaxonTokens';
        $tokenIdCol = $this->config['inverted_index']['token_id_column'] ?? 'token_id';
        $tokenValueCol = $this->config['inverted_index']['token_value_column'] ?? 'taxon_value';
        $indexTable = $this->config['inverted_index']['index_table'] ?? 'OccurrenceTaxonIndex';
        $indexOccidCol = $this->config['inverted_index']['index_occid_column'] ?? 'occid';
        $indexTokenCol = $this->config['inverted_index']['index_token_column'] ?? 'token_id';

        $search = '%' . $searchTerm . '%';

        // Step 1: Create temporary table with matching token_ids
        // This stays in SQLite - no PHP array transfer
        $db->exec("DROP TABLE IF EXISTS temp_matching_tokens");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_tokens (token_id INTEGER PRIMARY KEY)");

        $sql = "INSERT INTO temp_matching_tokens (token_id)
                SELECT {$tokenIdCol} FROM {$tokenTable}
                WHERE {$tokenValueCol} LIKE :search COLLATE NOCASE";

        $stmt = $db->prepare($sql);
        $stmt->execute(['search' => $search]);

        // Check if any tokens matched
        $tokenCount = $db->query("SELECT COUNT(*) FROM temp_matching_tokens")->fetchColumn();
        if ($tokenCount == 0) {
            return [];
        }

        // Step 2: Pre-filter by collection/location filters FIRST (if any)
        // This dramatically reduces the dataset before intersecting with taxon matches
        $preFilterOccids = null;
        $remainingFilters = [];

        if (!empty($additionalFilters)) {
            foreach ($additionalFilters as $filter) {
                // Extract original field name BEFORE parsing (to preserve alias like 'collection')
                // This is needed for resolveCollectionToCollids which expects the original alias
                $originalFieldName = '';
                if (strpos($filter, ':') !== false) {
                    list($originalFieldName, ) = explode(':', $filter, 2);
                    $originalFieldName = trim($originalFieldName);
                    // Remove exclusion/exclusive prefixes
                    $originalFieldName = ltrim($originalFieldName, '^!');
                }

                $parsed = $this->parseFieldValueQuery($filter);
                if (!$parsed) {
                    $remainingFilters[] = $filter;
                    continue;
                }

                // Extract field name for detection
                $firstField = $parsed['fields'][0] ?? '';
                $fieldNameLower = strtolower($firstField);
                // Remove table prefix if present for comparison (e.g., "c.institutionCode" -> "institutioncode")
                $fieldNameNoPrefix = $fieldNameLower;
                if (strpos($fieldNameLower, '.') !== false) {
                    $fieldNameNoPrefix = substr($fieldNameLower, strpos($fieldNameLower, '.') + 1);
                }
                // Extract search value - remove wildcards added by parseFieldValueQuery
                $searchValue = $parsed['search'] ?? '';
                $searchValue = trim($searchValue, '%'); // Remove % wildcards

                // Check if this is a collection filter (can be pre-filtered efficiently)
                $collectionFields = ['collection', 'collectioncode', 'collectionname', 'institutioncode'];
                if (in_array($fieldNameNoPrefix, $collectionFields)) {
                    // Resolve collection search to collid(s) using CollectionLookup
                    // Use the ORIGINAL field name (before alias resolution) so resolveCollectionToCollids
                    // can properly expand 'collection' to all three fields (collectionName, collectionCode, institutionCode)
                    $fieldNameForResolve = !empty($originalFieldName) ? $originalFieldName : $firstField;
                    $collids = $this->resolveCollectionToCollids($db, $fieldNameForResolve, $searchValue);

                    if (empty($collids)) {
                        return []; // No matching collections = no results
                    }

                    // Create/update temp table with occids for these collections
                    // Use SQL entirely - no PHP arrays (avoids memory issues)
                    if ($preFilterOccids === null) {
                        // First collection filter - create temp table
                        $db->exec("DROP TABLE IF EXISTS temp_prefilter_occids");
                        $db->exec("CREATE TEMPORARY TABLE temp_prefilter_occids (occid INTEGER PRIMARY KEY)");

                        $placeholders = implode(',', array_fill(0, count($collids), '?'));
                        $sql = "INSERT INTO temp_prefilter_occids (occid)
                                SELECT occid FROM OccurrenceCollectionIndex
                                WHERE collid IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($collids);

                        $preFilterOccids = 'temp_table'; // Flag that we have a temp table
                    } else {
                        // Subsequent collection filter - intersect with existing temp table
                        $db->exec("DROP TABLE IF EXISTS temp_collection_filter");
                        $db->exec("CREATE TEMPORARY TABLE temp_collection_filter (occid INTEGER PRIMARY KEY)");

                        $placeholders = implode(',', array_fill(0, count($collids), '?'));
                        $sql = "INSERT INTO temp_collection_filter (occid)
                                SELECT occid FROM OccurrenceCollectionIndex
                                WHERE collid IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($collids);

                        // Intersect: keep only occids that are in BOTH tables
                        $db->exec("CREATE TEMPORARY TABLE temp_intersect AS
                                   SELECT pf.occid
                                   FROM temp_prefilter_occids pf
                                   INNER JOIN temp_collection_filter cf ON pf.occid = cf.occid");

                        $db->exec("DROP TABLE temp_prefilter_occids");
                        $db->exec("ALTER TABLE temp_intersect RENAME TO temp_prefilter_occids");
                        $db->exec("DROP TABLE temp_collection_filter");
                    }

                    // Check if any occids remain
                    $count = $db->query("SELECT COUNT(*) FROM temp_prefilter_occids")->fetchColumn();
                    if ($count == 0) {
                        return []; // No matching occids
                    }
                    continue; // Filter applied, don't add to remainingFilters
                }

                // Not a collection filter - will be applied later
                $remainingFilters[] = $filter;
            }
        }

        // Step 3: Create temporary table with matching occids via JOIN
        // If we have pre-filter occids, intersect with them immediately
        $db->exec("DROP TABLE IF EXISTS temp_matching_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_occids (occid INTEGER PRIMARY KEY)");

        if ($preFilterOccids !== null) {
            // We have pre-filtered occids in temp_prefilter_occids table
            // Now intersect taxon matches with pre-filtered occids (FAST!)
            $sql = "INSERT INTO temp_matching_occids (occid)
                    SELECT DISTINCT idx.{$indexOccidCol}
                    FROM {$indexTable} idx
                    INNER JOIN temp_matching_tokens t ON idx.{$indexTokenCol} = t.token_id
                    INNER JOIN temp_prefilter_occids pf ON idx.{$indexOccidCol} = pf.occid";
            $db->exec($sql);
        } else {
            // No pre-filter - use original logic
            $sql = "INSERT INTO temp_matching_occids (occid)
                    SELECT DISTINCT idx.{$indexOccidCol}
                    FROM {$indexTable} idx
                    INNER JOIN temp_matching_tokens t ON idx.{$indexTokenCol} = t.token_id";
            $db->exec($sql);
        }

        // Create index on temp table for fast JOIN in step 4
        $db->exec("CREATE INDEX IF NOT EXISTS idx_temp_occids ON temp_matching_occids(occid)");

        // Check if any occids matched
        $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
        if ($occidCount == 0) {
            return [];
        }

        // Step 4: Apply remaining filters to temp_matching_occids (if any)
        // These are filters that couldn't be pre-filtered (e.g., locality, catalogNumber)
        // Collection filters were already applied in Step 2
        if (!empty($remainingFilters)) {
            foreach ($remainingFilters as $filter) {
                $parsed = $this->parseFieldValueQuery($filter);
                if (!$parsed) {
                    continue;
                }

                // Build WHERE clause for non-collection filters
                $filterClause = $this->buildWhereClause($parsed);
                $filterParams = $this->getWhereParams($parsed);

                // Filter temp_matching_occids by removing occids that don't match
                $sql = "DELETE FROM temp_matching_occids
                        WHERE occid NOT IN (
                            SELECT o.occid
                            FROM omoccurrences o
                            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                            WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
                            AND {$filterClause}
                        )";

                $stmt = $db->prepare($sql);
                $stmt->execute($filterParams);

                // Check if any occids remain after filtering
                $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                if ($occidCount == 0) {
                    return [];
                }
            }
        }

        // Step 4: Get media records using temporary occid table
        // Final JOIN stays in SQLite, uses indexes on all tables
        $sql = "
            SELECT
                m.mediaID,
                m.url,
                m.originalUrl,
                m.thumbnailUrl,
                o.occid,
                o.family,
                o.genus,
                o.sciname,
                o.scientificname,
                o.catalogNumber,
                o.country,
                o.stateProvince,
                o.county,
                o.locality,
                c.collid,
                c.collectionCode,
                c.institutionCode,
                c.collectionName,
                t.sciname AS taxa_sciname
            FROM media m
            INNER JOIN temp_matching_occids tmp ON m.occid = tmp.occid
            INNER JOIN omoccurrences o ON m.occid = o.occid
            LEFT JOIN omcollections c ON o.collid = c.collid
            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Optimized keyword search using triple inverted index
     *
     * Strategy:
     * 1. Search TaxonTokens for matching values
     * 2. Search LocationTokens for matching values
     * 3. Search CollectionTokens for matching values
     * 4. UNION all matching occids from the three inverted indexes
     * 5. Apply additional filters (if any)
     * 6. JOIN to media using temporary occid table
     *
     * This avoids the 11 LIKE operations with OR logic that cause table scans.
     * Instead, we use three indexed lookups and UNION the results.
     *
     * @param PDO $db Database connection
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional AND filters to apply
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchKeywordOptimized(PDO $db, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        $search = '%' . $searchTerm . '%';

        // Step 1: Create temporary table for matching occids
        $db->exec("DROP TABLE IF EXISTS temp_keyword_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_keyword_occids (occid INTEGER PRIMARY KEY)");

        // Step 2: Insert occids from taxon matches
        $sql = "INSERT OR IGNORE INTO temp_keyword_occids (occid)
                SELECT DISTINCT idx.occid
                FROM TaxonTokens t
                INNER JOIN OccurrenceTaxonIndex idx ON t.token_id = idx.token_id
                WHERE t.taxon_value LIKE :search COLLATE NOCASE";
        $stmt = $db->prepare($sql);
        $stmt->execute(['search' => $search]);

        // Step 3: Insert occids from location matches
        $sql = "INSERT OR IGNORE INTO temp_keyword_occids (occid)
                SELECT DISTINCT idx.occid
                FROM LocationTokens t
                INNER JOIN OccurrenceLocationIndex idx ON t.token_id = idx.token_id
                WHERE t.location_value LIKE :search COLLATE NOCASE";
        $stmt = $db->prepare($sql);
        $stmt->execute(['search' => $search]);

        // Step 4: Insert occids from collection matches
        // Check if we have the optimized collid-based schema or the old token-based schema
        $hasOptimizedSchema = $this->hasOptimizedCollectionSchema($db);

        if ($hasOptimizedSchema) {
            // Use optimized collid-based lookup (faster)
            $sql = "INSERT OR IGNORE INTO temp_keyword_occids (occid)
                    SELECT DISTINCT idx.occid
                    FROM CollectionLookup cl
                    INNER JOIN OccurrenceCollectionIndex idx ON cl.collid = idx.collid
                    WHERE cl.collectionName LIKE :search COLLATE NOCASE
                       OR cl.collectionCode LIKE :search COLLATE NOCASE
                       OR cl.institutionCode LIKE :search COLLATE NOCASE";
        } else {
            // Use legacy token-based lookup (backward compatibility)
            $sql = "INSERT OR IGNORE INTO temp_keyword_occids (occid)
                    SELECT DISTINCT idx.occid
                    FROM CollectionTokens t
                    INNER JOIN OccurrenceCollectionIndex idx ON t.token_id = idx.token_id
                    WHERE t.collection_value LIKE :search COLLATE NOCASE";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute(['search' => $search]);

        // Check if any matches found
        $occidCount = $db->query("SELECT COUNT(*) FROM temp_keyword_occids")->fetchColumn();
        if ($occidCount == 0) {
            return [];
        }

        // Step 5: Apply additional filters (if any)
        if (!empty($additionalFilters)) {
            $filterClauses = [];
            $filterParams = [];

            foreach ($additionalFilters as $filter) {
                $parsed = $this->parseFieldValueQuery($filter);
                if ($parsed) {
                    $filterClauses[] = $this->buildWhereClause($parsed);
                    $filterParams = array_merge($filterParams, $this->getWhereParams($parsed));
                }
            }

            if (!empty($filterClauses)) {
                // Filter temp_keyword_occids by removing occids that don't match filters
                $whereClause = implode(' AND ', $filterClauses);
                $sql = "DELETE FROM temp_keyword_occids
                        WHERE occid NOT IN (
                            SELECT o.occid
                            FROM omoccurrences o
                            LEFT JOIN omcollections c ON o.collid = c.collid
                            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                            WHERE o.occid IN (SELECT occid FROM temp_keyword_occids)
                            AND {$whereClause}
                        )";

                $stmt = $db->prepare($sql);
                $stmt->execute($filterParams);

                // Check if any occids remain after filtering
                $occidCount = $db->query("SELECT COUNT(*) FROM temp_keyword_occids")->fetchColumn();
                if ($occidCount == 0) {
                    return [];
                }
            }
        }

        // Step 6: Get media records using temporary occid table
        $sql = "
            SELECT
                m.mediaID,
                m.url,
                m.originalUrl,
                m.thumbnailUrl,
                o.occid,
                o.family,
                o.genus,
                o.sciname,
                o.scientificname,
                o.catalogNumber,
                o.country,
                o.stateProvince,
                o.county,
                o.locality,
                c.collid,
                c.collectionCode,
                c.institutionCode,
                c.collectionName,
                t.sciname AS taxa_sciname
            FROM media m
            INNER JOIN temp_keyword_occids tmp ON m.occid = tmp.occid
            INNER JOIN omoccurrences o ON m.occid = o.occid
            LEFT JOIN omcollections c ON o.collid = c.collid
            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Optimized collection search using collid lookup
     *
     * Strategy (OPTIMIZED for speed):
     * 1. Search CollectionLookup table for matching collid values (tiny table, ~100-1000 rows)
     * 2. Use OccurrenceCollectionIndex to find occids with matching collid (indexed lookup)
     * 3. Apply additional filters to occurrences
     * 4. Join to media
     *
     * Performance benefits:
     * - LIKE search on small CollectionLookup table instead of large omcollections
     * - Direct collid -> occid mapping via index (no JOIN needed)
     * - Avoids scanning 6M+ occurrence records
     *
     * @param PDO $db Database connection
     * @param string $fieldName Field name (collection, collectionCode, etc.)
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional filters from queryAnd
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchCollectionOptimized(PDO $db, string $fieldName, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Check if optimized CollectionLookup table exists
        $hasCollectionLookup = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='CollectionLookup'")->fetchColumn();

        if ($hasCollectionLookup) {
            // OPTIMIZED PATH: Use CollectionLookup + OccurrenceCollectionIndex
            return $this->searchCollectionViaLookup($db, $fieldName, $searchTerm, $additionalFilters, $limit, $offset);
        } else {
            // FALLBACK PATH: Use omcollections table (slower but works with old indexes)
            return $this->searchCollectionViaOmcollections($db, $fieldName, $searchTerm, $additionalFilters, $limit, $offset);
        }
    }

    /**
     * Resolve collection search term to collid(s)
     *
     * Strategy:
     * 1. Try exact match first (fastest - no LIKE needed)
     * 2. Fall back to LIKE search if no exact match
     *
     * This enables autocomplete to provide exact values that resolve instantly
     * without any LIKE searches, while still supporting partial matches.
     *
     * @param PDO $db Database connection
     * @param string $fieldName Field name (collection, collectionCode, etc.)
     * @param string $searchTerm Search term
     * @return array Array of collid values
     */
    private function resolveCollectionToCollids(PDO $db, string $fieldName, string $searchTerm): array
    {
        $collectionFields = $this->resolveFieldAlias($fieldName);

        // Map field names to CollectionLookup columns
        $fieldMap = [
            'c.collectionName' => 'collectionName',
            'c.collectionCode' => 'collectionCode',
            'c.institutionCode' => 'institutionCode'
        ];

        // Step 1: Try exact match first (fastest - uses index, no LIKE)
        $exactWhereParts = [];
        $exactParams = [];
        foreach ($collectionFields as $field) {
            $column = $fieldMap[$field] ?? null;
            if ($column) {
                $exactWhereParts[] = "$column = ?";
                $exactParams[] = $searchTerm;
            }
        }

        if (!empty($exactWhereParts)) {
            $exactWhereClause = implode(' OR ', $exactWhereParts);
            $sql = "SELECT collid FROM CollectionLookup WHERE $exactWhereClause";
            $stmt = $db->prepare($sql);
            $stmt->execute($exactParams);
            $collids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // If exact match found, return immediately (no LIKE search needed!)
            if (!empty($collids)) {
                return $collids;
            }
        }

        // Step 2: Fall back to LIKE search for partial matches
        $likeWhereParts = [];
        $likeParams = [];
        foreach ($collectionFields as $field) {
            $column = $fieldMap[$field] ?? null;
            if ($column) {
                $likeWhereParts[] = "$column LIKE ?";
                $likeParams[] = '%' . $searchTerm . '%';
            }
        }

        if (empty($likeWhereParts)) {
            return [];
        }

        $likeWhereClause = implode(' OR ', $likeWhereParts);
        $sql = "SELECT collid FROM CollectionLookup WHERE $likeWhereClause";
        $stmt = $db->prepare($sql);
        $stmt->execute($likeParams);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Search collections using optimized CollectionLookup table
     * This is the FAST path for collection searches
     */
    private function searchCollectionViaLookup(PDO $db, string $fieldName, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Step 1: Resolve search term to collid(s)
        // This tries exact match first (instant), then falls back to LIKE
        $collids = $this->resolveCollectionToCollids($db, $fieldName, $searchTerm);

        if (empty($collids)) {
            return [];
        }

        // Step 2: Use OccurrenceCollectionIndex to find matching occids
        // This is a simple integer lookup - very fast!
        $db->exec("DROP TABLE IF EXISTS temp_matching_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_occids (occid INTEGER PRIMARY KEY)");

        // Use index to find occids with matching collid
        $placeholders = implode(',', array_fill(0, count($collids), '?'));
        $sql = "INSERT INTO temp_matching_occids (occid)
                SELECT DISTINCT occid FROM OccurrenceCollectionIndex WHERE collid IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($collids);

        // Step 3: Apply additional filters if provided
        // OPTIMIZATION: Pre-filter taxon searches using inverted index (FAST!)
        // Then apply remaining filters using standard WHERE clause (slower but necessary)
        if (!empty($additionalFilters)) {
            $remainingFilters = [];

            foreach ($additionalFilters as $filter) {
                // Check if this is a taxon filter - use inverted index for speed
                if (strpos($filter, ':') !== false) {
                    list($fieldName, $searchTerm) = explode(':', $filter, 2);
                    $fieldName = strtolower(trim($fieldName));
                    $searchTerm = trim($searchTerm);

                    if ($fieldName === 'taxon') {
                        // Use inverted index to pre-filter by taxon (FAST!)
                        $tokenTable = $this->config['inverted_index']['token_table'] ?? 'TaxonTokens';
                        $tokenIdCol = $this->config['inverted_index']['token_id_column'] ?? 'token_id';
                        $tokenValueCol = $this->config['inverted_index']['token_value_column'] ?? 'taxon_value';
                        $indexTable = $this->config['inverted_index']['index_table'] ?? 'OccurrenceTaxonIndex';
                        $indexOccidCol = $this->config['inverted_index']['index_occid_column'] ?? 'occid';
                        $indexTokenCol = $this->config['inverted_index']['index_token_column'] ?? 'token_id';

                        $search = '%' . $searchTerm . '%';

                        // Find matching tokens
                        $db->exec("DROP TABLE IF EXISTS temp_taxon_tokens");
                        $db->exec("CREATE TEMPORARY TABLE temp_taxon_tokens (token_id INTEGER PRIMARY KEY)");

                        $sql = "INSERT INTO temp_taxon_tokens (token_id)
                                SELECT {$tokenIdCol} FROM {$tokenTable}
                                WHERE {$tokenValueCol} LIKE :search COLLATE NOCASE";
                        $stmt = $db->prepare($sql);
                        $stmt->execute(['search' => $search]);

                        // Intersect temp_matching_occids with taxon matches
                        $sql = "DELETE FROM temp_matching_occids
                                WHERE occid NOT IN (
                                    SELECT DISTINCT idx.{$indexOccidCol}
                                    FROM {$indexTable} idx
                                    INNER JOIN temp_taxon_tokens t ON idx.{$indexTokenCol} = t.token_id
                                )";
                        $db->exec($sql);

                        // Check if any occids remain
                        $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                        if ($occidCount == 0) {
                            return [];
                        }

                        continue; // Filter applied, skip adding to remainingFilters
                    }
                }

                // Not a taxon filter - add to remaining filters
                $remainingFilters[] = $filter;
            }

            // Apply remaining filters using standard WHERE clause
            if (!empty($remainingFilters)) {
                $filterClauses = [];
                $filterParams = [];

                foreach ($remainingFilters as $filter) {
                    $parsed = $this->parseFieldValueQuery($filter);
                    if ($parsed) {
                        $filterClauses[] = $this->buildWhereClause($parsed);
                        $filterParams = array_merge($filterParams, $this->getWhereParams($parsed));
                    }
                }

                if (!empty($filterClauses)) {
                    // Filter temp_matching_occids by removing occids that don't match filters
                    $whereClause = implode(' AND ', $filterClauses);
                    $sql = "DELETE FROM temp_matching_occids
                            WHERE occid NOT IN (
                                SELECT o.occid
                                FROM omoccurrences o
                                LEFT JOIN omcollections c ON o.collid = c.collid
                                LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                                WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
                                AND {$whereClause}
                            )";

                    $stmt = $db->prepare($sql);
                    $stmt->execute($filterParams);

                    // Check if any occids remain
                    $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                    if ($occidCount == 0) {
                        return [];
                    }
                }
            }
        }

        // Step 4: Get media records using temporary occid table
        $sql = "
            SELECT
                m.mediaID,
                m.url,
                m.originalUrl,
                m.thumbnailUrl,
                o.occid,
                o.family,
                o.genus,
                o.sciname,
                o.scientificname,
                o.catalogNumber,
                o.country,
                o.stateProvince,
                o.county,
                o.locality,
                c.collid,
                c.collectionCode,
                c.institutionCode,
                c.collectionName,
                t.sciname AS taxa_sciname
            FROM media m
            INNER JOIN temp_matching_occids tmp ON m.occid = tmp.occid
            INNER JOIN omoccurrences o ON m.occid = o.occid
            LEFT JOIN omcollections c ON o.collid = c.collid
            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search collections using omcollections table (fallback for old indexes)
     * This is the SLOWER path but works with indexes that don't have CollectionLookup
     */
    private function searchCollectionViaOmcollections(PDO $db, string $fieldName, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Step 1: Find matching collections in omcollections table
        $collectionFields = $this->resolveFieldAlias($fieldName);

        // Build WHERE clause for collections
        $whereParts = [];
        $params = [];
        foreach ($collectionFields as $field) {
            // Remove table prefix for column name
            $column = substr($field, 2); // Remove 'c.' prefix
            $whereParts[] = "$column LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }
        $whereClause = implode(' OR ', $whereParts);

        // Get matching collid values
        $sql = "SELECT collid FROM omcollections WHERE $whereClause";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $collids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($collids)) {
            return [];
        }

        // Step 2: Create temporary table with matching occids
        $db->exec("DROP TABLE IF EXISTS temp_matching_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_occids (occid INTEGER PRIMARY KEY)");

        // Insert occids with matching collid
        $placeholders = implode(',', array_fill(0, count($collids), '?'));
        $sql = "INSERT INTO temp_matching_occids (occid)
                SELECT occid FROM omoccurrences WHERE collid IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($collids);

        // Step 3: Apply additional filters if provided
        if (!empty($additionalFilters)) {
            $filterClauses = [];
            $filterParams = [];

            foreach ($additionalFilters as $filter) {
                $parsed = $this->parseFieldValueQuery($filter);
                if ($parsed) {
                    $filterClauses[] = $this->buildWhereClause($parsed);
                    $filterParams = array_merge($filterParams, $this->getWhereParams($parsed));
                }
            }

            if (!empty($filterClauses)) {
                // Filter temp_matching_occids by removing occids that don't match filters
                $whereClause = implode(' AND ', $filterClauses);
                $sql = "DELETE FROM temp_matching_occids
                        WHERE occid NOT IN (
                            SELECT o.occid
                            FROM omoccurrences o
                            LEFT JOIN omcollections c ON o.collid = c.collid
                            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                            WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
                            AND {$whereClause}
                        )";

                $stmt = $db->prepare($sql);
                $stmt->execute($filterParams);

                // Check if any occids remain
                $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                if ($occidCount == 0) {
                    return [];
                }
            }
        }

        // Step 4: Get media records using temporary occid table
        $sql = "
            SELECT
                m.mediaID,
                m.url,
                m.originalUrl,
                m.thumbnailUrl,
                o.occid,
                o.family,
                o.genus,
                o.sciname,
                o.scientificname,
                o.catalogNumber,
                o.country,
                o.stateProvince,
                o.county,
                o.locality,
                c.collid,
                c.collectionCode,
                c.institutionCode,
                c.collectionName,
                t.sciname AS taxa_sciname
            FROM media m
            INNER JOIN temp_matching_occids tmp ON m.occid = tmp.occid
            INNER JOIN omoccurrences o ON m.occid = o.occid
            LEFT JOIN omcollections c ON o.collid = c.collid
            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Optimized occid search using exact match (fastest possible search)
     *
     * Strategy:
     * 1. Parse occid value(s) - support single occid or comma-separated list
     * 2. Use indexed lookup on media.occid (indexed field - very fast!)
     * 3. Apply additional filters if needed
     * 4. Join to get metadata
     *
     * Performance: Uses indexed lookup on media.occid, should be near-instant
     *
     * @param PDO $db Database connection
     * @param string $searchTerm Occid value(s) - single or comma-separated
     * @param array $additionalFilters Additional filters to apply
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchByOccidExact(PDO $db, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Parse occid value(s) - support single occid or comma-separated list
        $occids = array_map('trim', explode(',', $searchTerm));
        $occids = array_filter(array_map('intval', $occids)); // Convert to integers and remove zeros

        if (empty($occids)) {
            return [];
        }

        // Create temporary table with matching occids (uses indexed lookup on media.occid)
        $db->exec("DROP TABLE IF EXISTS temp_matching_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_occids (occid INTEGER PRIMARY KEY)");

        // Insert matching occids using indexed lookup
        $placeholders = implode(',', array_fill(0, count($occids), '?'));
        $sql = "INSERT INTO temp_matching_occids (occid)
                SELECT DISTINCT m.occid
                FROM media m
                WHERE m.occid IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($occids);

        // Check if any matches found
        $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
        if ($occidCount == 0) {
            return [];
        }

        // Apply additional filters if provided
        if (!empty($additionalFilters)) {
            // Load filter SQL template
            $parser = new SqlTemplateParser();

            foreach ($additionalFilters as $filter) {
                $parsed = $this->parseFieldValueQuery($filter);
                if (!$parsed) {
                    continue;
                }

                // Build WHERE clause
                $filterClause = $this->buildWhereClause($parsed);
                $filterParams = $this->getWhereParams($parsed);

                // Use SQL template for filter query
                $filterSql = $parser->parse('images/flat/search_occid_filter.sql', [
                    'FILTER_CLAUSE' => $filterClause
                ]);

                $stmt = $db->prepare($filterSql);
                $stmt->execute($filterParams);

                // Check if any occids remain
                $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                if ($occidCount == 0) {
                    return [];
                }
            }
        }

        // Load main query SQL template
        $parser = new SqlTemplateParser();
        $mainSql = $parser->parse('images/flat/search_occid_exact.sql', [
            'LIMIT' => $limit,
            'OFFSET' => $offset
        ]);

        $stmt = $db->prepare($mainSql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Optimized occurrence search (search occurrences table first, then join to media)
     *
     * Strategy:
     * 1. Search omoccurrences table for matching occid values (indexed field)
     * 2. Apply additional filters to occurrences
     * 3. Join to media
     *
     * @param PDO $db Database connection
     * @param string $fieldName Field name (country, state, county, catalog, etc.)
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional filters from queryAnd
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchOccurrenceOptimized(PDO $db, string $fieldName, string $searchTerm, array $additionalFilters, int $limit, int $offset): array
    {
        // Step 1: Find matching occurrences
        $occurrenceFields = $this->resolveFieldAlias($fieldName);

        // Build WHERE clause for occurrences
        $whereParts = [];
        $params = [];
        foreach ($occurrenceFields as $field) {
            $whereParts[] = "$field LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }
        $whereClause = implode(' OR ', $whereParts);

        // Create temporary table with matching occids
        $db->exec("DROP TABLE IF EXISTS temp_matching_occids");
        $db->exec("CREATE TEMPORARY TABLE temp_matching_occids (occid INTEGER PRIMARY KEY)");

        // Insert matching occids
        $sql = "INSERT INTO temp_matching_occids (occid)
                SELECT o.occid FROM omoccurrences o WHERE $whereClause";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Check if any matches found
        $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
        if ($occidCount == 0) {
            return [];
        }

        // Step 2: Apply additional filters if provided
        if (!empty($additionalFilters)) {
            $filterClauses = [];
            $filterParams = [];

            foreach ($additionalFilters as $filter) {
                $parsed = $this->parseFieldValueQuery($filter);
                if ($parsed) {
                    $filterClauses[] = $this->buildWhereClause($parsed);
                    $filterParams = array_merge($filterParams, $this->getWhereParams($parsed));
                }
            }

            if (!empty($filterClauses)) {
                // Filter temp_matching_occids by removing occids that don't match filters
                $whereClause = implode(' AND ', $filterClauses);
                $sql = "DELETE FROM temp_matching_occids
                        WHERE occid NOT IN (
                            SELECT o.occid
                            FROM omoccurrences o
                            LEFT JOIN omcollections c ON o.collid = c.collid
                            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
                            WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
                            AND {$whereClause}
                        )";

                $stmt = $db->prepare($sql);
                $stmt->execute($filterParams);

                // Check if any occids remain
                $occidCount = $db->query("SELECT COUNT(*) FROM temp_matching_occids")->fetchColumn();
                if ($occidCount == 0) {
                    return [];
                }
            }
        }

        // Step 3: Get media records using temporary occid table
        $sql = "
            SELECT
                m.mediaID,
                m.url,
                m.originalUrl,
                m.thumbnailUrl,
                o.occid,
                o.family,
                o.genus,
                o.sciname,
                o.scientificname,
                o.catalogNumber,
                o.country,
                o.stateProvince,
                o.county,
                o.locality,
                c.collid,
                c.collectionCode,
                c.institutionCode,
                c.collectionName,
                t.sciname AS taxa_sciname
            FROM media m
            INNER JOIN temp_matching_occids tmp ON m.occid = tmp.occid
            INNER JOIN omoccurrences o ON m.occid = o.occid
            LEFT JOIN omcollections c ON o.collid = c.collid
            LEFT JOIN taxa t ON o.tidinterpreted = t.tid
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load SQL template from templates/sql/images/flat/
     *
     * @param string $templateName Template filename
     * @return string SQL template content
     */
    private function loadSqlTemplate(string $templateName): string
    {
        $templatePath = dirname($this->configPath) . '/' . $templateName;

        if (!file_exists($templatePath)) {
            throw new \Exception("SQL template not found: {$templatePath}");
        }

        return file_get_contents($templatePath);
    }

    /**
     * Search using standard JOIN query (for simple or complex AND/OR searches)
     *
     * Uses SQL template from templates/sql/images/flat/search.sql
     *
     * @param PDO $db Database connection
     * @param array $allQueries All AND queries
     * @param array $queryOr OR queries
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of matching records
     */
    private function searchWithJoin(PDO $db, array $allQueries, array $queryOr, int $limit, int $offset): array
    {
        $whereClauses = [];
        $params = [];

        // Process AND queries
        foreach ($allQueries as $q) {
            $parsed = $this->parseFieldValueQuery($q);
            if ($parsed) {
                $whereClauses[] = $this->buildWhereClause($parsed);
                $params = array_merge($params, $this->getWhereParams($parsed));
            }
        }

        // Process OR queries (combine with OR logic)
        if (!empty($queryOr)) {
            $orClauses = [];
            foreach ($queryOr as $q) {
                $parsed = $this->parseFieldValueQuery($q);
                if ($parsed) {
                    $orClauses[] = $this->buildWhereClause($parsed);
                    $params = array_merge($params, $this->getWhereParams($parsed));
                }
            }
            if (!empty($orClauses)) {
                $whereClauses[] = '(' . implode(' OR ', $orClauses) . ')';
            }
        }

        // Build WHERE clause string
        $whereClausesStr = !empty($whereClauses)
            ? implode("\nAND ", $whereClauses)
            : '1=1';  // Always true if no filters

        // Load SQL from template
        $parser = new SqlTemplateParser();
        $sql = $parser->parse('images/flat/search.sql', [
            'where_clauses' => $whereClausesStr,
            'order_by' => '',  // No ORDER BY for now
            'limit' => "LIMIT ?",
            'offset' => "OFFSET ?"
        ]);

        // Add limit and offset to params
        $params[] = $limit;
        $params[] = $offset;

        // Execute query
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Parse a field:value query into components
     *
     * Supports:
     * - Keyword search: "backyard" → searches all indexed fields
     * - Field search: "family:Russulaceae" → searches specific field
     * - Exclusive include: "!field:value" or "!:field:value" → LIKE (assert/require this value)
     * - Exclude: "^field:value" or "^:field:value" → NOT LIKE (exclude matches)
     *
     * @param string $query Query string
     * @return array|null Parsed query or null if invalid
     */
    private function parseFieldValueQuery(string $query): ?array
    {
        // Check for exclusive include prefix (! or !:)
        // This is just a semantic marker - it still uses LIKE, but indicates "must have this"
        $exclusive = false;
        if (strpos($query, '!:') === 0) {
            // Format: !:field:value (exclusive include - assert this value)
            $exclusive = true;
            $query = substr($query, 2); // Remove !:
        } elseif (strpos($query, '!') === 0) {
            // Format: !field:value (exclusive include - assert this value)
            $exclusive = true;
            $query = substr($query, 1); // Remove !
        }

        // Check for exclusion prefix (^ or ^:)
        $exclude = false;
        if (strpos($query, '^:') === 0) {
            // Format: ^:field:value (exclude - NOT LIKE)
            $exclude = true;
            $query = substr($query, 2); // Remove ^:
        } elseif (strpos($query, '^') === 0) {
            // Format: ^field:value (exclude - NOT LIKE)
            $exclude = true;
            $query = substr($query, 1); // Remove ^
        }

        // Check for field:value syntax
        if (strpos($query, ':') === false) {
            // Keyword search (search all searchable text fields from config)
            // searchableFields now already includes table prefixes (e.g., 'o.family', 'c.collectionCode')
            return [
                'fields' => $this->searchableFields,
                'search' => '%' . $query . '%',
                'exclude' => $exclude,
                'exclusive' => $exclusive
            ];
        }

        list($fieldName, $searchTerm) = explode(':', $query, 2);
        $fieldName = trim($fieldName);
        $searchTerm = trim($searchTerm);

        if (empty($fieldName) || empty($searchTerm)) {
            return null;
        }

        // Resolve field alias to actual field name(s)
        $fields = $this->resolveFieldAlias($fieldName);

        // Add wildcards for LIKE query
        $search = '%' . $searchTerm . '%';

        return [
            'fields' => $fields,
            'search' => $search,
            'exclude' => $exclude,
            'exclusive' => $exclusive
        ];
    }

    /**
     * Resolve field alias to actual field name(s) with table prefixes
     *
     * Handles:
     * - Configurable keyword search (default: 'q') → all searchable fields
     * - Multi-field aliases (taxon, collection, location)
     * - Single-field aliases from config
     * - Direct field names
     *
     * @param string $fieldName Field name or alias
     * @return array Array of actual field names with table prefixes
     */
    private function resolveFieldAlias(string $fieldName): array
    {
        $lowerField = strtolower($fieldName);

        // Check if this is the configurable keyword search
        // Default: 'q' → searches all searchable fields
        if ($lowerField === strtolower($this->searchKeyword)) {
            return $this->searchableFields;
        }

        // Multi-field aliases that search across multiple fields
        // taxon: searches 5 fields across 2 tables (omoccurrences and taxa)
        $multiFieldAliases = [
            'taxon' => ['o.family', 'o.genus', 'o.sciname', 'o.scientificname', 't.sciname'],
            'collection' => ['c.collectionName', 'c.collectionCode', 'c.institutionCode'],
            'location' => ['o.country', 'o.stateProvince', 'o.county', 'o.locality']
        ];

        // Check if this is a multi-field alias
        if (isset($multiFieldAliases[$lowerField])) {
            return $multiFieldAliases[$lowerField];
        }

        // Check if this is a single-field alias from config
        if (isset($this->config['aliases'])) {
            $aliases = $this->config['aliases'];
            // Check both lowercase and original case
            if (isset($aliases[$lowerField])) {
                return [$this->addTablePrefix($aliases[$lowerField])];
            }
            if (isset($aliases[$fieldName])) {
                return [$this->addTablePrefix($aliases[$fieldName])];
            }
        }

        // Not an alias, add table prefix and return
        return [$this->addTablePrefix($fieldName)];
    }

    /**
     * Add table prefix to field name
     *
     * @param string $fieldName Field name
     * @return string Field name with table prefix
     */
    private function addTablePrefix(string $fieldName): string
    {
        // If already has a prefix, return as-is
        if (strpos($fieldName, '.') !== false) {
            return $fieldName;
        }

        // Map field names to table prefixes (lowercase keys for case-insensitive matching)
        // Maps to: [table_prefix, actual_column_name]
        $fieldToTable = [
            // Media fields
            'mediaid' => ['m', 'mediaID'],
            'url' => ['m', 'url'],
            'originalurl' => ['m', 'originalUrl'],
            'thumbnailurl' => ['m', 'thumbnailUrl'],

            // Omoccurrences fields
            'occid' => ['o', 'occid'],
            'family' => ['o', 'family'],
            'genus' => ['o', 'genus'],
            'sciname' => ['o', 'sciname'],
            'scientificname' => ['o', 'scientificname'],
            'catalognumber' => ['o', 'catalogNumber'],
            'catalog' => ['o', 'catalogNumber'],
            'country' => ['o', 'country'],
            'stateprovince' => ['o', 'stateProvince'],
            'state' => ['o', 'stateProvince'],
            'county' => ['o', 'county'],
            'locality' => ['o', 'locality'],

            // Omcollections fields
            'collid' => ['c', 'collid'],
            'collectioncode' => ['c', 'collectionCode'],
            'institutioncode' => ['c', 'institutionCode'],
            'collectionname' => ['c', 'collectionName'],
            'collection' => ['c', 'collectionName'], // Default to name for multi-field alias
        ];

        $lowerField = strtolower($fieldName);
        $mapping = $fieldToTable[$lowerField] ?? ['o', $fieldName]; // Default to omoccurrences with original field name

        return $mapping[0] . '.' . $mapping[1];
    }

    /**
     * Build WHERE clause for parsed query
     *
     * Supports both inclusion (LIKE) and exclusion (NOT LIKE) filters.
     *
     * @param array $parsed Parsed query with 'fields', 'search', and 'exclude' keys
     * @return string WHERE clause SQL
     */
    private function buildWhereClause(array $parsed): string
    {
        $fields = $parsed['fields'];
        $exclude = $parsed['exclude'] ?? false;
        $operator = $exclude ? 'NOT LIKE' : 'LIKE';

        if (count($fields) === 1) {
            return $fields[0] . " $operator ? COLLATE NOCASE";
        } else {
            $conditions = array_map(function($field) use ($operator) {
                return "$field $operator ? COLLATE NOCASE";
            }, $fields);

            // For exclusions with multiple fields, use AND (exclude from ALL fields)
            // For inclusions with multiple fields, use OR (match ANY field)
            $logic = $exclude ? 'AND' : 'OR';
            return '(' . implode(" $logic ", $conditions) . ')';
        }
    }

    /**
     * Get parameters for WHERE clause
     *
     * @param array $parsed Parsed query
     * @return array Parameters array
     */
    private function getWhereParams(array $parsed): array
    {
        $search = $parsed['search'];
        $fields = $parsed['fields'];

        // Return search term once for each field
        return array_fill(0, count($fields), $search);
    }

    /**
     * Convert database results to images format
     *
     * @param array $results Database results
     * @return array Images array
     */
    private function convertResultsToImages(array $results): array
    {
        $images = [];
        foreach ($results as $row) {
            $images[] = [
                'mediaID' => $row['mediaID'] ?? '',
                'occid' => $row['occid'] ?? '',
                'collid' => $row['collid'] ?? '',
                'family' => $row['family'] ?? '',
                'genus' => $row['genus'] ?? '',
                'sciname' => $row['sciname'] ?? '',
                'scientificname' => $row['scientificname'] ?? '',
                'taxa_sciname' => $row['taxa_sciname'] ?? '',
                'country' => $row['country'] ?? '',
                'stateProvince' => $row['stateProvince'] ?? '',
                'county' => $row['county'] ?? '',
                'locality' => $row['locality'] ?? '',
                'catalogNumber' => $row['catalogNumber'] ?? '',
                'collectionCode' => $row['collectionCode'] ?? '',
                'institutionCode' => $row['institutionCode'] ?? '',
                'collectionName' => $row['collectionName'] ?? '',
                'url' => $row['url'] ?? '',
                'originalUrl' => $row['originalUrl'] ?? '',
                'thumbnailUrl' => $row['thumbnailUrl'] ?? ''
            ];
        }
        return $images;
    }

    /**
     * Format search results for CLI output
     *
     * @param array $images Images array
     * @param string $query Search query
     * @return array Response array
     */
    private function formatCliSearchResults(array $images, string $query): array
    {
        if (empty($images)) {
            return [
                'type' => 'cli',
                'content' => "No results found for query: $query\n"
            ];
        }

        $output = sprintf("Found %d results for query: %s\n\n", count($images), $query);
        $output .= str_pad('MediaID', 12) . str_pad('OccID', 12) . str_pad('Family', 20) .
                   str_pad('Genus', 20) . str_pad('Collection', 15) . "\n";
        $output .= str_repeat('-', 79) . "\n";

        foreach ($images as $image) {
            $output .= str_pad($image['mediaID'], 12) .
                      str_pad($image['occid'], 12) .
                      str_pad(substr($image['family'], 0, 19), 20) .
                      str_pad(substr($image['genus'], 0, 19), 20) .
                      str_pad(substr($image['collectionCode'], 0, 14), 15) . "\n";
        }

        return [
            'type' => 'cli',
            'content' => $output
        ];
    }

    /**
     * Render images with load more button for HTMX
     * Uses template engine for consistent formatting with EAV/Hybrid models
     *
     * @param array $images Images array
     * @param int $limit Result limit
     * @param int $offset Current offset
     * @param string $loadMoreUrl URL for load more
     * @param string $query Search query
     * @return array Response array
     */
    private function renderImagesWithLoadMore(array $images, int $limit, int $offset, string $loadMoreUrl, string $query): array
    {
        // Check if no results found
        if (empty($images) && $offset === 0) {
            $noResultsHtml = $this->renderTemplate('no_results', TemplateFormat::HTML, [
                'query' => htmlspecialchars($query ?? 'your search'),
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]);

            return [
                'type' => 'htmx',
                'content' => $this->renderTemplate('image_grid', TemplateFormat::HTML, [
                    'images_html' => $noResultsHtml,
                    'load_more_html' => '' // No load more button
                ])
            ];
        }

        // Generate images HTML using template (same as EAV/Hybrid models)
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
     * Get application URL prefix
     *
     * @return string URL prefix
     */
    private function getAppUrlPrefix(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/?/';
        $parser = new UriParser($requestUri);
        $fullUrl = $parser->getAppUrlPrefix();

        if (str_contains($fullUrl, '://')) {
            $parts = parse_url($fullUrl);
            return ($parts['path'] ?? '') . '?/';
        }

        return $fullUrl;
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
        // If so, switch to value autocomplete
        if (preg_match('/^([a-z]+):(.*)$/i', $query, $matches)) {
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

        // Build suggestions array
        $suggestions = [];
        $addedFields = [];

        // Multi-field aliases with descriptions
        $multiFieldAliases = [
            'taxon' => ['fields' => ['family', 'genus', 'sciname', 'scientificname', 'taxa.sciname'], 'description' => 'Search across all taxonomy fields'],
            'collection' => ['fields' => ['collectionName', 'collectionCode', 'institutionCode'], 'description' => 'Search across all collection fields'],
            'location' => ['fields' => ['country', 'stateProvince', 'county', 'locality'], 'description' => 'Search across all location fields']
        ];

        // Add multi-field aliases
        foreach ($multiFieldAliases as $alias => $info) {
            if (empty($query) || strpos($alias, $query) !== false) {
                $suggestions[] = [
                    'field' => $alias,
                    'maps_to' => implode(', ', $info['fields']),
                    'description' => $info['description'],
                    'is_alias' => true
                ];
                $addedFields[strtolower($alias)] = true;
            }
        }

        // Add single-field aliases from config
        if (isset($this->config['aliases'])) {
            foreach ($this->config['aliases'] as $alias => $actualField) {
                $aliasLower = strtolower($alias);

                // Skip if already added
                if (isset($addedFields[$aliasLower])) {
                    continue;
                }

                // Match against alias or actual field name
                if (empty($query) ||
                    strpos($aliasLower, $query) !== false ||
                    strpos(strtolower($actualField), $query) !== false) {

                    $suggestions[] = [
                        'field' => $alias,
                        'maps_to' => $actualField,
                        'description' => "Alias for $actualField",
                        'is_alias' => true
                    ];
                    $addedFields[$aliasLower] = true;
                }
            }
        }

        // Add searchable fields
        foreach ($this->searchableFields as $fieldWithPrefix) {
            // Strip table prefix for display (e.g., 'o.family' -> 'family')
            $field = $fieldWithPrefix;
            if (strpos($fieldWithPrefix, '.') !== false) {
                list($table, $field) = explode('.', $fieldWithPrefix, 2);
            }

            $fieldLower = strtolower($field);

            // Skip if already added as alias
            if (isset($addedFields[$fieldLower])) {
                continue;
            }

            // Match against field name
            if (empty($query) || strpos($fieldLower, $query) !== false) {
                $suggestions[] = [
                    'field' => $field,
                    'maps_to' => $field,
                    'description' => "Search by $field",
                    'is_alias' => false
                ];
                $addedFields[$fieldLower] = true;
            }
        }

        if (empty($suggestions)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">No matching fields found</div>'
            ];
        }

        // Generate HTML with onclick handlers (matching Hybrid implementation)
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
     * Get autocomplete value suggestions for a field
     *
     * @param array $params Parameters (field, q/term, format)
     * @return array Value suggestions (HTMX format for web, array for CLI)
     */
    public function autocompleteValues(array $params): array
    {
        $field = $params['field'] ?? '';
        $query = $params['q'] ?? $params['term'] ?? $params['query'] ?? '';
        $limit = (int)($params['limit'] ?? 20);
        $format = $params['format'] ?? 'htmx';

        if (empty($field)) {
            return $format === 'htmx'
                ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Field parameter required</div>']
                : [];
        }

        if (strlen($query) < 2) {
            return $format === 'htmx'
                ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Type at least 2 characters...</div>']
                : [];
        }

        if (!$this->isAvailable()) {
            return $format === 'htmx'
                ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Search index not available</div>']
                : [];
        }

        try {
            $db = new PDO(sprintf('sqlite:%s', $this->flatIndexDbPath), null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Map field/alias to autocomplete table (read from config)
            // Get table names and column names from config
            $taxonTable = $this->config['autocomplete.taxon']['table_name'] ?? 'TaxonTokens';
            $taxonValueCol = $this->config['autocomplete.taxon']['value_column'] ?? 'taxon_value';
            $taxonCountCol = $this->config['autocomplete.taxon']['count_column'] ?? 'count';

            $locationTable = $this->config['autocomplete.location']['table_name'] ?? 'LocationTokens';
            $locationValueCol = $this->config['autocomplete.location']['value_column'] ?? 'location_value';
            $locationCountCol = $this->config['autocomplete.location']['count_column'] ?? 'count';

            $collectionTable = $this->config['autocomplete.collection']['table_name'] ?? 'CollectionTokens';
            $collectionValueCol = $this->config['autocomplete.collection']['value_column'] ?? 'collection_value';
            $collectionCountCol = $this->config['autocomplete.collection']['count_column'] ?? 'count';

            $catalogTable = $this->config['autocomplete.catalog']['table_name'] ?? 'CollectionTokens';
            $catalogValueCol = $this->config['autocomplete.catalog']['value_column'] ?? 'collection_value';
            $catalogCountCol = $this->config['autocomplete.catalog']['count_column'] ?? 'count';

            // Map field/alias to autocomplete table (all lowercase keys for case-insensitive matching)
            $autocompleteTableMap = [
                'taxon' => $taxonTable,
                'family' => $taxonTable,
                'genus' => $taxonTable,
                'sciname' => $taxonTable,
                'scientificname' => $taxonTable,
                'country' => $locationTable,
                'stateprovince' => $locationTable,
                'state' => $locationTable,
                'county' => $locationTable,
                'location' => $locationTable,
                'collection' => $collectionTable,
                'collectioncode' => $collectionTable,
                'institutioncode' => $collectionTable,
                'collectionname' => $collectionTable,
                'catalognumber' => $catalogTable,
                'catalog' => $catalogTable,
            ];

            $lowerField = strtolower($field);
            $autocompleteTable = $autocompleteTableMap[$lowerField] ?? null;

            if (!$autocompleteTable) {
                return $format === 'htmx'
                    ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Autocomplete not available for this field</div>']
                    : [];
            }

            // Check if autocomplete table exists
            $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$autocompleteTable'")->fetchColumn();
            if (!$tableExists) {
                return $format === 'htmx'
                    ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Autocomplete table not built</div>']
                    : [];
            }

            // Query autocomplete table
            // Get column names based on which table we're using
            if ($autocompleteTable === $taxonTable) {
                $valueColumn = $taxonValueCol;
                $countColumn = $taxonCountCol;
            } elseif ($autocompleteTable === $locationTable) {
                $valueColumn = $locationValueCol;
                $countColumn = $locationCountCol;
            } elseif ($autocompleteTable === $collectionTable) {
                $valueColumn = $collectionValueCol;
                $countColumn = $collectionCountCol;
            } elseif ($autocompleteTable === $catalogTable) {
                $valueColumn = $catalogValueCol;
                $countColumn = $catalogCountCol;
            } else {
                // Fallback to generic column names
                $valueColumn = 'value';
                $countColumn = 'count';
            }

            $sql = "SELECT $valueColumn as value, $countColumn as count FROM $autocompleteTable\n" .
                   "WHERE $valueColumn LIKE :query COLLATE NOCASE\n" .
                   "ORDER BY $countColumn DESC, $valueColumn ASC\n" .
                   "LIMIT :limit";

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':query', $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $values = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($values)) {
                return $format === 'htmx'
                    ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">No suggestions found</div>']
                    : [];
            }

            // Return HTMX format for web
            if ($format === 'htmx') {
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
            }

            // Return array format for CLI (just values, not counts)
            return array_column($values, 'value');

        } catch (Exception $e) {
            return $format === 'htmx'
                ? ['type' => 'htmx', 'content' => '<div class="autocomplete-message">Error: ' . htmlspecialchars($e->getMessage()) . '</div>']
                : [];
        }
    }

    /**
     * Check if flat index is available
     *
     * @return bool True if available
     */
    public function isAvailable(): bool
    {
        return !empty($this->flatIndexDbPath) && file_exists($this->flatIndexDbPath);
    }

    /**
     * Get search mode identifier
     *
     * @return string Search mode
     */
    public function getSearchMode(): string
    {
        return 'flat';
    }

    /**
     * Get cache/index information and statistics
     *
     * @param array $params Optional parameters
     * @return array Information array
     */
    public function getInfo(array $params = []): array
    {
        try {
            if (!$this->isAvailable()) {
                return [
                    'type' => 'error',
                    'message' => 'Flat index database not found',
                    'stats' => []
                ];
            }

            $db = new PDO(sprintf('sqlite:%s', $this->flatIndexDbPath), null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);

            $stats = [];
            $stats['database_path'] = $this->flatIndexDbPath;
            $stats['database_size'] = filesize($this->flatIndexDbPath);
            $stats['database_size_mb'] = round(filesize($this->flatIndexDbPath) / 1024 / 1024, 2);
            $stats['total_records'] = $db->query("SELECT COUNT(*) FROM SearchIndex")->fetchColumn();

            // Get field statistics
            $fields = ['family', 'genus', 'sciname', 'country', 'stateProvince', 'county',
                       'collectionCode', 'institutionCode'];

            foreach ($fields as $field) {
                $count = $db->query("SELECT COUNT(DISTINCT $field) FROM SearchIndex WHERE $field IS NOT NULL AND $field != ''")->fetchColumn();
                $stats['distinct_' . $field] = $count;
            }

            return [
                'type' => 'success',
                'message' => 'Flat index information retrieved successfully',
                'stats' => $stats
            ];

        } catch (Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to get flat index info: ' . $e->getMessage(),
                'stats' => []
            ];
        }
    }

    /**
     * Get table name from configuration
     *
     * Returns the actual table name for a given key.
     * Reads from [tables] section in index_config.ini.
     *
     * @param string $key Table key (e.g., 'media', 'omoccurrences')
     * @return string Table name
     */
    private function getTableName(string $key): string
    {
        // Check if tables are defined in config
        if (isset($this->config['tables']['tables']) && is_array($this->config['tables']['tables'])) {
            // Tables are listed in the config
            if (in_array($key, $this->config['tables']['tables'])) {
                return $key;
            }
        }

        // Fallback to default table names
        $tables = [
            'media' => 'media',
            'omoccurrences' => 'omoccurrences',
            'omcollections' => 'omcollections',
            'taxa' => 'taxa'
        ];

        return $tables[$key] ?? $key;
    }

    /**
     * Get table alias for SQL queries
     *
     * Returns the short alias used in SQL JOINs.
     * Reads from [table_aliases] section in index_config.ini.
     *
     * @param string $tableName Full table name
     * @return string Table alias (e.g., 'm' for media, 'o' for omoccurrences)
     */
    private function getTableAlias(string $tableName): string
    {
        // Check if table aliases are defined in config
        if (isset($this->config['table_aliases'][$tableName])) {
            return $this->config['table_aliases'][$tableName];
        }

        // Fallback to default aliases
        $aliases = [
            'media' => 'm',
            'omoccurrences' => 'o',
            'omcollections' => 'c',
            'taxa' => 't'
        ];

        return $aliases[$tableName] ?? substr($tableName, 0, 1);
    }

    /**
     * Get column name from configuration
     *
     * Returns the actual column name for a given table and key.
     * This provides a central point for future configuration-driven column naming.
     *
     * @param string $table Table name
     * @param string $key Column key
     * @return string Column name
     */
    private function getColumnName(string $table, string $key): string
    {
        // Column names currently match their keys directly
        // Future: could support column aliases in config
        return $key;
    }

    /**
     * Get table relationship JOIN condition
     *
     * Returns the JOIN condition for relating two tables.
     * Reads from [table_relationships] section in index_config.ini.
     *
     * @param string $relationshipName Relationship name (e.g., 'media_to_occurrences')
     * @return string|null JOIN condition or null if not found
     */
    private function getTableRelationship(string $relationshipName): ?string
    {
        // Check if relationship is defined in config
        if (isset($this->config['table_relationships'][$relationshipName])) {
            return $this->config['table_relationships'][$relationshipName];
        }

        // Fallback to default relationships
        $relationships = [
            'media_to_occurrences' => 'media.occid = omoccurrences.occid',
            'occurrences_to_collections' => 'omoccurrences.collid = omcollections.collid',
            'occurrences_to_taxa' => 'omoccurrences.tidinterpreted = taxa.tid'
        ];

        return $relationships[$relationshipName] ?? null;
    }

    /**
     * Check if database has optimized collection schema
     *
     * The optimized schema uses CollectionLookup + OccurrenceCollectionIndex with collid.
     * The legacy schema uses CollectionTokens + OccurrenceCollectionIndex with token_id.
     *
     * @param PDO $db Database connection
     * @return bool True if optimized schema is present
     */
    private function hasOptimizedCollectionSchema(PDO $db): bool
    {
        try {
            // Check if CollectionLookup table exists
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='CollectionLookup'");
            $hasCollectionLookup = $result->fetchColumn() !== false;

            if (!$hasCollectionLookup) {
                return false;
            }

            // Check if OccurrenceCollectionIndex has collid column (optimized) or token_id column (legacy)
            $result = $db->query("PRAGMA table_info(OccurrenceCollectionIndex)");
            $columns = $result->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($columns, 'name');

            // Optimized schema has 'collid' column, legacy has 'token_id' column
            return in_array('collid', $columnNames);

        } catch (Exception $e) {
            // If we can't determine, assume legacy schema for backward compatibility
            return false;
        }
    }

    /**
     * Render template using TemplateEngine
     *
     * @param string $name Template name
     * @param TemplateFormat $format Template format
     * @param array $variables Template variables
     * @return string Rendered template
     */
    private function renderTemplate(string $name, $format, array $variables = []): string
    {
        $templatesPath = \Symbiota\Helpers\Core\ApplicationPaths::templatesDirectory();
        $templateEngine = new \Symbiota\Helpers\Core\TemplateEngine($templatesPath);

        // Prepend 'images/' to template name for model-specific templates
        $templatePath = sprintf('images/%s', $name);

        return $templateEngine->template($templatePath, $format, $variables);
    }
}

