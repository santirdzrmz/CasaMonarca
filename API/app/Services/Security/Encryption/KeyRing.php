<?php

namespace App\Services\Security\Encryption;

/**
 * Private keys unlocked for the current request, by kek id (for example "pk:cand-1").
 * Empty by default: whoever lacks the key can write (public key) but cannot read.
 */
final class KeyRing
{
    /** @var array<string, string> */
    private array $secrets = [];

    private ?string $signingSecret = null;

    public function unlock(string $kekId, string $secretKey): void
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new EncryptionException('Private key must be 32 bytes.');
        }

        $this->secrets[$kekId] = $secretKey;
    }

    /** Ed25519 key (64 bytes) the user signs temporary access grants with. */
    public function setSigningSecret(string $secret): void
    {
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new EncryptionException('Signing key must be 64 bytes.');
        }

        $this->signingSecret = $secret;
    }

    public function signingSecret(): ?string
    {
        return $this->signingSecret;
    }

    public function secret(string $kekId): ?string
    {
        return $this->secrets[$kekId] ?? null;
    }

    /** @return list<string> */
    public function unlockedIds(): array
    {
        return array_keys($this->secrets);
    }

    public function lock(): void
    {
        foreach ($this->secrets as $id => $secret) {
            sodium_memzero($secret);
            unset($this->secrets[$id]);
        }

        if ($this->signingSecret !== null) {
            sodium_memzero($this->signingSecret);
            $this->signingSecret = null;
        }
    }
}
