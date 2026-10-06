// Types du Video Studio (ADR-044), alignés sur backend/app/Http/Resources/Video*Resource.php.
// Temps toujours en millisecondes entières. Préfixe « Studio » : distinct des types de la
// simulation de découpage (types/video.ts).
import type { MediaAsset } from "./media"

export type StudioAspectRatio = "9:16" | "1:1" | "16:9"
export type StudioProjectStatus = "draft" | "rendering" | "ready" | "failed"
export type StudioSceneRole = "hook" | "intro" | "body" | "cta"
export type StudioTrackType = "video" | "image" | "text" | "audio" | "overlay"
export type StudioItemType = "video" | "image" | "text" | "audio"
export type StudioRenderStatus = "queued" | "rendering" | "succeeded" | "failed" | "cancelled"

export interface StudioItemProperties {
  text?: string
  font_size?: number
  color?: string
  position?: "top" | "center" | "bottom"
  align?: "left" | "center" | "right"
  fit?: "cover" | "contain"
  volume?: number
}

export interface StudioTransitions {
  in?: "none" | "fade"
  out?: "none" | "fade"
  duration_ms?: number
}

export interface StudioItem {
  id: string
  trackId: string
  sceneId: string | null
  assetId: string | null
  type: StudioItemType
  startMs: number
  durationMs: number
  endMs: number
  sourceStartMs: number | null
  sourceDurationMs: number | null
  properties: StudioItemProperties
  transitions: StudioTransitions | null
}

export interface StudioTrack {
  id: string
  type: StudioTrackType
  name: string
  position: number
  locked: boolean
  muted: boolean
  hidden: boolean
  items: StudioItem[]
}

export interface StudioScene {
  id: string
  position: number
  name: string | null
  role: StudioSceneRole
  durationMs: number
  mediaId: string | null
  onScreenText: string | null
  voiceoverText: string | null
}

export interface StudioAsset {
  id: string
  mediaId: string
  available: boolean
  media: MediaAsset | null
}

export interface StudioRender {
  id: string
  status: StudioRenderStatus
  progress: number
  aspectRatio: StudioAspectRatio | null
  width: number | null
  height: number | null
  errorCode: string | null
  errorMessage: string | null
  createdAt: string
  finishedAt: string | null
  /** Vidéo produite (média de la médiathèque, URLs signées) ; null tant que le rendu n'a pas réussi. */
  output: MediaAsset | null
}

export interface StudioProject {
  id: string
  title: string
  description: string | null
  status: StudioProjectStatus
  aspectRatio: StudioAspectRatio
  width: number
  height: number
  fps: number
  durationMs: number
  lockVersion: number
  updatedAt: string
  scenes: StudioScene[]
  tracks: StudioTrack[]
  assets: StudioAsset[]
  renders: StudioRender[]
}

/** Projet chargé pour l'éditeur, avec la disponibilité du moteur de rendu côté serveur. */
export interface StudioEditorData {
  project: StudioProject
  rendererAvailable: boolean
}

export interface StudioItemInput {
  type?: StudioItemType
  start_ms?: number
  duration_ms?: number
  video_project_asset_id?: string | null
  video_scene_id?: string | null
  video_track_id?: string
  source_start_ms?: number | null
  properties?: StudioItemProperties | null
  transitions?: StudioTransitions | null
}
