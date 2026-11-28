<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MigrantRegistryService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DELETED = 'deleted_by_admin';

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $request,
    ) {}

    public function create(User $user, array $payloadJson): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($user, $payloadJson) {
            $entry = MigrantRegistryEntry::create([
                'created_by' => $user->id,
                'created_by_role' => $user->role?->value ?? 'volunteer',
                'current_status' => self::STATUS_PENDING_REVIEW,
                'current_assignee_role' => 'non_coordinator',
                'pending_action' => self::ACTION_CREATE,
                'pending_requested_by' => $user->id,
                'pending_requested_by_role' => $user->role?->value ?? 'volunteer',
                'payload_json' => $payloadJson,
            ]);

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => null,
                'to_status' => self::STATUS_PENDING_REVIEW,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'volunteer',
                'reason' => 'Registration submitted for non-coordinator review',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryCreated,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
            );

            return $entry;
        });
    }

    /** @param array<string, mixed> $payloadJson */
    public function createDraft(User $user, array $payloadJson): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($user, $payloadJson): MigrantRegistryEntry {
            $entry = MigrantRegistryEntry::query()->create([
                'created_by' => $user->id,
                'created_by_role' => $user->role?->value ?? 'volunteer',
                'current_status' => self::STATUS_DRAFT,
                'current_assignee_role' => null,
                'pending_action' => self::ACTION_CREATE,
                'pending_requested_by' => $user->id,
                'pending_requested_by_role' => $user->role?->value ?? 'volunteer',
                'payload_json' => $payloadJson,
            ]);

            MigrantRegistryStatusHistory::query()->create([
                'registry_entry_id' => $entry->id,
                'from_status' => null,
                'to_status' => self::STATUS_DRAFT,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'volunteer',
                'reason' => 'Questionnaire draft created',
            ]);

            $this->auditService->success($this->request, AuditEventType::MigrantRegistryDraftCreated, $user, [
                'type' => MigrantRegistryEntry::class,
                'id' => $entry->id,
            ]);

            return $entry;
        });
    }

    /** @param array<string, mixed> $payloadJson */
    public function updateDraft(User $user, MigrantRegistryEntry $entry, array $payloadJson): MigrantRegistryEntry
    {
        $this->assertDraftOwner($user, $entry);
        $entry->forceFill(['payload_json' => $payloadJson])->save();

        return $entry->fresh(['creator:id,name,email,role', 'statusHistory']) ?? $entry;
    }

    /** @param array<string, mixed> $payloadJson */
    public function submitDraft(User $user, MigrantRegistryEntry $entry, array $payloadJson): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($user, $entry, $payloadJson): MigrantRegistryEntry {
            $entry = MigrantRegistryEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraftOwner($user, $entry);
            $entry->forceFill([
                'payload_json' => $payloadJson,
                'current_status' => self::STATUS_PENDING_REVIEW,
                'current_assignee_role' => 'non_coordinator',
            ])->save();

            MigrantRegistryStatusHistory::query()->create([
                'registry_entry_id' => $entry->id,
                'from_status' => self::STATUS_DRAFT,
                'to_status' => self::STATUS_PENDING_REVIEW,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'volunteer',
                'reason' => 'Registration submitted for non-coordinator review',
            ]);

            $this->auditService->success($this->request, AuditEventType::MigrantRegistrySubmitted, $user, [
                'type' => MigrantRegistryEntry::class,
                'id' => $entry->id,
            ]);

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    public function discardDraft(User $user, MigrantRegistryEntry $entry): void
    {
        $this->assertDraftOwner($user, $entry);
        $this->auditService->success($this->request, AuditEventType::MigrantRegistryDraftDiscarded, $user, [
            'type' => MigrantRegistryEntry::class,
            'id' => $entry->id,
        ]);
        $entry->forceDelete();
    }

    public function purgeExpiredDrafts(int $days = 7): int
    {
        $expired = MigrantRegistryEntry::query()
            ->where('current_status', self::STATUS_DRAFT)
            ->where('updated_at', '<=', now()->subDays($days))
            ->get();

        foreach ($expired as $entry) {
            $this->auditService->success($this->request, AuditEventType::MigrantRegistryDraftExpired, null, [
                'type' => MigrantRegistryEntry::class,
                'id' => $entry->id,
            ]);
            $entry->forceDelete();
        }

        return $expired->count();
    }

    private function assertDraftOwner(User $user, MigrantRegistryEntry $entry): void
    {
        abort_unless(
            $entry->current_status === self::STATUS_DRAFT && (int) $entry->created_by === (int) $user->getKey(),
            403,
            'Only the draft creator can access this registration draft.',
        );
    }

    public function requestUpdate(
        User $user,
        MigrantRegistryEntry $entry,
        array $payloadJson,
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $payloadJson) {
            if (in_array($entry->current_status, [self::STATUS_PENDING_REVIEW, self::STATUS_PENDING_APPROVAL], true)) {
                abort(409, 'This migrant registration is already awaiting review or approval.');
            }

            if ($entry->current_status === self::STATUS_DELETED) {
                abort(409, 'Deleted migrant registrations cannot be modified.');
            }

            if (
                $entry->current_status === self::STATUS_CHANGES_REQUESTED &&
                ! in_array($user->role, [UserRole::Admin, UserRole::Coordinator], true)
            ) {
                abort(403, 'Only a coordinator or administrator can correct this migrant registration.');
            }

            if (
                $entry->current_status !== self::STATUS_CHANGES_REQUESTED &&
                $entry->current_status !== self::STATUS_APPROVED
            ) {
                abort(409, 'Only approved migrant registrations can receive a new modification request.');
            }


            if (
                $entry->current_status === self::STATUS_APPROVED &&
                ! in_array($user->role, [UserRole::Admin, UserRole::Coordinator], true)
            ) {
                abort(403, 'Only a coordinator or administrator can start a registration modification request.');
            }

            $fromStatus = (string) $entry->current_status;

            // Quien corrige o modifica es coordinación/administración (ya validado arriba):
            // el cambio va directo a aprobación, sin regresar a revisión del operador.
            $entry->forceFill([
                'current_status' => self::STATUS_PENDING_APPROVAL,
                'current_assignee_role' => 'coordinator',
                'pending_action' => self::ACTION_UPDATE,
                'pending_requested_by' => $user->id,
                'pending_requested_by_role' => $user->role?->value ?? 'coordinator',
                'pending_payload_json' => $payloadJson,
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => $fromStatus,
                'to_status' => self::STATUS_PENDING_APPROVAL,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'coordinator',
                'reason' => 'Registration modification submitted by coordination for approval',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryUpdateRequested,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
                metadata: [
                    'previousStatus' => $fromStatus,
                    'status' => self::STATUS_PENDING_APPROVAL,
                ],
            );

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $signatureData
     */
    public function forwardReview(
        User $user,
        MigrantRegistryEntry $entry,
        ?string $reason,
        array $signatureData,
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $reason, $signatureData) {
            $signature = MigrantRegistrySignature::create([
                'registry_entry_id' => $entry->id,
                'actor_user_id' => $user->id,
                'actor_role' => $user->role?->value ?? 'non_coordinator',
                'action_type' => 'review_forward',
                'algorithm' => 'webauthn-passkey',
                'signature_payload' => json_encode($signatureData, JSON_THROW_ON_ERROR),
                'public_key_ref' => (string) ($signatureData['credentialId'] ?? ''),
                'verified_at' => now(),
            ]);

            $entry->forceFill([
                'current_status' => self::STATUS_PENDING_APPROVAL,
                'current_assignee_role' => 'coordinator',
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => self::STATUS_PENDING_REVIEW,
                'to_status' => self::STATUS_PENDING_APPROVAL,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'non_coordinator',
                'reason' => $reason,
                'signature_id' => $signature->id,
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryReviewForwarded,
                $user,
                resource: ['type' => MigrantRegistryEntry::class, 'id' => $entry->id],
                metadata: ['reason' => $reason, 'status' => self::STATUS_PENDING_APPROVAL],
            );

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    public function returnForCorrections(
        User $user,
        MigrantRegistryEntry $entry,
        string $reason,
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $reason) {
            $entry->forceFill([
                'current_status' => self::STATUS_CHANGES_REQUESTED,
                'current_assignee_role' => 'coordinator',
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => self::STATUS_PENDING_REVIEW,
                'to_status' => self::STATUS_CHANGES_REQUESTED,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'non_coordinator',
                'reason' => $reason,
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryReviewReturned,
                $user,
                resource: ['type' => MigrantRegistryEntry::class, 'id' => $entry->id],
                metadata: ['reason' => $reason, 'status' => self::STATUS_CHANGES_REQUESTED],
            );

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $signatureData
     */
    public function resolveApproval(
        User $user,
        MigrantRegistryEntry $entry,
        string $decision,
        ?string $reason,
        array $signatureData,
    ): MigrantRegistryEntry {
        return DB::transaction(fn (): MigrantRegistryEntry => $this->resolveApprovalRecord(
            $user,
            $entry,
            $decision,
            $reason,
            $signatureData,
        ));
    }

    /**
     * @param  list<array{id: int, payloadHash: string}>  $targets
     * @param  array<string, mixed>  $signatureData
     * @return Collection<int, MigrantRegistryEntry>
     */
    public function resolveBulkApproval(User $user, array $targets, array $signatureData): Collection
    {
        return DB::transaction(function () use ($user, $targets, $signatureData): Collection {
            $targetsById = collect($targets)->keyBy(fn (array $target): int => (int) $target['id']);
            $entries = MigrantRegistryEntry::query()
                ->whereKey($targetsById->keys()->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (MigrantRegistryEntry $entry): int => (int) $entry->getKey());

            if ($entries->count() !== $targetsById->count()) {
                abort(409, 'One or more registrations are no longer available for approval.');
            }

            foreach ($targetsById as $entryId => $target) {
                $entry = $entries->get((int) $entryId);

                if (
                    ! $entry instanceof MigrantRegistryEntry ||
                    $entry->current_status !== self::STATUS_PENDING_APPROVAL ||
                    ! hash_equals((string) $target['payloadHash'], $this->approvalPayloadHash($entry))
                ) {
                    abort(409, 'One or more registrations changed after bulk approval started. Reload and try again.');
                }
            }

            return $entries
                ->sortBy(fn (MigrantRegistryEntry $entry): int => (int) $entry->getKey())
                ->values()
                ->map(fn (MigrantRegistryEntry $entry): MigrantRegistryEntry => $this->resolveApprovalRecord(
                    $user,
                    $entry,
                    'approve',
                    null,
                    $signatureData,
                ));
        });
    }

    public function approvalPayloadHash(MigrantRegistryEntry $entry): string
    {
        $payload = $entry->pending_action === self::ACTION_UPDATE && is_array($entry->pending_payload_json)
            ? $entry->pending_payload_json
            : $entry->payload_json;

        return hash('sha256', json_encode(is_array($payload) ? $payload : [], JSON_THROW_ON_ERROR));
    }

    public static function approvalTargetsHash(mixed $targets): string
    {
        return hash('sha256', json_encode(self::canonicalize($targets), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
    }

    /**
     * @param  array<string, mixed>  $signatureData
     */
    private function resolveApprovalRecord(
        User $user,
        MigrantRegistryEntry $entry,
        string $decision,
        ?string $reason,
        array $signatureData,
    ): MigrantRegistryEntry {
        $fromStatus = (string) $entry->current_status;
        $pendingAction = (string) ($entry->pending_action ?? self::ACTION_CREATE);
        $pendingPayload = $entry->pending_payload_json;
        $toStatus = $decision === 'approve'
            ? self::STATUS_APPROVED
            : self::STATUS_REJECTED;

        $signature = MigrantRegistrySignature::create([
            'registry_entry_id' => $entry->id,
            'actor_user_id' => $user->id,
            'actor_role' => $user->role?->value ?? 'coordinator',
            'action_type' => $decision === 'approve' ? 'approve' : 'reject',
            'algorithm' => 'webauthn-passkey',
            'signature_payload' => json_encode($signatureData, JSON_THROW_ON_ERROR),
            'public_key_ref' => (string) ($signatureData['credentialId'] ?? ''),
            'verified_at' => now(),
        ]);

        $entryUpdates = [
            'current_status' => $toStatus,
            'current_assignee_role' => null,
            'pending_action' => null,
            'pending_requested_by' => null,
            'pending_requested_by_role' => null,
            'pending_payload_json' => null,
        ];

        if ($decision === 'approve' && $pendingAction === self::ACTION_UPDATE && is_array($pendingPayload)) {
            $entryUpdates = [
                ...$entryUpdates,
                'payload_json' => $pendingPayload,
            ];
        }

        $entry->forceFill($entryUpdates)->save();

        MigrantRegistryStatusHistory::create([
            'registry_entry_id' => $entry->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $user->id,
            'changed_by_role' => $user->role?->value ?? 'coordinator',
            'reason' => $reason,
            'signature_id' => $signature->id,
        ]);

        $this->auditService->success(
            $this->request,
            $decision === 'approve'
                ? AuditEventType::MigrantRegistryApproved
                : AuditEventType::MigrantRegistryRejected,
            $user,
            resource: [
                'type' => MigrantRegistryEntry::class,
                'id' => $entry->id,
            ],
            metadata: [
                'decision' => $decision,
                'reason' => $reason,
                'previousStatus' => $fromStatus,
                'status' => $toStatus,
                'pendingAction' => $pendingAction,
                'bulkApproval' => (bool) ($signatureData['bulkApproval'] ?? false),
            ],
        );

        return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
    }

    public function delete(User $user, MigrantRegistryEntry $entry): void
    {
        DB::transaction(function () use ($user, $entry) {
            $fromStatus = (string) $entry->current_status;

            $entry->forceFill([
                'current_status' => self::STATUS_DELETED,
                'current_assignee_role' => null,
                'pending_action' => null,
                'pending_requested_by' => null,
                'pending_requested_by_role' => null,
                'pending_payload_json' => null,
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => $fromStatus,
                'to_status' => self::STATUS_DELETED,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'admin',
                'reason' => 'Deleted by admin',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryDeleted,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
                metadata: [
                    'previousStatus' => $fromStatus,
                    'status' => self::STATUS_DELETED,
                    'processType' => 'normal',
                    'actionLabel' => 'Eliminación por Administrador',
                ],
            );

            $entry->delete();
        });
    }

    public function submit(
        User $user,
        MigrantRegistryEntry $entry,
        array $signatureData
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $signatureData) {
            $signature = MigrantRegistrySignature::create([
                'registry_entry_id' => $entry->id,
                'actor_user_id' => $user->id,
                'actor_role' => $user->role ?? 'volunteer',
                'action_type' => 'submit',
                'algorithm' => 'ES256',
                'signature_payload' => $signatureData['signature_payload'],
                'public_key_ref' => $signatureData['public_key_ref'] ?? null,
                'verified_at' => now(),
            ]);

            $entry->update([
                'current_status' => self::STATUS_PENDING_REVIEW,
                'current_assignee_role' => 'non_coordinator',
            ]);

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => 'draft',
                'to_status' => self::STATUS_PENDING_REVIEW,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role ?? 'volunteer',
                'reason' => 'Submitted for non-coordinator review',
                'signature_id' => $signature->id,
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistrySubmitted,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
            );

            return $entry->fresh([
                'signatures',
                'statusHistory',
            ]);
        });
    }
}
