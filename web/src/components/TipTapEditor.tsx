import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import { uploadProjectImage } from '../lib/mediaUpload'

type Props = {
  value: string
  /** Project to store uploads under (required for upload button). */
  projectId?: string
  /** Fired while typing — use for local preview only, not for network saves. */
  onChange?: (html: string) => void
  /** Fired when the editor loses focus — preferred place to persist. */
  onBlurSave?: (html: string) => void
  placeholder?: string
  editable?: boolean
}

export function TipTapEditor({ value, projectId, onChange, onBlurSave, editable = true }: Props) {
  const readyRef = useRef(false)
  const lastEmittedRef = useRef(value || '')
  const fileInputRef = useRef<HTMLInputElement>(null)
  const onChangeRef = useRef(onChange)
  const onBlurSaveRef = useRef(onBlurSave)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  onChangeRef.current = onChange
  onBlurSaveRef.current = onBlurSave

  const editor = useEditor({
    extensions: [
      StarterKit,
      Underline,
      Image.configure({ allowBase64: false }),
      Link.configure({ openOnClick: false }),
    ],
    content: value || '',
    editable,
    immediatelyRender: false,
    onUpdate: ({ editor: e }) => {
      if (!readyRef.current) return
      const html = e.getHTML()
      lastEmittedRef.current = html
      onChangeRef.current?.(html)
    },
  })

  // Sync external value changes (quiz switch / hydrate) without fighting keystrokes.
  useEffect(() => {
    if (!editor) return
    const incoming = value || ''
    if (incoming === lastEmittedRef.current) return
    if (incoming === editor.getHTML()) {
      lastEmittedRef.current = incoming
      return
    }
    // Never reset document while the user is typing in this editor.
    if (editor.isFocused) return

    readyRef.current = false
    editor.commands.setContent(incoming, { emitUpdate: false })
    lastEmittedRef.current = incoming
    readyRef.current = true
  }, [value, editor])

  useEffect(() => {
    if (!editor) return
    readyRef.current = true
    const handleBlur = () => {
      const html = editor.getHTML()
      lastEmittedRef.current = html
      onBlurSaveRef.current?.(html)
    }
    editor.on('blur', handleBlur)
    return () => {
      editor.off('blur', handleBlur)
    }
  }, [editor])

  useEffect(() => {
    if (!editor) return
    editor.setEditable(editable)
  }, [editor, editable])

  const insertImageUrl = (url: string) => {
    if (!editor) return
    editor.chain().focus().setImage({ src: url }).run()
  }

  const onFileSelected = async (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file || !projectId || !editor) return
    setUploadError(null)
    setUploading(true)
    try {
      const res = await uploadProjectImage(projectId, file)
      insertImageUrl(res.url)
    } catch (err) {
      setUploadError(err instanceof Error ? err.message : 'Upload failed')
    } finally {
      setUploading(false)
    }
  }

  if (!editor) return null

  const btn = (label: string, action: () => void, active?: boolean, disabled?: boolean) => (
    <button
      type="button"
      tabIndex={-1}
      disabled={!editable || disabled}
      className={`px-2 py-1 text-xs border border-[var(--color-line)] disabled:opacity-40 ${
        active
          ? 'bg-[var(--color-accent)] text-white'
          : 'bg-white hover:bg-[var(--color-accent-soft)]'
      }`}
      onClick={action}
    >
      {label}
    </button>
  )

  return (
    <div
      className={`border border-[var(--color-line)] overflow-hidden bg-white ${
        editable ? '' : 'opacity-75'
      }`}
    >
      <div className="flex flex-wrap gap-1 p-2 border-b border-[var(--color-line)] bg-[var(--color-paper)]">
        {btn('B', () => editor.chain().focus().toggleBold().run(), editor.isActive('bold'))}
        {btn('I', () => editor.chain().focus().toggleItalic().run(), editor.isActive('italic'))}
        {btn('U', () => editor.chain().focus().toggleUnderline().run(), editor.isActive('underline'))}
        {btn('H2', () => editor.chain().focus().toggleHeading({ level: 2 }).run())}
        {btn('•', () => editor.chain().focus().toggleBulletList().run())}
        {btn('1.', () => editor.chain().focus().toggleOrderedList().run())}
        {btn('Link', () => {
          const url = window.prompt('URL')
          if (url) editor.chain().focus().setLink({ href: url }).run()
        })}
        {btn(
          uploading ? 'Uploading…' : 'Upload',
          () => fileInputRef.current?.click(),
          false,
          uploading || !projectId,
        )}
        {btn('Image URL', () => {
          const url = window.prompt('Image URL')
          if (url) insertImageUrl(url)
        })}
        {btn('Embed', () => {
          const url = window.prompt('YouTube/Vimeo embed URL')
          if (!url) return
          editor.commands.insertContent(`<iframe src="${url}" allowfullscreen></iframe><p></p>`)
        })}
        <input
          ref={fileInputRef}
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
          className="hidden"
          onChange={onFileSelected}
        />
      </div>
      {uploadError && (
        <p className="px-3 py-1 text-xs text-red-700 bg-red-50 border-b border-red-200">{uploadError}</p>
      )}
      <EditorContent editor={editor} className="prose prose-sm max-w-none p-3 min-h-28 focus:outline-none" />
    </div>
  )
}
