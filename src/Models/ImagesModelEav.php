<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\EavIndexing;
use Symbiota\Helpers\Core\TextNormalizer;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\SqlTemplateParser;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Interfaces\ImagesSearchInterface;
use PDO;
use PDOException;
use Exception;

/**
 * Images Model EAV - EAV Index Management and Search
 *
 * Manages EAV (Entity-Attribute-Value) indexing for the images module.
 * Provides methods to build, query, and maintain the EAV index.
 * Implements ImagesSearchInterface as a plugin for ImagesModel delegation.
 *
 * @version 2.0.0
 * @author Symbiota Portal Helpers
 */
class ImagesModelEav implements ImagesSearchInterface
{
    private array $config;
    private string $configPath;
    private string $workDbPath;
    private string $cacheDbPath;
    private string $sourceDbPath;
    private ?PDO $workDb = null;
    private ?PDO $cacheDb = null;
    private ?PDO $sourceDb = null;

    // Batch size configuration
    private int $entityBatchSize = 1000;        // Batch size for entity inserts
    private int $valuesTextFlushSize = 100000;  // Flush ValuesText cache every N unique values

    // Statistics tracking
    private array $stats = [
        'entities_processed' => 0,
        'entity_batches' => 0,
        'valuestext_flushes' => 0,
        'eav_inserts' => 0,
        'total_tokens' => 0,
    ];

    // Attribute caches for combined token insertion
    private array $wholeTextAttrsCache = [];
    private array $splitTextAttrsCache = [];

    public function __construct(array $params = [])
    {
        $this->configPath = $params['config_path'] ?? '';
        $this->workDbPath = $params['work_db_path'] ?? '';
        $this->cacheDbPath = $params['cache_db_path'] ?? '';
        $this->sourceDbPath = $params['source_db_path'] ?? '';

        // Configurable batch sizes for testing and performance tuning
        // CLI uses dashes: --entity-batch-size, --valuestext-flush-size
        $this->entityBatchSize = $params['entity-batch-size'] ?? $params['entity_batch_size'] ?? 1000;
        $this->valuesTextFlushSize = $params['valuestext-flush-size'] ?? $params['valuestext_flush_size'] ?? 100000;

        if (empty($this->configPath)) {
            throw new Exception('Configuration path is required');
        }

        // Load configuration
        $this->config = EavIndexing::parseConfig($this->configPath);
    }

    /**
     * Get configuration
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get build statistics
     *
     * @return array Statistics array with batch counts and totals
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    private function resetStats(): void
    {
        $this->stats = [
            'entities_processed' => 0,
            'entity_batches' => 0,
            'valuestext_flushes' => 0,
            'eav_inserts' => 0,
            'total_tokens' => 0,
        ];
    }

    /**
     * Create work and cache databases
     *
     * @return void
     * @throws Exception If database creation fails
     */
    public function createDatabases(): void
    {
        try {
            // Create work database
            $this->workDb = new PDO('sqlite:' . $this->workDbPath);
            $this->workDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create cache database
            $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
            $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create cache database schema
            $this->createCacheSchema();

            // Store config in Config table
            $this->storeConfig();
        } catch (Exception $e) {
            throw new Exception('Failed to create databases: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Create cache database schema
     *
     * @return void
     */
    private function createCacheSchema(): void
    {
        // Use SqlTemplateParser to load SQL templates from templates/sql directory
        $parser = new SqlTemplateParser();

        try {
            // Create Entities table (base schema)
            $entitiesSql = $parser->parse('images/eav/create_cache_entities.sql');
            $this->cacheDb->exec($entitiesSql);

            // Add display field columns to Entities
            $displayFields = EavIndexing::getDisplayFields($this->config);
            foreach ($displayFields as $field) {
                $sqlType = EavIndexing::getSqlType($field['type']);
                $this->cacheDb->exec(sprintf(
                    'ALTER TABLE Entities ADD COLUMN %s %s',
                    $field['name'],
                    $sqlType
                ));
            }

            // Create Attributes table
            $attributesSql = $parser->parse('images/eav/create_cache_attributes.sql');
            $this->cacheDb->exec($attributesSql);

            // Create ValuesText table
            $valuesTextSql = $parser->parse('images/eav/create_cache_values_text.sql');
            $this->cacheDb->exec($valuesTextSql);

            // Create EAV table
            $eavSql = $parser->parse('images/eav/create_cache_eav.sql');
            $this->cacheDb->exec($eavSql);

            // Create Config table
            $configSql = $parser->parse('images/eav/create_cache_config.sql');
            $this->cacheDb->exec($configSql);

            // Create indexes for optimal query performance
            $this->createCacheIndexes();
        } catch (Exception $e) {
            throw new Exception('Failed to create cache schema: ' . $e->getMessage());
        }
    }

    /**
     * Create indexes on EAV cache tables for optimal query performance
     *
     * @return void
     */
    private function createCacheIndexes(): void
    {
        // Use SqlTemplateParser to load SQL templates from templates/sql directory
        $parser = new SqlTemplateParser();

        // Load and execute index creation SQL
        $indexesSql = $parser->parse('images/eav/create_cache_indexes.sql');
        $this->cacheDb->exec($indexesSql);
    }

    /**
     * Create autocomplete indexes for fast lookups
     * These pre-aggregate distinct values and image counts for frequently searched fields
     *
     * @return void
     * @throws Exception If autocomplete index creation fails
     */
    public function createAutocompleteIndexes(): void
    {
        // Use SqlTemplateParser to load SQL templates from templates/sql directory
        $parser = new SqlTemplateParser();

        // Load autocomplete SQL file
        try {
            $autocompleteSql = $parser->parse('images/eav/create_autocomplete_indexes.sql');
        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo "  Warning: Autocomplete SQL file not found, skipping...\n";
            }
            return;
        }

        // Execute autocomplete index creation
        // Note: This may fail if schema doesn't match expected columns (e.g., collectionName)
        // We catch and log errors but don't fail the entire build
        try {
            $this->cacheDb->exec($autocompleteSql);
        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo "  Warning: Autocomplete index creation failed: " . $e->getMessage() . "\n";
                echo "  This is non-fatal - EAV index is still functional.\n";
            }
            // Don't throw - autocomplete is optional
        }
    }

    /**
     * Store configuration in Config table
     *
     * @return void
     */
    private function storeConfig(): void
    {
        // Flatten config and store in Config table
        foreach ($this->config as $section => $values) {
            if ($section === '_config') {
                // Store _config values directly
                foreach ($values as $key => $value) {
                    $this->cacheDb->exec(sprintf(
                        "INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('%s', '%s')",
                        $key,
                        $value
                    ));
                }
            } else {
                // Store table config as JSON
                $this->cacheDb->exec(sprintf(
                    "INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('table_%s', '%s')",
                    $section,
                    json_encode($values)
                ));
            }
        }
    }

    /**
     * Build Attributes table from configuration
     *
     * @return void
     */
    public function buildAttributes(): void
    {
        $aid = 1;

        // Get all indexed fields (text and numeric, excluding display fields)
        $indexedFields = EavIndexing::getIndexedFields($this->config);
        $numericFields = EavIndexing::getNumericFields($this->config);

        // Combine all fields
        $allFields = array_merge($indexedFields, $numericFields);

        foreach ($allFields as $field) {
            $strategy = $field['strategy'] ?? 'whole';
            $this->cacheDb->exec(sprintf(
                "INSERT INTO Attributes (Aid, TableName, ColumnName, DataType, TokenStrategy) VALUES (%d, '%s', '%s', '%s', '%s')",
                $aid++,
                $field['table'],
                $field['name'],
                $field['type'],
                $strategy
            ));
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
        if (!file_exists($this->cacheDbPath)) {
            return [
                'type' => 'error',
                'message' => 'Cache database does not exist',
                'content' => 'Cache database not found. Run cache-build-eav to create it.'
            ];
        }

        $startTime = microtime(true);
        $db = new PDO('sqlite:' . $this->cacheDbPath);

        // Try to get counts from Config table first (fast)
        // Fall back to COUNT queries if Config values don't exist (slow but works for old caches)
        $entities = $this->getConfigValue($db, 'entity_count');
        $attributes = $this->getConfigValue($db, 'attribute_count');
        $valuesText = $this->getConfigValue($db, 'values_text_count');
        $eav = $this->getConfigValue($db, 'eav_count');
        $cacheBuiltAt = $this->getConfigValue($db, 'cache_built_at');

        // Track which method was used for performance metrics
        $usedConfigTable = ($entities !== null && $attributes !== null && $valuesText !== null && $eav !== null);
        $countQueriesUsed = [];

        // Fall back to COUNT queries if Config values don't exist
        if ($entities === null) {
            $entities = $db->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            $countQueriesUsed[] = 'Entities';
        }
        if ($attributes === null) {
            $attributes = $db->query('SELECT COUNT(*) FROM Attributes')->fetchColumn();
            $countQueriesUsed[] = 'Attributes';
        }
        if ($valuesText === null) {
            $valuesText = $db->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            $countQueriesUsed[] = 'ValuesText';
        }
        if ($eav === null) {
            $eav = $db->query('SELECT COUNT(*) FROM EAV')->fetchColumn();
            $countQueriesUsed[] = 'EAV';
        }

        $elapsedTime = microtime(true) - $startTime;

        // Build info output with header and file info
        $info = "\n📊 EAV Cache Information\n";
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $info .= "Cache location: {$this->cacheDbPath}\n";

        if (file_exists($this->cacheDbPath)) {
            $sizeBytes = filesize($this->cacheDbPath);
            $sizeMB = round($sizeBytes / 1024 / 1024, 2);
            $modTime = date('Y-m-d H:i:s', filemtime($this->cacheDbPath));
            $info .= "File size: {$sizeMB} MB\n";
            $info .= "Last modified: {$modTime}\n";
        }

        // Show source.db info if provided
        if ($this->sourceDbPath) {
            $info .= "\nSource database: {$this->sourceDbPath}\n";
            if (file_exists($this->sourceDbPath)) {
                $sourceSizeBytes = filesize($this->sourceDbPath);
                $sourceSizeMB = round($sourceSizeBytes / 1024 / 1024, 2);
                $sourceModTime = date('Y-m-d H:i:s', filemtime($this->sourceDbPath));
                $info .= "Source size: {$sourceSizeMB} MB\n";
                $info .= "Source modified: {$sourceModTime}\n";
            } else {
                $info .= "⚠️  Source database not found\n";
            }
        }

        $info .= "\n";

        $info .= sprintf(
            "📈 Statistics:\n" .
            "  • Entities: %s\n" .
            "  • Attributes: %s\n" .
            "  • ValuesText: %s\n" .
            "  • EAV Rows: %s\n",
            number_format($entities),
            number_format($attributes),
            number_format($valuesText),
            number_format($eav)
        );

        if ($cacheBuiltAt) {
            $info .= sprintf("  • Built At: %s\n", $cacheBuiltAt);
        }

        // Add performance metrics
        $info .= sprintf("\n⚡ Performance Metrics:\n");
        $info .= sprintf("  • Query Time: %.4f seconds\n", $elapsedTime);
        if ($usedConfigTable) {
            $info .= sprintf("  • Method: Config table (optimized)\n");
        } else {
            $info .= sprintf("  • Method: COUNT queries (slow)\n");
            $info .= sprintf("  • Tables counted: %s\n", implode(', ', $countQueriesUsed));
            $info .= sprintf("  • Note: Rebuild cache to enable Config table optimization\n");
        }

        $info .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        return [
            'type' => 'success',
            'content' => $info
        ];
    }

    /**
     * Get value from Config table
     *
     * @param PDO $db Database connection
     * @param string $key Config key
     * @return string|null Config value or null if not found
     */
    private function getConfigValue(PDO $db, string $key): ?string
    {
        try {
            $stmt = $db->prepare("SELECT ConfigValue FROM Config WHERE ConfigKey = :key");
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : null;
        } catch (Exception $e) {
            // Config table might not exist in old caches
            return null;
        }
    }

    /**
     * Cleanup EAV databases
     * Renamed from cleanup() to avoid conflict with parent Model::cleanup()
     *
     * @param array $params Parameters (work => bool, full => bool)
     * @return array Response array
     */
    public function cleanupDatabases(array $params): array
    {
        $workOnly = $params['work'] ?? false;
        $full = $params['full'] ?? false;

        if ($workOnly || $full) {
            if (file_exists($this->workDbPath)) {
                unlink($this->workDbPath);
            }
        }

        if ($full) {
            if (file_exists($this->cacheDbPath)) {
                unlink($this->cacheDbPath);
            }
        }

        return [
            'type' => 'success',
            'message' => 'Cleanup complete'
        ];
    }

    /**
     * Tokenize text based on strategy
     *
     * @param string $text Text to tokenize
     * @param string $strategy Tokenization strategy (whole|split)
     * @return array Array of tokens
     */
    public function tokenizeText(string $text, string $strategy): array
    {
        return TextNormalizer::tokenizeByStrategy($text, $strategy);
    }

    /**
     * Build JOIN query from configuration relationships
     *
     * @param string $rootTable Root table name
     * @param string $rootIdColumn Root ID column name
     * @return string SQL query with JOINs
     */
    private function buildJoinQuery(string $rootTable, string $rootIdColumn): string
    {
        // Build table graph from foreign keys
        $tableGraph = $this->buildTableGraph($rootTable);

        // Determine which tables will be joined
        $tablesToJoin = $this->getJoinedTables($tableGraph, $rootTable);
        $tablesToJoin[$rootTable] = true;

        // Track table aliases
        $aliases = [$rootTable => 'm'];
        $aliasCounter = 1;

        // Assign aliases to all tables that will be joined
        foreach ($tablesToJoin as $tableName => $included) {
            if (!isset($aliases[$tableName])) {
                $aliases[$tableName] = 't' . $aliasCounter++;
            }
        }

        // Start with root table (aliased as 'm')
        $query = sprintf('SELECT m.%s', $rootIdColumn);

        // Add columns only from tables that will be joined
        foreach ($this->config as $tableName => $tableConfig) {
            if ($tableName === '_config' || $tableName === 'aliases') {
                continue;
            }

            // Skip tables that won't be joined
            if (!isset($tablesToJoin[$tableName])) {
                continue;
            }

            $alias = $aliases[$tableName];

            // Add columns from this table (skip :fk and :root columns)
            if (isset($tableConfig['columns'])) {
                foreach ($tableConfig['columns'] as $columnDef) {
                    $parsed = TextNormalizer::parseColumnDef($columnDef);

                    // Skip foreign key columns (only used for JOINs)
                    if ($parsed['fk'] ?? false) {
                        continue;
                    }

                    // Skip root ID columns (already selected as primary identifier)
                    if ($parsed['root'] ?? false) {
                        continue;
                    }

                    $query .= sprintf(', %s.%s', $alias, $parsed['name']);
                }
            }
        }

        // Add FROM clause
        $query .= sprintf(' FROM %s m', $rootTable);

        // Add JOIN clauses based on table graph
        $joined = [$rootTable => true];
        $this->addJoins($query, $tableGraph, $rootTable, $joined, $aliases);

        // Add WHERE clause if configured
        // Qualify column names with root table alias to avoid ambiguity
        if (isset($this->config['_config']['where_clause'])) {
            $whereClause = $this->config['_config']['where_clause'];

            // Replace unqualified column names with qualified ones (m.columnName)
            $whereClause = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/',
                function($matches) use ($rootTable, $aliases) {
                    $columnName = $matches[1];

                    // Skip SQL keywords
                    $keywords = ['IS', 'NOT', 'NULL', 'AND', 'OR', 'IN', 'LIKE', 'BETWEEN'];
                    if (in_array(strtoupper($columnName), $keywords)) {
                        return $columnName;
                    }

                    // Check if column exists in root table config
                    if (isset($this->config[$rootTable]['columns'])) {
                        foreach ($this->config[$rootTable]['columns'] as $columnDef) {
                            $parsed = TextNormalizer::parseColumnDef($columnDef);
                            if ($parsed['name'] === $columnName) {
                                return $aliases[$rootTable] . '.' . $columnName;
                            }
                        }
                    }

                    return $columnName;
                },
                $whereClause
            );

            $query .= ' WHERE ' . $whereClause;
        }

        return $query;
    }

    /**
     * Get all tables that will be joined from root
     *
     * @param array $graph Table graph
     * @param string $rootTable Root table name
     * @return array Tables to join
     */
    private function getJoinedTables(array $graph, string $rootTable): array
    {
        $tables = [];
        $this->collectJoinedTables($graph, $rootTable, $tables);
        return $tables;
    }

    /**
     * Recursively collect joined tables
     *
     * @param array $graph Table graph
     * @param string $currentTable Current table
     * @param array &$tables Collected tables
     * @return void
     */
    private function collectJoinedTables(array $graph, string $currentTable, array &$tables): void
    {
        if (!isset($graph[$currentTable])) {
            return;
        }

        foreach ($graph[$currentTable] as $childTable => $joinInfo) {
            if (isset($tables[$childTable])) {
                continue;
            }

            $tables[$childTable] = true;
            $this->collectJoinedTables($graph, $childTable, $tables);
        }
    }

    /**
     * Build table relationship graph from foreign keys
     *
     * @param string $rootTable Root table name
     * @return array Table graph: [table => [child_table => [local_col, ref_col], ...]]
     */
    private function buildTableGraph(string $rootTable): array
    {
        $graph = [];

        foreach ($this->config as $tableName => $tableConfig) {
            if ($tableName === '_config' || $tableName === 'aliases') {
                continue;
            }

            // Parse foreign keys
            if (isset($tableConfig['foreign_key'])) {
                $foreignKeys = is_array($tableConfig['foreign_key'])
                    ? $tableConfig['foreign_key']
                    : [$tableConfig['foreign_key']];

                foreach ($foreignKeys as $fk) {
                    // Parse format: "local_column:referenced_table.referenced_column"
                    if (preg_match('/^([^:]+):([^.]+)\.(.+)$/', $fk, $matches)) {
                        $localCol = $matches[1];
                        $refTable = $matches[2];
                        $refCol = $matches[3];

                        // Add edge from this table to referenced table
                        // This builds the graph in the direction of the foreign key relationship
                        if (!isset($graph[$tableName])) {
                            $graph[$tableName] = [];
                        }
                        $graph[$tableName][$refTable] = [
                            'local_col' => $refCol,  // Referenced column (in child table)
                            'ref_col' => $localCol   // Local column (in parent table)
                        ];
                    }
                }
            }
        }

        return $graph;
    }

    /**
     * Recursively add JOIN clauses
     *
     * @param string &$query Query string to append to
     * @param array $graph Table graph
     * @param string $currentTable Current table being processed
     * @param array &$joined Track which tables have been joined
     * @param array $aliases Table aliases
     * @return void
     */
    private function addJoins(string &$query, array $graph, string $currentTable, array &$joined, array $aliases): void
    {
        if (!isset($graph[$currentTable])) {
            return;
        }

        foreach ($graph[$currentTable] as $childTable => $joinInfo) {
            if (isset($joined[$childTable])) {
                continue;
            }

            $parentAlias = $aliases[$currentTable];
            $childAlias = $aliases[$childTable];

            $query .= sprintf(
                ' LEFT JOIN %s %s ON %s.%s = %s.%s',
                $childTable,
                $childAlias,
                $childAlias,
                $joinInfo['local_col'],
                $parentAlias,
                $joinInfo['ref_col']
            );

            $joined[$childTable] = true;

            // Recursively add child joins
            $this->addJoins($query, $graph, $childTable, $joined, $aliases);
        }
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
     * Build EAV index using pure SQL approach (SINGLE-SCAN OPTIMIZATION)
     *
     * Architecture:
     * 1. Create work.Records staging table with all attributes as columns
     * 2. ONE SCAN of source tables → work.Records (with indexes)
     * 3. Insert numeric EAV from work.Records (fast indexed reads)
     * 4. Populate ValuesText from work.Records (fast indexed reads)
     * 5. Create indexed TokenLookup table
     * 6. Insert text EAV from TokenLookup (fast indexed reads)
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     * @param bool $append Incremental mode - only process new/changed records
     * @return array Response array
     */
    public function buildEavIndexPureSQL(?int $limit = null, bool $append = false): array
    {
        if (empty($this->sourceDbPath) || !file_exists($this->sourceDbPath)) {
            return [
                'type' => 'error',
                'message' => 'Source database not found: ' . $this->sourceDbPath
            ];
        }

        $startTime = microtime(true);

        // Determine last indexed ID for append mode
        $lastIndexedId = 0;
        if ($append && file_exists($this->cacheDbPath)) {
            try {
                $tempDb = new PDO('sqlite:' . $this->cacheDbPath);
                $stmt = $tempDb->query("SELECT ConfigValue FROM Config WHERE ConfigKey='last_indexed_id'");
                $lastIndexedId = (int)$stmt->fetchColumn();
                if (Environment::isCli() && $lastIndexedId > 0) {
                    echo "Append mode: Processing records after ID {$lastIndexedId}\n";
                }
            } catch (Exception $e) {
                // Cache doesn't exist or is invalid - do full build
                $append = false;
                if (Environment::isCli()) {
                    echo "Cache not found or invalid - performing full build\n";
                }
            }
        }

        // Connect to cache database
        $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Connect to work database for staging table
        $this->workDb = new PDO('sqlite:' . $this->workDbPath);
        $this->workDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            // Apply aggressive PRAGMA optimizations for maximum speed
            if (Environment::isCli()) {
                echo "Applying performance optimizations...\n";
            }

            // Work database optimizations (staging table) - apply BEFORE attaching
            $this->workDb->exec("PRAGMA synchronous = OFF");           // Don't wait for disk writes
            $this->workDb->exec("PRAGMA journal_mode = WAL");          // WAL mode (more efficient for large transactions)
            $this->workDb->exec("PRAGMA temp_store = MEMORY");         // Temp tables in memory
            $this->workDb->exec("PRAGMA cache_size = -2000000");       // 2GB cache (negative = KB)
            $this->workDb->exec("PRAGMA mmap_size = 2147483648");      // 2GB memory-mapped I/O

            // Cache database optimizations (final output) - apply BEFORE attaching
            $this->cacheDb->exec("PRAGMA synchronous = OFF");
            $this->cacheDb->exec("PRAGMA journal_mode = WAL");         // WAL mode for concurrent reads
            $this->cacheDb->exec("PRAGMA cache_size = -1000000");      // 1GB cache
            $this->cacheDb->exec("PRAGMA temp_store = MEMORY");
            $this->cacheDb->exec("PRAGMA mmap_size = 1073741824");     // 1GB memory-mapped I/O

            // Attach databases
            if (Environment::isCli()) {
                echo "Attaching databases...\n";
                if ($limit) {
                    echo "Processing limit: " . number_format($limit) . " entities\n";
                }
            }
            $this->workDb->exec("ATTACH DATABASE '{$this->sourceDbPath}' AS source");
            $this->workDb->exec("ATTACH DATABASE '{$this->cacheDbPath}' AS cache");

            // Source database optimizations (read-only) - apply AFTER attaching
            $this->workDb->exec("PRAGMA source.cache_size = -500000");  // 500MB cache for source
            $this->workDb->exec("PRAGMA source.mmap_size = 1073741824"); // 1GB memory-mapped I/O

            // Cache database optimizations via attached database - apply AFTER attaching
            $this->workDb->exec("PRAGMA cache.synchronous = OFF");
            $this->workDb->exec("PRAGMA cache.cache_size = -1000000");



            // Step 1: Create work.Records staging table (without indexes)
            if (Environment::isCli()) {
                echo "\nStep 1: Creating work.Records staging table...\n";
            }
            $step1Time = microtime(true);
            $this->createWorkRecordsTable();
            if (Environment::isCli()) {
                $elapsed = microtime(true) - $step1Time;
                echo sprintf("✓ Staging table created (%.2fs)\n", $elapsed);
            }

            // Step 2: MULTI-SCAN - Populate work.Records from source tables
            // Each operation commits separately to avoid OOM on large datasets
            if (Environment::isCli()) {
                if ($append && $lastIndexedId > 0) {
                    echo "\nStep 2: Populating work.Records (incremental - records > {$lastIndexedId})...\n";
                } else {
                    echo "\nStep 2: Populating work.Records (multi-scan with auto-commit)...\n";
                }
            }
            $step2Time = microtime(true);
            $this->populateWorkRecordsMultiScan($limit, $lastIndexedId);
            if (Environment::isCli()) {
                $elapsed = microtime(true) - $step2Time;
                echo sprintf("✓ Work records populated (%.2fs)\n", $elapsed);
            }

            // Step 2b: Create indexes on work.Records AFTER data insertion
            // These indexes speed up the per-attribute JOINs in buildTextEavByAttribute()
            if (Environment::isCli()) {
                echo "\nStep 2b: Creating indexes on work.Records...\n";
            }
            $step2bTime = microtime(true);
            $this->createWorkRecordsIndexes();
            if (Environment::isCli()) {
                $elapsed = microtime(true) - $step2bTime;
                echo sprintf("✓ Indexes created (%.2fs)\n", $elapsed);
            }

            // Step 3: Populate Entities from work.Records
            // NOTE: No transaction wrapper - auto-commit to avoid OOM on large datasets
            if (Environment::isCli()) {
                echo "\nStep 3: Populating Entities...\n";
            }
            $step3Time = microtime(true);
            $this->buildEntitiesFromWorkRecords($limit);
            if (Environment::isCli()) {
                $elapsed = microtime(true) - $step3Time;
                echo sprintf("✓ Entities populated (%.2fs)\n", $elapsed);
            }

            // Step 4-7: Build EAV (process text attributes one at a time to avoid OOM)
            if (Environment::isCli()) {
                echo "\nStep 4-7: Populating EAV...\n";
            }
            $step4Time = microtime(true);

            // Step 4: Insert numeric EAV from work.Records (in transaction)
            if (Environment::isCli()) {
                echo "  Step 4: Numeric EAV...\n";
            }
            $this->workDb->exec("BEGIN TRANSACTION");
            $this->buildNumericEavFromWorkRecords();
            $this->workDb->exec("COMMIT");

            // Step 5-8: Build text EAV one attribute at a time to avoid OOM
            // Processing 59M rows at once causes OOM when creating indexes
            if (Environment::isCli()) {
                echo "  Step 5-8: Text EAV (processing attributes individually)...\n";
            }
            $this->buildTextEavByAttribute();
            if (Environment::isCli()) {
                $elapsed = microtime(true) - $step4Time;
                echo sprintf("✓ All EAV operations completed (%.2fs)\n", $elapsed);
            }

            // Cleanup: Detach databases
            if (Environment::isCli()) {
                echo "\nCleaning up...\n";
            }
            $this->workDb->exec("DETACH DATABASE source");
            $this->workDb->exec("DETACH DATABASE cache");

            $totalTime = microtime(true) - $startTime;

            // Get statistics from cache database
            $entityCount = $this->cacheDb->query('SELECT COUNT(*) FROM Entities')->fetchColumn();
            $attributeCount = $this->cacheDb->query('SELECT COUNT(*) FROM Attributes')->fetchColumn();
            $valuesTextCount = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            $eavCount = $this->cacheDb->query('SELECT COUNT(*) FROM EAV')->fetchColumn();

            // Save statistics to Config table for fast retrieval by getInfo()
            $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('entity_count', '{$entityCount}')");
            $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('attribute_count', '{$attributeCount}')");
            $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('values_text_count', '{$valuesTextCount}')");
            $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('eav_count', '{$eavCount}')");
            $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('cache_built_at', '" . date('Y-m-d H:i:s') . "')");

            // Save last indexed ID for incremental builds
            // Use MAX(EntityValue) not MAX(Eid) because EntityValue is the source ID (mediaID/occid)
            $maxEntityValue = $this->cacheDb->query("SELECT MAX(EntityValue) FROM Entities")->fetchColumn();
            if ($maxEntityValue) {
                $this->cacheDb->exec("INSERT OR REPLACE INTO Config (ConfigKey, ConfigValue) VALUES ('last_indexed_id', '{$maxEntityValue}')");
                if (Environment::isCli() && $append) {
                    echo sprintf("  Last indexed ID: %s\n", number_format($maxEntityValue));
                }
            }

            if (Environment::isCli()) {
                echo sprintf("\n✓ SINGLE-SCAN optimization completed in %.2fs\n", $totalTime);
                echo sprintf("  Entities: %s\n", number_format($entityCount));
                echo sprintf("  ValuesText: %s\n", number_format($valuesTextCount));
                echo sprintf("  EAV rows: %s\n", number_format($eavCount));
                echo sprintf("  Rate: %s entities/sec\n", number_format($entityCount / $totalTime));
            }

            return [
                'entityCount' => (int)$entityCount,
                'valuesTextCount' => (int)$valuesTextCount,
                'eavCount' => (int)$eavCount,
                'totalTime' => $totalTime
            ];

        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo "\nError: " . $e->getMessage() . "\n";
                echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            }
            return [
                'type' => 'error',
                'message' => 'Error building EAV index: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build EAV index from source database (OLD BATCH APPROACH)
     *
     * @param array $params Parameters
     * @return array Response array
     */
    public function buildEavIndex(array $params): array
    {
        if (empty($this->sourceDbPath) || !file_exists($this->sourceDbPath)) {
            return [
                'type' => 'error',
                'message' => 'Source database not found'
            ];
        }

        // Connect to source database
        $this->sourceDb = new PDO('sqlite:' . $this->sourceDbPath);
        $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Connect to cache database
        $this->cacheDb = new PDO('sqlite:' . $this->cacheDbPath);
        $this->cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get configuration
        $rootTable = $this->config['_config']['root_table'];
        $rootIdColumn = $this->config['_config']['root_id_column'];

        // Get display fields, indexed fields, and numeric fields
        $displayFields = EavIndexing::getDisplayFields($this->config);
        $indexedFields = EavIndexing::getIndexedFields($this->config);
        $numericFields = EavIndexing::getNumericFields($this->config);

        // Build field maps
        $displayFieldMap = [];
        foreach ($displayFields as $field) {
            $displayFieldMap[$field['name']] = $field;
        }

        $indexedFieldMap = [];
        foreach ($indexedFields as $field) {
            $indexedFieldMap[$field['name']] = $field;
        }

        $numericFieldMap = [];
        foreach ($numericFields as $field) {
            $numericFieldMap[$field['name']] = $field;
        }

        // Get Attributes map (Aid by ColumnName)
        $attributesMap = [];
        $stmt = $this->cacheDb->query('SELECT Aid, ColumnName, DataType FROM Attributes');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $attributesMap[$row['ColumnName']] = [
                'aid' => $row['Aid'],
                'type' => $row['DataType']
            ];
        }

        // Reset statistics
        $this->resetStats();

        // Override batch sizes from params if provided
        if (isset($params['entity-batch-size'])) {
            $this->entityBatchSize = (int)$params['entity-batch-size'];
        } elseif (isset($params['entity_batch_size'])) {
            $this->entityBatchSize = (int)$params['entity_batch_size'];
        }

        if (isset($params['valuestext-flush-size'])) {
            $this->valuesTextFlushSize = (int)$params['valuestext-flush-size'];
        } elseif (isset($params['valuestext_flush_size'])) {
            $this->valuesTextFlushSize = (int)$params['valuestext_flush_size'];
        }

        // Use keyset pagination for batch processing
        $batchSize = $this->entityBatchSize;
        $lastRootId = 0;
        $totalProcessed = 0;
        $batchNumber = 0;
        $progressInterval = 10000;
        $lastProgressTime = microtime(true);
        $valuesTextCache = []; // Global cache: ValueText => Vid

        if (Environment::isCli()) {
            echo "Using keyset pagination with batch_size=$batchSize, flush_size={$this->valuesTextFlushSize}\n";
            echo "Processing batches...\n\n";
        }

        while (true) {
            $batchNumber++;
            $batchStartTime = microtime(true);

            // Build keyset pagination query
            $query = $this->buildJoinQuery($rootTable, $rootIdColumn);

            // Add WHERE clause for keyset pagination
            // Use WHERE if no WHERE clause exists, otherwise AND
            $whereKeyword = (stripos($query, ' WHERE ') !== false) ? ' AND' : ' WHERE';
            $query .= sprintf("%s %s.%s > %d ORDER BY %s.%s LIMIT %d",
                $whereKeyword,
                $this->getTableAlias($rootTable),
                $rootIdColumn,
                $lastRootId,
                $this->getTableAlias($rootTable),
                $rootIdColumn,
                $batchSize
            );

            $stmt = $this->sourceDb->query($query);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break; // No more rows
            }

            // Process this batch using 3-query approach
            $batchStats = $this->processBatchOptimized(
                $rows,
                $rootTable,
                $rootIdColumn,
                $displayFieldMap,
                $indexedFieldMap,
                $numericFieldMap,
                $attributesMap,
                $valuesTextCache
            );

            $rowCount = count($rows);
            $totalProcessed += $rowCount;
            $lastRootId = $rows[$rowCount - 1][$rootIdColumn]; // Update for next batch

            $batchElapsed = microtime(true) - $batchStartTime;
            $batchRate = $rowCount / $batchElapsed;

            if (Environment::isCli()) {
                echo sprintf("Batch %d: %d entities (%.0f entities/sec, %d EAV rows, %d tokens cached) [%.2fs]\n",
                    $batchNumber, $rowCount, $batchRate,
                    $batchStats['eav_rows'], count($valuesTextCache), $batchElapsed);
            }

            // Flush ValuesText cache if threshold reached
            if (count($valuesTextCache) >= $this->valuesTextFlushSize) {
                $valuesTextCache = [];
                $this->stats['valuestext_flushes']++;
                if (Environment::isCli()) {
                    echo "  → Flushed ValuesText cache\n";
                }
            }
        }

        return [
            'type' => 'success',
            'message' => sprintf('EAV index built successfully. Processed %d entities in %d batches.',
                $totalProcessed, $batchNumber),
            'stats' => $this->stats
        ];
    }

    /**
     * Process a batch of rows using optimized 3-query approach
     *
     * @param array $rows Batch of rows from source database
     * @param string $rootTable Root table name
     * @param string $rootIdColumn Root ID column name
     * @param array $displayFieldMap Display fields map
     * @param array $indexedFieldMap Indexed fields map
     * @param array $numericFieldMap Numeric fields map
     * @param array $attributesMap Attributes map (ColumnName => [aid, type])
     * @param array $valuesTextCache Global cache of ValueText => Vid
     * @return array Batch statistics
     */
    private function processBatchOptimized(
        array $rows,
        string $rootTable,
        string $rootIdColumn,
        array $displayFieldMap,
        array $indexedFieldMap,
        array $numericFieldMap,
        array $attributesMap,
        array &$valuesTextCache
    ): array {
        $batchEavRows = 0;

        // Query 1: Batch INSERT Entities with display fields
        $this->batchInsertEntities($rows, $rootTable, $rootIdColumn, $displayFieldMap);

        // Query 2: Batch INSERT numeric EAV rows
        $numericEavRows = $this->batchInsertNumericEav($rows, $numericFieldMap, $attributesMap);
        $batchEavRows += $numericEavRows;

        // Query 3: Batch INSERT text EAV rows (with ValuesText processing)
        $textEavRows = $this->batchInsertTextEav($rows, $indexedFieldMap, $attributesMap, $valuesTextCache);
        $batchEavRows += $textEavRows;

        return [
            'eav_rows' => $batchEavRows
        ];
    }

    /**
     * Get table alias for a table name
     */
    private function getTableAlias(string $tableName): string {
        // Simple alias: first letter of table name
        return substr($tableName, 0, 1);
    }

    /**
     * Batch INSERT entities with display fields using INSERT INTO SELECT
     * This is MUCH faster than individual INSERTs
     */
    private function batchInsertEntities(array $rows, string $rootTable, string $rootIdColumn, array $displayFieldMap): void {
        if (empty($rows)) {
            return;
        }

        // Get min and max IDs for this batch
        $minId = $rows[0][$rootIdColumn];
        $maxId = $rows[count($rows) - 1][$rootIdColumn];

        // Build column list: EntityValue (Eid is auto-increment), display fields
        $selectColumns = [$rootIdColumn . ' AS EntityValue'];
        $insertColumns = ['EntityValue'];

        foreach ($displayFieldMap as $fieldName => $field) {
            $selectColumns[] = $fieldName;
            $insertColumns[] = $fieldName;
        }

        // Use SQL templates
        $parser = new SqlTemplateParser();

        // Attach source database
        $attachSql = $parser->parse('images/eav/attach_source_db.sql', [
            'source_db_path' => $this->sourceDbPath
        ]);
        $this->cacheDb->exec($attachSql);

        // Batch insert entities
        $insertSql = $parser->parse('images/eav/batch_insert_entities.sql', [
            'insert_columns' => implode(', ', $insertColumns),
            'select_columns' => implode(', ', $selectColumns),
            'root_table' => $rootTable,
            'root_id_column' => $rootIdColumn,
            'min_id' => $minId,
            'max_id' => $maxId
        ]);

        try {
            $this->cacheDb->exec($insertSql);
        } catch (PDOException $e) {
            if (Environment::isCli()) {
                echo "\n[ERROR] Failed to insert entities:\n";
                echo "Min ID: $minId, Max ID: $maxId\n";
                echo "SQL: $insertSql\n";
                echo "Error: " . $e->getMessage() . "\n\n";

                // Check what's already in the Entities table
                $existing = $this->cacheDb->query('SELECT Eid, EntityValue FROM Entities ORDER BY Eid')->fetchAll(PDO::FETCH_ASSOC);
                echo "Existing Entities:\n";
                foreach ($existing as $row) {
                    echo "  Eid={$row['Eid']}, EntityValue={$row['EntityValue']}\n";
                }
            }
            throw $e;
        }

        // Detach source database
        $detachSql = $parser->parse('images/eav/detach_source_db.sql');
        $this->cacheDb->exec($detachSql);

        $this->stats['entities_processed'] += count($rows);
        $this->stats['entity_batches'] += count($rows); // Track individual entities for compatibility
    }

    /**
     * Batch INSERT numeric EAV rows
     */
    private function batchInsertNumericEav(array $rows, array $numericFieldMap, array $attributesMap): int {
        $eavBatch = [];

        foreach ($rows as $row) {
            $eid = $row[array_key_first($row)]; // Use first column (mediaID) as Eid

            foreach ($numericFieldMap as $fieldName => $field) {
                if (!isset($row[$fieldName]) || $row[$fieldName] === null || $row[$fieldName] === '') {
                    continue;
                }

                if (!isset($attributesMap[$fieldName])) {
                    continue;
                }

                $aid = $attributesMap[$fieldName]['aid'];
                $value = (float)$row[$fieldName];

                // For numeric: Vid=NULL, VidOrder=NULL, ValueNumber=value
                $eavBatch[] = [$eid, $aid, null, null, $value];
            }
        }

        if (!empty($eavBatch)) {
            $this->flushEavBatch($eavBatch);
        }

        return count($eavBatch);
    }

    /**
     * Batch INSERT text EAV rows with ValuesText processing
     */
    private function batchInsertTextEav(array $rows, array $indexedFieldMap, array $attributesMap, array &$valuesTextCache): int {
        // Step 1: Collect all tokens from all rows
        $allTokens = []; // token => true (for uniqueness)
        $rowTokens = []; // eid => [fieldName => [aid, strategy, tokens]]

        foreach ($rows as $row) {
            $eid = $row[array_key_first($row)]; // Use first column (mediaID) as Eid
            $rowTokens[$eid] = [];

            foreach ($indexedFieldMap as $fieldName => $field) {
                if (!isset($row[$fieldName]) || empty($row[$fieldName])) {
                    continue;
                }

                if (!isset($attributesMap[$fieldName])) {
                    continue;
                }

                $aid = $attributesMap[$fieldName]['aid'];
                $strategy = $field['strategy'] ?? 'whole';
                $tokens = $this->tokenizeText($row[$fieldName], $strategy);
                $this->stats['total_tokens'] += count($tokens);

                $rowTokens[$eid][$fieldName] = [
                    'aid' => $aid,
                    'strategy' => $strategy,
                    'tokens' => $tokens
                ];

                foreach ($tokens as $token) {
                    $allTokens[$token] = true;
                }
            }
        }

        // Step 2: Get/create Vids for all tokens
        $this->ensureValuesTextExist(array_keys($allTokens), $valuesTextCache);

        // Step 3: Build EAV batch with proper Vid/VidOrder structure
        $eavBatch = [];

        foreach ($rowTokens as $eid => $fields) {
            foreach ($fields as $fieldName => $data) {
                $aid = $data['aid'];
                $strategy = $data['strategy'];
                $tokens = $data['tokens'];

                // Create one row per token with VidOrder
                foreach ($tokens as $order => $token) {
                    if (isset($valuesTextCache[$token])) {
                        $vid = $valuesTextCache[$token];

                        // For :whole strategy, VidOrder is NULL (only one token)
                        // For :split strategy, VidOrder preserves token sequence (0, 1, 2, ...)
                        $vidOrder = ($strategy === 'split') ? $order : null;

                        // [Eid, Aid, Vid, VidOrder, ValueNumber]
                        $eavBatch[] = [$eid, $aid, $vid, $vidOrder, null];
                    }
                }
            }
        }

        if (!empty($eavBatch)) {
            $this->flushEavBatch($eavBatch);
        }

        return count($eavBatch);
    }

    /**
     * Ensure ValuesText entries exist for all tokens and update cache
     */
    private function ensureValuesTextExist(array $tokens, array &$cache): void {
        if (empty($tokens)) {
            return;
        }

        // Filter out tokens already in cache
        $newTokens = array_filter($tokens, fn($t) => !isset($cache[$t]));

        if (!empty($newTokens)) {
            // Insert new tokens
            $this->flushValuesTextBatch(array_fill_keys($newTokens, true));
            $this->stats['valuestext_flushes']++;
        }

        // Query all tokens to get Vids and update cache
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        $sql = "SELECT Vid, ValueText FROM ValuesText WHERE ValueText IN ($placeholders)";
        $stmt = $this->cacheDb->prepare($sql);
        $stmt->execute($tokens);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cache[$row['ValueText']] = $row['Vid'];
        }
    }

    /**
     * Flush ValuesText batch to database
     *
     * Inserts unique text values into ValuesText table using INSERT OR IGNORE.
     * Vid is auto-assigned by AUTOINCREMENT.
     *
     * @param array $valuesTextBatch Set of unique text values (keys are text, values are true)
     * @return void
     */
    private function flushValuesTextBatch(array $valuesTextBatch): void
    {
        if (empty($valuesTextBatch)) {
            return;
        }

        $this->cacheDb->beginTransaction();

        // Prepare INSERT OR IGNORE statement (Vid auto-increments)
        $stmt = $this->cacheDb->prepare("INSERT OR IGNORE INTO ValuesText (ValueText) VALUES (?)");

        foreach (array_keys($valuesTextBatch) as $valueText) {
            $stmt->execute([$valueText]);
        }

        $this->cacheDb->commit();
    }

    /**
     * Flush EAV batch using multi-row INSERT
     *
     * @param array $eavBatch Array of EAV rows [Eid, Aid, Vid, VidOrder, ValueNumber]
     * @return void
     */
    private function flushEavBatch(array &$eavBatch): void
    {
        if (empty($eavBatch)) {
            return;
        }

        $this->cacheDb->beginTransaction();

        // Build multi-row INSERT statement
        $placeholders = [];
        $values = [];

        foreach ($eavBatch as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $values = array_merge($values, $row);
        }

        $sql = 'INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber) VALUES ' . implode(', ', $placeholders);
        $stmt = $this->cacheDb->prepare($sql);
        $stmt->execute($values);

        $this->cacheDb->commit();

        $this->stats['eav_inserts'] += count($eavBatch);
    }

    /**
     * Get Vids for tokens by querying ValuesText table
     *
     * @param array $tokens Array of text tokens
     * @return array Array of Vids in same order as tokens
     */
    private function getVidsForTokens(array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        // Build IN clause with placeholders
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        $sql = "SELECT Vid, ValueText FROM ValuesText WHERE ValueText IN ($placeholders)";

        $stmt = $this->cacheDb->prepare($sql);
        $stmt->execute($tokens);

        // Build map of ValueText => Vid
        $textToVid = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $textToVid[$row['ValueText']] = $row['Vid'];
        }

        // Return Vids in same order as input tokens
        $vids = [];
        foreach ($tokens as $token) {
            if (isset($textToVid[$token])) {
                $vids[] = $textToVid[$token];
            }
        }

        return $vids;
    }

    /**
     * Step 0: Create indexes on source database for fast JOINs
     * This dramatically speeds up the single-scan operation
     */
    private function createSourceIndexes(): void
    {
        $rootTable = $this->config['_config']['root_table'];

        // Create indexes on foreign key columns for fast JOINs
        $indexesCreated = 0;

        // Index root table foreign keys
        if (isset($this->config[$rootTable]['foreign_key'])) {
            foreach ($this->config[$rootTable]['foreign_key'] as $fkDef) {
                list($localCol, $refTableCol) = explode(':', $fkDef);
                list($refTable, $refCol) = explode('.', $refTableCol);

                // Create index on root table foreign key column
                try {
                    $this->workDb->exec("CREATE INDEX IF NOT EXISTS idx_source_{$rootTable}_{$localCol} ON source.{$rootTable}({$localCol})");
                    $indexesCreated++;
                } catch (Exception $e) {
                    // Index might already exist, ignore
                }

                // Create index on referenced table primary key
                try {
                    $this->workDb->exec("CREATE INDEX IF NOT EXISTS idx_source_{$refTable}_{$refCol} ON source.{$refTable}({$refCol})");
                    $indexesCreated++;
                } catch (Exception $e) {
                    // Index might already exist, ignore
                }

                // Check for 2nd level foreign keys
                if (isset($this->config[$refTable]['foreign_key'])) {
                    foreach ($this->config[$refTable]['foreign_key'] as $fkDef2) {
                        list($localCol2, $refTableCol2) = explode(':', $fkDef2);
                        list($refTable2, $refCol2) = explode('.', $refTableCol2);

                        // Create index on 2nd level foreign key column
                        try {
                            $this->workDb->exec("CREATE INDEX IF NOT EXISTS idx_source_{$refTable}_{$localCol2} ON source.{$refTable}({$localCol2})");
                            $indexesCreated++;
                        } catch (Exception $e) {
                            // Index might already exist, ignore
                        }

                        // Create index on 2nd level referenced table primary key
                        try {
                            $this->workDb->exec("CREATE INDEX IF NOT EXISTS idx_source_{$refTable2}_{$refCol2} ON source.{$refTable2}({$refCol2})");
                            $indexesCreated++;
                        } catch (Exception $e) {
                            // Index might already exist, ignore
                        }
                    }
                }
            }
        }

        // Run ANALYZE on source database to help SQLite optimize queries
        $this->workDb->exec("ANALYZE source");

        if (Environment::isCli()) {
            echo sprintf("  Created/verified %d indexes on source tables\n", $indexesCreated);
        }
    }

    /**
     * Step 1: Create work.Records staging table with all attributes as columns
     * This enables single-scan optimization with indexed access
     * NOTE: Indexes created AFTER data insertion for speed
     */
    private function createWorkRecordsTable(): void
    {
        // Get all attributes from cache.Attributes
        $stmt = $this->cacheDb->query("SELECT Aid, TableName, ColumnName, DataType FROM Attributes ORDER BY Aid");
        $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build column definitions
        $numericColumns = [];
        $textColumns = [];

        foreach ($attributes as $attr) {
            $colName = "Aid_" . $attr['Aid'];

            if ($attr['DataType'] === 'numeric') {
                $numericColumns[] = "{$colName} REAL";
            } else {
                $textColumns[] = "{$colName} TEXT";
            }
        }

        // Build CREATE TABLE SQL (no prefix - creating in main work database)
        // WITHOUT INDEXES - we'll create them after data insertion
        // Drop existing table first to ensure clean state
        $this->workDb->exec("DROP TABLE IF EXISTS Records");

        $sql = "CREATE TABLE Records (\n";
        $sql .= "    Eid INTEGER PRIMARY KEY";

        if (!empty($numericColumns)) {
            $sql .= ",\n    " . implode(",\n    ", $numericColumns);
        }
        if (!empty($textColumns)) {
            $sql .= ",\n    " . implode(",\n    ", $textColumns);
        }

        $sql .= "\n) WITHOUT ROWID";  // WITHOUT ROWID for better performance with INTEGER PRIMARY KEY

        // Create table
        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            echo sprintf("  Created work.Records with %d columns (indexes deferred)\n",
                count($attributes));
        }
    }

    /**
     * Create indexes on work.Records AFTER data insertion
     * This is much faster than creating indexes before insertion
     */
    private function createWorkRecordsIndexes(): void
    {
        if (Environment::isCli()) {
            echo "  Creating indexes on work.Records...\n";
        }

        // Get all attributes
        $stmt = $this->cacheDb->query("SELECT Aid, DataType FROM Attributes ORDER BY Aid");
        $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Create indexes for each attribute
        // We only need indexes on columns we'll actually query
        // For this optimization, we only need indexes for text columns (for JOIN to ValuesText)
        $indexCount = 0;
        foreach ($attributes as $attr) {
            if ($attr['DataType'] === 'text') {
                $colName = "Aid_" . $attr['Aid'];
                $this->workDb->exec("CREATE INDEX idx_records_{$colName} ON Records({$colName})");
                $indexCount++;
            }
        }

        // Run ANALYZE to help SQLite optimize queries
        $this->workDb->exec("ANALYZE Records");

        if (Environment::isCli()) {
            echo sprintf("  Created %d indexes and analyzed table\n", $indexCount);
        }
    }

    /**
     * Step 2: MULTI-SCAN - Populate work.Records from source tables
     * Uses multiple simple scans instead of complex JOINs for better performance
     *
     * Strategy:
     * 1. INSERT root table columns (simple scan, no JOINs)
     * 2. UPDATE with 1-level JOIN columns (indexed UPDATE)
     * 3. UPDATE with 2-level JOIN columns (indexed UPDATE)
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     * @param int $lastIndexedId For incremental builds, only process records > this ID
     */
    private function populateWorkRecordsMultiScan(?int $limit = null, int $lastIndexedId = 0): void
    {
        $rootTable = $this->config['_config']['root_table'];
        $rootIdColumn = $this->config['_config']['root_id_column'];

        // Get all attributes grouped by table
        $stmt = $this->cacheDb->query("SELECT Aid, TableName, ColumnName, DataType FROM Attributes ORDER BY TableName, Aid");
        $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attrsByTable = [];
        foreach ($attributes as $attr) {
            $attrsByTable[$attr['TableName']][] = $attr;
        }

        // Step 2a: INSERT root table columns (simple scan, no JOINs)
        if (Environment::isCli()) {
            echo "  Step 2a: Scanning root table ({$rootTable})...\n";
        }

        $rootAttrs = $attrsByTable[$rootTable] ?? [];
        $selectExprs = ["source.{$rootTable}.{$rootIdColumn} AS Eid"];
        $columnList = ["Eid"];

        foreach ($rootAttrs as $attr) {
            $colName = "Aid_" . $attr['Aid'];
            $columnList[] = $colName;

            if ($attr['DataType'] === 'numeric') {
                $selectExprs[] = "{$attr['ColumnName']} AS {$colName}";
            } else {
                $selectExprs[] = "LOWER(TRIM({$attr['ColumnName']})) AS {$colName}";
            }
        }

        $sql = "INSERT INTO Records (" . implode(", ", $columnList) . ")\n";
        $sql .= "SELECT " . implode(", ", $selectExprs) . "\n";
        $sql .= "FROM source.{$rootTable}";

        // Add WHERE clause for incremental builds
        if ($lastIndexedId > 0) {
            $sql .= "\nWHERE {$rootIdColumn} > {$lastIndexedId}";
        }

        if ($limit !== null) {
            $sql .= "\nLIMIT {$limit}";
        }

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            echo sprintf("    Inserted %s records from root table\n", number_format($count));
        }

        // Step 2b: UPDATE with 1-level JOIN columns (indexed UPDATE)
        if (isset($this->config[$rootTable]['foreign_key'])) {
            foreach ($this->config[$rootTable]['foreign_key'] as $fkDef) {
                list($localCol, $refTableCol) = explode(':', $fkDef);
                list($refTable, $refCol) = explode('.', $refTableCol);

                if (!isset($attrsByTable[$refTable])) {
                    continue;  // No attributes from this table
                }

                if (Environment::isCli()) {
                    echo sprintf("  Step 2b: Updating from %s (1-level JOIN)...\n", $refTable);
                }

                // Build UPDATE SET clauses for all columns from this table
                $setClauses = [];
                foreach ($attrsByTable[$refTable] as $attr) {
                    $colName = "Aid_" . $attr['Aid'];

                    if ($attr['DataType'] === 'numeric') {
                        $setClauses[] = "{$colName} = t.{$attr['ColumnName']}";
                    } else {
                        $setClauses[] = "{$colName} = LOWER(TRIM(t.{$attr['ColumnName']}))";
                    }
                }

                // Execute UPDATE (single pass - source tables are indexed)
                $sql = "UPDATE Records SET " . implode(", ", $setClauses) . "\n";
                $sql .= "FROM source.{$rootTable} r\n";
                $sql .= "JOIN source.{$refTable} t ON r.{$localCol} = t.{$refCol}\n";
                $sql .= "WHERE Records.Eid = r.{$rootIdColumn}";

                $this->workDb->exec($sql);

                if (Environment::isCli()) {
                    $count = $this->workDb->query('SELECT changes()')->fetchColumn();
                    echo sprintf("    Updated %s records\n", number_format($count));
                }

                // Step 2c: UPDATE with 2-level JOIN columns using DISTINCT optimization
                // Process each unique foreign key value ONCE instead of per entity
                if (isset($this->config[$refTable]['foreign_key'])) {
                    // Create index on Records(Eid) for fast subquery lookups
                    // This is critical for TempMapping creation performance
                    static $eidIndexCreated = false;
                    if (!$eidIndexCreated) {
                        if (Environment::isCli()) {
                            echo "  Creating index on Records(Eid) for 2-level JOINs...\n";
                        }
                        $this->workDb->exec("CREATE INDEX IF NOT EXISTS idx_records_eid ON Records(Eid)");
                        $eidIndexCreated = true;
                    }

                    foreach ($this->config[$refTable]['foreign_key'] as $fkDef2) {
                        list($localCol2, $refTableCol2) = explode(':', $fkDef2);
                        list($refTable2, $refCol2) = explode('.', $refTableCol2);

                        if (!isset($attrsByTable[$refTable2])) {
                            continue;  // No attributes from this table
                        }

                        if (Environment::isCli()) {
                            echo sprintf("  Step 2c: Updating from %s (DISTINCT optimization via %s)...\n", $refTable2, $refTable);
                        }

                        // Get DISTINCT foreign key values from intermediate table
                        // ONLY for entities in our Records table (respects LIMIT)
                        // OPTIMIZED: Get DISTINCT foreign keys from intermediate table directly
                        // This avoids duplicate processing when multiple entities share the same intermediate record
                        // Example: Multiple images (media) may reference the same occurrence (omoccurrences.occid)
                        $distinctSql = "SELECT DISTINCT {$localCol2}\n";
                        $distinctSql .= "FROM source.{$refTable}\n";
                        $distinctSql .= "WHERE {$refCol} IN (SELECT DISTINCT {$localCol} FROM source.{$rootTable} WHERE {$rootIdColumn} IN (SELECT Eid FROM Records))\n";
                        $distinctSql .= "  AND {$localCol2} IS NOT NULL";
                        $distinctIds = $this->workDb->query($distinctSql)->fetchAll(PDO::FETCH_COLUMN);

                        if (empty($distinctIds)) {
                            if (Environment::isCli()) {
                                echo sprintf("    No distinct %s values found\n", $localCol2);
                            }
                            continue;
                        }

                        if (Environment::isCli()) {
                            echo sprintf("    Processing %s unique %s values (instead of all entities)...\n",
                                number_format(count($distinctIds)), $refTable2);
                        }

                        // Create temp table with DISTINCT ID → text values mapping
                        $this->workDb->exec("DROP TABLE IF EXISTS TempDistinct");

                        // Build SELECT for all columns from refTable2
                        $selectCols = [$refCol2];
                        foreach ($attrsByTable[$refTable2] as $attr) {
                            if ($attr['DataType'] === 'numeric') {
                                $selectCols[] = $attr['ColumnName'];
                            } else {
                                $selectCols[] = "LOWER(TRIM({$attr['ColumnName']})) AS {$attr['ColumnName']}";
                            }
                        }

                        $idList = implode(',', $distinctIds);
                        $sql_temp = "CREATE TEMP TABLE TempDistinct AS\n";
                        $sql_temp .= "SELECT " . implode(", ", $selectCols) . "\n";
                        $sql_temp .= "FROM source.{$refTable2}\n";
                        $sql_temp .= "WHERE {$refCol2} IN ({$idList})";

                        $this->workDb->exec($sql_temp);
                        $this->workDb->exec("CREATE INDEX idx_tempdistinct_id ON TempDistinct({$refCol2})");

                        // Create mapping table: Eid → RefId (WITHOUT indexes to avoid OOM)
                        $this->workDb->exec("DROP TABLE IF EXISTS TempMapping");
                        $this->workDb->exec("CREATE TEMP TABLE TempMapping (Eid INTEGER, RefId INTEGER)");

                        // Populate TempMapping in batches to avoid OOM
                        if (Environment::isCli()) {
                            echo "    Creating TempMapping in batches...\n";
                        }
                        $this->populateTempMappingBatched($rootTable, $rootIdColumn, $refTable, $localCol, $refCol, $localCol2);

                        // Create indexes AFTER population
                        if (Environment::isCli()) {
                            echo "    Creating indexes on TempMapping...\n";
                        }
                        $this->workDb->exec("CREATE INDEX idx_tempmapping_eid ON TempMapping(Eid)");
                        $this->workDb->exec("CREATE INDEX idx_tempmapping_refid ON TempMapping(RefId)");

                        // Build UPDATE SET clauses
                        $setClauses2 = [];
                        foreach ($attrsByTable[$refTable2] as $attr) {
                            $colName = "Aid_" . $attr['Aid'];
                            $setClauses2[] = "{$colName} = td.{$attr['ColumnName']}";
                        }

                        // UPDATE using the DISTINCT values table (single pass - TempMapping is already indexed)
                        $sql2 = "UPDATE Records SET " . implode(", ", $setClauses2) . "\n";
                        $sql2 .= "FROM TempMapping tm\n";
                        $sql2 .= "JOIN TempDistinct td ON tm.RefId = td.{$refCol2}\n";
                        $sql2 .= "WHERE Records.Eid = tm.Eid";

                        $this->workDb->exec($sql2);

                        if (Environment::isCli()) {
                            $count2 = $this->workDb->query('SELECT changes()')->fetchColumn();
                            echo sprintf("    Updated %s entity records from %s unique %s records\n",
                                number_format($count2), number_format(count($distinctIds)), $refTable2);
                        }

                        $this->workDb->exec("DROP TABLE TempDistinct");
                        $this->workDb->exec("DROP TABLE TempMapping");
                    }
                }
            }
        }

        if (Environment::isCli()) {
            $totalCount = $this->workDb->query('SELECT COUNT(*) FROM Records')->fetchColumn();
            echo sprintf("  Total records in work.Records: %s\n", number_format($totalCount));
        }
    }

    /**
     * Build all JOIN clauses needed to access all tables
     * Uses LEFT JOIN to preserve entities even if related data is missing
     */
    private function buildAllJoins(): string
    {
        $rootTable = $this->config['_config']['root_table'];
        $joins = [];
        $joinedTables = [$rootTable];

        // Build JOINs for all foreign keys
        if (isset($this->config[$rootTable]['foreign_key'])) {
            foreach ($this->config[$rootTable]['foreign_key'] as $fkDef) {
                list($localCol, $refTableCol) = explode(':', $fkDef);
                list($refTable, $refCol) = explode('.', $refTableCol);

                if (!in_array($refTable, $joinedTables)) {
                    $joins[] = sprintf(
                        "LEFT JOIN source.%s ON source.%s.%s = source.%s.%s",
                        $refTable,
                        $rootTable,
                        $localCol,
                        $refTable,
                        $refCol
                    );
                    $joinedTables[] = $refTable;

                    // Check for 2nd level JOINs
                    if (isset($this->config[$refTable]['foreign_key'])) {
                        foreach ($this->config[$refTable]['foreign_key'] as $fkDef2) {
                            list($localCol2, $refTableCol2) = explode(':', $fkDef2);
                            list($refTable2, $refCol2) = explode('.', $refTableCol2);

                            if (!in_array($refTable2, $joinedTables)) {
                                $joins[] = sprintf(
                                    "LEFT JOIN source.%s ON source.%s.%s = source.%s.%s",
                                    $refTable2,
                                    $refTable,
                                    $localCol2,
                                    $refTable2,
                                    $refCol2
                                );
                                $joinedTables[] = $refTable2;
                            }
                        }
                    }
                }
            }
        }

        return implode("\n", $joins);
    }

    /**
     * Step 3: Build Entities from work.Records
     */
    private function buildEntitiesFromWorkRecords(?int $limit = null): void
    {
        $rootIdColumn = $this->config['_config']['root_id_column'];

        // Get display fields
        $displayFields = EavIndexing::getDisplayFields($this->config);

        // Build SELECT for display columns from source (not work.Records)
        // Display fields are not in work.Records, so we need to get them from source
        $rootTable = $this->config['_config']['root_table'];
        $displayColumns = array_map(fn($f) => $f['name'], $displayFields);

        // Use INSERT OR IGNORE to handle append mode where entities may already exist
        $sql = "INSERT OR IGNORE INTO cache.Entities (EntityValue, " . implode(', ', $displayColumns) . ")\n";
        $sql .= "SELECT {$rootIdColumn} AS EntityValue, " . implode(', ', $displayColumns) . "\n";
        $sql .= "FROM source.{$rootTable}\n";
        $sql .= "WHERE {$rootIdColumn} IN (SELECT Eid FROM Records)\n";
        $sql .= "ORDER BY {$rootIdColumn}";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            echo sprintf("  Inserted %s entities\n", number_format($count));
        }

        // In append mode, remove from Records any entities that already have EAV data
        // This prevents UNIQUE constraint violations when inserting EAV rows
        // Logic:
        //   - Records.Eid = source entity ID (mediaID)
        //   - cache.Entities.EntityValue = source entity ID (mediaID)
        //   - cache.Entities.Eid = internal auto-increment ID
        //   - cache.EAV.Eid = internal ID (references cache.Entities.Eid)
        // So we need to: DELETE FROM Records WHERE Records.Eid matches an EntityValue that has EAV rows
        $sql = "DELETE FROM Records WHERE Eid IN (
            SELECT e.EntityValue
            FROM cache.Entities e
            WHERE EXISTS (
                SELECT 1 FROM cache.EAV eav WHERE eav.Eid = e.Eid
            )
        )";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $deletedCount = $this->workDb->query('SELECT changes()')->fetchColumn();
            if ($deletedCount > 0) {
                echo sprintf("  Removed %s existing entities from work.Records (append mode)\n", number_format($deletedCount));
            }
        }
    }

    /**
     * Step 4: Build numeric EAV from work.Records (indexed reads)
     */
    private function buildNumericEavFromWorkRecords(): void
    {
        // Get numeric attributes
        $stmt = $this->cacheDb->query("SELECT Aid FROM Attributes WHERE DataType = 'numeric'");
        $numericAttrs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($numericAttrs)) {
            return;
        }

        // Build UNION ALL from Records (fast indexed reads)
        // Normalized schema: (Eid, Aid, Vid, VidOrder, ValueNumber)
        $unionParts = [];
        foreach ($numericAttrs as $attr) {
            $colName = "Aid_" . $attr['Aid'];
            $unionParts[] = sprintf(
                "SELECT Eid, %d AS Aid, NULL AS Vid, NULL AS VidOrder, %s AS ValueNumber FROM Records WHERE %s IS NOT NULL",
                $attr['Aid'],
                $colName,
                $colName
            );
        }

        $sql = "INSERT INTO cache.EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\n";
        $sql .= implode("\nUNION ALL\n", $unionParts);
        $sql .= "\nORDER BY Eid, Aid";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            echo sprintf("  Inserted %s numeric EAV rows\n", number_format($count));
        }
    }

    /**
     * Build text EAV by processing one attribute at a time
     * This avoids OOM from creating 59M row TokenLookup table
     *
     * Strategy:
     * 1. For each text attribute:
     *    a. Extract DISTINCT values from Records
     *    b. Insert into ValuesText (get Vids)
     *    c. Insert into EAV with resolved Vids
     * 2. Process attributes individually to avoid massive temp tables
     */
    private function buildTextEavByAttribute(): void
    {
        // Get text attributes with tokenization strategy
        $stmt = $this->cacheDb->query("SELECT Aid, ColumnName, TokenStrategy FROM Attributes WHERE DataType = 'text' ORDER BY Aid");
        $textAttrs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($textAttrs)) {
            return;
        }

        $totalAttrs = count($textAttrs);
        $processedAttrs = 0;

        foreach ($textAttrs as $attr) {
            $processedAttrs++;
            $aid = $attr['Aid'];
            $colName = "Aid_" . $aid;
            $attrName = $attr['ColumnName'];
            $strategy = $attr['TokenStrategy'] ?? 'whole';

            if (Environment::isCli()) {
                echo sprintf("    [%d/%d] Processing %s (Aid=%d, strategy=%s)...\n",
                    $processedAttrs, $totalAttrs, $attrName, $aid, $strategy);
            }

            $attrStart = microtime(true);

            if ($strategy === 'split') {
                // Handle :split tokenization (e.g., locality)
                $rowCount = $this->buildTextEavSplit($aid, $colName);
            } else {
                // Handle :whole tokenization (default)
                $rowCount = $this->buildTextEavWhole($aid, $colName);
            }

            $attrElapsed = microtime(true) - $attrStart;

            if (Environment::isCli()) {
                echo sprintf("      ✓ %s EAV rows inserted (%.2fs)\n",
                    number_format($rowCount), $attrElapsed);
            }
        }

        if (Environment::isCli()) {
            $totalValuesText = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            $totalEav = $this->cacheDb->query('SELECT COUNT(*) FROM EAV WHERE Vid IS NOT NULL')->fetchColumn();
            echo sprintf("    Total ValuesText: %s\n", number_format($totalValuesText));
            echo sprintf("    Total text EAV rows: %s\n", number_format($totalEav));
        }
    }

    /**
     * Build text EAV for :whole tokenization (store entire value as single Vid)
     * Normalized schema: one row per (Eid, Aid, Vid) with VidOrder = NULL
     */
    private function buildTextEavWhole(int $aid, string $colName): int
    {
        // Step 1: Extract DISTINCT values for this attribute and insert into ValuesText
        $this->workDb->exec("BEGIN TRANSACTION");

        $sql = "INSERT OR IGNORE INTO cache.ValuesText (ValueText)\n";
        $sql .= "SELECT DISTINCT {$colName} FROM Records WHERE {$colName} IS NOT NULL AND {$colName} != ''";
        $this->workDb->exec($sql);

        $this->workDb->exec("COMMIT");

        // Step 2: Insert into EAV by JOINing Records to ValuesText
        // Normalized schema: (Eid, Aid, Vid, VidOrder, ValueNumber)
        $this->workDb->exec("BEGIN TRANSACTION");

        $sql = "INSERT INTO cache.EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\n";
        $sql .= "SELECT r.Eid, {$aid}, vt.Vid, NULL, NULL\n";
        $sql .= "FROM Records r\n";
        $sql .= "JOIN cache.ValuesText vt ON r.{$colName} = vt.ValueText\n";
        $sql .= "WHERE r.{$colName} IS NOT NULL AND r.{$colName} != ''";

        $this->workDb->exec($sql);

        $rowCount = $this->workDb->query('SELECT changes()')->fetchColumn();

        $this->workDb->exec("COMMIT");

        return $rowCount;
    }

    /**
     * Build text EAV for :split tokenization (tokenize by whitespace, normalized schema)
     *
     * Strategy (Pure SQL with RECURSIVE CTE - NO PHP LOOPS):
     * 1. Use RECURSIVE CTE to split all text values into individual words with order
     * 2. Create indexes on TempSplitWords for fast JOINs
     * 3. INSERT DISTINCT words into ValuesText (elimination technique)
     * 4. INSERT into EAV (one row per token with VidOrder)
     *
     * Benefits:
     * - 100% SQL - no PHP loops at all!
     * - Elimination technique for DISTINCT words
     * - Indexed JOINs for maximum performance
     * - Normalized schema enables proper indexing
     * - Set-based operations throughout
     * - All SQL in template files (not embedded in code)
     * - Much faster than PHP tokenization or GROUP_CONCAT
     */
    private function buildTextEavSplit(int $aid, string $colName): int
    {
        $parser = new SqlTemplateParser();

        // Step 1: Create temp table for split words
        $sql = $parser->parse('images/eav/split_text/split_recursive_create_temp.sql');
        $this->workDb->exec($sql);

        // Step 2: Populate using RECURSIVE CTE to split text by whitespace
        if (Environment::isCli()) {
            echo sprintf("        Splitting text values using RECURSIVE CTE...\n");
        }

        $this->workDb->exec("BEGIN TRANSACTION");

        $sql = $parser->parse('images/eav/split_text/split_recursive_populate.sql', [
            'column_name' => $colName
        ]);
        $this->workDb->exec($sql);

        $this->workDb->exec("COMMIT");

        $totalWords = $this->workDb->query("SELECT COUNT(*) FROM TempSplitWords")->fetchColumn();

        if ($totalWords == 0) {
            $sql = $parser->parse('images/eav/split_text/split_recursive_cleanup.sql');
            $this->workDb->exec($sql);
            return 0;
        }

        if (Environment::isCli()) {
            $distinctWords = $this->workDb->query("SELECT COUNT(DISTINCT Word) FROM TempSplitWords")->fetchColumn();
            echo sprintf("        ✓ Split into %s words (%s distinct)\n",
                number_format($totalWords), number_format($distinctWords));
        }

        // Step 2.5: Create indexes on TempSplitWords for fast JOINs
        if (Environment::isCli()) {
            echo sprintf("        Creating indexes on TempSplitWords...\n");
        }
        $this->workDb->exec("CREATE INDEX idx_temp_split_word ON TempSplitWords(Word)");
        $this->workDb->exec("CREATE INDEX idx_temp_split_eid ON TempSplitWords(Eid)");
        $this->workDb->exec("CREATE INDEX idx_temp_split_eid_order ON TempSplitWords(Eid, WordOrder)");

        // Step 3: Insert DISTINCT words into ValuesText (elimination)
        $this->workDb->exec("BEGIN TRANSACTION");

        $sql = $parser->parse('images/eav/split_text/split_recursive_insert_distinct_words.sql');
        $this->workDb->exec($sql);

        $this->workDb->exec("COMMIT");

        // Step 4: INSERT into EAV (now uses indexes for fast JOIN)
        $this->workDb->exec("BEGIN TRANSACTION");

        $sql = $parser->parse('images/eav/split_text/split_recursive_insert_eav.sql', [
            'aid' => $aid
        ]);
        $this->workDb->exec($sql);

        $rowCount = $this->workDb->query('SELECT changes()')->fetchColumn();

        $this->workDb->exec("COMMIT");

        // Cleanup
        $sql = $parser->parse('images/eav/split_text/split_recursive_cleanup.sql');
        $this->workDb->exec($sql);

        return $rowCount;
    }



    /**
     * Step 5: Create TokenLookup from Records (LEGACY - causes OOM with 59M rows)
     * Creates temp table with (Eid, Aid, ValueText) - no Vid yet
     */
    private function createTokenLookupFromRecords(): void
    {
        // Get text attributes
        $stmt = $this->cacheDb->query("SELECT Aid FROM Attributes WHERE DataType = 'text'");
        $textAttrs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($textAttrs)) {
            return;
        }

        // Build UNION ALL of all text attribute values
        $unionParts = [];
        foreach ($textAttrs as $attr) {
            $colName = "Aid_" . $attr['Aid'];
            $unionParts[] = sprintf(
                "SELECT Eid, %d AS Aid, %s AS ValueText FROM Records WHERE %s IS NOT NULL AND %s != ''",
                $attr['Aid'],
                $colName,
                $colName,
                $colName
            );
        }

        // Create temp table with text values (no Vid yet)
        $sql = "CREATE TEMP TABLE TokenLookup (Eid INTEGER, Aid INTEGER, ValueText TEXT)";
        $this->workDb->exec($sql);

        $sql = "INSERT INTO TokenLookup (Eid, Aid, ValueText)\n";
        $sql .= implode("\nUNION ALL\n", $unionParts);

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT COUNT(*) FROM TokenLookup')->fetchColumn();
            echo sprintf("    Created TokenLookup with %s rows\n", number_format($count));
            echo "    Creating indexes on TokenLookup...\n";
        }

        // Create indexes BEFORE the UPDATE (much faster)
        $this->workDb->exec("CREATE INDEX idx_tokenlookup_valuetext ON TokenLookup(ValueText)");
        $this->workDb->exec("CREATE INDEX idx_tokenlookup_eid_aid ON TokenLookup(Eid, Aid)");

        if (Environment::isCli()) {
            echo "    Indexes created\n";
        }
    }

    /**
     * Step 6: Populate ValuesText from TokenLookup
     * Extracts DISTINCT text values from TokenLookup
     */
    private function buildValuesTextFromTokenLookup(): void
    {
        $sql = "INSERT OR IGNORE INTO cache.ValuesText (ValueText)\n";
        $sql .= "SELECT DISTINCT ValueText FROM TokenLookup";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->cacheDb->query('SELECT COUNT(*) FROM ValuesText')->fetchColumn();
            echo sprintf("    Inserted %s unique tokens into ValuesText\n", number_format($count));
        }
    }

    /**
     * Step 7: Translate TokenLookup from text to Vid
     * Adds Vid column and populates it by joining to ValuesText
     * Uses indexed lookup for fast translation
     */
    private function translateTokenLookupToVids(): void
    {
        // Add Vid column to TokenLookup
        $this->workDb->exec("ALTER TABLE TokenLookup ADD COLUMN Vid INTEGER");

        // Update Vid by joining to ValuesText (uses idx_tokenlookup_valuetext index)
        $sql = "UPDATE TokenLookup SET Vid = (\n";
        $sql .= "  SELECT Vid FROM cache.ValuesText WHERE ValueText = TokenLookup.ValueText\n";
        $sql .= ")";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT COUNT(*) FROM TokenLookup WHERE Vid IS NOT NULL')->fetchColumn();
            echo sprintf("    Translated %s tokens to Vids\n", number_format($count));
        }
    }

    /**
     * Step 7: Build text EAV from TokenLookup (indexed reads)
     */
    private function buildTextEavFromTokenLookup(): void
    {
        $sql = "INSERT INTO cache.EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\n";
        $sql .= "SELECT Eid, Aid, Vid, VidOrder, NULL AS ValueNumber\n";
        $sql .= "FROM TokenLookup\n";
        $sql .= "ORDER BY Eid, Aid, VidOrder";

        $this->workDb->exec($sql);

        if (Environment::isCli()) {
            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            echo sprintf("  Inserted %s text EAV rows\n", number_format($count));
        }
    }

    /**
     * Step 3: Build whole-text EAV using pure SQL (BATCHED OPTIMIZATION v2)
     * Combines whole-text and split-text token insertion in ONE query
     * Then inserts EAV rows separately
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     */
    private function buildWholeTextEavPureSQL(?int $limit = null): void
    {
        // Get whole-text attributes from config
        $indexedFields = EavIndexing::getIndexedFields($this->config);
        $wholeTextFields = array_filter($indexedFields, fn($f) => $f['strategy'] === 'whole');

        // Build array with Aid from Attributes table
        $wholeTextAttrs = [];
        foreach ($wholeTextFields as $field) {
            $stmt = $this->cacheDb->prepare("SELECT Aid FROM Attributes WHERE ColumnName = ? LIMIT 1");
            $stmt->execute([$field['name']]);
            $aid = $stmt->fetchColumn();

            if ($aid) {
                $wholeTextAttrs[] = [
                    'Aid' => $aid,
                    'TableName' => $field['table'],
                    'ColumnName' => $field['name']
                ];
            }
        }

        if (empty($wholeTextAttrs)) {
            return;
        }

        // Store for combined token insertion later
        $this->wholeTextAttrsCache = $wholeTextAttrs;
    }

    /**
     * Step 3B: Insert EAV rows for whole-text attributes
     * Called after combined token insertion in Step 3A
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     */
    private function buildWholeTextEavRowsPureSQL(?int $limit = null): void
    {
        if (empty($this->wholeTextAttrsCache)) {
            return;
        }

        if (Environment::isCli()) {
            echo sprintf("  Inserting EAV rows for %d whole-text attributes...\n", count($this->wholeTextAttrsCache));
        }

        $eavUnionParts = [];
        foreach ($this->wholeTextAttrsCache as $attr) {
            $sqls = $this->buildOptimizedEavSql(
                $attr['TableName'],
                $attr['ColumnName'],
                $attr['Aid'],
                'LOWER(TRIM({column}))',
                false,  // not numeric
                $limit
            );

            // Extract just the SELECT portion
            $eavSelect = $sqls['insert_eav_sql'];
            $eavSelect = preg_replace('/^INSERT INTO EAV \(Eid, Aid, Vid, VidOrder, ValueNumber\)\n/', '', $eavSelect);
            $eavSelect = preg_replace('/\nORDER BY Eid, Aid$/', '', $eavSelect);

            $eavUnionParts[] = $eavSelect;
        }

        $eavSql = "INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\n";
        $eavSql .= implode("\nUNION ALL\n", $eavUnionParts);
        $eavSql .= "\nORDER BY Eid, Aid";

        try {
            $this->cacheDb->exec($eavSql);

            if (Environment::isCli()) {
                $count = $this->cacheDb->query('SELECT changes()')->fetchColumn();
                echo sprintf("  Total whole-text EAV rows: %s\n", number_format($count));
            }
        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo sprintf("\n  ERROR in batched EAV insert:\n");
                echo sprintf("  SQL:\n%s\n", $eavSql);
                echo sprintf("  Error: %s\n\n", $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Step 4: Build split-text EAV using pure SQL (BATCHED OPTIMIZATION v2)
     * Caches split-text attributes for combined token insertion
     *
     * NOTE: For split strategy, we still store whole value for now
     * TODO: Implement actual token splitting in future iteration
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     */
    private function buildSplitTextEavPureSQL(?int $limit = null): void
    {
        // Get split-text attributes from config
        $indexedFields = EavIndexing::getIndexedFields($this->config);
        $splitTextFields = array_filter($indexedFields, fn($f) => $f['strategy'] === 'split');

        // Build array with Aid from Attributes table
        $splitTextAttrs = [];
        foreach ($splitTextFields as $field) {
            $stmt = $this->cacheDb->prepare("SELECT Aid FROM Attributes WHERE ColumnName = ? LIMIT 1");
            $stmt->execute([$field['name']]);
            $aid = $stmt->fetchColumn();

            if ($aid) {
                $splitTextAttrs[] = [
                    'Aid' => $aid,
                    'TableName' => $field['table'],
                    'ColumnName' => $field['name']
                ];
            }
        }

        if (empty($splitTextAttrs)) {
            return;
        }

        // Store for combined token insertion later
        $this->splitTextAttrsCache = $splitTextAttrs;
    }

    /**
     * Step 4B: Insert EAV rows for split-text attributes
     * Called after combined token insertion in Step 3A
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     */
    private function buildSplitTextEavRowsPureSQL(?int $limit = null): void
    {
        if (empty($this->splitTextAttrsCache)) {
            return;
        }

        if (Environment::isCli()) {
            echo sprintf("  Inserting EAV rows for %d split-text attributes...\n", count($this->splitTextAttrsCache));
        }

        $eavUnionParts = [];
        foreach ($this->splitTextAttrsCache as $attr) {
            $sqls = $this->buildOptimizedEavSql(
                $attr['TableName'],
                $attr['ColumnName'],
                $attr['Aid'],
                'LOWER(TRIM({column}))',
                false,  // not numeric
                $limit
            );

            // Extract just the SELECT portion
            $eavSelect = $sqls['insert_eav_sql'];
            $eavSelect = preg_replace('/^INSERT INTO EAV \(Eid, Aid, Vid, VidOrder, ValueNumber\)\n/', '', $eavSelect);
            $eavSelect = preg_replace('/\nORDER BY Eid, Aid$/', '', $eavSelect);

            $eavUnionParts[] = $eavSelect;
        }

        $eavSql = "INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\n";
        $eavSql .= implode("\nUNION ALL\n", $eavUnionParts);
        $eavSql .= "\nORDER BY Eid, Aid";

        try {
            $this->cacheDb->exec($eavSql);

            if (Environment::isCli()) {
                $count = $this->cacheDb->query('SELECT changes()')->fetchColumn();
                echo sprintf("  Total split-text EAV rows: %s\n", number_format($count));
            }
        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo sprintf("\n  ERROR in batched EAV insert:\n");
                echo sprintf("  SQL:\n%s\n", $eavSql);
                echo sprintf("  Error: %s\n\n", $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Step 3A: Insert ALL unique tokens (whole + split) in ONE query
     * This combines whole-text and split-text token insertion to avoid duplicate scans
     *
     * @param int|null $limit Limit number of entities to process (null = all)
     */
    private function buildCombinedTokensPureSQL(?int $limit = null): void
    {
        $allAttrs = array_merge(
            $this->wholeTextAttrsCache ?? [],
            $this->splitTextAttrsCache ?? []
        );

        if (empty($allAttrs)) {
            return;
        }

        if (Environment::isCli()) {
            echo sprintf("  Inserting unique tokens for %d text attributes (whole + split)...\n", count($allAttrs));
        }

        // Build UNION ALL of all token selects, then SELECT DISTINCT
        $tokenUnionParts = [];
        foreach ($allAttrs as $attr) {
            $sqls = $this->buildOptimizedEavSql(
                $attr['TableName'],
                $attr['ColumnName'],
                $attr['Aid'],
                'LOWER(TRIM({column}))',
                false,  // not numeric
                $limit
            );

            // Extract just the SELECT portion (without DISTINCT)
            $tokenSelect = $sqls['insert_tokens_sql'];
            $tokenSelect = preg_replace('/^INSERT OR IGNORE INTO ValuesText \(ValueText\)\n/', '', $tokenSelect);
            $tokenSelect = preg_replace('/^SELECT DISTINCT /', 'SELECT ', $tokenSelect);

            $tokenUnionParts[] = $tokenSelect;
        }

        // Use SELECT DISTINCT on outer query with UNION ALL for speed
        $tokenSql = "INSERT OR IGNORE INTO ValuesText (ValueText)\n";
        $tokenSql .= "SELECT DISTINCT * FROM (\n";
        $tokenSql .= implode("\nUNION ALL\n", $tokenUnionParts);
        $tokenSql .= "\n)";

        try {
            $this->cacheDb->exec($tokenSql);

            if (Environment::isCli()) {
                $count = $this->cacheDb->query('SELECT changes()')->fetchColumn();
                echo sprintf("  Unique tokens inserted: %s\n", number_format($count));
            }
        } catch (Exception $e) {
            if (Environment::isCli()) {
                echo sprintf("\n  ERROR in combined token insert:\n");
                echo sprintf("  SQL:\n%s\n", $tokenSql);
                echo sprintf("  Error: %s\n\n", $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Step 5: Tokenize and finalize using pure SQL
     */
    private function tokenizeAndFinalizePureSQL(): void
    {
        $parser = new SqlTemplateParser();
        $sql = $parser->parse('images/eav/step5_tokenize_and_finalize.sql');

        // Execute the multi-statement SQL
        $statements = SqlTemplateParser::parseString($sql);

        foreach ($statements as $stmt) {
            if (!empty(trim($stmt))) {
                $this->workDb->exec($stmt);
            }
        }
    }

    /**
     * Build complete SELECT-JOIN SQL for accessing a column from any table
     * Handles multi-level JOINs automatically
     *
     * NEW OPTIMIZED VERSION: Inserts directly into EAV with Vid translation per attribute
     *
     * @param string $tableName Target table name
     * @param string $columnName Column name to select
     * @param int $aid Attribute ID
     * @param string $selectExpr Expression for the column (e.g., "LOWER(TRIM({column}))" or just "{column}")
     * @param bool $isNumeric Whether this is a numeric column
     * @param int|null $limit Optional limit on number of entities
     * @return array Array with 'insert_tokens_sql' and 'insert_eav_sql' keys
     */
    private function buildOptimizedEavSql(string $tableName, string $columnName, int $aid, string $selectExpr, bool $isNumeric, ?int $limit = null): array
    {
        $rootTable = $this->config['_config']['root_table'];
        $rootIdColumn = $this->config['_config']['root_id_column'];

        // Build the JOIN path from root to target table
        $joinPath = $this->buildJoinPath($tableName);

        // Qualify column names with table name to avoid ambiguity
        $qualifiedColumn = $tableName . '.' . $columnName;
        $qualifiedRootId = $rootTable . '.' . $rootIdColumn;

        // Replace {column} placeholder in expression with qualified column
        $valueExpr = str_replace('{column}', $qualifiedColumn, $selectExpr);

        // Build LIMIT clause if specified
        $limitClause = '';
        if ($limit !== null) {
            $limitClause = sprintf(
                " AND %s IN (SELECT %s FROM source.%s ORDER BY %s LIMIT %d)",
                $qualifiedRootId,
                $rootIdColumn,
                $rootTable,
                $rootIdColumn,
                $limit
            );
        }

        if ($isNumeric) {
            // For numeric: insert directly into EAV (no cache. prefix - executing on cache.db)
            $insertEavSql = sprintf(
                "INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\nSELECT\n    %s AS Eid,\n    %d AS Aid,\n    NULL AS Vid,\n    NULL AS VidOrder,\n    %s AS ValueNumber\n%s\nWHERE %s IS NOT NULL%s\nORDER BY Eid, Aid",
                $qualifiedRootId,
                $aid,
                $valueExpr,
                $joinPath['from_join'],
                $qualifiedColumn,
                $limitClause
            );

            return [
                'insert_tokens_sql' => null,
                'insert_eav_sql' => $insertEavSql
            ];
        } else {
            // For text: first insert unique tokens, then insert EAV with Vid translation
            // No cache. prefix - executing on cache.db with source.db attached
            $insertTokensSql = sprintf(
                "INSERT OR IGNORE INTO ValuesText (ValueText)\nSELECT DISTINCT %s\n%s\nWHERE %s IS NOT NULL AND %s != ''%s",
                $valueExpr,
                $joinPath['from_join'],
                $qualifiedColumn,
                $qualifiedColumn,
                $limitClause
            );

            // For text: Vid from ValuesText, VidOrder=NULL (whole text strategy)
            $insertEavSql = sprintf(
                "INSERT INTO EAV (Eid, Aid, Vid, VidOrder, ValueNumber)\nSELECT\n    %s AS Eid,\n    %d AS Aid,\n    v.Vid AS Vid,\n    NULL AS VidOrder,\n    NULL AS ValueNumber\n%s\nINNER JOIN ValuesText v ON v.ValueText = %s\nWHERE %s IS NOT NULL AND %s != ''%s\nORDER BY Eid, Aid",
                $qualifiedRootId,
                $aid,
                $joinPath['from_join'],
                $valueExpr,
                $qualifiedColumn,
                $qualifiedColumn,
                $limitClause
            );

            return [
                'insert_tokens_sql' => $insertTokensSql,
                'insert_eav_sql' => $insertEavSql
            ];
        }
    }

    /**
     * Build JOIN path from root table to target table
     * Returns array with 'from_join' (FROM and JOIN clauses) and 'tables' (list of tables in path)
     */
    private function buildJoinPath(string $targetTable): array
    {
        $rootTable = $this->config['_config']['root_table'];
        $rootIdColumn = $this->config['_config']['root_id_column'];

        // If target is root table, no JOIN needed
        if ($targetTable === $rootTable) {
            return [
                'from_join' => sprintf("FROM source.%s", $rootTable),
                'tables' => [$rootTable]
            ];
        }

        // Try to find path from root to target
        // First, check direct relationship
        if (isset($this->config[$rootTable]['foreign_key'])) {
            foreach ($this->config[$rootTable]['foreign_key'] as $fkDef) {
                list($localCol, $refTableCol) = explode(':', $fkDef);
                list($refTable, $refCol) = explode('.', $refTableCol);

                if ($refTable === $targetTable) {
                    // Direct relationship: root → target
                    return [
                        'from_join' => sprintf(
                            "FROM source.%s\nINNER JOIN source.%s ON %s.%s = %s.%s",
                            $rootTable,
                            $targetTable,
                            $rootTable,
                            $localCol,
                            $targetTable,
                            $refCol
                        ),
                        'tables' => [$rootTable, $targetTable]
                    ];
                }

                // Check 2-level relationship: root → intermediate → target
                if (isset($this->config[$refTable]['foreign_key'])) {
                    foreach ($this->config[$refTable]['foreign_key'] as $fkDef2) {
                        list($localCol2, $refTableCol2) = explode(':', $fkDef2);
                        list($refTable2, $refCol2) = explode('.', $refTableCol2);

                        if ($refTable2 === $targetTable) {
                            // 2-level relationship: root → intermediate → target
                            return [
                                'from_join' => sprintf(
                                    "FROM source.%s\nINNER JOIN source.%s ON %s.%s = %s.%s\nINNER JOIN source.%s ON %s.%s = %s.%s",
                                    $rootTable,
                                    $refTable,
                                    $rootTable,
                                    $localCol,
                                    $refTable,
                                    $refCol,
                                    $targetTable,
                                    $refTable,
                                    $localCol2,
                                    $targetTable,
                                    $refCol2
                                ),
                                'tables' => [$rootTable, $refTable, $targetTable]
                            ];
                        }
                    }
                }
            }
        }

        // No path found - return just the target table (will likely cause error)
        return [
            'from_join' => sprintf("FROM source.%s", $targetTable),
            'tables' => [$targetTable]
        ];
    }

    /**
     * Populate TempMapping table in batches to avoid OOM
     * TempMapping maps Eid → RefId for 2-level JOINs
     */
    private function populateTempMappingBatched(
        string $rootTable,
        string $rootIdColumn,
        string $refTable,
        string $localCol,
        string $refCol,
        string $localCol2
    ): void {
        $batchSize = 100000;

        // Get min and max Eid from Records
        $minEid = $this->workDb->query("SELECT MIN(Eid) FROM Records")->fetchColumn();
        $maxEid = $this->workDb->query("SELECT MAX(Eid) FROM Records")->fetchColumn();

        if (!$minEid || !$maxEid) {
            return;
        }

        $totalInserted = 0;
        $currentEid = $minEid;
        $batchNum = 0;
        $totalBatches = ceil(($maxEid - $minEid + 1) / $batchSize);

        while ($currentEid <= $maxEid) {
            $batchNum++;
            $nextEid = $currentEid + $batchSize;

            // Insert batch into TempMapping
            $sql = "INSERT INTO TempMapping (Eid, RefId)\n";
            $sql .= "SELECT r.{$rootIdColumn} AS Eid, t.{$localCol2} AS RefId\n";
            $sql .= "FROM source.{$rootTable} r\n";
            $sql .= "JOIN source.{$refTable} t ON r.{$localCol} = t.{$refCol}\n";
            $sql .= "WHERE r.{$rootIdColumn} >= {$currentEid}\n";
            $sql .= "  AND r.{$rootIdColumn} < {$nextEid}\n";
            $sql .= "  AND t.{$localCol2} IS NOT NULL";

            $this->workDb->exec($sql);

            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            $totalInserted += $count;

            if (Environment::isCli() && $batchNum % 10 == 0) {
                echo sprintf("      Batch %d/%d: %s total rows\n",
                    $batchNum, $totalBatches, number_format($totalInserted));
            }

            $currentEid = $nextEid;
        }

        if (Environment::isCli()) {
            echo sprintf("      Total TempMapping rows: %s\n", number_format($totalInserted));
        }
    }

    /**
     * Execute UPDATE in batches to avoid OOM on large datasets (LEGACY - not used)
     * Processes 100K rows at a time using keyset pagination
     */
    private function executeBatchedUpdate(
        string $rootTable,
        string $rootIdColumn,
        string $refTable,
        string $localCol,
        string $refCol,
        array $setClauses
    ): void {
        $batchSize = 100000;

        // Get min and max Eid from Records
        $minEid = $this->workDb->query("SELECT MIN(Eid) FROM Records")->fetchColumn();
        $maxEid = $this->workDb->query("SELECT MAX(Eid) FROM Records")->fetchColumn();

        if (!$minEid || !$maxEid) {
            return; // No records
        }

        $totalUpdated = 0;
        $currentEid = $minEid;
        $batchNum = 0;
        $totalBatches = ceil(($maxEid - $minEid + 1) / $batchSize);

        if (Environment::isCli()) {
            echo sprintf("    Updating from %s in %d batches...\n", $refTable, $totalBatches);
        }

        while ($currentEid <= $maxEid) {
            $batchNum++;
            $nextEid = $currentEid + $batchSize;

            // Build UPDATE with Eid range filter
            $sql = "UPDATE Records SET " . implode(", ", $setClauses) . "\n";
            $sql .= "FROM source.{$rootTable} r\n";
            $sql .= "JOIN source.{$refTable} t ON r.{$localCol} = t.{$refCol}\n";
            $sql .= "WHERE Records.Eid = r.{$rootIdColumn}\n";
            $sql .= "  AND Records.Eid >= {$currentEid}\n";
            $sql .= "  AND Records.Eid < {$nextEid}";

            $this->workDb->exec($sql);

            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            $totalUpdated += $count;

            if (Environment::isCli() && $batchNum % 10 == 0) {
                echo sprintf("      Batch %d/%d: %s total updated\n",
                    $batchNum, $totalBatches, number_format($totalUpdated));
            }

            $currentEid = $nextEid;
        }

        if (Environment::isCli()) {
            echo sprintf("    Updated %s records total\n", number_format($totalUpdated));
        }
    }

    /**
     * Execute 2-level JOIN UPDATE in batches to avoid OOM
     * Uses TempMapping and TempDistinct tables created earlier
     */
    private function executeBatched2LevelUpdate(
        array $setClauses,
        string $refCol,
        int $distinctCount,
        string $refTable
    ): void {
        $batchSize = 100000;

        // Get min and max Eid from Records
        $minEid = $this->workDb->query("SELECT MIN(Eid) FROM Records")->fetchColumn();
        $maxEid = $this->workDb->query("SELECT MAX(Eid) FROM Records")->fetchColumn();

        if (!$minEid || !$maxEid) {
            return;
        }

        $totalUpdated = 0;
        $currentEid = $minEid;
        $batchNum = 0;
        $totalBatches = ceil(($maxEid - $minEid + 1) / $batchSize);

        if (Environment::isCli()) {
            echo sprintf("    Updating from %s in %d batches...\n", $refTable, $totalBatches);
        }

        while ($currentEid <= $maxEid) {
            $batchNum++;
            $nextEid = $currentEid + $batchSize;

            $sql = "UPDATE Records SET " . implode(", ", $setClauses) . "\n";
            $sql .= "FROM TempMapping tm\n";
            $sql .= "JOIN TempDistinct td ON tm.RefId = td.{$refCol}\n";
            $sql .= "WHERE Records.Eid = tm.Eid\n";
            $sql .= "  AND Records.Eid >= {$currentEid}\n";
            $sql .= "  AND Records.Eid < {$nextEid}";

            $this->workDb->exec($sql);

            $count = $this->workDb->query('SELECT changes()')->fetchColumn();
            $totalUpdated += $count;

            if (Environment::isCli() && $batchNum % 10 == 0) {
                echo sprintf("      Batch %d/%d: %s total updated\n",
                    $batchNum, $totalBatches, number_format($totalUpdated));
            }

            $currentEid = $nextEid;
        }

        if (Environment::isCli()) {
            echo sprintf("    Updated %s entity records from %s unique %s records\n",
                number_format($totalUpdated), number_format($distinctCount), $refTable);
        }
    }

    // ========================================================================
    // ImagesSearchInterface Implementation
    // ========================================================================

    /**
     * Search for images using EAV index
     *
     * @param array $params Search parameters
     * @return array Search results
     */
    public function search(array $params): array
    {
        try {
            // Get cache database path from config
            $dbPath = $params['db-path'] ?? $this->cacheDbPath;

            // Open database in read-only mode for safety and better concurrency
            $db = new PDO(sprintf('sqlite:%s', $dbPath), null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
            ]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Handle --parameters flag to list available search fields
            if (isset($params['parameters']) || isset($params['params'])) {
                return $this->listEavParameters($db);
            }

            // Handle query parameter - collect all positional args if --query not provided
            $query = $params['query'] ?? $params['search'] ?? '';

            // If query is an array (from repeated --query parameters), join them
            if (is_array($query)) {
                $query = implode(' ', $query);
            }

            // If no named query parameter, collect all positional arguments
            if (empty($query)) {
                $positionalArgs = [];
                for ($i = 0; isset($params[$i]); $i++) {
                    $positionalArgs[] = $params[$i];
                }
                $query = implode(' ', $positionalArgs);
            }

            // Handle multiple query conditions (AND/OR logic)
            $queryAnd = $params['queryAnd'] ?? [];
            $queryOr = $params['queryOr'] ?? [];

            // Ensure they are arrays
            if (!is_array($queryAnd)) {
                $queryAnd = [$queryAnd];
            }
            if (!is_array($queryOr)) {
                $queryOr = [$queryOr];
            }

            // Parse limit and offset parameters
            $limit = (int)($params['limit'] ?? 20);
            $offset = (int)($params['offset'] ?? 0);

            // Parse sortBy parameter (comma-separated list of fields)
            $sortBy = [];
            if (isset($params['sortBy'])) {
                $sortBy = array_map('trim', explode(',', $params['sortBy']));
            }

            // Validate that we have at least one query
            if (empty($query) && empty($queryAnd) && empty($queryOr)) {
                // Use centralized format detection from Environment singleton
                $format = $params['format'] ?? Environment::getInstance()->getResponseFormat();
                $isCli = ($format === 'cli');

                if ($isCli) {
                    return [
                        'type' => 'error',
                        'message' => 'Search query is required. Use --query, --queryAnd[], or --queryOr[]',
                        'status_code' => 400
                    ];
                } else {
                    // For web, return HTMX content with error message
                    return [
                        'type' => 'htmx',
                        'content' => '<div class="alert alert-warning" role="alert">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>No search query provided.</strong> Please add at least one search filter.
                        </div>'
                    ];
                }
            }

            // Search in EAV index
            $entities = $this->searchEavIndex($db, $query, $queryAnd, $queryOr, $limit, $offset, $sortBy);

            // Check format parameter (for testing) or Environment
            $isCli = ($params['format'] ?? null) === 'cli' || (Environment::isCli() && !isset($params['format']));

            if ($isCli) {
                // Build query description
                $queryDesc = [];
                if (!empty($query)) $queryDesc[] = $query;
                if (!empty($queryAnd)) $queryDesc[] = 'AND[' . implode(', ', $queryAnd) . ']';
                if (!empty($queryOr)) $queryDesc[] = 'OR[' . implode(', ', $queryOr) . ']';
                $queryStr = implode(' ', $queryDesc);

                $outputLines = [
                    sprintf("EAV search results for '%s' (%d found):", $queryStr, count($entities)),
                    str_repeat("-", 50)
                ];

                foreach ($entities as $entity) {
                    $details = [];

                    if (!empty($entity['sciName'])) $details[] = "Species: {$entity['sciName']}";
                    if (!empty($entity['collectionName'])) $details[] = "Collection: {$entity['collectionName']}";
                    if (!empty($entity['occid'])) $details[] = "OccID: {$entity['occid']}";
                    if (!empty($entity['catalogNumber'])) $details[] = "Catalog: {$entity['catalogNumber']}";
                    if (!empty($entity['eventDate'])) $details[] = "Date: {$entity['eventDate']}";

                    $detailsStr = !empty($details) ? ' (' . implode(', ', $details) . ')' : '';
                    $filename = basename($entity['url'] ?? 'Unknown');

                    $outputLines[] = sprintf("- %s%s", $filename, $detailsStr);

                    if (!empty($entity['url'])) {
                        $outputLines[] = sprintf("  Full Image: %s", $entity['url']);
                    }
                    if (!empty($entity['thumbnailUrl'])) {
                        $outputLines[] = sprintf("  Thumbnail: %s", $entity['thumbnailUrl']);
                    }
                    $outputLines[] = ""; // Add blank line between results
                }

                if (empty($entities)) {
                    $outputLines[] = "No images found matching the search criteria.";
                    $outputLines[] = "";
                    $outputLines[] = "Search tips:";
                    $outputLines[] = "  - Use field:value syntax (e.g., sciname:amanita, collectioncode:mich)";
                    $outputLines[] = "  - Search is case-insensitive";
                    $outputLines[] = "  - Prefix matching is used (e.g., 'aman' matches 'amanita')";
                }

                return [
                    'type' => 'success',
                    'content' => implode("\n", $outputLines)
                ];
            }

            // Convert EAV entities to image format for web display
            $images = $this->convertEavEntitiesToImages($entities);

            // Build load more URL with all query parameters
            $nextOffset = $offset + $limit;
            $queryParams = [
                'query' => $query,
                'limit' => $limit,
                'offset' => $nextOffset
            ];

            // Add queryAnd and queryOr if present
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

            // Add sortBy if present
            if (!empty($sortBy)) {
                $queryParams['sortBy'] = implode(',', $sortBy);
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
            $errorMsg = sprintf('EAV search failed: %s (File: %s, Line: %d)',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            if (Environment::isCli()) {
                return [
                    'type' => 'error',
                    'content' => $errorMsg
                ];
            }

            return [
                'type' => 'error',
                'message' => $errorMsg,
                'status_code' => 500
            ];
        }
    }

    /**
     * Autocomplete field names from EAV Attributes table
     *
     * @param array $params Autocomplete parameters
     * @return array Autocomplete results in HTMX format
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
                    'q' => $searchValue
                ]);
            } else {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="autocomplete-message">Type at least 2 characters...</div>'
                ];
            }
        }

        // Load field aliases from config
        // Use the config path from constructor
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
        $db = $this->getEavCacheConnection($params['db-path'] ?? null);
        if ($db) {
            try {
                // Get all searchable attributes (text and numeric, excluding display fields)
                $stmt = $db->query("
                    SELECT DISTINCT ColumnName, DataType
                    FROM Attributes
                    WHERE TokenStrategy != 'display'
                    ORDER BY ColumnName
                ");

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fieldName = $row['ColumnName'];
                    $fieldNameLower = strtolower($fieldName);

                    // Skip if already added as an alias
                    if (isset($addedFields[$fieldNameLower])) {
                        continue;
                    }

                    // Match against query
                    if (empty($query) || strpos($fieldNameLower, $query) !== false) {
                        $dataType = $row['DataType'];
                        $description = $this->getFieldDescription($fieldName, $fieldName);

                        $suggestions[] = [
                            'field' => $fieldName,
                            'maps_to' => $fieldName,
                            'description' => $description . " ($dataType)",
                            'is_alias' => false
                        ];

                        $addedFields[$fieldNameLower] = true;
                    }
                }
            } catch (Exception $e) {
                // If cache doesn't exist or has errors, just show aliases
            }
        }

        // Sort suggestions: aliases first, then alphabetically
        usort($suggestions, function($a, $b) {
            if ($a['is_alias'] != $b['is_alias']) {
                return $b['is_alias'] - $a['is_alias']; // Aliases first
            }
            return strcasecmp($a['field'], $b['field']);
        });

        // Limit to 20 suggestions (increased from 10 to show more options)
        $suggestions = array_slice($suggestions, 0, 20);

        if (empty($suggestions)) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">No matching fields found</div>'
            ];
        }

        // Generate HTML
        $html = '';
        foreach ($suggestions as $suggestion) {
            // Only show "maps to" arrow if it's an alias (field != maps_to)
            $mapsToHtml = '';
            if ($suggestion['is_alias'] && $suggestion['field'] !== $suggestion['maps_to']) {
                $mapsToHtml = sprintf(
                    '<div class="autocomplete-maps-to">→ %s</div>',
                    htmlspecialchars($suggestion['maps_to'])
                );
            }

            $field = htmlspecialchars($suggestion['field']);
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
     * Autocomplete field values from EAV cache
     *
     * @param array $params Autocomplete parameters
     * @return array Autocomplete results in HTMX format
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

        // Get cache database connection
        $db = $this->getEavCacheConnection($params['db-path'] ?? null);
        if (!$db) {
            return [
                'type' => 'htmx',
                'content' => '<div class="autocomplete-message">Search index not available</div>'
            ];
        }

        try {
            // Try to use specialized autocomplete index first (much faster)
            $suggestions = $this->autocompleteFromIndex($field, $query, $limit, $db);

            // Fallback to direct EAV query if no specialized index exists
            if ($suggestions === null) {
                // Query for matching values with counts
                // Schema: EAV(Eid, Aid, Vid, ValueNumber), ValuesText(Vid, ValueText), Attributes(Aid, ColumnName)
                // Use LOWER() for case-insensitive comparison while preserving original case for display
                // Use LOWER() for attribute name matching to handle "collectioncode" vs "collectionCode"
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

                $suggestions = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $suggestions[] = [
                        'value' => $row['value'],
                        'count' => (int)$row['count']
                    ];
                }
            }

            if (empty($suggestions)) {
                return [
                    'type' => 'htmx',
                    'content' => '<div class="autocomplete-message">No suggestions found</div>'
                ];
            }

            // Generate HTML
            $html = '';
            foreach ($suggestions as $suggestion) {
                $fieldEscaped = htmlspecialchars($field);
                $valueEscaped = htmlspecialchars($suggestion['value']);
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
                    $suggestion['count'],
                    $suggestion['count'] === 1 ? 'image' : 'images'
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
     * Check if EAV cache is available and valid
     *
     * @return bool True if cache exists and is valid
     */
    public function isAvailable(): bool
    {
        if (empty($this->cacheDbPath)) {
            // Try to get from Configuration
            $config = Configuration::getInstance();
            $this->cacheDbPath = $config->get('components.images.images_cache_db', '');
        }

        if (empty($this->cacheDbPath) || !file_exists($this->cacheDbPath)) {
            return false;
        }

        try {
            $db = new PDO('sqlite:' . $this->cacheDbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check if Config table exists
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Config'");
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the search mode identifier
     *
     * @return string Always returns 'eav'
     */
    public function getSearchMode(): string
    {
        return 'eav';
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

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
            'taxon' => 'Scientific name (binomial/species name)',
            'sciname' => 'Scientific name (binomial/species name)',
            'scientificName' => 'Scientific name (binomial/species name)',
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
     * Get PDO connection to EAV cache database
     *
     * @param string|null $dbPath Optional custom database path (for testing)
     * @return PDO|null PDO connection or null if unavailable
     */
    private function getEavCacheConnection(?string $dbPath = null): ?PDO
    {
        // If a custom path is provided (for testing), use it directly
        if ($dbPath !== null) {
            if (!file_exists($dbPath)) {
                return null;
            }

            try {
                $db = new PDO("sqlite:$dbPath");
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $db;
            } catch (Exception $e) {
                error_log("Failed to connect to EAV cache: " . $e->getMessage());
                return null;
            }
        }

        // Use the configured cache path
        if (empty($this->cacheDbPath) || !file_exists($this->cacheDbPath)) {
            return null;
        }

        try {
            $db = new PDO("sqlite:" . $this->cacheDbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $db;
        } catch (Exception $e) {
            error_log("Failed to connect to EAV cache: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Autocomplete collection values (placeholder for now)
     *
     * @param string $searchValue Search value
     * @return array HTMX response
     */
    private function autocompleteCollectionValues(string $searchValue): array
    {
        // TODO: Implement collection value autocomplete
        return [
            'type' => 'htmx',
            'content' => '<div class="autocomplete-message">Collection autocomplete not yet implemented</div>'
        ];
    }

    /**
     * Autocomplete from specialized index tables
     * Routes to appropriate index based on field type
     *
     * @param string $field Field name
     * @param string $query Search query
     * @param int $limit Result limit
     * @param PDO $db Database connection
     * @return array|null Suggestions array or null if no specialized index exists
     */
    private function autocompleteFromIndex(string $field, string $query, int $limit, PDO $db): ?array
    {
        // Map fields to their autocomplete index tables
        $indexMap = [
            'taxon' => 'autocomplete_taxon',
            'sciname' => 'autocomplete_taxon',
            'scientificname' => 'autocomplete_taxon',
            'collection' => 'autocomplete_collection',
            'collectionname' => 'autocomplete_collection',
            'collectioncode' => 'autocomplete_collection',
            'institutioncode' => 'autocomplete_collection',
            'collid' => 'autocomplete_collection',
            'county' => 'autocomplete_location',
            'country' => 'autocomplete_location',
            'stateprovince' => 'autocomplete_location',
            'state' => 'autocomplete_location',
            'date' => 'autocomplete_date',
            'eventdate' => 'autocomplete_date',
        ];

        $indexTable = $indexMap[strtolower($field)] ?? null;
        if (!$indexTable) {
            return null; // No specialized index for this field
        }

        try {
            // Build query based on index type
            // Note: We deduplicate by value and take MAX count (since same taxon may appear in multiple source fields)
            // Use LOWER() for case-insensitive comparison while preserving original case for display
            if ($indexTable === 'autocomplete_taxon') {
                $sql = "SELECT taxon_name as value, MAX(image_count) as count,
                               GROUP_CONCAT(DISTINCT source_field) as field
                        FROM autocomplete_taxon
                        WHERE LOWER(taxon_name) LIKE LOWER(:query)
                        GROUP BY taxon_name
                        ORDER BY count DESC, taxon_name ASC
                        LIMIT :limit";
            } elseif ($indexTable === 'autocomplete_collection') {
                // Filter by source_field to show only values from the requested attribute
                // e.g., collectioncode should only show collectionCode values, not institutionCode
                $sql = "SELECT collection_value as value, image_count as count, source_field as field
                        FROM autocomplete_collection
                        WHERE LOWER(collection_value) LIKE LOWER(:query)
                        AND LOWER(source_field) = LOWER(:field)
                        ORDER BY image_count DESC, collection_value ASC
                        LIMIT :limit";
            } elseif ($indexTable === 'autocomplete_location') {
                $sql = "SELECT location_value as value, MAX(image_count) as count,
                               GROUP_CONCAT(DISTINCT source_field) as field
                        FROM autocomplete_location
                        WHERE LOWER(location_value) LIKE LOWER(:query)
                        GROUP BY location_value
                        ORDER BY count DESC, location_value ASC
                        LIMIT :limit";
            } elseif ($indexTable === 'autocomplete_date') {
                $sql = "SELECT year as value, image_count as count, 'eventDate' as field
                        FROM autocomplete_date
                        WHERE year LIKE :query
                        ORDER BY year DESC
                        LIMIT :limit";
            } else {
                return null;
            }

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

            // Bind :field parameter for autocomplete_collection queries
            if ($indexTable === 'autocomplete_collection') {
                $stmt->bindValue(':field', $field, PDO::PARAM_STR);
            }

            $stmt->execute();

            $suggestions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $suggestions[] = [
                    'value' => $row['value'],
                    'count' => (int)$row['count']
                ];
            }

            return $suggestions;

        } catch (Exception $e) {
            // Index table doesn't exist or query failed
            return null;
        }
    }

    // ========================================================================
    // Search Helper Methods
    // ========================================================================

    /**
     * Search the EAV index for entities matching the query
     */
    private function searchEavIndex(PDO $db, string $query, array $queryAnd, array $queryOr, int $limit, int $offset, array $sortBy): array
    {
        // Collect all entity IDs from different query types
        $entityIdSets = [];

        // Process main query (use higher limit for AND/OR combinations)
        // Use 10000 as a reasonable max to avoid memory issues
        $maxQueryLimit = 10000;
        $queryLimit = (!empty($queryAnd) || !empty($queryOr)) ? $maxQueryLimit : ($limit + $offset);

        if (!empty($query)) {
            $entityIdSets['main'] = $this->executeEavQuery($db, $query, $queryLimit);
        }

        // Process AND queries (use higher limit - we need all results for intersection)
        if (!empty($queryAnd)) {
            foreach ($queryAnd as $andQuery) {
                $results = $this->executeEavQuery($db, $andQuery, $maxQueryLimit);
                $entityIdSets['and_' . md5($andQuery)] = $results;
            }
        }

        // Process OR queries (use higher limit - we need all results for union)
        if (!empty($queryOr)) {
            $orEntityIds = [];
            foreach ($queryOr as $orQuery) {
                $orResults = $this->executeEavQuery($db, $orQuery, $maxQueryLimit);
                $orEntityIds = array_merge($orEntityIds, $orResults);
            }
            if (!empty($orEntityIds)) {
                $entityIdSets['or'] = array_unique($orEntityIds);
            }
        }

        // Combine results based on logic
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
                // Union with OR results
                $finalEntityIds = array_unique(array_merge($finalEntityIds, $entityIds));
            }
        }

        if (empty($finalEntityIds)) {
            return [];
        }

        // If sorting is requested, we need to load more data before limiting
        // Otherwise, apply limit to entity IDs to prevent memory issues
        if (!empty($sortBy)) {
            // Load all entities (up to maxQueryLimit) for sorting
            $loadLimit = min(count($finalEntityIds), $maxQueryLimit);
            $entitiesToLoad = array_slice($finalEntityIds, 0, $loadLimit);
            $entities = $this->getEavEntities($db, $entitiesToLoad);

            // Sort the entities
            $entities = $this->sortEavEntities($entities, $sortBy);

            // Apply offset and limit after sorting
            return array_slice($entities, $offset, $limit);
        } else {
            // No sorting - apply limit to entity IDs before loading
            $limitedEntityIds = array_slice($finalEntityIds, $offset, $limit);
            return $this->getEavEntities($db, $limitedEntityIds);
        }
    }

    /**
     * Execute a single EAV query and return entity IDs
     */
    private function executeEavQuery(PDO $db, string $query, int $limit): array
    {
        // Load field aliases from config
        static $aliases = null;
        if ($aliases === null) {
            $aliases = $this->loadFieldAliases();
        }

        // Parse field:value syntax
        $parts = explode(':', $query, 2);
        if (count($parts) === 2) {
            $fieldName = trim($parts[0]);
            $searchValue = trim($parts[1]);

            // Resolve alias to actual field name
            $fieldName = $aliases[strtolower($fieldName)] ?? $fieldName;

            // Check if this is a numeric field (case-insensitive)
            $isNumeric = $this->isNumericField($db, $fieldName);

            if ($isNumeric) {
                // Numeric field search
                // Use LOWER() for case-insensitive attribute name matching
                $sql = "SELECT DISTINCT e.Eid
                        FROM Entities e
                        JOIN EAV eav ON e.Eid = eav.Eid
                        JOIN Attributes a ON eav.Aid = a.Aid
                        WHERE LOWER(a.ColumnName) = LOWER(:fieldName)
                        AND eav.ValueNumber = :search
                        LIMIT :limit";

                $stmt = $db->prepare($sql);
                $stmt->bindValue(':fieldName', $fieldName, PDO::PARAM_STR);
                // Use float for decimals (coordinates), int for IDs
                $stmt->bindValue(':search', (float)$searchValue, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } else {
                // Text field search with normalized EAV schema
                // Simple JOIN on Vid - fast and indexed!
                // Use LOWER() for case-insensitive attribute name matching
                // Use LOWER() for case-insensitive value search
                $sql = "SELECT DISTINCT e.Eid, COUNT(DISTINCT eav.Vid) as relevance
                        FROM Entities e
                        JOIN EAV eav ON e.Eid = eav.Eid
                        JOIN Attributes a ON eav.Aid = a.Aid
                        JOIN ValuesText v ON eav.Vid = v.Vid
                        WHERE LOWER(a.ColumnName) = LOWER(:fieldName)
                        AND LOWER(v.ValueText) LIKE LOWER(:search)
                        GROUP BY e.Eid
                        ORDER BY relevance DESC
                        LIMIT :limit";

                $stmt = $db->prepare($sql);
                $stmt->bindValue(':fieldName', $fieldName, PDO::PARAM_STR);
                // Substring match to find value anywhere in the text (e.g., "bernardii" matches "Agaricus bernardii")
                $stmt->bindValue(':search', '%' . $searchValue . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            }
        } else {
            // Keyword search across all text fields (case-insensitive)
            $sql = "SELECT DISTINCT e.Eid, COUNT(DISTINCT eav.Vid) as relevance
                    FROM Entities e
                    JOIN EAV eav ON e.Eid = eav.Eid
                    JOIN ValuesText v ON eav.Vid = v.Vid
                    WHERE LOWER(v.ValueText) LIKE LOWER(:search)
                    GROUP BY e.Eid
                    ORDER BY relevance DESC
                    LIMIT :limit";

            $stmt = $db->prepare($sql);
            // Substring match to find value anywhere in the text
            $stmt->bindValue(':search', '%' . $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    }

    /**
     * Get full entity data from EAV index
     * Handles normalized schema: reassembles :split attributes using VidOrder
     */
    private function getEavEntities(PDO $db, array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

        // First, get display fields from Entities table
        // Dynamically detect which columns exist to support different schemas
        $columnsQuery = $db->query("PRAGMA table_info(Entities)");
        $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN, 1);
        $selectColumns = implode(', ', $columns);

        $displaySql = sprintf("
            SELECT %s
            FROM Entities
            WHERE Eid IN (%s)
        ", $selectColumns, $placeholders);

        $stmt = $db->prepare($displaySql);
        $stmt->execute($entityIds);
        $displayRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize entities with display fields
        $entities = [];
        foreach ($displayRows as $row) {
            $entities[$row['Eid']] = $row;
        }

        // Query EAV data with VidOrder to reassemble split tokens
        $sql = sprintf("
            SELECT
                e.Eid,
                a.ColumnName,
                a.TokenStrategy,
                eav.VidOrder,
                COALESCE(v.ValueText, CAST(eav.ValueNumber AS TEXT)) as Value
            FROM Entities e
            JOIN EAV eav ON e.Eid = eav.Eid
            JOIN Attributes a ON eav.Aid = a.Aid
            LEFT JOIN ValuesText v ON eav.Vid = v.Vid
            WHERE e.Eid IN (%s)
            ORDER BY e.Eid, a.ColumnName, eav.VidOrder
        ", $placeholders);

        $stmt = $db->prepare($sql);
        $stmt->execute($entityIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add EAV attributes to entities and reassemble split attributes
        foreach ($rows as $row) {
            $eid = $row['Eid'];
            $columnName = $row['ColumnName'];
            $value = $row['Value'];
            $tokenStrategy = $row['TokenStrategy'];

            // Handle :split attributes - reassemble tokens in order
            if ($tokenStrategy === 'split' && $row['VidOrder'] !== null) {
                if (!isset($entities[$eid][$columnName])) {
                    $entities[$eid][$columnName] = [];
                }
                $entities[$eid][$columnName][] = $value;
            } else {
                // :whole or numeric - single value
                $entities[$eid][$columnName] = $value;
            }
        }

        // Join split attribute tokens back into strings
        foreach ($entities as &$entity) {
            foreach ($entity as $key => &$value) {
                if (is_array($value)) {
                    $value = implode(' ', $value);
                }
            }
        }

        return array_values($entities);
    }

    /**
     * Convert EAV entities to image format
     * Uses ONLY original database column names - no artificial field names
     */
    private function convertEavEntitiesToImages(array $entities): array
    {
        $images = [];

        foreach ($entities as $entity) {
            // Use ONLY original column names from database
            // Cast mediaID to int to avoid float formatting (802566.0 -> 802566)
            // EntityValue contains the mediaID
            $mediaID = $entity['mediaID'] ?? $entity['EntityValue'] ?? $entity['Eid'] ?? 0;
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
     * Unified method to render images with load more button
     * Used by both random (stream) and EAV search to avoid code duplication
     *
     * @param array $images Array of image data (already formatted with mediaID, thumbnailUrl, etc.)
     * @param int $limit Number of images per page
     * @param int $offset Current offset
     * @param string $loadMoreUrl URL for load more button (with query params)
     * @param string|null $query Original search query (for no results message)
     * @return array HTMX response array
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
     * List available EAV search parameters
     */
    private function listEavParameters(PDO $db): array
    {
        $sql = "SELECT DISTINCT ColumnName FROM Attributes ORDER BY ColumnName";
        $stmt = $db->query($sql);
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (Environment::isCli()) {
            $output = ["Available search fields:", str_repeat("-", 50)];
            foreach ($fields as $field) {
                $output[] = "  - $field";
            }
            return [
                'type' => 'success',
                'content' => implode("\n", $output)
            ];
        }

        return [
            'type' => 'htmx',
            'content' => '<div>' . implode(', ', $fields) . '</div>'
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
     * Sort EAV entities by specified fields
     */
    private function sortEavEntities(array $entities, array $sortBy): array
    {
        if (empty($sortBy)) {
            return $entities;
        }

        usort($entities, function($a, $b) use ($sortBy) {
            foreach ($sortBy as $field) {
                $aVal = $a[$field] ?? '';
                $bVal = $b[$field] ?? '';
                $cmp = strcasecmp($aVal, $bVal);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return 0;
        });

        return $entities;
    }

    /**
     * Check if a field is numeric (case-insensitive attribute name lookup)
     */
    private function isNumericField(PDO $db, string $fieldName): bool
    {
        try {
            // Check DataType column (current schema) - case-insensitive
            $sql = "SELECT DataType FROM Attributes WHERE LOWER(ColumnName) = LOWER(:fieldName) LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':fieldName', $fieldName, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return $result === 'numeric';
        } catch (PDOException $e) {
            // Fallback: check if IsNumeric column exists (older schema) - case-insensitive
            try {
                $sql = "SELECT IsNumeric FROM Attributes WHERE LOWER(ColumnName) = LOWER(:fieldName) LIMIT 1";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':fieldName', $fieldName, PDO::PARAM_STR);
                $stmt->execute();
                $result = $stmt->fetchColumn();
                return $result == 1;
            } catch (PDOException $e2) {
                // If that also fails, assume not numeric
                return false;
            }
        }
    }

    /**
     * Load field aliases from config
     */
    private function loadFieldAliases(): array
    {
        $aliases = [];
        if (isset($this->config['aliases'])) {
            foreach ($this->config['aliases'] as $alias => $actualField) {
                $aliases[strtolower($alias)] = $actualField;
            }
        }
        return $aliases;
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

