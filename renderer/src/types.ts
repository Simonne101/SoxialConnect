// Contrat d'entrée de la composition (ADR-051) : le RenderPlan du backend
// (App\Domain\Video\Clipper\Planning\RenderPlan::toArray, schéma soxial.clip_render_plan.v1)
// + la vidéo déjà découpée et recadrée par FFmpeg (video_src). Temps en millisecondes,
// légendes relatives au début du clip.

export interface Caption {
  start_ms: number
  end_ms: number
  text: string
}

export interface ClipCompositionProps {
  schema: "soxial.clip_render_plan.v1"
  /** URL ou chemin de la vidéo du passage (sans sous-titres) ; null : fond seul (test de fumée). */
  video_src: string | null
  width: number
  height: number
  fps: number
  duration_in_frames: number
  template: "clean-short"
  subtitles: boolean
  captions: Caption[]
  hook: string | null
  hook_duration_ms: number
  /** Identité visuelle facultative (couleur d'accent). */
  branding?: { accent?: string } | null
  [key: string]: unknown
}

export const DEFAULT_PROPS: ClipCompositionProps = {
  schema: "soxial.clip_render_plan.v1",
  video_src: null,
  width: 1080,
  height: 1920,
  fps: 30,
  duration_in_frames: 60,
  template: "clean-short",
  subtitles: true,
  captions: [{ start_ms: 0, end_ms: 2000, text: "SoxialConnect" }],
  hook: "Aperçu de la composition",
  hook_duration_ms: 1500,
  branding: null,
}
