// Service Media — futur : /api/v1/media (upload présigné, antivirus, traitement asynchrone).
// Ici : upload simulé côté navigateur, aucun fichier n'est envoyé.
import type { MediaAsset, MediaFilters, MediaFolder, MediaType } from "@/types"
import { uid } from "@/mocks/helpers"
import { delay, notFound, store } from "./mockStore"

export async function listMedia(filters: MediaFilters = {}): Promise<MediaAsset[]> {
  await delay()
  const q = filters.search?.trim().toLowerCase()
  return store.media
    .filter((m) => m.status !== "deleted")
    .filter((m) => (filters.type && filters.type !== "all" ? m.type === filters.type : true))
    .filter((m) => (filters.folderId && filters.folderId !== "all" ? m.folderId === filters.folderId : true))
    .filter((m) => (filters.tag ? m.tags.includes(filters.tag) : true))
    .filter((m) => (q ? `${m.name} ${m.tags.join(" ")}`.toLowerCase().includes(q) : true))
    .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
    .map((m) => structuredClone(m))
}

export async function listFolders(): Promise<MediaFolder[]> {
  await delay(150)
  return store.folders.map((f) => ({
    ...f,
    count: store.media.filter((m) => m.folderId === f.id && m.status !== "deleted").length,
  }))
}

export async function createFolder(name: string): Promise<MediaFolder> {
  await delay(250)
  const folder: MediaFolder = { id: uid("f"), name, parentId: null, count: 0 }
  store.folders.push(folder)
  return folder
}

export async function listTags(): Promise<string[]> {
  await delay(100)
  return [...new Set(store.media.filter((m) => m.status !== "deleted").flatMap((m) => m.tags))].sort()
}

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
  "audio/wav": "audio",
  "application/pdf": "document",
}

export const MAX_UPLOAD_BYTES: Record<MediaType, number> = {
  image: 20 * 1024 * 1024,
  video: 1024 * 1024 * 1024,
  audio: 200 * 1024 * 1024,
  document: 25 * 1024 * 1024,
}

export function validateFile(file: File): { ok: true; type: MediaType } | { ok: false; reason: string } {
  const type = ACCEPTED[file.type]
  if (!type) return { ok: false, reason: "Format non accepté. Formats possibles : JPG, PNG, WebP, GIF, MP4, MOV, WebM, MP3, M4A, WAV, PDF." }
  if (file.size > MAX_UPLOAD_BYTES[type]) return { ok: false, reason: "Fichier trop volumineux pour ce type de média." }
  return { ok: true, type }
}

/** Crée un média en « uploading » ; la progression est simulée par le hook d'upload. */
export async function startUpload(file: File, type: MediaType, folderId: string | null): Promise<MediaAsset> {
  await delay(150)
  const asset: MediaAsset = {
    id: uid("m"),
    type,
    status: "uploading",
    name: file.name,
    mimeType: file.type,
    sizeBytes: file.size,
    width: null,
    height: null,
    durationMs: null,
    folderId,
    tags: [],
    preview: { kind: "gradient", className: `thumb-${(store.media.length % 12) + 1}` },
    isGif: file.type === "image/gif",
    createdAt: new Date().toISOString(),
    progress: 0,
  }
  store.media.unshift(asset)
  return structuredClone(asset)
}

export async function updateUploadProgress(id: string, progress: number): Promise<void> {
  const m = store.media.find((x) => x.id === id)
  if (!m) return
  m.progress = progress
  if (progress >= 100) m.status = "processing"
}

export async function completeProcessing(id: string): Promise<void> {
  const m = store.media.find((x) => x.id === id)
  if (!m) return
  m.status = "ready"
  m.progress = undefined
}

export async function updateMedia(id: string, patch: Pick<Partial<MediaAsset>, "name" | "tags" | "folderId">): Promise<MediaAsset> {
  await delay(250)
  const m = store.media.find((x) => x.id === id) ?? notFound("Média")
  Object.assign(m, patch)
  return structuredClone(m)
}

/** Refuse la suppression d'un média utilisé par une publication programmée (docs/database.md §5). */
export async function deleteMedia(ids: string[]): Promise<{ deleted: string[]; blocked: string[] }> {
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
  return { deleted, blocked }
}

export function getMediaSync(ids: string[]): MediaAsset[] {
  return ids.map((id) => store.media.find((m) => m.id === id)).filter((m): m is MediaAsset => Boolean(m))
}
