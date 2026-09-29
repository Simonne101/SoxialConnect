// Libellés et couleurs des statuts — correspondance centralisée (docs/ux-ui.md §3.5).
import type {
  ClipStatus,
  MediaStatus,
  PostStatus,
  SocialAccountStatus,
  VariantStatus,
  VideoProjectStatus,
} from "@/types"

export type Tone = "success" | "warning" | "danger" | "info" | "neutral" | "secondary" | "violet"

export interface StatusMeta {
  label: string
  tone: Tone
  /** Classe du point coloré (légende du calendrier). */
  dot: string
}

const DOT: Record<Tone, string> = {
  success: "bg-success-500",
  warning: "bg-warning-500",
  danger: "bg-danger-500",
  info: "bg-info-500",
  neutral: "bg-neutral-500",
  secondary: "bg-brand-500",
  violet: "bg-violet-500",
}

const meta = (label: string, tone: Tone): StatusMeta => ({ label, tone, dot: DOT[tone] })

export const POST_STATUS: Record<PostStatus, StatusMeta> = {
  draft: meta("Brouillon", "neutral"),
  scheduled: meta("Programmée", "info"),
  processing: meta("En cours", "secondary"),
  partially_published: meta("Partiellement publiée", "warning"),
  published: meta("Publiée", "success"),
  failed: meta("En erreur", "danger"),
  cancelled: meta("Annulée", "neutral"),
}

export const VARIANT_STATUS: Record<VariantStatus, StatusMeta> = {
  draft: meta("Brouillon", "neutral"),
  scheduled: meta("Programmée", "info"),
  queued: meta("En file d'attente", "secondary"),
  publishing: meta("Publication…", "secondary"),
  published: meta("Publiée", "success"),
  failed: meta("Échec", "danger"),
  cancelled: meta("Annulée", "neutral"),
}

export const ACCOUNT_STATUS: Record<SocialAccountStatus, StatusMeta & { hint: string }> = {
  connected: { ...meta("Connecté", "success"), hint: "Tout fonctionne." },
  disconnected: { ...meta("Non connecté", "danger"), hint: "Connectez ce réseau pour y publier." },
  expired: { ...meta("Expiré", "warning"), hint: "L'autorisation a expiré : reconnectez le compte." },
  permission_required: {
    ...meta("Permission requise", "warning"),
    hint: "Une autorisation nécessaire a été refusée.",
  },
  error: { ...meta("Erreur", "danger"), hint: "Le réseau renvoie une erreur. Réessayez ou reconnectez." },
  coming_soon: { ...meta("Bientôt disponible", "neutral"), hint: "Cette intégration arrive prochainement." },
}

export const MEDIA_STATUS: Record<MediaStatus, StatusMeta> = {
  uploading: meta("Envoi…", "secondary"),
  processing: meta("Traitement…", "info"),
  ready: meta("Prêt", "success"),
  failed: meta("Échec", "danger"),
  deleted: meta("Supprimé", "neutral"),
}

export const PROJECT_STATUS: Record<VideoProjectStatus, StatusMeta> = {
  pending: meta("En attente", "neutral"),
  importing: meta("Import…", "secondary"),
  analyzing: meta("Analyse…", "secondary"),
  transcribing: meta("Transcription…", "secondary"),
  detecting: meta("Détection des clips…", "violet"),
  ready: meta("Prêt", "success"),
  failed: meta("Échec", "danger"),
  cancelled: meta("Annulé", "neutral"),
}

export const CLIP_STATUS: Record<ClipStatus, StatusMeta> = {
  suggested: meta("Suggéré", "violet"),
  edited: meta("Modifié", "info"),
  rendering: meta("Export…", "secondary"),
  rendered: meta("Exporté", "success"),
  failed: meta("Échec", "danger"),
  discarded: meta("Écarté", "neutral"),
}
