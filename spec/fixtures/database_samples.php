<?php

/**
 * Database Sample Data for Mock Testing
 * 
 * This file contains captured sample data from real database calls
 * to be used in mock tests instead of making live database connections.
 * 
 * Usage:
 * - When database calls are confirmed working, capture sample responses here
 * - Use this data in tests to avoid slow database connections
 * - Update samples when database schema or data changes
 */

return [
    'collections' => [
        'sample_small_collection' => [
            'collid' => 24,
            'institutioncode' => 'NYBG',
            'collectioncode' => 'NY',
            'collectionname' => 'New York Botanical Garden Herbarium',
            'recordcount' => 850,
            'contact' => 'curator@nybg.org',
            'description' => 'Small test collection for backup testing'
        ],
        'sample_medium_collection' => [
            'collid' => 42,
            'institutioncode' => 'FLAS',
            'collectioncode' => 'FLAS',
            'collectionname' => 'University of Florida Herbarium',
            'recordcount' => 2500,
            'contact' => 'herbarium@ufl.edu',
            'description' => 'Medium collection for testing'
        ]
    ],

    'users' => [
        'collection_24_users' => [
            [
                'uid' => 101,
                'username' => 'admin_user',
                'firstname' => 'Admin',
                'lastname' => 'User',
                'email' => 'admin@example.com',
                'usergroup' => 'SuperAdmin',
                'rights' => 'CollAdmin'
            ],
            [
                'uid' => 102,
                'username' => 'curator_smith',
                'firstname' => 'Jane',
                'lastname' => 'Smith',
                'email' => 'j.smith@nybg.org',
                'usergroup' => 'CollectionEditor',
                'rights' => 'CollAdmin'
            ]
        ]
    ],

    'specimens' => [
        'catalog_search_163172' => [
            'occid' => 12345,
            'catalognumber' => '163172',
            'family' => 'Asteraceae',
            'genus' => 'Helianthus',
            'specificepithet' => 'annuus',
            'scientificname' => 'Helianthus annuus L.',
            'recordedby' => 'J. Smith',
            'recordnumber' => '2023-001',
            'eventdate' => '2023-06-15',
            'country' => 'United States',
            'stateprovince' => 'New York',
            'locality' => 'Central Park, Manhattan',
            'decimallatitude' => 40.7829,
            'decimallongitude' => -73.9654
        ]
    ],

    'backup_registry' => [
        'sample_entries' => [
            [
                'collid' => 24,
                'passphrase_hash' => 'hashed_passphrase_example',
                'registered_by' => 101,
                'registered_date' => '2024-01-15 10:30:00',
                'last_backup' => '2024-01-20 02:15:00',
                'backup_count' => 5,
                'status' => 'active'
            ]
        ]
    ],

    'database_responses' => [
        'connection_test_success' => [
            'available' => true,
            'config_path' => '/path/to/config/dbconnection.php',
            'connection_type' => 'readonly',
            'status' => 'connected',
            'message' => 'Database connection successful',
            'server_info' => 'MySQL 8.0.32',
            'charset' => 'utf8mb4'
        ],
        'connection_test_failure' => [
            'available' => false,
            'config_path' => '/path/to/config/dbconnection.php',
            'connection_type' => 'readonly',
            'status' => 'failed',
            'message' => 'Access denied for user',
            'error_code' => 1045
        ]
    ],

    'progress_spinner_states' => [
        'demo_sequence' => [
            ['state' => 'testing', 'message' => 'Testing...', 'duration' => 1.0],
            ['state' => 'success', 'message' => 'Success!', 'duration' => 0.5],
            ['state' => 'testing', 'message' => 'Testing...', 'duration' => 1.0],
            ['state' => 'failed', 'message' => 'Failed!', 'duration' => 0.5],
            ['state' => 'testing', 'message' => 'Testing...', 'duration' => 1.0],
            ['state' => 'warning', 'message' => 'Warning!', 'duration' => 0.5]
        ]
    ],

    'genbank_searches' => [
        'catalog_163172' => [
            'found' => true,
            'count' => 1,
            'results' => [
                [
                    'occid' => 12345,
                    'catalognumber' => '163172',
                    'genbank_id' => 'MN123456',
                    'sequence_type' => 'ITS',
                    'sequence_length' => 650,
                    'submission_date' => '2023-08-15'
                ]
            ]
        ],
        'occurrence_12345' => [
            'found' => true,
            'count' => 2,
            'results' => [
                [
                    'occid' => 12345,
                    'genbank_id' => 'MN123456',
                    'sequence_type' => 'ITS',
                    'gene_region' => 'Internal Transcribed Spacer'
                ],
                [
                    'occid' => 12345,
                    'genbank_id' => 'MN123457',
                    'sequence_type' => 'rbcL',
                    'gene_region' => 'Ribulose-1,5-bisphosphate carboxylase/oxygenase large subunit'
                ]
            ]
        ]
    ],

    'template_queries' => [
        'catalog_search_sql' => "SELECT occid, catalognumber, family, genus, specificepithet, scientificname, recordedby FROM omoccurrences WHERE catalognumber = ?",
        'occurrence_search_sql' => "SELECT * FROM omoccurrences WHERE occid = ?",
        'collection_users_sql' => "SELECT u.uid, u.username, u.firstname, u.lastname, u.email FROM users u INNER JOIN userpermissions up ON u.uid = up.uid WHERE up.collid = ? AND up.ptype = 'CollAdmin'"
    ]
];
