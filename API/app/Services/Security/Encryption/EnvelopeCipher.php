<?php

namespace App\Services\Security\Encryption;

use JsonException;

/**
 * Per-payload envelope encryption: a random 256-bit DEK encrypts the payload with
 * AES-256-GCM, and the DEK is wrapped by the active KEK. Both layers are bound to
 * environment, resource type, record id, payload purpose and format version as AAD,
 * so a valid ciphertext cannot be moved to another record or slot.
 */
final class EnvelopeCipher
{
    public const FORMAT_VERSION = 1;

    private const ALGORITHM = 'A256GCM';

    public function __construct(
        private readonly KekProvider $keks,
        private readonly string $environment,
    ) {}

    /** @param array<string, mixed> $payload */
    public function encrypt(array $payload, string $resourceType, int|string $resourceId, string $purpose): string
    {
        $aad = $this->aad($resourceType, $resourceId, $purpose);
        $dek = random_bytes(32);

        try {
            $plaintext = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return json_encode([
                'v' => self::FORMAT_VERSION,
                'alg' => self::ALGORITHM,
                'kek' => $this->keks->activeKekId(),
                'wk' => base64_encode($this->keks->wrap($dek, $aad)),
                'ct' => base64_encode(Aes256Gcm::seal($dek, $plaintext, $aad)),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EncryptionException('Payload could not be serialized.');
        } finally {
            sodium_memzero($dek);
        }
    }

    /** @return array<string, mixed> */
    public function decrypt(string $envelope, string $resourceType, int|string $resourceId, string $purpose): array
    {
        try {
            $parts = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EncryptionException('Envelope is malformed.');
        }

        if (
            ! is_array($parts)
            || ($parts['v'] ?? null) !== self::FORMAT_VERSION
            || ($parts['alg'] ?? null) !== self::ALGORITHM
            || ! is_string($parts['kek'] ?? null)
            || ! is_string($parts['wk'] ?? null)
            || ! is_string($parts['ct'] ?? null)
        ) {
            throw new EncryptionException('Envelope format is not supported.');
        }

        $wrapped = base64_decode($parts['wk'], true);
        $sealed = base64_decode($parts['ct'], true);

        if ($wrapped === false || $sealed === false) {
            throw new EncryptionException('Envelope is malformed.');
        }

        $aad = $this->aad($resourceType, $resourceId, $purpose);
        $dek = $this->keks->unwrap($parts['kek'], $wrapped, $aad);

        try {
            $decoded = json_decode(Aes256Gcm::open($dek, $sealed, $aad), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EncryptionException('Decrypted payload is invalid.');
        } finally {
            sodium_memzero($dek);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function aad(string $resourceType, int|string $resourceId, string $purpose): string
    {
        return implode('|', ['casamonarca', $this->environment, $resourceType, (string) $resourceId, $purpose, 'v'.self::FORMAT_VERSION]);
    }
}
