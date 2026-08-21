import { useEffect, useRef } from 'react'

export function RichContent({ html, className = '' }: { html: string; className?: string }) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.querySelectorAll('audio, video').forEach((node) => {
      node.setAttribute('preload', 'none')
      node.setAttribute('controls', 'controls')
      node.removeAttribute('autoplay')
    })
    el.querySelectorAll('img').forEach((img) => {
      img.setAttribute('loading', 'lazy')
    })
  }, [html])

  return (
    <div
      ref={ref}
      className={`rich-content prose prose-stone max-w-none dark:prose-invert ${className}`}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  )
}
