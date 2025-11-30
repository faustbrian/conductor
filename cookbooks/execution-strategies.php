<?php declare(strict_types=1);

use Cline\Sequencer\Enums\ExecutionStrategy;

/**
 * Execution Strategies Cookbook
 *
 * This cookbook demonstrates how to configure and use Sequencer's execution
 * strategies to control when and how operations are executed.
 *
 * Sequencer provides two execution strategies via the ExecutionStrategy enum:
 *
 * 1. ExecutionStrategy::Command (default)
 *    - Operations run only via explicit `php artisan sequencer:process`
 *    - Maximum control over when operations execute
 *    - Best for teams wanting explicit deployment steps
 *
 * 2. ExecutionStrategy::Migration
 *    - Operations run automatically during `php artisan migrate`
 *    - Operations interleave with migrations by timestamp
 *    - Best for teams wanting a single deployment command
 *
 * @see \Cline\Sequencer\Enums\ExecutionStrategy
 * @see \Cline\Sequencer\Contracts\ExecutionStrategy
 * @see \Cline\Sequencer\Strategies\CommandStrategy
 * @see \Cline\Sequencer\Strategies\MigrationStrategy
 */

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Example 1: Using the default command strategy
 *
 * The command strategy is the default. Operations only execute when you
 * explicitly run the sequencer:process command.
 *
 * config/sequencer.php:
 */
return [
    'strategy' => ExecutionStrategy::Command->value, // or env('SEQUENCER_STRATEGY', 'command')
];

/**
 * Deployment workflow with command strategy:
 *
 * ```bash
 * php artisan migrate
 * php artisan sequencer:process
 * ```
 *
 * Or with isolation for multi-server deployments:
 *
 * ```bash
 * php artisan migrate
 * php artisan sequencer:process --isolated
 * ```
 */

// =============================================================================

/**
 * Example 2: Using the migration strategy
 *
 * The migration strategy hooks into Laravel's migration events. Operations
 * execute automatically during `php artisan migrate`.
 *
 * config/sequencer.php:
 */
return [
    'strategy' => ExecutionStrategy::Migration->value, // or env('SEQUENCER_STRATEGY', 'migration')

    'migration_strategy' => [
        // Execute operations even when no migrations are pending
        'run_on_no_pending_migrations' => true,

        // Only execute during these commands
        'allowed_commands' => [
            'migrate',
        ],

        // Force synchronous execution even for async operations
        'force_sync' => false,
    ],
];

/**
 * Deployment workflow with event strategy:
 *
 * ```bash
 * php artisan migrate  # Operations run automatically!
 * ```
 *
 * That's it! Operations execute during the migrate command.
 */

// =============================================================================
// HOW EVENT STRATEGY WORKS
// =============================================================================

/**
 * The event strategy listens to Laravel's migration events:
 *
 * 1. MigrationsStarted
 *    - Fired before any migrations run
 *    - Sequencer dispatches OperationsStarted event
 *
 * 2. MigrationEnded (for each migration)
 *    - Fired after each individual migration
 *    - Sequencer executes operations with timestamp <= migration timestamp
 *    - This interleaves operations with migrations chronologically
 *
 * 3. MigrationsEnded
 *    - Fired after all migrations complete
 *    - Sequencer executes any remaining operations
 *    - Sequencer dispatches OperationsEnded event
 *
 * 4. NoPendingMigrations
 *    - Fired when `migrate` runs but no migrations are pending
 *    - If `run_on_no_pending_migrations` is true, executes all pending operations
 *
 * Example timeline:
 *
 * Migrations:
 *   2024_01_01_000000_create_users_table
 *   2024_01_03_000000_create_posts_table
 *
 * Operations:
 *   2024_01_02_000000_seed_admin_user.php
 *   2024_01_04_000000_send_welcome_emails.php
 *
 * Execution order:
 *   1. create_users_table (migration)
 *   2. seed_admin_user (operation - timestamp is between migrations)
 *   3. create_posts_table (migration)
 *   4. send_welcome_emails (operation - timestamp is after migrations)
 */

// =============================================================================
// CONFIGURATION OPTIONS
// =============================================================================

/**
 * Example 3: Configure which commands trigger operations
 *
 * By default, only `migrate` triggers operations. You can add other commands
 * if needed, but be careful with `migrate:fresh` as it drops all tables first.
 */
return [
    'migration_strategy' => [
        'allowed_commands' => [
            'migrate',
            'migrate:fresh', // Be careful! Tables are dropped first
        ],
    ],
];

/**
 * Example 4: Disable execution when no migrations are pending
 *
 * By default, operations execute even when `migrate` finds no pending
 * migrations. Set this to false if you only want operations to run when
 * there are actual migrations.
 */
return [
    'migration_strategy' => [
        'run_on_no_pending_migrations' => false,
    ],
];

/**
 * Example 5: Force synchronous execution
 *
 * Normally, operations implementing the Asynchronous interface are
 * dispatched to the queue during migrate. Set `force_sync` to true
 * to ensure all operations complete before migrate exits.
 */
return [
    'migration_strategy' => [
        'force_sync' => true,
    ],
];

// =============================================================================
// CHOOSING A STRATEGY
// =============================================================================

/**
 * Use ExecutionStrategy::Command when:
 *
 * - You need maximum control over operation execution
 * - You want to run operations independently of migrations
 * - You need tag filtering (--tags option)
 * - You're using complex orchestration strategies
 * - You need isolation locking (--isolated option)
 *
 * Use ExecutionStrategy::Migration when:
 *
 * - You want a simple single-command deployment
 * - Operations and migrations should always run together
 * - You trust timestamp-based ordering
 * - Your operations are idempotent (safe to re-run)
 * - You want automatic interleaving with migrations
 */

// =============================================================================
// SWITCHING STRATEGIES
// =============================================================================

/**
 * Switching from command to migration strategy:
 *
 * 1. Update config/sequencer.php:
 *    'strategy' => ExecutionStrategy::Migration->value,
 *
 * 2. Update deployment scripts:
 *    Before: php artisan migrate && php artisan sequencer:process
 *    After:  php artisan migrate
 *
 * 3. Consider if async operations need force_sync:
 *    'force_sync' => true,  // Ensure all complete before deploy finishes
 *
 * Switching from migration to command strategy:
 *
 * 1. Update config/sequencer.php:
 *    'strategy' => ExecutionStrategy::Command->value,
 *
 * 2. Update deployment scripts:
 *    Before: php artisan migrate
 *    After:  php artisan migrate && php artisan sequencer:process
 */

// =============================================================================
// TESTING STRATEGIES
// =============================================================================

/**
 * Example 6: Testing with command strategy
 */
use Cline\Sequencer\Facades\Sequencer;

test('operations execute via explicit command', function (): void {
    // Command strategy is default - operations only run when called
    Sequencer::execute('2024_01_01_000000_my_operation');

    expect(/* operation completed */)->toBeTrue();
});

/**
 * Example 7: Testing with event strategy
 */
use Illuminate\Support\Facades\Artisan;

test('operations execute during migrate', function (): void {
    config(['sequencer.strategy' => 'migration']);

    // Create operation file...

    Artisan::call('migrate');

    expect(/* operation completed */)->toBeTrue();
});

// =============================================================================
// ENVIRONMENT-BASED CONFIGURATION
// =============================================================================

/**
 * Example 8: Different strategies per environment
 *
 * .env.local:
 * SEQUENCER_STRATEGY=command
 *
 * .env.production:
 * SEQUENCER_STRATEGY=event
 *
 * This allows developers to run operations manually in local development
 * while production deployments run everything with a single command.
 */

// =============================================================================
// ROLLBACK CONSIDERATIONS
// =============================================================================

/**
 * Important: Rollback behavior differs between strategies
 *
 * Command Strategy:
 * - Full rollback support
 * - If an operation fails, all previous operations implementing Rollbackable
 *   are rolled back in reverse order
 *
 * Event Strategy:
 * - Operations only rollback (not migrations)
 * - When an operation fails during `migrate`, the migration that triggered
 *   it has already committed
 * - Only operations rolled back, not the preceding migration
 *
 * If you need transactional rollback of both migrations and operations,
 * use the command strategy with TransactionalBatchOrchestrator.
 */
