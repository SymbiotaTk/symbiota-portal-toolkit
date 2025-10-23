<?php
/**
 * EAV Index Configuration
 * 
 * Defines the structure for converting flat tables to EAV format.
 * 
 * Based on the original design in eav.sql:
 * - Root entity: media.mediaID
 * - Related tables: occurrences, collections, taxa, taxstatus
 * - Explicit relationship map
 * - Numeric field definitions
 */

return [
    /**
     * Root Entity Configuration
     * 
     * The root entity is the primary table that all other tables relate to.
     * All entities in the EAV index will be instances of this root entity.
     */
    'root_entity' => [
        'table' => 'media',
        'column' => 'mediaID',
        'description' => 'Media records with valid URLs'
    ],

    /**
     * Table Definitions
     * 
     * Defines which tables to include in the EAV index and their metadata.
     * 
     * Format:
     * 'table_name' => [
     *     'priority' => int,           // Processing order (1 = first)
     *     'primary_key' => 'column',   // Primary key column
     *     'join_path' => [             // How to join to root entity
     *         ['from_table' => 'table', 'from_col' => 'col', 'to_table' => 'table', 'to_col' => 'col'],
     *         ...
     *     ],
     *     'numeric_fields' => []       // Columns to treat as numeric (optional)
     * ]
     */
    'tables' => [
        'media' => [
            'priority' => 1,
            'primary_key' => 'mediaID',
            'join_path' => [],  // Root table - no joins needed
            'numeric_fields' => ['mediaID', 'occid']
        ],

        'omoccurrences' => [
            'priority' => 2,
            'primary_key' => 'occid',
            'join_path' => [
                // omoccurrences -> media (via occid)
                ['from_table' => 'omoccurrences', 'from_col' => 'occid', 'to_table' => 'media', 'to_col' => 'occid']
            ],
            'numeric_fields' => ['occid', 'collid', 'tidInterpreted']
        ],

        'omcollections' => [
            'priority' => 3,
            'primary_key' => 'collid',
            'join_path' => [
                // omcollections -> omoccurrences (via collid)
                ['from_table' => 'omcollections', 'from_col' => 'collid', 'to_table' => 'omoccurrences', 'to_col' => 'collid'],
                // omoccurrences -> media (via occid)
                ['from_table' => 'omoccurrences', 'from_col' => 'occid', 'to_table' => 'media', 'to_col' => 'occid']
            ],
            'numeric_fields' => ['collid']
        ],

        'taxa' => [
            'priority' => 4,
            'primary_key' => 'tid',
            'join_path' => [
                // taxa -> omoccurrences (via tidInterpreted)
                ['from_table' => 'taxa', 'from_col' => 'tid', 'to_table' => 'omoccurrences', 'to_col' => 'tidInterpreted'],
                // omoccurrences -> media (via occid)
                ['from_table' => 'omoccurrences', 'from_col' => 'occid', 'to_table' => 'media', 'to_col' => 'occid']
            ],
            'numeric_fields' => ['tid']
        ],

        'taxstatus' => [
            'priority' => 5,
            'primary_key' => 'tid',
            'join_path' => [
                // taxstatus -> taxa (via tid)
                ['from_table' => 'taxstatus', 'from_col' => 'tid', 'to_table' => 'taxa', 'to_col' => 'tid'],
                // taxa -> omoccurrences (via tidInterpreted)
                ['from_table' => 'taxa', 'from_col' => 'tid', 'to_table' => 'omoccurrences', 'to_col' => 'tidInterpreted'],
                // omoccurrences -> media (via occid)
                ['from_table' => 'omoccurrences', 'from_col' => 'occid', 'to_table' => 'media', 'to_col' => 'occid']
            ],
            'numeric_fields' => ['tid', 'parenttid']
        ]
    ],

    /**
     * Global Numeric Fields
     * 
     * Additional numeric fields that should be treated as numbers across all tables.
     * Table-specific numeric fields are defined in the 'tables' section above.
     */
    'global_numeric_fields' => [
        'decimalLatitude',
        'decimalLongitude',
        'minimumElevationInMeters',
        'maximumElevationInMeters'
    ]
];

