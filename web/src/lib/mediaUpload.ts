import { API_BASE, ApiError } from '../api/client'

export type UploadedMedia = {
  id: string
  url: string
  mime: string
  width: number | null
  height: number | null
  filename: string
}

export async function uploadProjectImage(projectId: string, file: File): Promise<UploadedMedia> {
  const form = new FormData()
  form.append('file', file)

  const headers = new Headers()
  const token = localStorage.getItem('fq_user_token')
  if (token) headers.set('X-FQ-Token', token)

  const res = await fetch(`${API_BASE}/api/admin/projects/${projectId}/media`, {
    method: 'POST',
    body: form,
    credentials: 'include',
    headers,
  })

  const ct = res.headers.get('content-type') || ''
  if (!ct.includes('application/json')) {
    if (!res.ok) throw new ApiError('NETWORK', res.statusText || 'Upload failed', res.status)
    throw new ApiError('NETWORK', 'Unexpected upload response', res.status)
  }

  const data = await res.json()
  if (!res.ok) {
    throw new ApiError(
      data?.error?.code ?? 'ERROR',
      data?.error?.message ?? 'Upload failed',
      res.status,
      data?.error,
    )
  }
  return data as UploadedMedia
}
