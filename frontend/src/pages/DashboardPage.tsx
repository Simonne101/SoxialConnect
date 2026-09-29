import { CalendarDays, Eye, FileCheck2, Megaphone, Plus, Repeat2 } from "lucide-react"
import { Suspense, lazy, useState } from "react"
import { Link } from "react-router"
import {
  AlertsBanner,
  ConnectedNetworks,
  QuickActions,
  RecentActivity,
  UpcomingPosts,
} from "@/components/dashboard/DashboardWidgets"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { StatCard } from "@/components/shared/StatCard"
import { CardsSkeleton, ErrorState } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { usePosts } from "@/hooks/queries/content"
import { useActivity, useAnalytics, useDashboardSummary } from "@/hooks/queries/misc"
import { useSocialAccounts } from "@/hooks/queries/social"
import { formatCompact, formatPercent } from "@/lib/format"
import type { DashboardRange } from "@/services/misc"
import type { AnalyticsPeriod } from "@/types"

// Recharts est chargé à part pour ne pas alourdir le premier rendu.
const PerformanceChart = lazy(() => import("@/components/analytics/charts").then((m) => ({ default: m.PerformanceChart })))

export default function DashboardPage() {
  const [range, setRange] = useState<DashboardRange>("today")
  const [chartPeriod, setChartPeriod] = useState<AnalyticsPeriod>("7d")
  const summary = useDashboardSummary(range)
  const accounts = useSocialAccounts()
  // Publications récentes et à venir (100 premières) : prochaines publications et échecs.
  const posts = usePosts({ perPage: 100 })
  const activity = useActivity()
  const analytics = useAnalytics(chartPeriod)

  const failedPosts = posts.data?.items.filter((p) => p.status === "failed" || p.status === "partially_published") ?? []
  const s = summary.data
  const changeLabel = range === "today" ? "vs hier" : "vs période précédente"

  return (
    <>
      <PageHeader
        title="Tableau de bord"
        description="Vue d'ensemble de votre activité sur les réseaux sociaux"
        actions={
          <>
            <Select value={range} onValueChange={(v) => setRange(v as DashboardRange)}>
              <SelectTrigger className="w-40" aria-label="Période des indicateurs">
                <CalendarDays className="size-4 text-ink-muted" />
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="today">Aujourd'hui</SelectItem>
                <SelectItem value="7d">7 derniers jours</SelectItem>
                <SelectItem value="30d">30 derniers jours</SelectItem>
              </SelectContent>
            </Select>
            <Button asChild>
              <Link to="/content/create">
                <Plus /> Créer une publication
              </Link>
            </Button>
          </>
        }
      />

      {summary.isLoading ? (
        <CardsSkeleton />
      ) : summary.isError || !s ? (
        <ErrorState onRetry={() => summary.refetch()} />
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
          <StatCard label="Publications publiées" value={String(s.publishedCount)} change={s.publishedChange} changeLabel={changeLabel} icon={FileCheck2} accent="brand" />
          <StatCard label="Programmées" value={String(s.scheduledCount)} change={s.scheduledChange} changeLabel={changeLabel} icon={Repeat2} accent="success" />
          <StatCard label="Total d'impressions" value={formatCompact(s.impressions)} change={s.impressionsChange} changeLabel={changeLabel} icon={Eye} accent="warning" />
          <StatCard label="Taux d'engagement" value={formatPercent(s.engagementRate)} change={s.engagementRateChange} changeLabel={changeLabel} icon={Megaphone} accent="violet" />
        </div>
      )}

      {accounts.data && <AlertsBanner accounts={accounts.data} failedPosts={failedPosts} />}

      <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <SectionCard
          title="Performance globale"
          action={
            <Select value={chartPeriod} onValueChange={(v) => setChartPeriod(v as AnalyticsPeriod)}>
              <SelectTrigger size="sm" className="w-40" aria-label="Période du graphique">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="7d">7 derniers jours</SelectItem>
                <SelectItem value="30d">30 derniers jours</SelectItem>
                <SelectItem value="90d">90 derniers jours</SelectItem>
              </SelectContent>
            </Select>
          }
        >
          <div className="mb-3 flex flex-wrap gap-x-6 gap-y-2 text-xs">
            <span className="inline-flex items-center gap-2 text-ink-soft">
              <span className="h-0.5 w-4 rounded bg-chart-1" aria-hidden="true" /> Impressions
            </span>
            <span className="inline-flex items-center gap-2 text-ink-soft">
              <span className="h-0.5 w-4 rounded bg-chart-2" aria-hidden="true" /> Engagement
            </span>
            {s && (
              <>
                <span className="text-ink-muted">
                  Portée : <strong className="text-ink">{formatCompact(s.reach)}</strong>
                </span>
                <span className="text-ink-muted">
                  Vues : <strong className="text-ink">{formatCompact(s.views)}</strong>
                </span>
              </>
            )}
          </div>
          <div className="h-64 w-full" role="img" aria-label="Évolution des impressions et de l'engagement sur la période">
            {analytics.data ? (
              <Suspense fallback={<Skeleton className="h-full w-full" />}>
                <PerformanceChart data={analytics.data.series} />
              </Suspense>
            ) : (
              <Skeleton className="h-full w-full" />
            )}
          </div>
        </SectionCard>
        <ConnectedNetworks accounts={accounts.data} isLoading={accounts.isLoading} />
        <UpcomingPosts posts={posts.data?.items} isLoading={posts.isLoading} />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <RecentActivity items={activity.data} isLoading={activity.isLoading} />
        <QuickActions />
      </div>
    </>
  )
}
