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
        private readonly ?DekRing $deks = null,
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
        $parts = $this->parts($envelope);
        $aad = $this->aad($resourceType, $resourceId, $purpose);

        // A DEK granted to this user for this exact record (temporary, signed grant) skips the KEK unwrap.
        $granted = $this->deks?->dek($resourceType, $resourceId, $purpose);
        $dek = $granted ?? $this->keks->unwrap($parts['kek'], $parts['wrapped'], $aad);

        try {
            $decoded = json_decode(Aes256Gcm::open($dek, $parts['sealed'], $aad), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EncryptionException('Decrypted payload is invalid.');
        } finally {
            if ($granted === null) {
                sodium_memzero($dek);
            }
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Opens the envelope's DEK with the active KEK access (private key unlocked in the session). Caller must wipe it. */
    public function openDek(string $envelope, string $resourceType, int|string $resourceId, string $purpose): string
    {
        $parts = $this->parts($envelope);

        return $this->keks->unwrap($parts['kek'], $parts['wrapped'], $this->aad($resourceType, $resourceId, $purpose));
    }

    /**
     * Re-wraps the DEK with the active KEK (use inside KekDomain::using to pick the domain). The ciphertext is untouched.
     * Used when rotating keys.
     */
    public function rewrap(string $envelope, string $resourceType, int|string $resourceId, string $purpose): string
    {
        $parts = $this->parts($envelope);
        $aad = $this->aad($resourceType, $resourceId, $purpose);
        $dek = $this->keks->unwrap($parts['kek'], $parts['wrapped'], $aad);

        try {
            return json_encode([
                'v' => self::FORMAT_VERSION,
                'alg' => self::ALGORITHM,
                'kek' => $this->keks->activeKekId(),
                'wk' => base64_encode($this->keks->wrap($dek, $aad)),
                'ct' => base64_encode($parts['sealed']),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EncryptionException('Envelope could not be re-wrapped.');
        } finally {
            sodium_memzero($dek);
        }
    }

    /** The additional authenticated data of a record's envelope (needed to bind delegated copies of its DEK). */
    public function aadFor(string $resourceType, int|string $resourceId, string $purpose): string
    {
        return $this->aad($resourceType, $resourceId, $purpose);
    }

    /** @return array{kek: string, wrapped: string, sealed: string} */
    private function parts(string $envelope): array
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

        return ['kek' => $parts['kek'], 'wrapped' => $wrapped, 'sealed' => $sealed];
    }

    private function aad(string $resourceType, int|string $resourceId, string $purpose): string
    {
        return implode('|', ['casamonarca', $this->environment, $resourceType, (string) $resourceId, $purpose, 'v'.self::FORMAT_VERSION]);
    }
}
