import type { Dictionary } from './locales/en'

export type Locale = 'en' | 'ru'

/** Dotted paths to string leaves in the dictionary */
export type TKey = Leaves<Dictionary>

type Leaves<T, P extends string = ''> = T extends string
  ? P
  : T extends object
    ? { [K in keyof T & string]: Leaves<T[K], P extends '' ? K : `${P}.${K}`> }[keyof T & string]
    : never
