<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Facades\Date;
use Override;

use function config;
use function ctype_upper;
use function is_string;
use function mb_str_split;
use function mb_strtolower;

/**
 * Artisan command to generate new Sequencer operation files.
 *
 * Creates timestamped operation files following the same naming convention as Laravel
 * migrations. Each operation receives a unique timestamp prefix to ensure chronological
 * execution order during deployment orchestration.
 *
 * ```bash
 * # Create a basic operation
 * php artisan make:operation NotifyUsersOfSystemUpgrade
 *
 * # Create an async operation
 * php artisan make:operation SyncProducts --async
 *
 * # Create a rollbackable operation
 * php artisan make:operation MigrateUserData --rollback
 *
 * # Create an operation with retry support
 * php artisan make:operation CallExternalAPI --retryable
 *
 * # Create an operation within a transaction
 * php artisan make:operation TransferBalances --transaction
 *
 * # Create a scheduled operation
 * php artisan make:operation RunMaintenanceTask --scheduled
 *
 * # Creates: database/operations/2024_01_15_143022_notify_users_of_system_upgrade.php
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MakeOperationCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:operation';

    /**
     * The console command signature with options.
     *
     * @var string
     */
    protected $signature = 'make:operation
                            {name : The name of the operation}
                            {--async : Create an asynchronous operation}
                            {--rollback : Create a rollbackable operation}
                            {--retryable : Create a retryable operation}
                            {--transaction : Create an operation within a database transaction}
                            {--idempotent : Create an idempotent operation}
                            {--conditional : Create a conditional operation}
                            {--hooks : Create an operation with lifecycle hooks}
                            {--scheduled : Create a scheduled operation}
                            {--dependencies : Create an operation with dependencies}
                            {--middleware : Create an operation with middleware}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Sequencer operation';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Operation';

    /**
     * Get the stub file for the generator.
     *
     * Selects the appropriate stub template based on command options. The stub
     * determines which interfaces and traits are included in the generated operation
     * class, enabling different operation behaviors like async execution, rollback
     * support, retry logic, transactional wrapping, and lifecycle hooks.
     *
     * @return string Absolute path to the stub file that will be used as the template
     *                for generating the operation class file
     */
    #[Override()]
    protected function getStub(): string
    {
        $stubsPath = __DIR__.'/../../stubs';

        if ($this->option('async')) {
            return $stubsPath.'/operation.async.stub';
        }

        if ($this->option('rollback')) {
            return $stubsPath.'/operation.rollback.stub';
        }

        if ($this->option('retryable')) {
            return $stubsPath.'/operation.retryable.stub';
        }

        if ($this->option('transaction')) {
            return $stubsPath.'/operation.transaction.stub';
        }

        if ($this->option('idempotent')) {
            return $stubsPath.'/operation.idempotent.stub';
        }

        if ($this->option('conditional')) {
            return $stubsPath.'/operation.conditional.stub';
        }

        if ($this->option('hooks')) {
            return $stubsPath.'/operation.hooks.stub';
        }

        if ($this->option('scheduled')) {
            return $stubsPath.'/operation.scheduled.stub';
        }

        if ($this->option('dependencies')) {
            return $stubsPath.'/operation.dependencies.stub';
        }

        if ($this->option('middleware')) {
            return $stubsPath.'/operation.middleware.stub';
        }

        return $stubsPath.'/operation.stub';
    }

    /**
     * Get the destination class path.
     *
     * Operations are stored in the configured operations directory with timestamp prefixes.
     * The timestamp ensures chronological execution order during deployment orchestration.
     *
     * @param  string $name The operation class name in PascalCase format
     * @return string Absolute path to the generated operation file including timestamp prefix
     *                and snake_case filename (e.g., database/operations/2024_01_15_143022_my_operation.php)
     */
    #[Override()]
    protected function getPath($name): string
    {
        $pathRaw = config('sequencer.paths.operations', 'database/operations');
        $path = is_string($pathRaw) ? $pathRaw : 'database/operations';

        $timestamp = Date::now()->format('Y_m_d_His');
        $filename = $timestamp.'_'.$this->getSnakeCase($this->getNameInput()).'.php';

        return $this->laravel->basePath($path.'/'.$filename);
    }

    /**
     * Convert a string to snake_case.
     *
     * Iterates through each character, inserting underscores before uppercase letters
     * (except at the start) and converting all uppercase letters to lowercase.
     *
     * @param  string $value The PascalCase or camelCase value to convert
     * @return string The converted snake_case string
     */
    private function getSnakeCase(string $value): string
    {
        $result = '';

        foreach (mb_str_split($value) as $index => $char) {
            if (ctype_upper($char)) {
                if ($index > 0) {
                    $result .= '_';
                }

                $result .= mb_strtolower($char);
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
