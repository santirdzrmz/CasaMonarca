<?php

namespace App\Services\Security\Encryption;

final class Aes256Gcm
{
    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private const KEY_BYTES = 32;

    /** Returns nonce (12) || ciphertext || tag (16). The nonce is random per call. */
    public static function seal(string $key, string $plaintext, string $aad): string
    {
        self::assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new EncryptionException('Encryption failed.');
        }

        return $nonce.$ciphertext.$tag;
    }

    public static function open(string $key, string $sealed, string $aad): string
    {
        self::assertKey($key);

        if (strlen($sealed) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new EncryptionException('Ciphertext is truncated.');
        }

        $nonce = substr($sealed, 0, self::NONCE_BYTES);
        $tag = substr($sealed, -self::TAG_BYTES);
        $ciphertext = substr($sealed, self::NONCE_BYTES, -self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        if ($plaintext === false) {
            throw new EncryptionException('Authentication failed.');
        }

        return $plaintext;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new EncryptionException('Key must be 256 bits.');
        }
    }
}
