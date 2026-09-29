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
