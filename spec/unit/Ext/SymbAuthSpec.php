<?php

use Symbiota\Helpers\Ext\SymbAuth;

describe('SymbAuth', function() {

    beforeEach(function() {
        // Reset auth state before each test
        SymbAuth::disableTestMode();
    });

    afterEach(function() {
        // Clean up after each test
        SymbAuth::disableTestMode();
    });

    describe('test mode functionality', function() {

        it('should start with test mode disabled', function() {
            expect(SymbAuth::isTestMode())->toBe(false);
            expect(SymbAuth::isAuthenticated())->toBe(false);
        });

        it('should enable test mode with default superadmin user', function() {
            SymbAuth::enableTestMode();

            expect(SymbAuth::isTestMode())->toBe(true);
            expect(SymbAuth::isAuthenticated())->toBe(true);

            $user = SymbAuth::getCurrentUser();
            expect($user['uid'])->toBe(433);
            expect($user['username'])->toBe('superadmin');
            expect($user['role'])->toBe('SuperAdmin');
        });

        it('should enable test mode with specific user', function() {
            SymbAuth::enableTestMode(525);

            expect(SymbAuth::isAuthenticated())->toBe(true);

            $user = SymbAuth::getCurrentUser();
            expect($user['uid'])->toBe(525);
            expect($user['username'])->toBe('colladmin');
            expect($user['role'])->toBe('CollAdmin');
        });

        it('should disable test mode', function() {
            SymbAuth::enableTestMode();
            expect(SymbAuth::isAuthenticated())->toBe(true);

            SymbAuth::disableTestMode();
            expect(SymbAuth::isTestMode())->toBe(false);
            expect(SymbAuth::isAuthenticated())->toBe(false);
        });
    });

    describe('permission checking', function() {

        it('should check backup permissions for superadmin', function() {
            SymbAuth::enableTestMode(433);

            expect(SymbAuth::hasPermission('backup'))->toBe(true);
            expect(SymbAuth::hasPermission('upload'))->toBe(true);
            expect(SymbAuth::hasPermission('admin'))->toBe(true);
            expect(SymbAuth::canAccessBackup())->toBe(true);
            expect(SymbAuth::canAccessUpload())->toBe(true);
        });

        it('should check backup permissions for colladmin', function() {
            SymbAuth::enableTestMode(525);

            expect(SymbAuth::hasPermission('backup'))->toBe(true);
            expect(SymbAuth::hasPermission('upload'))->toBe(true);
            expect(SymbAuth::hasPermission('admin'))->toBe(false);
            expect(SymbAuth::canAccessBackup())->toBe(true);
            expect(SymbAuth::canAccessUpload())->toBe(true);
        });

        it('should deny permissions when not authenticated', function() {
            expect(SymbAuth::hasPermission('backup'))->toBe(false);
            expect(SymbAuth::hasPermission('upload'))->toBe(false);
            expect(SymbAuth::canAccessBackup())->toBe(false);
            expect(SymbAuth::canAccessUpload())->toBe(false);
        });
    });

    describe('user display information', function() {

        it('should return guest display name when not authenticated', function() {
            expect(SymbAuth::getDisplayName())->toBe('Guest');
            expect(SymbAuth::getGreeting())->toBe('');
        });

        xit('should return proper display name for superadmin', function() {
            SymbAuth::enableTestMode(433);

            expect(SymbAuth::getDisplayName())->toBe('Super Administrator');
            expect(SymbAuth::getGreeting())->toBe('Super Administrator (SuperAdmin)');
        });

        xit('should return proper display name for colladmin', function() {
            SymbAuth::enableTestMode(525);

            expect(SymbAuth::getDisplayName())->toBe('Collection Administrator');
            expect(SymbAuth::getGreeting())->toBe('Collection Administrator (CollAdmin)');
        });
    });

    describe('authentication status', function() {

        xit('should return complete auth status when authenticated', function() {
            SymbAuth::enableTestMode(433);

            $status = SymbAuth::getAuthStatus();

            expect($status['authenticated'])->toBe(true);
            expect($status['user']['uid'])->toBe(433);
            expect($status['display_name'])->toBe('Super Administrator');
            expect($status['greeting'])->toBe('Super Administrator (SuperAdmin)');
            expect($status['can_backup'])->toBe(true);
            expect($status['can_upload'])->toBe(true);
            expect($status['is_admin'])->toBe(true);
        });

        it('should return guest status when not authenticated', function() {
            $status = SymbAuth::getAuthStatus();

            expect($status['authenticated'])->toBe(false);
            expect($status['user'])->toBe(null);
            expect($status['display_name'])->toBe('Guest');
            expect($status['greeting'])->toBe('');
            expect($status['can_backup'])->toBe(false);
            expect($status['can_upload'])->toBe(false);
            expect($status['is_admin'])->toBe(false);
        });

        xit('should return CollAdmin status with limited permissions', function() {
            SymbAuth::enableTestMode(525);

            $status = SymbAuth::getAuthStatus();

            expect($status['authenticated'])->toBe(true);
            expect($status['user']['uid'])->toBe(525);
            expect($status['display_name'])->toBe('Collection Administrator');
            expect($status['greeting'])->toBe('Collection Administrator (CollAdmin)');
            expect($status['can_backup'])->toBe(true);
            expect($status['can_upload'])->toBe(true);
            expect($status['is_admin'])->toBe(false);
        });

        xit('should show SuperAdmin greeting with [su] suffix', function() {
            SymbAuth::enableTestMode(433);

            $status = SymbAuth::getAuthStatus();

            expect($status['greeting'])->toBe('Super Administrator (SuperAdmin) [su]');
        });

        xit('should show CollAdmin greeting without [su] suffix', function() {
            SymbAuth::enableTestMode(525);

            $status = SymbAuth::getAuthStatus();

            expect($status['greeting'])->toBe('Collection Administrator (CollAdmin)');
        });
    });

    describe('test users', function() {

        it('should provide access to test users', function() {
            $testUsers = SymbAuth::getTestUsers();

            expect($testUsers)->toBeA('array');
            expect(array_key_exists(433, $testUsers))->toBe(true);
            expect(array_key_exists(525, $testUsers))->toBe(true);
            expect($testUsers[433]['username'])->toBe('superadmin');
            expect($testUsers[525]['username'])->toBe('colladmin');
        });
    });
});
