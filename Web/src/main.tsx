import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './App.tsx'
import { LanguageSwitcher } from './components/ui/LanguageSwitcher.tsx'
import { installActingDepartmentHeader } from './lib/departments.ts'
import { translate as t } from './lib/i18n.ts'
import './index.css'

installActingDepartmentHeader()

const rootElement = document.getElementById('root')

if (!rootElement) {
  throw new Error(t('The #root element was not found', 'No se encontró el elemento raíz #root'))
}

createRoot(rootElement).render(
  <StrictMode>
    <LanguageSwitcher />
    <App />
  </StrictMode>,
)
