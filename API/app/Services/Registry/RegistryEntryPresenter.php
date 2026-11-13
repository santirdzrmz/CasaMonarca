<?php

namespace App\Services\Registry;

use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Security\Encryption\EncryptionException;

/**
 * Da forma a las respuestas del API según quién pregunta. Con REGISTRY_VISIBILITY_ENFORCED=false devuelve el
 * modelo tal cual (comportamiento original). Con true, nunca descifra lo que el rol no debe ver, y si falta la
 * llave (EncryptionException) devuelve el registro bloqueado en lugar de fallar.
 */
final class RegistryEntryPresenter
{
    public function __construct(private readonly RegistryVisibilityPolicy $policy) {}

    public function enforced(): bool
    {
        return (bool) config('registry_visibility.enforced');
    }

    public function isVisibleTo(MigrantRegistryEntry $entry, ?User $viewer): bool
    {
        if (! $this->enforced()) {
            return true;
        }

        return $viewer !== null && $this->level($entry, $viewer) !== RegistryVisibilityPolicy::NONE;
    }

    /** @param iterable<MigrantRegistryEntry> $entries */
    public function presentMany(iterable $entries, ?User $viewer): iterable
    {
        if (! $this->enforced()) {
            return $entries;
        }

        $out = [];
        foreach ($entries as $entry) {
            if ($this->isVisibleTo($entry, $viewer)) {
                $out[] = $this->present($entry, $viewer);
            }
        }

        return $out;
    }

    public function present(MigrantRegistryEntry $entry, ?User $viewer): mixed
    {
        if (! $this->enforced() || $viewer === null) {
            return $entry;
        }

        $reason = null;

        if ($this->level($entry, $viewer) === RegistryVisibilityPolicy::FULL) {
            try {
                return array_merge($entry->toArray(), ['payload_locked' => false]);
            } catch (EncryptionException) {
                $reason = 'keys_locked';
            }
        }

        return array_merge(
            $entry->makeHidden(['payload_json', 'pending_payload_json'])->toArray(),
            ['payload_locked' => true, 'payload_lock_reason' => $reason ?? 'role_not_allowed'],
        );
    }

    /**
     * ARCO requests carry copies of the registration (original and proposed payloads). Whoever cannot open them
     * (no unlocked key and no temporary access) gets the request without that content instead of an error.
     */
    public function presentArco(MigrantArcoRequest $request): mixed
    {
        if (! $this->enforced() && ! config('registry_encryption.enabled')) {
            return $request;
        }

        try {
            return array_merge($request->toArray(), ['payload_locked' => false]);
        } catch (EncryptionException) {
            $request->makeHidden(['original_payload_json', 'proposed_payload_json']);

            if ($request->relationLoaded('registryEntry') && $request->registryEntry !== null) {
                $request->registryEntry->makeHidden(['payload_json', 'pending_payload_json']);
            }

            return array_merge($request->toArray(), ['payload_locked' => true, 'payload_lock_reason' => 'keys_locked']);
        }
    }

    private function level(MigrantRegistryEntry $entry, User $viewer): string
    {
        return $this->policy->level(
            $viewer->role->value,
            (string) $entry->current_status,
            (int) $entry->created_by === (int) $viewer->getKey(),
            (array) config('registry_visibility.operator_payload_statuses', []),
        );
    }
}
