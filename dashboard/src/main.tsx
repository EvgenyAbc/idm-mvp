import React from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { LocaleProvider } from './shared/i18n'
import { ModalProvider } from './shared/ui/modal/ModalProvider'
import { router } from './router'
import './styles.css'

const rootEl = document.getElementById('root')
if (!rootEl) {
  throw new Error('Root element #root not found')
}

createRoot(rootEl).render(
  <React.StrictMode>
    <LocaleProvider>
      <ModalProvider>
        <RouterProvider router={router} />
      </ModalProvider>
    </LocaleProvider>
  </React.StrictMode>
)
