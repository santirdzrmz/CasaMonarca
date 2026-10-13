<?php

namespace App\Services\Audit;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\Department;
use App\Http\Middleware\RequireActingDepartment;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditEventService
{
    /**
     * @param  array{
     *     type?: string|null,
     *     id?: int|string|null,
     *     documentId?: int|string|null,
     *     revisionId?: int|string|null,
     * }  $resource
     * @param  array<string, mixed>  $metadata
     */
    public function success(
        Request $request,
        AuditEventType $eventType,
        ?User $actor = null,
        array $resource = [],
        array $metadata = [],
    ): AuditEvent {
        return $this->record(
            $request,
            $eventType,
            AuditEventOutcome::Success,
            $actor,
            $resource,
            $metadata,
        );
    }

    /**
     * @param  array{
     *     type?: string|null,
     *     id?: int|string|null,
     *     documentId?: int|string|null,
     *     revisionId?: int|string|null,
     * }  $resource
     * @param  array<string, mixed>  $metadata
     */
    public function failure(
        Request $request,
        AuditEventType $eventType,
        ?User $actor = null,
        array $resource = [],
        array $metadata = [],
    ): AuditEvent {
        return $this->record(
            $request,
            $eventType,
            AuditEventOutcome::Failure,
            $actor,
            $resource,
            $metadata,
        );
    }

    /**
     * @param  array{
     *     type?: string|null,
     *     id?: int|string|null,
     *     documentId?: int|string|null,
     *     revisionId?: int|string|null,
     * }  $resource
     * @param  array<string, mixed>  $metadata
     */
    public function denied(
        Request $request,
        AuditEventType $eventType,
        ?User $actor = null,
        array $resource = [],
        array $metadata = [],
    ): AuditEvent {
        return $this->record(
            $request,
            $eventType,
            AuditEventOutcome::Denied,
            $actor,
            $resource,
            $metadata,
        );
    }

    /**
     * @param  array{
     *     type?: string|null,
     *     id?: int|string|null,
     *     documentId?: int|string|null,
     *     revisionId?: int|string|null,
     * }  $resource
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Request $request,
        AuditEventType $eventType,
        AuditEventOutcome $outcome,
        ?User $actor = null,
        array $resource = [],
        array $metadata = [],
    ): AuditEvent {
        $resolvedActor = $actor;

        if (! $resolvedActor instanceof User) {
            $requestUser = $request->user();
            $resolvedActor = $requestUser instanceof User ? $requestUser : null;
        }

        return AuditEvent::query()->create([
            'occurred_at' => now('UTC'),
            'actor_user_id' => $resolvedActor?->getKey(),
            'actor_role' => $resolvedActor?->role?->value,
            'acting_department' => $this->resolveActingDepartment($request),
            'event_type' => $eventType->value,
            'resource_type' => $this->nullableString($resource['type'] ?? null),
            'resource_id' => $this->nullableInteger($resource['id'] ?? null),
            'document_id' => $this->nullableInteger($resource['documentId'] ?? null),
            'revision_id' => $this->nullableInteger($resource['revisionId'] ?? null),
            'outcome' => $outcome->value,
            'request_id' => $this->resolveRequestId($request),
            'ip_address' => $request->ip(),
            'user_agent' => $this->nullableString($request->userAgent()),
            'session_id_hash' => $this->resolveSessionIdHash($request),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveActingDepartment(Request $request): ?string
    {
        $department = $request->attributes->get(RequireActingDepartment::ATTRIBUTE);

        return $department instanceof Department ? $department->value : null;
    }

    private function resolveRequestId(Request $request): ?string
    {
        $requestId = $request->headers->get('X-Request-Id')
            ?? $request->headers->get('X-Request-ID');

        return $this->nullableString($requestId);
    }

    private function resolveSessionIdHash(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        try {
            $sessionId = $request->session()->getId();
        } catch (\Throwable) {
            return null;
        }

        $normalizedSessionId = $this->nullableString($sessionId);

        return $normalizedSessionId === null
            ? null
            : hash('sha256', $normalizedSessionId);
    }
}
