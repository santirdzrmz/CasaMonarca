<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Models\MigrantArcoArtifact;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantArcoSignature;
use App\Models\MigrantArcoStatusHistory;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\StoredZipArchiveService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrantArcoService
{
    public const STATUS_PENDING_COORDINATOR = 'pending_coordinator';

    public const STATUS_PENDING_ADMIN = 'pending_admin';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING_COORDINATOR, self::STATUS_PENDING_ADMIN];

    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $httpRequest,
        private readonly MigrantRegistryDocumentService $documentService,
        private readonly MigrantQuestionnaireDefinitionService $questionnaireDefinitionService,
        private readonly StoredZipArchiveService $zipArchiveService,
    ) {}

    /** @param array<string, mixed>|null $proposal @param array<string, mixed> $signatureData */
    public function create(User $actor, MigrantRegistryEntry $entry, string $type, string $reason, ?array $proposal, array $signatureData): MigrantArcoRequest
    {
        return DB::transaction(function () use ($actor, $entry, $type, $reason, $proposal, $signatureData): MigrantArcoRequest {
            $entry = MigrantRegistryEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($entry->current_status !== MigrantRegistryService::STATUS_APPROVED || $entry->pending_action !== null) {
                abort(409, 'Only approved registrations without pending changes can start an ARCO request.');
            }

            if (MigrantArcoRequest::query()->where('registry_entry_id', $entry->id)->whereIn('status', self::ACTIVE_STATUSES)->exists()) {
                abort(409, 'This registration already has an active ARCO request.');
            }

            $original = is_array($entry->payload_json) ? $entry->payload_json : [];
            $request = MigrantArcoRequest::query()->create([
                'registry_entry_id' => $entry->id,
                'requested_by' => $actor->id,
                'requested_by_role' => $actor->role?->value ?? 'non_coordinator',
                'request_type' => $type,
                'reason' => $reason,
                'original_payload_json' => $original,
                'proposed_payload_json' => $type === 'rectification' ? $proposal : null,
                'original_payload_hash' => $this->payloadHash($original),
                'proposed_payload_hash' => $type === 'rectification' ? $this->payloadHash($proposal ?? []) : null,
                'status' => self::STATUS_PENDING_COORDINATOR,
                'escalated_to_admin' => false,
            ]);
            $signature = $this->signature($request, $actor, 'request_created', $signatureData);
            $this->history($request, null, self::STATUS_PENDING_COORDINATOR, $actor, $reason, $signature);
            $this->audit(AuditEventType::MigrantArcoRequested, $actor, $request, null, self::STATUS_PENDING_COORDINATOR, $reason);

            return $this->detail($request);
        });
    }

    /** @param array<string, mixed> $signatureData */
    public function coordinatorDecision(User $actor, MigrantArcoRequest $request, string $decision, ?string $reason, array $signatureData): MigrantArcoRequest
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $request, $decision, $reason, $signatureData, &$storedPath): MigrantArcoRequest {
                $request = MigrantArcoRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

                if ($request->status !== self::STATUS_PENDING_COORDINATOR) {
                    abort(409, 'This ARCO request is no longer pending coordinator review.');
                }

                $signature = $this->signature($request, $actor, $decision === 'approve' ? 'coordinator_approved' : 'coordinator_rejected', $signatureData);

                if ($decision === 'reject') {
                    $this->finish($request, $actor, self::STATUS_REJECTED, $reason, $signature);
                } elseif ($request->request_type === 'cancellation') {
                    $from = $request->status;
                    $request->forceFill(['status' => self::STATUS_PENDING_ADMIN, 'escalated_to_admin' => true])->save();
                    $this->history($request, $from, self::STATUS_PENDING_ADMIN, $actor, $reason, $signature);
                } else {
                    if ($request->request_type === 'rectification') {
                        $this->applyRectification($actor, $request);
                    }

                    $this->finish($request, $actor, self::STATUS_COMPLETED, $reason, $signature);

                    if ($request->request_type === 'access') {
                        $storedPath = $this->generateAccessBundle($actor, $request);
                    }
                }

                $this->audit(AuditEventType::MigrantArcoResolved, $actor, $request, self::STATUS_PENDING_COORDINATOR, $request->status, $reason, ['decision' => $decision]);

                return $this->detail($request);
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $signatureData */
    public function adminDecision(User $actor, MigrantArcoRequest $request, string $decision, ?string $reason, array $signatureData): MigrantArcoRequest
    {
        return DB::transaction(function () use ($actor, $request, $decision, $reason, $signatureData): MigrantArcoRequest {
            $request = MigrantArcoRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->request_type !== 'cancellation' || $request->status !== self::STATUS_PENDING_ADMIN) {
                abort(409, 'This ARCO cancellation is no longer pending administrator review.');
            }

            $signature = $this->signature($request, $actor, $decision === 'approve' ? 'admin_approved_cancellation' : 'admin_rejected_cancellation', $signatureData);

            if ($decision === 'approve') {
                $this->purgeRegistration($actor, $request);
                $this->finish($request, $actor, self::STATUS_COMPLETED, $reason, $signature);
            } else {
                $this->finish($request, $actor, self::STATUS_REJECTED, $reason, $signature);
            }

            $this->audit(
                $decision === 'approve' ? AuditEventType::MigrantArcoCancellationExecuted : AuditEventType::MigrantArcoResolved,
                $actor,
                $request,
                self::STATUS_PENDING_ADMIN,
                $request->status,
                $reason,
                ['decision' => $decision, 'actionLabel' => $decision === 'approve' ? 'Eliminación por Administrador (Derecho ARCO)' : null],
            );

            return $this->detail($request);
        });
    }

    public function detail(MigrantArcoRequest $request): MigrantArcoRequest
    {
        return $request->fresh(['registryEntry', 'requester:id,name,email,role', 'signatures', 'statusHistory', 'artifact']) ?? $request;
    }

    private function applyRectification(User $actor, MigrantArcoRequest $request): void
    {
        $entry = MigrantRegistryEntry::withTrashed()->whereKey($request->registry_entry_id)->lockForUpdate()->firstOrFail();
        $proposal = $request->proposed_payload_json;

        if (! is_array($proposal) || ! $this->payloadMatchesHash($proposal, (string) $request->proposed_payload_hash)) {
            abort(409, 'The proposed rectification payload is missing or changed.');
        }

        $entry->forceFill(['payload_json' => $proposal])->save();
        MigrantRegistryStatusHistory::query()->create([
            'registry_entry_id' => $entry->id,
            'from_status' => MigrantRegistryService::STATUS_APPROVED,
            'to_status' => MigrantRegistryService::STATUS_APPROVED,
            'changed_by' => $actor->id,
            'changed_by_role' => $actor->role?->value ?? 'coordinator',
            'reason' => 'Rectification applied through an approved ARCO request.',
            'arco_request_id' => $request->id,
        ]);
        $this->audit(AuditEventType::MigrantArcoRectificationApplied, $actor, $request, 'approved', 'approved', $request->resolution_reason);
    }

    private function purgeRegistration(User $actor, MigrantArcoRequest $request): void
    {
        $entry = MigrantRegistryEntry::withTrashed()->whereKey($request->registry_entry_id)->lockForUpdate()->firstOrFail();
        $from = (string) $entry->current_status;
        $artifacts = MigrantArcoArtifact::query()->whereIn('arco_request_id', MigrantArcoRequest::query()->select('id')->where('registry_entry_id', $entry->id))->get();

        foreach ($artifacts as $artifact) {
            if ($artifact->storage_disk && $artifact->storage_path) {
                $disk = Storage::disk($artifact->storage_disk);

                if ($disk->exists($artifact->storage_path) && ! $disk->delete($artifact->storage_path)) {
                    throw new \RuntimeException('An ARCO artifact could not be purged.');
                }
            }
        }

        $documents = MigrantRegistryDocument::query()->where('registry_entry_id', $entry->id)->whereNull('purged_at')->get();

        foreach ($documents as $document) {
            $this->documentService->deleteStoredFileOrFail($document);
        }

        foreach ($artifacts as $artifact) {
            $artifact->forceFill(['storage_disk' => null, 'storage_path' => null, 'purged_at' => now()])->save();
        }

        foreach ($documents as $document) {
            $document->forceFill(['storage_disk' => null, 'storage_path' => null, 'purged_at' => now()])->save();
            if (! $document->trashed()) {
                $document->delete();
            }
        }

        MigrantArcoRequest::query()->where('registry_entry_id', $entry->id)->update([
            'original_payload_json' => null,
            'proposed_payload_json' => null,
            'original_payload_json_enc' => null,
            'proposed_payload_json_enc' => null,
        ]);
        $entry->forceFill([
            'payload_json' => [],
            'pending_payload_json' => null,
            'pending_action' => null,
            'pending_requested_by' => null,
            'pending_requested_by_role' => null,
            'current_status' => 'deleted_by_admin_arco',
            'current_assignee_role' => null,
        ])->save();
        MigrantRegistryStatusHistory::query()->create([
            'registry_entry_id' => $entry->id,
            'from_status' => $from,
            'to_status' => 'deleted_by_admin_arco',
            'changed_by' => $actor->id,
            'changed_by_role' => $actor->role?->value ?? 'admin',
            'reason' => 'Eliminación por Administrador (Derecho ARCO)',
            'arco_request_id' => $request->id,
        ]);
        if (! $entry->trashed()) {
            $entry->delete();
        }
        $this->audit(AuditEventType::MigrantArcoArtifactsPurged, $actor, $request, $from, 'deleted_by_admin_arco', null, ['artifactCount' => $artifacts->count(), 'documentCount' => $documents->count()]);
    }

    private function generateAccessBundle(User $actor, MigrantArcoRequest $request): string
    {
        $request->loadMissing(['registryEntry', 'registryEntry.documents' => fn ($query) => $query->whereNull('purged_at'), 'requester', 'signatures', 'statusHistory']);
        $files = [['name' => 'registro/registro-migrante.pdf', 'contents' => $this->renderAccessPdf($request)]];

        foreach ($request->registryEntry?->documents ?? [] as $index => $document) {
            if (! $document->storage_disk || ! $document->storage_path) {
                throw new \RuntimeException('A supporting document covered by the ARCO Access request is unavailable.');
            }

            $disk = Storage::disk($document->storage_disk);

            if (! $disk->exists($document->storage_path)) {
                throw new \RuntimeException('A supporting document covered by the ARCO Access request is missing.');
            }

            $contents = $disk->get($document->storage_path);

            if (! hash_equals((string) $document->sha256, hash('sha256', $contents))) {
                throw new \RuntimeException('A supporting document covered by the ARCO Access request failed its integrity check.');
            }

            $files[] = [
                'name' => sprintf('documentos/%02d-%s', $index + 1, $this->safeArchiveFilename($document->original_file_name, $document->id)),
                'contents' => $contents,
            ];
        }

        $contents = $this->zipArchiveService->build($files);
        $filename = sprintf('solicitud-arco-acceso-%d.zip', $request->id);
        $path = sprintf('arco/access/%d/%s', $request->id, $filename);

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new \RuntimeException('The ARCO Access bundle could not be stored.');
        }

        try {
            $sha256 = hash('sha256', $contents);
            MigrantArcoArtifact::query()->create([
                'arco_request_id' => $request->id,
                'storage_disk' => 'local',
                'storage_path' => $path,
                'filename' => $filename,
                'mime_type' => 'application/zip',
                'byte_size' => strlen($contents),
                'sha256' => $sha256,
                'generated_at' => now(),
            ]);
            $this->audit(AuditEventType::MigrantArcoAccessDocumentGenerated, $actor, $request, null, self::STATUS_COMPLETED, null, [
                'sha256' => $sha256,
                'documentCount' => count($files) - 1,
                'bundleEntryCount' => count($files),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $path;
    }

    private function renderAccessPdf(MigrantArcoRequest $request): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $payload = $request->registryEntry?->payload_json ?? $request->original_payload_json ?? [];
        $dompdf->loadHtml(view('arco.access-pdf', [
            'arco' => $request,
            'questionnaireSections' => $this->questionnaireDefinitionService->spanishAnswerSections(is_array($payload) ? $payload : []),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }

    private function safeArchiveFilename(string $originalFilename, int $documentId): string
    {
        $filename = Str::ascii(basename(str_replace('\\', '/', $originalFilename)));
        $filename = preg_replace('/[^A-Za-z0-9._()-]+/', '-', $filename) ?? '';
        $filename = trim(str_replace('..', '.', $filename), '.-');

        return $filename !== '' ? $filename : "documento-{$documentId}";
    }

    private function finish(MigrantArcoRequest $request, User $actor, string $status, ?string $reason, MigrantArcoSignature $signature): void
    {
        $from = $request->status;
        $request->forceFill([
            'status' => $status,
            'resolved_by' => $actor->id,
            'resolved_by_role' => $actor->role?->value,
            'resolution_reason' => $reason,
            'completed_at' => now(),
        ])->save();
        $this->history($request, $from, $status, $actor, $reason, $signature);
    }

    /** @param array<string, mixed> $data */
    private function signature(MigrantArcoRequest $request, User $actor, string $action, array $data): MigrantArcoSignature
    {
        return MigrantArcoSignature::query()->create([
            'arco_request_id' => $request->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role?->value ?? 'non_coordinator',
            'action_type' => $action,
            'signature_payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'public_key_ref' => (string) ($data['credentialId'] ?? ''),
            'verified_at' => now(),
        ]);
    }

    private function history(MigrantArcoRequest $request, ?string $from, string $to, User $actor, ?string $reason, MigrantArcoSignature $signature): void
    {
        MigrantArcoStatusHistory::query()->create([
            'arco_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor->id,
            'changed_by_role' => $actor->role?->value ?? 'non_coordinator',
            'reason' => $reason,
            'signature_id' => $signature->id,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function audit(AuditEventType $type, ?User $actor, MigrantArcoRequest $request, ?string $from, ?string $to, ?string $reason, array $extra = []): void
    {
        $chain = $request->signatures()->get()->map(fn (MigrantArcoSignature $signature): array => [
            'id' => $signature->id,
            'actorUserId' => $signature->actor_user_id,
            'actorRole' => $signature->actor_role,
            'action' => $signature->action_type,
            'verifiedAt' => $signature->verified_at?->toIso8601String(),
            'evidenceSha256' => hash('sha256', $signature->signature_payload),
        ])->all();
        $this->auditService->success($this->httpRequest, $type, $actor, ['type' => MigrantArcoRequest::class, 'id' => $request->id], [
            'arcoProcess' => true,
            'requestType' => $request->request_type,
            'registryEntryId' => $request->registry_entry_id,
            'previousStatus' => $from,
            'status' => $to,
            'reason' => $reason,
            'signatureChain' => $chain,
            ...$extra,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalizeForHash($payload), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public function payloadMatchesHash(array $payload, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->payloadHash($payload))
            || hash_equals($expectedHash, $this->legacyPayloadHash($payload));
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
    }

    /** @param array<string, mixed> $payload */
    private function legacyPayloadHash(array $payload): string
    {
        if (($payload['schemaVersion'] ?? null) === 2 && is_array(data_get($payload, 'questionnaire.answers'))) {
            $answers = [];

            foreach (data_get($payload, 'questionnaire.answers', []) as $questionId => $answer) {
                if (! is_array($answer)) {
                    $answers[$questionId] = $answer;

                    continue;
                }

                $normalizedAnswer = ['value' => $answer['value'] ?? null];

                if (array_key_exists('otherText', $answer)) {
                    $normalizedAnswer['otherText'] = $answer['otherText'];
                }

                $answers[$questionId] = $normalizedAnswer;
            }

            $payload['questionnaire'] = [
                'definitionId' => data_get($payload, 'questionnaire.definitionId'),
                'answers' => $answers,
            ];
        }

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
