<?php

namespace App\Services\Security\Encryption;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of each coordinator's or admin's personal public key. Every change keeps the previous key in
 * registry_user_key_history and writes an audit event, so the log shows which public key a user had and when.
 */
class RegistryUserKeys
{
    public function __construct(private readonly AuditEventService $audit) {}

    public function active(User $user): ?object
    {
        return DB::table('registry_user_keys')->where('user_id', $user->getKey())->whereNull('revoked_at')->first();
    }

    /**
     * Register a new public key for the user. An active key is replaced only when $replace is true; the old
     * key is retired in the history and its domain grants are revoked (they were wrapped for the old key).
     *
     * @param  array{public: string, signing_public: string, fingerprint: string}  $keyFile
     * @return array{replaced: ?string, revokedGrants: int}
     */
    public function enroll(User $user, array $keyFile, string $source, bool $replace = false): array
    {
        $previous = $this->active($user);

        if ($previous !== null && ! $replace) {
            throw new EncryptionException("The user already has an active key ({$previous->fingerprint}). Use --replace to replace it.");
        }

        return DB::transaction(function () use ($user, $keyFile, $source, $previous): array {
            $revokedGrants = 0;

            if ($previous !== null) {
                $this->retireHistory($user, 'replaced');
                $revokedGrants = $this->revokeGrants($user);
            }

            $publicKey = base64_encode($keyFile['public']);
            $signingKey = base64_encode($keyFile['signing_public']);

            DB::table('registry_user_keys')->updateOrInsert(
                ['user_id' => $user->getKey()],
                [
                    'public_key' => $publicKey,
                    'signing_public_key' => $signingKey,
                    'fingerprint' => $keyFile['fingerprint'],
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('registry_user_key_history')->insert([
                'user_id' => $user->getKey(),
                'public_key' => $publicKey,
                'signing_public_key' => $signingKey,
                'fingerprint' => $keyFile['fingerprint'],
                'enrolled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->console(AuditEventType::RegistryKeysEnrolled, ['type' => User::class, 'id' => $user->getKey()], [
                'email' => $user->email,
                'fingerprint' => $keyFile['fingerprint'],
                'publicKey' => $publicKey,
                'signingPublicKey' => $signingKey,
                'replacedFingerprint' => $previous?->fingerprint,
                'revokedGrants' => $revokedGrants,
                'source' => $source,
                'algorithms' => [
                    'encryption' => CryptoAlgorithms::KEY_AGREEMENT_KEYPAIR,
                    'signing' => CryptoAlgorithms::SIGNING_KEYPAIR,
                    'keyFile' => CryptoAlgorithms::KEY_FILE,
                ],
            ]);

            return ['replaced' => $previous?->fingerprint, 'revokedGrants' => $revokedGrants];
        });
    }

    /**
     * @return array{fingerprint: ?string, revokedGrants: int}
     */
    public function revoke(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $key = $this->active($user);

            DB::table('registry_user_keys')->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->retireHistory($user, 'revoked');
            $revokedGrants = $this->revokeGrants($user);

            $this->audit->console(AuditEventType::RegistryKeysRevoked, ['type' => User::class, 'id' => $user->getKey()], [
                'email' => $user->email,
                'fingerprint' => $key?->fingerprint,
                'revokedGrants' => $revokedGrants,
            ]);

            return ['fingerprint' => $key?->fingerprint, 'revokedGrants' => $revokedGrants];
        });
    }

    private function retireHistory(User $user, string $reason): void
    {
        DB::table('registry_user_key_history')
            ->where('user_id', $user->getKey())
            ->whereNull('retired_at')
            ->update(['retired_at' => now(), 'retired_reason' => $reason, 'updated_at' => now()]);
    }

    private function revokeGrants(User $user): int
    {
        return DB::table('registry_key_grants')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
