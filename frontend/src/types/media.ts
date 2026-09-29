// Types du domaine Media — alignés sur docs/database.md (media, media_folders).

export type MediaType = "image" | "video" | "audio" | "document"
export type MediaStatus = "uploading" | "processing" | "ready" | "failed" | "deleted"
/** Analyse antivirus : « pending » = non analysé (jamais présenté comme sain). */
export type ScanStatus = "pending" | "clean" | "infected" | "error"

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
  scanStatus: ScanStatus
  /** Prêt et hors quarantaine : peut être affiché, téléchargé et publié. */
  usable: boolean
  name: string
  mimeType: string
  extension: string | null
  sizeBytes: number
  width: number | null
  height: number | null
  durationMs: number | null
  folderId: string | null
  tags: string[]
  /**
   * Vignette : dégradé fictif (démonstration), image (vignette ou original signé),
   * ou emplacement neutre avec l'icône du type (vidéo sans FFmpeg, audio, document).
   */
  preview: { kind: "gradient"; className: string } | { kind: "image"; src: string } | { kind: "placeholder" }
  /** URLs signées courtes (backend) ; null en quarantaine ou en démonstration. */
  url: string | null
  downloadUrl: string | null
  failureReason: string | null
  isGif: boolean
  createdAt: string
  /** Progression d'upload simulée (0-100) pendant « uploading » (démonstration). */
  progress?: number
}

export type MediaSort = "created_at" | "name" | "size"

export interface MediaFilters {
  search?: string
  /** « gif » : sous-type d'image. */
  type?: MediaType | "gif" | "all"
  /** « root » : médias hors dossier. */
  folderId?: string | "all" | "root"
  tag?: string | null
  sort?: MediaSort
  direction?: "asc" | "desc"
  page?: number
  perPage?: number
  /** Médias précis (vignettes des publications). */
  ids?: string[]
}

/** Page de la médiathèque (GET /api/v1/media). */
export interface MediaPage {
  items: MediaAsset[]
  page: number
  perPage: number
  total: number
  lastPage: number
  antivirus: { available: boolean; required: boolean }
}

/** Import en cours (file d'attente d'upload). */
export type UploadState = "pending" | "uploading" | "processing" | "ready" | "failed" | "quarantined"

export interface UploadItem {
  id: string
  name: string
  type: MediaType | null
  sizeBytes: number
  progress: number
  state: UploadState
  error: string | null
  mediaId: string | null
  /** Doublon d'un média existant (information : le fichier est importé quand même). */
  duplicate: boolean
}
