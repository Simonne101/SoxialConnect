// Base de données en mémoire pour la phase frontend.
// Les services lisent et modifient ce store ; il sera supprimé lorsque les services
// appelleront l'API Laravel (/api/v1). Données réinitialisées au rechargement de la page.
import { mockConversations } from "@/mocks/ai"
import { mockFolders, mockMedia } from "@/mocks/media"
import { mockNotifications } from "@/mocks/notifications"
import { mockPosts } from "@/mocks/posts"
import { mockSocialAccounts } from "@/mocks/socialAccounts"
import { mockNotificationPreferences, mockSessions, mockUser } from "@/mocks/user"
import { mockVideoProjects } from "@/mocks/videos"

export const store = {
  user: structuredClone(mockUser),
  sessions: structuredClone(mockSessions),
  notificationPreferences: structuredClone(mockNotificationPreferences),
  socialAccounts: structuredClone(mockSocialAccounts),
  posts: structuredClone(mockPosts),
  media: structuredClone(mockMedia),
  folders: structuredClone(mockFolders),
  notifications: structuredClone(mockNotifications),
  videoProjects: structuredClone(mockVideoProjects),
  conversations: structuredClone(mockConversations),
}

/** Latence réseau simulée pour faire apparaître les états de chargement. */
export function delay(ms = 350): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** Erreur « API » typée, même forme que les erreurs normalisées prévues (docs/architecture.md §4.11). */
export class ApiError extends Error {
  code: string
  constructor(code: string, message: string) {
    super(message)
    this.code = code
  }
}

export function notFound(resource: string): never {
  throw new ApiError("NOT_FOUND", `${resource} introuvable.`)
}
