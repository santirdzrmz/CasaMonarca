<?php

namespace App\Services\Security\Encryption;

use App\Enums\AuditEventType;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Temporary access of an operator to one record, shared by a coordinator or admin:
 * the operator opens a session key (Ks) and asks for a record; a coordinator who holds the domain key opens the record's
 * DEK, wraps it for the operator's Ks_pub and signs the grant (expiring); the operator's requests then use that DEK.
 */
final class AccessGrantService
{
    private const RESOURCES = ['registry' => MigrantRegistryEntry::class, 'arco' => MigrantArcoRequest::class];

    public function __construct(
        private readonly EnvelopeCipher $cipher,
        private readonly KeyRing $ring,
        private readonly OperatorSessionKeys $sessions,
        private readonly AuditEventService $audit,
    ) {}

    public function request(Request $http, User $operator, string $resource, int $resourceId, ?string $reason): int
    {
        $session = $this->currentSession($http, $operator);
        $this->model($resource, $resourceId);

        $existing = DB::table('registry_access_grants')
            ->where('session_id', $session->id)->where('resource_type', $resource)->where('resource_id', $resourceId)
            ->whereIn('status', ['requested', 'granted'])->whereNull('revoked_at')->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $id = (int) DB::table('registry_access_grants')->insertGetId([
            'user_id' => $operator->getKey(),
            'session_id' => $session->id,
            'resource_type' => $resource,
            'resource_id' => $resourceId,
            'status' => 'requested',
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->success($http, AuditEventType::RegistryAccessRequested, $operator, ['type' => $resource, 'id' => $resourceId], ['grantId' => $id]);

        return $id;
    }

    /** @return Collection<int, object> */
    public function requests(string $status): Collection
    {
        return DB::table('registry_access_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->join('registry_access_sessions as s', 's.id', '=', 'g.session_id')
            ->where('g.status', $status)
            ->orderByDesc('g.id')->limit(100)
            ->get(['g.id', 'g.resource_type', 'g.resource_id', 'g.status', 'g.reason', 'g.expires_at', 'g.created_at', 'u.name as operator_name', 's.fingerprint']);
    }

    /** @return Collection<int, object> */
    public function mine(Request $http, User $operator): Collection
    {
        return DB::table('registry_access_grants')
            ->where('user_id', $operator->getKey())->where('session_id', $this->currentSession($http, $operator)->id)
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'resource_type', 'resource_id', 'status', 'reason', 'expires_at', 'created_at']);
    }

    public function grant(Request $http, User $signer, int $grantId, string $fingerprint, int $minutes): void
    {
        $row = DB::table('registry_access_grants')->where('id', $grantId)->first();
        abort_unless($row !== null && $row->status === 'requested', 409, 'This access request is not pending.');
        abort_if((int) $row->user_id === (int) $signer->getKey(), 403, 'You cannot grant access to yourself.');

        $session = DB::table('registry_access_sessions')->where('id', $row->session_id)->first();
        abort_if($session === null || ($session->expires_at !== null && now()->gte($session->expires_at)), 409, 'The operator session expired.');
        abort_unless(hash_equals(strtolower($session->fingerprint), strtolower($fingerprint)), 422, 'The fingerprint does not match the operator session.');

        $signingSecret = $this->ring->signingSecret() ?? throw new KeysLockedException('Your signing key is not unlocked for this session.');
        $sessionPublic = (string) base64_decode($session->public_key, true);
        $model = $this->model($row->resource_type, (int) $row->resource_id);
        $wrapped = [];

        foreach ($model->getEncryptedPayloadColumns() as $column => [$purpose]) {
            $envelope = $model->getRawOriginal($column.'_enc');

            if (! is_string($envelope) || $envelope === '') {
                continue;
            }

            $dek = $this->cipher->openDek($envelope, $model->getTable(), $model->getKey(), $purpose);
            $wrapped[$purpose] = AccessGrantCrypto::wrapDek($dek, $this->cipher->aadFor($model->getTable(), $model->getKey(), $purpose), $sessionPublic);
            sodium_memzero($dek);
        }

        abort_if($wrapped === [], 409, 'This record has no encrypted payload to share.');

        $wrappedJson = json_encode($wrapped, JSON_THROW_ON_ERROR);
        $expires = now()->addMinutes(max(5, min(480, $minutes)));
        $signature = AccessGrantCrypto::sign(AccessGrantCrypto::payload([
            'id' => $row->id, 'resource_type' => $row->resource_type, 'resource_id' => $row->resource_id, 'user_id' => $row->user_id,
            'sessionPublic' => $sessionPublic, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'signed_by' => $signer->getKey(), 'wrapped_deks' => $wrappedJson,
        ]), $signingSecret);

        DB::table('registry_access_grants')->where('id', $row->id)->update([
            'status' => 'granted', 'wrapped_deks' => $wrappedJson, 'signed_by' => $signer->getKey(), 'signature' => $signature,
            'expires_at' => $expires, 'decided_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit->success($http, AuditEventType::RegistryAccessGranted, $signer, ['type' => $row->resource_type, 'id' => (int) $row->resource_id], [
            'grantId' => (int) $row->id, 'operatorId' => (int) $row->user_id, 'expiresAt' => $expires->toIso8601String(),
        ]);
    }

    public function deny(Request $http, User $decider, int $grantId): void
    {
        $row = DB::table('registry_access_grants')->where('id', $grantId)->where('status', 'requested')->first();
        abort_if($row === null, 409, 'This access request is not pending.');

        DB::table('registry_access_grants')->where('id', $row->id)->update(['status' => 'denied', 'decided_at' => now(), 'signed_by' => $decider->getKey(), 'updated_at' => now()]);
        $this->audit->success($http, AuditEventType::RegistryAccessDenied, $decider, ['type' => $row->resource_type, 'id' => (int) $row->resource_id], ['grantId' => (int) $row->id, 'operatorId' => (int) $row->user_id]);
    }

    public function revoke(Request $http, User $decider, int $grantId): void
    {
        $row = DB::table('registry_access_grants')->where('id', $grantId)->where('status', 'granted')->whereNull('revoked_at')->first();
        abort_if($row === null, 409, 'This access is not active.');

        DB::table('registry_access_grants')->where('id', $row->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->success($http, AuditEventType::RegistryAccessRevoked, $decider, ['type' => $row->resource_type, 'id' => (int) $row->resource_id], ['grantId' => (int) $row->id, 'operatorId' => (int) $row->user_id]);
    }

    /**
     * Verifies each active grant of this operator session (signature of a registered, non-revoked signer; expiry;
     * this session's key) and puts the opened DEKs in the DekRing for the current request.
     */
    public function loadInto(DekRing $deks, Request $http, User $operator): void
    {
        $pair = $this->sessions->secret($http);

        if ($pair === null) {
            return;
        }

        $session = DB::table('registry_access_sessions')->where('user_id', $operator->getKey())->where('session_hash', OperatorSessionKeys::sessionHash($http))->first();

        if ($session === null) {
            return;
        }

        $rows = DB::table('registry_access_grants')
            ->where('session_id', $session->id)->where('user_id', $operator->getKey())
            ->where('status', 'granted')->whereNull('revoked_at')->where('expires_at', '>', now())->get();

        foreach ($rows as $row) {
            $signer = DB::table('registry_user_keys')->where('user_id', $row->signed_by)->whereNull('revoked_at')->first();
            $class = self::RESOURCES[$row->resource_type] ?? null;

            if ($signer === null || $class === null || ! is_string($row->signature) || ! is_string($row->wrapped_deks)) {
                continue;
            }

            $payload = AccessGrantCrypto::payload([
                'id' => $row->id, 'resource_type' => $row->resource_type, 'resource_id' => $row->resource_id, 'user_id' => $row->user_id,
                'sessionPublic' => $pair['public'], 'expires_at' => date('Y-m-d H:i:s', strtotime((string) $row->expires_at)),
                'signed_by' => $row->signed_by, 'wrapped_deks' => $row->wrapped_deks,
            ]);

            if (! AccessGrantCrypto::verify($payload, $row->signature, (string) base64_decode($signer->signing_public_key, true))) {
                continue;
            }

            $table = (new $class)->getTable();

            foreach ((array) json_decode($row->wrapped_deks, true) as $purpose => $wrapped) {
                $dek = AccessGrantCrypto::unwrapDek((string) $wrapped, $this->cipher->aadFor($table, (int) $row->resource_id, (string) $purpose), $pair['public'], $pair['secret']);

                if ($dek !== null) {
                    $deks->grant($table, (int) $row->resource_id, (string) $purpose, $dek);
                }
            }
        }
    }

    private function currentSession(Request $http, User $operator): object
    {
        $session = DB::table('registry_access_sessions')->where('user_id', $operator->getKey())->where('session_hash', OperatorSessionKeys::sessionHash($http))->first();
        abort_if($session === null, 409, 'Open your access session first.');

        return $session;
    }

    private function model(string $resource, int $id): Model
    {
        $class = self::RESOURCES[$resource] ?? null;
        abort_if($class === null, 422, 'Unknown resource.');

        $query = $class::query()->withoutGlobalScopes();
        if (method_exists($class, 'withTrashed')) {
            $query->withTrashed();
        }

        return $query->find($id) ?? abort(404, 'Record not found.');
    }
}
