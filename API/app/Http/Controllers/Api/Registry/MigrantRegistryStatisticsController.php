<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditEventService;
use App\Services\Registry\MigrantRegistryStatisticsCsv;
use App\Services\Registry\MigrantRegistryStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MigrantRegistryStatisticsController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryStatisticsService $statisticsService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summary($request)]);
    }

    /** Same filters as the dashboard, as a CSV of aggregate counts. Every download is audited. */
    public function export(Request $request, MigrantRegistryStatisticsCsv $csv, AuditEventService $audit): Response
    {
        $summary = $this->summary($request);
        $range = $summary['range'];

        $audit->success($request, AuditEventType::MigrantStatisticsExported, $request->user(), ['type' => 'migrant_statistics'], [
            'format' => 'csv',
            'from' => $range['from'],
            'to' => $range['to'],
            'ageMin' => $range['ageMin'],
            'ageMax' => $range['ageMax'],
            'stay' => $range['stay'],
            'total' => $summary['total'],
        ]);

        $name = 'estadisticas-registros-'.($range['from'] ?? 'inicio').'-a-'.($range['to'] ?? CarbonImmutable::today()->toDateString()).'.csv';

        return response($csv->build($summary), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:120', ...($request->filled('age_min') ? ['gte:age_min'] : [])],
            'stay' => ['nullable', Rule::in([MigrantRegistryStatisticsService::STAY_IN_SHELTER, MigrantRegistryStatisticsService::STAY_EXITED])],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'])->endOfDay() : null;

        return $this->statisticsService->summarize(
            $from,
            $to,
            isset($validated['age_min']) ? (int) $validated['age_min'] : null,
            isset($validated['age_max']) ? (int) $validated['age_max'] : null,
            $validated['stay'] ?? null,
        );
    }
}
