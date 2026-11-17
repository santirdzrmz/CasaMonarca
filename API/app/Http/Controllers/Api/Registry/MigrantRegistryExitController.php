<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantRegistryExitService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Registry\RegistryEntryPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record (store) or undo (destroy) a person's exit from the shelter. Admins and coordinators only.
 */
class MigrantRegistryExitController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryExitService $exitService,
        private readonly RegistryEntryPresenter $presenter,
    ) {}

    public function store(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'exited_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_APPROVED) {
            return response()->json(['message' => 'Only approved registrations can record a shelter exit.'], 409);
        }

        if ($migrantRegistryEntry->hasExited()) {
            return response()->json(['message' => 'This person already has a shelter exit recorded.'], 409);
        }

        $exitedAt = CarbonImmutable::createFromFormat('!Y-m-d', $validated['exited_at']);
        $attention = $this->exitService->attentionDate($migrantRegistryEntry);

        if ($attention !== null && $exitedAt->lt($attention)) {
            return response()->json([
                'message' => 'The exit date cannot be before the attention date.',
                'errors' => ['exited_at' => ['The exit date cannot be before the attention date ('.$attention->toDateString().').']],
            ], 422);
        }

        $entry = $this->exitService->record($actor, $migrantRegistryEntry, $exitedAt, trim((string) $validated['reason']));

        return response()->json([
            'message' => 'Shelter exit recorded.',
            'data' => $this->presenter->present($entry, $actor),
        ]);
    }

    public function destroy(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $migrantRegistryEntry->hasExited()) {
            return response()->json(['message' => 'This person has no shelter exit recorded.'], 409);
        }

        $entry = $this->exitService->undo($actor, $migrantRegistryEntry);

        return response()->json([
            'message' => 'Shelter exit undone.',
            'data' => $this->presenter->present($entry, $actor),
        ]);
    }
}
