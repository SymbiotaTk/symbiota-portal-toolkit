<?php

declare(strict_types=1);

namespace Symbiota\Helpers\Models;

use Symbiota\Helpers\Core\Model;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\ApplicationPaths;
use Symbiota\Helpers\Core\TemplateEngine;
use Exception;

/**
 * TaxonomyReportModel - Generates taxonomy reports for collections
 *
 * Provides detailed taxonomy analysis including:
 * - Collection metadata
 * - Taxonomic identification statistics
 * - Unrecognized taxa lists
 * - Data quality metrics
 *
 * @package Helpers\Models
 * @version 1.0.0
 * @author Symbiota Portal Helpers
 */
class TaxonomyReportModel extends Model
{
    // Taxonomy reports are public read-only - no authentication required
    protected bool $requiresAuth = false;
    protected array $publicActions = ['index', 'help', 'report', 'collections', 'status'];

    /**
     * Get the model's routing resource ID
     */
    public static function getModelId(): string
    {
        return 'taxonomy-report';
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
        $templatePath = sprintf('%s/cli/help/taxonomy_report_model.txt', ApplicationPaths::templatesDirectory());
        if (!file_exists($templatePath)) {
            return 'Help documentation not found.';
        }

        $helpContent = file_get_contents($templatePath);
        if ($helpContent === false) {
            return 'Error reading help documentation.';
        }

        // Use TemplateEngine for variable interpolation
        $templateEngine = new TemplateEngine();
        return $templateEngine->render($helpContent, ['entry_file' => $entryFile]);
    }

    /**
     * Execute the requested action - implements Model base class method
     */
    protected function executeAction(string $action, array $route, array $params): array
    {
        switch ($action) {
            case 'index':
            case '':
                return $this->getMainPage();
            case 'report':
                return $this->generateReport($params);
            case 'collections':
                return $this->getCollections($params);
            case 'status':
                return $this->getTaxonomyReportStatus($params);
            case 'help':
                return $this->getHelp();
            default:
                return [
                    'type' => 'error',
                    'message' => "Unknown action: $action",
                    'status_code' => 404
                ];
        }
    }

    /**
     * Get main taxonomy report page
     */
    private function getMainPage(): array
    {
        if (Environment::isCli()) {
            $scriptName = static::getScriptName();
            return [
                'type' => 'success',
                'content' => "Taxonomy Report - Use '{$scriptName} taxonomy-report --help' for more information"
            ];
        }

        // Generate CSRF token for form security
        $csrfToken = $this->generateCsrfToken();

        // Get collection options for the dropdown
        $collectionOptions = $this->getCollectionOptions();

        return [
            'type' => 'html',
            'content' => $this->renderTemplate('content', TemplateFormat::HTML, [
                'app_url_prefix' => $this->getAppUrlPrefix(),
                'collection_options' => $collectionOptions,
                'csrf_token' => $csrfToken
            ])
        ];
    }

    /**
     * Generate CSRF token for session
     */
    private function generateCsrfToken(): string
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate new token
        $token = bin2hex(random_bytes(32));
        $_SESSION['taxonomy_report_csrf_token'] = $token;
        $_SESSION['taxonomy_report_csrf_expires'] = time() + 3600; // 1 hour expiry

        return $token;
    }

    /**
     * Validate CSRF token
     */
    private function validateCsrfToken(string $token): bool
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check if token exists and hasn't expired
        $sessionToken = $_SESSION['taxonomy_report_csrf_token'] ?? '';
        $expires = $_SESSION['taxonomy_report_csrf_expires'] ?? 0;

        if (empty($sessionToken) || empty($token)) {
            return false;
        }

        if (time() > $expires) {
            // Token expired, clean up
            unset($_SESSION['taxonomy_report_csrf_token']);
            unset($_SESSION['taxonomy_report_csrf_expires']);
            return false;
        }

        // Use hash_equals for timing-safe comparison
        return hash_equals($sessionToken, $token);
    }

    /**
     * Get collection options for dropdown
     */
    private function getCollectionOptions(string $filter = ''): string
    {
        try {
            $connection = $this->getDatabaseConnection('readonly');
            if (!$connection) {
                return '<option value="">Database connection failed</option>';
            }

            // Build SQL with optional filter
            $templateData = [];
            if (!empty($filter)) {
                $templateData['where_clause'] = " AND (c.collectionName LIKE ? OR c.institutionCode LIKE ? OR c.collectionCode LIKE ?)";
            } else {
                $templateData['where_clause'] = '';
            }

            $sql = $this->renderTemplate('collections_list', TemplateFormat::SQL, $templateData);

            // Prepare and execute query
            if (!empty($filter)) {
                $stmt = $connection->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare statement: ' . $connection->error);
                }
                $filterParam = '%' . $filter . '%';
                $stmt->bind_param('sss', $filterParam, $filterParam, $filterParam);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $connection->query($sql);
            }

            $options = '<option value="">Choose a collection...</option>' . "\n";
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $collid = htmlspecialchars((string)($row['collid'] ?? ''));
                    $name = htmlspecialchars((string)($row['collectionName'] ?? $row['collectionname'] ?? 'Unknown Collection'));
                    $code = htmlspecialchars((string)($row['institutionCode'] ?? $row['institutioncode'] ?? ''));
                    $displayName = $code ? "$name ($code)" : $name;
                    $options .= "<option value=\"$collid\">$displayName</option>\n";
                }
            }

            return $options;
        } catch (Exception $e) {
            error_log("Error getting collection options: " . $e->getMessage());
            return '<option value="">Error loading collections</option>';
        }
    }

    /**
     * Generate taxonomy report for a collection
     */
    private function generateReport(array $params): array
    {
        $collid = $params['collid'] ?? '';

        if (empty($collid) || !is_numeric($collid)) {
            return [
                'type' => 'error',
                'message' => 'Collection ID is required and must be numeric',
                'status_code' => 400
            ];
        }

        // For web requests, validate CSRF token
        if (!Environment::isCli()) {
            $csrfToken = $params['csrf_token'] ?? '';
            if (!$this->validateCsrfToken($csrfToken)) {
                return [
                    'type' => 'error',
                    'message' => 'Invalid or expired security token',
                    'status_code' => 403
                ];
            }
        }

        try {
            $connection = $this->getDatabaseConnection('readonly');
            if (!$connection) {
                throw new Exception('Database connection failed');
            }

            // Execute the taxonomy report query
            $sql = $this->renderTemplate('report', TemplateFormat::SQL, [
                'collid' => (int)$collid
            ]);

            $result = $connection->query($sql);
            if (!$result) {
                throw new Exception('Query execution failed: ' . $connection->error);
            }

            $reportData = $result->fetch_assoc();
            if (!$reportData) {
                return [
                    'type' => 'error',
                    'message' => 'No data found for collection ID: ' . (string)$collid,
                    'status_code' => 404
                ];
            }

            // Handle CLI output
            if (Environment::isCli()) {
                return $this->formatCliReport($reportData);
            }

            // Handle web output
            return $this->formatWebReport($reportData);

        } catch (Exception $e) {
            error_log("Error generating taxonomy report: " . $e->getMessage());
            return [
                'type' => 'error',
                'message' => 'Failed to generate report: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Format report for CLI output
     */
    private function formatCliReport(array $data): array
    {
        $output = [];
        $output[] = "Taxonomy Report";
        $output[] = "===============";
        $output[] = "";
        $output[] = "CollectionName:\t" . ($data['CollectionName'] ?? 'N/A');
        $output[] = "InstitutionCode:\t" . ($data['InstitutionCode'] ?? 'N/A');
        $output[] = "CollectionCode:\t" . ($data['CollectionCode'] ?? 'N/A');
        $output[] = "CollType:\t" . ($data['CollType'] ?? 'N/A');
        $output[] = "ManagementType:\t" . ($data['ManagementType'] ?? 'N/A');
        $output[] = "dwcaUrl:\t" . ($data['dwcaUrl'] ?? 'N/A');
        $output[] = "initialTimestamp:\t" . ($data['initialTimestamp'] ?? 'N/A');
        $output[] = "Total Records:\t" . ($data['totalRecords'] ?? '0');
        $output[] = "Records with Scientific Names Linked to Thesaurus:\t" . ($data['totalRecordsTaxonIdentified'] ?? '0');
        $output[] = "Unique Scientific Names Linked to Thesaurus:\t" . ($data['totalUniqueTaxonIdentified'] ?? '0');
        $output[] = "Records with Scientific Names Not Linked to Thesaurus:\t" . ($data['totalRecordsTaxonNotIdentified'] ?? '0');
        $output[] = "Records with Scientific Names That Can Be Linked to Thesaurus:\t" . ($data['totalUniqueTaxonNotIdentifiedExistsInThesaurus'] ?? '0');
        $output[] = "Unique Problematic Scientific Names within Records:\t" . ($data['totalUniqueTaxonNotIdentifiedUnrecognizedNotNULL'] ?? '0');
        $output[] = "Records with No Scientific Names:\t" . ($data['totalRecordsTaxonNULL'] ?? '0');

        // Handle unrecognized names list
        $unrecognizedNames = $data['taxaNotRecognizedByThesaurus'] ?? '';
        if (!empty($unrecognizedNames)) {
            $output[] = "Unrecognized Names: " . $unrecognizedNames;
        } else {
            $output[] = "Unrecognized Names: None";
        }

        return [
            'type' => 'success',
            'content' => implode("\n", $output)
        ];
    }

    /**
     * Format report for web output
     */
    private function formatWebReport(array $data): array
    {
        // Process unrecognized names for accordion display
        $unrecognizedNames = $data['taxaNotRecognizedByThesaurus'] ?? '';
        $namesList = [];
        $namesCount = 0;

        if (!empty($unrecognizedNames)) {
            $namesList = array_map('trim', explode('|', $unrecognizedNames));
            $namesCount = count($namesList);
        }

        // Flatten the data for template processing
        $templateData = [
            'collection_name' => $data['CollectionName'] ?? 'N/A',
            'institution_code' => $data['InstitutionCode'] ?? 'N/A',
            'collection_code' => $data['CollectionCode'] ?? 'N/A',
            'coll_type' => $data['CollType'] ?? 'N/A',
            'management_type' => $data['ManagementType'] ?? 'N/A',
            'dwca_url' => $data['dwcaUrl'] ?? 'N/A',
            'initial_timestamp' => $data['initialTimestamp'] ?? 'N/A',
            'total_records' => $data['totalRecords'] ?? '0',
            'records_identified' => $data['totalRecordsTaxonIdentified'] ?? '0',
            'unique_identified' => $data['totalUniqueTaxonIdentified'] ?? '0',
            'records_not_identified' => $data['totalRecordsTaxonNotIdentified'] ?? '0',
            'can_be_linked' => $data['totalUniqueTaxonNotIdentifiedExistsInThesaurus'] ?? '0',
            'problematic_names' => $data['totalUniqueTaxonNotIdentifiedUnrecognizedNotNULL'] ?? '0',
            'records_no_names' => $data['totalRecordsTaxonNULL'] ?? '0',
            'unrecognized_count' => $namesCount,
            'unrecognized_string' => $unrecognizedNames,
            'app_url_prefix' => $this->getAppUrlPrefix()
        ];

        // Add unrecognized names list if present
        if ($namesCount > 0) {
            $namesBadges = [];
            foreach ($namesList as $name) {
                $namesBadges[] = '<div class="col-md-6 col-lg-4 mb-2"><span class="badge bg-light text-dark border">' . htmlspecialchars($name) . '</span></div>';
            }
            $templateData['unrecognized_names_html'] = implode("\n", $namesBadges);
        } else {
            $templateData['unrecognized_names_html'] = '<span class="text-muted">None</span>';
        }

        return [
            'type' => 'htmx',
            'content' => $this->renderTemplate('report_table', TemplateFormat::HTML, $templateData)
        ];
    }

    /**
     * Get collections list (HTMX endpoint)
     */
    private function getCollections(array $params): array
    {
        $filter = $params['filter'] ?? '';
        $collectionOptions = $this->getCollectionOptions($filter);

        return [
            'type' => 'htmx',
            'content' => $collectionOptions
        ];
    }

    /**
     * Get Taxonomy Report module configuration and status
     */
    private function getTaxonomyReportStatus(array $params): array
    {
        $config = Configuration::getInstance();

        // Get configuration values
        $taxonomyConfig = $config ?
            ($config->get('components.taxonomy-report') ?? $config->get('taxonomy-report') ?? $config->get('mod.taxonomy-report') ?? []) :
            [];

        $status = [
            'configuration' => [
                'module_enabled' => true,
                'database_available' => $this->isDatabaseAvailable(),
                'template_path' => ApplicationPaths::templatesDirectory() . '/sql/taxonomy-report',
                'csrf_protection' => true,
                'max_collections' => $taxonomyConfig['max_collections'] ?? 50
            ],
            'paths' => [
                'templates_absolute' => realpath(ApplicationPaths::templatesDirectory() . '/sql/taxonomy-report') ?: ApplicationPaths::templatesDirectory() . '/sql/taxonomy-report',
                'templates_exist' => is_dir(ApplicationPaths::templatesDirectory() . '/sql/taxonomy-report'),
                'templates_readable' => is_readable(ApplicationPaths::templatesDirectory() . '/sql/taxonomy-report')
            ],
            'database' => [
                'connection_available' => $this->isDatabaseAvailable(),
                'connection_type' => 'readonly',
                'test_query_status' => 'unknown'
            ],
            'security' => [
                'readonly_mode' => true,
                'csrf_protection' => true,
                'input_validation' => true,
                'prepared_statements' => true
            ]
        ];

        // Test database connection if available
        if ($this->isDatabaseAvailable()) {
            try {
                $connection = $this->getDatabaseConnection('readonly');
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
            $output = ["Taxonomy Report Module Status", str_repeat("=", 50)];

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
                $output[] = "   Taxonomy reports will not be available.";
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
     * Get help information
     */
    private function getHelp(): array
    {
        if (Environment::isCli()) {
            return [
                'type' => 'success',
                'content' => $this->renderTemplate('help', TemplateFormat::CLI, [])
            ];
        }

        return [
            'type' => 'html',
            'content' => $this->renderTemplate('help', TemplateFormat::HTML, [
                'app_url_prefix' => $this->getAppUrlPrefix()
            ])
        ];
    }
}
