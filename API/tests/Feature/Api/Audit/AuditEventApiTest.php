<?php

namespace Tests\Feature\Api\Audit;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_recent_audit_events(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC'),
            'actor_user_id' => $admin->id,
            'actor_role' => $admin->role->value,
            'event_type' => AuditEventType::AuthLogout->value,
            'resource_type' => 'session',
            'outcome' => AuditEventOutcome::Success->value,
            'metadata' => [
                'method' => 'password',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=10')
            ->assertOk()
            ->assertJson([
                'message' => 'Audit events loaded successfully.',
                'events' => [
                    [
                        'actor' => [
                            'email' => $admin->email,
                            'role' => UserRole::Admin->value,
                            'userId' => $admin->id,
                        ],
                        'eventType' => AuditEventType::AuthLogout->value,
                        'metadata' => [
                            'method' => 'password',
                        ],
                        'outcome' => AuditEventOutcome::Success->value,
                        'resource' => [
                            'type' => 'session',
                        ],
                    ],
                ],
            ]);
    }

    public function test_admin_can_filter_registry_key_and_migrant_events_by_category(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);

        foreach ([AuditEventType::RegistryKeysEnrolled, AuditEventType::MigrantRegistryExitRecorded, AuditEventType::AuthLogout] as $type) {
            AuditEvent::query()->create([
                'occurred_at' => now('UTC'),
                'event_type' => $type->value,
                'outcome' => AuditEventOutcome::Success->value,
            ]);
        }

        foreach (['registry' => AuditEventType::RegistryKeysEnrolled, 'migrant' => AuditEventType::MigrantRegistryExitRecorded] as $category => $type) {
            $events = $this->actingAs($admin)->getJson("/audit-events?category={$category}")->assertOk()->json('events');
            $this->assertSame([$type->value], array_column($events, 'eventType'));
        }
    }

    public function test_admin_can_page_search_and_filter_audit_events(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Local',
            'role' => UserRole::Admin->value,
        ]);

        $coordinator = User::factory()->create([
            'name' => 'Coordinator Local',
            'role' => UserRole::Coordinator->value,
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC')->subMinutes(3),
            'actor_user_id' => $admin->id,
            'actor_role' => $admin->role->value,
            'event_type' => AuditEventType::DocumentCreated->value,
            'resource_type' => 'document',
            'outcome' => AuditEventOutcome::Success->value,
            'ip_address' => '198.51.100.10',
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC')->subMinutes(2),
            'actor_user_id' => $coordinator->id,
            'actor_role' => $coordinator->role->value,
            'event_type' => AuditEventType::AuthAuthorizationDenied->value,
            'resource_type' => 'document',
            'outcome' => AuditEventOutcome::Denied->value,
            'ip_address' => '203.0.113.20',
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC')->subMinute(),
            'actor_user_id' => $admin->id,
            'actor_role' => $admin->role->value,
            'event_type' => AuditEventType::AdminUserRoleChanged->value,
            'resource_type' => 'user',
            'outcome' => AuditEventOutcome::Success->value,
            'ip_address' => '198.51.100.30',
        ]);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=1&page=2&category=auth&outcome=denied&q=Coordinator')
            ->assertOk()
            ->assertJson([
                'pagination' => [
                    'hasNextPage' => false,
                    'hasPreviousPage' => true,
                    'limit' => 1,
                    'page' => 2,
                    'total' => 1,
                    'totalPages' => 1,
                ],
                'events' => [],
            ]);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=10&page=1&category=auth&outcome=denied&q=Coordinator')
            ->assertOk()
            ->assertJson([
                'pagination' => [
                    'hasNextPage' => false,
                    'hasPreviousPage' => false,
                    'limit' => 10,
                    'page' => 1,
                    'total' => 1,
                    'totalPages' => 1,
                ],
                'events' => [
                    [
                        'actor' => [
                            'name' => 'Coordinator Local',
                        ],
                        'eventType' => AuditEventType::AuthAuthorizationDenied->value,
                        'outcome' => AuditEventOutcome::Denied->value,
                    ],
                ],
            ]);
    }

    public function test_admin_can_search_audit_events_by_metadata_and_resource_ids(): void
    {
        // The search also matches the actor's name and email. Faker values often contain
        // digits (e.g. "kschulist7@example.net"), which made `q=7` match both events.
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'name' => 'Audit Search Admin',
            'email' => 'audit-search-admin@example.test',
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC')->subMinute(),
            'actor_user_id' => $admin->id,
            'actor_role' => $admin->role->value,
            'event_type' => AuditEventType::AdminUserRoleChanged->value,
            'resource_type' => 'user',
            'resource_id' => 42,
            'outcome' => AuditEventOutcome::Success->value,
            'metadata' => [
                'targetUserName' => 'Coordinator Candidate',
                'targetUserEmail' => 'candidate@casamonarca.local',
            ],
        ]);

        AuditEvent::query()->create([
            'occurred_at' => now('UTC'),
            'actor_user_id' => $admin->id,
            'actor_role' => $admin->role->value,
            'event_type' => AuditEventType::DocumentDownloaded->value,
            'resource_type' => 'document',
            'resource_id' => 7,
            'document_id' => 7,
            'outcome' => AuditEventOutcome::Success->value,
            'metadata' => [
                'originalFileName' => 'incident-report.pdf',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=10&q=Candidate')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('events.0.resource.id', 42);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=10&q=incident-report')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('events.0.resource.documentId', 7);

        $this->actingAs($admin)
            ->getJson('/audit-events?limit=10&q=7')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('events.0.resource.documentId', 7);
    }
}
