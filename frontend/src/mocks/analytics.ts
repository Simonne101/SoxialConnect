import type { AnalyticsData, AnalyticsPeriod, PlatformPerformance, TimeSeriesPoint, TopPost } from "@/types"
import { at, seeded } from "./helpers"

const DAYS: Record<AnalyticsPeriod, number> = { "7d": 7, "30d": 30, "90d": 90 }

function buildSeries(period: AnalyticsPeriod): TimeSeriesPoint[] {
  const n = DAYS[period]
  const rand = seeded(period === "7d" ? 7 : period === "30d" ? 30 : 90)
  let followers = 58_000
  return Array.from({ length: n }, (_, i) => {
    const trend = 1 + i / (n * 2.2)
    const impressions = Math.round((34_000 + rand() * 22_000) * trend)
    followers += Math.round(80 + rand() * 180)
    return {
      date: at(i - n + 1, 12),
      impressions,
      previousImpressions: Math.round((26_000 + rand() * 20_000) * (1 + i / (n * 4))),
      engagements: Math.round(impressions * (0.055 + rand() * 0.03)),
      reach: Math.round(impressions * (0.58 + rand() * 0.12)),
      views: Math.round(impressions * (0.42 + rand() * 0.2)),
      followers,
    }
  })
}

// Métriques null = non fournies par la plateforme (docs : jamais 0).
const PLATFORMS: PlatformPerformance[] = [
  { platform: "instagram", impressions: 128_400, reach: 81_200, engagements: 9_850, engagementRate: 7.7, views: 64_300, clicks: 2_140, followers: 24_300, followersChange: 4.2 },
  { platform: "tiktok", impressions: 96_700, reach: 71_900, engagements: 6_120, engagementRate: 6.3, views: 96_700, clicks: null, followers: 15_800, followersChange: 8.9 },
  { platform: "facebook", impressions: 48_900, reach: 30_400, engagements: 2_310, engagementRate: 4.7, views: 12_800, clicks: 1_980, followers: 8_420, followersChange: 1.1 },
  { platform: "youtube", impressions: 31_200, reach: null, engagements: 1_870, engagementRate: 6.0, views: 18_450, clicks: 1_020, followers: 6_150, followersChange: 3.4 },
  { platform: "linkedin", impressions: 14_100, reach: null, engagements: 890, engagementRate: 6.3, views: null, clicks: 2_310, followers: 3_120, followersChange: 2.0 },
  { platform: "x", impressions: 5_500, reach: null, engagements: 360, engagementRate: 6.5, views: null, clicks: 750, followers: 2_210, followersChange: -0.6 },
]

const TOP_POSTS: TopPost[] = [
  { postId: "post-13", title: "5 astuces pour des Reels qui performent", platform: "instagram", publishedAt: at(0, 7), impressions: 18_420, engagements: 1_742, engagementRate: 9.5, previewClassName: "thumb-4" },
  { postId: "post-14", title: "Coulisses de l'atelier", platform: "tiktok", publishedAt: at(-1, 18), impressions: 15_980, engagements: 1_210, engagementRate: 7.6, previewClassName: "thumb-6" },
  { postId: "post-16", title: "Checklist de rentrée", platform: "facebook", publishedAt: at(-3, 12), impressions: 9_870, engagements: 540, engagementRate: 5.5, previewClassName: "thumb-11" },
  { postId: "post-17", title: "Tutoriel montage mobile", platform: "youtube", publishedAt: at(-5, 15), impressions: 7_310, engagements: 488, engagementRate: 6.7, previewClassName: "thumb-5" },
  { postId: "post-20", title: "Nos meilleurs outils gratuits", platform: "linkedin", publishedAt: at(-9, 9), impressions: 4_920, engagements: 311, engagementRate: 6.3, previewClassName: "thumb-12" },
]

export function buildAnalytics(period: AnalyticsPeriod): AnalyticsData {
  const factor = DAYS[period] / 7
  const k = (v: number) => Math.round(v * factor)
  return {
    period,
    // Valeurs 7 jours reprises de la maquette (écran « Analyses »).
    kpis: {
      impressions: { value: k(324_800), change: 32 },
      reach: { value: k(212_600), change: 24.5 },
      engagements: { value: k(21_400), change: 18 },
      engagementRate: { value: 6.8, change: 11.4 },
      views: { value: k(192_250), change: 41.2 },
      clicks: { value: k(8_200), change: 34 },
      followers: { value: k(1_200), change: 12 },
    },
    series: buildSeries(period),
    platforms: PLATFORMS.map((p) => ({
      ...p,
      impressions: p.impressions === null ? null : k(p.impressions / 3),
      reach: p.reach === null ? null : k(p.reach / 3),
      engagements: p.engagements === null ? null : k(p.engagements / 3),
      views: p.views === null ? null : k(p.views / 3),
      clicks: p.clicks === null ? null : k(p.clicks / 3),
    })),
    topPosts: TOP_POSTS,
    audience: [
      { label: "18-24 ans", value: 22 },
      { label: "25-34 ans", value: 41 },
      { label: "35-44 ans", value: 23 },
      { label: "45-54 ans", value: 9 },
      { label: "55 ans et +", value: 5 },
    ],
  }
}
