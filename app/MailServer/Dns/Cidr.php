<?php

namespace App\MailServer\Dns;

/** Network containment for SPF's ip4: and ip6: terms (spec decision 8). */
final class Cidr
{
    /**
     * Whether $cidr (`192.0.2.0/24`, `2001:db8::/32`, or a bare address meaning /32 or /128) covers $ip.
     * Host bits set in the network are ignored, as SPF does. Anything malformed, or a family mismatch,
     * covers nothing.
     */
    public static function contains(string $cidr, string $ip): bool
    {
        $parts = explode('/', $cidr, 2);
        $network = @inet_pton($parts[0]);
        $address = @inet_pton($ip);
        if ($network === false || $address === false || strlen($network) !== strlen($address)) {
            return false;
        }

        $bits = strlen($network) * 8;
        if (! isset($parts[1])) {
            $prefix = $bits;
        } elseif (preg_match('/^\d{1,3}$/', $parts[1]) === 1 && (int) $parts[1] <= $bits) {
            $prefix = (int) $parts[1];
        } else {
            return false;
        }

        $whole = intdiv($prefix, 8);
        if (substr($network, 0, $whole) !== substr($address, 0, $whole)) {
            return false;
        }
        $rest = $prefix % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($network[$whole]) & $mask) === (ord($address[$whole]) & $mask);
    }
}
