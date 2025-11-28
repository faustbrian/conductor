<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Support\Facades\Event;

test('OperationStarted and OperationEnded events fire for sync operation', function (): void {
    $startedFired = false;
    $endedFired = false;

    Event::listen(OperationStarted::class, function ($event) use (&$startedFired): void {
        $startedFired = $event->method === ExecutionMethod::Sync;
    });

    Event::listen(OperationEnded::class, function ($event) use (&$endedFired): void {
        $endedFired = $event->method === ExecutionMethod::Sync;
    });

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeSync($operation);

    expect($startedFired)->toBeTrue()
        ->and($endedFired)->toBeTrue();
});

test('OperationsStarted and OperationsEnded events fire for batch operations', function (): void {
    $startedFired = false;
    $endedFired = false;

    Event::listen(OperationsStarted::class, function ($event) use (&$startedFired): void {
        $startedFired = $event->method === ExecutionMethod::Sync;
    });

    Event::listen(OperationsEnded::class, function ($event) use (&$endedFired): void {
        $endedFired = $event->method === ExecutionMethod::Sync;
    });

    // Use a current/past timestamp so the operation is discovered as pending
    $timestamp = now()->subDay()->format('Y_m_d_His');
    $operationName = $timestamp.'_test_batch_operation';

    $orchestrator = app(SequentialOrchestrator::class);

    // Create a temporary operation file
    $tempDir = sys_get_temp_dir().'/sequencer-events-test-'.uniqid();
    mkdir($tempDir);

    $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;

    file_put_contents(sprintf('%s/%s.php', $tempDir, $operationName), $operationContent);

    config(['sequencer.execution.discovery_paths' => [$tempDir]]);

    try {
        $orchestrator->process(isolate: false);
    } finally {
        // Cleanup
        unlink(sprintf('%s/%s.php', $tempDir, $operationName));
        rmdir($tempDir);
    }

    expect($startedFired)->toBeTrue()
        ->and($endedFired)->toBeTrue();
});

test('NoPendingOperations event fires when no operations to execute', function (): void {
    $fired = false;

    Event::listen(NoPendingOperations::class, function ($event) use (&$fired): void {
        $fired = $event->method === ExecutionMethod::Sync;
    });

    $orchestrator = app(SequentialOrchestrator::class);

    // Set empty discovery paths
    $tempDir = sys_get_temp_dir().'/sequencer-empty-'.uniqid();
    mkdir($tempDir);
    config(['sequencer.execution.discovery_paths' => [$tempDir]]);

    try {
        $orchestrator->process(isolate: false);
    } finally {
        rmdir($tempDir);
    }

    expect($fired)->toBeTrue();
});

test('event contains operation instance and method', function (): void {
    $operation = null;
    $method = null;

    Event::listen(OperationStarted::class, function ($event) use (&$operation, &$method): void {
        $operation = $event->operation;
        $method = $event->method;
    });

    $operationFile = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeSync($operationFile);

    expect($operation)->toBeInstanceOf(Operation::class)
        ->and($method)->toBe(ExecutionMethod::Sync);
});
