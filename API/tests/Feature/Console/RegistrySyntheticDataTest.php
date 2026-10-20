<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RegistrySyntheticData;
use App\Enums\UserStatus;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantQuestionnaireDefinitionService;
use App\Services\Registry\SyntheticMigrantRegistryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrySyntheticDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_complete_questionnaires_that_are_clearly_marked_synthetic(): void
    {
        $this->artisan('registry:synthetic', ['--count' => 40, '--seed' => 7, '--force' => true])->assertSuccessful();

        $creator = User::query()->where('email', RegistrySyntheticData::CREATOR_EMAIL)->sole();
        $this->assertSame(UserStatus::Suspended, $creator->status);

        $entries = MigrantRegistryEntry::query()->get();
        $this->assertCount(40, $entries);

        $definitions = app(MigrantQuestionnaireDefinitionService::class);

        foreach ($entries as $entry) {
            $payload = $entry->payload_json;

            $this->assertSame($creator->id, $entry->created_by);
            $this->assertSame('registry:synthetic', $payload['synthetic']['generator']);
            $this->assertSame('Sintético', $payload['firstLastName']);
            $this->assertMatchesRegularExpression('/^Prueba-\d{4}$/', $payload['secondLastName']);
            $this->assertSame('0000000000', $payload['phone']);

            // Some branches end before "Observaciones finales"; when reached, it carries the marker.
            if ($payload['notes'] !== '') {
                $this->assertStringStartsWith(SyntheticMigrantRegistryGenerator::MARKER, $payload['notes']);
            }

            // Re-validating as a complete submission must not throw.
            $definitions->normalizePayload($payload, true);
            $this->assertNotEmpty($payload['questionnaire']['answers']);
        }

        $this->assertGreaterThan(0, $entries->where('current_status', 'approved')->count());
    }

    public function test_the_same_seed_produces_the_same_data_set(): void
    {
        $this->artisan('registry:synthetic', ['--count' => 5, '--seed' => 42, '--force' => true])->assertSuccessful();
        $first = MigrantRegistryEntry::query()->orderBy('id')->pluck('payload_json')->map(fn (array $p): array => $p['questionnaire']['answers'])->all();

        $this->artisan('registry:synthetic', ['--purge' => true, '--force' => true])->assertSuccessful();
        $this->artisan('registry:synthetic', ['--count' => 5, '--seed' => 42, '--force' => true])->assertSuccessful();
        $second = MigrantRegistryEntry::query()->orderBy('id')->pluck('payload_json')->map(fn (array $p): array => $p['questionnaire']['answers'])->all();

        $this->assertEquals(
            array_map(fn (array $answers): array => array_diff_key($answers, ['rdd8f1a5c8a8c4ec4ae4dbc15dc582676' => true]), $first),
            array_map(fn (array $answers): array => array_diff_key($answers, ['rdd8f1a5c8a8c4ec4ae4dbc15dc582676' => true]), $second),
        );
    }

    public function test_purge_removes_only_synthetic_registrations(): void
    {
        $real = MigrantRegistryEntry::query()->create([
            'created_by' => User::factory()->create()->id,
            'created_by_role' => 'volunteer',
            'current_status' => 'approved',
            'payload_json' => ['schemaVersion' => 2, 'questionnaire' => ['definitionId' => 'migrant-intake-v2', 'answers' => []]],
        ]);

        $this->artisan('registry:synthetic', ['--count' => 10, '--force' => true])->assertSuccessful();
        $this->assertSame(11, MigrantRegistryEntry::query()->count());

        $this->artisan('registry:synthetic', ['--purge' => true, '--force' => true])->assertSuccessful();

        $this->assertSame([$real->id], MigrantRegistryEntry::query()->withTrashed()->pluck('id')->all());
        $this->assertDatabaseMissing('users', ['email' => RegistrySyntheticData::CREATOR_EMAIL]);
        $this->assertDatabaseMissing('migrant_registry_status_history', ['changed_by_role' => 'volunteer', 'reason' => SyntheticMigrantRegistryGenerator::MARKER.': generado por registry:synthetic']);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('registry:synthetic', ['--count' => 5, '--force' => true])->assertFailed();
        $this->artisan('registry:synthetic', ['--purge' => true, '--force' => true])->assertFailed();

        $this->app['env'] = 'testing';
        $this->assertSame(0, MigrantRegistryEntry::query()->count());
    }
}
