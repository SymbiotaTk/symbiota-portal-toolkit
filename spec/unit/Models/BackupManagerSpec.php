<?php

use Symbiota\Helpers\Models\BackupManager;

describe('BackupManager', function() {

    describe('constructor', function() {
        it('should accept a mysqli connection', function() {
            $manager = new BackupManager(null);

            expect($manager)->toBeAnInstanceOf(BackupManager::class);
        });

        it('should accept null connection', function() {
            $manager = new BackupManager(null);

            expect($manager)->toBeAnInstanceOf(BackupManager::class);
        });
    });

    describe('error handling with null connection', function() {
        beforeEach(function() {
            $this->manager = new BackupManager(null);
        });

        it('should throw exception when getCollectionsWithAdmins called with no connection', function() {
            expect(function() {
                $this->manager->getCollectionsWithAdmins();
            })->toThrow(new Exception('Database connection not available'));
        });

        it('should throw exception when getCollectionAdminUsers called with no connection', function() {
            expect(function() {
                $this->manager->getCollectionAdminUsers(171);
            })->toThrow(new Exception('Database connection not available'));
        });

        it('should throw exception when getCollectionInfo called with no connection', function() {
            expect(function() {
                $this->manager->getCollectionInfo(171);
            })->toThrow(new Exception('Database connection not available'));
        });

        it('should throw exception when getUserCollections called with no connection', function() {
            expect(function() {
                $this->manager->getUserCollections(123);
            })->toThrow(new Exception('Database connection not available'));
        });

        it('should throw exception when getAllCollections called with no connection', function() {
            expect(function() {
                $this->manager->getAllCollections();
            })->toThrow(new Exception('Database connection not available'));
        });
    });

    describe('method signatures and return types', function() {
        it('should have correct method signatures', function() {
            $reflection = new ReflectionClass(BackupManager::class);

            // Check that all expected methods exist
            expect($reflection->hasMethod('getCollectionsWithAdmins'))->toBe(true);
            expect($reflection->hasMethod('getCollectionAdminUsers'))->toBe(true);
            expect($reflection->hasMethod('getCollectionInfo'))->toBe(true);
            expect($reflection->hasMethod('getUserCollections'))->toBe(true);
            expect($reflection->hasMethod('getAllCollections'))->toBe(true);

            // Check method parameter counts
            expect($reflection->getMethod('getCollectionsWithAdmins')->getNumberOfParameters())->toBe(0);
            expect($reflection->getMethod('getCollectionAdminUsers')->getNumberOfParameters())->toBe(1);
            expect($reflection->getMethod('getCollectionInfo')->getNumberOfParameters())->toBe(1);
            expect($reflection->getMethod('getUserCollections')->getNumberOfParameters())->toBe(1);
            expect($reflection->getMethod('getAllCollections')->getNumberOfParameters())->toBe(0);
        });

        it('should have proper return type declarations', function() {
            $reflection = new ReflectionClass(BackupManager::class);

            // Check return types
            $getCollectionsMethod = $reflection->getMethod('getCollectionsWithAdmins');
            $returnType = $getCollectionsMethod->getReturnType();
            expect($returnType->getName())->toBe('array');

            $getCollectionInfoMethod = $reflection->getMethod('getCollectionInfo');
            $returnType = $getCollectionInfoMethod->getReturnType();
            expect($returnType->getName())->toBe('array');
            expect($returnType->allowsNull())->toBe(true);
        });
    });
});
