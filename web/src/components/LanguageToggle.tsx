import { useTranslation } from 'react-i18next'

export function LanguageToggle() {
  const { i18n, t } = useTranslation()
  const next = i18n.language.startsWith('pl') ? 'en' : 'pl'
  return (
    <button
      type="button"
      className="text-sm font-semibold text-[var(--color-accent)] underline-offset-4 hover:underline min-h-12 px-2"
      onClick={() => void i18n.changeLanguage(next)}
      aria-label={t('common.language')}
    >
      {next === 'pl' ? 'PL' : 'EN'}
    </button>
  )
}
