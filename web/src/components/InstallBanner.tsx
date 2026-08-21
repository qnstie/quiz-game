import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

type BIPEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }

export function InstallBanner() {
  const { t } = useTranslation()
  const [deferred, setDeferred] = useState<BIPEvent | null>(null)
  const [ios, setIos] = useState(false)
  const [dismissed, setDismissed] = useState(() => localStorage.getItem('fq_install_dismissed') === '1')

  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault()
      setDeferred(e as BIPEvent)
    }
    window.addEventListener('beforeinstallprompt', handler)
    const ua = navigator.userAgent
    setIos(/iPad|iPhone|iPod/.test(ua) && !(window as unknown as { MSStream?: unknown }).MSStream)
    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [])

  if (dismissed || (!deferred && !ios)) return null

  return (
    <div className="fixed bottom-0 inset-x-0 z-40 safe-pad">
      <div className="mx-auto max-w-lg mb-3 rounded-2xl bg-[var(--color-ink)] text-[var(--color-paper)] px-4 py-3 shadow-lg flex gap-3 items-start">
        <p className="text-sm flex-1">
          {deferred ? t('common.installHint') : t('common.installIos')}
        </p>
        {deferred && (
          <button
            type="button"
            className="text-sm font-semibold text-teal-300 min-h-10"
            onClick={() => void deferred.prompt()}
          >
            Install
          </button>
        )}
        <button
          type="button"
          className="text-sm opacity-70 min-h-10"
          onClick={() => {
            localStorage.setItem('fq_install_dismissed', '1')
            setDismissed(true)
          }}
        >
          {t('common.dismiss')}
        </button>
      </div>
    </div>
  )
}
