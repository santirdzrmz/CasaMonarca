import { useId } from 'react'

import {
  departmentLabel,
  setActingDepartment,
  useActingDepartment,
  type Department,
} from '../lib/departments'
import { translate as t } from '../lib/i18n'

type ActingDepartmentSelectorProps = {
  departments: string[]
}

export function ActingDepartmentSelector({ departments }: ActingDepartmentSelectorProps) {
  const selectId = useId()
  const actingDepartment = useActingDepartment()

  if (departments.length === 0) {
    return (
      <section className="workspace-sidebar__role acting-department">
        <span className="workspace-sidebar__role-label">{t('Acting department', 'Departamento')}</span>
        <p className="acting-department__notice acting-department__notice--warning" role="status">
          {t(
            'No department assigned. Ask an administrator to assign one before making changes.',
            'Sin departamento asignado. Pide a un administrador que te asigne uno para poder hacer cambios.',
          )}
        </p>
      </section>
    )
  }

  return (
    <section className="workspace-sidebar__role acting-department">
      <label className="workspace-sidebar__role-label" htmlFor={selectId}>
        {t('Acting department', 'Departamento con el que actúas')}
      </label>
      <select
        className="acting-department__select"
        id={selectId}
        onChange={(event) => setActingDepartment((event.target.value || null) as Department | null)}
        value={actingDepartment ?? ''}
      >
        {actingDepartment === null ? (
          <option disabled value="">
            {t('Select a department…', 'Selecciona un departamento…')}
          </option>
        ) : null}
        {departments.map((department) => (
          <option key={department} value={department}>
            {departmentLabel(department)}
          </option>
        ))}
      </select>
      <p
        className={`acting-department__notice${actingDepartment === null ? ' acting-department__notice--warning' : ''}`}
        role="status"
      >
        {actingDepartment === null
          ? t(
            'Choose a department before making changes. It is recorded with every change.',
            'Elige un departamento antes de hacer cambios. Queda registrado con cada cambio.',
          )
          : t(
            'Every change you make is recorded and signed under this department.',
            'Cada cambio que hagas queda registrado y firmado con este departamento.',
          )}
      </p>
    </section>
  )
}
