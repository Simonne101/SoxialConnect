// Types du domaine Media — alignés sur docs/database.md (media, media_folders).

export type MediaType = "image" | "video" | "audio" | "document"
export type MediaStatus = "uploading" | "processing" | "ready" | "failed" | "deleted"

export interface MediaFolder {
  id: string
  name: string
  parentId: string | null
  count: number
}

export interface MediaAsset {
  id: string
  type: MediaType
  status: MediaStatus
  name: string
  mimeType: string
  sizeBytes: number
  width: number | null
  height: number | null
  durationMs: number | null
  folderId: string | null
  tags: string[]
  /** Aperçu fictif : classe CSS de dégradé (design system) ou URL d'asset local. */
  preview: { kind: "gradient"; className: string } | { kind: "image"; src: string }
  isGif: boolean
  createdAt: string
  /** Progression d'upload simulée (0-100) pendant « uploading ». */
  progress?: number
}

export interface MediaFilters {
  search?: string
  type?: MediaType | "all"
  folderId?: string | "all"
  tag?: string | null
}
