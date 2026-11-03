<?php

namespace Tests\Feature\Api\Registry;

use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\FileKekProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EncryptedPayloadStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kek-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
        $path = $this->directory.'/test-kek.key';
        file_put_contents($path, FileKekProvider::generateKeyMaterial());
        chmod($path, 0600);

        config([
            'registry_encryption.enabled' => true,
            'registry_encryption.write_plaintext' => false,
            'registry_encryption.key_directory' => $this->directory,
            'registry_encryption.active_kek_id' => 'test-kek',
        ]);
        app()->forgetInstance(\App\Services\Security\Encryption\KekProvider::class);
        app()->forgetInstance(\App\Services\Security\Encryption\EnvelopeCipher::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
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

    public function test_legacy_plaintext_rows_remain_readable_during_dual_read(): void
    {
        config(['registry_encryption.enabled' => false]);
        $entry = $this->entry(['fullName' => 'Legacy']);

        $this->assertNull(DB::table('migrant_registry_entries')->where('id', $entry->id)->value('payload_json_enc'));
        $this->assertSame('Legacy', MigrantRegistryEntry::findOrFail($entry->id)->payload_json['fullName']);
    }
}
