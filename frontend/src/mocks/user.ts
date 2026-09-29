import type { ActivityItem, NotificationPreferences, SecurityEvent, UserProfile, UserSession } from "@/types"
import { minutesAgo } from "./helpers"

// Persona de la maquette. Aucune donnée réelle, aucun mot de passe.
export const mockUser: UserProfile = {
  id: "user-marie",
  name: "Marie Dubois",
  email: "marie.dubois@example.com",
  role: "Créatrice de contenu",
  bio: "Je partage des conseils de création et de productivité pour les indépendants.",
  timezone: "Europe/Paris",
  locale: "fr",
  emailVerified: true,
  twoFactorEnabled: false,
}

export const mockSessions: UserSession[] = [
  {
    id: "sess-1",
    device: "Chrome · Windows",
    location: "Paris, France",
    ipMasked: "81.64.•••.•••",
    lastActiveAt: minutesAgo(1),
    current: true,
  },
  {
    id: "sess-2",
    device: "Safari · iPhone",
    location: "Paris, France",
    ipMasked: "92.184.•••.•••",
    lastActiveAt: minutesAgo(180),
    current: false,
  },
  {
    id: "sess-3",
    device: "Firefox · macOS",
    location: "Lyon, France",
    ipMasked: "176.150.•••.•••",
    lastActiveAt: minutesAgo(60 * 24 * 3),
    current: false,
  },
]

export const mockNotificationPreferences: NotificationPreferences = {
  publishSuccessEmail: false,
  publishFailureEmail: true,
  accountAlertsEmail: true,
  videoReadyEmail: true,
  weeklyReportEmail: true,
  inAppAll: true,
}

export const mockSecurityEvents: SecurityEvent[] = [
  { id: "se-1", action: "LOGIN", label: "Connexion réussie", ipMasked: "81.64.•••.•••", createdAt: minutesAgo(35) },
  { id: "se-2", action: "OAUTH_CONNECTED", label: "Instagram connecté", ipMasked: "81.64.•••.•••", createdAt: minutesAgo(60 * 26) },
  { id: "se-3", action: "TOKEN_REFRESHED", label: "Autorisation Facebook renouvelée", ipMasked: "Système", createdAt: minutesAgo(60 * 30) },
  { id: "se-4", action: "PASSWORD_CHANGED", label: "Mot de passe modifié", ipMasked: "81.64.•••.•••", createdAt: minutesAgo(60 * 24 * 12) },
  { id: "se-5", action: "LOGIN", label: "Connexion réussie", ipMasked: "92.184.•••.•••", createdAt: minutesAgo(60 * 24 * 14) },
]

export const mockActivity: ActivityItem[] = [
  { id: "a-1", kind: "published", title: "Publication publiée", detail: "« 5 astuces pour des Reels qui performent » sur Instagram", createdAt: minutesAgo(5), href: "/content" },
  { id: "a-2", kind: "clip", title: "Clips prêts", detail: "6 clips détectés dans « Interview — Stratégie de contenu »", createdAt: minutesAgo(12), href: "/video-studio" },
  { id: "a-3", kind: "failed", title: "Échec de publication", detail: "LinkedIn : l'autorisation a expiré", createdAt: minutesAgo(120), href: "/social-accounts" },
  { id: "a-4", kind: "scheduled", title: "Publication programmée", detail: "« Nouvelle vidéo YouTube » demain à 14:30", createdAt: minutesAgo(200), href: "/content/calendar" },
  { id: "a-5", kind: "media", title: "Médias importés", detail: "8 photos ajoutées au dossier « Campagne automne »", createdAt: minutesAgo(60 * 20), href: "/media" },
  { id: "a-6", kind: "ai", title: "Légende générée", detail: "Assistant IA — ton inspirant pour Instagram", createdAt: minutesAgo(60 * 26), href: "/ai" },
]
