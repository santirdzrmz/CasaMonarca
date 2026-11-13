<?php

namespace App\Models\Concerns;

use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\KekDomain;
use App\Services\Security\Encryption\PublicKeyKekProvider;
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
        static::saved(fn (Model $model) => $model->resealForKeyDomain());
    }

    /**
     * Key domain ("cand" or "proc") used to seal a column when the mode is `publickey`.
     * Models override it; null uses the default domain.
     */
    public function encryptionKeyDomain(string $column): ?string
    {
        return null;
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
            $cipher = app(EnvelopeCipher::class);
            $updates[$encColumn] = KekDomain::using(
                $this->encryptionKeyDomain($column),
                fn (): string => $cipher->encrypt(is_array($payload) ? $payload : [], $this->getTable(), $this->getKey(), $purpose),
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

    /**
     * If the record moved to another domain (for example from candidate to approved), re-seals the payload
     * with the new domain's key. Requires the session to have both domains' keys unlocked.
     */
    public function resealForKeyDomain(): void
    {
        if (! config('registry_encryption.enabled')) {
            return;
        }

        $updates = [];

        foreach ($this->encryptedPayloads as $column => [$purpose]) {
            $envelope = $this->attributes[$column.'_enc'] ?? null;
            $domain = $this->encryptionKeyDomain($column);

            if (! is_string($envelope) || $envelope === '' || $domain === null) {
                continue;
            }

            $kek = json_decode($envelope, true)['kek'] ?? null;

            if (! is_string($kek) || ! PublicKeyKekProvider::handles($kek) || PublicKeyKekProvider::domainOf($kek) === $domain) {
                continue;
            }

            $cipher = app(EnvelopeCipher::class);
            $payload = $cipher->decrypt($envelope, $this->getTable(), $this->getKey(), $purpose);
            $updates[$column.'_enc'] = KekDomain::using(
                $domain,
                fn (): string => $cipher->encrypt($payload, $this->getTable(), $this->getKey(), $purpose),
            );
        }

        if ($updates !== []) {
            static::withoutGlobalScopes()->whereKey($this->getKey())->toBase()->update($updates);
            $this->setRawAttributes(array_merge($this->attributes, $updates), true);
        }
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
