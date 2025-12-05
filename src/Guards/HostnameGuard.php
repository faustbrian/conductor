<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Guards;

use Cline\Sequencer\Contracts\ExecutionGuard;

use function gethostname;
use function implode;
use function in_array;
use function sprintf;

/**
 * Guard that restricts operation execution to specific hostnames.
 *
 * Use this guard to ensure operations only run on designated servers,
 * which is essential for multi-server deployments where certain operations
 * should only execute on specific nodes.
 *
 * Configuration via config/sequencer.php:
 * ```php
 * 'guards' => [
 *     [
 *         'driver' => Cline\Sequencer\Guards\HostnameGuard::class,
 *         'config' => ['allowed' => ['hel2', 'hel3', 'production-worker-1']],
 *     ],
 * ],
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class HostnameGuard implements ExecutionGuard
{
    /**
     * The current server hostname.
     */
    private string $currentHostname;

    /**
     * List of allowed hostnames.
     *
     * @var array<string>
     */
    private array $allowedHostnames;

    /**
     * Create a new hostname guard instance.
     *
     * @param array<string, mixed> $config Guard configuration with 'allowed' key
     */
    public function __construct(array $config = [])
    {
        /** @var array<string> $allowed */
        $allowed = $config['allowed'] ?? [];

        $this->allowedHostnames = $allowed;

        /** @var string $currentHostname */
        $currentHostname = $config['current_hostname'] ?? (gethostname() ?: 'unknown');
        $this->currentHostname = $currentHostname;
    }

    /**
     * Determine if operations should execute on this hostname.
     *
     * Returns true if:
     * - No allowed hostnames are configured (guard is effectively disabled)
     * - The current hostname is in the allowed list
     *
     * @return bool True if execution should proceed, false to block
     */
    public function shouldExecute(): bool
    {
        // If no hostnames configured, allow execution (guard disabled)
        if ($this->allowedHostnames === []) {
            return true;
        }

        return in_array($this->currentHostname, $this->allowedHostnames, true);
    }

    /**
     * Get explanation for why execution was blocked.
     *
     * @return string Message explaining the hostname restriction
     */
    public function reason(): string
    {
        return sprintf(
            "Execution blocked: hostname '%s' is not in allowed list [%s]",
            $this->currentHostname,
            implode(', ', $this->allowedHostnames),
        );
    }

    /**
     * Get the guard identifier.
     *
     * @return string Returns 'hostname'
     */
    public function name(): string
    {
        return 'Hostname Guard';
    }

    /**
     * Get the current hostname being checked.
     *
     * @return string The current server hostname
     */
    public function getCurrentHostname(): string
    {
        return $this->currentHostname;
    }

    /**
     * Get the list of allowed hostnames.
     *
     * @return array<string> Allowed hostname list
     */
    public function getAllowedHostnames(): array
    {
        return $this->allowedHostnames;
    }
}
