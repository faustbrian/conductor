<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ExecutionGuard;
use Cline\Sequencer\Guards\HostnameGuard;

describe('HostnameGuard', function (): void {
    describe('shouldExecute', function (): void {
        test('allows execution when no hostnames are configured', function (): void {
            $guard = new HostnameGuard([]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('allows execution when allowed list is empty', function (): void {
            $guard = new HostnameGuard(['allowed' => []]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('allows execution when current hostname is in allowed list', function (): void {
            $guard = new HostnameGuard([
                'allowed' => ['server1', 'server2', 'server3'],
                'current_hostname' => 'server2',
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('blocks execution when current hostname is not in allowed list', function (): void {
            $guard = new HostnameGuard([
                'allowed' => ['server1', 'server2'],
                'current_hostname' => 'server3',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('sad-path');

        test('uses system hostname when not configured', function (): void {
            $systemHostname = gethostname() ?: 'unknown';

            $guard = new HostnameGuard([
                'allowed' => [$systemHostname],
            ]);

            expect($guard->shouldExecute())->toBeTrue();
        })->group('happy-path');

        test('performs exact hostname matching', function (): void {
            $guard = new HostnameGuard([
                'allowed' => ['server1.example.com'],
                'current_hostname' => 'server1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('edge-case');

        test('is case-sensitive for hostname matching', function (): void {
            $guard = new HostnameGuard([
                'allowed' => ['Server1'],
                'current_hostname' => 'server1',
            ]);

            expect($guard->shouldExecute())->toBeFalse();
        })->group('edge-case');
    });

    describe('name', function (): void {
        test('returns human-readable guard name', function (): void {
            $guard = new HostnameGuard([]);

            expect($guard->name())->toBe('Hostname Guard');
        })->group('happy-path');
    });

    describe('reason', function (): void {
        test('returns reason with current and allowed hostnames', function (): void {
            $guard = new HostnameGuard([
                'allowed' => ['server1', 'server2'],
                'current_hostname' => 'server3',
            ]);

            $reason = $guard->reason();

            expect($reason)->toContain('server3')
                ->and($reason)->toContain('server1, server2');
        })->group('happy-path');

        test('returns reason mentioning no allowed hosts when list is empty', function (): void {
            $guard = new HostnameGuard([
                'allowed' => [],
                'current_hostname' => 'server1',
            ]);

            $reason = $guard->reason();

            expect($reason)->toContain('server1');
        })->group('edge-case');
    });

    describe('contract compliance', function (): void {
        test('implements ExecutionGuard interface', function (): void {
            $guard = new HostnameGuard([]);

            expect($guard)->toBeInstanceOf(ExecutionGuard::class);
        })->group('happy-path');
    });
});
