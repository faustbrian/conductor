<?php declare(strict_types=1);

use Cline\Sequencer\Contracts\ExecutionGuard;
use Cline\Sequencer\Guards\HostnameGuard;
use Cline\Sequencer\Guards\IpAddressGuard;

/**
 * Execution Guards Cookbook
 *
 * This cookbook demonstrates how to configure and use Sequencer's execution
 * guards to control WHERE operations are allowed to execute.
 *
 * Guards differ from strategies:
 * - Strategies control HOW/WHEN operations execute (command vs migration)
 * - Guards control WHETHER operations execute based on runtime conditions
 *
 * Use guards to restrict `php artisan sequencer:process` to specific servers,
 * preventing accidental execution in wrong environments.
 *
 * @see \Cline\Sequencer\Contracts\ExecutionGuard
 * @see \Cline\Sequencer\Guards\HostnameGuard
 * @see \Cline\Sequencer\Guards\IpAddressGuard
 * @see \Cline\Sequencer\Support\GuardManager
 */

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Example 1: Restrict execution to specific hostnames
 *
 * Only allow operations to run on servers named 'hel2' or 'hel3'.
 * Useful for multi-server deployments where only certain servers should
 * execute operations.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['hel2', 'hel3'],
            ],
        ],
    ],
];

/**
 * If this runs on server 'us3', execution is blocked with:
 * "Execution blocked: hostname 'us3' is not in allowed list [hel2, hel3]"
 */

// =============================================================================

/**
 * Example 2: Restrict execution to specific IP addresses
 *
 * Only allow operations to run from servers with specific IPs.
 * Supports exact matches and CIDR notation for network ranges.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.0.1.50',          // Exact IP
                    '192.168.1.0/24',     // CIDR range (all 192.168.1.x)
                    '2001:db8::/32',      // IPv6 CIDR range
                ],
            ],
        ],
    ],
];

// =============================================================================

/**
 * Example 3: Combine multiple guards
 *
 * All guards must pass for execution to proceed. Use multiple guards
 * for layered security.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['prod-1', 'prod-2'],
            ],
        ],
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => ['10.0.0.0/8'],
            ],
        ],
    ],
];

/**
 * Execution only proceeds if:
 * 1. Hostname is 'prod-1' or 'prod-2' AND
 * 2. IP is in the 10.x.x.x range
 */

// =============================================================================
// BUILT-IN GUARDS
// =============================================================================

/**
 * HostnameGuard
 *
 * Checks the server's hostname via gethostname().
 *
 * Config options:
 * - allowed: array of allowed hostnames (exact match, case-sensitive)
 * - current_hostname: override detection (useful for testing)
 *
 * Empty allowed list = guard disabled (allows all)
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['web-1', 'web-2', 'worker-1'],
            ],
        ],
    ],
];

/**
 * IpAddressGuard
 *
 * Checks the server's IP address. Supports:
 * - Exact IPv4: '192.168.1.100'
 * - Exact IPv6: '2001:db8::1'
 * - CIDR IPv4: '10.0.0.0/8', '192.168.1.0/24'
 * - CIDR IPv6: '2001:db8::/32'
 *
 * Config options:
 * - allowed: array of IPs/CIDR ranges
 * - current_ip: override detection (useful for testing)
 *
 * Empty allowed list = guard disabled (allows all)
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.0.0.0/8',         // Private class A
                    '172.16.0.0/12',      // Private class B
                    '192.168.0.0/16',     // Private class C
                ],
            ],
        ],
    ],
];

// =============================================================================
// CUSTOM GUARDS
// =============================================================================

/**
 * Example 4: Create a custom environment guard
 *
 * Guards must implement the ExecutionGuard interface.
 */

final readonly class EnvironmentGuard implements ExecutionGuard
{
    /** @var array<string> */
    private array $allowedEnvironments;

    private string $currentEnvironment;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        /** @var array<string> $allowed */
        $allowed = $config['allowed'] ?? [];
        $this->allowedEnvironments = $allowed;

        /** @var string $current */
        $current = $config['current_environment'] ?? app()->environment();
        $this->currentEnvironment = $current;
    }

    public function shouldExecute(): bool
    {
        if ($this->allowedEnvironments === []) {
            return true;
        }

        return in_array($this->currentEnvironment, $this->allowedEnvironments, true);
    }

    public function reason(): string
    {
        return sprintf(
            "Execution blocked: environment '%s' is not in allowed list [%s]",
            $this->currentEnvironment,
            implode(', ', $this->allowedEnvironments),
        );
    }

    public function name(): string
    {
        return 'Environment Guard';
    }
}

/**
 * Register your custom guard the same way as built-in guards:
 */
return [
    'guards' => [
        [
            'driver' => EnvironmentGuard::class,
            'config' => [
                'allowed' => ['production', 'staging'],
            ],
        ],
    ],
];

// =============================================================================
// TESTING WITH GUARDS
// =============================================================================

/**
 * Example 5: Testing guard behavior
 */
use Cline\Sequencer\Exceptions\ExecutionGuardException;
use Cline\Sequencer\Support\GuardManager;

test('operations blocked on unauthorized hostname', function (): void {
    config([
        'sequencer.guards' => [
            [
                'driver' => HostnameGuard::class,
                'config' => [
                    'allowed' => ['allowed-server'],
                    'current_hostname' => 'blocked-server',
                ],
            ],
        ],
    ]);

    $manager = resolve(GuardManager::class);
    $manager->clearCache();

    expect(fn () => $manager->check())
        ->toThrow(ExecutionGuardException::class);
});

test('operations allowed on authorized hostname', function (): void {
    config([
        'sequencer.guards' => [
            [
                'driver' => HostnameGuard::class,
                'config' => [
                    'allowed' => ['allowed-server'],
                    'current_hostname' => 'allowed-server',
                ],
            ],
        ],
    ]);

    $manager = resolve(GuardManager::class);
    $manager->clearCache();

    expect($manager->isAllowed())->toBeTrue();
});

/**
 * Example 6: Checking guard status without throwing
 */
test('check guard status programmatically', function (): void {
    $manager = resolve(GuardManager::class);

    // Check if execution is allowed
    if ($manager->isAllowed()) {
        // Proceed with execution
    }

    // Get the blocking guard if any
    $blocker = $manager->getBlockingGuard();

    if ($blocker !== null) {
        // Log or handle the block
        logger()->warning('Execution blocked', [
            'guard' => $blocker->name(),
            'reason' => $blocker->reason(),
        ]);
    }
});

// =============================================================================
// GUARD EXCEPTION HANDLING
// =============================================================================

/**
 * Example 7: Handle guard exceptions in deployment scripts
 */
use Illuminate\Support\Facades\Artisan;

try {
    Artisan::call('sequencer:process');
} catch (ExecutionGuardException $e) {
    // $e->guard contains the blocking guard instance
    echo "Blocked by: {$e->guard->name()}\n";
    echo "Reason: {$e->guard->reason()}\n";

    // Exit with error code for CI/CD pipelines
    exit(1);
}

// =============================================================================
// COMMON PATTERNS
// =============================================================================

/**
 * Pattern 1: Production-only operations
 *
 * Ensure operations never accidentally run in development.
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => array_filter([
                    env('OPERATIONS_ALLOWED_HOST_1'),
                    env('OPERATIONS_ALLOWED_HOST_2'),
                ]),
            ],
        ],
    ],
];

/**
 * Pattern 2: Data center restrictions
 *
 * Only run on servers in specific network segments.
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.1.0.0/16',  // EU data center
                    '10.2.0.0/16',  // US data center
                ],
            ],
        ],
    ],
];

/**
 * Pattern 3: Disable guards in testing
 *
 * Empty guards array means all execution is allowed.
 */

// phpunit.xml or Pest.php
// <env name="SEQUENCER_GUARDS_ENABLED" value="false"/>

// config/sequencer.php
return [
    'guards' => env('SEQUENCER_GUARDS_ENABLED', true) ? [
        [
            'driver' => HostnameGuard::class,
            'config' => ['allowed' => ['prod-1']],
        ],
    ] : [],
];
