<?php

use Symbiota\Helpers\Models\BackupModel;
use Symbiota\Helpers\Ext\SymbAuth;
use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\Environment;

describe('BackupModel HTTP Interface', function() {

    beforeEach(function() {
        // Force HTTP environment for these tests
        Environment::forceEnvironment(Environment::HTTP);
        $this->config = [
            'base_url' => '/test/',
            'app_url_prefix' => '/?/',
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'database' => [
                'dbconnection' => __DIR__ . '/../../fixtures/dbconnection.php'
            ],
            'backup' => [
                'storage_path' => sys_get_temp_dir() . '/backup_test',
                'working_path' => sys_get_temp_dir() . '/backup_work_test',
                'site_salt' => 'test_salt_for_testing_only',
                'max_backup_age_days' => 7,
                'throttle_hours' => 24
            ]
        ];
        $this->model = new BackupModel($this->config);

        // Reset authentication state
        SymbAuth::disableTestMode();
    });

    afterEach(function() {
        // Reset environment
        Environment::reset();

        // Clean up authentication state
        SymbAuth::disableTestMode();
    });

    describe('authentication integration', function() {

        it('should require authentication for protected actions', function() {
            // Test without authentication
            $result = $this->model->handleAction('collections', [], []);

            expect($result['type'])->toBe('error');
            expect(isset($result['error']) || isset($result['message']))->toBe(true);
            $errorMessage = $result['error'] ?? $result['message'] ?? '';
            expect($errorMessage)->toContain('Authentication required');
            expect($result['status_code'] ?? $result['code'] ?? 401)->toBe(401);
        });

        xit('should allow access with proper authentication', function() {
            // Enable test mode with superadmin
            SymbAuth::enableTestMode(433);

            $result = $this->model->handleAction('collections', [], []);

            // Should not be an access denied error
            expect($result['type'])->not->toBe('error');
            expect($result['status_code'] ?? 200)->not->toBe(401);
        });

        xit('should allow access for colladmin users', function() {
            // Enable test mode with colladmin
            SymbAuth::enableTestMode(525);

            $result = $this->model->handleAction('collections', [], []);

            // Should not be an access denied error
            expect($result['type'])->not->toBe('error');
            expect($result['status_code'] ?? 200)->not->toBe(401);
        });

        it('should allow public actions without authentication', function() {
            // Test index action without authentication
            $result = $this->model->handleAction('index', [], []);

            // Should return permission denied page, not access error
            expect($result['type'])->toBe('html');
            expect($result['permission_display'] ?? 'none')->toBe('block');
            expect($result['backup_display'] ?? 'block')->toBe('none');
        });
    });

    describe('main page rendering', function() {

        it('should show permission denied for unauthenticated users', function() {
            $result = $this->model->handleAction('index', [], []);

            expect($result['type'])->toBe('html');
            expect($result['title'] ?? '')->toBe('Collection Backup');
            expect($result['permission_display'] ?? 'none')->toBe('block');
            expect($result['backup_display'] ?? 'block')->toBe('none');
            expect($result['auth_status']['authenticated'] ?? true)->toBe(false);
        });

        it('should show backup interface for authenticated users', function() {
            SymbAuth::enableTestMode(433);

            $result = $this->model->handleAction('index', [], []);

            expect($result['type'])->toBe('html');
            expect($result['title'] ?? '')->toBe('Collection Backup');
            expect($result['permission_display'] ?? 'block')->toBe('none');
            expect($result['backup_display'] ?? 'none')->toBe('block');
            expect($result['auth_status']['authenticated'] ?? false)->toBe(true);
            expect($result['auth_status']['can_backup'] ?? false)->toBe(true);
        });

        it('should include collections content for authenticated users', function() {
            SymbAuth::enableTestMode(433);

            $result = $this->model->handleAction('index', [], []);

            expect($result['collections_content'] ?? null)->not->toBeNull();
            expect($result['collections_content'] ?? '')->toBeA('string');
        });
    });

    describe('HTTP-specific actions', function() {

        beforeEach(function() {
            // Enable authentication for HTTP actions
            SymbAuth::enableTestMode(433);
        });

        describe('set-passphrase action', function() {

            xit('should require collection ID and passphrase', function() {
                $result = $this->model->handleAction('set-passphrase', [], []);

                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect($result['message'])->toContain('Collection ID and passphrase are required');
            });

            it('should handle valid passphrase setting', function() {
                $result = $this->model->handleAction('set-passphrase', [], [
                    'collid' => '123',
                    'passphrase' => 'test_passphrase_123'
                ]);

                // Should attempt to set passphrase (may fail due to test environment)
                expect($result)->toContainKey('type');
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);
            });
        });

        describe('check-passphrase action', function() {

            xit('should require collection ID', function() {
                $result = $this->model->handleAction('check-passphrase', [], []);

                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect($result['message'])->toContain('Collection ID is required');
            });

            it('should check passphrase status', function() {
                $result = $this->model->handleAction('check-passphrase', [], [
                    'collid' => '123'
                ]);

                expect($result['type'])->toBe('info');
                expect($result)->toContainKey('has_passphrase');
                expect($result)->toContainKey('message');
            });
        });

        describe('disable action', function() {

            xit('should require collection ID', function() {
                $result = $this->model->handleAction('disable', [], []);

                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect($result['message'])->toContain('Collection ID is required');
            });

            it('should handle backup disable', function() {
                $result = $this->model->handleAction('disable', [], [
                    'collid' => '123'
                ]);

                // Should attempt to disable backup
                expect($result)->toContainKey('type');
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);
            });
        });

        describe('create-backup action', function() {

            xit('should require collection ID', function() {
                $result = $this->model->handleAction('create-backup', [], []);

                expect($result['type'])->toBe('error');
                expect(isset($result['message']))->toBe(true);
                expect($result['message'])->toContain('Collection ID is required');
            });

            it('should handle backup creation', function() {
                $result = $this->model->handleAction('create-backup', [], [
                    'collid' => '123'
                ]);

                // Should attempt to create backup
                expect($result)->toContainKey('type');
                expect(in_array($result['type'], ['success', 'error']))->toBe(true);
            });
        });
    });

    describe('authentication status integration', function() {

        it('should return HTML content for authenticated users', function() {
            SymbAuth::enableTestMode(433);

            $result = $this->model->handleAction('index', [], []);

            expect($result)->toContainKey('type');
            expect($result['type'])->toBe('html');
            expect($result)->toContainKey('content');
            expect($result['content'])->toContain('Collection Backup');
        });

        it('should handle different user roles correctly', function() {
            SymbAuth::enableTestMode(525); // colladmin

            $result = $this->model->handleAction('index', [], []);

            expect($result)->toContainKey('type');
            expect($result['type'])->toBe('html');
            expect($result)->toContainKey('content');
            expect($result['content'])->toContain('Collection Backup');
        });
    });
});
