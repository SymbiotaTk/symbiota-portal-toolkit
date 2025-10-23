<?php

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\ProgressSpinner;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateEngine;
use Exception;

/**
 * GenBank Model - Specimen Data Queries
 *
 * Handles specimen data queries with automatic discovery and help generation.
 * Supports catalog number search, occid lookup, and collection listing.
 *
 * @version 3.0.0
 * @author Symbiota Portal Helpers
 */
class GenBankModel extends Model
{
    // GenBank is a public read-only service - no authentication required
    protected bool $requiresAuth = false;
    protected array $publicActions = ['index', 'help', 'catalogNumber', 'occid', 'collections', 'test', 'status', 'demo'];

    /**
     * Get the model's routing resource ID
     */
    public static function getModelId(): string
    {
        return 'genbank';
    }

    /**
     * Get the module type classification
     */
    public static function getModuleType(): ModuleType
    {
        return ModuleType::COMPONENT;
    }

    /**
     * Get the model's help documentation in markdown format
     */
    public static function getHelpMarkdown(): string
    {
        // Get the entry file name dynamically
        $entryFile = basename(ApplicationPaths::applicationFilename());

        // Load help content from template
        $templatePath = sprintf('%s/cli/help/genbank_model.txt', ApplicationPaths::templatesDirectory());
        if (!file_exists($templatePath)) {
            return 'Help documentation not found.';
        }

        $helpContent = file_get_contents($templatePath);

        // Use TemplateEngine for variable interpolation
        $templateEngine = new TemplateEngine();
        return $templateEngine->render($helpContent, ['entry_file' => $entryFile]);
    }

    /**
     * Get model priority for ordering
     */
    public static function getPriority(): int
    {
        return 10; // High priority - primary tool
    }

    /**
     * Initialize SQL query templates as fallback (lazy)
     */
    protected function initializeTemplates(): void
    {
        // Register inline SQL templates as fallback if files don't exist
        try {
            // Test if template files exist by trying to load one
            $catalogSearchPath = sprintf('%s/sql/genbank/catalog_search.sql', ApplicationPaths::templatesDirectory());
            if (file_exists($catalogSearchPath)) {
                // Template files exist, no need to register inline templates
                return;
            }
        } catch (\RuntimeException $e) {
            // Continue to fallback registration
        }

        // Files don't exist, register inline templates as fallback
        $this->registerTemplate('sql', 'catalog_search', $this->getCatalogSearchTemplate());
        $this->registerTemplate('sql', 'occid_search', $this->getOccidSearchTemplate());
        $this->registerTemplate('sql', 'collections_list', $this->getCollectionsListTemplate());
    }
    
    /**
     * Execute specific actions for this model
     */
    protected function executeAction(string $action, array $route, array $params): array
    {


        switch ($action) {
            case 'catalogNumber':
                // Route contains route element names after model and action (e.g., ['163172'])
                $catalogNumber = $route[0] ?? '';
                if (empty($catalogNumber)) {
                    throw new \InvalidArgumentException('Catalog number is required');
                }
                $collectionIds = $params['collid'] ?? [];
                if (is_string($collectionIds)) {
                    $collectionIds = explode(',', $collectionIds);
                }
                return $this->searchByCatalogNumber($catalogNumber, $collectionIds);

            case 'occid':
                // Route contains route element names after model and action (e.g., ['22877'])
                $occid = (int)($route[0] ?? 0);
                if ($occid <= 0) {
                    throw new \InvalidArgumentException('Valid occid is required');
                }
                return $this->searchByOccid($occid);

            case 'collections':
                $limit = (int)($params['limit'] ?? 100);
                $sort = $params['sort'] ?? [];
                $filter = $params['filter'] ?? [];

                // Parse sort parameter if it's a string (from CLI)
                if (is_string($sort)) {
                    $sort = $this->parseSortParameter($sort);
                }

                // Parse filter parameter if it's a string (from CLI)
                if (is_string($filter)) {
                    $filter = $this->parseFilterParameter($filter);
                }

                // Determine format based on URL format or request type
                $requestedFormat = $params['format'] ?? null;

                // Simple explicit format checking - no header detection
                if ($requestedFormat === 'htmx' ||
                    $requestedFormat === 'html-partial' ||
                    $requestedFormat === 'html') {
                    // Return HTML options for select dropdown
                    return $this->getCollectionsOptions($params);
                } else {
                    // Return JSON data (default)
                    return $this->getCollections($limit, $sort, $filter);
                }

            case 'test':
                return $this->testConnection();

            case 'status':
                return $this->getGenBankStatus($params);

            case 'demo':
                return $this->demoProgressSpinner();

            // HTMX Frontend Routes
            case 'main':
                return $this->getMainPage();

            case 'search':
                // Handle both GET (form display) and POST (search execution)
                if ($this->requestMethod === 'POST') {
                    return $this->handleSearch($params);
                } else {
                    return $this->getSearchForm('catalog');
                }

            case 'form':
                $formType = $route[0] ?? 'catalog';
                return $this->getSearchForm($formType);

            case 'collection-name':
                return $this->getCollectionName($params);

            case 'clear-results':
                return $this->getClearResults();

            case 'devForm':
                return $this->getDevForm();

            case 'fragment':
                return $this->getSecureFragment($params);

            case 'index':
            default:
                // Default action - show main page for web requests, help for CLI
                if (Environment::isCli()) {
                    // For CLI, return help information
                    return $this->cliResponse(static::generateCliHelp());
                } else {
                    // For web requests, show main page
                    return $this->getMainPage();
                }
        }
    }

    /**
     * Search by catalog number with optional collection filtering
     */
    public function searchByCatalogNumber(string $catalogNumber, array $collectionIds = []): array
    {
        // Validate collection selection first (business logic validation)
        if (empty($collectionIds) && $this->isDatabaseAvailable()) {
            return $this->errorResponse('Collection selection required', 400, [
                'message' => 'Catalog number searches require exact collection selection. Please select a collection first.',
                'operation' => 'catalogNumber',
                'query' => $catalogNumber,
                'suggestion' => 'Use the collection dropdown to select a specific collection before searching'
            ]);
        }

        return $this->executeWithFallback(
            'catalogNumber',
            function() use ($catalogNumber, $collectionIds) {
                // Database operation
                $connection = $this->getDatabaseConnection();
                if (!$connection) {
                    throw new Exception('Database connection failed');
                }

                // Build collection filter clause (required for catalog searches)
                $placeholders = str_repeat('?,', count($collectionIds) - 1) . '?';
                $collectionFilter = sprintf(' AND o.collid IN (%s)', $placeholders);
                // Use LIKE pattern for partial catalog number matching
                $catalogPattern = sprintf('%%%s%%', $catalogNumber);
                $params = array_merge([$catalogPattern], $collectionIds);
                $types = sprintf('s%s', str_repeat('i', count($collectionIds)));

                // Render SQL using template (lazy) - limit to 1 result for exact matching
                $sql = $this->renderTemplate('catalog_search', TemplateFormat::SQL, [
                    'collection_filter' => $collectionFilter,
                    'limit' => 1
                ]);

                // Execute query with progress tracking
                $this->startProgress(sprintf('Searching for catalog number: %s', $catalogNumber));

                $stmt = $connection->prepare($sql);
                if (!$stmt) {
                    $this->failProgress('Query preparation failed');
                    throw new Exception(sprintf('Prepare failed: %s', $connection->error));
                }

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();

                $records = [];
                while ($row = $result->fetch_assoc()) {
                    $records[] = $row;
                }

                $this->succeedProgress(sprintf('Found %d records', count($records)));

                return $this->successResponse([
                    'search_type' => 'catalogNumber',
                    'query' => $catalogNumber,
                    'filters' => ['collid' => $collectionIds],
                    'results' => $records,
                    'database_status' => 'connected'
                ]);
            },
            function() use ($catalogNumber, $collectionIds) {
                // Fixture fallback
                $config = \Symbiota\Helpers\Core\Configuration::getInstance();
                $appDir = $config ? $config->getAppDirectory() : __DIR__ . '/../..';
                require_once sprintf('%s/spec/fixtures/GenBankFixtures.php', $appDir);
                $records = \Symbiota\Helpers\Spec\Fixtures\GenBankFixtures::getOccurrencesByCatalogNumber($catalogNumber, $collectionIds);

                return $this->successResponse([
                    'search_type' => 'catalogNumber',
                    'query' => $catalogNumber,
                    'filters' => ['collid' => $collectionIds],
                    'results' => $records,
                    'database_status' => 'fixtures',
                    'source' => 'fixtures'
                ]);
            }
        );
    }
    
    /**
     * Search by unique occid (Symbiota primary key)
     */
    public function searchByOccid(int $occid): array
    {
        if (!$this->isDatabaseAvailable()) {
            return $this->errorResponse('Database unavailable', 503, [
                'operation' => 'occid',
                'query' => $occid
            ]);
        }

        try {
            $connection = $this->getDatabaseConnection();
            if (!$connection) {
                return $this->errorResponse('Database connection failed', 503, [
                    'operation' => 'occid',
                    'query' => $occid
                ]);
            }

            // Render SQL using template (lazy)
            $sql = $this->renderTemplate('occid_search', TemplateFormat::SQL, []);

            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                throw new Exception(sprintf('Prepare failed: %s', $connection->error));
            }

            $stmt->bind_param('i', $occid);
            $stmt->execute();
            $result = $stmt->get_result();

            $records = [];
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }

            return $this->successResponse([
                'search_type' => 'occid',
                'query' => $occid,
                'results' => $records,
                'database_status' => 'connected'
            ]);

        } catch (Exception $e) {
            $this->logError(sprintf('Database query failed: %s', $e->getMessage()));
            return $this->errorResponse('Database query failed', 500, [
                'operation' => 'occid',
                'query' => $occid,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Parse sort parameter from string format to array
     */
    private function parseSortParameter(string $sort): array
    {
        if (empty($sort)) {
            return [];
        }

        $sortArray = [];
        $sortPairs = explode(',', $sort);

        foreach ($sortPairs as $pair) {
            $parts = explode(':', trim($pair));
            if (count($parts) >= 2) {
                $field = trim($parts[0]);
                $direction = strtolower(trim($parts[1]));
                if (in_array($direction, ['asc', 'desc'])) {
                    $sortArray[$field] = $direction;
                }
            }
        }

        return $sortArray;
    }

    /**
     * Parse filter parameter from string format to array
     */
    private function parseFilterParameter(string $filter): array
    {
        if (empty($filter)) {
            return [];
        }

        $filterArray = [];
        $filterParts = explode(':', $filter);

        if (count($filterParts) >= 2) {
            $field = trim($filterParts[0]);
            $operator = trim($filterParts[1]);
            $value = count($filterParts) > 2 ? trim($filterParts[2]) : '';

            $filterArray[$field] = [
                'operator' => $operator,
                'value' => $value
            ];
        }

        return $filterArray;
    }

    /**
     * Get list of available collections with sorting and filtering (secure with prepared statements)
     */
    public function getCollections(int $limit = 100, array $sort = [], array $filter = []): array
    {
        if (!$this->isDatabaseAvailable()) {
            return $this->errorResponse('Database unavailable', 503, [
                'operation' => 'collections'
            ]);
        }

        try {
            $connection = $this->getDatabaseConnection();
            if (!$connection) {
                return $this->errorResponse('Database connection failed', 503, [
                    'operation' => 'collections'
                ]);
            }

            // Define valid fields for security
            $validFields = ['collid', 'collectionName', 'institutionCode', 'collectionCode'];

            // Build secure WHERE clause for collections (supports multi-field search)
            $filterResult = $this->buildCollectionsFilterClause($filter, $validFields);
            $whereClause = $filterResult['clause'];
            $parameters = $filterResult['parameters'];
            $paramTypes = $filterResult['types'];

            // Add AND prefix if we have additional conditions
            if (!empty($whereClause) && $whereClause !== '1=1') {
                $whereClause = ' AND ' . $whereClause;
            } else {
                $whereClause = ''; // No additional conditions
            }

            // Build ORDER BY clause
            $orderByClause = $this->buildSortClause($sort, $validFields, 'ORDER BY c.collectionName ASC');

            // Render SQL using template with values
            $sql = $this->renderTemplate('collections_list', TemplateFormat::SQL, [
                'limit' => $limit,
                'where_clause' => $whereClause,
                'order_by_clause' => $orderByClause
            ]);

            // Execute query with progress tracking
            $this->startProgress('Loading collections...');

            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                throw new Exception(sprintf('Prepare failed: %s', $connection->error));
            }

            if (!empty($parameters)) {
                $stmt->bind_param($paramTypes, ...$parameters);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$result) {
                throw new Exception(sprintf('Query failed: %s', $connection->error));
            }

            $collections = [];
            while ($row = $result->fetch_assoc()) {
                $collections[] = [
                    'collid' => (int)$row['collid'],
                    'collectionName' => $row['collectionName'],
                    'institutionCode' => $row['institutionCode'],
                    'collectionCode' => $row['collectionCode']
                ];
            }

            $this->succeedProgress(sprintf('Loaded %d collections', count($collections)));

            return $this->successResponse([
                'collections' => $collections,
                'database_status' => 'connected'
            ]);

        } catch (Exception $e) {
            $this->failProgress('Database query failed');
            $this->logError(sprintf('Database query failed: %s', $e->getMessage()));
            return $this->errorResponse('Database query failed', 500, [
                'operation' => 'collections',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get collections as HTML options for dropdown
     */
    public function getCollectionsOptions(array $params = []): array
    {
        // Process filter for case-insensitive search across all collection fields
        $filter = [];
        if (!empty($params['filter'])) {
            $filterValue = is_string($params['filter']) ? $params['filter'] : '';
            if (!empty($filterValue)) {
                // Create filter spec for case-insensitive LIKE search across all fields
                // Format: value:matchType:logicType:fieldName (empty fieldName means search all fields)
                $filter = [sprintf('%s:like:or:', $filterValue)];
            }
        }

        $collections = $this->getCollections(
            limit: (int)($params['limit'] ?? 200),
            sort: $params['sort'] ?? [],
            filter: $filter
        );

        // Check if we got a successful response with collections data
        if ($collections['type'] !== 'success' || !isset($collections['content']['collections'])) {
            return [
                'type' => 'htmx',
                'content' => '<option value="">Error loading collections</option>'
            ];
        }

        $optionsList = [];
        foreach ($collections['content']['collections'] as $collection) {
            $collid = $collection['collid'];
            $name = htmlspecialchars($collection['collectionName']);
            $institution = htmlspecialchars($collection['institutionCode'] ?? '');
            $code = htmlspecialchars($collection['collectionCode'] ?? '');

            $displayName = $name;
            if ($institution) {
                $displayName = sprintf('%s (%s%s)',
                    $displayName,
                    $institution,
                    $code ? sprintf(' - %s', $code) : ''
                );
            }

            $optionsList[] = sprintf('<option value="%d">%s</option>', $collid, $displayName);
        }

        // Build options using template
        $options = $this->renderTemplate('options', TemplateFormat::HTML, [
            'options_list' => implode("\n", $optionsList)
        ]);

        // Prepend the default option
        $fullOptions = sprintf('<option value="-1">Select a collection...</option>%s%s', "\n", $options);

        return $this->htmxResponse($fullOptions);
    }





    /**
     * Test database connection and get detailed status
     */
    public function testConnection(): array
    {
        if (!$this->isDatabaseAvailable()) {
            return $this->errorResponse('Database unavailable', 503, [
                'operation' => 'test',
                'suggestion' => 'Check database configuration'
            ]);
        }

        try {
            $connection = $this->getDatabaseConnection();
            if (!$connection) {
                return $this->errorResponse('Database connection failed', 503, [
                    'operation' => 'test',
                    'suggestion' => 'Check database configuration'
                ]);
            }

            // Test with a simple query
            $result = $connection->query('SELECT 1 as test');
            if (!$result) {
                return $this->errorResponse('Database query test failed', 503, [
                    'operation' => 'test',
                    'error' => $connection->error
                ]);
            }

            return $this->successResponse([
                'database_status' => 'connected',
                'test_query' => 'passed',
                'server_info' => $connection->server_info
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Database test failed', 500, [
                'operation' => 'test',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get GenBank module configuration and status
     */
    public function getGenBankStatus(array $params): array
    {
        $config = Configuration::getInstance();

        // Get configuration values
        $genbankConfig = $config ?
            ($config->get('components.genbank') ?? $config->get('genbank') ?? $config->get('mod.genbank') ?? []) :
            [];

        $status = [
            'configuration' => [
                'module_enabled' => true,
                'database_available' => $this->isDatabaseAvailable(),
                'template_path' => ApplicationPaths::templatesDirectory() . '/sql/genbank',
                'cache_enabled' => $genbankConfig['cache_enabled'] ?? false,
                'max_results' => $genbankConfig['max_results'] ?? 1000
            ],
            'paths' => [
                'templates_absolute' => realpath(ApplicationPaths::templatesDirectory() . '/sql/genbank') ?: ApplicationPaths::templatesDirectory() . '/sql/genbank',
                'templates_exist' => is_dir(ApplicationPaths::templatesDirectory() . '/sql/genbank'),
                'templates_readable' => is_readable(ApplicationPaths::templatesDirectory() . '/sql/genbank')
            ],
            'database' => [
                'connection_available' => $this->isDatabaseAvailable(),
                'connection_type' => 'readonly',
                'test_query_status' => 'unknown'
            ],
            'security' => [
                'readonly_mode' => true,
                'prepared_statements' => true,
                'input_validation' => true
            ]
        ];

        // Test database connection if available
        if ($this->isDatabaseAvailable()) {
            try {
                $connection = $this->getDatabaseConnection();
                if ($connection) {
                    $result = $connection->query('SELECT 1 as test');
                    $status['database']['test_query_status'] = $result ? 'passed' : 'failed';
                    $status['database']['server_info'] = $connection->server_info ?? 'unknown';
                }
            } catch (Exception $e) {
                $status['database']['test_query_status'] = 'failed';
                $status['database']['error'] = $e->getMessage();
            }
        }

        if (Environment::isCli()) {
            $output = ["GenBank Module Status", str_repeat("=", 50)];

            $output[] = "\nConfiguration:";
            foreach ($status['configuration'] as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'YES' : 'NO') : $value;
                $output[] = sprintf("  %-25s: %s", $key, $displayValue);
            }

            $output[] = "\nPaths:";
            foreach ($status['paths'] as $key => $value) {
                if (str_ends_with($key, '_absolute')) continue; // Skip absolute paths in CLI
                $displayValue = is_bool($value) ? ($value ? 'YES' : 'NO') : $value;
                $output[] = sprintf("  %-25s: %s", $key, $displayValue);
            }

            $output[] = "\nDatabase:";
            foreach ($status['database'] as $key => $value) {
                if ($key === 'error') continue; // Skip error in normal display
                $displayValue = is_bool($value) ? ($value ? 'YES' : 'NO') : $value;
                $output[] = sprintf("  %-25s: %s", $key, $displayValue);
            }

            $output[] = "\nSecurity:";
            foreach ($status['security'] as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'YES' : 'NO') : $value;
                $output[] = sprintf("  %-25s: %s", $key, $displayValue);
            }

            // Add warnings if needed
            if (!$status['database']['connection_available']) {
                $output[] = "";
                $output[] = "⚠️  WARNING: Database connection not available!";
                $output[] = "   GenBank searches will use fixture data only.";
                $output[] = "   Please check database configuration.";
            }

            return [
                'type' => 'success',
                'content' => implode("\n", $output)
            ];
        }

        return [
            'type' => 'json',
            'data' => $status
        ];
    }

    /**
     * Demo progress spinner functionality
     */
    public function demoProgressSpinner(): array
    {
        // Demo 1: Basic progress tracking
        $operationId = $this->startProgress('Initializing demo...');
        sleep(1);
        $this->updateProgressMessage($operationId, 'Loading data...');
        sleep(1);
        $this->updateProgressMessage($operationId, 'Processing records...');
        sleep(1);
        $this->succeedProgress($operationId, 'Demo completed successfully!');

        echo "\n";

        // Demo 2: Progress bar simulation
        echo "Progress bar demo:\n";
        $operationId2 = $this->startProgress('Starting progress bar demo...');
        for ($i = 0; $i <= 10; $i++) {
            $this->updateProgressMessage($operationId2, sprintf('Step %d/10', $i), ($i * 10));
            usleep(200000); // 200ms
        }
        $this->succeedProgress($operationId2, 'Progress bar demo completed!');

        echo "\n";

        // Demo 3: Different completion states
        $this->startProgress('Testing success...');
        sleep(1);
        $this->succeedProgress('Success!');

        $this->startProgress('Testing warning...');
        sleep(1);
        $this->warnProgress('Warning message');

        $this->startProgress('Testing info...');
        sleep(1);
        $this->infoProgress('Information message');

        $this->startProgress('Testing failure...');
        sleep(1);
        $this->failProgress('Error message');

        return $this->successResponse([
            'message' => 'Progress spinner demo completed',
            'features' => [
                'Basic spinner with messages',
                'Progress bar for known totals',
                'Success/warning/info/error states',
                'Elapsed time tracking'
            ]
        ]);
    }

    // Database helper methods removed - now handled by Model base class

    // Template loading method removed - now handled by Model base class renderTemplate method

    /**
     * Get catalog search SQL template
     */
    private function getCatalogSearchTemplate(): string
    {
        return <<<'SQL'
SELECT
    o.occid,
    o.catalogNumber,
    o.collid,
    c.collectionName,
    o.scientificName,
    o.locality,
    o.decimalLatitude,
    o.decimalLongitude,
    o.eventDate,
    o.recordedBy,
    o.family
FROM omoccurrences o
LEFT JOIN omcollections c ON o.collid = c.collid
WHERE o.catalogNumber = ?
{collection_filter}
ORDER BY o.collid, o.occid
LIMIT {limit|100}
SQL;
    }

    /**
     * Get occid search SQL template
     */
    private function getOccidSearchTemplate(): string
    {
        return <<<'SQL'
SELECT
    o.occid,
    o.catalogNumber,
    o.collid,
    c.collectionName,
    o.scientificName,
    o.family,
    o.locality,
    o.stateProvince,
    o.country,
    o.decimalLatitude,
    o.decimalLongitude,
    o.eventDate,
    o.recordedBy,
    o.recordNumber
FROM omoccurrences o
LEFT JOIN omcollections c ON o.collid = c.collid
WHERE o.occid = ?
LIMIT 1
SQL;
    }

    /**
     * Build secure filter clause specifically for collections (supports multi-field search)
     */
    private function buildCollectionsFilterClause(array $filter, array $validFields): array
    {
        $clauses = [];
        $parameters = [];
        $types = '';

        foreach ($filter as $filterSpec) {
            if (!is_string($filterSpec)) {
                continue;
            }

            // Parse filter format: "value:matchType:logicType:fieldName"
            // Example: "denver:like:or:" (empty fieldName means search all fields)
            $parts = explode(':', $filterSpec);
            if (count($parts) < 3) {
                continue;
            }

            $value = $parts[0];
            $matchType = $parts[1]; // like, exact, etc.
            $logicType = $parts[2]; // and, or
            $fieldName = $parts[3] ?? ''; // specific field or empty for all fields

            if (empty($value)) {
                continue;
            }

            if ($matchType === 'like') {
                $searchValue = sprintf('%%%s%%', $value);

                if (empty($fieldName)) {
                    // Search across all valid fields
                    $fieldClauses = [];
                    foreach ($validFields as $field) {
                        $fieldClauses[] = sprintf('c.%s LIKE ?', $field);
                        $parameters[] = $searchValue;
                        $types .= 's';
                    }

                    if (!empty($fieldClauses)) {
                        $clauses[] = sprintf('(%s)', implode(' OR ', $fieldClauses));
                    }
                } else {
                    // Search specific field
                    if (in_array($fieldName, $validFields)) {
                        $clauses[] = sprintf('c.%s LIKE ?', $fieldName);
                        $parameters[] = $searchValue;
                        $types .= 's';
                    }
                }
            } elseif ($matchType === 'exact') {
                if (empty($fieldName)) {
                    // Exact search across all valid fields
                    $fieldClauses = [];
                    foreach ($validFields as $field) {
                        $fieldClauses[] = sprintf('c.%s = ?', $field);
                        $parameters[] = $value;
                        $types .= 's';
                    }

                    if (!empty($fieldClauses)) {
                        $clauses[] = sprintf('(%s)', implode(' OR ', $fieldClauses));
                    }
                } else {
                    // Exact search specific field
                    if (in_array($fieldName, $validFields)) {
                        $clauses[] = sprintf('c.%s = ?', $fieldName);
                        $parameters[] = $value;
                        $types .= 's';
                    }
                }
            }
        }

        return [
            'clause' => empty($clauses) ? '1=1' : implode(' AND ', $clauses),
            'parameters' => $parameters,
            'types' => $types
        ];
    }

    /**
     * Get collections list SQL template (secure with prepared statement placeholders)
     */
    private function getCollectionsListTemplate(): string
    {
        return <<<'SQL'
SELECT DISTINCT
    c.collid,
    c.collectionName,
    c.institutionCode,
    c.collectionCode
FROM omcollections c
INNER JOIN omoccurrences o ON c.collid = o.collid
WHERE c.colltype = 'Preserved Specimens'
{where_clause}
{order_by_clause|ORDER BY c.collectionName ASC}
LIMIT {limit}
SQL;
    }

    /**
     * Get main HTMX page
     */
    public function getMainPage(): array
    {
        // Get the main content using the content template (loads forms dynamically)
        $mainContent = $this->renderTemplate('content', TemplateFormat::HTML, [
            'app_url_prefix' => $this->getAppUrlPrefix()
        ]);

        return $this->htmlResponse($mainContent);
    }

    /**
     * Get search form based on type
     */
    public function getSearchForm(string $formType): array
    {
        // For fragment requests, return just the form content
        $route = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($route, 'fragment') !== false) {
            $template = match ($formType) {
                'catalog' => 'search_form_catalog',
                'occid' => 'search_form_occid',
                default => 'search_form_catalog'
            };

            return $this->htmxResponse($this->renderTemplate($template, TemplateFormat::HTML, [
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]));
        }

        // For full page requests, return the main form
        return $this->htmlResponse($this->renderTemplate('main_form', TemplateFormat::HTML, [
            'app_url_prefix' => $this->getAppUrlPrefix()
        ]), 'layout');
    }

    /**
     * Override parameter validation for HTMX endpoints and CLI parameter normalization
     */
    public function validateParameters(array $params): array
    {
        // Skip validation for HTMX-specific endpoints
        $route = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($route, 'collection-name') !== false ||
            strpos($route, 'clear-results') !== false ||
            strpos($route, 'fragment') !== false ||
            strpos($route, 'catalog') !== false ||
            strpos($route, 'occid') !== false) {
            return []; // No validation errors for these endpoints
        }

        // For CLI, handle comma-separated collid parameter before validation
        if (isset($params['collid'])) {
            if (is_string($params['collid']) && strpos($params['collid'], ',') !== false) {
                // Convert comma-separated string to array of integers
                $collectionIds = explode(',', $params['collid']);
                $collectionIds = array_map('trim', $collectionIds);
                $collectionIds = array_filter($collectionIds, function($val) {
                    return !empty($val) && $val !== '' && $val !== '-1' && is_numeric($val);
                });
                $params['collid'] = array_map('intval', $collectionIds);
            } elseif (is_array($params['collid'])) {
                // Already an array, filter out empty values
                $params['collid'] = array_filter($params['collid'], function($val) {
                    return !empty($val) && $val !== '' && $val !== '-1';
                });
                if (empty($params['collid'])) {
                    unset($params['collid']);
                }
            } else {
                // Single value - convert to array if valid, remove if empty
                if (!empty($params['collid']) && $params['collid'] !== '' && $params['collid'] !== '-1') {
                    // Convert to integer if it's a numeric string
                    if (is_numeric($params['collid'])) {
                        $params['collid'] = [(int)$params['collid']];
                    } else {
                        $params['collid'] = [$params['collid']];
                    }
                } else {
                    // Remove empty collid to avoid validation errors
                    unset($params['collid']);
                }
            }
        }

        // Skip parent validation for collid since we handle it specially
        $filteredParams = $params;
        unset($filteredParams['collid']);

        return parent::validateParameters($filteredParams);
    }

    /**
     * Get collection name for selected collection ID
     */
    public function getCollectionName(array $params): array
    {
        $collectionId = $params['collid'] ?? '';

        if (empty($collectionId) || $collectionId === '-1') {
            return $this->htmxResponse('None selected');
        }

        try {
            $collections = $this->getCollections(1000, [], []);

            // Check if we got a successful response with collections data
            if ($collections['type'] !== 'success' || !isset($collections['content']['collections'])) {
                return $this->htmxResponse('Error loading collections');
            }

            foreach ($collections['content']['collections'] as $collection) {
                if ($collection['collid'] == $collectionId) {
                    $collectionName = $collection['collectionname'] ?? $collection['collectionName'] ?? 'Unknown Collection';
                    return $this->htmxResponse(htmlspecialchars($collectionName));
                }
            }

            return $this->htmxResponse('Collection not found');
        } catch (\Exception $e) {
            return $this->htmxResponse('Error loading collection');
        }
    }

    /**
     * Get clear results content
     */
    public function getClearResults(): array
    {
        try {
            $content = $this->renderTemplate('clear_results', TemplateFormat::HTML, []);
        } catch (\RuntimeException $e) {
            // Fallback to inline content
            $content = '
                <div class="text-center text-muted py-5">
                    <i class="fas fa-search fa-3x mb-3 opacity-50"></i>
                    <p class="lead">Enter search criteria above to find specimens</p>
                    <small>Use the form above to search by catalog number or Symbiota occurrence ID</small>
                </div>
            ';
        }

        return $this->htmlResponse($content);
    }

    /**
     * Get development form for HTMX testing
     */
    public function getDevForm(): array
    {
        // Get the dev form content
        $devFormContent = $this->renderTemplate('dev_form', TemplateFormat::HTML, [
            'title' => 'GenBank Development Form'
        ]);

        // Get breadcrumb content from template
        try {
            $breadcrumbItems = $this->renderTemplate('breadcrumb_dev_form', TemplateFormat::HTML, [
                'app_url_prefix' => $this->getAppUrlPrefix()
            ]);
        } catch (\RuntimeException $e) {
            // Fallback to inline breadcrumb
            $breadcrumbItems = sprintf(
                '<li class="breadcrumb-item"><a href="%sgenbank">GenBank</a></li><li class="breadcrumb-item active">Dev Form</li>',
                $this->getAppUrlPrefix()
            );
        }

        // Wrap in layout
        $fullPage = $this->renderTemplate('layout', TemplateFormat::HTML, [
            'page_title' => 'GenBank Dev Form - Symbiota Portal Helpers',
            'content' => $devFormContent,
            'genbank_active' => 'active',
            'breadcrumb_items' => $breadcrumbItems,
            'php_version' => PHP_VERSION
        ]);

        return $this->htmlResponse($fullPage, 'layout');
    }

    /**
     * Handle search requests from HTMX frontend
     */
    public function handleSearch(array $route, array $params): array
    {
        try {
            // Use Environment singleton to determine response context
            $env = \Symbiota\Helpers\Core\Environment::getInstance();




            // Determine search type based on parameters
            if (isset($params['catalog_number']) && isset($params['collid'])) {
                // Catalog number search
                $catalogNumber = trim($params['catalog_number']);
                $collectionId = (int)$params['collid'];

                if (empty($catalogNumber) || $collectionId <= 0) {
                    return $this->renderError('Please provide both catalog number and collection.');
                }

                $results = $this->searchByCatalogNumber($catalogNumber, [$collectionId]);

            } elseif (isset($params['occid'])) {
                // Occid search
                $occid = (int)$params['occid'];

                if ($occid <= 0) {
                    return $this->renderError('Please provide a valid occid.');
                }

                $results = $this->searchByOccid($occid);

            } else {
                return $this->renderError('Invalid search parameters provided.');
            }

            // Check if results were found
            $actualResults = $results['content']['results'] ?? [];
            if (empty($actualResults)) {
                return $this->renderNoResults();
            }

            // If JSON response is requested, return raw data
            if ($env->shouldReturnJson()) {
                return $results; // Return the raw search results for JSON requests
            }

            // Render results - pass the content structure
            return $this->renderSearchResults($results['content']);

        } catch (Exception $e) {
            $this->logError(sprintf('Search error: %s', $e->getMessage()));
            return $this->renderError(sprintf('An error occurred during search: %s', $e->getMessage()));
        }
    }

    /**
     * Render search results as HTML
     */
    private function renderSearchResults(array $results): array
    {
        $records = $results['results'];
        if (empty($records)) {
            return $this->renderNoResults();
        }

        // Get first record for collection info
        $firstRecord = $records[0];
        $collectionName = $firstRecord['collectionName'] ?? 'Unknown Collection';
        $occid = $firstRecord['occid'] ?? '';

        // Build table headers from first record
        $headerCells = [];
        foreach (array_keys($firstRecord) as $field) {
            $headerCells[] = sprintf('<th>%s</th>', htmlspecialchars(ucfirst(str_replace('_', ' ', $field))));
        }

        $headers = $this->renderTemplate('header', TemplateFormat::HTML, [
            'header_cells' => implode("\n", $headerCells)
        ]);

        // Build table rows
        $tableRows = [];
        foreach ($records as $record) {
            $rowCells = [];
            foreach ($record as $value) {
                $rowCells[] = sprintf('<td>%s</td>', htmlspecialchars($value ?? ''));
            }
            $tableRows[] = sprintf('<tr>%s</tr>', implode('', $rowCells));
        }

        $rows = $this->renderTemplate('rows', TemplateFormat::HTML, [
            'table_rows' => implode("\n", $tableRows)
        ]);

        // Generate filename for TSV download
        $timestamp = date('Y-m-d_H-i-s');
        $filename = sprintf('genbank_results_%s.tsv', $timestamp);

        // Build occurrence URL (assuming standard Symbiota structure)
        $occurrenceUrl = sprintf('/collections/individual/index.php?occid=%s', $occid);

        return $this->htmlResponse($this->renderTemplate('results', TemplateFormat::HTML, [
            'collection_name' => $collectionName,
            'occid' => $occid,
            'occurrence_url' => $occurrenceUrl,
            'table_headers' => $headers,
            'table_rows' => $rows,
            'record_count' => count($records),
            'timestamp' => date('Y-m-d H:i:s'),
            'tsv_filename' => $filename
        ]));
    }

    /**
     * Render error message
     */
    private function renderError(string $message): array
    {
        return $this->htmlResponse($this->renderTemplate('error', TemplateFormat::HTML, [
            'error_message' => $message
        ]));
    }

    /**
     * Render no results message
     */
    private function renderNoResults(): array
    {
        return $this->htmlResponse($this->renderTemplate('no_results', TemplateFormat::HTML, []));
    }

    /**
     * Get secure fragment with validation
     */
    public function getSecureFragment(array $params): array
    {
        // Validate request method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmxResponse('<div class="alert alert-danger">Invalid request method</div>');
        }

        // Validate required parameters
        $fragmentType = $params['fragment'] ?? '';
        $format = $params['format'] ?? 'json';

        if (empty($fragmentType)) {
            return $this->htmxResponse('<div class="alert alert-danger">Fragment type required</div>');
        }

        // Validate allowed fragment types
        $allowedFragments = ['collections', 'collection-name', 'search-form', 'search-results'];
        if (!in_array($fragmentType, $allowedFragments)) {
            return $this->htmxResponse('<div class="alert alert-danger">Invalid fragment type</div>');
        }

        // TODO: Add CSRF token validation
        // $csrfToken = $params['csrf_token'] ?? '';
        // if (!$this->validateCsrfToken($csrfToken)) {
        //     return ['type' => 'htmx', 'content' => '<div class="alert alert-danger">Invalid CSRF token</div>'];
        // }

        // TODO: Add rate limiting
        // if (!$this->checkRateLimit()) {
        //     return ['type' => 'htmx', 'content' => '<div class="alert alert-warning">Rate limit exceeded</div>'];
        // }

        // Route to appropriate fragment handler
        switch ($fragmentType) {
            case 'collections':
                if ($format === 'htmx' || $format === 'html') {
                    // Pass limit parameter to getCollectionsOptions
                    return $this->getCollectionsOptions($params);
                } else {
                    return $this->getCollections(
                        limit: (int)($params['limit'] ?? 100),
                        sort: $params['sort'] ?? [],
                        filter: $params['filter'] ?? []
                    );
                }

            case 'collection-name':
                return $this->getCollectionName($params);

            case 'search-form':
                $formType = $params['form_type'] ?? 'catalog';
                return $this->getSearchForm($formType);

            case 'search-results':
                return $this->getSearchResults($params);

            default:
                return [
                    'type' => 'htmx',
                    'content' => '<div class="alert alert-danger">Unknown fragment type</div>'
                ];
        }
    }

    /**
     * Get search results fragment for HTMX
     */
    public function getSearchResults(array $params): array
    {
        try {
            // Determine search type based on parameters
            if (!empty($params['catalog_number']) || !empty($params['catalogNumber'])) {
                // Catalog number search - use the same method as CLI
                $catalogNumber = trim($params['catalog_number'] ?? $params['catalogNumber'] ?? '');
                $collectionIds = [];

                // Handle collection ID parameter
                if (!empty($params['collid'])) {
                    if (is_array($params['collid'])) {
                        $collectionIds = array_map('intval', $params['collid']);
                    } else {
                        $collectionIds = [(int)$params['collid']];
                    }
                }

                if (empty($catalogNumber)) {
                    return $this->htmxResponse('<div class="alert alert-warning">Please provide a catalog number</div>');
                }

                // Use the same method as CLI - this ensures HTTP-CLI parity
                $results = $this->searchByCatalogNumber($catalogNumber, $collectionIds);

            } elseif (!empty($params['occid'])) {
                // Occid search
                $occid = (int)$params['occid'];

                if ($occid <= 0) {
                    return $this->htmxResponse('<div class="alert alert-warning">Please provide a valid occid</div>');
                }

                $results = $this->searchByOccid($occid);

            } else {
                return $this->htmxResponse('<div class="alert alert-warning">Please provide either a catalog number or occid</div>');
            }

            // Handle database errors
            if (isset($results['type']) && $results['type'] === 'error') {
                $errorMessage = htmlspecialchars($results['error'] ?? 'Database error occurred');
                $detailMessage = htmlspecialchars($results['message'] ?? '');
                return $this->htmxResponse(sprintf('<div class="alert alert-danger"><strong>%s</strong><br>%s</div>', $errorMessage, $detailMessage));
            }

            // Format results for HTMX display
            $actualResults = $results['content']['results'] ?? [];
            if (empty($actualResults)) {
                return $this->htmxResponse('<div class="alert alert-info">No specimens found matching your search criteria</div>');
            }

            // Build GenBank-style results matching the specified format
            $specimen = $actualResults[0]; // Get first result for header info
            $collectionName = htmlspecialchars($specimen['collectionName'] ?? 'Unknown Collection');
            $institutionCode = htmlspecialchars($specimen['institutionCode'] ?? '');
            $collectionCode = htmlspecialchars($specimen['collectionCode'] ?? '');
            $occid = $specimen['occid'] ?? 'N/A';

            // Build collection display name
            $collectionCodes = '';
            if ($institutionCode || $collectionCode) {
                $collectionCodes = sprintf('[%s:%s]', $institutionCode, $collectionCode);
            }

            $collectionDisplay = $this->renderTemplate('collection_display', TemplateFormat::HTML, [
                'collection_name' => $collectionName,
                'collection_codes' => $collectionCodes
            ]);

            // Build occid section if available
            $occidSection = '';
            if ($occid !== 'N/A') {
                // Get dynamic portal web path from config
                $portalPath = $this->getConfig('portal_web_path', '/portal');
                $occidSection = sprintf(
                    '<div class="row"><span><p>Occid link: <a href="%s/collections/individual/index.php?occid=%s" target="_blank">%s</a></p></span></div>',
                    $portalPath,
                    $occid,
                    $occid
                );
            }

            // Extract specimen data for key-value pairs
            $specimen = $actualResults[0];

            // Build GenBank-style key-value pairs
            $keyValuePairs = [
                'sequence_id' => 'SEQUENCE-ID',
                'organism' => $specimen['scientificName'] ?? '',
                'altitude' => $specimen['minimumElevationInMeters'] ?? '',
                'collected_by' => $specimen['recordedBy'] ?? '',
                'collection_date' => $specimen['eventDate'] ?? '',
                'country' => $specimen['locality'] ?? '',
                'host' => $specimen['associatedTaxa'] ?? '',
                'isolation_source' => '',
                'identified_by' => $specimen['identifiedBy'] ?? '',
                'lat_lon' => $this->formatLatLon($specimen),
                'specimen_voucher' => $this->formatSpecimenVoucher($specimen)
            ];

            // Build key-value rows
            $keyValueRows = [];
            foreach ($keyValuePairs as $key => $value) {
                $keyValueRows[] = sprintf(
                    '<tr><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars($key),
                    htmlspecialchars($value)
                );
            }

            // Generate filename for download
            $filename = sprintf('myco-genbank-%s.tsv', $occid);

            // Build complete HTML using template
            $html = $this->renderTemplate('model', TemplateFormat::HTML, [
                'collection_display' => $collectionDisplay,
                'occid_section' => $occidSection,
                'key_value_rows' => implode("\n", $keyValueRows),
                'filename' => $filename
            ]);

            return $this->htmxResponse($html);

        } catch (\Exception $e) {
            return $this->htmxResponse(sprintf('<div class="alert alert-danger">Search error: %s</div>', htmlspecialchars($e->getMessage())));
        }
    }



    /**
     * Format latitude and longitude for GenBank display
     */
    private function formatLatLon(array $specimen): string
    {
        $lat = $specimen['decimalLatitude'] ?? '';
        $lon = $specimen['decimalLongitude'] ?? '';

        if (empty($lat) || empty($lon)) {
            return '';
        }

        $latDir = (float)$lat >= 0 ? 'N' : 'S';
        $lonDir = (float)$lon >= 0 ? 'E' : 'W';

        return sprintf('%s %s %s %s', abs($lat), $latDir, abs($lon), $lonDir);
    }

    /**
     * Format specimen voucher for GenBank display
     */
    private function formatSpecimenVoucher(array $specimen): string
    {
        $institutionCode = $specimen['institutionCode'] ?? '';
        $collectionCode = $specimen['collectionCode'] ?? '';
        $catalogNumber = $specimen['catalogNumber'] ?? '';

        if (empty($catalogNumber)) {
            return '';
        }

        // Build voucher using sprintf
        $voucherParts = [];
        if ($institutionCode) {
            $voucherParts[] = $institutionCode;
        }
        if ($collectionCode) {
            $voucherParts[] = $collectionCode;
        }

        if (!empty($voucherParts)) {
            $voucher = sprintf('%s-%s', implode(':', $voucherParts), $catalogNumber);
        } else {
            $voucher = $catalogNumber;
        }

        return $voucher;
    }

    // Configuration method inherited from Model base class
}
