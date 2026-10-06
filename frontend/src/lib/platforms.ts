// Configuration des plateformes côté interface.
// Source fonctionnelle : docs/api-integration.md. Toutes les valeurs marquées TO_VERIFY
// sont indicatives et seront remplacées par les capacités renvoyées par l'API Laravel
// (GET /api/v1/social/platforms) ; ne jamais les considérer comme garanties.
import type {
  Capability,
  CapabilityStatus,
  PlatformAvailability,
  PlatformKey,
  PostFormat,
} from "@/types"

export type VariantField =
  | "caption"
  | "hashtags"
  | "title"
  | "description"
  | "destinationUrl"
  | "board"
  | "privacy"
  | "interactions"
  | "commercialContent"
  | "youtubeVisibility"
  | "madeForKids"
  | "cover"
  | "thumbnail"
  | "firstComment"

export interface PlatformConfig {
  key: PlatformKey
  name: string
  availability: PlatformAvailability
  /** Limite de caractères du texte principal (TO_VERIFY). */
  maxChars: number
  /** Un média est-il obligatoire pour publier ? */
  requiresMedia: boolean
  mediaHint: string
  formats: { value: PostFormat; label: string }[]
  preview: { width: number; height: number; label: string; ratio: string }
  variantFields: VariantField[]
  accountRequirement: string
  permissions: { scope: string; label: string }[]
  capabilities: Record<Capability, CapabilityStatus>
}

const cap = (
  partial: Partial<Record<Capability, CapabilityStatus>>,
): Record<Capability, CapabilityStatus> => ({
  text_post: "unsupported",
  image_post: "unsupported",
  multi_image_post: "unsupported",
  video_post: "unsupported",
  story: "unsupported",
  reel_short: "unsupported",
  native_scheduling: "unsupported",
  account_analytics: "unsupported",
  post_analytics: "unsupported",
  webhooks: "unsupported",
  ...partial,
})

export const PLATFORMS: Record<PlatformKey, PlatformConfig> = {
  facebook: {
    key: "facebook",
    name: "Facebook",
    availability: "available",
    maxChars: 63206,
    requiresMedia: false,
    mediaHint: "Texte, lien, photos ou vidéo",
    formats: [
      { value: "feed", label: "Publication" },
      { value: "reel", label: "Reel" },
    ],
    preview: { width: 1200, height: 630, label: "Lien / Image", ratio: "1.91 / 1" },
    variantFields: ["caption", "hashtags", "firstComment"],
    accountRequirement: "Une Page Facebook (les profils personnels ne sont pas pris en charge par l'API).",
    permissions: [
      { scope: "pages_show_list", label: "Voir la liste de vos Pages" },
      { scope: "pages_manage_posts", label: "Publier sur vos Pages" },
      { scope: "pages_read_engagement", label: "Lire l'engagement" },
      { scope: "read_insights", label: "Lire les statistiques" },
    ],
    capabilities: cap({
      text_post: "supported",
      image_post: "supported",
      multi_image_post: "to_verify",
      video_post: "supported",
      story: "to_verify",
      reel_short: "to_verify",
      native_scheduling: "to_verify",
      account_analytics: "to_verify",
      post_analytics: "to_verify",
      webhooks: "supported",
    }),
  },
  instagram: {
    key: "instagram",
    name: "Instagram",
    availability: "available",
    maxChars: 2200,
    requiresMedia: true,
    mediaHint: "Image, carrousel ou Reel (média obligatoire)",
    formats: [
      { value: "feed", label: "Publication" },
      { value: "carousel", label: "Carrousel" },
      { value: "reel", label: "Reel" },
      { value: "story", label: "Story" },
    ],
    preview: { width: 1080, height: 1080, label: "Image carrée", ratio: "1 / 1" },
    variantFields: ["caption", "hashtags", "cover", "firstComment"],
    accountRequirement: "Un compte Instagram professionnel (Business ou Créateur).",
    permissions: [
      { scope: "instagram_business_basic", label: "Voir votre profil professionnel" },
      { scope: "instagram_business_content_publish", label: "Publier du contenu" },
      { scope: "instagram_business_manage_insights", label: "Lire les statistiques" },
    ],
    capabilities: cap({
      image_post: "supported",
      multi_image_post: "supported",
      video_post: "supported",
      story: "to_verify",
      reel_short: "supported",
      account_analytics: "supported",
      post_analytics: "supported",
      webhooks: "supported",
    }),
  },
  tiktok: {
    key: "tiktok",
    name: "TikTok",
    availability: "available",
    maxChars: 2200,
    requiresMedia: true,
    mediaHint: "Vidéo verticale (média obligatoire)",
    formats: [{ value: "video", label: "Vidéo" }],
    preview: { width: 1080, height: 1920, label: "Vidéo verticale", ratio: "9 / 16" },
    variantFields: ["caption", "hashtags", "privacy", "interactions", "commercialContent", "cover"],
    accountRequirement: "Un compte TikTok. Avant l'audit de l'application, les vidéos sont publiées en privé.",
    permissions: [
      { scope: "user.info.basic", label: "Voir votre profil" },
      { scope: "video.publish", label: "Publier des vidéos" },
      { scope: "video.list", label: "Lire vos vidéos et leurs compteurs" },
    ],
    capabilities: cap({
      image_post: "to_verify",
      video_post: "requires_review",
      account_analytics: "to_verify",
      post_analytics: "to_verify",
      webhooks: "to_verify",
    }),
  },
  youtube: {
    key: "youtube",
    name: "YouTube",
    availability: "available",
    maxChars: 5000,
    requiresMedia: true,
    mediaHint: "Vidéo (média obligatoire)",
    formats: [
      { value: "video", label: "Vidéo" },
      { value: "short", label: "Short" },
    ],
    preview: { width: 1920, height: 1080, label: "Vidéo 16:9", ratio: "16 / 9" },
    variantFields: ["title", "description", "youtubeVisibility", "madeForKids", "thumbnail"],
    accountRequirement: "Une chaîne YouTube. Avant l'audit du projet Google, les vidéos sont privées.",
    permissions: [
      { scope: "youtube.upload", label: "Mettre en ligne des vidéos" },
      { scope: "youtube.readonly", label: "Voir votre chaîne" },
      { scope: "yt-analytics.readonly", label: "Lire les statistiques" },
    ],
    capabilities: cap({
      video_post: "requires_review",
      reel_short: "to_verify",
      native_scheduling: "supported",
      account_analytics: "supported",
      post_analytics: "supported",
      webhooks: "to_verify",
    }),
  },
  linkedin: {
    key: "linkedin",
    name: "LinkedIn",
    availability: "available",
    maxChars: 3000,
    requiresMedia: false,
    // V1 : texte seul (Share on LinkedIn, ugcPosts). Images et vidéos : intégration ultérieure.
    mediaHint: "Texte seul pour le moment (images et vidéos bientôt)",
    formats: [{ value: "feed", label: "Publication" }],
    preview: { width: 1200, height: 627, label: "Image paysage", ratio: "1.91 / 1" },
    variantFields: ["caption", "hashtags"],
    accountRequirement: "Votre profil LinkedIn personnel. Les Pages d'entreprise arriveront plus tard (validation LinkedIn requise).",
    // Scopes réellement demandés (Sign In with LinkedIn using OpenID Connect + Share on LinkedIn).
    permissions: [
      { scope: "openid", label: "Vous identifier (OpenID Connect)" },
      { scope: "profile", label: "Voir votre nom et votre photo" },
      { scope: "email", label: "Voir votre adresse e-mail" },
      { scope: "w_member_social", label: "Publier en votre nom" },
    ],
    capabilities: cap({
      text_post: "supported",
      image_post: "to_verify",
      video_post: "to_verify",
    }),
  },
  x: {
    key: "x",
    name: "X (Twitter)",
    availability: "available",
    maxChars: 280,
    requiresMedia: false,
    // V1 : texte seul (POST /2/tweets). Images et vidéos : intégration ultérieure.
    mediaHint: "Texte seul pour le moment (images et vidéos bientôt)",
    // Format « Thread » (posts chaînés) retiré tant qu'aucun éditeur de fil n'existe dans le Composer.
    formats: [{ value: "feed", label: "Post" }],
    preview: { width: 1600, height: 900, label: "Image 16:9", ratio: "16 / 9" },
    variantFields: ["caption", "hashtags"],
    accountRequirement: "Votre compte X. Publication de texte uniquement pour le moment (280 caractères selon le décompte de X).",
    permissions: [
      { scope: "tweet.write", label: "Publier des posts" },
      { scope: "tweet.read", label: "Lire vos posts" },
      { scope: "users.read", label: "Voir votre profil" },
      { scope: "offline.access", label: "Rester connecté" },
    ],
    capabilities: cap({
      text_post: "supported",
      image_post: "to_verify",
      multi_image_post: "to_verify",
      video_post: "to_verify",
      account_analytics: "to_verify",
      post_analytics: "to_verify",
      webhooks: "to_verify",
    }),
  },
  pinterest: {
    key: "pinterest",
    name: "Pinterest",
    availability: "available",
    maxChars: 500,
    requiresMedia: true,
    mediaHint: "Image ou vidéo (média obligatoire)",
    formats: [{ value: "pin", label: "Épingle" }],
    preview: { width: 1000, height: 1500, label: "Épingle 2:3", ratio: "2 / 3" },
    variantFields: ["title", "description", "destinationUrl", "board"],
    accountRequirement: "Un compte Pinterest (analytics réservées aux comptes professionnels).",
    permissions: [
      { scope: "boards:read", label: "Voir vos tableaux" },
      { scope: "pins:write", label: "Créer des épingles" },
      { scope: "user_accounts:read", label: "Voir votre profil" },
    ],
    capabilities: cap({
      image_post: "supported",
      multi_image_post: "to_verify",
      video_post: "supported",
      native_scheduling: "to_verify",
      account_analytics: "supported",
      post_analytics: "supported",
      webhooks: "to_verify",
    }),
  },
  telegram: {
    key: "telegram",
    name: "Telegram",
    availability: "available",
    // Texte seul : 4096 caractères ; avec un média, la légende est limitée à 1024 (TELEGRAM_CAPTION_LIMIT).
    maxChars: 4096,
    requiresMedia: false,
    mediaHint: "Texte, une image, une vidéo MP4 ou un album de 2 à 10 médias (50 Mo par fichier)",
    formats: [{ value: "feed", label: "Message" }],
    preview: { width: 1280, height: 720, label: "Message", ratio: "16 / 9" },
    variantFields: ["caption", "hashtags"],
    accountRequirement: "Une chaîne ou un groupe Telegram dont vous êtes administrateur. Le bot SoxialConnect y est ajouté comme administrateur.",
    permissions: [
      { scope: "can_post_messages", label: "Publier des messages (bot administrateur)" },
    ],
    capabilities: cap({
      text_post: "supported",
      image_post: "supported",
      multi_image_post: "supported",
      video_post: "supported",
      webhooks: "supported",
    }),
  },
  threads: {
    key: "threads",
    name: "Threads",
    // Maquette : « Bientôt disponible ».
    availability: "coming_soon",
    maxChars: 500,
    requiresMedia: false,
    mediaHint: "Texte, image ou vidéo",
    formats: [{ value: "feed", label: "Publication" }],
    preview: { width: 1080, height: 1350, label: "Portrait 4:5", ratio: "4 / 5" },
    variantFields: ["caption", "hashtags"],
    accountRequirement: "Un profil Threads.",
    permissions: [
      { scope: "threads_basic", label: "Voir votre profil" },
      { scope: "threads_content_publish", label: "Publier du contenu" },
      { scope: "threads_manage_insights", label: "Lire les statistiques" },
    ],
    capabilities: cap({
      text_post: "supported",
      image_post: "supported",
      multi_image_post: "to_verify",
      video_post: "supported",
      account_analytics: "supported",
      post_analytics: "supported",
      webhooks: "to_verify",
    }),
  },
}

/** Ordre d'affichage de la maquette. */
export const PLATFORM_ORDER: PlatformKey[] = [
  "facebook",
  "instagram",
  "tiktok",
  "youtube",
  "linkedin",
  "x",
  "pinterest",
  "telegram",
  "threads",
]

/** Telegram : limites de la Bot API (vérifiées aussi par le serveur avant tout envoi). */
export const TELEGRAM_CAPTION_LIMIT = 1024
export const TELEGRAM_ALBUM_MAX = 10
export const TELEGRAM_MAX_FILE_BYTES = 50 * 1024 * 1024
export const TELEGRAM_MAX_PHOTO_BYTES = 10 * 1024 * 1024

export const CAPABILITY_LABELS: Record<Capability, string> = {
  text_post: "Texte seul",
  image_post: "Image",
  multi_image_post: "Plusieurs images",
  video_post: "Vidéo",
  story: "Story",
  reel_short: "Reel / Short",
  native_scheduling: "Programmation native",
  account_analytics: "Statistiques du compte",
  post_analytics: "Statistiques des posts",
  webhooks: "Événements temps réel",
}

export const CAPABILITY_STATUS_LABELS: Record<CapabilityStatus, string> = {
  supported: "Disponible",
  unsupported: "Non disponible",
  requires_review: "Sur validation",
  to_verify: "À confirmer",
}
