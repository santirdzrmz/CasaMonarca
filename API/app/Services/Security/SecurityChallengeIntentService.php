<?php

namespace App\Services\Security;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\Department;
use App\Http\Middleware\RequireActingDepartment;
use App\Models\AuditEvent;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityChallengeIntentService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        string $purpose,
        string $challenge,
        User $actor,
        string $origin,
        string $rpId,
        \DateTimeInterface|string $expiresAt,
        array $payload = [],
        ?string $targetType = null,
        int|string|null $targetId = null,
    ): SecurityChallengeIntent {
        $actingDepartment = request()->attributes->get(RequireActingDepartment::ATTRIBUTE);

        if ($actingDepartment instanceof Department) {
            $payload['actingDepartment'] = $actingDepartment->value;
        }

        return SecurityChallengeIntent::query()->create([
            'purpose' => $purpose,
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'actor_user_id' => $actor->getKey(),
            'target_type' => $targetType,
            'target_id' => is_numeric($targetId) ? (int) $targetId : null,
            'challenge_hash' => $this->hashChallenge($challenge),
            'payload' => $payload,
            'origin' => $origin,
            'rp_id' => $rpId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findPendingForActor(string $intentId, User $actor, string $purpose): ?SecurityChallengeIntent
    {
        return SecurityChallengeIntent::query()
            ->whereKey($intentId)
            ->where('actor_user_id', $actor->getKey())
            ->where('purpose', $purpose)
            ->where('status', SecurityChallengeIntent::STATUS_PENDING)
            ->first();
    }

    public function markSucceeded(SecurityChallengeIntent $intent): SecurityChallengeIntent
    {
        $intent->forceFill([
            'status' => SecurityChallengeIntent::STATUS_SUCCEEDED,
            'completed_at' => now('UTC'),
            'failure_reason' => null,
        ])->save();

        return $intent;
    }

    public function markFailed(SecurityChallengeIntent $intent, string $reason): SecurityChallengeIntent
    {
        $intent->forceFill([
            'status' => SecurityChallengeIntent::STATUS_FAILED,
            'completed_at' => now('UTC'),
            'failure_reason' => $reason,
        ])->save();

        return $intent;
    }

    public function markCancelled(SecurityChallengeIntent $intent, ?Request $request = null, string $reason = 'user_cancelled'): SecurityChallengeIntent
    {
        $intent->forceFill([
            'status' => SecurityChallengeIntent::STATUS_CANCELLED,
            'cancelled_at' => now('UTC'),
            'failure_reason' => $reason,
        ])->save();

        $this->recordLifecycleAudit($intent, AuditEventType::SecurityChallengeCancelled, $request, [
            'reason' => $reason,
        ]);

        return $intent;
    }

    public function markExpired(SecurityChallengeIntent $intent, ?Request $request = null, string $reason = 'expired'): SecurityChallengeIntent
    {
        $intent->forceFill([
            'status' => SecurityChallengeIntent::STATUS_EXPIRED,
            'completed_at' => now('UTC'),
            'failure_reason' => $reason,
        ])->save();

        $this->recordLifecycleAudit($intent, AuditEventType::SecurityChallengeExpired, $request, [
            'reason' => $reason,
        ]);

        return $intent;
    }

    public function expirePending(int $limit = 500): int
    {
        $expiredCount = 0;

        SecurityChallengeIntent::query()
            ->where('status', SecurityChallengeIntent::STATUS_PENDING)
            ->where('expires_at', '<', now('UTC'))
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (SecurityChallengeIntent $intent) use (&$expiredCount): void {
                $this->markExpired($intent, null, 'scheduled_expiry');
                $expiredCount++;
            });

        return $expiredCount;
    }

    public function hashChallenge(string $challenge): string
    {
        return hash('sha256', $challenge);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordLifecycleAudit(
        SecurityChallengeIntent $intent,
        AuditEventType $eventType,
        ?Request $request,
        array $metadata = [],
    ): void {
        AuditEvent::query()->create([
            'occurred_at' => now('UTC'),
            'actor_user_id' => $intent->actor_user_id,
            'actor_role' => $intent->actor?->role?->value,
            'acting_department' => data_get($intent->payload, 'actingDepartment'),
            'event_type' => $eventType->value,
            'resource_type' => $intent->target_type,
            'resource_id' => $intent->target_id,
            'document_id' => $this->nullableInteger(data_get($intent->payload, 'documentId')),
            'revision_id' => $this->nullableInteger(data_get($intent->payload, 'revisionId')),
            'outcome' => AuditEventOutcome::Failure->value,
            'request_id' => $request?->headers->get('X-Request-Id') ?? $request?->headers->get('X-Request-ID'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'session_id_hash' => $this->resolveSessionIdHash($request),
            'metadata' => array_filter([
                ...$metadata,
                'action' => data_get($intent->payload, 'action'),
                'challengeIntentId' => $intent->getKey(),
                'purpose' => $intent->purpose,
                'targetUserId' => $this->nullableInteger(data_get($intent->payload, 'targetUserId')),
                'targetUserName' => data_get($intent->payload, 'targetUserName'),
                'targetUserEmail' => data_get($intent->payload, 'targetUserEmail'),
                'targetType' => $intent->target_type,
                'targetId' => $intent->target_id,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveSessionIdHash(?Request $request): ?string
    {
        if ($request === null || ! $request->hasSession()) {
            return null;
        }

        try {
            $sessionId = $request->session()->getId();
        } catch (\Throwable) {
            return null;
        }

        return is_string($sessionId) && trim($sessionId) !== ''
            ? hash('sha256', $sessionId)
            : null;
    }
}
