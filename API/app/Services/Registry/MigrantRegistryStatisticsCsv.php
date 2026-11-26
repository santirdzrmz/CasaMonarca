<?php

namespace App\Services\Registry;

/**
 * Lays a statistics summary out as side-by-side column blocks, the way a person reads a report in Excel or Sheets:
 * row 1 names each block (Filtros, Resumen, Por mes, Género, ...), row 2 holds its column headers and the data runs
 * down underneath, with an empty column between blocks. Only aggregate counts leave the system, never records.
 */
class MigrantRegistryStatisticsCsv
{
    /** @param array<string, mixed> $summary */
    public function build(array $summary): string
    {
        // UTF-8 BOM so Excel shows accents correctly.
        $csv = "\u{FEFF}";

        foreach ($this->grid($this->blocks($summary)) as $row) {
            $csv .= $this->line($row);
        }

        return $csv;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function blocks(array $summary): array
    {
        $total = (int) $summary['total'];
        $range = $summary['range'];
        $days = $summary['mexicoToMonterrey'];
        $services = $summary['requestedServices'];

        $filters = [
            ['Desde', $range['from'] ?? 'Todo'],
            ['Hasta', $range['to'] ?? 'Todo'],
            ['Edad mínima', $range['ageMin'] ?? 'Sin límite'],
            ['Edad máxima', $range['ageMax'] ?? 'Sin límite'],
            ['Estancia', match ($range['stay'] ?? null) {
                MigrantRegistryStatisticsService::STAY_IN_SHELTER => 'En albergue',
                MigrantRegistryStatisticsService::STAY_EXITED => 'Egresados',
                default => 'Todos',
            }],
            ['Sin edad registrada (excluidos)', $range['ageUnknownExcluded'] ?? 0],
        ];

        $overview = [['Personas que llegaron', $total, '']];

        if (isset($summary['shelterStay'])) {
            $stay = $summary['shelterStay'];
            $arrived = $stay['inShelter'] + $stay['exited'];
            $overview[] = ['En albergue', $stay['inShelter'], $this->percent($stay['inShelter'], $arrived)];
            $overview[] = ['Egresados', $stay['exited'], $this->percent($stay['exited'], $arrived)];
            $overview[] = ['Estancia mediana (días)', $stay['medianDays'] ?? '', ''];
        }

        $overview[] = ['Mediana de días México → Monterrey', $days['median'] ?? '', ''];

        $exits = collect($summary['exitsByMonth'] ?? [])->pluck('count', 'month');
        $months = array_map(
            fn (array $month): array => [$month['month'], $month['count'], $exits[$month['month']] ?? 0],
            $summary['arrivalsByMonth'],
        );

        $blocks = [
            ['title' => 'Filtros', 'headers' => ['Filtro', 'Valor'], 'rows' => $filters],
            ['title' => 'Resumen', 'headers' => ['Indicador', 'Valor', '%'], 'rows' => $overview],
            ['title' => 'Por mes', 'headers' => ['Mes', 'Ingresos', 'Egresos'], 'rows' => $months],
        ];

        foreach ($summary['dimensions'] as $dimension) {
            $blocks[] = $this->counts($dimension['label'], $dimension['label'], $dimension['categories'], 'value', $total);
        }

        $blocks[] = $this->counts('Servicios por área', 'Área', $services['areas'], 'label', $services['respondents']);
        $blocks[] = $this->counts('Servicios solicitados', 'Servicio', $services['services'], 'value', $services['respondents']);
        $blocks[] = $this->counts('Días de México a Monterrey', 'Días', $days['bins'], 'label', $days['count']);

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function counts(string $title, string $header, array $items, string $labelKey, int|float $base): array
    {
        return [
            'title' => $title,
            'headers' => [$header, 'Personas', '%'],
            'rows' => array_map(
                fn (array $item): array => [$item[$labelKey], $item['count'], $this->percent($item['count'], $base)],
                $items,
            ),
        ];
    }

    /**
     * Places the blocks next to each other: title row, header row, then data rows. Shorter blocks are padded with
     * empty cells so every row has the same width.
     *
     * @param  list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>  $blocks
     * @return list<list<mixed>>
     */
    private function grid(array $blocks): array
    {
        $height = 2 + max(array_map(fn (array $block): int => count($block['rows']), $blocks));
        $grid = array_fill(0, $height, []);

        foreach ($blocks as $index => $block) {
            $width = count($block['headers']);
            $column = [
                array_pad([$block['title']], $width, ''),
                $block['headers'],
                ...array_map(fn (array $row): array => array_pad($row, $width, ''), $block['rows']),
            ];

            for ($y = 0; $y < $height; $y++) {
                if ($index > 0) {
                    $grid[$y][] = '';
                }
                array_push($grid[$y], ...($column[$y] ?? array_fill(0, $width, '')));
            }
        }

        return $grid;
    }

    private function percent(int|float $count, int|float $base): string
    {
        return $base > 0 ? number_format($count / $base * 100, 1, '.', '') : '';
    }

    /** @param list<mixed> $cells */
    private function line(array $cells): string
    {
        return implode(',', array_map(fn (mixed $cell): string => $this->cell((string) $cell), $cells))."\r\n";
    }

    /**
     * Quotes when needed and neutralises spreadsheet formulas (CSV injection): a text cell that starts with
     * = + - @ or a control character gets a leading apostrophe. Plain numbers are left alone.
     */
    private function cell(string $value): string
    {
        if ($value !== '' && ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'".$value;
        }

        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
