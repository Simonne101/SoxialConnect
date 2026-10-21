// Service Video Studio (ADR-044) : /api/v1/video (Laravel). Aucune donnée simulée : sans backend,
// chaque appel est refusé explicitement (FeatureUnavailableError).
import type {
  StudioAspectRatio,
  StudioAsset,
  StudioEditorData,
  StudioItem,
  StudioItemInput,
  StudioProject,
  StudioRender,
  StudioScene,
  StudioSceneRole,
  StudioTrack,
  StudioTrackType,
} from "@/types"
import { FeatureUnavailableError } from "@/lib/features"
import { http, isApiEnabled } from "@/lib/http"
import { toMediaAsset, type ApiMedia } from "./media"

export const STUDIO_UNAVAILABLE = "Le Video Studio nécessite le serveur SoxialConnect."

// --- Format de l'API (backend/app/Http/Resources/Video*Resource.php) -----------------------------

interface ApiItem {
  id: string
  video_track_id: string
  video_scene_id: string | null
  video_project_asset_id: string | null
  type: StudioItem["type"]
  start_ms: number
  duration_ms: number
  end_ms: number
  source_start_ms: number | null
  source_duration_ms: number | null
  properties: StudioItem["properties"] | []
  transitions: StudioItem["transitions"]
}
interface ApiTrack {
  id: string
  type: StudioTrackType
  name: string
  position: number
  locked: boolean
  muted: boolean
  hidden: boolean
  items?: ApiItem[]
}
interface ApiScene {
  id: string
  position: number
  name: string | null
  role: StudioSceneRole
  duration_ms: number
  media_id: string | null
  on_screen_text: string | null
  voiceover_text: string | null
}
interface ApiAsset {
  id: string
  media_id: string
  available: boolean
  media?: ApiMedia | null
}
interface ApiRender {
  id: string
  status: StudioRender["status"]
  progress: number
  aspect_ratio: StudioAspectRatio | null
  width: number | null
  height: number | null
  error_code: string | null
  error_message: string | null
  created_at: string
  finished_at: string | null
  output?: ApiMedia | null
}
interface ApiProject {
  id: string
  title: string
  description: string | null
  status: StudioProject["status"]
  aspect_ratio: StudioAspectRatio
  width: number
  height: number
  fps: number
  duration_ms: number
  lock_version: number
  updated_at: string
  scenes?: ApiScene[]
  tracks?: ApiTrack[]
  assets?: ApiAsset[]
  renders?: ApiRender[]
}

const toItem = (i: ApiItem): StudioItem => ({
  id: i.id,
  trackId: i.video_track_id,
  sceneId: i.video_scene_id,
  assetId: i.video_project_asset_id,
  type: i.type,
  startMs: i.start_ms,
  durationMs: i.duration_ms,
  endMs: i.end_ms,
  sourceStartMs: i.source_start_ms,
  sourceDurationMs: i.source_duration_ms,
  properties: Array.isArray(i.properties) ? {} : i.properties,
  transitions: i.transitions,
})
const toTrack = (t: ApiTrack): StudioTrack => ({ ...t, items: (t.items ?? []).map(toItem) })
const toScene = (s: ApiScene): StudioScene => ({
  id: s.id,
  position: s.position,
  name: s.name,
  role: s.role,
  durationMs: s.duration_ms,
  mediaId: s.media_id,
  onScreenText: s.on_screen_text,
  voiceoverText: s.voiceover_text,
})
const toAsset = (a: ApiAsset): StudioAsset => ({ id: a.id, mediaId: a.media_id, available: a.available, media: a.media ? toMediaAsset(a.media) : null })
const toRender = (r: ApiRender): StudioRender => ({
  id: r.id,
  status: r.status,
  progress: r.progress,
  aspectRatio: r.aspect_ratio,
  width: r.width,
  height: r.height,
  errorCode: r.error_code,
  errorMessage: r.error_message,
  createdAt: r.created_at,
  finishedAt: r.finished_at,
  output: r.output ? toMediaAsset(r.output) : null,
})
const toProject = (p: ApiProject): StudioProject => ({
  id: p.id,
  title: p.title,
  description: p.description,
  status: p.status,
  aspectRatio: p.aspect_ratio,
  width: p.width,
  height: p.height,
  fps: p.fps,
  durationMs: p.duration_ms,
  lockVersion: p.lock_version,
  updatedAt: p.updated_at,
  scenes: (p.scenes ?? []).map(toScene),
  tracks: (p.tracks ?? []).map(toTrack),
  assets: (p.assets ?? []).map(toAsset),
  renders: (p.renders ?? []).map(toRender),
})

function requireApi(): void {
  if (!isApiEnabled) throw new FeatureUnavailableError(STUDIO_UNAVAILABLE)
}

/** Version attendue du projet : le serveur refuse (409) une écriture basée sur une version périmée. */
const withVersion = <T extends object>(body: T, lockVersion?: number) => (lockVersion === undefined ? body : { ...body, lock_version: lockVersion })

// --- Projets ------------------------------------------------------------------------------------

export async function listProjects(): Promise<StudioProject[]> {
  requireApi()
  const res = await http.get<ApiProject[]>("/video/projects?per_page=50")
  return res!.data.map(toProject)
}

export async function getProject(id: string): Promise<StudioEditorData> {
  requireApi()
  const res = await http.get<ApiProject>(`/video/projects/${encodeURIComponent(id)}`)
  return { project: toProject(res!.data), rendererAvailable: Boolean(res!.meta.renderer_available) }
}

export async function createProject(input: { title: string; aspectRatio: StudioAspectRatio; description?: string }): Promise<StudioProject> {
  requireApi()
  const res = await http.post<ApiProject>("/video/projects", { title: input.title, aspect_ratio: input.aspectRatio, description: input.description || null })
  return toProject(res!.data)
}

export async function updateProject(id: string, patch: { title?: string; aspect_ratio?: StudioAspectRatio; description?: string | null }, lockVersion?: number): Promise<void> {
  requireApi()
  await http.patch(`/video/projects/${encodeURIComponent(id)}`, withVersion(patch, lockVersion))
}

export async function deleteProject(id: string): Promise<void> {
  requireApi()
  await http.delete(`/video/projects/${encodeURIComponent(id)}`)
}

// --- Scènes, pistes, éléments, médias -------------------------------------------------------------

export interface SceneInput {
  name?: string | null
  role?: StudioSceneRole
  duration_ms?: number
  position?: number
  on_screen_text?: string | null
}

export async function createScene(projectId: string, input: SceneInput, lockVersion?: number): Promise<void> {
  requireApi()
  await http.post(`/video/projects/${encodeURIComponent(projectId)}/scenes`, withVersion(input, lockVersion))
}

export async function updateScene(sceneId: string, input: SceneInput, lockVersion?: number): Promise<void> {
  requireApi()
  await http.patch(`/video/scenes/${encodeURIComponent(sceneId)}`, withVersion(input, lockVersion))
}

export async function deleteScene(sceneId: string, lockVersion?: number): Promise<void> {
  requireApi()
  await http.delete(`/video/scenes/${encodeURIComponent(sceneId)}${lockVersion === undefined ? "" : `?lock_version=${lockVersion}`}`)
}

export interface TrackInput {
  type?: StudioTrackType
  name?: string | null
  position?: number
  locked?: boolean
  muted?: boolean
  hidden?: boolean
}

export async function createTrack(projectId: string, input: TrackInput, lockVersion?: number): Promise<void> {
  requireApi()
  await http.post(`/video/projects/${encodeURIComponent(projectId)}/tracks`, withVersion(input, lockVersion))
}

export async function updateTrack(trackId: string, input: TrackInput, lockVersion?: number): Promise<void> {
  requireApi()
  await http.patch(`/video/tracks/${encodeURIComponent(trackId)}`, withVersion(input, lockVersion))
}

export async function deleteTrack(trackId: string): Promise<void> {
  requireApi()
  await http.delete(`/video/tracks/${encodeURIComponent(trackId)}`)
}

export async function addItem(trackId: string, input: StudioItemInput, lockVersion?: number): Promise<StudioItem> {
  requireApi()
  const res = await http.post<ApiItem>(`/video/tracks/${encodeURIComponent(trackId)}/items`, withVersion(input, lockVersion))
  return toItem(res!.data)
}

export async function updateItem(itemId: string, input: StudioItemInput, lockVersion?: number): Promise<StudioItem> {
  requireApi()
  const res = await http.patch<ApiItem>(`/video/timeline-items/${encodeURIComponent(itemId)}`, withVersion(input, lockVersion))
  return toItem(res!.data)
}

export async function deleteItem(itemId: string): Promise<void> {
  requireApi()
  await http.delete(`/video/timeline-items/${encodeURIComponent(itemId)}`)
}

export async function attachAsset(projectId: string, mediaId: string): Promise<void> {
  requireApi()
  await http.post(`/video/projects/${encodeURIComponent(projectId)}/assets`, { media_id: mediaId })
}

export async function detachAsset(assetId: string): Promise<void> {
  requireApi()
  await http.delete(`/video/assets/${encodeURIComponent(assetId)}`)
}

// --- Rendus -------------------------------------------------------------------------------------

/** Planifie un rendu ; le message du serveur précise si le moteur (FFmpeg) est disponible. */
export async function requestRender(projectId: string, lockVersion?: number): Promise<{ render: StudioRender; message: string | null }> {
  requireApi()
  const res = await http.post<ApiRender>(`/video/projects/${encodeURIComponent(projectId)}/renders`, withVersion({}, lockVersion))
  return { render: toRender(res!.data), message: res!.message }
}

export async function cancelRender(renderId: string): Promise<void> {
  requireApi()
  await http.post(`/video/renders/${encodeURIComponent(renderId)}/cancel`)
}
