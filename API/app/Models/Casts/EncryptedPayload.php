<?php

namespace App\Models\Casts;

use App\Services\Security\Encryption\EnvelopeCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-read payload cast. Reads the envelope in `<column>_enc` when present
 * (fail-closed: a bad envelope throws, never falls back to plaintext) and the
 * legacy plaintext JSON column otherwise. Writes clear the stale envelope; the
 * HasEncryptedPayloads trait re-seals it once the record id is known.
 *
 * Usage: `'payload_json' => EncryptedPayload::class.':registration_payload'`
 */
class EncryptedPayload implements CastsAttributes
{
    public function __construct(private readonly string $purpose) {}

    /** @return array<string, mixed>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $envelope = $attributes[$key.'_enc'] ?? null;

        if (is_string($envelope) && $envelope !== '' && $model->exists) {
            return app(EnvelopeCipher::class)->decrypt($envelope, $model->getTable(), $model->getKey(), $this->purpose);
        }

        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [
            $key => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $key.'_enc' => null,
        ];
    }
}
