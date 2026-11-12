<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantArcoRequest;
use App\Models\User;
use App\Services\Registry\RegistryEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantArcoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = MigrantArcoRequest::query()
            ->whereIn('request_type', config('features.arco_types', ['access']))
            ->with(['registryEntry', 'requester:id,name,email,role', 'signatures', 'statusHistory', 'artifact'])
            ->latest();
        if ($actor instanceof User && $actor->role === UserRole::NonCoordinator) {
            $query->where('requested_by', $actor->id);
        }

        $presenter = app(RegistryEntryPresenter::class);

        return response()->json(['data' => $query->get()->map(fn (MigrantArcoRequest $arco) => $presenter->presentArco($arco))->all()]);
    }

    public function show(Request $request, MigrantArcoRequest $migrantArcoRequest): JsonResponse
    {
        abort_unless(in_array($migrantArcoRequest->request_type, config('features.arco_types', ['access']), true), 404);

        $actor = $request->user();
        if ($actor instanceof User && $actor->role === UserRole::NonCoordinator && (int) $actor->id !== (int) $migrantArcoRequest->requested_by) {
            abort(403);
        }

        return response()->json(['data' => app(RegistryEntryPresenter::class)->presentArco(
            $migrantArcoRequest->load(['registryEntry', 'requester:id,name,email,role', 'signatures', 'statusHistory', 'artifact']),
        )]);
    }
}
