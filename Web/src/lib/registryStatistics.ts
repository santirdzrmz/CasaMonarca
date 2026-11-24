import { apiFetch, ApiRequestError, buildApiUrl } from './api'

export type StatisticsCategory = {
  count: number
  value: string
}

export type StatisticsMonthCounts = {
  counts: Record<string, number>
  month: string
}

export type StatisticsDimension = {
  byMonth: StatisticsMonthCounts[]
  categories: StatisticsCategory[]
  label: string
  ordered: boolean
}

export type StatisticsDimensionKey =
  | 'countryOfOrigin'
  | 'education'
  | 'gender'
  | 'literacy'
  | 'finalDestination'
  | 'civilStatus'

export type StatisticsDayBin = {
  count: number
  label: string
  max: number | null
  min: number
}

export type ServiceAreaKey = 'humanitarian' | 'legal' | 'psychosocial'

export type RequestedServices = {
  /** People who asked for at least one service in each area (counted once per area). */
  areas: { count: number; key: ServiceAreaKey; label: string; services: string[] }[]
  areasByMonth: { counts: Partial<Record<ServiceAreaKey, number>>; month: string }[]
  label: string
  noAnswer: number
  respondents: number
  /** Every questionnaire option, most requested first. A person can pick several. */
  services: StatisticsCategory[]
}

/** Shelter stay: still in the shelter, or with a recorded exit (egreso). */
export type ShelterStay = 'in_shelter' | 'exited'

export type RegistryStatistics = {
  arrivalsByMonth: { count: number; month: string }[]
  /** Exits by the month the person left (within the period), whenever they arrived. */
  exitsByMonth?: { count: number; month: string }[]
  /** People who arrived in the period (stay filter not applied) and days from attention to exit. */
  shelterStay?: {
    daysCount: number
    exited: number
    inShelter: number
    medianDays: number | null
    p25Days: number | null
    p75Days: number | null
  }
  dimensions: Record<StatisticsDimensionKey, StatisticsDimension>
  mexicoToMonterrey: {
    bins: StatisticsDayBin[]
    count: number
    inconsistentDates: number
    median: number | null
    missingDates: number
    p25: number | null
    p75: number | null
  }
  requestedServices: RequestedServices
  range: {
    ageMax?: number | null
    ageMin?: number | null
    /** Records left out only because they have no age or birth date (age filter active). */
    ageUnknownExcluded?: number
    dateBasis: 'attentionDate'
    from: string | null
    stay?: ShelterStay | null
    to: string | null
  }
  total: number
}

/** Age in whole years at the attention date; null leaves that end open. */
export type AgeRange = { max: number | null; min: number | null }

export const MISSING_ANSWER = 'Sin respuesta'

type StatisticsRange = { from?: string | null; to?: string | null }

const statisticsQuery = (range: StatisticsRange, age: AgeRange, stay: ShelterStay | null) => {
  const params = new URLSearchParams()

  if (range.from) {
    params.set('from', range.from)
  }

  if (range.to) {
    params.set('to', range.to)
  }

  if (age.min !== null) {
    params.set('age_min', String(age.min))
  }

  if (age.max !== null) {
    params.set('age_max', String(age.max))
  }

  if (stay) {
    params.set('stay', stay)
  }

  const query = params.toString()

  return query ? `?${query}` : ''
}

export async function getRegistryStatistics(
  range: StatisticsRange,
  age: AgeRange = { max: null, min: null },
  stay: ShelterStay | null = null,
) {
  const response = await apiFetch<{ data: RegistryStatistics }>(
    `/registry/migrants/statistics${statisticsQuery(range, age, stay)}`,
  )

  return response.data
}

/** CSV of the same aggregate figures, with the same filters. The server audits every download. */
export async function downloadRegistryStatisticsCsv(
  range: StatisticsRange,
  age: AgeRange = { max: null, min: null },
  stay: ShelterStay | null = null,
): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(buildApiUrl(`/registry/migrants/statistics/export${statisticsQuery(range, age, stay)}`), {
    credentials: 'include',
    headers: { Accept: 'text/csv' },
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => null) as { message?: unknown } | null
    throw new ApiRequestError(
      typeof payload?.message === 'string' ? payload.message : `Request failed with status ${response.status}`,
      response.status,
    )
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? 'estadisticas-registros.csv'

  return { blob: await response.blob(), filename }
}
