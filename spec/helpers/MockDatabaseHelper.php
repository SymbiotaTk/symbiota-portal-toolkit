<?php

namespace Symbiota\Helpers\Test;

/**
 * Mock Database Helper for Testing
 * 
 * Provides mock database responses using captured sample data
 * instead of making live database connections during testing.
 */
class MockDatabaseHelper
{
    private static ?array $sampleData = null;

    /**
     * Load sample data from fixtures
     */
    private static function loadSampleData(): array
    {
        if (self::$sampleData === null) {
            $sampleFile = __DIR__ . '/../fixtures/database_samples.php';
            if (file_exists($sampleFile)) {
                self::$sampleData = require $sampleFile;
            } else {
                self::$sampleData = [];
            }
        }
        return self::$sampleData;
    }

    /**
     * Get mock collection data
     */
    public static function getCollectionData(string $key = 'sample_small_collection'): array
    {
        $data = self::loadSampleData();
        return $data['collections'][$key] ?? [];
    }

    /**
     * Get mock user data for a collection
     */
    public static function getCollectionUsers(int $collid): array
    {
        $data = self::loadSampleData();
        $key = "collection_{$collid}_users";
        return $data['users'][$key] ?? [];
    }

    /**
     * Get mock specimen data
     */
    public static function getSpecimenData(string $catalogNumber): array
    {
        $data = self::loadSampleData();
        $key = "catalog_search_{$catalogNumber}";
        return $data['specimens'][$key] ?? [];
    }

    /**
     * Get mock GenBank search results
     */
    public static function getGenBankResults(string $searchType, string $searchValue): array
    {
        $data = self::loadSampleData();
        $key = "{$searchType}_{$searchValue}";
        return $data['genbank_searches'][$key] ?? ['found' => false, 'count' => 0, 'results' => []];
    }

    /**
     * Get mock database connection test result
     */
    public static function getConnectionTestResult(bool $success = true): array
    {
        $data = self::loadSampleData();
        $key = $success ? 'connection_test_success' : 'connection_test_failure';
        return $data['database_responses'][$key] ?? [];
    }

    /**
     * Get mock progress spinner sequence
     */
    public static function getProgressSpinnerSequence(): array
    {
        $data = self::loadSampleData();
        return $data['progress_spinner_states']['demo_sequence'] ?? [];
    }

    /**
     * Get mock backup registry data
     */
    public static function getBackupRegistryData(): array
    {
        $data = self::loadSampleData();
        return $data['backup_registry']['sample_entries'] ?? [];
    }

    /**
     * Get SQL template for testing
     */
    public static function getSqlTemplate(string $templateName): string
    {
        $data = self::loadSampleData();
        return $data['template_queries'][$templateName] ?? '';
    }

    /**
     * Mock database query execution
     */
    public static function mockQuery(string $sql, array $params = []): array
    {
        // Simple pattern matching for common queries
        if (strpos($sql, 'catalognumber') !== false && !empty($params)) {
            return [self::getSpecimenData($params[0])];
        }
        
        if (strpos($sql, 'occid') !== false && !empty($params)) {
            return [self::getSpecimenData('163172')]; // Default specimen
        }
        
        if (strpos($sql, 'userpermissions') !== false && !empty($params)) {
            return self::getCollectionUsers($params[0]);
        }
        
        return [];
    }

    /**
     * Create a mock database connection that returns sample data
     */
    public static function createMockConnection(): object
    {
        return new class {
            public function query(string $sql): object {
                return new class {
                    public function fetch_assoc(): ?array {
                        static $called = false;
                        if (!$called) {
                            $called = true;
                            return MockDatabaseHelper::getSpecimenData('163172');
                        }
                        return null;
                    }
                    
                    public function num_rows(): int {
                        return 1;
                    }
                };
            }
            
            public function prepare(string $sql): object {
                return new class {
                    public function bind_param(string $types, ...$params): bool {
                        return true;
                    }
                    
                    public function execute(): bool {
                        return true;
                    }
                    
                    public function get_result(): object {
                        return new class {
                            public function fetch_assoc(): ?array {
                                static $called = false;
                                if (!$called) {
                                    $called = true;
                                    return MockDatabaseHelper::getSpecimenData('163172');
                                }
                                return null;
                            }
                        };
                    }
                };
            }
            
            public function close(): bool {
                return true;
            }
        };
    }

    /**
     * Reset static state for clean testing
     */
    public static function reset(): void
    {
        self::$sampleData = null;
    }
}
