<?php

namespace App\Services\Security\Encryption;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Domain private keys (and the signing key) unlocked by a user, bound to THEIR session and expiring.
 * Stored encrypted with the application key; never written in plaintext and never outlive the TTL.
 */
final class RegistrySessionKeys
{
    public const SIGNING_ENTRY = '_sign';

    /** @param array<string, string> $secrets kek id (or SIGNING_ENTRY) => private key bytes */
    public function store(string $sessionId, array $secrets, int $ttlSeconds): void
    {
        $ttl = max(60, $ttlSeconds);
        $payload = ['keys' => array_map('base64_encode', $secrets), 'expires_at' => time() + $ttl];
        Cache::put(self::key($sessionId), Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), $ttl);
    }

    /** @return array{keys: array<string, string>, expires_at: int} */
    public function load(string $sessionId): array
    {
        $blob = Cache::get(self::key($sessionId));

        if (! is_string($blob)) {
            return ['keys' => [], 'expires_at' => 0];
        }

        $decoded = json_decode(Crypt::decryptString($blob), true);
        $keys = is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [];

        return [
            'keys' => array_map(fn ($v) => (string) base64_decode((string) $v, true), $keys),
            'expires_at' => (int) ($decoded['expires_at'] ?? 0),
        ];
    }

    public function forget(string $sessionId): void
    {
        Cache::forget(self::key($sessionId));
    }

    private static function key(string $sessionId): string
    {
        return 'registry-keys:'.hash('sha256', $sessionId);
    }
}
