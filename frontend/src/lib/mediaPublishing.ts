import type { MediaAsset } from "@/types"

/** Médias par publication, comme le serveur (SavePostRequest : media_ids max:20). */
export const MAX_MEDIA_PER_POST = 20

export type PublishSelection = { ok: true; mediaIds: string[] } | { ok: false; reason: string }

/**
 * Sélection de la médiathèque publiable telle quelle : images et vidéos prêtes (hors quarantaine),
 * dans l'ordre de sélection. Le serveur revérifie propriété, statut et analyse à l'enregistrement.
 */
export function publishableSelection(selectedIds: string[], items: MediaAsset[]): PublishSelection {
  if (selectedIds.length === 0) return { ok: false, reason: "Sélectionnez une vidéo ou une image." }
  if (selectedIds.length > MAX_MEDIA_PER_POST) return { ok: false, reason: `${MAX_MEDIA_PER_POST} médias au maximum par publication.` }
  const byId = new Map(items.map((m) => [m.id, m]))
  const selected = selectedIds.map((id) => byId.get(id))
  if (selected.some((m) => m === undefined)) return { ok: false, reason: "Un média sélectionné n'est plus affiché : actualisez la sélection." }
  const media = selected as MediaAsset[]
  if (media.some((m) => m.type !== "video" && m.type !== "image")) return { ok: false, reason: "Seules les vidéos et les images peuvent être publiées." }
  if (media.some((m) => !m.usable)) return { ok: false, reason: "Un média sélectionné n'est pas encore prêt (traitement ou analyse en cours)." }

  return { ok: true, mediaIds: media.map((m) => m.id) }
}

/** État de navigation vers le Composer (/content/create) : même forme que depuis le Clipper. */
export function composerStateFor(mediaIds: string[]): { mediaIds: string[] } {
  return { mediaIds: [...mediaIds] }
}
