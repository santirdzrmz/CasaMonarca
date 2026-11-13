<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Services\Audit\AuditEventService;
use App\Services\Security\Encryption\AccessGrantService;
use App\Services\Security\Encryption\DekRing;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\RegistrySessionKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads into the KeyRing the keys the user unlocked in this session, and (for operators) into the DekRing the DEKs of
 * the records they were granted temporary access to. Everything is wiped when the request ends, and every use of a
 * granted key is audited. Does nothing unless REGISTRY_ENCRYPTION_ENABLED is true.
 */
class LoadRegistryKeys
{
    public function __construct(
        private readonly KeyRing $ring,
        private readonly DekRing $deks,
        private readonly RegistrySessionKeys $sessionKeys,
        private readonly AccessGrantService $grants,
        private readonly AuditEventService $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('registry_encryption.enabled') || ! $request->hasSession()) {
            return $next($request);
        }

        foreach ($this->sessionKeys->load($request->session()->getId())['keys'] as $kekId => $secret) {
            if ($secret === '') {
                continue;
            }

            $kekId === RegistrySessionKeys::SIGNING_ENTRY
                ? $this->ring->setSigningSecret($secret)
                : $this->ring->unlock((string) $kekId, $secret);
        }

        $user = $request->user();

        if ($user !== null && $user->role?->value === 'non_coordinator') {
            $this->grants->loadInto($this->deks, $request, $user);
        }

        try {
            return $next($request);
        } finally {
            foreach (array_slice($this->deks->usedResources(), 0, 10) as $used) {
                $this->audit->success($request, AuditEventType::RegistryAccessUsed, $user, ['type' => $used['type'], 'id' => $used['id']]);
            }

            $this->ring->lock();
            $this->deks->clear();
        }
    }
}
