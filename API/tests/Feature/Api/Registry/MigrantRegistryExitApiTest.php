<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrantRegistryExitApiTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENTION = 'ra09736a581f046c8aea75da8c905a1b9';

    public function test_coordinators_record_an_exit_with_date_and_reason(): void
    {
        $coordinator = $this->enrolled(UserRole::Coordinator);
        $entry = $this->entry('2026-03-01');

        $this->actingAs($coordinator)
            ->postJson($this->url($entry), ['exited_at' => '2026-03-15', 'reason' => 'Continuó su viaje a Estados Unidos.'])
            ->assertOk()
            ->assertJsonPath('data.exited_at', '2026-03-15')
            ->assertJsonPath('data.exit_reason', 'Continuó su viaje a Estados Unidos.')
            ->assertJsonPath('data.exited_by.name', $coordinator->name);

        // The reason is encrypted at rest and the audit trail keeps only the date.
        $stored = DB::table('migrant_registry_entries')->where('id', $entry->id)->value('exit_reason');
        $this->assertStringNotContainsString('Continuó', (string) $stored);
        $this->assertDatabaseHas('audit_events', [
            'resource_id' => $entry->id,
            'event_type' => AuditEventType::MigrantRegistryExitRecorded->value,
        ]);
        $this->assertStringNotContainsString(
            'Continuó',
            (string) DB::table('audit_events')->where('resource_id', $entry->id)->value('metadata'),
        );
    }

    public function test_the_reason_is_required_and_the_date_must_be_valid(): void
    {
        $admin = $this->enrolled(UserRole::Admin);
        $entry = $this->entry('2026-03-01');

        $this->actingAs($admin)->postJson($this->url($entry), ['exited_at' => '2026-03-15'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson($this->url($entry), ['exited_at' => now()->addDay()->toDateString(), 'reason' => 'Salió del albergue.'])
            ->assertStatus(422)->assertJsonValidationErrors('exited_at');
        $this->actingAs($admin)->postJson($this->url($entry), ['exited_at' => '2026-02-20', 'reason' => 'Salió del albergue.'])
            ->assertStatus(422)->assertJsonValidationErrors('exited_at');

        $this->assertNull($entry->fresh()->exited_at);
    }

    public function test_only_approved_registrations_without_an_exit_can_record_one(): void
    {
        $admin = $this->enrolled(UserRole::Admin);
        $body = ['exited_at' => '2026-03-15', 'reason' => 'Salió del albergue.'];

        $this->actingAs($admin)->postJson($this->url($this->entry('2026-03-01', 'pending_review')), $body)->assertStatus(409);

        $exited = $this->entry('2026-03-01');
        $exited->forceFill(['exited_at' => '2026-03-10'])->save();
        $this->actingAs($admin)->postJson($this->url($exited), $body)->assertStatus(409);
    }

    public function test_an_exit_can_be_undone(): void
    {
        $admin = $this->enrolled(UserRole::Admin);
        $entry = $this->entry('2026-03-01');
        $entry->forceFill(['exited_at' => '2026-03-10', 'exit_reason' => 'Error de captura', 'exited_by' => $admin->id])->save();

        $this->actingAs($admin)->deleteJson($this->url($entry))
            ->assertOk()
            ->assertJsonPath('data.exited_at', null);

        $this->assertNull($entry->fresh()->exit_reason);
        $this->assertDatabaseHas('audit_events', [
            'resource_id' => $entry->id,
            'event_type' => AuditEventType::MigrantRegistryExitUndone->value,
        ]);
        $this->actingAs($admin)->deleteJson($this->url($entry))->assertStatus(409);
    }

    public function test_other_roles_cannot_record_exits_or_read_the_reason(): void
    {
        $entry = $this->entry('2026-03-01');
        $entry->forceFill(['exited_at' => '2026-03-10', 'exit_reason' => 'Motivo privado'])->save();

        foreach ([UserRole::NonCoordinator, UserRole::Volunteer] as $role) {
            $user = $this->enrolled($role);
            $this->actingAs($user)->postJson($this->url($entry), ['exited_at' => '2026-03-15', 'reason' => 'Salió del albergue.'])->assertForbidden();
            $this->actingAs($user)->deleteJson($this->url($entry))->assertForbidden();
        }

        $listed = collect($this->actingAs($this->enrolled(UserRole::NonCoordinator))->getJson('/registry/migrants')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);
        $this->assertSame('2026-03-10', $listed['exited_at']);
        $this->assertArrayNotHasKey('exit_reason', $listed);

        $listed = collect($this->actingAs($this->enrolled(UserRole::Coordinator))->getJson('/registry/migrants')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);
        $this->assertSame('Motivo privado', $listed['exit_reason']);
    }

    private function url(MigrantRegistryEntry $entry): string
    {
        return "/registry/migrants/{$entry->id}/exit";
    }

    private function entry(string $attention, string $status = 'approved'): MigrantRegistryEntry
    {
        return MigrantRegistryEntry::query()->create([
            'created_by' => User::factory()->create()->id,
            'created_by_role' => 'volunteer',
            'current_status' => $status,
            'payload_json' => ['schemaVersion' => 2, 'questionnaire' => ['definitionId' => 'test', 'answers' => [
                self::ATTENTION => ['value' => $attention],
            ]]],
        ]);
    }

    private function enrolled(UserRole $role): User
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $user->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        return $user;
    }
}
