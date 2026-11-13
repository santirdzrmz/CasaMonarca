<?php

namespace Tests\Unit;

use App\Services\Security\Encryption\AccessGrantCrypto;
use App\Services\Security\Encryption\DekRing;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\KekDomain;
use App\Services\Security\Encryption\KeyFile;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\KeysLockedException;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use PHPUnit\Framework\TestCase;

class AccessGrantCryptoTest extends TestCase
{
    private const TYPE = 'migrant_registry_entries';

    private const PURPOSE = 'registration_payload';

    /** @var array<string, array{id: string, public: string}> */
    private array $keys;

    private string $candSecret;

    protected function setUp(): void
    {
        $cand = sodium_crypto_box_keypair();
        $proc = sodium_crypto_box_keypair();
        $this->candSecret = sodium_crypto_box_secretkey($cand);
        $this->keys = [
            'cand' => ['id' => 'pk:cand-1', 'public' => sodium_crypto_box_publickey($cand)],
            'proc' => ['id' => 'pk:proc-1', 'public' => sodium_crypto_box_publickey($proc)],
        ];
    }

    private function cipher(?KeyRing $ring = null, ?DekRing $deks = null): EnvelopeCipher
    {
        return new EnvelopeCipher(new PublicKeyKekProvider($this->keys, $ring ?? new KeyRing), 'testing', $deks);
    }

    private function sealed(int $id, array $payload): string
    {
        return KekDomain::using('cand', fn () => $this->cipher()->encrypt($payload, self::TYPE, $id, self::PURPOSE));
    }

    /** @return array{0: string, 1: string} session public and secret key */
    private function sessionKeys(): array
    {
        $pair = sodium_crypto_box_keypair();

        return [sodium_crypto_box_publickey($pair), sodium_crypto_box_secretkey($pair)];
    }

    private function coordinatorCipher(): EnvelopeCipher
    {
        $ring = new KeyRing;
        $ring->unlock('pk:cand-1', $this->candSecret);

        return $this->cipher($ring);
    }

    public function test_a_granted_dek_lets_an_operator_read_only_that_record(): void
    {
        $payload = ['fullName' => 'María José'];
        $envelope = $this->sealed(5, $payload);
        [$sessionPublic, $sessionSecret] = $this->sessionKeys();

        $coordinator = $this->coordinatorCipher();
        $dek = $coordinator->openDek($envelope, self::TYPE, 5, self::PURPOSE);
        $wrapped = AccessGrantCrypto::wrapDek($dek, $coordinator->aadFor(self::TYPE, 5, self::PURPOSE), $sessionPublic);

        $deks = new DekRing;
        $operator = $this->cipher(null, $deks);
        $deks->grant(self::TYPE, 5, self::PURPOSE, AccessGrantCrypto::unwrapDek($wrapped, $operator->aadFor(self::TYPE, 5, self::PURPOSE), $sessionPublic, $sessionSecret));

        $this->assertSame($payload, $operator->decrypt($envelope, self::TYPE, 5, self::PURPOSE));
        $this->assertSame([['type' => self::TYPE, 'id' => '5']], $deks->usedResources());

        $this->expectException(KeysLockedException::class);
        $operator->decrypt($this->sealed(6, ['x' => 1]), self::TYPE, 6, self::PURPOSE);
    }

    public function test_the_wrapped_dek_does_not_open_for_another_record_or_another_session(): void
    {
        $envelope = $this->sealed(5, ['a' => 1]);
        [$sessionPublic, $sessionSecret] = $this->sessionKeys();
        $coordinator = $this->coordinatorCipher();
        $dek = $coordinator->openDek($envelope, self::TYPE, 5, self::PURPOSE);
        $wrapped = AccessGrantCrypto::wrapDek($dek, $coordinator->aadFor(self::TYPE, 5, self::PURPOSE), $sessionPublic);

        $this->assertNull(AccessGrantCrypto::unwrapDek($wrapped, $coordinator->aadFor(self::TYPE, 6, self::PURPOSE), $sessionPublic, $sessionSecret));
        $this->assertNull(AccessGrantCrypto::unwrapDek($wrapped, $coordinator->aadFor(self::TYPE, 5, self::PURPOSE), $sessionPublic, $this->sessionKeys()[1]));
    }

    public function test_the_grant_signature_binds_record_operator_session_expiry_and_keys(): void
    {
        $file = KeyFile::create('contraseña-coordinadora-1');
        $secrets = KeyFile::open($file['file'], 'contraseña-coordinadora-1');
        [$sessionPublic] = $this->sessionKeys();
        $grant = [
            'id' => 9, 'resource_type' => 'registry', 'resource_id' => 5, 'user_id' => 77, 'sessionPublic' => $sessionPublic,
            'expires_at' => '2026-11-10 18:00:00', 'signed_by' => 2, 'wrapped_deks' => '{"registration_payload":"AAAA"}',
        ];
        $signature = AccessGrantCrypto::sign(AccessGrantCrypto::payload($grant), $secrets['sign']);

        $this->assertTrue(AccessGrantCrypto::verify(AccessGrantCrypto::payload($grant), $signature, $file['signing_public']));

        foreach (['expires_at' => '2099-01-01 00:00:00', 'resource_id' => 6, 'user_id' => 78, 'wrapped_deks' => '{}', 'sessionPublic' => $this->sessionKeys()[0]] as $field => $value) {
            $this->assertFalse(AccessGrantCrypto::verify(AccessGrantCrypto::payload([$field => $value] + $grant), $signature, $file['signing_public']), $field);
        }

        $other = KeyFile::create('otra-persona-1234');
        $this->assertFalse(AccessGrantCrypto::verify(AccessGrantCrypto::payload($grant), $signature, $other['signing_public']));
    }

    public function test_clearing_the_dek_ring_removes_the_access_and_reads_do_not_wipe_it(): void
    {
        $envelope = $this->sealed(5, ['a' => 1]);
        $dek = $this->coordinatorCipher()->openDek($envelope, self::TYPE, 5, self::PURPOSE);
        $deks = new DekRing;
        $operator = $this->cipher(null, $deks);
        $deks->grant(self::TYPE, 5, self::PURPOSE, $dek);

        $this->assertSame(['a' => 1], $operator->decrypt($envelope, self::TYPE, 5, self::PURPOSE));
        $this->assertSame(['a' => 1], $operator->decrypt($envelope, self::TYPE, 5, self::PURPOSE));

        $deks->clear();
        $this->assertTrue($deks->isEmpty());

        $this->expectException(KeysLockedException::class);
        $operator->decrypt($envelope, self::TYPE, 5, self::PURPOSE);
    }

    public function test_rewrap_moves_the_dek_to_a_new_key_without_touching_the_ciphertext(): void
    {
        $envelope = $this->sealed(5, ['a' => 1]);
        $newPair = sodium_crypto_box_keypair();
        $newKeys = $this->keys;
        $newKeys['cand'] = ['id' => 'pk:cand-2', 'public' => sodium_crypto_box_publickey($newPair)];

        $ring = new KeyRing;
        $ring->unlock('pk:cand-1', $this->candSecret);
        $ring->unlock('pk:cand-2', sodium_crypto_box_secretkey($newPair));
        $rotator = new EnvelopeCipher(new PublicKeyKekProvider($newKeys, $ring), 'testing');

        $rewrapped = KekDomain::using('cand', fn () => $rotator->rewrap($envelope, self::TYPE, 5, self::PURPOSE));

        $this->assertSame('pk:cand-2', json_decode($rewrapped, true)['kek']);
        $this->assertSame(json_decode($envelope, true)['ct'], json_decode($rewrapped, true)['ct']);
        $this->assertSame(['a' => 1], $rotator->decrypt($rewrapped, self::TYPE, 5, self::PURPOSE));

        $oldOnly = new KeyRing;
        $oldOnly->unlock('pk:cand-1', $this->candSecret);
        $this->expectException(KeysLockedException::class);
        (new EnvelopeCipher(new PublicKeyKekProvider($newKeys, $oldOnly), 'testing'))->decrypt($rewrapped, self::TYPE, 5, self::PURPOSE);
    }

    public function test_a_malformed_envelope_is_still_rejected_as_before(): void
    {
        $this->expectException(EncryptionException::class);
        $this->cipher()->decrypt('not json', self::TYPE, 5, self::PURPOSE);
    }
}
