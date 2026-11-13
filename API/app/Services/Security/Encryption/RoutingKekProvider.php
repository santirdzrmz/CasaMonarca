<?php

namespace App\Services\Security\Encryption;

/** Coexists with existing envelopes: "pk:" ids go to the public-key provider, the rest to the file provider. */
final class RoutingKekProvider implements KekProvider
{
    public function __construct(
        private readonly KekProvider $active,
        private readonly KekProvider $legacy,
    ) {}

    public function activeKekId(): string
    {
        return $this->active->activeKekId();
    }

    public function wrap(string $dek, string $aad): string
    {
        return $this->active->wrap($dek, $aad);
    }

    public function unwrap(string $kekId, string $wrapped, string $aad): string
    {
        return PublicKeyKekProvider::handles($kekId)
            ? $this->active->unwrap($kekId, $wrapped, $aad)
            : $this->legacy->unwrap($kekId, $wrapped, $aad);
    }
}
