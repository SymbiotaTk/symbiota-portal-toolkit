<?php

namespace Symbiota\Helpers\Fixtures;

/**
 * Test data fixtures for GenBank model testing
 * 
 * This class provides test data that was previously embedded in production code.
 * It should only be used in test environments.
 */
class GenBankTestData
{
    /**
     * Generate test data for various GenBank operations
     */
    public static function generateTestData(string $type, $query = null, array $filters = []): array
    {
        $baseResponse = [
            'database_status' => 'test_data',
            'message' => 'No active database connection - returning test data',
            'connection_info' => 'Looking for config/dbconnection.php relative to application directory'
        ];
        
        switch ($type) {
            case 'catalogNumber':
                return array_merge($baseResponse, [
                    'type' => 'search_result',
                    'search_type' => 'catalogNumber',
                    'query' => $query,
                    'filters' => ['collid' => $filters],
                    'results' => [
                        [
                            'occid' => 12345,
                            'catalogNumber' => $query,
                            'collid' => 2,
                            'collectionName' => 'Test Herbarium',
                            'scientificName' => 'Quercus alba',
                            'family' => 'Fagaceae',
                            'locality' => 'Test County, Test State',
                            'decimalLatitude' => 40.7128,
                            'decimalLongitude' => -74.0060,
                            'eventDate' => '2023-06-15',
                            'recordedBy' => 'Test Collector'
                        ]
                    ]
                ]);
                
            case 'occid':
                return array_merge($baseResponse, [
                    'type' => 'search_result',
                    'search_type' => 'occid',
                    'query' => $query,
                    'results' => [
                        [
                            'occid' => $query,
                            'catalogNumber' => 'TEST-' . $query,
                            'collid' => 1,
                            'collectionName' => 'Test Collection',
                            'scientificName' => 'Acer saccharum',
                            'family' => 'Sapindaceae',
                            'locality' => 'Test Forest, Test County',
                            'stateProvince' => 'Test State',
                            'country' => 'United States',
                            'decimalLatitude' => 42.3601,
                            'decimalLongitude' => -71.0589,
                            'eventDate' => '2023-07-20',
                            'recordedBy' => 'Test Botanist',
                            'recordNumber' => 'TB-2023-001'
                        ]
                    ]
                ]);
                
            case 'collections':
                return array_merge($baseResponse, [
                    'type' => 'collections_list',
                    'collections' => [
                        [
                            'collid' => 1,
                            'collectionName' => 'Test University Herbarium',
                            'institutionCode' => 'TEST',
                            'collectionCode' => 'TUH'
                        ],
                        [
                            'collid' => 2,
                            'collectionName' => 'Test Natural History Museum',
                            'institutionCode' => 'TNHM',
                            'collectionCode' => 'BOT'
                        ],
                        [
                            'collid' => 3,
                            'collectionName' => 'Test Field Station',
                            'institutionCode' => 'TFS',
                            'collectionCode' => 'FIELD'
                        ]
                    ]
                ]);

            default:
                return array_merge($baseResponse, [
                    'type' => 'error',
                    'error' => "Unknown test data type: {$type}"
                ]);
        }
    }

    /**
     * Get test data for catalog number search
     */
    public static function getCatalogNumberTestData(string $catalogNumber, array $collectionIds = []): array
    {
        return self::generateTestData('catalogNumber', $catalogNumber, $collectionIds);
    }

    /**
     * Get test data for occurrence ID search
     */
    public static function getOccurrenceIdTestData(int $occid): array
    {
        return self::generateTestData('occid', $occid);
    }

    /**
     * Get test data for collections list
     */
    public static function getCollectionsTestData(): array
    {
        return self::generateTestData('collections');
    }
}
