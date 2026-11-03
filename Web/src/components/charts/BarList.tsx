import { formatCount, formatPercent } from './chartFormat'

export type BarListRow = {
  count: number
  label: string
  muted?: boolean
}

type BarListProps = {
  ariaLabel: string
  rows: BarListRow[]
  total: number
}

/**
 * Horizontal bars for one categorical dimension: a single series (slot 1), value and
 * share at the tip, scaled to the largest row so short bars stay readable. Rows
 * flagged `muted` (missing answers, folded "other") use the de-emphasis gray.
 */
export function BarList({ ariaLabel, rows, total }: BarListProps) {
  const max = Math.max(1, ...rows.map((row) => row.count))

  return (
    <ul aria-label={ariaLabel} className="viz-barlist">
      {rows.map((row) => (
        <li
          aria-label={`${row.label}: ${formatCount(row.count)} (${formatPercent(row.count, total)})`}
          className={`viz-barlist__row${row.muted ? ' viz-barlist__row--muted' : ''}`}
          key={row.label}
          tabIndex={0}
        >
          <span className="viz-barlist__label" title={row.label}>{row.label}</span>
          <span aria-hidden="true" className="viz-barlist__track">
            <span
              className="viz-barlist__bar"
              style={{ width: `${row.count > 0 ? Math.max(1.5, (row.count / max) * 100) : 0}%` }}
            />
          </span>
          <span aria-hidden="true" className="viz-barlist__value">
            <strong>{formatCount(row.count)}</strong>
            <span>{formatPercent(row.count, total)}</span>
          </span>
        </li>
      ))}
    </ul>
  )
}
