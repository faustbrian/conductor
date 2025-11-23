<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
|--------------------------------------------------------------------------
| Sequencer Configuration
|--------------------------------------------------------------------------
|
| This file defines the configuration for Sequencer, a Laravel package that
| orchestrates sequential execution of migrations and operations. It ensures
| database changes and business logic execute in chronological order during
| deployments, preventing conflicts between migrations and operations.
|
*/

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\MorphType;
use Cline\Sequencer\Enums\PrimaryKeyType;
use Cline\Sequencer\SequentialOrchestrator;

return [
    /*
    |--------------------------------------------------------------------------
    | Orchestrator Strategy
    |--------------------------------------------------------------------------
    |
    | This option controls which orchestrator strategy to use for executing
    | operations. Different orchestrators provide different execution patterns:
    |
    | - SequentialOrchestrator: Default. Executes in chronological order
    | - BatchOrchestrator: Parallel execution of all operations
    | - TransactionalBatchOrchestrator: All-or-nothing with auto-rollback
    | - AllowedToFailBatchOrchestrator: Continue on non-critical failures
    | - DependencyGraphOrchestrator: DAG-based wave execution
    | - ScheduledOrchestrator: Time-based delayed execution
    |
    | You can also override this at runtime:
    | Sequencer::using(BatchOrchestrator::class)->executeAll()
    |
    */

    'orchestrator' => env('SEQUENCER_ORCHESTRATOR', SequentialOrchestrator::class),

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of primary key used in Sequencer's database
    | tables. You may use traditional auto-incrementing integers or choose
    | ULIDs or UUIDs for distributed systems or enhanced privacy.
    |
    | Supported: "id", "ulid", "uuid"
    |
    */

    'primary_key_type' => env('SEQUENCER_PRIMARY_KEY_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Morph Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of polymorphic relationship columns used
    | for tracking who executed operations. This determines how operations
    | are associated with different user models or system actors.
    |
    | Supported: "morph", "uuidMorph", "ulidMorph", "numericMorph"
    |
    */

    'morph_type' => env('SEQUENCER_MORPH_TYPE', 'morph'),

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option allows you to specify which column should be used as the
    | foreign key for each model in polymorphic relationships. This is
    | particularly useful when different models in your application use
    | different primary key column names.
    |
    | Note: You may only configure either 'morphKeyMap' or 'enforceMorphKeyMap',
    | not both.
    |
    */

    'morphKeyMap' => [
        // App\Models\User::class => 'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforced Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option works identically to 'morphKeyMap' above, but enables strict
    | enforcement. Any model referenced without an explicit mapping will throw
    | a MorphKeyViolationException.
    |
    */

    'enforceMorphKeyMap' => [
        // App\Models\User::class => 'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent Models
    |--------------------------------------------------------------------------
    |
    | When using Sequencer, these Eloquent models are used to interact with
    | the database. You may extend these models with your own implementations
    | whilst ensuring they extend the base classes provided by Sequencer.
    |
    */

    'models' => [
        /*
        |--------------------------------------------------------------------------
        | Operation Model
        |--------------------------------------------------------------------------
        |
        | This model tracks all executed operations including their execution
        | time, status, and who performed them.
        |
        */

        'operation' => Operation::class,

        /*
        |--------------------------------------------------------------------------
        | Operation Error Model
        |--------------------------------------------------------------------------
        |
        | This model stores detailed error information when operations fail,
        | providing a complete audit trail for debugging and recovery.
        |
        */

        'operation_error' => OperationError::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | Sequencer uses these table names to store operation execution records
    | and error logs. You may customize these names to fit your application's
    | database schema conventions.
    |
    */

    'table_names' => [
        /*
        |--------------------------------------------------------------------------
        | Operations Table
        |--------------------------------------------------------------------------
        |
        | This table stores records of all executed operations including their
        | timestamps, execution status, and associated metadata.
        |
        */

        'operations' => env('SEQUENCER_OPERATIONS_TABLE', 'operations'),

        /*
        |--------------------------------------------------------------------------
        | Operation Errors Table
        |--------------------------------------------------------------------------
        |
        | This table stores detailed error information when operations fail,
        | including exception messages, stack traces, and context data.
        |
        */

        'operation_errors' => env('SEQUENCER_ERRORS_TABLE', 'operation_errors'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how operations are discovered, validated, and
    | executed by Sequencer.
    |
    */

    'execution' => [
        /*
        |--------------------------------------------------------------------------
        | Discovery Paths
        |--------------------------------------------------------------------------
        |
        | Sequencer will scan these paths for operation files. Operations must
        | follow the naming convention: YYYY_MM_DD_HHMMSS_OperationName.php
        |
        */

        'discovery_paths' => [
            database_path('operations'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Sequential Execution
        |--------------------------------------------------------------------------
        |
        | When enabled, migrations and operations execute in chronological order
        | based on their timestamps. This ensures database changes and business
        | logic run in the correct sequence during deployments.
        |
        */

        'sequential' => env('SEQUENCER_SEQUENTIAL', true),

        /*
        |--------------------------------------------------------------------------
        | Auto Transaction
        |--------------------------------------------------------------------------
        |
        | When enabled, all operations automatically execute within database
        | transactions. This prevents partial execution and database corruption
        | if an operation fails.
        |
        */

        'auto_transaction' => env('SEQUENCER_AUTO_TRANSACTION', true),

        /*
        |--------------------------------------------------------------------------
        | Lock Configuration
        |--------------------------------------------------------------------------
        |
        | These settings control the atomic lock mechanism used with --isolate
        | to prevent duplicate execution in multi-server environments.
        |
        */

        'lock' => [
            /*
            |----------------------------------------------------------------------
            | Lock Store
            |----------------------------------------------------------------------
            |
            | The cache store used for atomic locks. Should use a shared cache
            | like Redis in multi-server setups.
            |
            */

            'store' => env('SEQUENCER_LOCK_STORE', env('CACHE_STORE', 'redis')),

            /*
            |----------------------------------------------------------------------
            | Lock Timeout
            |----------------------------------------------------------------------
            |
            | Maximum seconds to wait for lock acquisition. Operations will fail
            | if they cannot acquire the lock within this time.
            |
            */

            'timeout' => env('SEQUENCER_LOCK_TIMEOUT', 60),

            /*
            |----------------------------------------------------------------------
            | Lock TTL
            |----------------------------------------------------------------------
            |
            | Maximum seconds the lock can be held. Prevents deadlocks if a
            | process crashes while holding the lock.
            |
            */

            'ttl' => env('SEQUENCER_LOCK_TTL', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how asynchronous operations are queued and
    | processed.
    |
    */

    'queue' => [
        /*
        |--------------------------------------------------------------------------
        | Connection
        |--------------------------------------------------------------------------
        |
        | The queue connection to use for async operations. Leave null to use
        | the default queue connection.
        |
        */

        'connection' => env('SEQUENCER_QUEUE_CONNECTION'),

        /*
        |--------------------------------------------------------------------------
        | Queue Name
        |--------------------------------------------------------------------------
        |
        | The queue name for async operations.
        |
        */

        'queue' => env('SEQUENCER_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Recording
    |--------------------------------------------------------------------------
    |
    | Configure how operation failures are recorded and reported.
    |
    */

    'errors' => [
        /*
        |--------------------------------------------------------------------------
        | Record Errors
        |--------------------------------------------------------------------------
        |
        | When enabled, operation failures are stored in the database with
        | full stack traces and context for debugging.
        |
        */

        'record' => env('SEQUENCER_RECORD_ERRORS', true),

        /*
        |--------------------------------------------------------------------------
        | Log Channel
        |--------------------------------------------------------------------------
        |
        | The log channel to use for operation errors. Errors are always
        | logged regardless of database recording.
        |
        */

        'log_channel' => env('SEQUENCER_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting & Observability
    |--------------------------------------------------------------------------
    |
    | Configure integration with Laravel monitoring and observability tools.
    |
    */

    'reporting' => [
        /*
        |--------------------------------------------------------------------------
        | Laravel Pulse Integration
        |--------------------------------------------------------------------------
        |
        | When enabled, operation events are recorded in Laravel Pulse for
        | real-time monitoring and metrics visualization.
        |
        */

        'pulse' => env('SEQUENCER_PULSE_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Laravel Telescope Integration
        |--------------------------------------------------------------------------
        |
        | When enabled, operation events are recorded in Laravel Telescope for
        | detailed debugging and request inspection.
        |
        */

        'telescope' => env('SEQUENCER_TELESCOPE_ENABLED', false),
    ],
];

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
// Here endeth thy configuration, noble developer!                            //
// Beyond: code so wretched, even wyrms learned the scribing arts.            //
// Forsooth, they but penned "// TODO: remedy ere long"                       //
// Three realms have fallen since...                                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
//                                                  .~))>>                    //
//                                                 .~)>>                      //
//                                               .~))))>>>                    //
//                                             .~))>>             ___         //
//                                           .~))>>)))>>      .-~))>>         //
//                                         .~)))))>>       .-~))>>)>          //
//                                       .~)))>>))))>>  .-~)>>)>              //
//                   )                 .~))>>))))>>  .-~)))))>>)>             //
//                ( )@@*)             //)>))))))  .-~))))>>)>                 //
//              ).@(@@               //))>>))) .-~))>>)))))>>)>               //
//            (( @.@).              //))))) .-~)>>)))))>>)>                   //
//          ))  )@@*.@@ )          //)>))) //))))))>>))))>>)>                 //
//       ((  ((@@@.@@             |/))))) //)))))>>)))>>)>                    //
//      )) @@*. )@@ )   (\_(\-\b  |))>)) //)))>>)))))))>>)>                   //
//    (( @@@(.@(@ .    _/`-`  ~|b |>))) //)>>)))))))>>)>                      //
//     )* @@@ )@*     (@)  (@) /\b|))) //))))))>>))))>>                       //
//   (( @. )@( @ .   _/  /    /  \b)) //))>>)))))>>>_._                       //
//    )@@ (@@*)@@.  (6///6)- / ^  \b)//))))))>>)))>>   ~~-.                   //
// ( @jgs@@. @@@.*@_ VvvvvV//  ^  \b/)>>))))>>      _.     `bb                //
//  ((@@ @@@*.(@@ . - | o |' \ (  ^   \b)))>>        .'       b`,             //
//   ((@@).*@@ )@ )   \^^^/  ((   ^  ~)_        \  /           b `,           //
//     (@@. (@@ ).     `-'   (((   ^    `\ \ \ \ \|             b  `.         //
//       (*.@*              / ((((        \| | |  \       .       b `.        //
//                         / / (((((  \    \ /  _.-~\     Y,      b  ;        //
//                        / / / (((((( \    \.-~   _.`" _.-~`,    b  ;        //
//                       /   /   `(((((()    )    (((((~      `,  b  ;        //
//                     _/  _/      `"""/   /'                  ; b   ;        //
//                 _.-~_.-~           /  /'                _.'~bb _.'         //
//               ((((~~              / /'              _.'~bb.--~             //
//                                  ((((          __.-~bb.-~                  //
//                                              .'  b .~~                     //
//                                              :bb ,'                        //
//                                              ~~~~                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
