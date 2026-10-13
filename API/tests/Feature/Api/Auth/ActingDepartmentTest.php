<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\Department;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserDepartment;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActingDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'X-Acting-Department';

    protected function setUp(): void
    {
        parent::setUp();

        config(['departments.enforced' => true]);
    }

    public function test_change_without_declared_department_is_rejected_and_audited(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);

        $this->actingAs($admin)
            ->postJson('/admin/invites', $this->invitePayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'acting_department_required')
            ->assertJsonPath('error.assignedDepartments', ['information_technology']);

        $this->assertDatabaseCount('account_invites', 0);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => AuditEventType::AuthActingDepartmentDenied->value,
            'outcome' => AuditEventOutcome::Denied->value,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_unknown_department_is_rejected(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);

        $this->actingAs($admin)
            ->withHeader(self::HEADER, 'finance')
            ->postJson('/admin/invites', $this->invitePayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'acting_department_invalid');
    }

    public function test_department_not_assigned_to_actor_is_rejected(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::Legal->value)
            ->postJson('/admin/invites', $this->invitePayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'acting_department_not_assigned');

        $this->assertDatabaseCount('account_invites', 0);
    }

    public function test_declared_department_is_recorded_on_the_audit_event(): void
    {
        $admin = $this->createEnrolledAdmin([Department::Legal, Department::InformationTechnology]);

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::Legal->value)
            ->postJson('/admin/invites', $this->invitePayload())
            ->assertCreated();

        $event = AuditEvent::query()->where('event_type', AuditEventType::AccountInviteCreated->value)->sole();
        $this->assertSame(Department::Legal->value, $event->acting_department);
    }

    public function test_read_requests_do_not_require_a_department(): void
    {
        $admin = $this->createEnrolledAdmin([]);

        $this->actingAs($admin)
            ->getJson('/admin/invites')
            ->assertOk();
    }

    public function test_signature_must_use_the_department_declared_when_the_challenge_was_issued(): void
    {
        $admin = $this->createEnrolledAdmin([Department::Legal, Department::InformationTechnology]);
        $target = User::factory()->create();
        $flow = "admin/users/{$target->id}/status";

        $this->actingAs($admin)
            ->withSession(['acting_department_challenges' => [$flow => Department::InformationTechnology->value]])
            ->withHeader(self::HEADER, Department::Legal->value)
            ->postJson("/{$flow}/verify", [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'acting_department_mismatch');

        $this->assertDatabaseHas('audit_events', [
            'event_type' => AuditEventType::AuthActingDepartmentDenied->value,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_assign_departments_and_the_change_is_audited(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);
        $volunteer = User::factory()->create(['role' => UserRole::Volunteer->value]);

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::InformationTechnology->value)
            ->putJson("/admin/users/{$volunteer->id}/departments", [
                'departments' => [Department::Warehouse->value, Department::Humanitarian->value],
            ])
            ->assertOk()
            ->assertJsonPath('user.departments', ['humanitarian', 'warehouse']);

        $this->assertSame(['humanitarian', 'warehouse'], $volunteer->departmentValues());
        $this->assertDatabaseHas('user_departments', [
            'user_id' => $volunteer->id,
            'department' => Department::Warehouse->value,
            'assigned_by_user_id' => $admin->id,
        ]);

        $event = AuditEvent::query()->where('event_type', AuditEventType::AdminUserDepartmentsChanged->value)->sole();
        $this->assertSame(Department::InformationTechnology->value, $event->acting_department);
        $this->assertSame(['humanitarian', 'warehouse'], $event->metadata['added']);
        $this->assertSame([], $event->metadata['previousDepartments']);
    }

    public function test_admin_cannot_remove_all_of_their_own_departments(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::InformationTechnology->value)
            ->putJson("/admin/users/{$admin->id}/departments", ['departments' => []])
            ->assertStatus(422);

        $this->assertSame(['information_technology'], $admin->departmentValues());
    }

    public function test_assignment_rejects_unknown_departments(): void
    {
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::InformationTechnology->value)
            ->putJson("/admin/users/{$user->id}/departments", ['departments' => ['finance']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('departments.0');
    }

    public function test_rollout_mode_allows_missing_header_but_still_rejects_false_claims(): void
    {
        config(['departments.enforced' => false]);
        $admin = $this->createEnrolledAdmin([Department::InformationTechnology]);

        $this->actingAs($admin)
            ->postJson('/admin/invites', $this->invitePayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->withHeader(self::HEADER, Department::Legal->value)
            ->postJson('/admin/invites', $this->invitePayload('other@casamonarca.local'))
            ->assertForbidden();
    }

    public function test_current_user_payload_lists_assigned_departments(): void
    {
        $admin = $this->createEnrolledAdmin([Department::Psychosocial, Department::Communication]);

        $this->actingAs($admin)
            ->getJson('/me')
            ->assertOk()
            ->assertJsonPath('user.departments', ['communication', 'psychosocial']);
    }

    /**
     * @return array<string, string>
     */
    private function invitePayload(string $email = 'coordinator@casamonarca.local'): array
    {
        return [
            'email' => $email,
            'role' => UserRole::Coordinator->value,
        ];
    }

    /**
     * @param  list<Department>  $departments
     */
    private function createEnrolledAdmin(array $departments): User
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $admin->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Admin key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        foreach ($departments as $department) {
            UserDepartment::query()->create([
                'user_id' => $admin->id,
                'department' => $department->value,
            ]);
        }

        return $admin;
    }
}
