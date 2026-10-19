// AI Creative Studio : timecodes, libellés et règles d'affichage. Les échanges avec l'API sont
// toujours en millisecondes entières ; l'interface affiche des timecodes (hh:mm:ss).
import type { ClipAspectRatio, ClipperClipStatus, ClipperProjectStatus, ClipRenderStage, ClipSubtitlePosition, ClipSubtitleSize, ClipSubtitleStyle, ImageAspectRatio } from "@/types"

/** Durée maximale d'un clip : rappel côté interface (le serveur l'impose, le frontend ne protège rien). */
export const MAX_CLIP_MS = 60_000

const pad = (n: number) => String(n).padStart(2, "0")

/** 75 000 → « 01:15 » ; 3 723 000 → « 1:02:03 ». */
export function formatTimecode(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

/** « 1:15 », « 01:02:03 », « 75 », « 1:15.5 » → millisecondes ; null si la saisie est invalide. */
export function parseTimecode(input: string): number | null {
  const value = input.trim().replace(",", ".")
  if (!/^\d+(:\d{1,2}){0,2}(\.\d{1,3})?$/.test(value)) return null
  const parts = value.split(":").map(Number)
  if (parts.slice(1).some((p) => p >= 60)) return null
  const seconds = parts.reduce((acc, p) => acc * 60 + p, 0)
  return Math.round(seconds * 1000)
}

/** 48 000 → « 48 s » ; 60 000 → « 60 s ». */
export const formatDuration = (ms: number) => `${Math.round(ms / 100) / 10} s`.replace(".", ",")

export const PROJECT_STATUS: Record<ClipperProjectStatus, { label: string; variant: "neutral" | "warning" | "success" | "danger" }> = {
  pending: { label: "En file d'attente", variant: "neutral" },
  analyzing: { label: "Lecture de la vidéo", variant: "warning" },
  transcribing: { label: "Transcription", variant: "warning" },
  detecting: { label: "Choix des passages", variant: "warning" },
  ready: { label: "Passages prêts", variant: "success" },
  failed: { label: "Analyse impossible", variant: "danger" },
  cancelled: { label: "Annulé", variant: "neutral" },
}

export const CLIP_STATUS: Record<ClipperClipStatus, { label: string; variant: "neutral" | "warning" | "success" | "danger" | "secondary" }> = {
  suggested: { label: "Proposé", variant: "secondary" },
  edited: { label: "Modifié", variant: "secondary" },
  rendering: { label: "Génération en cours", variant: "warning" },
  rendered: { label: "Clip prêt", variant: "success" },
  failed: { label: "Échec", variant: "danger" },
  discarded: { label: "Écarté", variant: "neutral" },
}

export const IMAGE_FORMATS: { value: ImageAspectRatio; label: string; hint: string }[] = [
  { value: "1:1", label: "Carré 1:1", hint: "Feed Instagram, Facebook, LinkedIn" },
  { value: "4:5", label: "Portrait 4:5", hint: "Feed Instagram (portrait)" },
  { value: "9:16", label: "Vertical 9:16", hint: "Stories, Reels, TikTok" },
  { value: "16:9", label: "Paysage 16:9", hint: "YouTube, X, LinkedIn" },
]

/** Validation côté interface du lien (même forme que le serveur, qui revalide tout). */
export const isYouTubeUrl = (value: string) =>
  /^https:\/\/((www\.|m\.|music\.)?youtube\.com\/(watch\?(.*&)?v=|shorts\/|live\/|embed\/)|(www\.)?youtube-nocookie\.com\/embed\/|youtu\.be\/)[A-Za-z0-9_-]{11}([?&#/].*)?$/.test(value.trim())

/** Étapes visibles d'un rendu de clip : « Clip prêt » seulement après validation et publication. */
export const RENDER_STEPS: { stage: ClipRenderStage; label: string }[] = [
  { stage: "preparing", label: "Préparation" },
  { stage: "rendering", label: "Rendu" },
  { stage: "validating", label: "Validation" },
  { stage: "publishing", label: "Publication" },
]

export const SUBTITLE_POSITIONS: { value: ClipSubtitlePosition; label: string }[] = [
  { value: "bottom", label: "En bas" },
  { value: "center", label: "Au centre" },
  { value: "top", label: "En haut" },
]

export const SUBTITLE_SIZES: { value: ClipSubtitleSize; label: string }[] = [
  { value: "small", label: "Petits" },
  { value: "medium", label: "Moyens" },
  { value: "large", label: "Grands" },
]

/** Critères du score (Clip Intelligence Engine, ADR-051), dans l'ordre d'affichage. */
export const SCORE_CRITERIA: { key: "hook" | "value" | "autonomy" | "emotion" | "rhythm" | "quality"; label: string }[] = [
  { key: "hook", label: "Accroche" },
  { key: "value", label: "Valeur" },
  { key: "autonomy", label: "Autonomie" },
  { key: "emotion", label: "Émotion" },
  { key: "rhythm", label: "Rythme" },
  { key: "quality", label: "Qualité" },
]

/** Origine d'un passage proposé, sans jamais présenter l'analyse locale comme une IA. */
export const SUGGESTION_ORIGIN: Record<"ai" | "auto" | "manual", string> = {
  ai: "Proposé par l'IA",
  auto: "Proposé automatiquement (sans IA)",
  manual: "Passage choisi",
}

/** Options de rendu d'un clip : format et sous-titres incrustés. */
export interface RenderOptionsValue {
  aspectRatio: ClipAspectRatio
  subtitles: boolean
  subtitleStyle: ClipSubtitleStyle
}

export const defaultRenderOptions = (aspectRatio: ClipAspectRatio): RenderOptionsValue => ({
  aspectRatio,
  subtitles: false,
  subtitleStyle: { position: "bottom", size: "medium" },
})
