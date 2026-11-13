<?php

namespace App\Services\Security\Encryption;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Per-session key pair of an operator (Ks). It is created on demand, lives in the cache (encrypted with the application
 * key) for the session lifetime and disappears with it. Only the PUBLIC half is registered in the database, so a
 * coordinator can wrap record keys for it.
 */
final class OperatorSessionKeys
{
    /** @return array{id: int, fingerprint: string, public: string, expires_at: string} */
    public function ensure(Request $request, int $userId): array
    {
        $hash = self::sessionHash($request);
        $pair = $this->secret($request);

        if ($pair === null) {
            $box = sodium_crypto_box_keypair();
            $pair = ['public' => sodium_crypto_box_publickey($box), 'secret' => sodium_crypto_box_secretkey($box)];
            Cache::put(
                self::cacheKey($hash),
                Crypt::encryptString(json_encode(['public' => base64_encode($pair['public']), 'secret' => base64_encode($pair['secret'])], JSON_THROW_ON_ERROR)),
                $this->ttl(),
            );
        }

        $expires = now()->addSeconds($this->ttl());
        DB::table('registry_access_sessions')->updateOrInsert(
            ['user_id' => $userId, 'session_hash' => $hash],
            [
                'public_key' => base64_encode($pair['public']),
                'fingerprint' => KeyFile::fingerprint($pair['public']),
                'expires_at' => $expires,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $row = DB::table('registry_access_sessions')->where('user_id', $userId)->where('session_hash', $hash)->first();

        return [
            'id' => (int) $row->id,
            'fingerprint' => $row->fingerprint,
            'public' => $pair['public'],
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /** @return array{public: string, secret: string}|null */
    public function secret(Request $request): ?array
    {
        $blob = Cache::get(self::cacheKey(self::sessionHash($request)));

        if (! is_string($blob)) {
            return null;
        }

        $data = json_decode(Crypt::decryptString($blob), true);
        $public = base64_decode((string) ($data['public'] ?? ''), true);
        $secret = base64_decode((string) ($data['secret'] ?? ''), true);

        return $public === false || $secret === false ? null : ['public' => $public, 'secret' => $secret];
    }

    public function forget(Request $request): void
    {
        Cache::forget(self::cacheKey(self::sessionHash($request)));
    }

    public static function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    private function ttl(): int
    {
        return max(300, (int) config('registry_encryption.operator_session_ttl'));
    }

    private static function cacheKey(string $hash): string
    {
        return 'registry-ops:'.$hash;
    }
}
