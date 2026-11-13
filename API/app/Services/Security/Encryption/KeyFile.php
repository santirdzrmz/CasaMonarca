<?php

namespace App\Services\Security\Encryption;

/**
 * A user's personal key file: their X25519 private key (to open delivered keys) and their Ed25519 signing key
 * (to sign temporary access grants), both encrypted with a passphrase (Argon2id + secretbox).
 * The public halves are registered on the server; the user keeps the file.
 */
final class KeyFile
{
    /** @return array{file: string, public: string, signing_public: string, fingerprint: string} */
    public static function create(string $passphrase): array
    {
        $box = sodium_crypto_box_keypair();
        $sign = sodium_crypto_sign_keypair();
        $public = sodium_crypto_box_publickey($box);
        $signingPublic = sodium_crypto_sign_publickey($sign);
        $secrets = sodium_crypto_box_secretkey($box).sodium_crypto_sign_secretkey($sign);

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $file = json_encode([
            'v' => 2,
            'kdf' => 'argon2id13',
            'ops' => $ops,
            'mem' => $mem,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'box' => base64_encode(sodium_crypto_secretbox($secrets, $nonce, $key)),
            'pub' => base64_encode($public),
            'spub' => base64_encode($signingPublic),
        ], JSON_THROW_ON_ERROR);

        sodium_memzero($secrets);
        sodium_memzero($key);

        return [
            'file' => $file,
            'public' => $public,
            'signing_public' => $signingPublic,
            'fingerprint' => self::fingerprint($public, $signingPublic),
        ];
    }

    /**
     * Returns the private keys: `box` (32 bytes, opens delivered keys) and `sign` (64 bytes, signs access grants).
     * The caller must wipe them with sodium_memzero.
     *
     * @return array{box: string, sign: string}
     */
    public static function open(string $file, string $passphrase): array
    {
        $data = json_decode($file, true);

        if (! is_array($data) || ($data['v'] ?? null) !== 2 || ($data['kdf'] ?? null) !== 'argon2id13') {
            throw new EncryptionException('Key file is not valid.');
        }

        $salt = base64_decode((string) ($data['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $box = base64_decode((string) ($data['box'] ?? ''), true);

        if (
            $salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            || $box === false
        ) {
            throw new EncryptionException('Key file is not valid.');
        }

        $ops = (int) ($data['ops'] ?? 0);
        $mem = (int) ($data['mem'] ?? 0);

        // Stops a tampered file from demanding absurd amounts of memory or time.
        if ($ops < 1 || $ops > SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE || $mem < 8192 || $mem > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE) {
            throw new EncryptionException('Key file is not valid.');
        }

        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $secrets = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);

        if ($secrets === false || strlen($secrets) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES + SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new EncryptionException('Key file or passphrase is not valid.');
        }

        $result = [
            'box' => substr($secrets, 0, SODIUM_CRYPTO_BOX_SECRETKEYBYTES),
            'sign' => substr($secrets, SODIUM_CRYPTO_BOX_SECRETKEYBYTES),
        ];
        sodium_memzero($secrets);

        return $result;
    }

    /** Short fingerprint to confirm the user's public keys over another channel (voice, in person). */
    public static function fingerprint(string ...$publicKeys): string
    {
        return implode('-', str_split(substr(bin2hex(sodium_crypto_generichash(implode('|', array_map('base64_encode', $publicKeys)))), 0, 12), 4));
    }
}
