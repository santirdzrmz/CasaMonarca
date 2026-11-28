import { getAppLocale, translate as t } from './i18n'

const optionLabels: Record<string, () => string> = {
  admin: () => t('Administrator', 'Administración'),
  accompanied_adolescent_boy: () => t('Accompanied adolescent boy', 'Adolescente hombre acompañado'),
  accompanied_adolescent_girl: () => t('Accompanied adolescent girl', 'Adolescente mujer acompañada'),
  accompanied_boy: () => t('Accompanied boy', 'Niño acompañado'),
  accompanied_girl: () => t('Accompanied girl', 'Niña acompañada'),
  adult: () => t('Adult (18-59 years)', 'Persona adulta (18-59 años)'),
  approved: () => t('Approved', 'Aprobado'),
  approved_by_coordinator: () => t('Approved by coordination', 'Aprobado por coordinación'),
  approved_by_operator: () => t('Approved by staff', 'Aprobado por personal'),
  changes_requested: () => t('Changes requested', 'Correcciones solicitadas'),
  common_law_union: () => t('Common-law union', 'Unión libre'),
  coordinator: () => t('Coordinator', 'Coordinación'),
  deleted_by_admin: () => t('Deleted by administration', 'Eliminado por administración'),
  deleted_by_admin_arco: () => t('Deleted through an ARCO cancellation', 'Eliminado mediante cancelación ARCO'),
  anonymized_by_admin_arco: () => t('Anonymized through an ARCO cancellation', 'Anonimizado mediante cancelación ARCO'),
  divorced: () => t('Divorced', 'Divorciado'),
  draft: () => t('Draft', 'Borrador'),
  edited_by_coordinator: () => t('Edited by coordination', 'Editado por coordinación'),
  female: () => t('Female', 'Femenino'),
  lgbtq_plus: () => 'LGBTQ+',
  male: () => t('Male', 'Masculino'),
  man: () => t('Male', 'Masculino'),
  married: () => t('Married', 'Casado'),
  migrant: () => t('Migrant', 'Persona migrante'),
  non_binary: () => t('Non-binary', 'No binario'),
  non_coordinator: () => t('Non-coordinator', 'No coordinador'),
  older_adult: () => t('Older adult (60+ years)', 'Persona adulta mayor (60 años o más)'),
  pending_approval: () => t('Pending approval', 'Pendiente de aprobación'),
  pending_review: () => t('Pending review', 'Pendiente de revisión'),
  reviewed_by_operator: () => t('Reviewed by staff', 'Revisado por personal'),
  rejected: () => t('Rejected', 'Rechazado'),
  rejected_by_coordinator: () => t('Rejected by coordination', 'Rechazado por coordinación'),
  rejected_by_operator: () => t('Rejected by staff', 'Rechazado por personal'),
  sent_to_admin_for_deletion: () => t('Sent to administration for deletion', 'Enviado a administración para eliminación'),
  sent_to_coordinator: () => t('Sent to coordination', 'Enviado a coordinación'),
  separated: () => t('Separated', 'Separado'),
  single: () => t('Single', 'Soltero'),
  submitted_by_volunteer: () => t('Submitted by a volunteer', 'Enviado por voluntariado'),
  unaccompanied_minor: () => t('Unaccompanied child or adolescent', 'Niña, niño o adolescente no acompañado'),
  volunteer: () => t('Volunteer', 'Voluntariado'),
  woman: () => t('Female', 'Femenino'),
  widowed: () => t('Widowed', 'Viudo'),
}

export const formatRegistryValue = (value: unknown, fallback?: string) => {
  if (typeof value !== 'string' || value.trim() === '') {
    return fallback ?? t('Not available', 'No disponible')
  }

  const normalized = value.trim()
  return optionLabels[normalized]?.() ?? normalized.replace(/_/g, ' ')
}

export const formatRegistryDate = (value?: string | null, includeTime = false) => {
  if (!value) return t('Not available', 'No disponible')

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return t('Not available', 'No disponible')

  return new Intl.DateTimeFormat(getAppLocale() === 'en' ? 'en-US' : 'es-MX', {
    dateStyle: 'medium',
    ...(includeTime ? { timeStyle: 'short' as const } : {}),
    ...(/^\d{4}-\d{2}-\d{2}$/.test(value) ? { timeZone: 'UTC' } : {}),
  }).format(date)
}
