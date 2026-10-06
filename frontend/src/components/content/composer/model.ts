// Modèle du Composer : valeurs du formulaire (React Hook Form), validation par plateforme
// et construction de la requête (future API POST /api/v1/posts).
import {
  PLATFORMS,
  TELEGRAM_ALBUM_MAX,
  TELEGRAM_CAPTION_LIMIT,
  TELEGRAM_MAX_FILE_BYTES,
  TELEGRAM_MAX_PHOTO_BYTES,
} from "@/lib/platforms"
import { browserTimezone } from "@/lib/time"
import { xWeightedLength } from "@/lib/xText"
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
  /** Compte ciblé quand le réseau en compte plusieurs (ex. chaînes et groupes Telegram). */
  accountId?: string
}

/**
 * Compte social ciblé pour un réseau (identifié par son id interne, jamais par son nom).
 * - Compte choisi (accountId) : CE compte uniquement ; s'il n'existe plus, aucun autre n'est
 *   substitué (undefined) — la publication est bloquée plutôt que partir vers un autre compte.
 * - Aucun choix : premier compte connecté, sinon premier compte du réseau (pour afficher son état).
 */
export function platformAccount(accounts: SocialAccount[], platform: PlatformKey, accountId?: string): SocialAccount | undefined {
  const own = accounts.filter((a) => a.platform === platform)
  if (accountId) return own.find((a) => a.id === accountId)
  return own.find((a) => a.status === "connected") ?? own[0]
}

/** Comptes publiables d'un réseau (connectés), dans l'ordre de connexion. */
export function connectedAccounts(accounts: SocialAccount[], platform: PlatformKey): SocialAccount[] {
  return accounts.filter((a) => a.platform === platform && a.status === "connected")
}

/** Nom affiché d'un compte dans le Composer (titre, sinon identifiant public). */
export function accountLabel(account: SocialAccount): string {
  const name = account.displayName ?? account.username ?? "Compte sans nom"
  if (account.platform === "telegram") return `${account.accountType === "group" ? "Groupe" : "Chaîne"} · ${name}`
  return account.username && account.displayName && account.username !== account.displayName ? `${name} (${account.username})` : name
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
    const account = platformAccount(accounts, p, v.accountId)
    if (!account) {
      issues.push({
        platform: p,
        level: "error",
        message: v.accountId
          ? `${cfg.name} : le compte choisi n'existe plus. Choisissez un autre compte.`
          : `${cfg.name} : aucun compte connecté. Connectez un compte dans Réseaux sociaux.`,
      })
    } else if (account.status !== "connected") {
      issues.push({ platform: p, level: "error", message: `${cfg.name} n'est pas prêt : ${(account.statusMessage ?? "reconnectez le compte").replace(/.$/, "")}.` })
    }
    if (p === "x" && media.length > 0) {
      // V1 : texte seul (le serveur refuse aussi les médias pour X, sans rien envoyer).
      issues.push({ platform: p, level: "error", message: "X : les images et vidéos ne sont pas encore prises en charge. Retirez les médias ou décochez X." })
    }
    if (p === "linkedin" && media.length > 0) {
      // V1 : texte seul (le serveur refuse aussi les médias pour LinkedIn, sans rien envoyer).
      issues.push({ platform: p, level: "error", message: "LinkedIn : les images et vidéos ne sont pas encore prises en charge. Retirez les médias ou décochez LinkedIn." })
    }
    if (p === "telegram") {
      issues.push(...telegramIssues(text, media))
    } else if (p === "x") {
      // Décompte de X (emoji et CJK = 2, URL = 23), identique à celui du serveur.
      const length = xWeightedLength(text)
      if (length > cfg.maxChars) {
        issues.push({ platform: p, level: "error", message: `X : le texte dépasse ${cfg.maxChars} caractères selon le décompte de X (${length}).` })
      }
    } else if (text.length > cfg.maxChars) {
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

/** Limite du texte Telegram : 4096 caractères seul, 1024 en légende d'un média. */
export function telegramTextLimit(mediaCount: number): number {
  return mediaCount > 0 ? TELEGRAM_CAPTION_LIMIT : PLATFORMS.telegram.maxChars
}

/** Limites de la Bot API Telegram (mêmes refus que le serveur, avant tout envoi). */
function telegramIssues(text: string, media: MediaAsset[]): Issue[] {
  const issues: Issue[] = []
  const add = (message: string) => issues.push({ platform: "telegram", level: "error", message: `Telegram : ${message}` })
  const limit = telegramTextLimit(media.length)
  if (text.length > limit) {
    add(media.length > 0
      ? `avec un média, le texte (légende) est limité à 1 024 caractères (${text.length.toLocaleString("fr-FR")}).`
      : `le texte dépasse 4 096 caractères (${text.length.toLocaleString("fr-FR")}).`)
  }
  if (media.length > TELEGRAM_ALBUM_MAX) add("10 médias au maximum par publication (album).")
  if (media.some((m) => m.type === "audio" || m.type === "document")) add("seules les images et les vidéos MP4 sont acceptées.")
  if (media.some((m) => m.type === "video" && m.mimeType !== "video/mp4")) add("les vidéos doivent être au format MP4.")
  if (media.some((m) => m.sizeBytes > TELEGRAM_MAX_FILE_BYTES)) add("chaque fichier est limité à 50 Mo.")
  if (media.some((m) => m.type === "image" && !m.isGif && m.sizeBytes > TELEGRAM_MAX_PHOTO_BYTES)) add("chaque image est limitée à 10 Mo.")
  if (media.length > 1 && media.some((m) => m.isGif)) add("un album ne peut pas contenir de GIF : publiez le GIF seul.")
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
        socialAccountId: platformAccount(accounts, p, v.accountId)?.id ?? "",
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
    variants[v.platform] = { customize: v.body !== null, body: v.body ?? "", format: v.format, options: v.options, accountId: v.socialAccountId }
  }
  return {
    title: post.title ?? "",
    body: post.body,
    mediaIds: post.mediaIds,
    platforms: [...new Set(post.variants.map((v) => v.platform))],
    variants,
  }
}
