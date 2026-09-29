// Services Analytics, Notifications, Dashboard, Utilisateur — implémentations mockées.
// Futur : /api/v1/analytics, /api/v1/notifications, /api/v1/me…
import type {
  ActivityItem,
  AnalyticsData,
  AnalyticsPeriod,
  AppNotification,
  NotificationPreferences,
  SecurityEvent,
  UserProfile,
  UserSession,
} from "@/types"
import { buildAnalytics } from "@/mocks/analytics"
import { mockActivity, mockSecurityEvents } from "@/mocks/user"
import { delay, store } from "./mockStore"

// --- Analytics ------------------------------------------------------------------------------
export async function getAnalytics(period: AnalyticsPeriod): Promise<AnalyticsData> {
  await delay(450)
  return buildAnalytics(period)
}

// --- Dashboard ------------------------------------------------------------------------------
export interface DashboardSummary {
  publishedCount: number
  publishedChange: number
  scheduledCount: number
  scheduledChange: number
  impressions: number
  impressionsChange: number
  reach: number
  reachChange: number
  engagementRate: number
  engagementRateChange: number
  views: number
  viewsChange: number
}

export type DashboardRange = "today" | "7d" | "30d"

const RANGE_DAYS: Record<DashboardRange, number> = { today: 1, "7d": 7, "30d": 30 }

export async function getDashboardSummary(range: DashboardRange = "today"): Promise<DashboardSummary> {
  await delay(300)
  const days = RANGE_DAYS[range]
  const since = Date.now() - days * 86_400_000
  const published = store.posts.filter(
    (p) => (p.status === "published" || p.status === "partially_published") && p.publishedAt && new Date(p.publishedAt).getTime() > since,
  ).length
  // Volumes « aujourd'hui » repris de la maquette, extrapolés sur la période choisie.
  const k = (v: number) => Math.round(v * (range === "today" ? 1 : days * 0.85))
  return {
    publishedCount: range === "today" ? Math.max(published, 12) : published,
    publishedChange: 20,
    scheduledCount: store.posts.filter((p) => p.status === "scheduled").length,
    scheduledChange: 2,
    impressions: k(48_200),
    impressionsChange: 32,
    reach: k(31_600),
    reachChange: 18.5,
    engagementRate: 6.8,
    engagementRateChange: 11.4,
    views: k(27_900),
    viewsChange: 24.1,
  }
}

export async function getActivity(): Promise<ActivityItem[]> {
  await delay(250)
  return structuredClone(mockActivity)
}

// --- Notifications -------------------------------------------------------------------------
export async function listNotifications(): Promise<AppNotification[]> {
  await delay(250)
  return structuredClone(store.notifications).sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
}

export async function markNotificationsRead(ids: string[] | "all"): Promise<void> {
  await delay(150)
  const now = new Date().toISOString()
  for (const n of store.notifications) if (ids === "all" || ids.includes(n.id)) n.readAt = n.readAt ?? now
}

export async function deleteNotification(id: string): Promise<void> {
  await delay(150)
  store.notifications = store.notifications.filter((n) => n.id !== id)
}

// --- Utilisateur / paramètres ----------------------------------------------------------------
export async function getMe(): Promise<UserProfile> {
  await delay(150)
  return structuredClone(store.user)
}

export async function updateMe(patch: Partial<UserProfile>): Promise<UserProfile> {
  await delay(500)
  Object.assign(store.user, patch)
  return structuredClone(store.user)
}

export async function listSessions(): Promise<UserSession[]> {
  await delay(250)
  return structuredClone(store.sessions)
}

export async function revokeSession(id: string): Promise<void> {
  await delay(400)
  store.sessions = store.sessions.filter((s) => s.id !== id || s.current)
}

export async function listSecurityEvents(): Promise<SecurityEvent[]> {
  await delay(250)
  return structuredClone(mockSecurityEvents)
}

export async function getNotificationPreferences(): Promise<NotificationPreferences> {
  await delay(200)
  return structuredClone(store.notificationPreferences)
}

export async function updateNotificationPreferences(patch: Partial<NotificationPreferences>): Promise<NotificationPreferences> {
  await delay(300)
  Object.assign(store.notificationPreferences, patch)
  return structuredClone(store.notificationPreferences)
}

/** Simulation — aucun mot de passe n'est stocké ni transmis dans cette phase. */
export async function changePassword(): Promise<void> {
  await delay(700)
}
