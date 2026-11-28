import type { UserRole } from '../lib/auth'

export type RegistryRole = UserRole

export type RegistryStatus =
  | 'draft'
  | 'pending_review'
  | 'pending_approval'
  | 'changes_requested'
  | 'anonymized_by_admin_arco'
  | 'approved'
  | 'rejected'
  | 'deleted_by_admin'
  | 'submitted_by_volunteer'
  | 'reviewed_by_operator'
  | 'approved_by_operator'
  | 'rejected_by_operator'
  | 'sent_to_coordinator'
  | 'edited_by_coordinator'
  | 'approved_by_coordinator'
  | 'arco_requested'
  | 'arco_approved'
  | 'arco_rejected'
  | 'sent_to_admin_for_deletion'
  | 'deleted_by_admin'

export type MigrantRegistrationPayload = {
  age?: string
  attentionDate?: string
  birthDate?: string
  civilStatus?: string
  countryOfOrigin?: string
  departmentState?: string
  firstLastName?: string
  firstName?: string
  fullName?: string
  gender?: string
  notes?: string
  phone?: string
  populationGroup?: string
  questionnaire?: MigrantQuestionnairePayload
  schemaVersion?: 2
  secondLastName?: string
}

export type MigrantQuestionnaireAnswer = {
  otherText?: string
  value: string | string[]
}

export type MigrantQuestionnairePayload = {
  answers: Record<string, MigrantQuestionnaireAnswer>
  definitionId: string
}

export type QuestionnaireLocale = 'es' | 'en' | 'fr' | 'ht'

export type QuestionnaireDestination =
  | { kind: 'end' }
  | { kind: 'question'; questionId: string }

export type QuestionnaireChoice = {
  custom: boolean
  id: string
  label: Record<QuestionnaireLocale, string>
  next: QuestionnaireDestination
  value: string
}

export type QuestionnaireQuestion = {
  choices: QuestionnaireChoice[]
  defaultNext: QuestionnaireDestination
  help: Record<QuestionnaireLocale, string> | null
  id: string
  multiline: boolean
  multipleSelection: boolean
  number: number
  numeric: boolean
  required: boolean
  sectionId: string
  title: Record<QuestionnaireLocale, string>
  type: 'choice' | 'date' | 'text'
}

export type MigrantQuestionnaireDefinition = {
  canonicalAnswerLocale: 'es'
  defaultLocale: 'es'
  id: string
  locales: Array<{ id: QuestionnaireLocale; name: string }>
  questions: QuestionnaireQuestion[]
  schemaVersion: 2
  sections: Array<{ id: string; title: Record<QuestionnaireLocale, string> }>
  summaryMappings: Record<string, string>
  title: Record<QuestionnaireLocale, string>
}

export type RegistryActor = {
  email?: string | null
  id: number
  name?: string | null
  role?: UserRole | null
}

export type RegistryEntry = {
  created_at: string
  created_by: number
  created_by_role: RegistryRole
  creator?: RegistryActor | null
  current_assignee_role?: RegistryRole | null
  current_status: RegistryStatus
  id: number
  pending_action?: 'create' | 'update' | null
  pending_payload_json?: (Partial<MigrantRegistrationPayload> & Record<string, unknown>) | null
  pending_requested_by?: number | null
  pending_requested_by_role?: RegistryRole | null
  payload_json: Partial<MigrantRegistrationPayload> & Record<string, unknown>
  status_history?: RegistryStatusHistory[]
  updated_at: string
  expires_at?: string
}

export type RegistrySignature = {
  action_type: string
  actor_role: RegistryRole
  actor_user_id: number
  algorithm: string
  created_at: string
  id: number
  public_key_ref?: string | null
  registry_entry_id: number
  signature_payload: string
  verified_at?: string | null
}

export type RegistryStatusHistory = {
  changed_by: number
  changed_by_role: RegistryRole
  created_at: string
  from_status?: RegistryStatus | null
  id: number
  reason?: string | null
  to_status: RegistryStatus
}
