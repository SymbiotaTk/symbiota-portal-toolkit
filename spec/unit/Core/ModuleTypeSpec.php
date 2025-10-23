<?php

use Symbiota\Helpers\Core\ModuleType;

describe('ModuleType', function() {

    describe('enum values', function() {
        it('should define COMPONENT type', function() {
            expect(ModuleType::COMPONENT)->toBeAnInstanceOf(ModuleType::class);
            expect(ModuleType::COMPONENT->name)->toBe('COMPONENT');
            expect(ModuleType::COMPONENT->value)->toBe('hComponent');
        });

        it('should define SERVICE type', function() {
            expect(ModuleType::SERVICE)->toBeAnInstanceOf(ModuleType::class);
            expect(ModuleType::SERVICE->name)->toBe('SERVICE');
            expect(ModuleType::SERVICE->value)->toBe('hService');
        });

        it('should define LIBRARY type', function() {
            expect(ModuleType::LIBRARY)->toBeAnInstanceOf(ModuleType::class);
            expect(ModuleType::LIBRARY->name)->toBe('LIBRARY');
            expect(ModuleType::LIBRARY->value)->toBe('hLibrary');
        });
    });

    describe('enum comparison', function() {
        it('should allow equality comparison', function() {
            $type1 = ModuleType::COMPONENT;
            $type2 = ModuleType::COMPONENT;
            $type3 = ModuleType::SERVICE;

            expect($type1 === $type2)->toBe(true);
            expect($type1 === $type3)->toBe(false);
        });

        it('should work with match expressions', function() {
            $type = ModuleType::COMPONENT;

            $result = match($type) {
                ModuleType::COMPONENT => 'component',
                ModuleType::SERVICE => 'service',
                ModuleType::LIBRARY => 'library'
            };

            expect($result)->toBe('component');
        });

        it('should work with switch statements', function() {
            $type = ModuleType::SERVICE;
            $result = '';

            switch($type) {
                case ModuleType::COMPONENT:
                    $result = 'component';
                    break;
                case ModuleType::SERVICE:
                    $result = 'service';
                    break;
                case ModuleType::LIBRARY:
                    $result = 'library';
                    break;
            }

            expect($result)->toBe('service');
        });
    });

    describe('enum methods', function() {
        it('should provide cases() method', function() {
            $cases = ModuleType::cases();

            expect($cases)->toBeA('array');
            expect(count($cases))->toBe(4);
            expect($cases[0])->toBeAnInstanceOf(ModuleType::class);
            expect($cases[1])->toBeAnInstanceOf(ModuleType::class);
            expect($cases[2])->toBeAnInstanceOf(ModuleType::class);
        });

        it('should have name property', function() {
            expect(ModuleType::COMPONENT->name)->toBe('COMPONENT');
            expect(ModuleType::SERVICE->name)->toBe('SERVICE');
            expect(ModuleType::LIBRARY->name)->toBe('LIBRARY');
        });
    });

    describe('type semantics', function() {
        it('should represent different module categories', function() {
            // COMPONENT: Interactive tools with UI
            $component = ModuleType::COMPONENT;
            expect($component->name)->toBe('COMPONENT');

            // SERVICE: API endpoints and services
            $service = ModuleType::SERVICE;
            expect($service->name)->toBe('SERVICE');

            // LIBRARY: Utility classes and helpers
            $library = ModuleType::LIBRARY;
            expect($library->name)->toBe('LIBRARY');
        });
    });

    describe('serialization', function() {
        it('should be serializable', function() {
            $type = ModuleType::COMPONENT;
            $serialized = serialize($type);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBe($type);
            expect($unserialized->name)->toBe('COMPONENT');
        });

        it('should work with json encoding', function() {
            $type = ModuleType::SERVICE;
            $json = json_encode(['type' => $type->name]);
            $decoded = json_decode($json, true);

            expect($decoded['type'])->toBe('SERVICE');
        });
    });

    describe('type checking', function() {
        it('should work with instanceof', function() {
            $type = ModuleType::LIBRARY;

            expect($type instanceof ModuleType)->toBe(true);
            expect($type instanceof \BackedEnum)->toBe(true); // IS a backed enum (has string values)
            expect($type instanceof \UnitEnum)->toBe(true); // Also implements UnitEnum
        });

        it('should work with is_a function', function() {
            $type = ModuleType::COMPONENT;

            expect(is_a($type, ModuleType::class))->toBe(true);
            expect(is_a($type, \UnitEnum::class))->toBe(true);
        });
    });

    describe('array operations', function() {
        it('should work as array keys using values', function() {
            // Backed enums can be used as array keys by using their values
            $array = [
                ModuleType::COMPONENT->value => 'Interactive tools',
                ModuleType::SERVICE->value => 'API endpoints',
                ModuleType::LIBRARY->value => 'Utility classes'
            ];

            expect($array[ModuleType::COMPONENT->value])->toBe('Interactive tools');
            expect($array[ModuleType::SERVICE->value])->toBe('API endpoints');
            expect($array[ModuleType::LIBRARY->value])->toBe('Utility classes');
        });

        it('should work with in_array', function() {
            $types = [ModuleType::COMPONENT, ModuleType::SERVICE];

            expect(in_array(ModuleType::COMPONENT, $types, true))->toBe(true);
            expect(in_array(ModuleType::LIBRARY, $types, true))->toBe(false);
        });
    });

    describe('string representation', function() {
        it('should have meaningful string representation', function() {
            // Backed enums can be converted to string using their value property
            $component = ModuleType::COMPONENT->value;
            $service = ModuleType::SERVICE->value;
            $library = ModuleType::LIBRARY->value;

            // All should be strings
            expect($component)->toBeA('string');
            expect($service)->toBeA('string');
            expect($library)->toBeA('string');

            // They should be different and meaningful
            expect($component)->toBe('hComponent');
            expect($service)->toBe('hService');
            expect($library)->toBe('hLibrary');

            // They should be different
            expect($component)->not->toBe($service);
            expect($service)->not->toBe($library);
            expect($component)->not->toBe($library);
        });
    });

    describe('enum methods', function() {
        it('should provide getDescription() method', function() {
            expect(ModuleType::COMPONENT->getDescription())->toBe('User-facing component displayed in UI');
            expect(ModuleType::SERVICE->getDescription())->toBe('Backend service providing endpoints');
            expect(ModuleType::LIBRARY->getDescription())->toBe('Internal library for system functionality');
        });

        it('should provide isDisplayable() method', function() {
            expect(ModuleType::COMPONENT->isDisplayable())->toBe(true);
            expect(ModuleType::SERVICE->isDisplayable())->toBe(false);
            expect(ModuleType::LIBRARY->isDisplayable())->toBe(false);
        });

        it('should provide providesEndpoints() method', function() {
            expect(ModuleType::COMPONENT->providesEndpoints())->toBe(true);
            expect(ModuleType::SERVICE->providesEndpoints())->toBe(true);
            expect(ModuleType::LIBRARY->providesEndpoints())->toBe(false);
        });

        it('should provide isInternal() method', function() {
            expect(ModuleType::COMPONENT->isInternal())->toBe(false);
            expect(ModuleType::SERVICE->isInternal())->toBe(false);
            expect(ModuleType::LIBRARY->isInternal())->toBe(true);
        });

        it('should provide static all() method', function() {
            $all = ModuleType::all();
            expect($all)->toBeA('array');
            expect(count($all))->toBe(4);
            expect(in_array(ModuleType::COMPONENT, $all, true))->toBe(true);
            expect(in_array(ModuleType::SERVICE, $all, true))->toBe(true);
            expect(in_array(ModuleType::LIBRARY, $all, true))->toBe(true);
        });

        it('should provide static displayable() method', function() {
            $displayable = ModuleType::displayable();
            expect($displayable)->toBeA('array');
            expect(count($displayable))->toBe(1);
            expect(in_array(ModuleType::COMPONENT, $displayable, true))->toBe(true);
            expect(in_array(ModuleType::SERVICE, $displayable, true))->toBe(false);
            expect(in_array(ModuleType::LIBRARY, $displayable, true))->toBe(false);
        });

        it('should provide static services() method', function() {
            $services = ModuleType::services();
            expect($services)->toBeA('array');
            expect(count($services))->toBe(1);
            expect(in_array(ModuleType::SERVICE, $services, true))->toBe(true);
            expect(in_array(ModuleType::COMPONENT, $services, true))->toBe(false);
            expect(in_array(ModuleType::LIBRARY, $services, true))->toBe(false);
        });
    });

    describe('edge cases', function() {
        it('should handle null comparison', function() {
            $type = ModuleType::COMPONENT;

            expect($type === null)->toBe(false);
            expect($type !== null)->toBe(true);
        });

        it('should handle type coercion', function() {
            $type = ModuleType::SERVICE;

            // Should not be equal to strings
            expect($type == 'SERVICE')->toBe(false);
            expect($type === 'SERVICE')->toBe(false);
        });
    });
});
