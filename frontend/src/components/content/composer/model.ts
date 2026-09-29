// Modèle du Composer : valeurs du formulaire (React Hook Form), validation par plateforme
// et construction de la requête (future API POST /api/v1/posts).
import { PLATFORMS } from "@/lib/platforms"
import { browserTimezone } from "@/lib/time"
import type {
  MediaAsset,
  PlatformKey,
  Post,
  PostDraftInput,
  PostFormat,
  SocialAccount,
  VariantOptions,
} from "@/types"

export interface VariantDraft {
  customize: boolean
  body: string
  format: PostFormat
  options: VariantOptions
}

export interface ComposerValues {
  title: string
  body: string
  mediaIds: string[]
  platforms: PlatformKey[]
  variants: Partial<Record<PlatformKey, VariantDraft>>
}

export const EMPTY_VALUES: ComposerValues = { title: "", body: "", mediaIds: [], platforms: [], variants: {} }

export function defaultVariant(platform: PlatformKey): VariantDraft {
  const cfg = PLATFORMS[platform]
  return {
    customize: false,
    body: "",
    format: cfg.formats[0].value,
    options:
      platform === "tiktok"
        ? { allowComments: true, allowDuet: false, allowStitch: false, commercialContent: false }
        : platform === "youtube"
          ? { youtubeVisibility: "public", madeForKids: false }
          : {},
  }
}

export function variantText(values: ComposerValues, platform: PlatformKey): string {
  const v = values.variants[platform]
  const base = v?.customize ? v.body : values.body
  const tags = v?.options.hashtags ?? []
  return tags.length ? `${base.trimEnd()}\n\n${tags.map((t) => `#${t}`).join(" ")}` : base
}

export interface Issue {
  platform: PlatformKey | null
  message: string
  level: "error" | "warning"
}

/**
 * Contrôles côté interface (confort UX). Le backend refera toutes les validations
 * via les adaptateurs officiels — ces règles sont indicatives (docs/api-integration.md).
 */
export function validateComposer(
  values: ComposerValues,
  accounts: SocialAccount[],
  mediaMap: Record<string, MediaAsset>,
  mode: "draft" | "schedule" | "publish",
): Issue[] {
  const issues: Issue[] = []
  if (mode === "draft") {
    if (!values.body.trim() && !values.title.trim() && values.mediaIds.length === 0) {
      issues.push({ platform: null, level: "error", message: "Écrivez au moins quelques mots avant d'enregistrer." })
    }
    return issues
  }
  if (values.platforms.length === 0) {
    issues.push({ platform: null, level: "error", message: "Choisissez au moins un réseau social." })
  }
  if (!values.body.trim() && values.mediaIds.length === 0) {
    issues.push({ platform: null, level: "error", message: "Ajoutez un texte ou un média." })
  }
  const media = values.mediaIds.map((id) => mediaMap[id]).filter(Boolean)
  const hasVideo = media.some((m) => m.type === "video")
  const hasVisual = media.some((m) => m.type === "image" || m.type === "video")

  for (const p of values.platforms) {
    const cfg = PLATFORMS[p]
    const v = values.variants[p] ?? defaultVariant(p)
    const text = variantText(values, p)
    const account = accounts.find((a) => a.platform === p)
    if (account && account.status !== "connected") {
      issues.push({ platform: p, level: "error", message: `${cfg.name} n'est pas prêt : ${(account.statusMessage ?? "reconnectez le compte").replace(/.$/, "")}.` })
    }
    if (text.length > cfg.maxChars) {
      issues.push({ platform: p, level: "error", message: `${cfg.name} : le texte dépasse ${cfg.maxChars.toLocaleString("fr-FR")} caractères (${text.length.toLocaleString("fr-FR")}).` })
    }
    const videoOnly = p === "tiktok" || p === "youtube"
    if (videoOnly && !hasVideo) {
      issues.push({ platform: p, level: "error", message: `${cfg.name} : ajoutez une vidéo (obligatoire).` })
    } else if (cfg.requiresMedia && !hasVisual) {
      issues.push({ platform: p, level: "error", message: `${cfg.name} : ajoutez une image ou une vidéo (obligatoire).` })
    }
    if (p === "tiktok" && !v.options.privacy) {
      issues.push({ platform: p, level: "error", message: "TikTok : choisissez qui peut voir la vidéo." })
    }
    if (p === "youtube" && !v.options.title?.trim()) {
      issues.push({ platform: p, level: "error", message: "YouTube : le titre de la vidéo est obligatoire." })
    }
    if (p === "pinterest") {
      if (!v.options.board) issues.push({ platform: p, level: "error", message: "Pinterest : choisissez un tableau." })
      if (v.options.destinationUrl && !/^https:\/\/\S+\.\S+/.test(v.options.destinationUrl)) {
        issues.push({ platform: p, level: "error", message: "Pinterest : le lien doit commencer par https://." })
      }
    }
    if ((v.format === "reel" || v.format === "short") && hasVisual && !hasVideo) {
      issues.push({ platform: p, level: "error", message: `${cfg.name} : le format ${v.format === "reel" ? "Reel" : "Short"} nécessite une vidéo.` })
    }
    if (p === "instagram" && v.format === "carousel" && media.length < 2) {
      issues.push({ platform: p, level: "warning", message: "Instagram : un carrousel contient au moins 2 médias." })
    }
  }
  return issues
}

export function toDraftInput(values: ComposerValues, accounts: SocialAccount[], scheduledAt: string | null): PostDraftInput {
  return {
    title: values.title.trim() || null,
    body: values.body,
    mediaIds: values.mediaIds,
    scheduledAt,
    timezone: browserTimezone(),
    targets: values.platforms.map((p) => {
      const v = values.variants[p] ?? defaultVariant(p)
      return {
        platform: p,
        socialAccountId: accounts.find((a) => a.platform === p)?.id ?? "",
        format: v.format,
        body: v.customize ? v.body : null,
        options: v.options,
      }
    }),
  }
}

export function fromPost(post: Post): ComposerValues {
  const variants: ComposerValues["variants"] = {}
  for (const v of post.variants) {
    variants[v.platform] = { customize: v.body !== null, body: v.body ?? "", format: v.format, options: v.options }
  }
  return {
    title: post.title ?? "",
    body: post.body,
    mediaIds: post.mediaIds,
    platforms: [...new Set(post.variants.map((v) => v.platform))],
    variants,
  }
}
