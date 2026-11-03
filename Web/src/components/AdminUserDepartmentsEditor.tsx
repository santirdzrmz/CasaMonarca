import { useState } from 'react'

import { ApiRequestError } from '../lib/api'
import { updateAdminUserDepartments, type AdminUserSummary } from '../lib/adminUsers'
import { DEPARTMENTS, departmentLabel } from '../lib/departments'
import { translate as t } from '../lib/i18n'
import { AppIcon } from './ui/AppIcon'

type AdminUserDepartmentsEditorProps = {
  account: AdminUserSummary
  isSelf: boolean
  onUpdated: (account: AdminUserSummary) => void
}

type Feedback = { kind: 'error' | 'success'; message: string }

const sameSet = (a: string[], b: string[]) =>
  a.length === b.length && a.every((value) => b.includes(value))

export function AdminUserDepartmentsEditor({ account, isSelf, onUpdated }: AdminUserDepartmentsEditorProps) {
  const assigned = account.departments ?? []
  const [draft, setDraft] = useState<string[]>(assigned)
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback | null>(null)

  const unchanged = sameSet(draft, assigned)
  const removesOwnLast = isSelf && draft.length === 0

  const toggle = (department: string) => {
    setFeedback(null)
    setDraft((current) => (current.includes(department)
      ? current.filter((value) => value !== department)
      : [...current, department]))
  }

  const save = async () => {
    setSaving(true)
    setFeedback(null)

    try {
      const response = await updateAdminUserDepartments(account.id, draft)
      onUpdated(response.user)
      setDraft(response.user.departments ?? [])
      setFeedback({ kind: 'success', message: t('Departments updated.', 'Departamentos actualizados.') })
    } catch (error) {
      setFeedback({
        kind: 'error',
        message: error instanceof ApiRequestError
          ? error.message
          : t('Departments could not be updated.', 'No se pudieron actualizar los departamentos.'),
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <fieldset className="admin-departments" disabled={saving}>
      <legend>{t('Departments', 'Departamentos')}</legend>
      <div className="admin-departments__options">
        {DEPARTMENTS.map((department) => (
          <label className="admin-departments__option" key={department}>
            <input
              checked={draft.includes(department)}
              onChange={() => toggle(department)}
              type="checkbox"
            />
            {departmentLabel(department)}
          </label>
        ))}
      </div>
      <button
        className="admin-role-assignment__button"
        disabled={saving || unchanged || removesOwnLast}
        onClick={() => void save()}
        type="button"
      >
        <AppIcon name="verify" size={17} />
        {saving ? t('Saving...', 'Guardando...') : t('Save departments', 'Guardar departamentos')}
      </button>
      {removesOwnLast ? (
        <p className="admin-role-assignment__note admin-role-assignment__note--warning">
          {t(
            'Keep at least one of your own departments, or you will not be able to make changes.',
            'Conserva al menos uno de tus departamentos; sin ellos no podrás hacer cambios.',
          )}
        </p>
      ) : null}
      {feedback ? (
        <p
          className={`admin-field-feedback admin-field-feedback--${feedback.kind}`}
          role={feedback.kind === 'error' ? 'alert' : 'status'}
        >
          {feedback.message}
        </p>
      ) : null}
    </fieldset>
  )
}
