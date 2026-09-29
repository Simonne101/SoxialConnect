// Types du domaine Content — alignés sur docs/database.md (posts, post_variants, post_media).
import type { PlatformKey } from "./social"

export type PostStatus =
  | "draft"
  | "scheduled"
  | "processing"
  | "partially_published"
  | "published"
  | "failed"
  | "cancelled"

export type VariantStatus =
  | "draft"
  | "scheduled"
  | "queued"
  | "publishing"
  | "published"
  | "failed"
  | "cancelled"

export type PostFormat =
  | "feed"
  | "story"
  | "reel"
  | "short"
  | "video"
  | "pin"
  | "thread"
  | "carousel"

export type PostSource = "manual" | "ai" | "clip"

/** Options spécifiques à une plateforme (validées plus tard par l'adaptateur backend). */
export interface VariantOptions {
  title?: string
  description?: string
  hashtags?: string[]
  destinationUrl?: string
  board?: string
  /** TikTok : visibilité choisie explicitement par l'utilisateur (aucune valeur par défaut). */
  privacy?: "public" | "followers" | "friends" | "private"
  allowComments?: boolean
  allowDuet?: boolean
  allowStitch?: boolean
  commercialContent?: boolean
  youtubeVisibility?: "public" | "unlisted" | "private"
  madeForKids?: boolean
  coverMediaId?: string
  thumbnailMediaId?: string
  firstComment?: string
  /** Sous-compte ciblé (ex. une des Pages Facebook de la connexion). */
  accountEntityId?: string
}

export interface PostVariant {
  id: string
  postId: string
  platform: PlatformKey
  socialAccountId: string
  format: PostFormat
  /** null = hérite du texte principal du post. */
  body: string | null
  options: VariantOptions
  status: VariantStatus
  scheduledAt: string | null
  publishedAt: string | null
  externalUrl: string | null
  errorMessage: string | null
}

export interface PostMetrics {
  impressions: number | null
  reach: number | null
  engagements: number | null
  views: number | null
  clicks: number | null
}

export interface Post {
  id: string
  title: string | null
  body: string
  status: PostStatus
  scheduledAt: string | null
  publishedAt: string | null
  timezone: string
  source: PostSource
  mediaIds: string[]
  variants: PostVariant[]
  metrics: PostMetrics | null
  /** Verrou optimiste : renvoyé à chaque écriture, refus 409 si la publication a changé entre-temps. */
  lockVersion: number
  createdAt: string
  updatedAt: string
}

/** Données envoyées par le Composer (future requête POST /api/v1/posts). */
export interface PostDraftInput {
  title: string | null
  body: string
  mediaIds: string[]
  targets: {
    platform: PlatformKey
    socialAccountId: string
    format: PostFormat
    body: string | null
    options: VariantOptions
  }[]
  scheduledAt: string | null
  timezone: string
}

export interface PostFilters {
  search?: string
  status?: PostStatus | PostStatus[] | "all"
  platform?: PlatformKey | "all"
  /** Calendrier : bornes ISO 8601 appliquées à la date de programmation. */
  from?: string
  to?: string
  page?: number
  perPage?: number
}

/** Page de résultats (GET /api/v1/content/posts). */
export interface PostPage {
  items: Post[]
  page: number
  perPage: number
  total: number
  lastPage: number
  /** Nombre de publications par statut, pour les filtres hors statut (onglets). */
  statusCounts: Record<PostStatus, number>
}
