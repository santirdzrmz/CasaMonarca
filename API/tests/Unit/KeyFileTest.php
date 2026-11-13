<?php

namespace Tests\Unit;

use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyFile;
use App\Services\Security\Encryption\KeyGrants;
use PHPUnit\Framework\TestCase;

class KeyFileTest extends TestCase
{
    public function test_the_key_file_opens_only_with_the_right_passphrase(): void
    {
        $created = KeyFile::create('una-contraseña-larga-1');
        $secrets = KeyFile::open($created['file'], 'una-contraseña-larga-1');

        $this->assertSame($created['public'], sodium_crypto_box_publickey_from_secretkey($secrets['box']));
        $this->assertSame($created['signing_public'], sodium_crypto_sign_publickey_from_secretkey($secrets['sign']));

        $this->expectException(EncryptionException::class);
        KeyFile::open($created['file'], 'otra-contraseña');
    }

    public function test_a_tampered_key_file_cannot_demand_absurd_memory(): void
    {
        $created = KeyFile::create('una-contraseña-larga-1');
        $tampered = json_decode($created['file'], true);
        $tampered['mem'] = PHP_INT_MAX;

        $this->expectException(EncryptionException::class);
        KeyFile::open(json_encode($tampered), 'una-contraseña-larga-1');
    }

    public function test_fingerprint_has_a_short_readable_format(): void
    {
        $created = KeyFile::create('una-contraseña-larga-1');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){2}$/', $created['fingerprint']);
    }

    public function test_a_domain_key_granted_to_a_user_opens_only_for_that_user(): void
    {
        $domainSecret = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
        $ana = KeyFile::create('contraseña-de-ana-1');
        $luis = KeyFile::create('contraseña-de-luis-1');

        $wrapped = KeyGrants::wrap($domainSecret, $ana['public']);

        $this->assertSame($domainSecret, KeyGrants::unwrap($wrapped, KeyFile::open($ana['file'], 'contraseña-de-ana-1')['box']));
        $this->assertNull(KeyGrants::unwrap($wrapped, KeyFile::open($luis['file'], 'contraseña-de-luis-1')['box']));
    }

    public function test_the_signing_key_in_the_file_signs_and_the_registered_public_key_verifies(): void
    {
        $created = KeyFile::create('una-contraseña-larga-1');
        $secrets = KeyFile::open($created['file'], 'una-contraseña-larga-1');
        $signature = sodium_crypto_sign_detached('grant|1|2', $secrets['sign']);

        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'grant|1|2', $created['signing_public']));
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, 'grant|1|3', $created['signing_public']));
    }

    public function test_private_key_file_names_are_valid_on_windows(): void
    {
        $this->assertSame('pk_cand-1.age', KeyGrants::privateFileName('pk:cand-1', 'age'));
    }
}
