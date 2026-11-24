<?php

namespace App\Services\Registry;

/**
 * Flattens a statistics summary into one long ("tidy") CSV table: Sección, Mes, Categoría, Personas, Porcentaje.
 * One table is easier to filter and pivot in Excel or Sheets than several stacked blocks with different headers.
 * Only aggregate counts leave the system, never individual records.
 */
class MigrantRegistryStatisticsCsv
{
    public const HEADERS = ['Sección', 'Mes', 'Categoría', 'Personas', 'Porcentaje'];

    /** @param array<string, mixed> $summary */
    public function build(array $summary): string
    {
        $total = (int) $summary['total'];
        $rows = [];
        $range = $summary['range'];

        foreach ([
            'Desde' => $range['from'] ?? 'Todo',
            'Hasta' => $range['to'] ?? 'Todo',
            'Edad mínima' => $range['ageMin'] ?? 'Sin límite',
            'Edad máxima' => $range['ageMax'] ?? 'Sin límite',
            'Estancia' => match ($range['stay'] ?? null) {
                MigrantRegistryStatisticsService::STAY_IN_SHELTER => 'En albergue',
                MigrantRegistryStatisticsService::STAY_EXITED => 'Egresados',
                default => 'Todos',
            },
            'Sin edad registrada (excluidos)' => $range['ageUnknownExcluded'] ?? 0,
        ] as $label => $value) {
            $rows[] = ['Filtros', '', $label, (string) $value, ''];
        }

        $rows[] = ['Resumen', '', 'Personas que llegaron', $total, ''];

        if (isset($summary['shelterStay'])) {
            $stay = $summary['shelterStay'];
            $arrived = $stay['inShelter'] + $stay['exited'];
            $rows[] = ['Resumen', '', 'En albergue', $stay['inShelter'], $this->percent($stay['inShelter'], $arrived)];
            $rows[] = ['Resumen', '', 'Egresados', $stay['exited'], $this->percent($stay['exited'], $arrived)];
            $rows[] = ['Resumen', '', 'Estancia mediana (días)', $stay['medianDays'] ?? '', ''];
        }

        $days = $summary['mexicoToMonterrey'];
        $rows[] = ['Resumen', '', 'Mediana de días México → Monterrey', $days['median'] ?? '', ''];

        $exits = collect($summary['exitsByMonth'] ?? [])->pluck('count', 'month');

        foreach ($summary['arrivalsByMonth'] as $month) {
            $rows[] = ['Ingresos por mes', $month['month'], 'Llegadas', $month['count'], ''];
            $rows[] = ['Egresos por mes', $month['month'], 'Egresos', $exits[$month['month']] ?? 0, ''];
        }

        foreach ($summary['dimensions'] as $dimension) {
            foreach ($dimension['categories'] as $category) {
                $rows[] = [$dimension['label'], '', $category['value'], $category['count'], $this->percent($category['count'], $total)];
            }
        }

        $services = $summary['requestedServices'];

        foreach ($services['areas'] as $area) {
            $rows[] = ['Servicios por área', '', $area['label'], $area['count'], $this->percent($area['count'], $services['respondents'])];
        }

        foreach ($services['services'] as $service) {
            $rows[] = ['Servicios solicitados', '', $service['value'], $service['count'], $this->percent($service['count'], $services['respondents'])];
        }

        foreach ($days['bins'] as $bin) {
            $rows[] = ['Días de México a Monterrey', '', $bin['label'], $bin['count'], $this->percent($bin['count'], $days['count'])];
        }

        // UTF-8 BOM so Excel shows accents correctly.
        $csv = "\u{FEFF}".$this->line(self::HEADERS);

        foreach ($rows as $row) {
            $csv .= $this->line($row);
        }

        return $csv;
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
