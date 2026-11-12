<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditEventService;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyFile;
use App\Services\Security\Encryption\KeyGrants;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use App\Services\Security\Encryption\RegistrySessionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistryKeysController extends Controller
{
    public function __construct(
        private readonly RegistrySessionKeys $sessionKeys,
        private readonly AuditEventService $audit,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request)]);
    }

    /** The user uploads their key file and passphrase; THEIR grants are opened and kept in their session. */
    public function unlock(Request $request): JsonResponse
    {
        abort_unless(config('registry_encryption.mode') === 'publickey', 409, 'User keys are not enabled.');

        $validated = $request->validate([
            'key_file' => ['required', 'string', 'max:4096'],
            'passphrase' => ['required', 'string', 'max:256'],
        ]);

        try {
            $secrets = KeyFile::open($validated['key_file'], $validated['passphrase']);
        } catch (EncryptionException) {
            $this->audit->failure($request, AuditEventType::RegistryKeysUnlockFailed, $request->user(), [], ['reason' => 'invalid_key_file_or_passphrase']);

            throw ValidationException::withMessages(['passphrase' => ['The key file or passphrase is not valid.']]);
        }

        $unlocked = [];

        foreach ($this->activeGrants((int) $request->user()->getKey()) as $grant) {
            $domainSecret = KeyGrants::unwrap($grant->wrapped, $secrets['box']);

            if ($domainSecret !== null && PublicKeyKekProvider::handles($grant->kek_id)) {
                $unlocked[$grant->kek_id] = $domainSecret;
            }
        }

        if ($unlocked !== []) {
            $unlocked[RegistrySessionKeys::SIGNING_ENTRY] = $secrets['sign'];
        }

        sodium_memzero($secrets['box']);
        sodium_memzero($secrets['sign']);

        if ($unlocked === []) {
            $this->audit->failure($request, AuditEventType::RegistryKeysUnlockFailed, $request->user(), [], ['reason' => 'no_active_grants']);
            abort(403, 'No active key grants for this user.');
        }

        $this->sessionKeys->store($request->session()->getId(), $unlocked, (int) config('registry_encryption.session_key_ttl'));
        $this->audit->success($request, AuditEventType::RegistryKeysUnlocked, $request->user(), [], ['kekIds' => array_values(array_diff(array_keys($unlocked), [RegistrySessionKeys::SIGNING_ENTRY]))]);

        return response()->json(['data' => $this->state($request)]);
    }

    public function lock(Request $request): JsonResponse
    {
        $this->sessionKeys->forget($request->session()->getId());
        $this->audit->success($request, AuditEventType::RegistryKeysLocked, $request->user());

        return response()->json(['data' => $this->state($request)]);
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $userId = (int) $request->user()->getKey();
        $loaded = $this->sessionKeys->load($request->session()->getId());
        $domains = [];

        foreach (array_keys($loaded['keys']) as $kekId) {
            $domain = PublicKeyKekProvider::domainOf((string) $kekId);
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }

        return [
            'mode' => config('registry_encryption.mode'),
            'enrolled' => DB::table('registry_user_keys')->where('user_id', $userId)->whereNull('revoked_at')->exists(),
            'unlocked' => $domains !== [],
            'unlocked_domains' => array_values(array_unique($domains)),
            'unlocked_kek_ids' => array_values(array_filter(array_keys($loaded['keys']), fn ($id) => PublicKeyKekProvider::handles((string) $id))),
            'expires_in_seconds' => $domains === [] ? 0 : max(0, $loaded['expires_at'] - time()),
            'ttl_seconds' => (int) config('registry_encryption.session_key_ttl'),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function activeGrants(int $userId)
    {
        $key = DB::table('registry_user_keys')->where('user_id', $userId)->whereNull('revoked_at')->exists();

        if (! $key) {
            return collect();
        }

        return DB::table('registry_key_grants')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }
}
