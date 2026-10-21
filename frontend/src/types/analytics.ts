// Types du domaine Analytics — métriques normalisées (docs/database.md §3.7).
// Règle : null = non fourni par la plateforme (affiché « n/d »), jamais 0.
import type { PlatformKey } from "./social"

export type AnalyticsPeriod = "7d" | "30d" | "90d"

export interface MetricValue {
  value: number | null
  /** Variation en % par rapport à la période précédente. */
  change: number | null
}

export interface AnalyticsKpis {
  impressions: MetricValue
  reach: MetricValue
  engagements: MetricValue
  engagementRate: MetricValue
  views: MetricValue
  clicks: MetricValue
  followers: MetricValue
}

export interface TimeSeriesPoint {
  date: string
  impressions: number
  previousImpressions: number
  engagements: number
  reach: number
  views: number
  followers: number
}

export interface PlatformPerformance {
  platform: PlatformKey
  impressions: number | null
  reach: number | null
  engagements: number | null
  engagementRate: number | null
  views: number | null
  clicks: number | null
  followers: number | null
  followersChange: number | null
}

export interface TopPost {
  postId: string
  title: string
  platform: PlatformKey
  publishedAt: string
  impressions: number | null
  engagements: number | null
  engagementRate: number | null
  previewClassName: string
}

export interface AnalyticsData {
  period: AnalyticsPeriod
  kpis: AnalyticsKpis
  series: TimeSeriesPoint[]
  platforms: PlatformPerformance[]
  topPosts: TopPost[]
  audience: { label: string; value: number }[]
}

// --- Statistiques réelles (GET /api/v1/analytics — ADR-063, ADR-068) --------------------------------------

/** Compteurs normalisés : null = non fourni par le réseau (jamais 0). */
export type LiveMetricKey = "impressions" | "reach" | "views" | "engagements" | "clicks" | "likes" | "comments" | "shares" | "saves" | "followers"

/** Point collecté : compte (postVariantId null) ou publication ; « lifetime » = cumul relevé à la date. */
export interface LiveAnalyticsPoint {
  socialAccountId: string
  platform: PlatformKey | null
  postVariantId: string | null
  date: string
  granularity: "day" | "lifetime"
  metrics: Record<LiveMetricKey, number | null>
  followersDelta: number | null
  engagementRate: number | null
  extra: Record<string, string | number | boolean | null> | null
}

/** Publication citée par les points (titre, lien, date). */
export interface LiveAnalyticsPost {
  postVariantId: string
  postId: string
  socialAccountId: string
  platform: PlatformKey | null
  title: string | null
  externalUrl: string | null
  publishedAt: string | null
}

export type LiveSyncStatus = "pending" | "running" | "succeeded" | "failed" | "unsupported"

/** État de collecte d'un compte, enregistré ou déduit par le serveur. */
export interface LiveSyncState {
  socialAccountId: string
  platform: PlatformKey | null
  status: LiveSyncStatus
  lastAttemptAt: string | null
  lastSuccessAt: string | null
  lastErrorCode: string | null
  lastErrorMessage: string | null
  nextAttemptAt: string | null
}

export interface LiveAnalyticsFilters {
  from: string
  to: string
  platform?: PlatformKey
}

export interface LiveAnalytics {
  points: LiveAnalyticsPoint[]
  posts: LiveAnalyticsPost[]
  sync: LiveSyncState[]
  meta: { from: string; to: string; truncated: boolean; engagementRateFormula: string }
}
