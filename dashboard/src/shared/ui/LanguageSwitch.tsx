import { useLocale, useT } from '../i18n'

export function LanguageSwitch() {
  const { locale, setLocale } = useLocale()
  const t = useT()

  return (
    <div className="language-switch">
      <select
        className="language-switch-select"
        aria-label={t('layout.languageAria')}
        value={locale}
        onChange={(event) => setLocale(event.target.value as 'en' | 'ru')}
      >
        <option value="en">EN</option>
        <option value="ru">RU</option>
      </select>
    </div>
  )
}
