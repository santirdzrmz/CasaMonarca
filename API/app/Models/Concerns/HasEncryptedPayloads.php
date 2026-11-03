<?php

namespace App\Models\Concerns;

use App\Services\Security\Encryption\EnvelopeCipher;
use Illuminate\Database\Eloquent\Model;

/**
 * Seals payload columns into `<column>_enc` after save, once the record id exists
 * (the id is part of the AES-GCM additional authenticated data).
 *
 * Models declare `protected array $encryptedPayloads = [column => [purpose, placeholder]]`
 * where placeholder is the plaintext-column value used when plaintext writes are off.
 *
 * @mixin Model
 */
trait HasEncryptedPayloads
{
    public static function bootHasEncryptedPayloads(): void
    {
        static::saved(fn (Model $model) => $model->sealEncryptedPayloads());
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public function getEncryptedPayloadColumns(): array
    {
        return $this->encryptedPayloads;
    }

    public function sealEncryptedPayloads(bool $force = false): void
    {
        if (! $force && ! config('registry_encryption.enabled')) {
            return;
        }

        $updates = [];

        foreach ($this->encryptedPayloads as $column => [$purpose, $placeholder]) {
            $encColumn = $column.'_enc';
            $raw = $this->attributes[$column] ?? null;

            if ($raw === null || ($this->attributes[$encColumn] ?? null) !== null) {
                continue;
            }

            $payload = json_decode((string) $raw, true);
            $updates[$encColumn] = app(EnvelopeCipher::class)->encrypt(
                is_array($payload) ? $payload : [],
                $this->getTable(),
                $this->getKey(),
                $purpose,
            );

            if (! config('registry_encryption.write_plaintext')) {
                $updates[$column] = $placeholder;
            }
        }

        if ($updates === []) {
            return;
        }

        // Raw base-query update: no model events, no updated_at bump.
        static::withoutGlobalScopes()->whereKey($this->getKey())->toBase()->update($updates);
        $this->setRawAttributes(array_merge($this->attributes, $updates), true);
    }

    /** Replace plaintext columns with placeholders for rows whose envelope is already stored. */
    public function purgePlaintextPayloads(): void
    {
        $updates = [];

        foreach ($this->encryptedPayloads as $column => [, $placeholder]) {
            if (($this->attributes[$column.'_enc'] ?? null) !== null && ($this->attributes[$column] ?? null) !== $placeholder) {
                $updates[$column] = $placeholder;
            }
        }

        if ($updates !== []) {
            static::withoutGlobalScopes()->whereKey($this->getKey())->toBase()->update($updates);
            $this->setRawAttributes(array_merge($this->attributes, $updates), true);
        }
    }
}
