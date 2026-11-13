<?php

namespace App\Services\Security\Encryption;

/**
 * Cryptography of temporary access grants (pure functions, no database).
 * A coordinator or admin re-wraps one record's DEK for an operator's SESSION public key and signs the grant.
 * The operator's session key (Ks) is created at sign-in and destroyed with the session.
 */
final class AccessGrantCrypto
{
    /** Seals the DEK for the session public key; the AAD hash travels inside so the copy only opens for that record. */
    public static function wrapDek(string $dek, string $aad, string $sessionPublic): string
    {
        return base64_encode(sodium_crypto_box_seal($dek.hash('sha256', $aad, true), $sessionPublic));
    }

    public static function unwrapDek(string $wrapped, string $aad, string $sessionPublic, string $sessionSecret): ?string
    {
        $raw = base64_decode($wrapped, true);

        if ($raw === false || strlen($sessionPublic) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES || strlen($sessionSecret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return null;
        }

        $plain = sodium_crypto_box_seal_open($raw, sodium_crypto_box_keypair_from_secretkey_and_publickey($sessionSecret, $sessionPublic));

        if ($plain === false || strlen($plain) !== 64 || ! hash_equals(substr($plain, 32), hash('sha256', $aad, true))) {
            return null;
        }

        return substr($plain, 0, 32);
    }

    /**
     * Canonical text the signer signs. It binds the grant to the record, the operator, that operator's session key,
     * the expiry, the signer and the exact wrapped keys.
     *
     * @param  array{id: int|string, resource_type: string, resource_id: int|string, user_id: int|string, sessionPublic: string, expires_at: string, signed_by: int|string, wrapped_deks: string}  $grant
     */
    public static function payload(array $grant): string
    {
        return implode('|', [
            'registry-access-grant',
            $grant['id'],
            $grant['resource_type'],
            $grant['resource_id'],
            $grant['user_id'],
            KeyFile::fingerprint($grant['sessionPublic']),
            $grant['expires_at'],
            $grant['signed_by'],
            hash('sha256', $grant['wrapped_deks']),
        ]);
    }

    public static function sign(string $payload, string $signingSecret): string
    {
        return base64_encode(sodium_crypto_sign_detached($payload, $signingSecret));
    }

    public static function verify(string $payload, string $signature, string $signingPublic): bool
    {
        $raw = base64_decode($signature, true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES && strlen($signingPublic) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($raw, $payload, $signingPublic);
    }
}
