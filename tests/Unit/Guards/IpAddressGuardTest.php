<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ExecutionGuard;
use Cline\Sequencer\Guards\IpAddressGuard;

describe('IpAddressGuard', function (): void {
    describe('shouldExecute with exact IP matching', function (): void {
        test('allows execution when no IPs are configured', function (): void {
            $guard = new IpAddressGuard([]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('allows execution when allowed list is empty', function (): void {
            $guard = new IpAddressGuard(['allowed' => []]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('allows execution when current IP is in allowed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.1', '192.168.1.2', '10.0.0.1'],
                'current_ip' => '192.168.1.2',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when current IP is not in allowed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.1', '192.168.1.2'],
                'current_ip' => '192.168.1.100',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');
    });

    describe('shouldExecute with CIDR notation', function (): void {
        test('allows execution when IP is within CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.0/24'],
                'current_ip' => '192.168.1.50',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when IP is outside CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.0/24'],
                'current_ip' => '192.168.2.1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');

        test('allows execution for /32 CIDR (exact match)', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.1/32'],
                'current_ip' => '192.168.1.1',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('edge-case');

        test('blocks execution for /32 CIDR when IP differs', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.1/32'],
                'current_ip' => '192.168.1.2',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('edge-case');

        test('allows execution for /16 CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['10.0.0.0/16'],
                'current_ip' => '10.0.255.255',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when outside /16 CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['10.0.0.0/16'],
                'current_ip' => '10.1.0.1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');
    });

    describe('shouldExecute with mixed exact and CIDR', function (): void {
        test('allows execution when IP matches exact entry in mixed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.0/24', '10.0.0.50'],
                'current_ip' => '10.0.0.50',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('allows execution when IP matches CIDR in mixed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['10.0.0.50', '192.168.1.0/24'],
                'current_ip' => '192.168.1.100',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when IP matches neither exact nor CIDR', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.0/24', '10.0.0.50'],
                'current_ip' => '172.16.0.1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');
    });

    describe('shouldExecute with IPv6', function (): void {
        test('allows execution when IPv6 is in allowed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['::1', '2001:db8::1'],
                'current_ip' => '::1',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when IPv6 is not in allowed list', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['::1', '2001:db8::1'],
                'current_ip' => '2001:db8::2',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');

        test('allows execution for IPv6 CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['2001:db8::/32'],
                'current_ip' => '2001:db8:1234:5678::1',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution for IPv6 outside CIDR range', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['2001:db8::/32'],
                'current_ip' => '2001:db9::1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');
    });

    describe('name', function (): void {
        test('returns human-readable guard name', function (): void {
            $guard = new IpAddressGuard([]);

            expect($guard->name())->toBe('IP Address Guard');
        })->group('happy-path');
    });

    describe('reason', function (): void {
        test('returns reason with current IP and allowed addresses', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.0/24', '10.0.0.1'],
                'current_ip' => '172.16.0.1',
            ]);

            $reason = $guard->reason();

            expect($reason)->toContain('172.16.0.1')
                ->and($reason)->toContain('192.168.1.0/24')
                ->and($reason)->toContain('10.0.0.1');
        })->group('happy-path');
    });

    describe('contract compliance', function (): void {
        test('implements ExecutionGuard interface', function (): void {
            $guard = new IpAddressGuard([]);

            expect($guard)->toBeInstanceOf(ExecutionGuard::class);
        })->group('happy-path');
    });

    describe('edge cases', function (): void {
        test('handles empty string IP address', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['192.168.1.1'],
                'current_ip' => '',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('edge-case');

        test('handles localhost IP', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['127.0.0.1'],
                'current_ip' => '127.0.0.1',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('edge-case');

        test('handles private network ranges', function (): void {
            $guard = new IpAddressGuard([
                'allowed' => ['10.0.0.0/8'],
                'current_ip' => '10.255.255.255',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('edge-case');
    });
});
