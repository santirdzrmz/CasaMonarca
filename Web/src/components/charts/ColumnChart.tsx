import { useCallback, useRef, useState } from 'react'

import { translate as t } from '../../lib/i18n'
import { formatCompact, formatCount, niceTicks } from './chartFormat'

export type ColumnSeries = {
  /** CSS color, normally a `var(--viz-series-n)` token. */
  color: string
  key: string
  label: string
}

export type ColumnDatum = {
  key: string
  /** Axis label (short). */
  label: string
  /** Tooltip heading (long); defaults to `label`. */
  title?: string
  values: Record<string, number>
}

type ColumnChartProps = {
  ariaLabel: string
  data: ColumnDatum[]
  height?: number
  series: ColumnSeries[]
}

const MARGIN = { top: 22, right: 8, bottom: 30, left: 40 }
const MAX_BAR = 24
const GAP = 2
const RADIUS = 4
const MIN_LABEL_SPACING = 58

/** Measures the container on mount and on resize (falls back to 640px without ResizeObserver). */
function useWidth<T extends HTMLElement>() {
  const [width, setWidth] = useState(0)
  const observerRef = useRef<ResizeObserver | null>(null)

  const ref = useCallback((element: T | null) => {
    observerRef.current?.disconnect()
    observerRef.current = null

    if (!element) {
      return
    }

    setWidth(element.clientWidth || 640)

    if (typeof ResizeObserver === 'undefined') {
      return
    }

    const observer = new ResizeObserver(([entry]) => setWidth(Math.round(entry.contentRect.width)))
    observer.observe(element)
    observerRef.current = observer
  }, [])

  return [ref, width] as const
}

/** A column whose data-end (top) is rounded and whose baseline is square. */
const roundedTopPath = (x: number, yTop: number, w: number, yBottom: number) => {
  const r = Math.min(RADIUS, w / 2, Math.max(0, yBottom - yTop))

  return `M${x},${yBottom}V${yTop + r}Q${x},${yTop} ${x + r},${yTop}H${x + w - r}Q${x + w},${yTop} ${x + w},${yTop + r}V${yBottom}Z`
}

/**
 * Vertical columns on one baseline; stacked when several series are given. One axis,
 * hairline grid, selective direct labels (tallest and latest column), per-column
 * hover/focus tooltip listing every series.
 */
export function ColumnChart({ ariaLabel, data, height = 240, series }: ColumnChartProps) {
  const [containerRef, width] = useWidth<HTMLDivElement>()
  const [active, setActive] = useState<number | null>(null)

  const plotWidth = Math.max(0, width - MARGIN.left - MARGIN.right)
  const plotHeight = height - MARGIN.top - MARGIN.bottom
  const totals = data.map((datum) => series.reduce((sum, item) => sum + (datum.values[item.key] ?? 0), 0))
  const ticks = niceTicks(Math.max(0, ...totals))
  const top = ticks[ticks.length - 1] || 1
  const band = data.length > 0 ? plotWidth / data.length : 0
  const barWidth = Math.max(2, Math.min(MAX_BAR, band * 0.62))
  const baseline = MARGIN.top + plotHeight
  const y = (value: number) => (value / top) * plotHeight
  const labelEvery = Math.max(1, Math.ceil(data.length / Math.max(1, Math.floor(plotWidth / MIN_LABEL_SPACING))))
  const maxIndex = totals.indexOf(Math.max(...totals))
  const labelled = new Set([maxIndex, data.length - 1].filter((index) => index >= 0 && totals[index] > 0))
  const stacked = series.length > 1

  const activeDatum = active !== null ? data[active] : null
  const activeCenter = active !== null ? MARGIN.left + band * active + band / 2 : 0

  return (
    <div className="viz-chart" ref={containerRef}>
      {stacked ? (
        <ul className="viz-legend" aria-label={t('Legend', 'Leyenda')}>
          {series.map((item) => (
            <li key={item.key}>
              <span aria-hidden="true" className="viz-legend__swatch" style={{ background: item.color }} />
              {item.label}
            </li>
          ))}
        </ul>
      ) : null}

      {width > 0 ? (
        <svg aria-label={ariaLabel} className="viz-chart__svg" height={height} role="img" width={width}>
          {ticks.map((tick) => (
            <g key={tick}>
              <line
                className={tick === 0 ? 'viz-axis' : 'viz-grid'}
                x1={MARGIN.left}
                x2={width - MARGIN.right}
                y1={baseline - y(tick)}
                y2={baseline - y(tick)}
              />
              <text className="viz-tick" textAnchor="end" x={MARGIN.left - 8} y={baseline - y(tick) + 4}>
                {formatCompact(tick)}
              </text>
            </g>
          ))}

          {data.map((datum, index) => {
            const x = MARGIN.left + band * index + (band - barWidth) / 2
            const visible = series.filter((item) => (datum.values[item.key] ?? 0) > 0)
            let cumulative = 0

            return (
              <g
                className={active !== null && active !== index ? 'viz-column viz-column--dimmed' : 'viz-column'}
                key={datum.key}
              >
                {visible.map((item, segmentIndex) => {
                  const value = datum.values[item.key] ?? 0
                  const yBottom = baseline - y(cumulative)
                  const yTop = baseline - y(cumulative + value)
                  cumulative += value
                  const isTop = segmentIndex === visible.length - 1

                  return isTop ? (
                    <path d={roundedTopPath(x, yTop, barWidth, yBottom)} fill={item.color} key={item.key} />
                  ) : (
                    <rect
                      fill={item.color}
                      height={Math.max(0, yBottom - yTop - GAP)}
                      key={item.key}
                      width={barWidth}
                      x={x}
                      y={yTop + GAP}
                    />
                  )
                })}

                {labelled.has(index) ? (
                  <text className="viz-value" textAnchor="middle" x={x + barWidth / 2} y={baseline - y(totals[index]) - 6}>
                    {formatCount(totals[index])}
                  </text>
                ) : null}

                {index % labelEvery === 0 ? (
                  <text className="viz-tick" textAnchor="middle" x={x + barWidth / 2} y={baseline + 18}>
                    {datum.label}
                  </text>
                ) : null}

                <rect
                  aria-label={`${datum.title ?? datum.label}: ${formatCount(totals[index])}`}
                  className="viz-hit"
                  height={plotHeight + MARGIN.top}
                  onBlur={() => setActive(null)}
                  onFocus={() => setActive(index)}
                  onPointerEnter={() => setActive(index)}
                  onPointerLeave={() => setActive(null)}
                  role="graphics-symbol"
                  tabIndex={0}
                  width={band}
                  x={MARGIN.left + band * index}
                  y={0}
                />
              </g>
            )
          })}
        </svg>
      ) : null}

      {activeDatum && active !== null ? (
        <div
          aria-hidden="true"
          className="viz-tooltip"
          style={{ left: Math.min(Math.max(activeCenter, 80), Math.max(80, width - 80)), top: 4 }}
        >
          <span className="viz-tooltip__title">{activeDatum.title ?? activeDatum.label}</span>
          {stacked ? (
            <>
              {series.map((item) => (
                <span className="viz-tooltip__row" key={item.key}>
                  <span aria-hidden="true" className="viz-tooltip__key" style={{ background: item.color }} />
                  <strong>{formatCount(activeDatum.values[item.key] ?? 0)}</strong>
                  <span>{item.label}</span>
                </span>
              ))}
              <span className="viz-tooltip__row viz-tooltip__row--total">
                <strong>{formatCount(totals[active])}</strong>
                <span>{t('Total', 'Total')}</span>
              </span>
            </>
          ) : (
            <span className="viz-tooltip__row">
              <strong>{formatCount(totals[active])}</strong>
              <span>{series[0]?.label}</span>
            </span>
          )}
        </div>
      ) : null}
    </div>
  )
}
