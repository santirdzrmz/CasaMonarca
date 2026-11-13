<?php

namespace Tests\Unit;

use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\KeysLockedException;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use PHPUnit\Framework\TestCase;

class EnvelopeCipherTest extends TestCase
{
    /** @var array<string, array{public: string, secret: string}> keypairs by kek id */
    private array $pairs = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pk:cand-1', 'pk:cand-2'] as $id) {
            $pair = sodium_crypto_box_keypair();
            $this->pairs[$id] = ['public' => sodium_crypto_box_publickey($pair), 'secret' => sodium_crypto_box_secretkey($pair)];
        }
    }

    /** A cipher whose active (writing) key is $active and whose session has $unlocked private keys. */
    private function cipher(string $active = 'pk:cand-1', string $env = 'testing', ?array $unlocked = null): EnvelopeCipher
    {
        $ring = new KeyRing;

        foreach ($unlocked ?? array_keys($this->pairs) as $id) {
            $ring->unlock($id, $this->pairs[$id]['secret']);
        }

        return new EnvelopeCipher(
            new PublicKeyKekProvider(['cand' => ['id' => $active, 'public' => $this->pairs[$active]['public']]], $ring),
            $env,
        );
    }

    public function test_round_trip_preserves_unicode_payload(): void
    {
        $payload = ['fullName' => 'María José Peña', 'notes' => ['a', 'b'], 'n' => 3];
        $envelope = $this->cipher()->encrypt($payload, 'migrant_registry_entries', 7, 'registration_payload');

        $this->assertStringNotContainsString('María', $envelope);
        $this->assertSame($payload, $this->cipher()->decrypt($envelope, 'migrant_registry_entries', 7, 'registration_payload'));
    }

    public function test_every_encryption_uses_fresh_dek_and_nonce(): void
    {
        $a = $this->cipher()->encrypt(['x' => 1], 't', 1, 'p');
        $b = $this->cipher()->encrypt(['x' => 1], 't', 1, 'p');

        $this->assertNotSame($a, $b);
    }

    public function test_ciphertext_cannot_be_moved_to_another_record_purpose_table_or_environment(): void
    {
        $envelope = $this->cipher()->encrypt(['x' => 1], 't', 1, 'p');

        foreach ([['t', 2, 'p'], ['t', 1, 'other'], ['u', 1, 'p']] as [$table, $id, $purpose]) {
            try {
                $this->cipher()->decrypt($envelope, $table, $id, $purpose);
                $this->fail('Context swap must fail.');
            } catch (EncryptionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(EncryptionException::class);
        $this->cipher('pk:cand-1', 'production')->decrypt($envelope, 't', 1, 'p');
    }

    public function test_tampering_fails_closed(): void
    {
        $parts = json_decode($this->cipher()->encrypt(['x' => 1], 't', 1, 'p'), true);
        $raw = base64_decode($parts['ct']);
        $raw[15] = $raw[15] ^ "\x01";
        $parts['ct'] = base64_encode($raw);

        $this->expectException(EncryptionException::class);
        $this->cipher()->decrypt(json_encode($parts), 't', 1, 'p');
    }

    public function test_truncated_and_malformed_envelopes_fail_closed(): void
    {
        $parts = json_decode($this->cipher()->encrypt(['x' => 1], 't', 1, 'p'), true);
        $parts['ct'] = base64_encode(substr(base64_decode($parts['ct']), 0, 10));

        foreach ([json_encode($parts), 'not json', '{"v":2}'] as $bad) {
            try {
                $this->cipher()->decrypt($bad, 't', 1, 'p');
                $this->fail('Bad envelope must fail.');
            } catch (EncryptionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_old_envelopes_stay_readable_after_the_active_key_generation_changes(): void
    {
        $envelope = $this->cipher('pk:cand-1')->encrypt(['x' => 1], 't', 1, 'p');

        $this->assertSame(['x' => 1], $this->cipher('pk:cand-2')->decrypt($envelope, 't', 1, 'p'));
        $this->assertSame('pk:cand-2', json_decode($this->cipher('pk:cand-2')->encrypt([], 't', 1, 'p'), true)['kek']);
    }

    public function test_missing_private_key_never_falls_back_to_plaintext(): void
    {
        $envelope = $this->cipher()->encrypt(['x' => 1], 't', 1, 'p');

        $this->expectException(KeysLockedException::class);
        $this->cipher(unlocked: [])->decrypt($envelope, 't', 1, 'p');
    }
}
