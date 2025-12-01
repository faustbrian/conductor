<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\MorphType;
use Cline\Sequencer\Enums\PrimaryKeyType;

/**
 * Configuration Test Suite
 *
 * Tests Sequencer configuration options and defaults.
 */
describe('Configuration', function (): void {
    describe('Primary Key Configuration', function (): void {
        test('default primary key type is id', function (): void {
            expect(config('sequencer.primary_key_type'))->toBe('id');
        });

        test('primary key type enum has all expected values', function (): void {
            expect(PrimaryKeyType::ID->value)->toBe('id');
            expect(PrimaryKeyType::ULID->value)->toBe('ulid');
            expect(PrimaryKeyType::UUID->value)->toBe('uuid');
        });

        test('primary key type can be configured', function (): void {
            config(['sequencer.primary_key_type' => 'ulid']);

            expect(config('sequencer.primary_key_type'))->toBe('ulid');
        });
    });

    describe('Morph Type Configuration', function (): void {
        test('default morph type is morphs', function (): void {
            expect(config('sequencer.morph_type'))->toBe('string');
        });

        test('morph type enum has all expected values', function (): void {
            expect(MorphType::String->value)->toBe('string');
            expect(MorphType::Numeric->value)->toBe('numeric');
            expect(MorphType::UUID->value)->toBe('uuid');
            expect(MorphType::ULID->value)->toBe('ulid');
        });

        test('morph type can be configured', function (): void {
            config(['sequencer.morph_type' => 'uuid']);

            expect(config('sequencer.morph_type'))->toBe('uuid');
        });
    });

    describe('Execution Configuration', function (): void {
        test('sequential execution enabled by default', function (): void {
            expect(config('sequencer.execution.sequential'))->toBeTrue();
        });

        test('auto transaction enabled by default', function (): void {
            expect(config('sequencer.execution.auto_transaction'))->toBeTrue();
        });

        test('discovery paths configured', function (): void {
            $paths = config('sequencer.execution.discovery_paths');

            expect($paths)->toBeArray();
            expect($paths)->not->toBeEmpty();
        });

        test('lock configuration has required keys', function (): void {
            $lockConfig = config('sequencer.execution.lock');

            expect($lockConfig)->toHaveKey('store');
            expect($lockConfig)->toHaveKey('timeout');
            expect($lockConfig)->toHaveKey('ttl');
        });
    });

    describe('Queue Configuration', function (): void {
        test('queue connection can be null', function (): void {
            expect(config('sequencer.queue.connection'))->toBeNull();
        });

        test('queue name defaults to default', function (): void {
            expect(config('sequencer.queue.queue'))->toBe('default');
        });
    });

    describe('Error Configuration', function (): void {
        test('error recording enabled by default', function (): void {
            expect(config('sequencer.errors.record'))->toBeTrue();
        });

        test('log channel defaults to stack', function (): void {
            expect(config('sequencer.errors.log_channel'))->toBe('stack');
        });
    });

    describe('Reporting Configuration', function (): void {
        test('pulse integration disabled by default', function (): void {
            expect(config('sequencer.reporting.pulse'))->toBeFalse();
        });

        test('telescope integration disabled by default', function (): void {
            expect(config('sequencer.reporting.telescope'))->toBeFalse();
        });
    });

    describe('Model Configuration', function (): void {
        test('operation model configured', function (): void {
            $model = config('sequencer.models.operation');

            expect($model)->toBe(Operation::class);
        });

        test('operation error model configured', function (): void {
            $model = config('sequencer.models.operation_error');

            expect($model)->toBe(OperationError::class);
        });
    });

    describe('Table Names Configuration', function (): void {
        test('operations table name configured', function (): void {
            expect(config('sequencer.table_names.operations'))->toBe('operations');
        });

        test('operation errors table name configured', function (): void {
            expect(config('sequencer.table_names.operation_errors'))->toBe('operation_errors');
        });
    });

    describe('Morph Key Mapping', function (): void {
        test('morph key map is array', function (): void {
            expect(config('sequencer.morphKeyMap'))->toBeArray();
        });

        test('enforce morph key map is array', function (): void {
            expect(config('sequencer.enforceMorphKeyMap'))->toBeArray();
        });

        test('morph key map can be configured', function (): void {
            config(['sequencer.morphKeyMap' => ['App\\Models\\User' => 'id']]);

            expect(config('sequencer.morphKeyMap'))->toHaveKey('App\\Models\\User');
        });
    });
});
