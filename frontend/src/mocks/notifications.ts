import type { AppNotification } from "@/types"
import { minutesAgo } from "./helpers"

// Reprend les notifications de la maquette. « Nouveau commentaire » et « Mise à jour disponible »
// sont exclus : fonctions hors V1 (docs/ux-ui.md UX-15, UX-16).
export const mockNotifications: AppNotification[] = [
  { id: "n-1", type: "post_published", severity: "success", title: "Publication publiée avec succès", message: "Votre post Instagram a bien été publié.", platform: "instagram", href: "/content", readAt: null, createdAt: minutesAgo(5) },
  { id: "n-2", type: "video_ready", severity: "info", title: "Votre vidéo est prête", message: "6 clips ont été détectés dans « Interview — Stratégie de contenu ».", platform: null, href: "/video-studio", readAt: null, createdAt: minutesAgo(12) },
  { id: "n-3", type: "account_expired", severity: "warning", title: "Réseau déconnecté", message: "LinkedIn n'est plus connecté. Reconnectez-le pour continuer à publier.", platform: "linkedin", href: "/social-accounts", readAt: null, createdAt: minutesAgo(120) },
  { id: "n-4", type: "post_failed", severity: "error", title: "Échec de publication", message: "« Retour d'expérience client » n'a pas pu être publié sur LinkedIn.", platform: "linkedin", href: "/content", readAt: null, createdAt: minutesAgo(125) },
  { id: "n-5", type: "permission_missing", severity: "warning", title: "Permission requise", message: "X : la permission « Publier des posts » est nécessaire.", platform: "x", href: "/social-accounts", readAt: minutesAgo(60), createdAt: minutesAgo(180) },
  { id: "n-6", type: "post_scheduled", severity: "info", title: "Publication programmée", message: "« Nouvelle vidéo YouTube » sera publiée demain à 14:30.", platform: "youtube", href: "/content/calendar", readAt: minutesAgo(150), createdAt: minutesAgo(200) },
  { id: "n-7", type: "media_ready", severity: "success", title: "Médias prêts", message: "8 photos ont été ajoutées à « Campagne automne ».", platform: null, href: "/media", readAt: minutesAgo(600), createdAt: minutesAgo(60 * 20) },
  { id: "n-8", type: "clip_rendered", severity: "success", title: "Clip exporté", message: "« Le hook parfait en 3 secondes » est disponible dans vos médias.", platform: null, href: "/media", readAt: minutesAgo(60 * 30), createdAt: minutesAgo(60 * 32) },
]
