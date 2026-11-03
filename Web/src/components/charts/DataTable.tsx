import { translate as t } from '../../lib/i18n'

type DataTableProps = {
  caption: string
  columns: string[]
  rows: (string | number)[][]
}

/** The table twin every chart carries: every value is reachable without hovering. */
export function DataTable({ caption, columns, rows }: DataTableProps) {
  return (
    <details className="viz-table">
      <summary>{t('View as table', 'Ver como tabla')}</summary>
      <div className="viz-table__scroll">
        <table>
          <caption className="viz-sr-only">{caption}</caption>
          <thead>
            <tr>
              {columns.map((column, index) => (
                <th key={column} scope="col" className={index > 0 ? 'viz-table__num' : undefined}>
                  {column}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={String(row[0])}>
                {row.map((cell, index) =>
                  index === 0 ? (
                    <th key={index} scope="row">{cell}</th>
                  ) : (
                    <td key={index} className="viz-table__num">{cell}</td>
                  ),
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}
