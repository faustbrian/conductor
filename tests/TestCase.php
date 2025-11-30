<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests;

use Cline\Sequencer\SequencerServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

use function base64_encode;
use function env;
use function random_bytes;

/**
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SequencerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Set encryption key for tests
        $app->make(Repository::class)->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app->make(Repository::class)->set('sequencer.primary_key_type', env('SEQUENCER_PRIMARY_KEY_TYPE', 'id'));
        $app->make(Repository::class)->set('sequencer.morph_type', env('SEQUENCER_MORPH_TYPE', 'string'));

        // Configure cache for unique job locks
        $app->make(Repository::class)->set('cache.default', 'array');

        // Configure queue for batch testing
        $app->make(Repository::class)->set('queue.default', 'database');
        $app->make(Repository::class)->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);

        // Disable morph map enforcement for tests
        Relation::morphMap([], merge: false);
        Relation::requireMorphMap(false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations',
            __DIR__.'/database/migrations',
        ]);
    }
}
