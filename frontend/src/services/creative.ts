// AI Creative Studio (ADR-046) : /api/v1/clipper et /api/v1/ai/images (Laravel). Aucune donnée
// simulée : sans backend, chaque appel est refusé explicitement (FeatureUnavailableError).
import type {
  AiImageGeneration,
  ClipAnalysisMode,
  ClipAspectRatio,
  ClipperCapabilities,
  ClipperClip,
  ClipperProject,
  ClipperSourceStatus,
  ClipScoreBreakdown,
  ImageAspectRatio,
  ClipSubtitleStyle,
  YouTubeVideoPreview,
} from "@/types"
import { FeatureUnavailableError } from "@/lib/features"
import { http, isApiEnabled } from "@/lib/http"
import { toMediaAsset, type ApiMedia } from "./media"

export const CREATIVE_UNAVAILABLE = "L'AI Creative Studio nécessite le serveur SoxialConnect."

// --- Format de l'API -----------------------------------------------------------------------------

interface ApiClip {
  id: string
  title: string
  topic: string | null
  reason: string | null
  start_ms: number
  end_ms: number
  duration_ms: number
  aspect_ratio: ClipAspectRatio
  source: ClipperClip["source"]
  status: ClipperClip["status"]
  render_stage: ClipperClip["renderStage"]
  render_progress?: { step: string; percent: number | null } | null
  queued_ms?: number | null
  subtitles: boolean
  subtitle_style: ClipSubtitleStyle | null
  score: number | null
  hook?: string | null
  confidence?: number | null
  score_breakdown?: ClipScoreBreakdown | null
  error_code: string | null
  error_message: string | null
  output?: ApiMedia | null
  render?: { engine: "remotion" | "ffmpeg" | null; fallback: boolean; hook: boolean; audio?: "ok" | "source_without_audio" | "source_silent" | null } | null
  created_at: string
  finished_at: string | null
}
interface ApiProject {
  id: string
  title: string
  status: ClipperProject["status"]
  youtube_url: string
  youtube_video_id: string | null
  youtube_author: string | null
  youtube_thumbnail: string | null
  renderable: boolean
  aspect_ratio: ClipAspectRatio
  duration_ms: number | null
  error_code: string | null
  error_message: string | null
  analysis: { mode: ClipAnalysisMode; input?: "youtube_url" | "file"; transcription: "local" | "ai" | null; transcription_engine: string | null; fallback: string | null; recalibrated?: number; notes: string[] } | null
  source_status?: { status: ClipperSourceStatus["status"]; ownership: ClipperSourceStatus["ownership"]; method: ClipperSourceStatus["method"]; error_code: string | null; error_message: string | null; purge_at: string | null; rights_attested?: boolean; download_available?: boolean }
  has_transcript: boolean
  max_clip_ms: number
  min_clip_ms: number
  source?: ApiMedia | null
  clips?: ApiClip[]
  created_at: string
}
interface ApiImage {
  id: string
  status: AiImageGeneration["status"]
  prompt: string | null
  aspect_ratio: ImageAspectRatio | null
  width: number | null
  height: number | null
  generated_width: number | null
  generated_height: number | null
  error_code: string | null
  error_message: string | null
  media: ApiMedia | null
  created_at: string
}

const toClip = (c: ApiClip): ClipperClip => ({
  id: c.id,
  title: c.title,
  topic: c.topic,
  reason: c.reason,
  startMs: c.start_ms,
  endMs: c.end_ms,
  durationMs: c.duration_ms,
  aspectRatio: c.aspect_ratio,
  source: c.source,
  status: c.status,
  renderStage: c.render_stage ?? null,
  queuedMs: typeof c.queued_ms === "number" ? c.queued_ms : null,
  renderProgress: c.render_progress ? { step: c.render_progress.step as NonNullable<ClipperClip["renderProgress"]>["step"], percent: typeof c.render_progress.percent === "number" ? c.render_progress.percent : null } : null,
  subtitles: Boolean(c.subtitles),
  subtitleStyle: c.subtitle_style ?? null,
  score: c.score,
  hook: c.hook ?? null,
  confidence: c.confidence ?? null,
  scoreBreakdown: c.score_breakdown ?? null,
  errorCode: c.error_code,
  errorMessage: c.error_message,
  output: c.output ? toMediaAsset(c.output) : null,
  render: c.render ? { engine: c.render.engine, fallback: Boolean(c.render.fallback), hook: Boolean(c.render.hook), audio: c.render.audio ?? null } : null,
  createdAt: c.created_at,
  finishedAt: c.finished_at,
})
const toProject = (p: ApiProject): ClipperProject => ({
  id: p.id,
  title: p.title,
  status: p.status,
  youtubeUrl: p.youtube_url,
  youtubeVideoId: p.youtube_video_id,
  youtubeAuthor: p.youtube_author,
  youtubeThumbnail: p.youtube_thumbnail ?? null,
  renderable: Boolean(p.renderable),
  aspectRatio: p.aspect_ratio,
  durationMs: p.duration_ms,
  errorCode: p.error_code,
  errorMessage: p.error_message,
  analysis: p.analysis
    ? { mode: p.analysis.mode, input: p.analysis.input ?? "file", transcription: p.analysis.transcription, transcriptionEngine: p.analysis.transcription_engine, fallback: p.analysis.fallback, recalibrated: p.analysis.recalibrated ?? null, notes: p.analysis.notes ?? [] }
    : null,
  sourceStatus: p.source_status
    ? { status: p.source_status.status, ownership: p.source_status.ownership, method: p.source_status.method, errorCode: p.source_status.error_code, errorMessage: p.source_status.error_message, purgeAt: p.source_status.purge_at, rightsAttested: Boolean(p.source_status.rights_attested), downloadAvailable: Boolean(p.source_status.download_available) }
    : { status: p.renderable ? "ready" : "pending", ownership: "unverified", method: p.renderable ? "import" : null, errorCode: null, errorMessage: null, purgeAt: null, rightsAttested: false, downloadAvailable: false },
  hasTranscript: Boolean(p.has_transcript),
  maxClipMs: p.max_clip_ms,
  minClipMs: p.min_clip_ms,
  source: p.source ? toMediaAsset(p.source) : null,
  clips: (p.clips ?? []).map(toClip),
  createdAt: p.created_at,
})
const toImage = (g: ApiImage): AiImageGeneration => ({
  id: g.id,
  status: g.status,
  prompt: g.prompt,
  aspectRatio: g.aspect_ratio,
  width: g.width,
  height: g.height,
  generatedWidth: g.generated_width,
  generatedHeight: g.generated_height,
  errorCode: g.error_code,
  errorMessage: g.error_message,
  media: g.media ? toMediaAsset(g.media) : null,
  createdAt: g.created_at,
})
const toCapabilities = (meta: Record<string, unknown>): ClipperCapabilities => ({
  rendererAvailable: Boolean(meta.renderer_available),
  analysisAvailable: Boolean(meta.analysis_available),
  analysisMode: meta.analysis_mode === "ai" || meta.analysis_mode === "local" ? meta.analysis_mode : "none",
  urlAnalysisAvailable: Boolean(meta.url_analysis_available),
  urlAnalysisProvider: typeof meta.url_analysis_provider === "string" ? meta.url_analysis_provider : null,
  automaticSourceAvailable: Boolean(meta.automatic_source_available),
  videoDownloadAvailable: Boolean(meta.video_download_available),
  transcriptionMode: meta.transcription_mode === "local" || meta.transcription_mode === "ai" ? meta.transcription_mode : "none",
  transcriptionEngine: typeof meta.transcription_engine === "string" ? meta.transcription_engine : null,
})

function requireApi(): void {
  if (!isApiEnabled) throw new FeatureUnavailableError(CREATIVE_UNAVAILABLE)
}

// --- YouTube Clipper -----------------------------------------------------------------------------

export async function listClipperProjects(): Promise<{ projects: ClipperProject[]; capabilities: ClipperCapabilities }> {
  requireApi()
  const res = await http.get<ApiProject[]>("/clipper/projects")
  return { projects: res!.data.map(toProject), capabilities: toCapabilities(res!.meta) }
}

export async function getClipperProject(id: string): Promise<{ project: ClipperProject; capabilities: ClipperCapabilities }> {
  requireApi()
  const res = await http.get<ApiProject>(`/clipper/projects/${encodeURIComponent(id)}`)
  return { project: toProject(res!.data), capabilities: toCapabilities(res!.meta) }
}

/** Identifie une vidéo YouTube (lien validé par le serveur, métadonnées oEmbed) avant l'analyse. */
export async function lookupYouTubeVideo(youtubeUrl: string): Promise<YouTubeVideoPreview> {
  requireApi()
  const res = await http.post<{ video_id: string; url: string; title: string; author: string | null; thumbnail: string | null }>("/clipper/lookup", { youtube_url: youtubeUrl })
  const d = res!.data
  return { videoId: d.video_id, url: d.url, title: d.title, author: d.author, thumbnail: d.thumbnail }
}

/** Projet créé depuis le lien ; la vidéo source (facultative ici) n'est exigée que pour le rendu. */
export async function createClipperProject(input: { youtubeUrl: string; mediaId?: string | null; aspectRatio: ClipAspectRatio; rightsConfirmed?: boolean }): Promise<ClipperProject> {
  requireApi()
  const res = await http.post<ApiProject>("/clipper/projects", { youtube_url: input.youtubeUrl, aspect_ratio: input.aspectRatio, ...(input.mediaId ? { media_id: input.mediaId } : {}), ...(input.rightsConfirmed ? { rights_confirmed: true } : {}) })
  return toProject(res!.data)
}

/** Joint la vidéo source rendable (médiathèque) : mesurée, analysée, puis utilisable pour le rendu. */
export async function attachClipperSource(projectId: string, mediaId: string): Promise<ClipperProject> {
  requireApi()
  const res = await http.put<ApiProject>(`/clipper/projects/${encodeURIComponent(projectId)}/source`, { media_id: mediaId })
  return toProject(res!.data)
}

/** Annule le traitement en cours (analyse, recherche de source, rendus) ; les clips déjà prêts restent. */
export async function cancelClipperProject(projectId: string): Promise<ClipperProject> {
  requireApi()
  const res = await http.post<ApiProject>(`/clipper/projects/${encodeURIComponent(projectId)}/cancel`)
  return toProject(res!.data)
}

/** Relance l'analyse (après une erreur temporaire) : même projet, transcription réutilisée. */
export async function reanalyzeClipperProject(projectId: string): Promise<ClipperProject> {
  requireApi()
  const res = await http.post<ApiProject>(`/clipper/projects/${encodeURIComponent(projectId)}/reanalyze`)
  return toProject(res!.data)
}

/**
 * Relance la recherche automatique d'une source vidéo autorisée (ADR-049). rightsConfirmed : attestation
 * de droits enregistrée avant la relance (récupération yt-dlp, ADR-059).
 */
export async function acquireClipperSource(projectId: string, rightsConfirmed = false): Promise<ClipperProject> {
  requireApi()
  const res = await http.post<ApiProject>(`/clipper/projects/${encodeURIComponent(projectId)}/source/acquire`, rightsConfirmed ? { rights_confirmed: true } : {})
  return toProject(res!.data)
}

/**
 * Consentement à l'export officiel Google de la chaîne (Data Portability, ADR-054) : le serveur crée le
 * state (usage unique) et renvoie l'URL de consentement Google ; le navigateur y est redirigé. Le retour
 * arrive sur le callback Laravel, qui relance la récupération puis revient sur la page du projet.
 */
export async function startPortabilityConsent(projectId: string): Promise<void> {
  requireApi()
  const res = await http.post<{ authorization_url: string }>(`/clipper/projects/${encodeURIComponent(projectId)}/source/consent`)
  const url = res!.data.authorization_url
  // Garde-fou : uniquement la page de consentement officielle de Google.
  if (!url.startsWith("https://accounts.google.com/o/oauth2/v2/auth?")) throw new Error("Adresse d'autorisation inattendue.")
  window.location.assign(url)
  return new Promise<void>(() => {})
}

export async function deleteClipperProject(id: string): Promise<void> {
  requireApi()
  await http.delete(`/clipper/projects/${encodeURIComponent(id)}`)
}

export interface ClipInput {
  startMs: number
  endMs: number
  aspectRatio?: ClipAspectRatio
  title?: string | null
  /** Sous-titres incrustés (transcription requise). */
  subtitles?: boolean
  subtitleStyle?: ClipSubtitleStyle
}

const clipBody = (input: Partial<ClipInput>) => ({
  ...(input.startMs !== undefined ? { start_ms: input.startMs } : {}),
  ...(input.endMs !== undefined ? { end_ms: input.endMs } : {}),
  ...(input.aspectRatio ? { aspect_ratio: input.aspectRatio } : {}),
  ...(input.title ? { title: input.title } : {}),
  ...(input.subtitles ? { subtitles: true, subtitle_style: input.subtitleStyle ?? { position: "bottom", size: "medium" } } : {}),
})

/** Clip d'un passage choisi par l'utilisateur (le serveur refuse toute durée > 60 s). */
export async function createClip(projectId: string, input: ClipInput): Promise<ClipperClip> {
  requireApi()
  const res = await http.post<ApiClip>(`/clipper/projects/${encodeURIComponent(projectId)}/clips`, clipBody(input))
  return toClip(res!.data)
}

/** Clip d'un passage proposé par l'analyse (bornes facultatives, toujours ≤ 60 s). */
export async function renderSuggestion(clipId: string, input: Partial<ClipInput> = {}): Promise<ClipperClip> {
  requireApi()
  const res = await http.post<ApiClip>(`/clipper/clips/${encodeURIComponent(clipId)}/render`, clipBody(input))
  return toClip(res!.data)
}

// --- Génération d'images -------------------------------------------------------------------------

export async function listImageGenerations(): Promise<{ generations: AiImageGeneration[]; configured: boolean }> {
  requireApi()
  const res = await http.get<ApiImage[]>("/ai/images")
  return { generations: res!.data.map(toImage), configured: Boolean(res!.meta.configured) }
}

export async function getImageGeneration(id: string): Promise<AiImageGeneration> {
  requireApi()
  const res = await http.get<ApiImage>(`/ai/images/${encodeURIComponent(id)}`)
  return toImage(res!.data)
}

export async function generateImage(input: { prompt: string; aspectRatio: ImageAspectRatio }): Promise<AiImageGeneration> {
  requireApi()
  const res = await http.post<ApiImage>("/ai/images", { prompt: input.prompt, aspect_ratio: input.aspectRatio })
  return toImage(res!.data)
}
