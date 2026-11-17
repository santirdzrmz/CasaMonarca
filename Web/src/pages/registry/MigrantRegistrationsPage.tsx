import { translate as t } from '../../lib/i18n'
import { useEffect, useMemo, useState } from 'react'

import { MigrantDocumentsPanel } from '../../components/registry/MigrantDocumentsPanel'
import { MigrantQuestionnaireViewer } from '../../components/registry/MigrantQuestionnaireViewer'
import { ShelterExitDialog } from '../../components/registry/ShelterExitDialog'
import { AppIcon } from '../../components/ui/AppIcon'
import { APP_MIGRANT_REGISTRY_PATH } from '../../config/appRoutes'
import { migrantDocumentsEnabled } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import { formatRegistryDate, formatRegistryValue } from '../../lib/registryDisplay'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion } from '../../lib/webauthn'
import {
  ApiRequestError,
  getRegistryEntries,
  startRegistryPdfDownload,
  type RegistryEntry,
  verifyRegistryPdfDownload,
} from '../../lib/registry'

type MigrantRegistrationsPageProps = {
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type StayFilter = '' | 'in_shelter' | 'exited'

type RegistrationsFilterState = {
  country: string
  page: number
  pageSize: number
  populationGroup: string
  search: string
  status: string
  stay: StayFilter
}

const STAY_OPTIONS: { label: () => string; value: StayFilter }[] = [
  { label: () => t('All', 'Todos'), value: '' },
  { label: () => t('In the shelter', 'En albergue'), value: 'in_shelter' },
  { label: () => t('Left the shelter', 'Egresados'), value: 'exited' },
]

/** Only approved registrations are in the shelter; they stay there until an exit is recorded. */
const matchesStay = (entry: RegistryEntry, stay: StayFilter) =>
  stay === ''
  || (stay === 'exited' && Boolean(entry.exited_at))
  || (stay === 'in_shelter' && entry.current_status === 'approved' && !entry.exited_at)

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100]
const REGISTRATIONS_FILTER_SESSION_KEY = 'casa-monarca.migrant-registrations.filters'

const DEFAULT_FILTERS: RegistrationsFilterState = {
  country: '',
  page: 1,
  pageSize: 25,
  populationGroup: '',
  search: '',
  status: '',
  stay: '',
}

const getEntryName = (entry: RegistryEntry) =>
  formatRegistryValue(
    entry.payload_json.fullName ?? entry.payload_json.full_name,
    t(`Registration #${entry.id}`, `Registro #${entry.id}`),
  )

const getEntryCountry = (entry: RegistryEntry) =>
  formatRegistryValue(entry.payload_json.countryOfOrigin)

const getEntryPopulationGroup = (entry: RegistryEntry) =>
  formatRegistryValue(entry.payload_json.populationGroup)

const getEntryPopulationGroupCode = (entry: RegistryEntry) =>
  typeof entry.payload_json.populationGroup === 'string'
    ? entry.payload_json.populationGroup
    : ''

const normalizeSearchText = (value: string | number) => String(value)
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase()

const getSearchableValue = (entry: RegistryEntry) => normalizeSearchText([
  entry.id,
  entry.created_by_role,
  entry.creator?.email,
  entry.creator?.name,
  entry.current_status,
  entry.payload_json.fullName,
  entry.payload_json.full_name,
  entry.payload_json.firstName,
  entry.payload_json.firstLastName,
  entry.payload_json.secondLastName,
  entry.payload_json.countryOfOrigin,
  entry.payload_json.departmentState,
  entry.payload_json.populationGroup,
  entry.payload_json.phone,
]
  .filter((value): value is string | number => typeof value === 'string' || typeof value === 'number')
  .join(' '))

const normalizeFilterValue = (value: unknown) =>
  typeof value === 'string' ? value.trim() : ''

const readStoredFilters = (): RegistrationsFilterState => {
  if (typeof window === 'undefined') {
    return DEFAULT_FILTERS
  }

  try {
    const rawValue = window.sessionStorage.getItem(REGISTRATIONS_FILTER_SESSION_KEY)

    if (!rawValue) {
      return DEFAULT_FILTERS
    }

    const stored = JSON.parse(rawValue) as Partial<RegistrationsFilterState>
    const page = Number(stored.page)
    const pageSize = Number(stored.pageSize)

    return {
      country: normalizeFilterValue(stored.country),
      page: Number.isInteger(page) && page > 0 ? page : DEFAULT_FILTERS.page,
      pageSize: PAGE_SIZE_OPTIONS.includes(pageSize) ? pageSize : DEFAULT_FILTERS.pageSize,
      populationGroup: normalizeFilterValue(stored.populationGroup),
      search: normalizeFilterValue(stored.search),
      status: normalizeFilterValue(stored.status),
      stay: stored.stay === 'in_shelter' || stored.stay === 'exited' ? stored.stay : '',
    }
  } catch {
    return DEFAULT_FILTERS
  }
}

const storeFilters = (filters: RegistrationsFilterState) => {
  try {
    window.sessionStorage.setItem(REGISTRATIONS_FILTER_SESSION_KEY, JSON.stringify(filters))
  } catch {
    // Filter persistence is a convenience only; browsing must work without storage.
  }
}

const getUniqueValues = (entries: RegistryEntry[], getValue: (entry: RegistryEntry) => string) =>
  [...new Set(entries.map(getValue).filter((value) => value.trim() !== '' && value !== t('Not available', 'No disponible')))]
    .sort((first, second) => first.localeCompare(second))

export function MigrantRegistrationsPage({ onNavigate, onSessionExpired, user }: MigrantRegistrationsPageProps) {
  const [initialFilters] = useState<RegistrationsFilterState>(() => readStoredFilters())
  const [entries, setEntries] = useState<RegistryEntry[]>([])
  const [error, setError] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [countryFilter, setCountryFilter] = useState(initialFilters.country)
  const [populationGroupFilter, setPopulationGroupFilter] = useState(initialFilters.populationGroup)
  const [statusFilter, setStatusFilter] = useState(initialFilters.status)
  const [stayFilter, setStayFilter] = useState<StayFilter>(initialFilters.stay)
  const [exitDialog, setExitDialog] = useState<{ entry: RegistryEntry; mode: 'record' | 'undo' } | null>(null)
  // Page-level, so it stays visible when the entry leaves the current stay filter.
  const [exitNotice, setExitNotice] = useState<string | null>(null)
  const canManageExits = user.role === 'admin' || user.role === 'coordinator'
  const [page, setPage] = useState(initialFilters.page)
  const [pageSize, setPageSize] = useState(initialFilters.pageSize)
  const [searchInput, setSearchInput] = useState(initialFilters.search)
  const [debouncedSearch, setDebouncedSearch] = useState(initialFilters.search)
  const [reloadToken, setReloadToken] = useState(0)
  const [documentEntryIds, setDocumentEntryIds] = useState<Set<number>>(() => new Set())
  const [pendingPdfEntryId, setPendingPdfEntryId] = useState<number | null>(null)
  const [pdfErrors, setPdfErrors] = useState<Record<number, string>>({})
  const [pdfFeedback, setPdfFeedback] = useState<Record<number, string>>({})

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      const nextSearch = searchInput.trim()

      if (nextSearch !== debouncedSearch) {
        setDebouncedSearch(nextSearch)
        setPage(1)
      }
    }, 300)

    return () => window.clearTimeout(timeoutId)
  }, [debouncedSearch, searchInput])

  useEffect(() => {
    let isMounted = true

    getRegistryEntries()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setEntries(response.data)
        setIsLoading(false)
      })
      .catch((loadError) => {
        if (!isMounted) {
          return
        }

        if (loadError instanceof ApiRequestError && loadError.status === 401) {
          onSessionExpired?.()
          return
        }

        setEntries([])
        setError(loadError instanceof Error ? loadError.message : t('Unable to load migrant registrations.', 'No se pudieron cargar los registros de migrantes.'))
        setIsLoading(false)
      })

    return () => {
      isMounted = false
    }
  }, [onSessionExpired, reloadToken])

  useEffect(() => {
    storeFilters({
      country: countryFilter,
      page,
      pageSize,
      populationGroup: populationGroupFilter,
      search: debouncedSearch,
      status: statusFilter,
      stay: stayFilter,
    })
  }, [countryFilter, debouncedSearch, page, pageSize, populationGroupFilter, statusFilter, stayFilter])

  const countries = useMemo(() => getUniqueValues(entries, getEntryCountry), [entries])
  const populationGroups = useMemo(
    () => getUniqueValues(entries, getEntryPopulationGroupCode),
    [entries],
  )
  const statuses = useMemo(
    () => [...new Set(entries.map((entry) => entry.current_status))].sort(),
    [entries],
  )
  const filteredEntries = useMemo(() => {
    const searchTerm = normalizeSearchText(debouncedSearch)

    return entries.filter((entry) =>
      (statusFilter === '' || entry.current_status === statusFilter) &&
      matchesStay(entry, stayFilter) &&
      (countryFilter === '' || getEntryCountry(entry) === countryFilter) &&
      (populationGroupFilter === '' || getEntryPopulationGroupCode(entry) === populationGroupFilter) &&
      (searchTerm === '' || getSearchableValue(entry).includes(searchTerm)),
    )
  }, [countryFilter, debouncedSearch, entries, populationGroupFilter, statusFilter, stayFilter])

  const totalPages = Math.max(1, Math.ceil(filteredEntries.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const firstVisibleRegistration = filteredEntries.length === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const lastVisibleRegistration = Math.min(currentPage * pageSize, filteredEntries.length)
  const visibleEntries = filteredEntries.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const hasActiveFilters = Boolean(
    countryFilter || populationGroupFilter || statusFilter || stayFilter || debouncedSearch,
  )

  const refresh = () => {
    setError(null)
    setIsLoading(true)
    setReloadToken((current) => current + 1)
  }

  const resetFilters = () => {
    setCountryFilter('')
    setPopulationGroupFilter('')
    setStatusFilter('')
    setStayFilter('')
    setSearchInput('')
    setDebouncedSearch('')
    setPage(1)
  }

  const saveExit = (saved: RegistryEntry) => {
    const mode = exitDialog?.mode
    setEntries((current) => current.map((entry) => (entry.id === saved.id ? { ...entry, ...saved } : entry)))
    const name = getEntryName(saved)
    setExitNotice(mode === 'undo'
      ? t(`Exit undone: ${name} shows as in the shelter again.`, `Egreso deshecho: ${name} vuelve a aparecer en el albergue.`)
      : t(`Shelter exit recorded for ${name}.`, `Egreso registrado para ${name}.`))
    setExitDialog(null)
  }

  const revealDocuments = (entryId: number) => {
    setDocumentEntryIds((current) => {
      if (current.has(entryId)) {
        return current
      }

      return new Set(current).add(entryId)
    })
  }

  const downloadRegistryPdf = async (entry: RegistryEntry) => {
    setPendingPdfEntryId(entry.id)
    setPdfErrors((current) => ({ ...current, [entry.id]: '' }))
    setPdfFeedback((current) => ({ ...current, [entry.id]: '' }))
    let challengeIntentId: string | null = null

    try {
      const options = await startRegistryPdfDownload(entry.id)
      challengeIntentId = options.challengeIntent.id
      const assertion = await getWebauthnAssertion(options.options)
      const blob = await verifyRegistryPdfDownload(entry.id, assertion)
      const url = URL.createObjectURL(blob)
      const link = window.document.createElement('a')
      link.href = url
      link.download = `registro-migrante-${entry.id}.pdf`
      link.click()
      URL.revokeObjectURL(url)
      setPdfFeedback((current) => ({
        ...current,
        [entry.id]: t('Registration PDF downloaded.', 'Se descargó el PDF del registro.'),
      }))
    } catch (caught: unknown) {
      const passkeyCancelled = caught instanceof DOMException && caught.name === 'NotAllowedError'

      if (challengeIntentId && passkeyCancelled) {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      if (caught instanceof ApiRequestError && caught.status === 401) {
        onSessionExpired?.()
        return
      }

      setPdfErrors((current) => ({
        ...current,
        [entry.id]: passkeyCancelled
          ? t('Passkey download was cancelled.', 'Se canceló la descarga con llave de acceso.')
          : caught instanceof ApiRequestError && caught.status === 409
            ? t('The registration changed. Reload and try again.', 'El registro cambió. Actualiza la página e inténtalo de nuevo.')
            : caught instanceof ApiRequestError && caught.status === 422
              ? t(caught.message, 'No se pudo iniciar la descarga. Verifica que tu llave de acceso esté registrada.')
              : caught instanceof Error
                ? caught.message
                : t('Unable to download the registration PDF.', 'No se pudo descargar el PDF del registro.'),
      }))
    } finally {
      setPendingPdfEntryId(null)
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <div className="audit-toolbar">
          <div>
            <h2 className="workspace-panel__title">{t("Current migrant registrations", "Registros actuales de migrantes")}</h2>
            <p className="workspace-panel__copy">
              {t("Browse the shared registry and review the current details attached to each registration. ", "Consulta el registro compartido y revisa los detalles actuales de cada expediente. ")}</p>
          </div>

          <button className="audit-toolbar__button" disabled={isLoading} onClick={refresh} type="button">
            <AppIcon name="refresh" />
            {isLoading ? t("Refreshing...", "Actualizando...") : t("Refresh", "Actualizar")}
          </button>
        </div>

        <div aria-label={t("Migrant registration filters", "Filtros de registros de migrantes")} className="audit-controls">
          <label className="audit-control audit-control--search">
            <span>{t("Search", "Buscar")}</span>
            <input
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder={t("Name, country, contact, submitter, or ID", "Nombre, país, contacto, remitente o ID")}
              type="search"
              value={searchInput}
            />
          </label>

          <label className="audit-control">
            <span>{t("Status", "Estado")}</span>
            <select
              onChange={(event) => {
                setStatusFilter(event.target.value)
                setPage(1)
              }}
              value={statusFilter}
            >
              <option value="">{t("All statuses", "Todos los estados")}</option>
              {statuses.map((status) => (
                <option key={status} value={status}>{formatRegistryValue(status, t('Unknown status', 'Estado desconocido'))}</option>
              ))}
            </select>
          </label>

          <label className="audit-control">
            <span>{t("Country", "País")}</span>
            <select
              onChange={(event) => {
                setCountryFilter(event.target.value)
                setPage(1)
              }}
              value={countryFilter}
            >
              <option value="">{t("All countries", "Todos los países")}</option>
              {countries.map((country) => <option key={country} value={country}>{country}</option>)}
            </select>
          </label>

          <label className="audit-control audit-control--size">
            <span>{t("Rows", "Filas")}</span>
            <select
              onChange={(event) => {
                setPageSize(Number(event.target.value))
                setPage(1)
              }}
              value={pageSize}
            >
              {PAGE_SIZE_OPTIONS.map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </label>

          <button
            className="audit-controls__reset"
            disabled={!hasActiveFilters}
            onClick={resetFilters}
            type="button"
          >
            {t("Clear filters ", "Limpiar filtros ")}</button>
        </div>

        <div className="registry-stay-filter">
          <span id="registry-stay-filter-label">{t('Shelter stay', 'Ingreso / egreso')}</span>
          <div aria-labelledby="registry-stay-filter-label" className="registry-stay-filter__options" role="group">
            {STAY_OPTIONS.map((option) => (
              <button
                aria-pressed={stayFilter === option.value}
                key={option.value || 'all'}
                onClick={() => {
                  setStayFilter(option.value)
                  setPage(1)
                }}
                type="button"
              >
                {option.label()}
              </button>
            ))}
          </div>
        </div>

        <label className="registry-browser__population-filter audit-control">
          <span>{t("Population group", "Grupo poblacional")}</span>
          <select
            onChange={(event) => {
              setPopulationGroupFilter(event.target.value)
              setPage(1)
            }}
            value={populationGroupFilter}
          >
            <option value="">{t("All population groups", "Todos los grupos poblacionales")}</option>
            {populationGroups.map((group) => <option key={group} value={group}>{formatRegistryValue(group)}</option>)}
          </select>
        </label>

        <div className="audit-pagination">
          <p>
            {t("Showing ", "Mostrando ")}<strong>{firstVisibleRegistration}-{lastVisibleRegistration}</strong> {t("of", "de")}{' '}
            <strong>{filteredEntries.length}</strong>
            {filteredEntries.length !== entries.length ? t(` filtered from ${entries.length}`, ` filtrados de ${entries.length}`) : ''}
          </p>

          <div className="audit-pagination__actions">
            <button disabled={isLoading || currentPage === 1} onClick={() => setPage(currentPage - 1)} type="button">
              {t("Previous ", "Anterior ")}</button>
            <span>{t("Page ", "Página ")}{currentPage} {t("of ", "de ")}{totalPages}</span>
            <button disabled={isLoading || currentPage === totalPages} onClick={() => setPage(currentPage + 1)} type="button">
              {t("Next ", "Siguiente ")}</button>
          </div>
        </div>

        {exitNotice ? <div className="login-feedback login-feedback--success" role="status">{exitNotice}</div> : null}
        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
        {isLoading ? <p className="workspace-panel__copy">{t("Loading current registrations...", "Cargando registros actuales...")}</p> : null}
        {!isLoading && !error && visibleEntries.length === 0 ? (
          <p className="workspace-panel__copy">
            {hasActiveFilters ? t("No registrations match the current filters.", "Ningún registro coincide con los filtros actuales.") : t("No current migrant registrations are available.", "No hay registros actuales de migrantes disponibles.")}
          </p>
        ) : null}
      </section>

      {!isLoading && !error ? (
        <section className="registry-browser__feed">
          {visibleEntries.map((entry) => (
            <article className={`registry-browser__card registry-browser__card--${entry.current_status}`} key={entry.id}>
              <div className="registry-browser__header">
                <div>
                  <h3 className="workspace-panel__title">{getEntryName(entry)}</h3>
                  <p>{t("Registration #", "Registro #")}{entry.id} {t("· received ", "· recibido ")}{formatRegistryDate(entry.created_at, true)}</p>
                </div>
                <div className="audit-card__badges">
                  <span className="registry-browser__badge">{getEntryPopulationGroup(entry)}</span>
                  <span className="registry-browser__status">{formatRegistryValue(entry.current_status, t('Unknown status', 'Estado desconocido'))}</span>
                  {entry.exited_at ? (
                    <span className="registry-browser__stay registry-browser__stay--out">{t('Left ', 'Egresó ')}{formatRegistryDate(entry.exited_at)}</span>
                  ) : entry.current_status === 'approved' ? (
                    <span className="registry-browser__stay registry-browser__stay--in">{t('In the shelter', 'En albergue')}</span>
                  ) : null}
                </div>
              </div>

              {canManageExits && entry.current_status === 'approved' ? (
                <div className="registry-browser__actions">
                  <button
                    className="session-action session-action--quiet session-action--inline"
                    onClick={() => setExitDialog({ entry, mode: entry.exited_at ? 'undo' : 'record' })}
                    type="button"
                  >
                    <AppIcon name={entry.exited_at ? 'refresh' : 'logout'} />
                    {entry.exited_at ? t('Undo exit', 'Deshacer egreso') : t('Record exit', 'Registrar egreso')}
                  </button>
                </div>
              ) : null}
              {entry.exited_at && entry.exit_reason ? (
                <p className="registry-browser__exit-reason">
                  <small>
                    {t('Exit reason', 'Motivo de egreso')}
                    {entry.exited_by?.name ? ` · ${entry.exited_by.name}` : ''}
                  </small>
                  {entry.exit_reason}
                </p>
              ) : null}

              {user.role === 'non_coordinator' && entry.current_status === 'approved' ? (
                <div className="registry-browser__actions">
                  <button
                    className="session-action session-action--quiet session-action--inline"
                    onClick={() => onNavigate?.(`${APP_MIGRANT_REGISTRY_PATH}?mode=edit&entryId=${entry.id}`)}
                    type="button"
                  >
                    <AppIcon name="document" />
                    {t("Request edit ", "Solicitar edición ")}</button>
                </div>
              ) : null}

              <div className="registry-browser__context">
                <span><small>{t("Origin", "Origen")}</small><strong>{getEntryCountry(entry)}</strong></span>
                <span><small>{t("State", "Estado")}</small><strong>{formatRegistryValue(entry.payload_json.departmentState)}</strong></span>
                <span><small>{t("Attention date", "Fecha de atención")}</small><strong>{formatRegistryDate(entry.payload_json.attentionDate)}</strong></span>
                <span><small>{t("Submitted by", "Enviado por")}</small><strong>{entry.creator?.email ?? formatRegistryValue(entry.created_by_role)}</strong></span>
              </div>

              <details
                className="registry-browser__details"
                onToggle={(event) => {
                  if (event.currentTarget.open) {
                    revealDocuments(entry.id)
                  }
                }}
              >
                <summary>{t("View registration details", "Ver detalles del registro")}</summary>
                <MigrantQuestionnaireViewer payload={entry.payload_json} />
                <dl>
                  <div><dt>{t("First name", "Nombre")}</dt><dd>{formatRegistryValue(entry.payload_json.firstName)}</dd></div>
                  <div><dt>{t("First last name", "Primer apellido")}</dt><dd>{formatRegistryValue(entry.payload_json.firstLastName)}</dd></div>
                  <div><dt>{t("Second last name", "Segundo apellido")}</dt><dd>{formatRegistryValue(entry.payload_json.secondLastName)}</dd></div>
                  <div><dt>{t("Birth date", "Fecha de nacimiento")}</dt><dd>{formatRegistryDate(entry.payload_json.birthDate)}</dd></div>
                  <div><dt>{t("Gender", "Género")}</dt><dd>{formatRegistryValue(entry.payload_json.gender)}</dd></div>
                  <div><dt>{t("Civil status", "Estado civil")}</dt><dd>{formatRegistryValue(entry.payload_json.civilStatus)}</dd></div>
                  <div><dt>{t("Phone", "Teléfono")}</dt><dd>{formatRegistryValue(entry.payload_json.phone)}</dd></div>
                  <div><dt>{t("Last updated", "Última actualización")}</dt><dd>{formatRegistryDate(entry.updated_at, true)}</dd></div>
                </dl>
                {typeof entry.payload_json.notes === 'string' && entry.payload_json.notes.trim() ? (
                  <p className="registry-browser__notes"><small>{t("Notes", "Notas")}</small>{entry.payload_json.notes}</p>
                ) : null}
                {user.role === 'admin' ? (
                  <div className="registry-browser__actions">
                    <button
                      className="session-action session-action--quiet session-action--inline"
                      disabled={pendingPdfEntryId === entry.id}
                      onClick={() => void downloadRegistryPdf(entry)}
                      type="button"
                    >
                      <AppIcon name="download" />
                      {pendingPdfEntryId === entry.id
                        ? t('Authenticating...', 'Autenticando...')
                        : t('Download registration PDF', 'Descargar PDF del registro')}
                    </button>
                  </div>
                ) : null}
                {pdfErrors[entry.id] ? <div className="login-feedback login-feedback--error">{pdfErrors[entry.id]}</div> : null}
                {pdfFeedback[entry.id] ? <div className="login-feedback login-feedback--success">{pdfFeedback[entry.id]}</div> : null}
                {migrantDocumentsEnabled && user.role !== 'volunteer' && documentEntryIds.has(entry.id) ? (
                  <section className="registry-browser__documents">
                    <h4>{t("Supporting documents", "Documentos de soporte")}</h4>
                    <MigrantDocumentsPanel
                      canDelete={false}
                      canDownload={user.role === 'admin' || user.role === 'coordinator'}
                      canDownloadArcoApproved={user.role === 'non_coordinator'}
                      canView
                      embedded
                      entryId={entry.id}
                      onSessionExpired={onSessionExpired}
                    />
                  </section>
                ) : null}
              </details>
            </article>
          ))}
        </section>
      ) : null}

      {exitDialog ? (
        <ShelterExitDialog
          entry={exitDialog.entry}
          entryName={getEntryName(exitDialog.entry)}
          mode={exitDialog.mode}
          onClose={() => setExitDialog(null)}
          onSaved={saveExit}
          onSessionExpired={onSessionExpired}
        />
      ) : null}
    </section>
  )
}
