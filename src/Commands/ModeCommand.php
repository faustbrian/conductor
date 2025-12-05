<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Enums\ExecutionStrategy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;

use const PHP_EOL;

use function base_path;
use function config;
use function mb_trim;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;

/**
 * Artisan command to view and change the Sequencer execution strategy.
 *
 * Provides a convenient way to switch between command and migration execution
 * strategies by updating either the .env file or the config file directly.
 *
 * ```bash
 * # Show current execution strategy
 * php artisan sequencer:mode
 *
 * # Switch to command strategy (explicit sequencer:process)
 * php artisan sequencer:mode --command --env
 * php artisan sequencer:mode --command --config
 *
 * # Switch to migration strategy (auto-execute during migrate)
 * php artisan sequencer:mode --migration --env
 * php artisan sequencer:mode --migration --config
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ModeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequencer:mode
                            {--command : Set execution strategy to command (explicit sequencer:process)}
                            {--migration : Set execution strategy to migration (auto-execute during migrate)}
                            {--env : Update the .env file}
                            {--config : Update the config/sequencer.php file}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'View or change the Sequencer execution strategy';

    /**
     * Execute the console command.
     *
     * Routes to the appropriate action based on provided options: displays current mode
     * if no mode flags are specified, validates mutually exclusive flags, and updates
     * either the .env file or config/sequencer.php file with the new execution strategy.
     *
     * @param Filesystem $filesystem Laravel's filesystem service for reading and writing
     *                               configuration files safely with proper error handling
     *
     * @throws FileNotFoundException When .env or config/sequencer.php cannot be read
     *
     * @return int Command exit code: self::SUCCESS (0) if mode displayed or updated successfully,
     *             self::FAILURE (1) if validation fails or file operations encounter errors
     */
    public function handle(Filesystem $filesystem): int
    {
        $setCommand = (bool) $this->option('command');
        $setMigration = (bool) $this->option('migration');
        $useEnv = (bool) $this->option('env');
        $useConfig = (bool) $this->option('config');

        // If no mode flags, show current status
        if (!$setCommand && !$setMigration) {
            return $this->displayCurrentMode();
        }

        // Validate mutually exclusive mode flags
        if ($setCommand && $setMigration) {
            $this->components->error('Cannot specify both --command and --migration');

            return self::FAILURE;
        }

        // Require target flag
        if (!$useEnv && !$useConfig) {
            $this->components->error('You must specify --env or --config to indicate where to save the setting');

            return self::FAILURE;
        }

        // Validate mutually exclusive target flags
        if ($useEnv && $useConfig) {
            $this->components->error('Cannot specify both --env and --config');

            return self::FAILURE;
        }

        $strategy = $setCommand ? ExecutionStrategy::Command : ExecutionStrategy::Migration;

        if ($useEnv) {
            return $this->updateEnvFile($filesystem, $strategy);
        }

        return $this->updateConfigFile($filesystem, $strategy);
    }

    /**
     * Display the current execution strategy.
     *
     * Reads the current strategy from configuration and displays it in a formatted
     * two-column layout showing the mode (command or migration) and its description.
     *
     * @return int Always returns self::SUCCESS (0) as display operations cannot fail
     */
    private function displayCurrentMode(): int
    {
        /** @var string $currentStrategy */
        $currentStrategy = config('sequencer.strategy', ExecutionStrategy::Command->value);

        $strategy = ExecutionStrategy::tryFrom($currentStrategy) ?? ExecutionStrategy::Command;

        $this->components->info('Current Sequencer Execution Strategy');
        $this->newLine();

        if ($strategy === ExecutionStrategy::Command) {
            $this->components->twoColumnDetail('Mode', '<fg=yellow>command</>');
            $this->components->twoColumnDetail('Description', 'Operations run via explicit <fg=cyan>php artisan sequencer:process</> command');
        } else {
            $this->components->twoColumnDetail('Mode', '<fg=green>migration</>');
            $this->components->twoColumnDetail('Description', 'Operations run automatically during <fg=cyan>php artisan migrate</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>Change with:</> php artisan sequencer:mode --command|--migration --env|--config');

        return self::SUCCESS;
    }

    /**
     * Update the .env file with the new strategy.
     *
     * Modifies the SEQUENCER_STRATEGY environment variable in the .env file, either
     * replacing an existing value or appending the new setting. Uses regex pattern
     * matching to preserve file formatting and other environment variables.
     *
     * @param Filesystem        $filesystem Laravel's filesystem service for safe file operations
     * @param ExecutionStrategy $strategy   The new execution strategy to set (Command or Migration)
     *
     * @throws FileNotFoundException When .env file cannot be read
     *
     * @return int Command exit code: self::SUCCESS (0) if .env updated successfully,
     *             self::FAILURE (1) if .env file not found or cannot be read
     */
    private function updateEnvFile(Filesystem $filesystem, ExecutionStrategy $strategy): int
    {
        $envPath = base_path('.env');

        if (!$filesystem->exists($envPath)) {
            $this->components->error('.env file not found');

            return self::FAILURE;
        }

        try {
            $contents = $filesystem->get($envPath);
        } catch (FileNotFoundException) {
            $this->components->error('Failed to read .env file');

            return self::FAILURE;
        }

        $key = 'SEQUENCER_STRATEGY';
        $value = $strategy->value;

        if (preg_match(sprintf('/^%s=.*/m', $key), $contents)) {
            // Replace existing value
            $contents = preg_replace(
                sprintf('/^%s=.*/m', $key),
                sprintf('%s=%s', $key, $value),
                $contents,
            );
        } else {
            // Append new value
            $contents = mb_trim($contents).sprintf('%s%s=%s%s', PHP_EOL, $key, $value, PHP_EOL);
        }

        $filesystem->put($envPath, (string) $contents);

        $this->components->success(sprintf(
            'Updated .env: SEQUENCER_STRATEGY=%s',
            $value,
        ));

        $this->components->warn('Run "php artisan config:clear" if config is cached');

        return self::SUCCESS;
    }

    /**
     * Update the config file with the new strategy.
     *
     * Modifies the strategy configuration in config/sequencer.php using regex pattern
     * matching to replace the existing value while preserving file structure. Ensures
     * the ExecutionStrategy import statement is present, adding it if missing.
     *
     * @param Filesystem        $filesystem Laravel's filesystem service for safe file operations
     * @param ExecutionStrategy $strategy   The new execution strategy to set (Command or Migration)
     *
     * @throws FileNotFoundException When config/sequencer.php cannot be read
     *
     * @return int Command exit code: self::SUCCESS (0) if config updated successfully,
     *             self::FAILURE (1) if config file not found, cannot be read, or pattern not matched
     */
    private function updateConfigFile(Filesystem $filesystem, ExecutionStrategy $strategy): int
    {
        $configPath = base_path('config/sequencer.php');

        if (!$filesystem->exists($configPath)) {
            $this->components->error('config/sequencer.php not found. Run: php artisan vendor:publish --tag=sequencer-config');

            return self::FAILURE;
        }

        try {
            $contents = $filesystem->get($configPath);
        } catch (FileNotFoundException) {
            $this->components->error('Failed to read config/sequencer.php');

            return self::FAILURE;
        }

        // Match the strategy line with env() helper
        $envPattern = "/'strategy'\\s*=>\\s*env\\s*\\(\\s*['\"]SEQUENCER_STRATEGY['\"]\\s*,\\s*[^)]+\\)/";
        // Match direct ExecutionStrategy enum usage
        $enumPattern = "/'strategy'\\s*=>\\s*ExecutionStrategy::(Command|Migration)->value/";
        // Match simple string value
        $stringPattern = "/'strategy'\\s*=>\\s*['\"][^'\"]+['\"]/";

        $replacement = sprintf(
            "'strategy' => env('SEQUENCER_STRATEGY', ExecutionStrategy::%s->value)",
            $strategy === ExecutionStrategy::Command ? 'Command' : 'Migration',
        );

        $updated = false;

        if (preg_match($envPattern, $contents)) {
            $contents = preg_replace($envPattern, $replacement, $contents);
            $updated = true;
        } elseif (preg_match($enumPattern, $contents)) {
            $contents = preg_replace($enumPattern, $replacement, $contents);
            $updated = true;
        } elseif (preg_match($stringPattern, $contents)) {
            $contents = preg_replace($stringPattern, $replacement, $contents);
            $updated = true;
        }

        if (!$updated) {
            $this->components->error('Could not locate strategy configuration in config/sequencer.php');

            return self::FAILURE;
        }

        // Ensure ExecutionStrategy import exists
        if (!str_contains((string) $contents, 'use Cline\Sequencer\Enums\ExecutionStrategy;')) {
            $contents = preg_replace(
                '/(<\?php.*?)\n\nreturn/s',
                "$1\n\nuse Cline\\Sequencer\\Enums\\ExecutionStrategy;\n\nreturn",
                (string) $contents,
            );
        }

        $filesystem->put($configPath, (string) $contents);

        $this->components->success(sprintf(
            'Updated config/sequencer.php: strategy => ExecutionStrategy::%s',
            $strategy === ExecutionStrategy::Command ? 'Command' : 'Migration',
        ));

        $this->components->warn('Run "php artisan config:clear" if config is cached');

        return self::SUCCESS;
    }
}
