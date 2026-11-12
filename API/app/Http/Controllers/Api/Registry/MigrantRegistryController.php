<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMigrantRegistryRequest;
use App\Http\Requests\SubmitMigrantRegistryDraftRequest;
use App\Http\Requests\SubmitMigrantRegistryRequest;
use App\Http\Requests\UpdateMigrantRegistryRequest;
use App\Models\MigrantRegistryEntry;
use App\Services\Registry\MigrantQuestionnaireDefinitionService;
use App\Services\Registry\MigrantRegistryDocumentService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Registry\RegistryEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrantRegistryController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $service,
        private readonly MigrantRegistryDocumentService $documentService,
        private readonly MigrantQuestionnaireDefinitionService $questionnaire,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entries = MigrantRegistryEntry::query()
            ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
            ->latest()
            ->where('current_status', '!=', MigrantRegistryService::STATUS_DRAFT)
            ->get();

        return response()->json([
            'data' => app(RegistryEntryPresenter::class)->presentMany($entries, $request->user()),
        ]);
    }

    public function questionnaire(): JsonResponse
    {
        return response()->json(['data' => $this->questionnaire->definition()]);
    }

    public function drafts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->where('current_status', MigrantRegistryService::STATUS_DRAFT)
                ->where('created_by', $request->user()?->getKey())
                ->latest('updated_at')
                ->get()
                ->each(function (MigrantRegistryEntry $entry): void {
                    $entry->setAttribute('expires_at', $entry->updated_at?->copy()->addDays(7));
                }),
        ]);
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $payload = $request->validate(['payload_json' => ['required', 'array']])['payload_json'];
        $entry = $this->service->createDraft($request->user(), $this->questionnaire->normalizePayload($payload, false));

        return response()->json(['data' => $entry], 201);
    }

    public function updateDraft(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $payload = $request->validate(['payload_json' => ['required', 'array']])['payload_json'];
        $entry = $this->service->updateDraft(
            $request->user(),
            $migrantRegistryEntry,
            $this->questionnaire->normalizePayload($payload, false),
        );

        return response()->json(['data' => $entry]);
    }

    public function discardDraft(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $this->service->discardDraft($request->user(), $migrantRegistryEntry);

        return response()->json(['message' => 'Registration draft discarded.']);
    }

    public function submitDraft(
        SubmitMigrantRegistryDraftRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $validated = $request->validated();
        $payload = $this->questionnaire->normalizePayload($validated['payload_json'], true);
        $storedDocuments = collect();

        try {
            $entry = DB::transaction(function () use ($request, $migrantRegistryEntry, $payload, $validated, $storedDocuments): MigrantRegistryEntry {
                $entry = $this->service->submitDraft($request->user(), $migrantRegistryEntry, $payload);

                foreach ($validated['documents'] ?? [] as $index => $file) {
                    $storedDocuments->push($this->documentService->store(
                        $request->user(),
                        $entry,
                        $file,
                        $validated['document_labels'][$index] ?? null,
                    ));
                }

                return $entry;
            });
        } catch (\Throwable $exception) {
            $this->documentService->cleanupStoredFiles($storedDocuments);
            throw $exception;
        }

        return response()->json(['data' => $entry]);
    }

    public function pendingApproval(Request $request): JsonResponse
    {
        $entries = MigrantRegistryEntry::query()
            ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
            ->where('current_status', MigrantRegistryService::STATUS_PENDING_APPROVAL)
            ->latest()
            ->get();

        return response()->json([
            'data' => app(RegistryEntryPresenter::class)->presentMany($entries, $request->user()),
        ]);
    }

    public function pendingReview(Request $request): JsonResponse
    {
        $entries = MigrantRegistryEntry::query()
            ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
            ->where('current_status', MigrantRegistryService::STATUS_PENDING_REVIEW)
            ->latest()
            ->get();

        return response()->json([
            'data' => app(RegistryEntryPresenter::class)->presentMany($entries, $request->user()),
        ]);
    }

    public function corrections(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
                ->where('current_status', MigrantRegistryService::STATUS_CHANGES_REQUESTED)
                ->where('created_by', $request->user()?->getKey())
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreMigrantRegistryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storedDocuments = collect();

        try {
            $entry = DB::transaction(function () use ($request, $validated, $storedDocuments): MigrantRegistryEntry {
                $entry = $this->service->create(
                    $request->user(),
                    $validated['payload_json'],
                );

                foreach ($validated['documents'] ?? [] as $index => $file) {
                    $storedDocuments->push($this->documentService->store(
                        $request->user(),
                        $entry,
                        $file,
                        $validated['document_labels'][$index] ?? null,
                    ));
                }

                return $entry;
            });
        } catch (\Throwable $exception) {
            $this->documentService->cleanupStoredFiles($storedDocuments);

            throw $exception;
        }

        return response()->json([
            'data' => $entry,
        ], 201);
    }

    public function show(MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        if ($migrantRegistryEntry->current_status === MigrantRegistryService::STATUS_DRAFT) {
            abort_unless((int) $migrantRegistryEntry->created_by === (int) request()->user()?->getKey(), 403);
        }

        $presenter = app(RegistryEntryPresenter::class);
        abort_unless($presenter->isVisibleTo($migrantRegistryEntry, request()->user()), 403);

        return response()->json([
            'data' => $presenter->present($migrantRegistryEntry->load([
                'creator:id,name,email,role',
                'signatures',
                'statusHistory',
            ]), request()->user()),
        ]);
    }

    public function update(
        UpdateMigrantRegistryRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $validated = $request->validated();
        $storedDocuments = collect();

        try {
            $entry = DB::transaction(function () use ($request, $validated, $migrantRegistryEntry, $storedDocuments): MigrantRegistryEntry {
                $entry = $this->service->requestUpdate(
                    $request->user(),
                    $migrantRegistryEntry,
                    $validated['payload_json'],
                );

                foreach ($validated['documents'] ?? [] as $index => $file) {
                    $storedDocuments->push($this->documentService->store(
                        $request->user(),
                        $entry,
                        $file,
                        $validated['document_labels'][$index] ?? null,
                    ));
                }

                return $entry;
            });
        } catch (\Throwable $exception) {
            $this->documentService->cleanupStoredFiles($storedDocuments);

            throw $exception;
        }

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function destroy(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $this->service->delete($request->user(), $migrantRegistryEntry);

        return response()->json([
            'message' => 'Migrant registration deleted.',
        ]);
    }

    public function submit(
        SubmitMigrantRegistryRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $entry = $this->service->submit(
            $request->user(),
            $migrantRegistryEntry,
            $request->validated(),
        );

        return response()->json([
            'data' => $entry,
        ]);
    }
}
