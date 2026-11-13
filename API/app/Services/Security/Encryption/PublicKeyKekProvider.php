<?php

namespace App\Services\Security\Encryption;

/**
 * Asymmetric KEK (X25519, libsodium sealed box) with two key domains: "cand" and "proc".
 *  - wrap():   uses only the PUBLIC key of the active domain (KekDomain), so any role can write.
 *  - unwrap(): requires the PRIVATE key in the session KeyRing; if missing, KeysLockedException (fail-closed).
 * The AAD hash travels inside the sealed box, so a `wk` copied to another record does not open.
 * Kek ids are "pk:<domain>-<version>", for example "pk:cand-1".
 */
final class PublicKeyKekProvider implements KekProvider
{
    public const PREFIX = 'pk:';

    public const DEFAULT_DOMAIN = 'cand';

    /** @param array<string, array{id: string, public: string}> $keys active public key per domain */
    public function __construct(
        private readonly array $keys,
        private readonly KeyRing $ring,
    ) {
        foreach ($keys as $domain => $key) {
            if (self::domainOf($key['id']) !== $domain) {
                throw new EncryptionException(sprintf('KEK id [%s] does not belong to domain [%s].', $key['id'], $domain));
            }

            if (strlen((string) $key['public']) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
                throw new EncryptionException(sprintf('Public key for domain [%s] is missing or invalid.', $domain));
            }
        }
    }

    public static function handles(string $kekId): bool
    {
        return str_starts_with($kekId, self::PREFIX);
    }

    public static function domainOf(string $kekId): ?string
    {
        return preg_match('/^pk:([a-z]+)-[0-9]+$/', $kekId, $m) === 1 ? $m[1] : null;
    }

    public function activeKekId(): string
    {
        return $this->active()['id'];
    }

    public function wrap(string $dek, string $aad): string
    {
        return sodium_crypto_box_seal($dek.hash('sha256', $aad, true), $this->active()['public']);
    }

    public function unwrap(string $kekId, string $wrapped, string $aad): string
    {
        $secret = $this->ring->secret($kekId);

        if ($secret === null) {
            throw new KeysLockedException(sprintf('Key [%s] is not unlocked for this session.', $kekId));
        }

        $public = sodium_crypto_box_publickey_from_secretkey($secret);
        $plain = sodium_crypto_box_seal_open($wrapped, sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public));

        if ($plain === false || strlen($plain) !== 64 || ! hash_equals(substr($plain, 32), hash('sha256', $aad, true))) {
            throw new EncryptionException('Authentication failed.');
        }

        return substr($plain, 0, 32);
    }

    /** @return array{id: string, public: string} */
    private function active(): array
    {
        $domain = KekDomain::current() ?? self::DEFAULT_DOMAIN;

        return $this->keys[$domain] ?? throw new EncryptionException(sprintf('No public key configured for domain [%s].', $domain));
    }
}
