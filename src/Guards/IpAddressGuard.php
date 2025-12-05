<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Guards;

use Cline\Sequencer\Contracts\ExecutionGuard;
use Illuminate\Support\Facades\Request;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const STR_PAD_LEFT;

use function count;
use function decbin;
use function explode;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function gethostbyname;
use function gethostname;
use function implode;
use function inet_pton;
use function ip2long;
use function is_string;
use function mb_str_pad;
use function mb_substr;
use function ord;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Guard that restricts operation execution to specific IP addresses or CIDR ranges.
 *
 * Use this guard to ensure operations only run on servers with designated IP addresses,
 * useful for restricting execution to specific network segments or data centers.
 *
 * Supports:
 * - Individual IPv4 addresses (e.g., '192.168.1.100')
 * - IPv4 CIDR notation (e.g., '10.0.0.0/8', '192.168.1.0/24')
 * - Individual IPv6 addresses (e.g., '2001:db8::1')
 * - IPv6 CIDR notation (e.g., '2001:db8::/32')
 *
 * Configuration via config/sequencer.php:
 * ```php
 * 'guards' => [
 *     [
 *         'driver' => Cline\Sequencer\Guards\IpAddressGuard::class,
 *         'config' => [
 *             'allowed' => ['10.0.0.0/8', '192.168.1.100', '2001:db8::/32'],
 *         ],
 *     ],
 * ],
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class IpAddressGuard implements ExecutionGuard
{
    /**
     * The current server IP address.
     */
    private string $currentIp;

    /**
     * List of allowed IP addresses and CIDR ranges.
     *
     * @var array<string>
     */
    private array $allowedIps;

    /**
     * Create a new IP address guard instance.
     *
     * @param array<string, mixed> $config Guard configuration with 'allowed' key
     */
    public function __construct(array $config = [])
    {
        /** @var array<string> $allowed */
        $allowed = $config['allowed'] ?? [];

        $this->allowedIps = $allowed;

        /** @var string $currentIp */
        $currentIp = $config['current_ip'] ?? $this->detectCurrentIp();
        $this->currentIp = $currentIp;
    }

    /**
     * Determine if operations should execute from this IP address.
     *
     * Returns true if:
     * - No allowed IPs are configured (guard is effectively disabled)
     * - The current IP matches an allowed IP or falls within an allowed CIDR range
     *
     * @return bool True if execution should proceed, false to block
     */
    public function shouldExecute(): bool
    {
        // If no IPs configured, allow execution (guard disabled)
        if ($this->allowedIps === []) {
            return true;
        }

        // If we couldn't detect an IP, block execution for safety
        if ($this->currentIp === '') {
            return false;
        }

        foreach ($this->allowedIps as $allowed) {
            if ($this->ipMatches($this->currentIp, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get explanation for why execution was blocked.
     *
     * @return string Message explaining the IP restriction
     */
    public function reason(): string
    {
        if ($this->currentIp === '') {
            return 'Execution blocked: could not detect server IP address';
        }

        return sprintf(
            "Execution blocked: IP address '%s' is not in allowed list [%s]",
            $this->currentIp,
            implode(', ', $this->allowedIps),
        );
    }

    /**
     * Get the guard identifier.
     *
     * @return string Returns 'ip_address'
     */
    public function name(): string
    {
        return 'IP Address Guard';
    }

    /**
     * Get the current IP being checked.
     *
     * @return string The current server IP address
     */
    public function getCurrentIp(): string
    {
        return $this->currentIp;
    }

    /**
     * Get the list of allowed IPs and CIDR ranges.
     *
     * @return array<string> Allowed IP list
     */
    public function getAllowedIps(): array
    {
        return $this->allowedIps;
    }

    /**
     * Check if an IP matches a given IP or CIDR range.
     *
     * @param string $ip      The IP to check
     * @param string $allowed The allowed IP or CIDR range
     */
    private function ipMatches(string $ip, string $allowed): bool
    {
        // Direct match
        if ($ip === $allowed) {
            return true;
        }

        // CIDR notation check
        if (str_contains($allowed, '/')) {
            return $this->ipInCidr($ip, $allowed);
        }

        return false;
    }

    /**
     * Check if an IP falls within a CIDR range.
     *
     * @param string $ip   The IP address to check
     * @param string $cidr The CIDR range (e.g., '10.0.0.0/8')
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);

        if (count($parts) !== 2) {
            return false;
        }

        [$range, $netmask] = $parts;
        $netmaskInt = (int) $netmask;

        // Determine if IPv4 or IPv6
        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isRangeIpv4 = filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isRangeIpv6 = filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        // Both must be same IP version
        if ($isIpv4 && $isRangeIpv4) {
            return $this->ipv4InCidr($ip, $range, $netmaskInt);
        }

        if ($isIpv6 && $isRangeIpv6) {
            return $this->ipv6InCidr($ip, $range, $netmaskInt);
        }

        return false;
    }

    /**
     * Check if an IPv4 address falls within a CIDR range.
     */
    private function ipv4InCidr(string $ip, string $range, int $netmask): bool
    {
        if ($netmask < 0 || $netmask > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $rangeLong = ip2long($range);

        if ($ipLong === false || $rangeLong === false) {
            return false;
        }

        $mask = -1 << (32 - $netmask);

        return ($ipLong & $mask) === ($rangeLong & $mask);
    }

    /**
     * Check if an IPv6 address falls within a CIDR range.
     */
    private function ipv6InCidr(string $ip, string $range, int $netmask): bool
    {
        if ($netmask < 0 || $netmask > 128) {
            return false;
        }

        $ipBin = $this->ipv6ToBinary($ip);
        $rangeBin = $this->ipv6ToBinary($range);

        if ($ipBin === null || $rangeBin === null) {
            return false;
        }

        // Compare the first $netmask bits
        $ipPrefix = mb_substr($ipBin, 0, $netmask);
        $rangePrefix = mb_substr($rangeBin, 0, $netmask);

        return $ipPrefix === $rangePrefix;
    }

    /**
     * Convert an IPv6 address to binary string representation.
     */
    private function ipv6ToBinary(string $ip): ?string
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $binary = '';

        for ($i = 0; $i < 16; ++$i) {
            $binary .= mb_str_pad(decbin(ord($packed[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $binary;
    }

    /**
     * Detect the current server's IP address.
     *
     * Attempts multiple methods to detect the server IP:
     * 1. SERVER_ADDR from $_SERVER
     * 2. hostname -I command output
     * 3. Reading from /etc/hosts
     */
    private function detectCurrentIp(): string
    {
        // Try SERVER_ADDR first (works in web context)
        $serverAddr = Request::server('SERVER_ADDR');

        if ($serverAddr !== null && is_string($serverAddr)) {
            return $serverAddr;
        }

        // Try hostname command (works in CLI)
        $hostname = gethostname();

        if ($hostname !== false) {
            $ip = gethostbyname($hostname);

            if ($ip !== $hostname && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        // Try reading network interfaces (Linux)
        $interfaceFile = '/proc/net/fib_trie';

        if (file_exists($interfaceFile)) {
            $content = file_get_contents($interfaceFile);

            if ($content !== false && preg_match('/\|--\s+(\d+\.\d+\.\d+\.\d+)\s*$/', $content, $matches)) {
                $ip = $matches[1];

                if ($ip !== '127.0.0.1' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        return '';
    }
}
