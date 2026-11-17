<?php

namespace Tests\Feature\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every change to registry keys must leave a trace: the public key history keeps old keys, and the audit log
 * (bitácora) gets one event per enrollment, delivery, revocation, domain key generation and rewrap.
 */
class RegistryKeyAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASS_PROMPT = 'Contraseña del archivo de llave (la elige el usuario, mínimo 12 caracteres)';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/registry-keys-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_enrolling_keeps_the_public_key_in_history_and_in_the_audit_log(): void
    {
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);

        $this->enroll($coordinator)->assertSuccessful();

        $key = DB::table('registry_user_keys')->where('user_id', $coordinator->id)->first();
        $this->assertDatabaseHas('registry_user_key_history', [
            'user_id' => $coordinator->id,
            'fingerprint' => $key->fingerprint,
            'public_key' => $key->public_key,
            'retired_at' => null,
        ]);

        $event = $this->lastEvent(AuditEventType::RegistryKeysEnrolled);
        $this->assertSame($coordinator->id, $event->resource_id);
        $this->assertSame($key->fingerprint, $event->metadata['fingerprint']);
        $this->assertSame($key->public_key, $event->metadata['publicKey']);
        $this->assertSame('console', $event->metadata['via']);
    }

    public function test_re_enrolling_requires_replace_and_never_erases_the_previous_key(): void
    {
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $this->enroll($coordinator)->assertSuccessful();
        $first = DB::table('registry_user_keys')->where('user_id', $coordinator->id)->value('fingerprint');
        DB::table('registry_key_grants')->insert(['user_id' => $coordinator->id, 'kek_id' => 'pk:cand-1', 'wrapped' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('registry:keys-enroll', ['email' => $coordinator->email, '--out' => $this->dir.'/again.json'])->assertFailed();
        $this->assertSame($first, DB::table('registry_user_keys')->where('user_id', $coordinator->id)->value('fingerprint'));

        $this->enroll($coordinator, ['--replace' => true])->assertSuccessful();

        $history = DB::table('registry_user_key_history')->where('user_id', $coordinator->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame($first, $history[0]->fingerprint);
        $this->assertSame('replaced', $history[0]->retired_reason);
        $this->assertNull($history[1]->retired_at);
        $this->assertSame(0, DB::table('registry_key_grants')->where('user_id', $coordinator->id)->whereNull('revoked_at')->count());

        $events = AuditEvent::query()->where('event_type', AuditEventType::RegistryKeysEnrolled->value)->get();
        $this->assertCount(2, $events);
        $replacement = $events->first(fn (AuditEvent $event): bool => ($event->metadata['replacedFingerprint'] ?? null) !== null);
        $this->assertSame($first, $replacement->metadata['replacedFingerprint']);
        $this->assertSame(1, $replacement->metadata['revokedGrants']);
    }

    public function test_domain_keys_grants_and_revocations_are_audited(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);

        $this->artisan('registry:keys-init', ['--private-dir' => $this->dir, '--plain' => true])->assertSuccessful();
        $generated = $this->lastEvent(AuditEventType::RegistryDomainKeysGenerated);
        $this->assertSame(['pk:cand-1', 'pk:proc-1'], array_keys($generated->metadata['publicKeys']));
        $this->assertSame('plain', $generated->metadata['privateKeyProtection']);

        $this->enroll($admin)->assertSuccessful();
        $fingerprint = DB::table('registry_user_keys')->where('user_id', $admin->id)->value('fingerprint');

        $this->artisan('registry:keys-grant', [
            'email' => $admin->email, '--private-dir' => $this->dir, '--fingerprint' => $fingerprint,
        ])->assertSuccessful();
        $granted = $this->lastEvent(AuditEventType::RegistryKeysGranted);
        $this->assertSame(['pk:cand-1', 'pk:proc-1'], $granted->metadata['kekIds']);
        $this->assertSame($fingerprint, $granted->metadata['fingerprint']);

        $this->artisan('registry:keys-revoke', ['email' => $admin->email])->assertSuccessful();
        $revoked = $this->lastEvent(AuditEventType::RegistryKeysRevoked);
        $this->assertSame(2, $revoked->metadata['revokedGrants']);
        $this->assertDatabaseHas('registry_user_key_history', ['user_id' => $admin->id, 'retired_reason' => 'revoked']);
    }

    /** @param array<string, mixed> $options */
    private function enroll(User $user, array $options = []): mixed
    {
        return $this->artisan('registry:keys-enroll', ['email' => $user->email, '--out' => $this->dir.'/'.$user->id.'-'.uniqid().'.json', ...$options])
            ->expectsQuestion(self::PASS_PROMPT, 'una-frase-larga-de-prueba')
            ->expectsQuestion('Repite la contraseña', 'una-frase-larga-de-prueba');
    }

    private function lastEvent(AuditEventType $type): AuditEvent
    {
        // Audit ids are UUIDs, so "last" is by time; each test triggers one event of the type it reads here.
        $event = AuditEvent::query()->where('event_type', $type->value)->latest('occurred_at')->first();
        $this->assertNotNull($event, "No audit event {$type->value}");

        return $event;
    }
}
