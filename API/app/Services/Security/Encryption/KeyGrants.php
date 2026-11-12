<?php

namespace App\Services\Security\Encryption;

/** Delivery of domain private keys to a user: the key is encrypted with the user's PUBLIC key. */
final class KeyGrants
{
    /** Windows-safe file name for a domain private key (colons are not allowed). */
    public static function privateFileName(string $kekId, string $extension): string
    {
        return str_replace(':', '_', $kekId).'.'.$extension;
    }

    public static function wrap(string $domainSecret, string $userPublic): string
    {
        return base64_encode(sodium_crypto_box_seal($domainSecret, $userPublic));
    }

    /** Null if the package is not for this private key. */
    public static function unwrap(string $wrapped, string $userSecret): ?string
    {
        $raw = base64_decode($wrapped, true);

        if ($raw === false || strlen($userSecret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return null;
        }

        $public = sodium_crypto_box_publickey_from_secretkey($userSecret);
        $plain = sodium_crypto_box_seal_open($raw, sodium_crypto_box_keypair_from_secretkey_and_publickey($userSecret, $public));

        return $plain === false || strlen($plain) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES ? null : $plain;
    }
}
