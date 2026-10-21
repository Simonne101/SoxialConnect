import { PLATFORMS } from "../../../lib/platforms.ts"
import type { MediaAsset, PlatformKey, SocialAccount, VariantOptions } from "@/types"

/** Libellés de la visibilité YouTube — absente : « private », comme le serveur (ADR-065). */
export const YOUTUBE_VISIBILITY_LABELS = { public: "Publique", unlisted: "Non répertoriée", private: "Privée" } as const

export const TIKTOK_PRIVACY_LABELS = { public: "Tout le monde", followers: "Mes abonnés", friends: "Amis (abonnements mutuels)", private: "Moi uniquement" } as const

export interface RecapLine {
  /** Clé de la destination (réseau + compte). */
  key: string
  platform: PlatformKey
  network: string
  account: string
  text: string
  /** Visibilité qui sera envoyée ; null quand le réseau n'en a pas dans SoxialConnect. */
  visibility: string | null
}

/** Visibilité réellement transmise au serveur pour ce réseau (jamais la valeur affichée par défaut). */
export function visibilityLabel(platform: PlatformKey, options: VariantOptions): string | null {
  if (platform === "youtube") {
    const value = options.youtubeVisibility
    return value && value in YOUTUBE_VISIBILITY_LABELS ? YOUTUBE_VISIBILITY_LABELS[value] : `${YOUTUBE_VISIBILITY_LABELS.private} (par défaut)`
  }
  if (platform === "tiktok") return options.privacy ? TIKTOK_PRIVACY_LABELS[options.privacy] : null

  return null
}

/**
 * Récapitulatif avant publication ou programmation : UNE ligne par destination (réseau + compte), avec le
 * texte envoyé et la visibilité. Le texte vient de l'appelant (variantText du Composer) pour rester
 * identique à ce qui est enregistré.
 */
export function publicationRecap(input: {
  destinations: { platform: PlatformKey; account: SocialAccount | undefined }[]
  options: (platform: PlatformKey) => VariantOptions
  text: (platform: PlatformKey) => string
}): RecapLine[] {
  return input.destinations.map(({ platform, account }, index) => {
    return {
      key: `${platform}:${account?.id ?? index}`,
      platform,
      network: PLATFORMS[platform].name,
      account: account?.entities[0]?.name ?? account?.displayName ?? account?.username ?? "Compte non trouvé",
      text: input.text(platform),
      visibility: visibilityLabel(platform, input.options(platform)),
    }
  })
}

/** Médias du récapitulatif, dans l'ordre de la publication. */
export function recapMedia(mediaIds: string[], mediaMap: Record<string, MediaAsset>): MediaAsset[] {
  return mediaIds.map((id) => mediaMap[id]).filter((m): m is MediaAsset => Boolean(m))
}
