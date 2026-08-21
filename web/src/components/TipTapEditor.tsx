import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import { useEffect } from 'react'

type Props = {
  value: string
  onChange: (html: string) => void
  placeholder?: string
}

export function TipTapEditor({ value, onChange }: Props) {
  const editor = useEditor({
    extensions: [
      StarterKit,
      Underline,
      Image.configure({ allowBase64: false }),
      Link.configure({ openOnClick: false }),
    ],
    content: value || '',
    onUpdate: ({ editor: e }) => onChange(e.getHTML()),
  })

  useEffect(() => {
    if (!editor) return
    if (editor.getHTML() !== value) {
      editor.commands.setContent(value || '', { emitUpdate: false })
    }
  }, [value, editor])

  if (!editor) return null

  const btn = (label: string, action: () => void, active?: boolean) => (
    <button
      type="button"
      className={`px-2 py-1 text-xs rounded border ${active ? 'bg-teal-700 text-white' : 'bg-white dark:bg-stone-800'}`}
      onClick={action}
    >
      {label}
    </button>
  )

  return (
    <div className="rounded-xl border border-[var(--color-line)] overflow-hidden bg-white/80 dark:bg-stone-900">
      <div className="flex flex-wrap gap-1 p-2 border-b border-[var(--color-line)] bg-stone-50 dark:bg-stone-950">
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
        {btn('Image', () => {
          const url = window.prompt('Image URL')
          if (url) editor.chain().focus().setImage({ src: url }).run()
        })}
        {btn('Embed', () => {
          const url = window.prompt('YouTube/Vimeo embed URL')
          if (!url) return
          editor.commands.insertContent(
            `<iframe src="${url}" allowfullscreen></iframe><p></p>`,
          )
        })}
      </div>
      <EditorContent editor={editor} className="prose prose-sm max-w-none p-3 min-h-28 focus:outline-none" />
    </div>
  )
}
