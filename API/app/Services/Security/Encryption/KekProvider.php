<?php

namespace App\Services\Security\Encryption;

/**
 * Key-encryption-key boundary. The file provider is the interim implementation;
 * an AWS KMS provider can replace it without touching stored envelopes.
 */
interface KekProvider
{
    public function activeKekId(): string;

    /** @return string DEK sealed under the active KEK (nonce || ct || tag). */
    public function wrap(string $dek, string $aad): string;

    public function unwrap(string $kekId, string $wrapped, string $aad): string;
}
