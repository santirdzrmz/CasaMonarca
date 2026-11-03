import { useEffect, useMemo, useState } from 'react'

import { BarList, type BarListRow } from '../../components/charts/BarList'
import { ColumnChart, type ColumnSeries } from '../../components/charts/ColumnChart'
import { DataTable } from '../../components/charts/DataTable'
import { formatCount, formatMonth, formatPercent } from '../../components/charts/chartFormat'
import '../../components/charts/charts.css'
import { ApiRequestError } from '../../lib/api'
import type { AuthenticatedUser } from '../../lib/auth'
import { translate as t } from '../../lib/i18n'
import {
  getRegistryStatistics,
  MISSING_ANSWER,
  type RegistryStatistics,
  type StatisticsCategory,
  type StatisticsDimension,
  type StatisticsDimensionKey,
} from '../../lib/registryStatistics'

type MigrantStatisticsPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type PresetId = 'last30' | 'last90' | 'last365' | 'all' | 'custom'

type Range = { from: string | null; to: string | null }

const PRESETS: { id: PresetId; label: string }[] = [
  { id: 'last30', label: t('Last 30 days', 'Últimos 30 días') },
  { id: 'last90', label: t('Last 3 months', 'Últimos 3 meses') },
  { id: 'last365', label: t('Last 12 months', 'Últimos 12 meses') },
  { id: 'all', label: t('All time', 'Todo') },
  { id: 'custom', label: t('Custom', 'Personalizado') },
]

const isoDate = (date: Date) => {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)

  return local.toISOString().slice(0, 10)
}

const presetRange = (preset: Exclude<PresetId, 'custom'>): Range => {
  if (preset === 'all') {
    return { from: null, to: null }
  }

  const days = { last30: 30, last90: 90, last365: 365 }[preset]
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - (days - 1))

  return { from: isoDate(from), to: isoDate(to) }
}

/**
 * Breakdowns use fixed category orders so a category keeps its color when the date
 * range changes (color follows the entity, never its rank). Only dimensions with at
 * most four known categories are offered; anything else folds into the gray bucket.
 */
const STACK_DIMENSIONS: { key: StatisticsDimensionKey; label: string; order: string[] }[] = [
  { key: 'gender', label: t('Gender', 'Género'), order: ['Femenino', 'Masculino', 'No binario', 'LGBTIQ+'] },
  { key: 'finalDestination', label: t('Final destination', 'Destino final'), order: ['México', 'Estados Unidos', 'Regresar a mi país de origen'] },
  { key: 'literacy', label: t('Reads and writes', 'Sabe leer y escribir'), order: ['Sí', 'No'] },
]

const SERIES_COLORS = ['var(--viz-series-1)', 'var(--viz-series-2)', 'var(--viz-series-3)', 'var(--viz-series-4)']
const OTHER_KEY = '__other__'
const TOP_COUNTRIES = 10

const toRows = (categories: StatisticsCategory[]): BarListRow[] =>
  categories.map((category) => ({
    count: category.count,
    label: category.value,
    muted: category.value === MISSING_ANSWER,
  }))

const countryRows = (categories: StatisticsCategory[]): BarListRow[] => {
  const answered = categories.filter((category) => category.value !== MISSING_ANSWER)
  const missing = categories.find((category) => category.value === MISSING_ANSWER)
  const rows = toRows(answered.slice(0, TOP_COUNTRIES))
  const rest = answered.slice(TOP_COUNTRIES)

  if (rest.length > 0) {
    rows.push({
      count: rest.reduce((sum, category) => sum + category.count, 0),
      label: t(`Other countries (${rest.length})`, `Otros países (${rest.length})`),
      muted: true,
    })
  }

  if (missing) {
    rows.push({ count: missing.count, label: missing.value, muted: true })
  }

  return rows
}

const categoryTable = (dimension: StatisticsDimension, total: number) => ({
  caption: dimension.label,
  columns: [dimension.label, t('People', 'Personas'), t('Share', 'Porcentaje')],
  rows: dimension.categories.map((category) => [
    category.value,
    formatCount(category.count),
    formatPercent(category.count, total),
  ]),
})

export function MigrantStatisticsPage({ onSessionExpired }: MigrantStatisticsPageProps) {
  const [preset, setPreset] = useState<PresetId>('last365')
  const [range, setRange] = useState<Range>(() => presetRange('last365'))
  const [customRange, setCustomRange] = useState<Range>(() => presetRange('last90'))
  const [stackKey, setStackKey] = useState<StatisticsDimensionKey>('gender')
  const [data, setData] = useState<RegistryStatistics | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let active = true

    getRegistryStatistics(range)
      .then((response) => {
        if (active) {
          setData(response)
          setError(null)
        }
      })
      .catch((loadError) => {
        if (!active) {
          return
        }

        if (loadError instanceof ApiRequestError && loadError.status === 401) {
          onSessionExpired?.()
          return
        }

        setError(loadError instanceof Error ? loadError.message : t('Statistics could not be loaded.', 'No se pudieron cargar las estadísticas.'))
      })
      .finally(() => {
        if (active) {
          setLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [onSessionExpired, range])

  const changeRange = (next: Range) => {
    setLoading(true)
    setRange(next)
  }

  const selectPreset = (id: PresetId) => {
    setPreset(id)
    changeRange(id === 'custom' ? customRange : presetRange(id))
  }

  const updateCustom = (next: Range) => {
    setCustomRange(next)

    if (next.from && next.to && next.from <= next.to) {
      changeRange(next)
    }
  }

  const stack = STACK_DIMENSIONS.find((item) => item.key === stackKey) ?? STACK_DIMENSIONS[0]

  const stackSeries: ColumnSeries[] = useMemo(() => [
    ...stack.order.map((value, index) => ({ color: SERIES_COLORS[index], key: value, label: value })),
    { color: 'var(--viz-muted-series)', key: OTHER_KEY, label: t('No answer / other', 'Sin respuesta / otro') },
  ], [stack])

  const stackData = useMemo(() => (data?.dimensions[stack.key].byMonth ?? []).map((month) => {
    const values: Record<string, number> = {}

    for (const [value, count] of Object.entries(month.counts)) {
      const key = stack.order.includes(value) ? value : OTHER_KEY
      values[key] = (values[key] ?? 0) + count
    }

    return { key: month.month, label: formatMonth(month.month), title: formatMonth(month.month, 'long'), values }
  }), [data, stack])

  if (!data) {
    return (
      <section className="workspace-stack viz-root">
        <section className="workspace-panel">
          {error ? (
            <div className="login-feedback login-feedback--error">{error}</div>
          ) : (
            <p className="viz-empty">{loading ? t('Loading statistics…', 'Cargando estadísticas…') : null}</p>
          )}
        </section>
      </section>
    )
  }

  const { dimensions, mexicoToMonterrey: days, total } = data
  const literacy = dimensions.literacy.categories
  const literacyYes = literacy.find((category) => category.value === 'Sí')?.count ?? 0
  const literacyAnswered = literacy
    .filter((category) => category.value !== MISSING_ANSWER)
    .reduce((sum, category) => sum + category.count, 0)
  const topDestination = dimensions.finalDestination.categories.find((category) => category.value !== MISSING_ANSWER)
  const arrivals = data.arrivalsByMonth.map((month) => ({
    key: month.month,
    label: formatMonth(month.month),
    title: formatMonth(month.month, 'long'),
    values: { count: month.count },
  }))
  const dayBins = days.bins.map((bin) => ({ key: bin.label, label: bin.label, values: { count: bin.count } }))

  const categoryCards: { key: StatisticsDimensionKey; rows: BarListRow[]; subtitle?: string }[] = [
    {
      key: 'countryOfOrigin',
      rows: countryRows(dimensions.countryOfOrigin.categories),
      subtitle: t(`Top ${TOP_COUNTRIES}; the rest are grouped.`, `Los ${TOP_COUNTRIES} principales; el resto se agrupa.`),
    },
    {
      key: 'education',
      rows: toRows(dimensions.education.categories),
      subtitle: t('Adults and minors combined, from no schooling to postgraduate.', 'Adultos y menores juntos, de sin escolarizar a posgrado.'),
    },
    { key: 'gender', rows: toRows(dimensions.gender.categories) },
    { key: 'civilStatus', rows: toRows(dimensions.civilStatus.categories) },
    { key: 'finalDestination', rows: toRows(dimensions.finalDestination.categories) },
    { key: 'literacy', rows: toRows(dimensions.literacy.categories) },
  ]

  return (
    <section className="workspace-stack viz-root">
      <section className="workspace-panel">
        <div className="viz-filters" aria-label={t('Statistics filters', 'Filtros de estadísticas')}>
          <div className="viz-filters__group">
            <span className="viz-filters__label">{t('Attention date', 'Fecha de atención')}</span>
            <div className="viz-presets" role="group" aria-label={t('Date range', 'Rango de fechas')}>
              {PRESETS.map((item) => (
                <button
                  aria-pressed={preset === item.id}
                  key={item.id}
                  onClick={() => selectPreset(item.id)}
                  type="button"
                >
                  {preset === item.id ? <span aria-hidden="true" className="viz-presets__check">✓</span> : null}
                  {item.label}
                </button>
              ))}
            </div>
          </div>

          {preset === 'custom' ? (
            <div className="viz-filters__group">
              <span className="viz-filters__label">{t('From – to', 'Desde – hasta')}</span>
              <div className="viz-filters__range">
                <input
                  aria-label={t('From', 'Desde')}
                  max={customRange.to ?? undefined}
                  onChange={(event) => updateCustom({ ...customRange, from: event.target.value || null })}
                  type="date"
                  value={customRange.from ?? ''}
                />
                <span aria-hidden="true">–</span>
                <input
                  aria-label={t('To', 'Hasta')}
                  min={customRange.from ?? undefined}
                  onChange={(event) => updateCustom({ ...customRange, to: event.target.value || null })}
                  type="date"
                  value={customRange.to ?? ''}
                />
              </div>
            </div>
          ) : null}

          <label className="viz-filters__group">
            <span className="viz-filters__label">{t('Monthly breakdown by', 'Desglose mensual por')}</span>
            <select onChange={(event) => setStackKey(event.target.value as StatisticsDimensionKey)} value={stackKey}>
              {STACK_DIMENSIONS.map((item) => (
                <option key={item.key} value={item.key}>{item.label}</option>
              ))}
            </select>
          </label>
        </div>
      </section>

      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

      <div aria-busy={loading} className={`viz-body${loading ? ' viz-body--refreshing' : ''}`}>
        <section className="workspace-panel viz-kpis" aria-label={t('Summary', 'Resumen')}>
          <div className="viz-tile">
            <p className="viz-tile__label">{t('People who arrived', 'Personas que llegaron')}</p>
            <p className="viz-tile__value viz-tile__value--hero">{formatCount(total)}</p>
            <p className="viz-tile__note">{t('Approved registrations in the period.', 'Registros aprobados en el periodo.')}</p>
          </div>
          <div className="viz-tile">
            <p className="viz-tile__label">{t('Median days from entering Mexico to Monterrey', 'Mediana de días de México a Monterrey')}</p>
            <p className="viz-tile__value">
              {days.median === null ? '—' : t(`${formatCount(days.median)} days`, `${formatCount(days.median)} días`)}
            </p>
            <p className="viz-tile__note">
              {days.median === null
                ? t('No records with both dates.', 'Sin registros con ambas fechas.')
                : t(
                  `Half took between ${formatCount(days.p25 ?? 0)} and ${formatCount(days.p75 ?? 0)} days · ${formatCount(days.count)} records`,
                  `La mitad tardó entre ${formatCount(days.p25 ?? 0)} y ${formatCount(days.p75 ?? 0)} días · ${formatCount(days.count)} registros`,
                )}
            </p>
          </div>
          <div className="viz-tile">
            <p className="viz-tile__label">{t('Can read and write', 'Saben leer y escribir')}</p>
            <p className="viz-tile__value">{formatPercent(literacyYes, literacyAnswered)}</p>
            <div aria-hidden="true" className="viz-meter">
              <div className="viz-meter__fill" style={{ width: literacyAnswered > 0 ? `${(literacyYes / literacyAnswered) * 100}%` : 0 }} />
            </div>
            <p className="viz-tile__note">
              {t(`${formatCount(literacyYes)} of ${formatCount(literacyAnswered)} who answered`, `${formatCount(literacyYes)} de ${formatCount(literacyAnswered)} que respondieron`)}
            </p>
          </div>
          <div className="viz-tile">
            <p className="viz-tile__label">{t('Most common final destination', 'Destino final más común')}</p>
            <p className="viz-tile__value">{topDestination?.value ?? '—'}</p>
            <p className="viz-tile__note">
              {topDestination ? t(`${formatPercent(topDestination.count, total)} of arrivals`, `${formatPercent(topDestination.count, total)} de las llegadas`) : null}
            </p>
          </div>
        </section>

        {total === 0 ? (
          <section className="workspace-panel">
            <p className="viz-empty">
              {t('No approved registrations in this period. Try a wider date range.', 'No hay registros aprobados en este periodo. Prueba con un rango de fechas más amplio.')}
            </p>
          </section>
        ) : (
          <>
            <section className="workspace-panel viz-card">
              <div>
                <h2 className="viz-card__title">{t('Arrivals per month', 'Llegadas por mes')}</h2>
                <p className="viz-card__subtitle">{t('By attention date at Casa Monarca.', 'Según la fecha de atención en Casa Monarca.')}</p>
              </div>
              <ColumnChart
                ariaLabel={t('Arrivals per month', 'Llegadas por mes')}
                data={arrivals}
                series={[{ color: 'var(--viz-series-1)', key: 'count', label: t('arrivals', 'llegadas') }]}
              />
              <DataTable
                caption={t('Arrivals per month', 'Llegadas por mes')}
                columns={[t('Month', 'Mes'), t('Arrivals', 'Llegadas')]}
                rows={data.arrivalsByMonth.map((month) => [formatMonth(month.month, 'long'), formatCount(month.count)])}
              />
            </section>

            <section className="workspace-panel viz-card">
              <div>
                <h2 className="viz-card__title">{t(`Arrivals per month by ${stack.label.toLowerCase()}`, `Llegadas por mes según ${stack.label.toLowerCase()}`)}</h2>
                <p className="viz-card__subtitle">{t('Change the breakdown in the filters above.', 'Cambia el desglose en los filtros de arriba.')}</p>
              </div>
              <ColumnChart
                ariaLabel={t(`Arrivals per month by ${stack.label}`, `Llegadas por mes según ${stack.label}`)}
                data={stackData}
                series={stackSeries}
              />
              <DataTable
                caption={stack.label}
                columns={[t('Month', 'Mes'), ...stackSeries.map((item) => item.label)]}
                rows={stackData.map((month) => [
                  month.title,
                  ...stackSeries.map((item) => formatCount(month.values[item.key] ?? 0)),
                ])}
              />
            </section>

            <section className="workspace-panel viz-card">
              <div>
                <h2 className="viz-card__title">{t('Time from entering Mexico to arriving in Monterrey', 'Tiempo desde que entró a México hasta llegar a Monterrey')}</h2>
                <p className="viz-card__subtitle">
                  {t(
                    `Days between "Date entered Mexico" and the attention date. Excluded: ${formatCount(days.missingDates)} without a date, ${formatCount(days.inconsistentDates)} with the attention date before entering Mexico.`,
                    `Días entre "Fecha en que ingresó a México" y la fecha de atención. Excluidos: ${formatCount(days.missingDates)} sin fecha y ${formatCount(days.inconsistentDates)} con la fecha de atención antes del ingreso a México.`,
                  )}
                </p>
              </div>
              <ColumnChart
                ariaLabel={t('Days from entering Mexico to Monterrey', 'Días de México a Monterrey')}
                data={dayBins}
                series={[{ color: 'var(--viz-series-1)', key: 'count', label: t('people', 'personas') }]}
              />
              <DataTable
                caption={t('Days from entering Mexico to Monterrey', 'Días de México a Monterrey')}
                columns={[t('Time', 'Tiempo'), t('People', 'Personas')]}
                rows={days.bins.map((bin) => [bin.label, formatCount(bin.count)])}
              />
            </section>

            <div className="viz-cards">
              {categoryCards.map((card) => {
                const dimension = dimensions[card.key]
                const table = categoryTable(dimension, total)

                return (
                  <section className="workspace-panel viz-card" key={card.key}>
                    <div>
                      <h2 className="viz-card__title">{dimension.label}</h2>
                      {card.subtitle ? <p className="viz-card__subtitle">{card.subtitle}</p> : null}
                    </div>
                    <BarList ariaLabel={dimension.label} rows={card.rows} total={total} />
                    <DataTable caption={table.caption} columns={table.columns} rows={table.rows} />
                  </section>
                )
              })}
            </div>
          </>
        )}
      </div>
    </section>
  )
}
