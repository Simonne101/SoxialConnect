// Types du domaine Social — alignés sur docs/database.md (social_platforms, social_accounts…).

export type PlatformKey =
  | "facebook"
  | "instagram"
  | "tiktok"
  | "youtube"
  | "linkedin"
  | "x"
  | "pinterest"
  | "threads"

export type PlatformAvailability = "available" | "coming_soon" | "disabled"

/** Statut d'une capacité : docs/architecture.md §4.2 — « to_verify » = désactivé en production. */
export type CapabilityStatus = "supported" | "unsupported" | "requires_review" | "to_verify"

export type Capability =
  | "text_post"
  | "image_post"
  | "multi_image_post"
  | "video_post"
  | "story"
  | "reel_short"
  | "native_scheduling"
  | "account_analytics"
  | "post_analytics"
  | "webhooks"

export interface SocialPlatform {
  key: PlatformKey
  name: string
  availability: PlatformAvailability
  capabilities: Record<Capability, CapabilityStatus>
}

/**
 * Statuts d'un compte côté interface.
 * Le backend stocke connected | expired | revoked | error (database.md) ;
 * « disconnected », « permission_required » et « coming_soon » sont dérivés pour l'UI.
 */
export type SocialAccountStatus =
  | "connected"
  | "disconnected"
  | "expired"
  | "permission_required"
  | "error"
  | "coming_soon"

export type SocialAccountType =
  | "profile"
  | "page"
  | "business"
  | "creator"
  | "channel"
  | "organization"

export interface SocialPermission {
  scope: string
  label: string
  status: "granted" | "declined" | "missing"
  required: boolean
}

export interface SocialAccount {
  id: string
  platform: PlatformKey
  status: SocialAccountStatus
  accountType: SocialAccountType | null
  displayName: string | null
  username: string | null
  /** Sous-entités gérées (pages, chaînes…) — maquette : « Pages (2) ». */
  entities: { id: string; name: string; kind: string }[]
  permissions: SocialPermission[]
  followers: number | null
  connectedAt: string | null
  lastSyncedAt: string | null
  statusMessage: string | null
  /** Compte fictif de développement local (aucune connexion réelle). */
  isDemo?: boolean
}
