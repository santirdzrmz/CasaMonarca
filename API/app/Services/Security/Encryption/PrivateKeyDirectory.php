<?php

namespace App\Services\Security\Encryption;

/**
 * Reads domain private keys from the administrator's private directory (files `pk_cand-1.age` or `pk_cand-1.key`).
 * Used by admin commands only; private keys never live on the web server.
 */
final class PrivateKeyDirectory
{
    public static function secret(string $directory, string $kekId, ?string $ageIdentity): string
    {
        $age = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.KeyGrants::privateFileName($kekId, 'age');
        $plain = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.KeyGrants::privateFileName($kekId, 'key');

        if (is_file($age)) {
            if ($ageIdentity === null || $ageIdentity === '') {
                throw new EncryptionException('An age identity (--age-identity) is required to open '.basename($age).'.');
            }

            $secret = base64_decode(trim(AgeCli::decrypt($age, $ageIdentity)), true);
        } elseif (is_file($plain)) {
            $secret = base64_decode(trim((string) file_get_contents($plain)), true);
        } else {
            throw new EncryptionException("No private key for $kekId in $directory");
        }

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new EncryptionException("The private key for $kekId is not valid.");
        }

        return $secret;
    }

    /** Unlocks every private key found in the directory (all generations), for rotation. */
    public static function unlockAll(KeyRing $ring, string $directory, ?string $ageIdentity): int
    {
        $count = 0;

        foreach (glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'pk_*') ?: [] as $path) {
            if (preg_match('/^pk_([a-z]+)-([0-9]+)\.(age|key)$/', basename($path), $m) === 1) {
                $kekId = "pk:{$m[1]}-{$m[2]}";
                $ring->unlock($kekId, self::secret($directory, $kekId, $ageIdentity));
                $count++;
            }
        }

        return $count;
    }

    /** Unlocks the private keys of every configured domain (cand and proc) into the ring. */
    public static function unlockInto(KeyRing $ring, string $directory, ?string $ageIdentity): void
    {
        foreach (['cand', 'proc'] as $domain) {
            $kekId = (string) config("registry_encryption.public_keys.$domain.id");
            $ring->unlock($kekId, self::secret($directory, $kekId, $ageIdentity));
        }
    }
}
