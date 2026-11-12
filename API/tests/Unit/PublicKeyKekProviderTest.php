<?php

namespace Tests\Unit;

use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\FileKekProvider;
use App\Services\Security\Encryption\KekDomain;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\KeysLockedException;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use App\Services\Security\Encryption\RoutingKekProvider;
use PHPUnit\Framework\TestCase;

class PublicKeyKekProviderTest extends TestCase
{
    private const TYPE = 'migrant_registry_entries';

    private const PURPOSE = 'registration_payload';

    private string $cand;

    private string $proc;

    /** @var array<string, array{id: string, public: string}> */
    private array $keys;

    protected function setUp(): void
    {
        $c = sodium_crypto_box_keypair();
        $p = sodium_crypto_box_keypair();
        $this->cand = sodium_crypto_box_secretkey($c);
        $this->proc = sodium_crypto_box_secretkey($p);
        $this->keys = [
            'cand' => ['id' => 'pk:cand-1', 'public' => sodium_crypto_box_publickey($c)],
            'proc' => ['id' => 'pk:proc-1', 'public' => sodium_crypto_box_publickey($p)],
        ];
    }

    private function cipher(KeyRing $ring): EnvelopeCipher
    {
        return new EnvelopeCipher(new PublicKeyKekProvider($this->keys, $ring), 'testing');
    }

    private function seal(string $domain, array $payload = ['fullName' => 'María José']): string
    {
        return KekDomain::using($domain, fn () => $this->cipher(new KeyRing)->encrypt($payload, self::TYPE, 5, self::PURPOSE));
    }

    private function ring(string ...$domains): KeyRing
    {
        $ring = new KeyRing;
        foreach ($domains as $d) {
            $ring->unlock($this->keys[$d]['id'], $d === 'cand' ? $this->cand : $this->proc);
        }

        return $ring;
    }

    public function test_writing_needs_only_public_keys_and_uses_the_domain_key(): void
    {
        $this->assertSame('pk:cand-1', json_decode($this->seal('cand'), true)['kek']);
        $this->assertSame('pk:proc-1', json_decode($this->seal('proc'), true)['kek']);
    }

    public function test_reading_without_the_private_key_fails_closed_as_locked(): void
    {
        $this->expectException(KeysLockedException::class);
        $this->cipher(new KeyRing)->decrypt($this->seal('cand'), self::TYPE, 5, self::PURPOSE);
    }

    public function test_each_domain_key_opens_only_its_own_envelopes(): void
    {
        $payload = ['fullName' => 'María José'];
        $this->assertSame($payload, $this->cipher($this->ring('cand'))->decrypt($this->seal('cand'), self::TYPE, 5, self::PURPOSE));

        $this->expectException(KeysLockedException::class);
        $this->cipher($this->ring('cand'))->decrypt($this->seal('proc'), self::TYPE, 5, self::PURPOSE);
    }

    public function test_resealing_from_candidates_to_processed_requires_both_keys(): void
    {
        $cipher = $this->cipher($this->ring('cand', 'proc'));
        $plain = $cipher->decrypt($this->seal('cand'), self::TYPE, 5, self::PURPOSE);
        $resealed = KekDomain::using('proc', fn () => $cipher->encrypt($plain, self::TYPE, 5, self::PURPOSE));

        $this->assertSame('pk:proc-1', json_decode($resealed, true)['kek']);
        $this->assertSame($plain, $cipher->decrypt($resealed, self::TYPE, 5, self::PURPOSE));
    }

    public function test_an_envelope_moved_to_another_record_does_not_open(): void
    {
        $this->expectException(EncryptionException::class);
        $this->cipher($this->ring('cand'))->decrypt($this->seal('cand'), self::TYPE, 6, self::PURPOSE);
    }

    public function test_a_wrapped_key_copied_from_another_record_does_not_open(): void
    {
        $other = json_decode(KekDomain::using('cand', fn () => $this->cipher(new KeyRing)->encrypt(['x' => 1], self::TYPE, 6, self::PURPOSE)), true);
        $mixed = json_decode($this->seal('cand'), true);
        $mixed['wk'] = $other['wk'];

        $this->expectException(EncryptionException::class);
        $this->cipher($this->ring('cand'))->decrypt(json_encode($mixed), self::TYPE, 5, self::PURPOSE);
    }

    public function test_locking_the_ring_removes_access(): void
    {
        $ring = $this->ring('cand');
        $cipher = $this->cipher($ring);
        $envelope = $this->seal('cand');
        $ring->lock();

        $this->expectException(KeysLockedException::class);
        $cipher->decrypt($envelope, self::TYPE, 5, self::PURPOSE);
    }

    public function test_domain_and_id_helpers(): void
    {
        $this->assertSame('cand', PublicKeyKekProvider::domainOf('pk:cand-12'));
        $this->assertNull(PublicKeyKekProvider::domainOf('registry-2026-10'));
        $this->assertTrue(PublicKeyKekProvider::handles('pk:proc-1'));
        $this->assertFalse(PublicKeyKekProvider::handles('registry-2026-10'));
    }

    public function test_a_key_id_from_another_domain_is_rejected(): void
    {
        $this->expectException(EncryptionException::class);
        new PublicKeyKekProvider(['cand' => ['id' => 'pk:proc-1', 'public' => $this->keys['cand']['public']]], new KeyRing);
    }

    public function test_routing_keeps_legacy_file_envelopes_readable(): void
    {
        $dir = sys_get_temp_dir().'/pk-routing-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/registry-2026-10.key', FileKekProvider::generateKeyMaterial());
        chmod($dir.'/registry-2026-10.key', 0600);

        $file = new FileKekProvider($dir, 'registry-2026-10');
        $legacy = (new EnvelopeCipher($file, 'testing'))->encrypt(['a' => 1], self::TYPE, 5, self::PURPOSE);
        $routing = new EnvelopeCipher(new RoutingKekProvider(new PublicKeyKekProvider($this->keys, new KeyRing), $file), 'testing');

        $this->assertSame(['a' => 1], $routing->decrypt($legacy, self::TYPE, 5, self::PURPOSE));

        unlink($dir.'/registry-2026-10.key');
        rmdir($dir);
    }
}
