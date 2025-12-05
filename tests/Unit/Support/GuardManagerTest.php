<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\ExecutionGuardException;
use Cline\Sequencer\Guards\HostnameGuard;
use Cline\Sequencer\Guards\IpAddressGuard;
use Cline\Sequencer\Support\GuardManager;
use Illuminate\Support\Facades\Config;

describe('GuardManager', function (): void {
    beforeEach(function (): void {
        $this->manager = new GuardManager();
    });

    afterEach(function (): void {
        $this->manager->clearCache();
    });

    describe('getGuards', function (): void {
        test('returns empty array when no guards configured', function (): void {
            Config::set('sequencer.guards', []);

            expect($this->manager->getGuards())->toBe([]);
        })->group('happy-path');

        test('creates guard instances from config', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => ['allowed' => ['server1']],
                ],
            ]);
            $this->manager->clearCache();

            $guards = $this->manager->getGuards();

            expect($guards)->toHaveCount(1)
                ->and($guards[0])->toBeInstanceOf(HostnameGuard::class);
        })->group('happy-path');

        test('creates multiple guard instances', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => ['allowed' => ['server1']],
                ],
                [
                    'driver' => IpAddressGuard::class,
                    'config' => ['allowed' => ['192.168.1.1']],
                ],
            ]);
            $this->manager->clearCache();

            $guards = $this->manager->getGuards();

            expect($guards)->toHaveCount(2)
                ->and($guards[0])->toBeInstanceOf(HostnameGuard::class)
                ->and($guards[1])->toBeInstanceOf(IpAddressGuard::class);
        })->group('happy-path');

        test('caches guard instances', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => ['allowed' => ['server1']],
                ],
            ]);
            $this->manager->clearCache();

            $guards1 = $this->manager->getGuards();
            $guards2 = $this->manager->getGuards();

            expect($guards1)->toBe($guards2);
        })->group('happy-path');

        test('throws exception for non-existent driver class', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => 'NonExistent\\Guard\\Class',
                    'config' => [],
                ],
            ]);
            $this->manager->clearCache();

            expect(fn () => $this->manager->getGuards())
                ->toThrow(InvalidArgumentException::class, 'does not exist');
        })->group('sad-path');

        test('throws exception when driver does not implement ExecutionGuard', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => stdClass::class,
                    'config' => [],
                ],
            ]);
            $this->manager->clearCache();

            expect(fn () => $this->manager->getGuards())
                ->toThrow(InvalidArgumentException::class, 'must implement');
        })->group('sad-path');

        test('uses empty config when config key not provided', function (): void {
            Config::set('sequencer.guards', [
                ['driver' => HostnameGuard::class],
            ]);
            $this->manager->clearCache();

            $guards = $this->manager->getGuards();

            expect($guards)->toHaveCount(1)
                ->and($guards[0]->shouldExecute())->toBeTrue(); // Empty allowed = allow all
        })->group('edge-case');
    });

    describe('check', function (): void {
        test('does not throw when all guards pass', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [], // Empty = allow all
                ],
            ]);
            $this->manager->clearCache();

            expect(fn () => $this->manager->check())->not->toThrow(ExecutionGuardException::class);
        })->group('happy-path');

        test('throws ExecutionGuardException when a guard blocks', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['allowed-server'],
                        'current_hostname' => 'blocked-server',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            expect(fn () => $this->manager->check())
                ->toThrow(ExecutionGuardException::class);
        })->group('sad-path');

        test('exception contains blocking guard reference', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['allowed-server'],
                        'current_hostname' => 'blocked-server',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            try {
                $this->manager->check();
                $this->fail('Expected ExecutionGuardException');
            } catch (ExecutionGuardException $executionGuardException) {
                expect($executionGuardException->guard)->toBeInstanceOf(HostnameGuard::class);
            }
        })->group('sad-path');

        test('stops at first blocking guard', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['blocked'],
                        'current_hostname' => 'current',
                    ],
                ],
                [
                    'driver' => IpAddressGuard::class,
                    'config' => [
                        'allowed' => ['blocked'],
                        'current_ip' => 'current',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            try {
                $this->manager->check();
                $this->fail('Expected ExecutionGuardException');
            } catch (ExecutionGuardException $executionGuardException) {
                expect($executionGuardException->guard)->toBeInstanceOf(HostnameGuard::class);
            }
        })->group('edge-case');
    });

    describe('isAllowed', function (): void {
        test('returns true when all guards pass', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [], // Empty = allow all
                ],
            ]);
            $this->manager->clearCache();

            expect($this->manager->isAllowed())->toBeTrue();
        })->group('happy-path');

        test('returns false when any guard blocks', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['allowed-server'],
                        'current_hostname' => 'blocked-server',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            expect($this->manager->isAllowed())->toBeFalse();
        })->group('sad-path');

        test('returns true when no guards configured', function (): void {
            Config::set('sequencer.guards', []);
            $this->manager->clearCache();

            expect($this->manager->isAllowed())->toBeTrue();
        })->group('edge-case');
    });

    describe('getBlockingGuard', function (): void {
        test('returns null when all guards pass', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [], // Empty = allow all
                ],
            ]);
            $this->manager->clearCache();

            expect($this->manager->getBlockingGuard())->toBeNull();
        })->group('happy-path');

        test('returns blocking guard when one blocks', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['allowed-server'],
                        'current_hostname' => 'blocked-server',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            $blocker = $this->manager->getBlockingGuard();

            expect($blocker)->toBeInstanceOf(HostnameGuard::class);
        })->group('sad-path');

        test('returns first blocking guard when multiple block', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => [
                        'allowed' => ['allowed'],
                        'current_hostname' => 'blocked',
                    ],
                ],
                [
                    'driver' => IpAddressGuard::class,
                    'config' => [
                        'allowed' => ['192.168.1.1'],
                        'current_ip' => '10.0.0.1',
                    ],
                ],
            ]);
            $this->manager->clearCache();

            $blocker = $this->manager->getBlockingGuard();

            expect($blocker)->toBeInstanceOf(HostnameGuard::class);
        })->group('edge-case');
    });

    describe('clearCache', function (): void {
        test('clears cached guard instances', function (): void {
            Config::set('sequencer.guards', [
                [
                    'driver' => HostnameGuard::class,
                    'config' => ['allowed' => ['server1']],
                ],
            ]);

            $guards1 = $this->manager->getGuards();

            // Change config
            Config::set('sequencer.guards', [
                [
                    'driver' => IpAddressGuard::class,
                    'config' => ['allowed' => ['192.168.1.1']],
                ],
            ]);

            // Without clearing, should still return cached
            $guards2 = $this->manager->getGuards();
            expect($guards2[0])->toBeInstanceOf(HostnameGuard::class);

            // After clearing, should return new
            $this->manager->clearCache();
            $guards3 = $this->manager->getGuards();
            expect($guards3[0])->toBeInstanceOf(IpAddressGuard::class);
        })->group('happy-path');
    });
});
