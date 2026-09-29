import type { AnalyticsPeriod, MediaFilters, PostFilters } from "@/types"

// Fabrique centralisée des clés de requêtes TanStack Query.
export const qk = {
  posts: {
    all: ["posts"] as const,
    list: (f: PostFilters) => ["posts", "list", f] as const,
    detail: (id: string) => ["posts", "detail", id] as const,
  },
  socialAccounts: ["social-accounts"] as const,
  media: {
    all: ["media"] as const,
    list: (f: MediaFilters) => ["media", "list", f] as const,
    folders: ["media", "folders"] as const,
    tags: ["media", "tags"] as const,
  },
  analytics: (p: AnalyticsPeriod) => ["analytics", p] as const,
  dashboard: ["dashboard"] as const,
  dashboardRange: (r: string) => ["dashboard", r] as const,
  activity: ["activity"] as const,
  notifications: ["notifications"] as const,
  me: ["me"] as const,
  sessions: ["sessions"] as const,
  securityEvents: ["security-events"] as const,
  notificationPreferences: ["notification-preferences"] as const,
  videoProjects: {
    all: ["video-projects"] as const,
    detail: (id: string) => ["video-projects", id] as const,
  },
  conversations: ["ai-conversations"] as const,
}
