<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Services\Registry\MigrantRegistryStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryStatisticsController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryStatisticsService $statisticsService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:120', ...($request->filled('age_min') ? ['gte:age_min'] : [])],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'])->endOfDay() : null;

        return response()->json([
            'data' => $this->statisticsService->summarize(
                $from,
                $to,
                isset($validated['age_min']) ? (int) $validated['age_min'] : null,
                isset($validated['age_max']) ? (int) $validated['age_max'] : null,
            ),
        ]);
    }
}
