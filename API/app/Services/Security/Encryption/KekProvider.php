<?php

namespace App\Services\Security\Encryption;

/**
 * Key-encryption-key boundary, implemented by PublicKeyKekProvider (per-user keys).
 * Another provider (for example AWS KMS) can replace it without touching stored payloads.
 */
interface KekProvider
{
    public function activeKekId(): string;

    /** @return string DEK sealed under the active KEK (nonce || ct || tag). */
    public function wrap(string $dek, string $aad): string;

    public function unwrap(string $kekId, string $wrapped, string $aad): string;
}
