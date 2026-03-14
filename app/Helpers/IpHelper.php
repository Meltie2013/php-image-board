<?php

/**
 * IpHelper
 *
 * Shared IP normalization helpers used by session and request-guard logic to
 * group nearby addresses without relying on exact per-request matches.
 */
class IpHelper
{
    /**
     * Normalize an IP address for broader grouping.
     *
     * - IPv4: zero the final octet
     * - IPv6: keep the first four segments and compress the remainder
     *
     * @param string $ip Raw IP address.
     * @return string Normalized IP grouping key.
     */
    public static function normalizeForGrouping(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            $parts = explode('.', $ip);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }
        else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
        {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }

        return $ip;
    }
}

