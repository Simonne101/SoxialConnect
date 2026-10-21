// Libellés et conversions du Video Studio. L'interface affiche des secondes ; l'API reçoit toujours
// des millisecondes entières (aucun mélange d'unités dans les échanges).
import type { StudioAspectRatio, StudioItemType, StudioRenderStatus, StudioSceneRole, StudioTrackType } from "@/types"

export const msToSeconds = (ms: number) => Math.round(ms / 100) / 10
export const secondsToMs = (s: number) => Math.round(s * 1000)
export const formatSeconds = (ms: number) => `${msToSeconds(ms).toLocaleString("fr-FR")} s`

export const ASPECT_LABELS: Record<StudioAspectRatio, string> = {
  "9:16": "Vertical 9:16 (Reels, Shorts, TikTok)",
  "1:1": "Carré 1:1",
  "16:9": "Paysage 16:9 (YouTube)",
}

export const TRACK_LABELS: Record<StudioTrackType, string> = {
  video: "Vidéo",
  image: "Images",
  text: "Texte",
  audio: "Audio",
  overlay: "Superposition",
}

/** Types d'éléments acceptés par piste (même règle que le serveur, VideoTrackType::accepts). */
export const TRACK_ACCEPTS: Record<StudioTrackType, StudioItemType[]> = {
  video: ["video", "image"],
  image: ["image"],
  text: ["text"],
  audio: ["audio"],
  overlay: ["image", "video", "text"],
}

export const ITEM_LABELS: Record<StudioItemType, string> = { video: "Vidéo", image: "Image", text: "Texte", audio: "Son" }

export const ROLE_LABELS: Record<StudioSceneRole, string> = { hook: "Accroche", intro: "Introduction", body: "Développement", cta: "Appel à l'action" }

export const RENDER_LABELS: Record<StudioRenderStatus, { label: string; tone: "info" | "warning" | "success" | "danger" | "neutral" }> = {
  queued: { label: "En file", tone: "info" },
  rendering: { label: "En cours", tone: "warning" },
  succeeded: { label: "Terminé", tone: "success" },
  failed: { label: "Échec", tone: "danger" },
  cancelled: { label: "Annulé", tone: "neutral" },
}
