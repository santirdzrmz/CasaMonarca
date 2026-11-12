<?php

namespace App\Services\Security\Encryption;

use Closure;

/**
 * Key domain active while a payload is sealed: "cand" (candidates, before approval) or "proc"
 * (processed, already approved). Lets the public key be chosen without changing the KekProvider interface.
 */
final class KekDomain
{
    private static ?string $current = null;

    public static function using(?string $domain, Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $domain;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public static function current(): ?string
    {
        return self::$current;
    }
}
