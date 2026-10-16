import { apiFetch } from './api'

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

export type RegistryStatistics = {
  arrivalsByMonth: { count: number; month: string }[]
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
  range: {
    dateBasis: 'attentionDate'
    from: string | null
    to: string | null
  }
  total: number
}

export const MISSING_ANSWER = 'Sin respuesta'

export async function getRegistryStatistics(range: { from?: string | null; to?: string | null }) {
  const params = new URLSearchParams()

  if (range.from) {
    params.set('from', range.from)
  }

  if (range.to) {
    params.set('to', range.to)
  }

  const query = params.toString()
  const response = await apiFetch<{ data: RegistryStatistics }>(
    `/registry/migrants/statistics${query ? `?${query}` : ''}`,
  )

  return response.data
}
