<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Security\Encryption\EncryptionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shelter stay. An approved registration is "in the shelter" until staff record the date the
 * person left (egreso) and why; the exit can be undone if it was recorded by mistake. The audit
 * trail keeps the date and who did it, never the reason text (it can hold personal details).
 */
class MigrantRegistryExitService
{
    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly MigrantQuestionnaireDefinitionService $definitionService,
        private readonly Request $request,
    ) {}

    /**
     * The questionnaire "Fecha de atención", when the payload is readable. An exit cannot come before it.
     */
    public function attentionDate(MigrantRegistryEntry $entry): ?CarbonImmutable
    {
        try {
            $payload = $entry->payload_json;
        } catch (EncryptionException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        if (! is_array(data_get($payload, 'questionnaire.answers'))) {
            $payload = $this->definitionService->upgradeLegacyPayload($payload);
        }

        $questionId = $this->definitionService->definition()['summaryMappings']['attentionDate'] ?? null;
        $value = $questionId ? data_get($payload, "questionnaire.answers.{$questionId}.value") : null;

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function record(User $actor, MigrantRegistryEntry $entry, CarbonImmutable $exitedAt, string $reason): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($actor, $entry, $exitedAt, $reason): MigrantRegistryEntry {
            $entry->forceFill([
                'exited_at' => $exitedAt->toDateString(),
                'exit_reason' => $reason,
                'exited_by' => $actor->id,
            ])->save();

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryExitRecorded,
                $actor,
                resource: ['type' => MigrantRegistryEntry::class, 'id' => $entry->id],
                metadata: ['exited_at' => $exitedAt->toDateString()],
            );

            return $this->fresh($entry);
        });
    }

    public function undo(User $actor, MigrantRegistryEntry $entry): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($actor, $entry): MigrantRegistryEntry {
            $previous = $entry->exited_at?->toDateString();

            $entry->forceFill(['exited_at' => null, 'exit_reason' => null, 'exited_by' => null])->save();

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryExitUndone,
                $actor,
                resource: ['type' => MigrantRegistryEntry::class, 'id' => $entry->id],
                metadata: ['previous_exited_at' => $previous],
            );

            return $this->fresh($entry);
        });
    }

    private function fresh(MigrantRegistryEntry $entry): MigrantRegistryEntry
    {
        return $entry->fresh(['creator:id,name,email,role', 'exitedBy:id,name', 'signatures', 'statusHistory']) ?? $entry;
    }
}
