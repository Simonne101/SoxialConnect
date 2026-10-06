// Service Media.
// - Backend branché (VITE_API_URL défini) : /api/v1/media (upload sécurisé, stockage privé, URLs signées).
// - Sinon : démonstration en mémoire (upload simulé, aucun fichier envoyé), même contrat.
import type { MediaAsset, MediaFilters, MediaFolder, MediaPage, MediaStatus, MediaType, ScanStatus } from "@/types"
import { ApiError, http, isApiEnabled } from "@/lib/http"
import { uid } from "@/mocks/helpers"
import { delay, notFound, store } from "./mockStore"

const DEFAULT_PER_PAGE = 20

// --- Conversion API ↔ frontend (backend/app/Http/Resources/MediaResource.php) -----------------

export interface ApiMedia {
  id: string
  type: MediaType
  status: MediaStatus
  scan_status: ScanStatus
  name: string
  mime_type: string | null
  extension: string | null
  size_bytes: number
  width: number | null
  height: number | null
  duration_ms: number | null
  folder_id: string | null
  tags: string[]
  is_gif: boolean
  usable: boolean
  url: string | null
  thumbnail_url: string | null
  download_url: string | null
  failure_reason: string | null
  created_at: string
}

interface ApiFolder {
  id: string
  name: string
  parent_id: string | null
  media_count: number
}

interface ApiMediaMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  antivirus?: { available: boolean; required: boolean }
}

export function toMediaAsset(m: ApiMedia): MediaAsset {
  // Vignette : miniature générée, sinon l'original pour une image ; vidéo sans FFmpeg → emplacement neutre.
  const src = m.thumbnail_url ?? (m.type === "image" ? m.url : null)
  return {
    id: m.id,
    type: m.type,
    status: m.status,
    scanStatus: m.scan_status,
    usable: m.usable,
    name: m.name,
    mimeType: m.mime_type ?? "",
    extension: m.extension,
    sizeBytes: m.size_bytes,
    width: m.width,
    height: m.height,
    durationMs: m.duration_ms,
    folderId: m.folder_id,
    tags: m.tags ?? [],
    preview: src ? { kind: "image", src } : { kind: "placeholder" },
    url: m.url,
    downloadUrl: m.download_url,
    failureReason: m.failure_reason,
    isGif: m.is_gif,
    createdAt: m.created_at,
  }
}

const toFolder = (f: ApiFolder): MediaFolder => ({ id: f.id, name: f.name, parentId: f.parent_id, count: f.media_count })

function toQuery(f: MediaFilters): string {
  const q = new URLSearchParams()
  if (f.search?.trim()) q.set("search", f.search.trim())
  if (f.type && f.type !== "all") q.set("type", f.type)
  if (f.folderId && f.folderId !== "all") q.set("folder", f.folderId)
  if (f.tag) q.set("tag", f.tag)
  if (f.sort) q.set("sort", f.sort)
  if (f.direction) q.set("direction", f.direction)
  for (const id of f.ids ?? []) q.append("ids[]", id)
  q.set("page", String(f.page ?? 1))
  q.set("per_page", String(f.perPage ?? DEFAULT_PER_PAGE))
  return q.toString()
}

// --- Lecture -------------------------------------------------------------------------------------

export async function listMedia(filters: MediaFilters = {}): Promise<MediaPage> {
  if (isApiEnabled) {
    const res = await http.get<ApiMedia[]>(`/media?${toQuery(filters)}`)
    const meta = res!.meta as unknown as ApiMediaMeta
    return {
      items: res!.data.map(toMediaAsset),
      page: meta.current_page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
      antivirus: meta.antivirus ?? { available: false, required: false },
    }
  }
  return mockList(filters)
}

/** Médias précis (vignettes des publications), par lots de 100. */
export async function getMediaByIds(ids: string[]): Promise<MediaAsset[]> {
  const unique = [...new Set(ids)]
  if (unique.length === 0) return []
  if (!isApiEnabled) return getMediaSync(unique)
  const batches: string[][] = []
  for (let i = 0; i < unique.length; i += 100) batches.push(unique.slice(i, i + 100))
  const pages = await Promise.all(batches.map((ids) => listMedia({ ids, perPage: 100 })))
  return pages.flatMap((p) => p.items)
}

export async function listFolders(): Promise<MediaFolder[]> {
  if (isApiEnabled) {
    const res = await http.get<ApiFolder[]>("/media/folders")
    return res!.data.map(toFolder)
  }
  await delay(150)
  return store.folders.map((f) => ({
    ...f,
    count: store.media.filter((m) => m.folderId === f.id && m.status !== "deleted").length,
  }))
}

export async function listTags(): Promise<string[]> {
  if (isApiEnabled) {
    const res = await http.get<string[]>("/media/tags")
    return res!.data
  }
  await delay(100)
  return [...new Set(store.media.filter((m) => m.status !== "deleted").flatMap((m) => m.tags))].sort()
}

// --- Dossiers ------------------------------------------------------------------------------------

export async function createFolder(v: { name: string; parentId?: string | null }): Promise<MediaFolder> {
  if (isApiEnabled) {
    const res = await http.post<ApiFolder>("/media/folders", { name: v.name, parent_id: v.parentId ?? null })
    return toFolder(res!.data)
  }
  await delay(250)
  if (store.folders.some((f) => f.name === v.name && f.parentId === (v.parentId ?? null))) {
    throw new ApiError(422, "Un dossier porte déjà ce nom à cet endroit.", { name: ["Un dossier porte déjà ce nom à cet endroit."] })
  }
  const folder: MediaFolder = { id: uid("f"), name: v.name, parentId: v.parentId ?? null, count: 0 }
  store.folders.push(folder)
  return folder
}

export async function renameFolder(v: { id: string; name: string }): Promise<MediaFolder> {
  if (isApiEnabled) {
    const res = await http.patch<ApiFolder>(`/media/folders/${encodeURIComponent(v.id)}`, { name: v.name })
    return toFolder(res!.data)
  }
  await delay(200)
  const folder = store.folders.find((f) => f.id === v.id) ?? notFound("Dossier")
  folder.name = v.name
  return { ...folder }
}

/** Supprime le dossier et ses sous-dossiers ; les médias reviennent à la racine (jamais supprimés). */
export async function deleteFolder(id: string): Promise<void> {
  if (isApiEnabled) {
    await http.delete(`/media/folders/${encodeURIComponent(id)}`)
    return
  }
  await delay(250)
  const ids = new Set([id])
  let grew = true
  while (grew) {
    grew = false
    for (const f of store.folders) {
      if (f.parentId && ids.has(f.parentId) && !ids.has(f.id)) {
        ids.add(f.id)
        grew = true
      }
    }
  }
  for (const m of store.media) if (m.folderId && ids.has(m.folderId)) m.folderId = null
  store.folders = store.folders.filter((f) => !ids.has(f.id))
}

// --- Import ----------------------------------------------------------------------------------------

// Liste blanche côté client (confort UX) — la validation réelle est serveur (docs/security.md §7).
const ACCEPTED: Record<string, MediaType> = {
  "image/jpeg": "image",
  "image/png": "image",
  "image/webp": "image",
  "image/gif": "image",
  "video/mp4": "video",
  "video/quicktime": "video",
  "video/webm": "video",
  "audio/mpeg": "audio",
  "audio/mp4": "audio",
  "audio/x-m4a": "audio",
  "audio/wav": "audio",
  "audio/x-wav": "audio",
  "application/pdf": "document",
}

export const ACCEPT_ATTRIBUTE = Object.keys(ACCEPTED).join(",")

export const MAX_UPLOAD_BYTES: Record<MediaType, number> = {
  image: 20 * 1024 * 1024,
  video: 1024 * 1024 * 1024,
  audio: 200 * 1024 * 1024,
  document: 25 * 1024 * 1024,
}

export function validateFile(file: File): { ok: true; type: MediaType } | { ok: false; reason: string } {
  const type = ACCEPTED[file.type]
  if (!type) return { ok: false, reason: "Format non accepté. Formats possibles : JPG, PNG, WebP, GIF, MP4, MOV, WebM, MP3, M4A, WAV, PDF." }
  if (file.size === 0) return { ok: false, reason: "Le fichier est vide." }
  if (file.size > MAX_UPLOAD_BYTES[type]) return { ok: false, reason: "Fichier trop volumineux pour ce type de média." }
  return { ok: true, type }
}

export interface UploadResult {
  media: MediaAsset
  duplicates: number
}

/** Envoie un fichier ; onProgress reçoit 0-100 pendant l'envoi. */
export async function uploadMedia(file: File, folderId: string | null, onProgress?: (percent: number) => void): Promise<UploadResult> {
  if (isApiEnabled) {
    const form = new FormData()
    form.append("file", file)
    if (folderId) form.append("folder_id", folderId)
    const res = await http.upload<ApiMedia>("/media", form, onProgress)
    return { media: toMediaAsset(res.data), duplicates: Number((res.meta as { duplicates?: number }).duplicates ?? 0) }
  }
  return mockUpload(file, folderId, onProgress)
}

// --- Modifications -------------------------------------------------------------------------------

export async function updateMedia(id: string, patch: { name?: string; folderId?: string | null }): Promise<MediaAsset> {
  if (isApiEnabled) {
    const body: Record<string, unknown> = {}
    if (patch.name !== undefined) body.name = patch.name
    if (patch.folderId !== undefined) body.folder_id = patch.folderId
    const res = await http.patch<ApiMedia>(`/media/${encodeURIComponent(id)}`, body)
    return toMediaAsset(res!.data)
  }
  await delay(250)
  const m = store.media.find((x) => x.id === id) ?? notFound("Média")
  if (patch.name !== undefined) m.name = patch.name
  if (patch.folderId !== undefined) m.folderId = patch.folderId
  return structuredClone(m)
}

/** Normalisation identique au serveur (App\Domain\Media\Support\MediaTag). */
export const normalizeTag = (tag: string) => tag.trim().replace(/^#+/, "").replace(/\s+/g, " ").trim().toLowerCase()

export async function addTag(id: string, tag: string): Promise<MediaAsset> {
  if (isApiEnabled) {
    const res = await http.post<ApiMedia>(`/media/${encodeURIComponent(id)}/tags`, { tag })
    return toMediaAsset(res!.data)
  }
  await delay(150)
  const m = store.media.find((x) => x.id === id) ?? notFound("Média")
  const t = normalizeTag(tag)
  if (t && !m.tags.includes(t)) m.tags = [...m.tags, t].sort()
  return structuredClone(m)
}

export async function removeTag(id: string, tag: string): Promise<MediaAsset> {
  if (isApiEnabled) {
    const res = await http.delete<ApiMedia>(`/media/${encodeURIComponent(id)}/tags/${encodeURIComponent(tag)}`)
    return toMediaAsset(res!.data)
  }
  await delay(150)
  const m = store.media.find((x) => x.id === id) ?? notFound("Média")
  m.tags = m.tags.filter((t) => t !== tag)
  return structuredClone(m)
}

/** Supprime plusieurs médias ; ceux utilisés par une publication programmée sont refusés (409). */
export async function deleteMedia(ids: string[], names: Record<string, string> = {}): Promise<{ deleted: string[]; blocked: string[]; failed: string[] }> {
  if (isApiEnabled) {
    const results = await Promise.allSettled(ids.map((id) => http.delete(`/media/${encodeURIComponent(id)}`)))
    const out = { deleted: [] as string[], blocked: [] as string[], failed: [] as string[] }
    results.forEach((r, i) => {
      const id = ids[i]
      if (r.status === "fulfilled") out.deleted.push(id)
      else if (r.reason instanceof ApiError && r.reason.status === 409) out.blocked.push(names[id] ?? id)
      else out.failed.push(names[id] ?? id)
    })
    return out
  }
  await delay(400)
  const blocked: string[] = []
  const deleted: string[] = []
  for (const id of ids) {
    const used = store.posts.some((p) => p.status === "scheduled" && p.mediaIds.includes(id))
    const m = store.media.find((x) => x.id === id)
    if (!m) continue
    if (used) blocked.push(m.name)
    else {
      m.status = "deleted"
      deleted.push(id)
    }
  }
  return { deleted, blocked, failed: [] }
}

// --- Démonstration (sans backend) ------------------------------------------------------------------

export function getMediaSync(ids: string[]): MediaAsset[] {
  return ids.map((id) => store.media.find((m) => m.id === id)).filter((m): m is MediaAsset => Boolean(m)).map((m) => structuredClone(m))
}

async function mockList(filters: MediaFilters): Promise<MediaPage> {
  await delay()
  const q = filters.search?.trim().toLowerCase()
  const tag = filters.tag ? normalizeTag(filters.tag) : null
  const dir = filters.direction === "asc" ? 1 : -1
  const key = filters.sort ?? "created_at"
  const matching = store.media
    .filter((m) => m.status !== "deleted")
    .filter((m) => (filters.ids ? filters.ids.includes(m.id) : true))
    .filter((m) => (filters.type === "gif" ? m.isGif : filters.type && filters.type !== "all" ? m.type === filters.type : true))
    .filter((m) => (filters.folderId === "root" ? m.folderId === null : filters.folderId && filters.folderId !== "all" ? m.folderId === filters.folderId : true))
    .filter((m) => (tag ? m.tags.includes(tag) : true))
    .filter((m) => (q ? m.name.toLowerCase().includes(q) : true))
    .sort((a, b) => {
      const va = key === "name" ? a.name : key === "size" ? a.sizeBytes : new Date(a.createdAt).getTime()
      const vb = key === "name" ? b.name : key === "size" ? b.sizeBytes : new Date(b.createdAt).getTime()
      return (va < vb ? -1 : va > vb ? 1 : 0) * dir
    })
  const perPage = filters.perPage ?? DEFAULT_PER_PAGE
  const page = filters.page ?? 1
  return {
    items: matching.slice((page - 1) * perPage, page * perPage).map((m) => structuredClone(m)),
    page,
    perPage,
    total: matching.length,
    lastPage: Math.max(1, Math.ceil(matching.length / perPage)),
    antivirus: { available: false, required: false },
  }
}

/** Démonstration : upload simulé (progression puis traitement), aucun fichier n'est envoyé. */
async function mockUpload(file: File, folderId: string | null, onProgress?: (percent: number) => void): Promise<UploadResult> {
  const check = validateFile(file)
  if (!check.ok) throw new ApiError(422, check.reason, { file: [check.reason] })
  for (let p = 20; p <= 100; p += 20) {
    await delay(200)
    onProgress?.(p)
  }
  const asset: MediaAsset = {
    id: uid("m"),
    type: check.type,
    status: "ready",
    scanStatus: "pending",
    usable: true,
    name: file.name,
    mimeType: file.type,
    extension: file.name.split(".").pop()?.toLowerCase() ?? null,
    sizeBytes: file.size,
    width: null,
    height: null,
    durationMs: null,
    folderId,
    tags: [],
    preview: { kind: "gradient", className: `thumb-${(store.media.length % 12) + 1}` },
    url: null,
    downloadUrl: null,
    failureReason: null,
    isGif: file.type === "image/gif",
    createdAt: new Date().toISOString(),
  }
  store.media.unshift(asset)
  return { media: structuredClone(asset), duplicates: 0 }
}
