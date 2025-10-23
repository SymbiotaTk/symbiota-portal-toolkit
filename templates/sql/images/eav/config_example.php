<?php
/**
 * EAV Indexing Configuration Example
 * 
 * Add this to your config.php file to customize EAV indexing behavior
 */

return [
    'mod' => [
        'images' => [
            /**
             * EAV Batch Size
             * 
             * Number of rows to commit in a single transaction during TSV import.
             * Higher values = faster import, but more memory usage.
             * 
             * Default: 27000
             * 
             * To determine optimal value for your system:
             * 1. Run: php index.php images status
             * 2. Check "recommended_batch_size" in Memory & Performance section
             * 3. Set this value accordingly
             * 
             * Memory considerations:
             * - 128MB memory_limit → ~27,000 batch size (safe default)
             * - 256MB memory_limit → ~100,000 batch size
             * - 512MB memory_limit → ~100,000 batch size (capped at max)
             * 
             * If you see "WARNING: May exceed memory limit" in status output,
             * reduce this value or increase PHP memory_limit.
             */
            'eav_batch_size' => 27000,
            
            /**
             * Other Images Module Settings
             */
            'images_per_request' => 30,
            'images_enable_random' => true,
            'images_cache_hours' => 168,  // 7 days
            'validate_images' => false,
            'validation_timeout' => 30,
            'validation_connect_timeout' => 10,
            'max_concurrent_validations' => 5,
            'log_invalid_images' => '',
            'registry_file' => '/var/www/temp/bioc/data/images.json',
            'images_cache_db' => '/var/www/temp/bioc/data/images_cache.db'
        ]
    ]
];

