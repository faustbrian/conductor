<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;

beforeEach(function (): void {
    // Create test operations
    Operation::query()->create([
        'name' => 'test_completed',
        'type' => 'sync',
        'executed_at' => now()->subHours(2),
        'completed_at' => now()->subHours(1),
    ]);

    Operation::query()->create([
        'name' => 'test_failed',
        'type' => 'async',
        'executed_at' => now()->subHours(3),
        'failed_at' => now()->subHours(2),
    ]);

    Operation::query()->create([
        'name' => 'test_rolled_back',
        'type' => 'sync',
        'executed_at' => now()->subHours(4),
        'completed_at' => now()->subHours(3),
        'rolled_back_at' => now()->subHours(2),
    ]);

    Operation::query()->create([
        'name' => 'test_pending',
        'type' => 'async',
        'executed_at' => now()->subMinutes(5),
    ]);

    Operation::query()->create([
        'name' => 'seed_users',
        'type' => 'sync',
        'executed_at' => now()->subDays(1),
        'completed_at' => now()->subDays(1),
    ]);
});

test('completed scope returns only completed operations', function (): void {
    $operations = Operation::completed()->get();

    expect($operations)->toHaveCount(3)
        ->and($operations->every(fn ($op): bool => $op->completed_at !== null))->toBeTrue();
});

test('failed scope returns only failed operations', function (): void {
    $operations = Operation::failed()->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->name)->toBe('test_failed');
});

test('pending scope returns only pending operations', function (): void {
    $operations = Operation::pending()->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->name)->toBe('test_pending');
});

test('rolledBack scope returns only rolled back operations', function (): void {
    $operations = Operation::rolledBack()->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->name)->toBe('test_rolled_back');
});

test('successful scope returns completed non-rolled-back operations', function (): void {
    $operations = Operation::successful()->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->pluck('name')->contains('test_rolled_back'))->toBeFalse();
});

test('synchronous scope returns only sync operations', function (): void {
    $operations = Operation::synchronous()->get();

    expect($operations)->toHaveCount(3)
        ->and($operations->every(fn ($op): bool => $op->type === 'sync'))->toBeTrue();
});

test('asynchronous scope returns only async operations', function (): void {
    $operations = Operation::asynchronous()->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->every(fn ($op): bool => $op->type === 'async'))->toBeTrue();
});

test('named scope returns operations by exact name', function (): void {
    $operations = Operation::named('test_failed')->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->name)->toBe('test_failed');
});

test('named scope supports wildcard patterns', function (): void {
    $operations = Operation::named('test_%')->get();

    expect($operations)->toHaveCount(4)
        ->and($operations->pluck('name')->contains('seed_users'))->toBeFalse();
});

test('today scope returns operations executed today', function (): void {
    $operations = Operation::today()->get();

    // Should not include operation from yesterday
    expect($operations->pluck('name')->contains('seed_users'))->toBeFalse()
        // Should include at least one operation from today
        ->and($operations->count())->toBeGreaterThan(0);
});

test('latest scope orders by most recent first', function (): void {
    $operations = Operation::query()->latest()->get();

    expect($operations->first()->name)->toBe('test_pending');
});

test('oldest scope orders by oldest first', function (): void {
    $operations = Operation::query()->oldest()->get();

    expect($operations->first()->name)->toBe('seed_users');
});

test('scopes can be chained', function (): void {
    $operations = Operation::completed()
        ->synchronous()
        ->latest()
        ->get();

    expect($operations)->toHaveCount(3)
        ->and($operations->every(fn ($op): bool => $op->type === 'sync'))->toBeTrue()
        ->and($operations->every(fn ($op): bool => $op->completed_at !== null))->toBeTrue();
});
