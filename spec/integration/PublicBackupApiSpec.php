<?php

use Symbiota\Helpers\Models\BackupModel;

/**
 * Integration tests for Public Backup API endpoints
 * 
 * Tests the security and functionality of public backup endpoints
 * when http_post configuration is enabled/disabled.
 */
describe('PublicBackupApi', function() {

    beforeEach(function() {
        $this->originalConfig = [
            'components' => [
                'backup' => [
                    'http_post' => true,
                    'output_dir' => 'dev/test/downloads',
                    'site_salt' => 'test_salt_for_development_only',
                    'threshold_hours' => 0.01 // Very short threshold for testing
                ]
            ]
        ];

        $this->testCollectionId = 53;
        $this->model = new BackupModel($this->originalConfig);
    });

    afterAll(function() {
        // Clean up test directories created during tests
        // Recursively remove directory helper
        $removeDir = function($dir) use (&$removeDir) {
            if (!is_dir($dir)) {
                return;
            }
            $items = array_diff(scandir($dir), ['.', '..']);
            foreach ($items as $item) {
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $removeDir($path) : unlink($path);
            }
            rmdir($dir);
        };

        // Remove dev/test/myco and parent directories
        if (is_dir('dev/test/myco')) {
            $removeDir('dev/test/myco');
        }
        if (is_dir('dev/test')) {
            $removeDir('dev/test');
        }
        if (is_dir('dev')) {
            $removeDir('dev');
        }
    });

    describe('when http_post is enabled', function() {

        xit('allows public access to collection status', function() {
            // SKIPPED: Requires live MySQL database connection
            $result = $this->model->handleAction($this->testCollectionId, [], []);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('json');
            expect($result['data']['collid'])->toBe($this->testCollectionId);
        });

        xit('provides collection status via public api', function() {
            // SKIPPED: Requires live MySQL database connection
            $result = $this->model->handleAction($this->testCollectionId, [], []);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('json');
            expect($result['data'])->toBeA('array');
            expect($result['data']['collid'])->toBe($this->testCollectionId);
            expect($result['data'])->toContainKey('collection_name');
            expect($result['data'])->toContainKey('record_count');
            expect($result['data'])->toContainKey('estimated_size');
            expect($result['data'])->toContainKey('is_registered');
            expect($result['data'])->toContainKey('http_post');
        });

        xit('provides backup list via public api', function() {
            // SKIPPED: Requires live MySQL database connection
            $result = $this->model->handleAction($this->testCollectionId, ['list'], []);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('json');
            expect($result['data'])->toBeA('array');
            expect($result['data']['collid'])->toBe($this->testCollectionId);
            expect($result['data'])->toContainKey('backups');
        });

        it('provides backup download via public api', function() {
            $result = $this->model->handleAction($this->testCollectionId, ['download'], []);
            
            expect($result)->toBeA('array');
            // Should either return download info or error if no backups exist
            expect($result['type'])->toMatch('/^(download|error)$/');
        });

        it('blocks protected endpoints even with http_post enabled', function() {
            // Test register endpoint (should be protected)
            $result = $this->model->handleAction($this->testCollectionId, ['register'], []);
            
            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);
        });

        it('blocks clear endpoint even with http_post enabled', function() {
            // Test clear endpoint (should be protected)
            $result = $this->model->handleAction($this->testCollectionId, ['clear'], []);
            
            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);
        });

        it('blocks verify-pw endpoint even with http_post enabled', function() {
            // Test verify-pw endpoint (should be protected)
            $result = $this->model->handleAction($this->testCollectionId, ['verify-pw'], []);
            
            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);
        });
    });

    describe('when http_post is disabled', function() {
        
        beforeEach(function() {
            $disabledConfig = $this->originalConfig;
            $disabledConfig['components']['backup']['http_post'] = false;
            $this->model = new BackupModel($disabledConfig);
        });

        xit('denies access when http_post disabled', function() {
            // SKIPPED: Requires live MySQL database connection
            $result = $this->model->handleAction($this->testCollectionId, [], []);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);
        });

        xit('ignores url parameter attempts to enable http_post', function() {
            // SKIPPED: Requires live MySQL database connection
            // Try to override with URL parameter (should be ignored for security)
            $params = ['http-post' => 'true'];
            $result = $this->model->handleAction($this->testCollectionId, [], $params);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);
            expect($result['message'])->toContain('Authentication required');
        });

        xit('ignores post body attempts to enable http_post', function() {
            // SKIPPED: Requires live MySQL database connection
            // Simulate POST body data (should be ignored for security)
            $_POST['http-post'] = 'true';
            $params = ['http-post' => 'true'];

            $result = $this->model->handleAction($this->testCollectionId, [], $params);

            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(401);

            // Clean up
            unset($_POST['http-post']);
        });
    });

    describe('error handling', function() {
        
        it('returns proper error when collection not found', function() {
            $nonExistentCollectionId = 99999;
            $result = $this->model->handleAction($nonExistentCollectionId, [], []);
            
            expect($result)->toBeA('array');
            expect($result['type'])->toBe('error');
            expect($result['status_code'])->toBe(404);
        });
    });

    describe('http method security', function() {
        
        xit('maintains security with different http methods', function() {
            // SKIPPED: Requires live MySQL database connection
            // Test that GET, POST, PUT, DELETE all respect the same security rules
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $getResult = $this->model->handleAction($this->testCollectionId, [], []);
            expect($getResult['type'])->toBe('json');

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $postResult = $this->model->handleAction($this->testCollectionId, [], []);
            // POST to collection root should work (create backup) or return htmx
            expect($postResult['type'])->toMatch('/^(success|error|htmx)$/');

            // Clean up
            $_SERVER['REQUEST_METHOD'] = 'GET';
        });
    });
});
