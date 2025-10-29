<?php
/**
 * Symbiota Portal Toolkit - Image Carousel Include
 *
 * This file can be included in any Symbiota portal page to display
 * a modern image carousel powered by the tk images model.
 *
 * Usage:
 *   <?php include($SERVER_ROOT.'/tk/includes/carousel.php'); ?>
 *
 * Optional parameters (set before including):
 *   $carousel_limit = 10;           // Number of random images (default: 10)
 *   $carousel_width = 600;          // Width in pixels (default: 600)
 *   $carousel_media_ids = '1,2,3';  // Specific mediaIDs to display (optional)
 *   $carousel_shuffle = false;      // Randomize order of images (useful with media_ids)
 *   $carousel_silent = false;       // If true, suppress error messages (for frontpage)
 *
 * Example 1 - Random images:
 *   <?php
 *   $carousel_limit = 15;
 *   $carousel_width = 800;
 *   include($SERVER_ROOT.'/tk/includes/carousel.php');
 *   ?>
 *
 * Example 2 - Specific images (curated list):
 *   <?php
 *   $carousel_media_ids = '12345,67890,11111';  // Curated list of beautiful specimens
 *   $carousel_width = 800;
 *   include($SERVER_ROOT.'/tk/includes/carousel.php');
 *   ?>
 *
 * Example 3 - Specific images in random order:
 *   <?php
 *   $carousel_media_ids = '12345,67890,11111,22222,33333';  // Curated list
 *   $carousel_shuffle = true;  // Randomize order each page load
 *   $carousel_width = 800;
 *   include($SERVER_ROOT.'/tk/includes/carousel.php');
 *   ?>
 */

// Get parameters or use defaults
$carouselLimit = isset($carousel_limit) ? (int)$carousel_limit : 10;
$carouselWidth = isset($carousel_width) ? (int)$carousel_width : 600;
$carouselMediaIds = isset($carousel_media_ids) ? $carousel_media_ids : null;
$carouselShuffle = isset($carousel_shuffle) ? (bool)$carousel_shuffle : false;
$carouselSilent = isset($carousel_silent) ? (bool)$carousel_silent : false;

// Load tk autoloader if not already loaded
if (!class_exists('Symbiota\Helpers\Core\Environment')) {
    require_once(__DIR__ . '/../vendor/autoload.php');
}

use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Core\Configuration;
use Symbiota\Helpers\Models\ImagesModel;

try {
    // Determine tk root directory (parent of includes/)
    $tkRoot = dirname(__DIR__);

    // Initialize configuration if not already done
    $config = Configuration::getInstance();
    if (!$config) {
        // Load config.php if it exists, otherwise use defaults with explicit templates_path
        $configFile = $tkRoot . '/config.php';

        if (file_exists($configFile)) {
            Configuration::initialize($configFile);
        } else {
            // Initialize with minimal config including templates_path
            Configuration::initialize([
                'templates_path' => $tkRoot . '/templates'
            ]);
        }
        $config = Configuration::getInstance();
    }

    // Get environment instance (auto-initializes)
    $env = Environment::getInstance();

    // Create images model with explicit templates_path
    $imagesModel = new ImagesModel([
        'templates_path' => $tkRoot . '/templates'
    ]);

    // Build carousel parameters
    $carouselParams = [
        'limit' => $carouselLimit,
        'width' => $carouselWidth
    ];

    // Add media_ids if specified
    if ($carouselMediaIds !== null) {
        $carouselParams['media_ids'] = $carouselMediaIds;
    }

    // Add shuffle if specified
    if ($carouselShuffle) {
        $carouselParams['shuffle'] = true;
    }

    // Generate carousel
    $result = $imagesModel->handleAction('carousel', [], $carouselParams);

    // Output carousel HTML
    if (isset($result['type']) && $result['type'] === 'html' && !empty($result['content'])) {
        echo $result['content'];
    } elseif (isset($result['type']) && $result['type'] === 'error') {
        error_log('Carousel error: ' . ($result['message'] ?? 'Unknown error'));
        if (!$carouselSilent) {
            echo '<div class="alert alert-warning">Unable to load image carousel: ' . htmlspecialchars($result['message'] ?? 'Unknown error') . '</div>';
        }
    } else {
        error_log('Carousel unexpected result: ' . print_r($result, true));
        if (!$carouselSilent) {
            echo '<div class="alert alert-info">Image carousel unavailable</div>';
        }
    }

} catch (Exception $e) {
    error_log('Carousel exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!$carouselSilent) {
        echo '<div class="alert alert-warning">Unable to load image carousel: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

