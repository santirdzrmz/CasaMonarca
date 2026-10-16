import { getAppLocale } from '../../lib/i18n'

const intlLocale = () => (getAppLocale() === 'es' ? 'es-MX' : 'en-US')

export const formatCount = (value: number) =>
  new Intl.NumberFormat(intlLocale()).format(value)

export const formatCompact = (value: number) =>
  new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: 1, notation: 'compact' }).format(value)

export const formatPercent = (part: number, total: number) =>
  total > 0
    ? new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: 1, style: 'percent' }).format(part / total)
    : '—'

/** "2026-03" -> "mar 2026" (or "Mar 2026"). */
export const formatMonth = (month: string, style: 'short' | 'long' = 'short') => {
  const [year, monthIndex] = month.split('-').map(Number)

  if (!year || !monthIndex) {
    return month
  }

  return new Intl.DateTimeFormat(intlLocale(), {
    month: style,
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(Date.UTC(year, monthIndex - 1, 1)))
}

/** Round a maximum up to a clean axis top with ~4 even steps. */
export const niceTicks = (max: number, steps = 4): number[] => {
  if (max <= 0) {
    return [0, 1]
  }

  const raw = max / steps
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((m) => m >= raw) ?? raw
  const niceStep = Math.max(1, Math.ceil(step))
  const top = Math.ceil(max / niceStep) * niceStep

  return Array.from({ length: Math.round(top / niceStep) + 1 }, (_, index) => index * niceStep)
}
