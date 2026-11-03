<?php

namespace App\Services\Registry;

use App\Models\MigrantRegistryEntry;
use Carbon\CarbonImmutable;

/**
 * Aggregate statistics over approved migrant registrations.
 *
 * Aggregation runs in PHP over the decoded payloads (not SQL JSON paths) so it keeps
 * working once registry payloads are envelope-encrypted. The reference date for every
 * record is the questionnaire "Fecha de atención"; records without a valid one fall
 * back to the date the registration was created.
 */
class MigrantRegistryStatisticsService
{
    private const QUESTION_LITERACY = 'rf233bf6dce04426e869ab22c29187923';

    private const QUESTION_EDUCATION_ADULT = 'rc4c93ad0575541cc8285754e6d11d9b0';

    private const QUESTION_EDUCATION_MINOR = 'r65ec8fd31b8f421eb0d6c50bdd9115b3';

    private const QUESTION_MEXICO_ENTRY_DATE = 'rb5d53bd5db9045dd8fe0b1dc849acdf4';

    private const QUESTION_FINAL_DESTINATION = 'r2bbdd2ae059d41b988ef4e55381fafde';

    public const MISSING = 'Sin respuesta';

    /** Lowest to highest; both education questions share these values. */
    private const EDUCATION_ORDER = [
        'Sin escolarizar',
        'Preescolar',
        'Primaria',
        'Secundaria',
        'Preparatoria',
        'Bachillerato técnico',
        'Licenciatura',
        'Posgrado',
    ];

    /** Days from entering Mexico to the attention date at Casa Monarca. */
    private const DAY_BINS = [
        ['label' => '0–7 días', 'min' => 0, 'max' => 7],
        ['label' => '8–14 días', 'min' => 8, 'max' => 14],
        ['label' => '15–30 días', 'min' => 15, 'max' => 30],
        ['label' => '1–2 meses', 'min' => 31, 'max' => 60],
        ['label' => '2–3 meses', 'min' => 61, 'max' => 90],
        ['label' => '3–6 meses', 'min' => 91, 'max' => 180],
        ['label' => '6–12 meses', 'min' => 181, 'max' => 365],
        ['label' => 'Más de 1 año', 'min' => 366, 'max' => null],
    ];

    public function __construct(
        private readonly MigrantQuestionnaireDefinitionService $definitionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $definition = $this->definitionService->definition();
        $mapping = $definition['summaryMappings'] ?? [];
        $dimensions = $this->dimensionDefinitions($definition, $mapping);

        $counts = [];
        $byMonth = [];
        foreach (array_keys($dimensions) as $key) {
            $counts[$key] = [];
            $byMonth[$key] = [];
        }

        $arrivals = [];
        $days = [];
        $daysMissing = 0;
        $daysInconsistent = 0;
        $total = 0;
        $minMonth = null;
        $maxMonth = null;

        $query = MigrantRegistryEntry::query()->where('current_status', MigrantRegistryService::STATUS_APPROVED);

        foreach ($query->lazyById(500) as $entry) {
            $answers = $this->answers($entry->payload_json);
            $referenceDate = $this->date($answers[$mapping['attentionDate'] ?? ''] ?? null)
                ?? ($entry->created_at ? CarbonImmutable::parse($entry->created_at)->startOfDay() : null);

            if ($referenceDate === null || ($from && $referenceDate->lt($from)) || ($to && $referenceDate->gt($to))) {
                continue;
            }

            $total++;
            $month = $referenceDate->format('Y-m');
            $arrivals[$month] = ($arrivals[$month] ?? 0) + 1;
            $minMonth = $minMonth === null || $month < $minMonth ? $month : $minMonth;
            $maxMonth = $maxMonth === null || $month > $maxMonth ? $month : $maxMonth;

            foreach ($dimensions as $key => $dimension) {
                $value = ($dimension['resolve'])($answers);
                $counts[$key][$value] = ($counts[$key][$value] ?? 0) + 1;
                $byMonth[$key][$month][$value] = ($byMonth[$key][$month][$value] ?? 0) + 1;
            }

            $enteredMexico = $this->date($answers[self::QUESTION_MEXICO_ENTRY_DATE] ?? null);
            $attention = $this->date($answers[$mapping['attentionDate'] ?? ''] ?? null);

            if ($enteredMexico === null || $attention === null) {
                $daysMissing++;
            } elseif ($attention->lt($enteredMexico)) {
                $daysInconsistent++;
            } else {
                $days[] = (int) $enteredMexico->diffInDays($attention);
            }
        }

        $months = $this->monthRange($from, $to, $minMonth, $maxMonth);

        return [
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'dateBasis' => 'attentionDate',
            ],
            'total' => $total,
            'arrivalsByMonth' => array_map(
                fn (string $month): array => ['month' => $month, 'count' => $arrivals[$month] ?? 0],
                $months,
            ),
            'dimensions' => collect($dimensions)->map(fn (array $dimension, string $key): array => [
                'label' => $dimension['label'],
                'ordered' => $dimension['ordered'],
                'categories' => $this->categories($counts[$key], $dimension['order'], $dimension['ordered']),
                'byMonth' => array_map(
                    fn (string $month): array => ['month' => $month, 'counts' => $byMonth[$key][$month] ?? (object) []],
                    $months,
                ),
            ])->all(),
            'mexicoToMonterrey' => $this->daysSummary($days, $daysMissing, $daysInconsistent),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $mapping
     * @return array<string, array{label: string, ordered: bool, order: list<string>, resolve: callable}>
     */
    private function dimensionDefinitions(array $definition, array $mapping): array
    {
        $choiceOrder = fn (?string $questionId): array => collect($definition['questions'] ?? [])
            ->firstWhere('id', $questionId)['choices'] ?? [];
        $values = fn (?string $questionId): array => array_values(array_filter(array_map(
            fn (array $choice): ?string => is_string($choice['value'] ?? null) ? $choice['value'] : null,
            $choiceOrder($questionId),
        )));
        $single = fn (?string $questionId): callable => fn (array $answers): string => $this->choice($answers[$questionId ?? ''] ?? null);

        return [
            'countryOfOrigin' => [
                'label' => 'Nacionalidad (país de origen)',
                'ordered' => false,
                'order' => [],
                'resolve' => $single($mapping['countryOfOrigin'] ?? null),
            ],
            'education' => [
                'label' => 'Nivel educativo',
                'ordered' => true,
                'order' => self::EDUCATION_ORDER,
                'resolve' => function (array $answers): string {
                    $adult = $this->choice($answers[self::QUESTION_EDUCATION_ADULT] ?? null);

                    return $adult !== self::MISSING ? $adult : $this->choice($answers[self::QUESTION_EDUCATION_MINOR] ?? null);
                },
            ],
            'gender' => [
                'label' => 'Género',
                'ordered' => false,
                'order' => $values($mapping['gender'] ?? null),
                'resolve' => $single($mapping['gender'] ?? null),
            ],
            'literacy' => [
                'label' => 'Sabe leer y escribir',
                'ordered' => true,
                'order' => $values(self::QUESTION_LITERACY),
                'resolve' => $single(self::QUESTION_LITERACY),
            ],
            'finalDestination' => [
                'label' => 'Destino final',
                'ordered' => false,
                'order' => $values(self::QUESTION_FINAL_DESTINATION),
                'resolve' => fn (array $answers): string => rtrim($this->choice($answers[self::QUESTION_FINAL_DESTINATION] ?? null), '.'),
            ],
            'civilStatus' => [
                'label' => 'Estado civil',
                'ordered' => false,
                'order' => $values($mapping['civilStatus'] ?? null),
                'resolve' => $single($mapping['civilStatus'] ?? null),
            ],
        ];
    }

    /**
     * Ordered dimensions keep their scale order (missing last); the rest sort by count.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $order
     * @return list<array{value: string, count: int}>
     */
    private function categories(array $counts, array $order, bool $ordered): array
    {
        $order = array_map(fn (string $value): string => rtrim($value, '.'), $order);
        $rows = [];

        foreach ($counts as $value => $count) {
            $rows[] = ['value' => (string) $value, 'count' => $count];
        }

        usort($rows, function (array $a, array $b) use ($order, $ordered): int {
            if ($a['value'] === self::MISSING || $b['value'] === self::MISSING) {
                return $a['value'] === self::MISSING ? 1 : -1;
            }

            if ($ordered) {
                $ia = array_search($a['value'], $order, true);
                $ib = array_search($b['value'], $order, true);

                return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
            }

            return [$b['count'], $a['value']] <=> [$a['count'], $b['value']];
        });

        return $rows;
    }

    /**
     * @param  list<int>  $days
     * @return array<string, mixed>
     */
    private function daysSummary(array $days, int $missing, int $inconsistent): array
    {
        sort($days);

        return [
            'count' => count($days),
            'missingDates' => $missing,
            'inconsistentDates' => $inconsistent,
            'median' => $this->percentile($days, 0.5),
            'p25' => $this->percentile($days, 0.25),
            'p75' => $this->percentile($days, 0.75),
            'bins' => array_map(fn (array $bin): array => [
                ...$bin,
                'count' => count(array_filter(
                    $days,
                    fn (int $value): bool => $value >= $bin['min'] && ($bin['max'] === null || $value <= $bin['max']),
                )),
            ], self::DAY_BINS),
        ];
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, float $p): ?float
    {
        if ($sorted === []) {
            return null;
        }

        $index = ($p * (count($sorted) - 1));
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return round($sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower), 1);
    }

    /**
     * Continuous months so gaps read as zero, not as missing columns.
     *
     * @return list<string>
     */
    private function monthRange(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $minMonth, ?string $maxMonth): array
    {
        $start = $from?->startOfMonth() ?? ($minMonth ? CarbonImmutable::createFromFormat('!Y-m', $minMonth) : null);
        $end = $to?->startOfMonth() ?? ($maxMonth ? CarbonImmutable::createFromFormat('!Y-m', $maxMonth) : null);

        if ($start === null || $end === null || $start->gt($end)) {
            return [];
        }

        $months = [];

        for ($cursor = $start; $cursor->lte($end) && count($months) < 240; $cursor = $cursor->addMonth()) {
            $months[] = $cursor->format('Y-m');
        }

        return $months;
    }

    /** @return array<string, mixed> */
    private function answers(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (! is_array(data_get($payload, 'questionnaire.answers'))) {
            $payload = $this->definitionService->upgradeLegacyPayload($payload);
        }

        $answers = data_get($payload, 'questionnaire.answers');

        return is_array($answers) ? $answers : [];
    }

    private function choice(mixed $answer): string
    {
        $value = is_array($answer) ? ($answer['value'] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : self::MISSING;
    }

    private function date(mixed $answer): ?CarbonImmutable
    {
        $value = is_array($answer) ? ($answer['value'] ?? null) : null;

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
