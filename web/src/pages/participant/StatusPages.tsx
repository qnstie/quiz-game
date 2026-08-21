import { useTranslation } from 'react-i18next'
import { useBootstrap } from '../../components/ParticipantShell'

export function BlockedPage() {
  const { t } = useTranslation()
  useBootstrap(10_000)
  return (
    <section className="py-20 text-center space-y-4">
      <h1 className="font-display text-3xl font-bold">{t('blocked.title')}</h1>
      <p className="text-[var(--color-muted)] text-lg">{t('blocked.body')}</p>
    </section>
  )
}

export function ClosedPage() {
  const { t } = useTranslation()
  useBootstrap(10_000)
  return (
    <section className="py-20 text-center space-y-4">
      <h1 className="font-display text-3xl font-bold">{t('closed.title')}</h1>
      <p className="text-[var(--color-muted)] text-lg">{t('closed.body')}</p>
    </section>
  )
}
