<?php

namespace Tests\Feature\Api\Registry;

use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\KekProvider;
use App\Services\Security\Encryption\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EncryptedPayloadStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $publicKeys = [];
        $secrets = [];

        foreach (['cand', 'proc'] as $domain) {
            $pair = sodium_crypto_box_keypair();
            $publicKeys[$domain] = ['id' => "pk:{$domain}-1", 'public' => base64_encode(sodium_crypto_box_publickey($pair))];
            $secrets["pk:{$domain}-1"] = sodium_crypto_box_secretkey($pair);
        }

        config([
            'registry_encryption.enabled' => true,
            'registry_encryption.write_plaintext' => false,
            'registry_encryption.public_keys' => $publicKeys,
        ]);
        app()->forgetInstance(KekProvider::class);
        app()->forgetInstance(EnvelopeCipher::class);

        // Stand-in for a coordinator who unlocked both domains in this session.
        foreach ($secrets as $kekId => $secret) {
            app(KeyRing::class)->unlock($kekId, $secret);
        }
    }

    private function entry(array $payload): MigrantRegistryEntry
    {
        $user = User::factory()->create();

        return MigrantRegistryEntry::create([
            'created_by' => $user->id,
            'created_by_role' => 'volunteer',
            'current_status' => 'pending_review',
            'payload_json' => $payload,
        ]);
    }

    public function test_raw_database_row_contains_no_plaintext_but_model_reads_it_back(): void
    {
        $entry = $this->entry(['fullName' => 'María Peña']);

        $raw = DB::table('migrant_registry_entries')->where('id', $entry->id)->first();
        $this->assertStringNotContainsString('María', (string) $raw->payload_json);
        $this->assertStringNotContainsString('María', (string) $raw->payload_json_enc);
        $this->assertSame('pk:cand-1', json_decode((string) $raw->payload_json_enc, true)['kek']);
        $this->assertSame('María Peña', MigrantRegistryEntry::findOrFail($entry->id)->payload_json['fullName']);
    }

    public function test_update_reseals_and_hides_envelope_columns_from_serialization(): void
    {
        $entry = $this->entry(['fullName' => 'Before']);
        $entry->forceFill(['payload_json' => ['fullName' => 'After']])->save();

        $fresh = MigrantRegistryEntry::findOrFail($entry->id);
        $this->assertSame('After', $fresh->payload_json['fullName']);
        $this->assertArrayNotHasKey('payload_json_enc', $fresh->toArray());
    }

    public function test_envelope_moved_to_another_record_fails_closed(): void
    {
        $a = $this->entry(['fullName' => 'Alpha']);
        $b = $this->entry(['fullName' => 'Bravo']);

        $envelopeA = DB::table('migrant_registry_entries')->where('id', $a->id)->value('payload_json_enc');
        DB::table('migrant_registry_entries')->where('id', $b->id)->update(['payload_json_enc' => $envelopeA]);

        $this->expectException(EncryptionException::class);
        MigrantRegistryEntry::findOrFail($b->id)->payload_json;
    }

    public function test_reading_without_unlocked_keys_fails_closed(): void
    {
        $entry = $this->entry(['fullName' => 'Locked']);
        app(KeyRing::class)->lock();

        $this->expectException(EncryptionException::class);
        MigrantRegistryEntry::findOrFail($entry->id)->payload_json;
    }

    public function test_legacy_plaintext_rows_remain_readable_during_dual_read(): void
    {
        config(['registry_encryption.enabled' => false]);
        $entry = $this->entry(['fullName' => 'Legacy']);

        $this->assertNull(DB::table('migrant_registry_entries')->where('id', $entry->id)->value('payload_json_enc'));
        $this->assertSame('Legacy', MigrantRegistryEntry::findOrFail($entry->id)->payload_json['fullName']);
    }
}
