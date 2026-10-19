// Services Analytics, Notifications, Dashboard, Utilisateur.
// Tableau de bord : GET /api/v1/dashboard/summary en mode API (comptages réels, métriques d'audience
// « non disponibles » tant qu'aucune collecte n'existe) ; données simulées uniquement en mode démonstration.
// Analytics : GET /api/v1/analytics en mode API (données réellement collectées, ADR-068) ; jeu simulé chargé
// à la demande (import dynamique) et uniquement en mode démonstration.
import type {
  ActivityItem,
  AnalyticsData,
  AnalyticsPeriod,
  LiveAnalytics,
  LiveAnalyticsFilters,
  LiveMetricKey,
  LiveSyncStatus,
  PlatformKey,
  AppNotification,
  NotificationPreferences,
  SecurityEvent,
  UserProfile,
  UserSession,
} from "@/types"
import { mockActivity, mockSecurityEvents } from "@/mocks/user"
import { http, isApiEnabled } from "@/lib/http"
import { currentUser, updateCurrentUser } from "./auth"
import { delay, store } from "./mockStore"

// --- Analytics ------------------------------------------------------------------------------
/** Jeu de démonstration : jamais appelé en mode API ; le module simulé n'est chargé qu'ici. */
export async function getAnalytics(period: AnalyticsPeriod): Promise<AnalyticsData> {
  if (isApiEnabled) throw new Error("Mode API : le jeu de démonstration n'est jamais servi.")
  const { buildAnalytics } = await import("@/mocks/analytics")
  await delay(450)
  return buildAnalytics(period)
}

const LIVE_METRICS: LiveMetricKey[] = ["impressions", "reach", "views", "engagements", "clicks", "likes", "comments", "shares", "saves", "followers"]

interface ApiLiveAnalytics {
  points: (Record<LiveMetricKey, number | null> & {
    social_account_id: string
    platform: PlatformKey | null
    post_variant_id: string | null
    date: string
    granularity: "day" | "lifetime"
    followers_delta: number | null
    engagement_rate: number | null
    extra: Record<string, string | number | boolean | null> | null
  })[]
  posts?: {
    post_variant_id: string
    post_id: string
    social_account_id: string
    platform: PlatformKey | null
    title: string | null
    external_url: string | null
    published_at: string | null
  }[]
  sync: {
    social_account_id: string
    platform: PlatformKey | null
    status: LiveSyncStatus
    last_attempt_at: string | null
    last_success_at: string | null
    last_error_code: string | null
    last_error_message: string | null
    next_attempt_at: string | null
  }[]
}

/**
 * Statistiques réellement collectées (GET /api/v1/analytics) : mode API uniquement, aucun repli simulé. Les
 * valeurs null restent null (« non disponible »).
 */
export async function getLiveAnalytics(filters: LiveAnalyticsFilters): Promise<LiveAnalytics> {
  if (!isApiEnabled) throw new Error("Statistiques réelles indisponibles en mode démonstration.")
  const query = new URLSearchParams({ from: filters.from, to: filters.to, ...(filters.platform ? { platform: filters.platform } : {}) })
  const res = await http.get<ApiLiveAnalytics>(`/analytics?${query.toString()}`)
  const data = res!.data
  const meta = (res!.meta ?? {}) as { from?: string; to?: string; truncated?: boolean; engagement_rate_formula?: string }
  return {
    points: data.points.map((p) => ({
      socialAccountId: p.social_account_id,
      platform: p.platform,
      postVariantId: p.post_variant_id,
      date: p.date,
      granularity: p.granularity,
      metrics: Object.fromEntries(LIVE_METRICS.map((k) => [k, p[k] ?? null])) as Record<LiveMetricKey, number | null>,
      followersDelta: p.followers_delta ?? null,
      engagementRate: p.engagement_rate ?? null,
      extra: p.extra ?? null,
    })),
    posts: (data.posts ?? []).map((p) => ({
      postVariantId: p.post_variant_id,
      postId: p.post_id,
      socialAccountId: p.social_account_id,
      platform: p.platform,
      title: p.title,
      externalUrl: p.external_url,
      publishedAt: p.published_at,
    })),
    sync: data.sync.map((s) => ({
      socialAccountId: s.social_account_id,
      platform: s.platform,
      status: s.status,
      lastAttemptAt: s.last_attempt_at,
      lastSuccessAt: s.last_success_at,
      lastErrorCode: s.last_error_code,
      lastErrorMessage: s.last_error_message,
      nextAttemptAt: s.next_attempt_at,
    })),
    meta: { from: meta.from ?? filters.from, to: meta.to ?? filters.to, truncated: Boolean(meta.truncated), engagementRateFormula: meta.engagement_rate_formula ?? "" },
  }
}

// --- Dashboard ------------------------------------------------------------------------------
/**
 * Indicateurs du tableau de bord. null = non disponible (métrique non collectée), jamais 0 ; variations
 * null = non calculées (l'API ne compare pas encore les périodes).
 */
export interface DashboardSummary {
  publishedCount: number
  publishedChange: number | null
  scheduledCount: number
  scheduledChange: number | null
  draftCount: number | null
  failedCount: number | null
  connectedAccounts: number | null
  impressions: number | null
  impressionsChange: number | null
  reach: number | null
  reachChange: number | null
  engagementRate: number | null
  engagementRateChange: number | null
  views: number | null
  viewsChange: number | null
  /** true : données simulées (mode démonstration, sans API). */
  simulated: boolean
}

/** Périodes acceptées par GET /dashboard/summary (le serveur refuse toute autre valeur). */
export type DashboardRange = "today" | "7d" | "30d"

interface ApiDashboardSummary {
  range: DashboardRange
  published_count: number
  scheduled_count: number
  draft_count: number
  failed_count: number
  connected_accounts: number
  impressions: number | null
  reach: number | null
  views: number | null
  engagement_rate: number | null
}

const RANGE_DAYS: Record<DashboardRange, number> = { today: 1, "7d": 7, "30d": 30 }

export async function getDashboardSummary(range: DashboardRange = "today"): Promise<DashboardSummary> {
  if (isApiEnabled) {
    const res = await http.get<ApiDashboardSummary>(`/dashboard/summary?range=${encodeURIComponent(range)}`)
    const s = res!.data
    return {
      publishedCount: s.published_count,
      publishedChange: null,
      scheduledCount: s.scheduled_count,
      scheduledChange: null,
      draftCount: s.draft_count,
      failedCount: s.failed_count,
      connectedAccounts: s.connected_accounts,
      impressions: s.impressions,
      impressionsChange: null,
      reach: s.reach,
      reachChange: null,
      engagementRate: s.engagement_rate,
      engagementRateChange: null,
      views: s.views,
      viewsChange: null,
      simulated: false,
    }
  }
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
    draftCount: null,
    failedCount: null,
    connectedAccounts: null,
    simulated: true,
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
// Utilisateur courant : branché sur l'API Laravel (GET/PATCH /api/v1/users/me) via services/auth.
export function getMe(): Promise<UserProfile> {
  return currentUser()
}

export function updateMe(patch: Partial<UserProfile>): Promise<UserProfile> {
  return updateCurrentUser(patch)
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

export interface ChangePasswordInput {
  currentPassword: string
  password: string
  passwordConfirmation: string
}

/**
 * Changement de mot de passe : PUT /api/v1/auth/password (Fortify, session Sanctum). Le serveur
 * vérifie le mot de passe actuel et la politique de mot de passe ; toute erreur (422, 401, 429,
 * réseau, 5xx) est levée en ApiError — jamais de succès sans réponse positive du serveur.
 * Les mots de passe ne sont ni journalisés ni conservés.
 * Mode démonstration (sans backend) : aucun envoi, aucune donnée stockée.
 */
export async function changePassword(input: ChangePasswordInput): Promise<void> {
  if (!isApiEnabled) {
    await delay(700)
    return
  }
  await http.put<unknown>("/auth/password", {
    current_password: input.currentPassword,
    password: input.password,
    password_confirmation: input.passwordConfirmation,
  })
}
