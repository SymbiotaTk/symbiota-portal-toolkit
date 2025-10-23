<?php

/**
 * Shared test utilities for the Symbiota Helpers test suite
 * 
 * Provides common functionality used across multiple test files to reduce
 * code duplication and ensure consistent test behavior.
 */

if (!function_exists('removeDirectory')) {
    /**
     * Recursively remove a directory and all its contents
     * 
     * @param string $dir Directory path to remove
     * @return void
     */
    function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                removeDirectory($path);
            } else {
                // Ensure we can delete the file
                if (file_exists($path)) {
                    chmod($path, 0644);
                    unlink($path);
                }
            }
        }
        
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}

if (!function_exists('createTempDirectory')) {
    /**
     * Create a temporary directory for testing
     * 
     * @param string $prefix Prefix for the directory name
     * @return string Path to the created directory
     */
    function createTempDirectory(string $prefix = 'test_'): string
    {
        $tempDir = sys_get_temp_dir() . '/' . $prefix . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        return $tempDir;
    }
}

if (!function_exists('cleanupTempFiles')) {
    /**
     * Clean up temporary files and directories created during testing
     * 
     * @param array $paths Array of file/directory paths to clean up
     * @return void
     */
    function cleanupTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_dir($path)) {
                removeDirectory($path);
            } elseif (file_exists($path)) {
                chmod($path, 0644);
                unlink($path);
            }
        }
    }
}

if (!function_exists('createTestFile')) {
    /**
     * Create a test file with specified content
     * 
     * @param string $path File path
     * @param string $content File content
     * @return bool Success status
     */
    function createTestFile(string $path, string $content = 'test content'): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($path, $content) !== false;
    }
}

if (!function_exists('assertFileCleanup')) {
    /**
     * Assert that test files have been properly cleaned up
     *
     * @param array $paths Array of paths that should not exist
     * @return void
     */
    function assertFileCleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path) || is_dir($path)) {
                throw new Exception("Test cleanup failed: {$path} still exists");
            }
        }
    }
}

if (!function_exists('timeTest')) {
    /**
     * Time a test operation and log if it exceeds threshold
     *
     * @param string $name Test name
     * @param callable $callback Test callback
     * @param float $threshold Threshold in seconds (default: 0.1)
     * @return mixed Result of callback
     */
    function timeTest(string $name, callable $callback, float $threshold = 0.1)
    {
        $start = microtime(true);
        $result = $callback();
        $duration = microtime(true) - $start;

        if ($duration > $threshold) {
            error_log(sprintf("[SLOW TEST] %s took %.3fs (threshold: %.3fs)", $name, $duration, $threshold));
        }

        return $result;
    }
}

if (!function_exists('getTimingLogPath')) {
    /**
     * Get the path to the timing log file
     *
     * @return string
     */
    function getTimingLogPath(): string
    {
        return sys_get_temp_dir() . '/kahlan_timing.log';
    }
}

if (!function_exists('clearTimingLog')) {
    /**
     * Clear the timing log file
     *
     * @return void
     */
    function clearTimingLog(): void
    {
        $logFile = getTimingLogPath();
        if (file_exists($logFile)) {
            unlink($logFile);
        }
    }
}

if (!function_exists('getSlowTests')) {
    /**
     * Parse timing log and return slow tests
     *
     * @param float $threshold Minimum duration in seconds
     * @return array Array of slow tests
     */
    function getSlowTests(float $threshold = 0.1): array
    {
        $logFile = getTimingLogPath();
        if (!file_exists($logFile)) {
            return [];
        }

        $content = file_get_contents($logFile);
        $slowTests = [];

        // Parse SLOW TEST lines
        if (preg_match_all('/SLOW TEST: (.+?) \(([0-9.]+)s\) \[(\w+)\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $duration = (float)$match[2];
                if ($duration >= $threshold) {
                    $slowTests[] = [
                        'name' => $match[1],
                        'duration' => $duration,
                        'status' => $match[3]
                    ];
                }
            }
        }

        // Sort by duration (slowest first)
        usort($slowTests, function($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });

        return $slowTests;
    }
}
