<?php

namespace Symbiota\Helpers\Models;

use PDO;
use Exception;

/**
 * Flat Index Builder for Images Search (Option B - Normalized with Indexes)
 *
 * Builds a flat index by copying source.db tables and adding comprehensive indexes.
 * Strategy:
 *   1. Copy tables from source.db (media, omoccurrences, omcollections, taxa)
 *   2. Create indexes on all searchable fields
 *   3. Build autocomplete tables
 *   4. No data duplication - same size as source.db with added indexes
 *
 * @version 2.0.0
 * @author Philip J Anders <anders2@illinois.edu>
 * @license NCSA
 */
class ImagesModelFlatIndex
{
    private array $config;
    private string $configPath;
    private array $tablesToCopy;
    private array $indexesToCreate;

    /**
     * Constructor
     *
     * @param array $params Configuration parameters
     */
    public function __construct(array $params = [])
    {
        $this->configPath = $params['config_path'] ?? __DIR__ . '/../../templates/sql/images/flat/index_config.ini';

        if (!file_exists($this->configPath)) {
            throw new Exception("Config file not found: {$this->configPath}");
        }

        // Load configuration
        $this->config = parse_ini_file($this->configPath, true);

        // Get tables to copy
        $this->tablesToCopy = $this->config['tables']['tables'] ?? ['media', 'omoccurrences', 'omcollections', 'taxa'];

        // Get indexes to create
        $this->indexesToCreate = $this->config['indexes']['indexes'] ?? [];
    }

    /**
     * Build flat search index from source database (Option B)
     *
     * Strategy:
     *   1. Copy tables from source.db into flat_index.db
     *   2. Create comprehensive indexes on all searchable fields
     *   3. Build autocomplete tables
     *
     * @param string $sourceDbPath Path to source.db
     * @param string $outputDbPath Path to output flat index database
     * @param int|null $limit Optional limit for testing
     * @param bool $append Whether to append to existing database
     * @return array Build statistics
     */
    public function buildIndex(string $sourceDbPath, string $outputDbPath, ?int $limit = null, bool $append = false): array
    {
        if (!file_exists($sourceDbPath)) {
            throw new Exception("Source database not found: $sourceDbPath");
        }

        // CRITICAL SAFETY CHECK: Prevent overwriting source database
        $sourceRealPath = realpath($sourceDbPath);
        $outputRealPath = file_exists($outputDbPath) ? realpath($outputDbPath) : realpath(dirname($outputDbPath)) . '/' . basename($outputDbPath);

        if ($sourceRealPath === $outputRealPath) {
            throw new Exception("FATAL ERROR: Output path is the same as source database!\n" .
                              "  Source: $sourceDbPath\n" .
                              "  Output: $outputDbPath\n" .
                              "This would destroy your source data. Please specify a different --output-file.");
        }

        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Building Flat Index (Normalized with Indexes)\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        $startTime = microtime(true);

        // Create or open output database
        if (!$append && file_exists($outputDbPath)) {
            echo "Removing existing flat index...\n";
            unlink($outputDbPath);
        }

        $flatDb = new PDO("sqlite:$outputDbPath");
        $flatDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Enable WAL mode for better write performance
        echo "Configuring SQLite for optimal performance...\n";
        $flatDb->exec("PRAGMA journal_mode=WAL");
        $flatDb->exec("PRAGMA synchronous=NORMAL");
        $flatDb->exec("PRAGMA cache_size=10000");
        $flatDb->exec("PRAGMA temp_store=MEMORY");

        // Attach source database BEFORE executing any templates
        echo "Attaching source.db...\n";
        echo "  Source path: $sourceDbPath\n";

        // Verify source database exists and is readable
        if (!is_readable($sourceDbPath)) {
            throw new Exception("Source database is not readable: $sourceDbPath");
        }

        $escapedSourcePath = str_replace("'", "''", $sourceDbPath);
        $flatDb->exec("ATTACH DATABASE '$escapedSourcePath' AS source");

        // Verify attachment worked by checking for tables
        $tables = $flatDb->query("SELECT name FROM source.sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo "  Found " . count($tables) . " tables in source.db: " . implode(', ', array_slice($tables, 0, 5)) . (count($tables) > 5 ? '...' : '') . "\n";

        if (!in_array('media', $tables)) {
            throw new Exception("Source database does not contain 'media' table. Available tables: " . implode(', ', $tables));
        }

        // Step 1: Copy tables from source.db
        echo "\n📦 Step 1: Copying tables from source.db\n";
        echo "─────────────────────────────────────────────────────────────\n";
        $this->executeSqlTemplate($flatDb, 'copy_tables.sql', [
            'limit' => $limit ? "LIMIT $limit" : ""
        ]);

        $mediaCount = $flatDb->query("SELECT COUNT(*) FROM media")->fetchColumn();
        $occCount = $flatDb->query("SELECT COUNT(*) FROM omoccurrences")->fetchColumn();
        $collCount = $flatDb->query("SELECT COUNT(*) FROM omcollections")->fetchColumn();
        $taxaCount = $flatDb->query("SELECT COUNT(*) FROM taxa")->fetchColumn();

        echo "  ✓ Copied media: " . number_format($mediaCount) . " rows\n";
        echo "  ✓ Copied omoccurrences: " . number_format($occCount) . " rows\n";
        echo "  ✓ Copied omcollections: " . number_format($collCount) . " rows\n";
        echo "  ✓ Copied taxa: " . number_format($taxaCount) . " rows\n";

        // Step 2: Create indexes
        echo "\n🔍 Step 2: Creating indexes on searchable fields\n";
        echo "─────────────────────────────────────────────────────────────\n";
        $this->executeSqlTemplate($flatDb, 'create_indexes.sql');
        echo "  ✓ Created indexes on all searchable fields\n";

        // Step 3: Build inverted indexes (triple store concept)
        echo "\n🔗 Step 3: Building inverted indexes\n";
        echo "─────────────────────────────────────────────────────────────\n";

        // Build taxon inverted index
        $this->executeSqlTemplate($flatDb, 'create_taxon_inverted_index.sql');
        $taxonTokenCount = $flatDb->query("SELECT COUNT(*) FROM TaxonTokens")->fetchColumn();
        $taxonIndexCount = $flatDb->query("SELECT COUNT(*) FROM OccurrenceTaxonIndex")->fetchColumn();
        echo "  ✓ TaxonTokens: " . number_format($taxonTokenCount) . " distinct values\n";
        echo "  ✓ OccurrenceTaxonIndex: " . number_format($taxonIndexCount) . " occid→token mappings\n";

        // Build location inverted index
        $this->executeSqlTemplate($flatDb, 'create_location_inverted_index.sql');
        $locationTokenCount = $flatDb->query("SELECT COUNT(*) FROM LocationTokens")->fetchColumn();
        $locationIndexCount = $flatDb->query("SELECT COUNT(*) FROM OccurrenceLocationIndex")->fetchColumn();
        echo "  ✓ LocationTokens: " . number_format($locationTokenCount) . " distinct values\n";
        echo "  ✓ OccurrenceLocationIndex: " . number_format($locationIndexCount) . " occid→token mappings\n";

        // Build collection inverted index (OPTIMIZED with CollectionLookup)
        $this->executeSqlTemplate($flatDb, 'create_collection_lookup_optimized.sql');
        $collectionLookupCount = $flatDb->query("SELECT COUNT(*) FROM CollectionLookup")->fetchColumn();
        $collectionIndexCount = $flatDb->query("SELECT COUNT(*) FROM OccurrenceCollectionIndex")->fetchColumn();
        $collectionTokenCount = $flatDb->query("SELECT COUNT(*) FROM CollectionTokens")->fetchColumn();
        echo "  ✓ CollectionLookup: " . number_format($collectionLookupCount) . " unique collections\n";
        echo "  ✓ OccurrenceCollectionIndex: " . number_format($collectionIndexCount) . " occid→collid mappings\n";
        echo "  ✓ CollectionTokens: " . number_format($collectionTokenCount) . " distinct values (for autocomplete)\n";

        // Detach source database
        $flatDb->exec("DETACH DATABASE source");

        // Run ANALYZE for query optimization
        echo "\n📊 Running ANALYZE for query optimization...\n";
        $flatDb->exec("ANALYZE");

        $buildTime = microtime(true) - $startTime;

        // Get statistics
        $stats = $this->getIndexStats($outputDbPath);
        $stats['build_time'] = $buildTime;

        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✅ Flat index built successfully in " . number_format($buildTime, 2) . " seconds\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        return $stats;
    }

    /**
     * Execute SQL template with placeholder substitution
     *
     * @param PDO $db Database connection
     * @param string $templateFile Template filename (relative to templates/sql/images/flat/)
     * @param array $placeholders Placeholder values to substitute
     * @return void
     */
    private function executeSqlTemplate(PDO $db, string $templateFile, array $placeholders = []): void
    {
        $templatePath = dirname($this->configPath) . '/' . $templateFile;

        if (!file_exists($templatePath)) {
            throw new Exception("SQL template not found: {$templatePath}");
        }

        $sql = file_get_contents($templatePath);

        // Substitute placeholders
        foreach ($placeholders as $key => $value) {
            $sql = str_replace('{' . $key . '}', $value, $sql);
        }

        // Remove comments and split into individual statements
        $lines = explode("\n", $sql);
        $statements = [];
        $currentStatement = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }

            $currentStatement .= ' ' . $line;

            // Check if statement is complete (ends with semicolon)
            if (substr($line, -1) === ';') {
                $statements[] = trim($currentStatement);
                $currentStatement = '';
            }
        }

        // Execute each statement separately
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $db->exec($statement);
                } catch (Exception $e) {
                    throw new Exception("SQL execution failed: " . $e->getMessage() . "\nStatement: " . substr($statement, 0, 200));
                }
            }
        }
    }

    /**
     * Get index statistics
     *
     * @param string $dbPath Path to flat index database
     * @return array Statistics
     */
    public function getIndexStats(string $dbPath): array
    {
        if (!file_exists($dbPath)) {
            throw new Exception("Flat index database not found: $dbPath");
        }

        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stats = [];
        $stats['database_path'] = $dbPath;
        $stats['database_size'] = filesize($dbPath);

        // Get table counts
        $stats['total_records'] = $db->query("SELECT COUNT(*) FROM media")->fetchColumn();
        $stats['total_occurrences'] = $db->query("SELECT COUNT(*) FROM omoccurrences")->fetchColumn();
        $stats['total_collections'] = $db->query("SELECT COUNT(*) FROM omcollections")->fetchColumn();
        $stats['total_taxa'] = $db->query("SELECT COUNT(*) FROM taxa")->fetchColumn();

        // Get distinct field counts from omoccurrences
        $fields = ['family', 'genus', 'sciname', 'scientificname', 'country', 'stateProvince', 'county', 'locality', 'catalogNumber'];
        foreach ($fields as $field) {
            try {
                $count = $db->query("SELECT COUNT(DISTINCT $field) FROM omoccurrences WHERE $field IS NOT NULL AND $field != ''")->fetchColumn();
                $stats['distinct_' . $field] = $count;
            } catch (Exception $e) {
                // Field might not exist
                $stats['distinct_' . $field] = 0;
            }
        }

        // Get distinct collection fields
        $collFields = ['collectionCode', 'institutionCode', 'collectionName'];
        foreach ($collFields as $field) {
            try {
                $count = $db->query("SELECT COUNT(DISTINCT $field) FROM omcollections WHERE $field IS NOT NULL AND $field != ''")->fetchColumn();
                $stats['distinct_' . $field] = $count;
            } catch (Exception $e) {
                $stats['distinct_' . $field] = 0;
            }
        }

        // Get autocomplete table counts if they exist
        $autocompleteTables = ['AutocompleteTaxon', 'AutocompleteLocation', 'AutocompleteCollection', 'AutocompleteCatalog'];
        foreach ($autocompleteTables as $table) {
            try {
                $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                $stats['autocomplete_' . strtolower(str_replace('Autocomplete', '', $table))] = $count;
            } catch (Exception $e) {
                // Table might not exist
            }
        }

        return $stats;
    }
}

