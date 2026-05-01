import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import type { Locale, TKey } from './types'
import { en } from './locales/en'
import { ru } from './locales/ru'

export const LOCALE_STORAGE_KEY = 'idm_locale'

export type ActionMessage = {
  messageKey: string
  params?: Record<string, string | number>
}

const GENERIC_REQUEST_FAILED_EN =
  'Request failed. Check API availability/CORS settings and try again.'

export function isGenericRequestFailedMessage(message: string): boolean {
  return message === GENERIC_REQUEST_FAILED_EN
}

export function detectInitialLocale(): Locale {
  try {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (stored === 'en' || stored === 'ru') return stored
  } catch {
    /* ignore */
  }
  if (typeof navigator !== 'undefined' && navigator.language?.toLowerCase().startsWith('ru')) {
    return 'ru'
  }
  return 'en'
}

function getLeaf(dict: unknown, path: string): string | undefined {
  const parts = path.split('.')
  let cur: unknown = dict
  for (const p of parts) {
    if (cur === null || typeof cur !== 'object') return undefined
    cur = (cur as Record<string, unknown>)[p]
  }
  return typeof cur === 'string' ? cur : undefined
}

function interpolate(template: string, params?: Record<string, string | number>): string {
  if (!params) return template
  return template.replace(/\{\{(\w+)\}\}/g, (_, key: string) => {
    const v = params[key]
    return v !== undefined && v !== null ? String(v) : ''
  })
}

type LocaleContextValue = {
  locale: Locale
  setLocale: (locale: Locale) => void
  t: (
    key: TKey | string,
    params?: Record<string, string | number> & { defaultValue?: string },
  ) => string
}

const LocaleContext = createContext<LocaleContextValue | null>(null)

export function LocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(() => detectInitialLocale())

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next)
    try {
      localStorage.setItem(LOCALE_STORAGE_KEY, next)
    } catch {
      /* ignore */
    }
  }, [])

  const dict = locale === 'ru' ? ru : en

  useEffect(() => {
    if (typeof document !== 'undefined') {
      document.documentElement.lang = locale === 'ru' ? 'ru' : 'en'
    }
  }, [locale])

  const t = useCallback(
    (
      key: TKey | string,
      params?: Record<string, string | number> & { defaultValue?: string },
    ) => {
      const defaultValue = params?.defaultValue
      const rest: Record<string, string | number> | undefined = params
        ? Object.fromEntries(
            Object.entries(params).filter(([k]) => k !== 'defaultValue'),
          ) as Record<string, string | number>
        : undefined

      let template =
        getLeaf(dict, key) ??
        (locale !== 'en' ? getLeaf(en, key) : undefined) ??
        defaultValue ??
        String(key)
      template = interpolate(template, rest)
      return template
    },
    [dict, locale],
  )

  const value = useMemo<LocaleContextValue>(
    () => ({
      locale,
      setLocale,
      t,
    }),
    [locale, setLocale, t],
  )

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export function useLocale(): Pick<LocaleContextValue, 'locale' | 'setLocale'> {
  const ctx = useContext(LocaleContext)
  if (!ctx) {
    throw new Error('useLocale must be used within LocaleProvider')
  }
  return { locale: ctx.locale, setLocale: ctx.setLocale }
}

export function useT(): LocaleContextValue['t'] {
  const ctx = useContext(LocaleContext)
  if (!ctx) {
    throw new Error('useT must be used within LocaleProvider')
  }
  return ctx.t
}

export { GENERIC_REQUEST_FAILED_EN }
