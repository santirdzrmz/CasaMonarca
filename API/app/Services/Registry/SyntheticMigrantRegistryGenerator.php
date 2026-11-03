<?php

namespace App\Services\Registry;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds SYNTHETIC migrant registration payloads for development and demos.
 *
 * Every payload walks the real questionnaire graph (so branching is respected),
 * answers each reachable question, and is validated with the same "complete"
 * normalization a submitted registration goes through. Names, phone and notes are
 * visibly synthetic so these records can never be mistaken for real people.
 * Distributions are plausible but invented; they are not statistics about anyone.
 */
class SyntheticMigrantRegistryGenerator
{
    public const MARKER = 'DATO SINTÉTICO';

    private const Q = [
        'attention' => 'ra09736a581f046c8aea75da8c905a1b9',
        'firstName' => 'r4245b1a3a77049c7a2a36b44545ca343',
        'firstLastName' => 'rbc1165c31e964d2bb1e201598e7d4d1d',
        'secondLastName' => 'rdd8f1a5c8a8c4ec4ae4dbc15dc582676',
        'phone' => 'r641b990f039f4098ba8915c837a95225',
        'gender' => 'r8203ecd497f348c58e0f37ee52b0f281',
        'country' => 'r64febf36f78743768d7049e10743d53f',
        'civil' => 'rd7dd054b57a449bb8fc272fb5018b270',
        'birth' => 'r1a57098392944e63882969e6150cdd98',
        'age' => 'rce625b31357e425ea492b6a99bce67c5',
        'group' => 'rb3208d6f80234b5f911e7d8bbd3f0bb9',
        'travelling' => 'rf9ba2440b84d43a7ac77d3775e373403',
        'literacy' => 'rf233bf6dce04426e869ab22c29187923',
        'internalGroup' => 'r1b2e0a25dff648b5995aaf74dff57057',
        'eduAdult' => 'rc4c93ad0575541cc8285754e6d11d9b0',
        'eduMinor' => 'r65ec8fd31b8f421eb0d6c50bdd9115b3',
        'leftCountry' => 'rc935c79445494c0db9c43d3aa05e3389',
        'mexicoEntry' => 'rb5d53bd5db9045dd8fe0b1dc849acdf4',
        'destination' => 'r2bbdd2ae059d41b988ef4e55381fafde',
        'notes' => 'rf980f8d2f87b417eaec74038400b5276',
    ];

    private const GROUPS = [
        'Adulto (18-59 años)' => 70,
        'Adulto mayor (+60 años)' => 3,
        'Niña acompañada' => 8,
        'Niño acompañado' => 8,
        'Adolescente hombre acompañado' => 5,
        'Adolescente mujer acompañada' => 4,
        'NNA No acompañado' => 2,
    ];

    private const COUNTRIES = [
        'Honduras' => 30,
        'Venezuela (República Bolivariana de)' => 18,
        'Guatemala' => 14,
        'El Salvador' => 9,
        'Haití' => 7,
        'Cuba' => 5,
        'Colombia' => 4,
        'Nicaragua' => 3,
        'Ecuador' => 3,
        'México' => 2,
        'Perú' => 1,
        'Chile' => 1,
        'Angola' => 1,
        'Afganistán' => 1,
        'Camerún' => 1,
    ];

    private const CIVIL = [
        'Soltera / Soltero' => 55,
        'Unión Libre' => 22,
        'Casado / Casado' => 15,
        'Separada / Separado' => 4,
        'Divorciada / Divorciado' => 2,
        'Viuda / Viudo' => 2,
    ];

    private const EDU_ADULT = [
        'Sin escolarizar' => 6, 'Preescolar' => 1, 'Primaria' => 33, 'Secundaria' => 28,
        'Preparatoria' => 16, 'Bachillerato técnico' => 4, 'Licenciatura' => 10, 'Posgrado' => 2,
    ];

    private const DESTINATION = [
        'Estados Unidos.' => 60,
        'México.' => 35,
        'Regresar a mi país de origen.' => 5,
    ];

    private const FIRST_NAMES_F = ['Ana', 'Lucía', 'María', 'Rosa', 'Daniela', 'Carmen', 'Sofía', 'Valeria', 'Gabriela', 'Elena'];

    private const FIRST_NAMES_M = ['Luis', 'José', 'Carlos', 'Miguel', 'Jorge', 'Andrés', 'Pedro', 'Juan', 'David', 'Mateo'];

    /** @var array<string, array<string, mixed>> */
    private array $questions;

    private Randomizer $random;

    public function __construct(
        private readonly MigrantQuestionnaireDefinitionService $definitionService,
    ) {
        $this->questions = collect($this->definitionService->definition()['questions'] ?? [])->keyBy('id')->all();
        $this->random = new Randomizer;
    }

    public function seed(int $seed): void
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return array<string, mixed> A normalized, complete, schema-valid payload flagged as synthetic.
     */
    public function payload(int $sequence, CarbonImmutable $attention): array
    {
        $profile = $this->profile($sequence, $attention);
        $answers = [];
        $current = data_get($this->definitionService->definition(), 'questions.0.id');
        $visited = [];

        while (is_string($current) && ! isset($visited[$current]) && isset($this->questions[$current])) {
            $visited[$current] = true;
            $question = $this->questions[$current];
            $answer = $this->answer($question, $profile);
            $next = $question['defaultNext'] ?? ['kind' => 'end'];

            if ($answer !== null) {
                $answers[$current] = $answer;

                if (($question['type'] ?? null) === 'choice' && ! ($question['multipleSelection'] ?? false)) {
                    $choice = collect($question['choices'] ?? [])->firstWhere('value', $answer['value']);
                    $next = is_array($choice) ? ($choice['next'] ?? $next) : $next;
                }
            }

            $current = ($next['kind'] ?? null) === 'question' ? ($next['questionId'] ?? null) : null;
        }

        $normalized = $this->definitionService->normalizePayload([
            'schemaVersion' => 2,
            'questionnaire' => [
                'definitionId' => MigrantQuestionnaireDefinitionService::DEFINITION_ID,
                'answers' => $answers,
            ],
        ], true);

        return [...$normalized, 'synthetic' => ['generator' => 'registry:synthetic', 'version' => 1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(int $sequence, CarbonImmutable $attention): array
    {
        $group = $this->weighted(self::GROUPS);
        [$minYears, $maxYears] = match ($group) {
            'Adulto mayor (+60 años)' => [60, 76],
            'Niña acompañada', 'Niño acompañado' => [0, 11],
            'Adolescente hombre acompañado', 'Adolescente mujer acompañada' => [12, 17],
            'NNA No acompañado' => [13, 17],
            default => [18, 59],
        };
        $years = $group === 'Adulto (18-59 años)'
            ? min(59, 18 + (int) round(abs($this->normal(0, 11))))
            : $this->random->getInt($minYears, $maxYears);
        $birth = $attention->subYears($years)->subDays($this->random->getInt(0, 300));
        $isMinor = $years < 18;

        $gender = match ($group) {
            'Niña acompañada', 'Adolescente mujer acompañada' => 'Femenino',
            'Niño acompañado', 'Adolescente hombre acompañado' => 'Masculino',
            default => $this->weighted(['Masculino' => 54, 'Femenino' => 40, 'LGBTIQ+' => 4, 'No binario' => 2]),
        };

        // Days in Mexico before reaching Casa Monarca: right-skewed, median ~3 weeks.
        $daysInMexico = (int) min(540, max(0, round(exp($this->normal(3.0, 1.05)))));
        $mexicoEntry = $attention->subDays($daysInMexico);
        $leftCountry = $mexicoEntry->subDays($this->random->getInt(3, 75));

        $firstNames = $gender === 'Femenino' ? self::FIRST_NAMES_F : self::FIRST_NAMES_M;

        return [
            'sequence' => $sequence,
            'attention' => $attention,
            'birth' => $birth,
            'years' => $years,
            'isMinor' => $isMinor,
            'group' => $group,
            'gender' => $gender,
            'firstName' => $firstNames[$this->random->getInt(0, count($firstNames) - 1)],
            'country' => $this->weighted(self::COUNTRIES),
            'civil' => $isMinor ? 'Soltera / Soltero' : $this->weighted(self::CIVIL),
            'literacy' => $years < 6 ? 'No' : ($this->random->getFloat(0, 1) < ($isMinor ? 0.9 : 0.87) ? 'Sí' : 'No'),
            'eduAdult' => $this->weighted(self::EDU_ADULT),
            'eduMinor' => match (true) {
                $years < 4 => 'Sin escolarizar',
                $years < 6 => 'Preescolar',
                $years < 12 => 'Primaria',
                $years < 15 => $this->weighted(['Secundaria' => 80, 'Primaria' => 20]),
                default => $this->weighted(['Preparatoria' => 50, 'Secundaria' => 40, 'Bachillerato técnico' => 10]),
            },
            'mexicoEntry' => $mexicoEntry,
            'leftCountry' => $leftCountry,
            'destination' => $this->weighted(self::DESTINATION),
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $p
     * @return array{value: mixed, otherText?: string}|null
     */
    private function answer(array $question, array $p): ?array
    {
        $id = $question['id'];
        $date = fn (CarbonImmutable $value): array => ['value' => $value->format('Y-m-d')];

        $fixed = match ($id) {
            self::Q['attention'] => $date($p['attention']),
            self::Q['birth'] => $date($p['birth']),
            self::Q['mexicoEntry'] => $date($p['mexicoEntry']),
            self::Q['leftCountry'] => $date($p['leftCountry']),
            self::Q['firstName'] => ['value' => $p['firstName']],
            self::Q['firstLastName'] => ['value' => 'Sintético'],
            self::Q['secondLastName'] => ['value' => sprintf('Prueba-%04d', $p['sequence'])],
            self::Q['phone'] => ['value' => '0000000000'],
            self::Q['notes'] => ['value' => self::MARKER.': registro generado por registry:synthetic. No corresponde a una persona real.'],
            self::Q['gender'] => ['value' => $p['gender']],
            self::Q['country'] => ['value' => $p['country']],
            self::Q['civil'] => ['value' => $p['civil']],
            self::Q['age'] => ['value' => $p['years'] === 0 ? '0 - 11 meses' : (string) min(90, $p['years'])],
            self::Q['group'] => ['value' => $p['group']],
            self::Q['travelling'] => ['value' => $this->weighted(['Sola/o' => 55, 'Acompañada/o' => 45])],
            self::Q['literacy'] => ['value' => $p['literacy']],
            self::Q['internalGroup'] => ['value' => $p['isMinor'] ? 'Niña, niño o adolescente (18-)' : 'Adulto (18+)'],
            self::Q['eduAdult'] => ['value' => $p['eduAdult']],
            self::Q['eduMinor'] => ['value' => $p['eduMinor']],
            self::Q['destination'] => ['value' => $p['destination']],
            default => null,
        };

        if ($fixed !== null) {
            return $this->ensureValid($question, $fixed);
        }

        return match ($question['type'] ?? null) {
            'choice' => $this->randomChoice($question),
            'date' => $date($p['attention']->subDays($this->random->getInt(0, 30))),
            default => ['value' => ($question['numeric'] ?? false)
                ? (string) $this->random->getInt(1, 4)
                : self::MARKER],
        };
    }

    /**
     * Fall back to a random valid option if a preferred value is not offered by the
     * active questionnaire (keeps the generator working if options are renamed).
     *
     * @param  array<string, mixed>  $question
     * @param  array{value: mixed}  $answer
     * @return array{value: mixed, otherText?: string}
     */
    private function ensureValid(array $question, array $answer): array
    {
        if (($question['type'] ?? null) !== 'choice') {
            return $answer;
        }

        $values = collect($question['choices'] ?? [])->pluck('value')->all();

        return in_array($answer['value'], $values, true) ? $answer : ($this->randomChoice($question) ?? $answer);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{value: mixed}|null
     */
    private function randomChoice(array $question): ?array
    {
        $values = collect($question['choices'] ?? [])
            ->reject(fn (array $choice): bool => (bool) ($choice['custom'] ?? false))
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        if ($values === []) {
            return null;
        }

        if ($question['multipleSelection'] ?? false) {
            $picked = $this->random->pickArrayKeys($values, min(count($values), $this->random->getInt(1, 2)));

            return ['value' => array_map(fn (int $key): string => $values[$key], $picked)];
        }

        return ['value' => $values[$this->random->getInt(0, count($values) - 1)]];
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $roll = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    private function normal(float $mean, float $deviation): float
    {
        $u = max(1e-9, $this->random->getFloat(0, 1));
        $v = $this->random->getFloat(0, 1);

        return $mean + $deviation * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
