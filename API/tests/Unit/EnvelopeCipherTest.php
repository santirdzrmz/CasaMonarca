<?php

namespace Tests\Unit;

use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\FileKekProvider;
use PHPUnit\Framework\TestCase;

class EnvelopeCipherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kek-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
        $this->writeKey('kek-a');
        $this->writeKey('kek-b');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function writeKey(string $id): void
    {
        $path = $this->directory.'/'.$id.'.key';
        file_put_contents($path, FileKekProvider::generateKeyMaterial());
        chmod($path, 0600);
    }

    private function cipher(string $active = 'kek-a', string $env = 'testing'): EnvelopeCipher
    {
        return new EnvelopeCipher(new FileKekProvider($this->directory, $active), $env);
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
        $this->cipher('kek-a', 'production')->decrypt($envelope, 't', 1, 'p');
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

    public function test_old_envelopes_stay_readable_after_active_kek_changes(): void
    {
        $envelope = $this->cipher('kek-a')->encrypt(['x' => 1], 't', 1, 'p');

        $this->assertSame(['x' => 1], $this->cipher('kek-b')->decrypt($envelope, 't', 1, 'p'));
        $this->assertSame('kek-b', json_decode($this->cipher('kek-b')->encrypt([], 't', 1, 'p'), true)['kek']);
    }

    public function test_missing_key_never_falls_back_to_plaintext(): void
    {
        $envelope = $this->cipher()->encrypt(['x' => 1], 't', 1, 'p');
        unlink($this->directory.'/kek-a.key');

        $this->expectException(EncryptionException::class);
        $this->cipher()->decrypt($envelope, 't', 1, 'p');
    }
}
