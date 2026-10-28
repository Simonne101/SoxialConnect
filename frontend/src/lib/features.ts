// Fonctionnalités disponibles selon la phase du backend.
// Données de démonstration (sans VITE_API_URL) : tout est simulé côté navigateur.
// Backend branché : seules les fonctions réellement implémentées côté serveur sont actives.
import type { PlatformKey } from "@/types"
import { ApiError, isApiEnabled } from "./http"

export const features = {
  /**
   * « Publier maintenant » / « Réessayer » : moteur de publication interne du backend. Sans
   * intégration officielle active, l'envoi échoue explicitement (NOT_CONFIGURED) : jamais de
   * succès simulé.
   */
  publishing: true,
  /** Ajout de médias dans le Composer : médiathèque réelle (phase Media) ou démonstration. */
  media: true,
  /** Connexion / déconnexion des réseaux : phase Social Connectors (OAuth officiel). */
  socialConnect: !isApiEnabled,
} as const

/** Réseaux dont la connexion OAuth officielle est livrée (backend branché). */
export const OAUTH_PLATFORMS: readonly PlatformKey[] = ["youtube", "tiktok", "linkedin", "x", "facebook", "instagram"]

export const PUBLISHING_UNAVAILABLE = "Disponible avec la publication réelle"
export const MEDIA_UNAVAILABLE = "Bientôt disponible"
/** Messages affichés quand une action sur un réseau social n'est pas encore disponible (OAuth). */
export const SOCIAL_ACTION_UNAVAILABLE = {
  connect: "La connexion des réseaux sociaux sera disponible avec l'intégration OAuth.",
  disconnect: "La déconnexion des réseaux sociaux sera disponible avec l'intégration OAuth.",
  sync: "La synchronisation des réseaux sociaux sera disponible avec l'intégration OAuth.",
} as const

/** Action refusée volontairement car la fonctionnalité n'est pas encore livrée (aucun appel serveur). */
export class FeatureUnavailableError extends ApiError {
  constructor(message: string) {
    super(409, message)
  }
}
