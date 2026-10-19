import { BarChart3, Eye, Heart, MousePointerClick, Radio, UserPlus, Users } from "lucide-react"
import { useState } from "react"
import { Link } from "react-router"
import {
  ComparisonChart,
  EngagementBarChart,
  FollowersLineChart,
  PlatformBarChart,
} from "@/components/analytics/charts"
import { LiveAnalytics } from "@/components/analytics/LiveAnalytics"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { StatCard } from "@/components/shared/StatCard"
import { CardsSkeleton, DemoNotice, EmptyState, ErrorState } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useAnalytics } from "@/hooks/queries/misc"
import { useSocialAccounts } from "@/hooks/queries/social"
import { formatChange, formatCompact, formatDate, formatInt, formatPercent } from "@/lib/format"
import { isApiEnabled } from "@/lib/http"
import { PLATFORMS } from "@/lib/platforms"
import { cn } from "@/lib/utils"
import type { AnalyticsData, AnalyticsPeriod } from "@/types"

const PERIOD_LABEL: Record<AnalyticsPeriod, string> = { "7d": "7 derniers jours", "30d": "30 derniers jours", "90d": "90 derniers jours" }

function TopPostsTable({ data, limit }: { data: AnalyticsData; limit?: number }) {
  const rows = limit ? data.topPosts.slice(0, limit) : data.topPosts
  return (
    <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Tableau des meilleurs contenus">
      <table className="w-full min-w-[520px] text-left text-sm">
        <caption className="sr-only">Contenus les plus performants</caption>
        <thead>
          <tr className="border-b border-border text-xs text-ink-muted">
            <th scope="col" className="py-2 pr-3 font-medium">Contenu</th>
            <th scope="col" className="px-3 py-2 font-medium">Impressions</th>
            <th scope="col" className="px-3 py-2 font-medium">Engagement</th>
            <th scope="col" className="py-2 pl-3 font-medium">Taux</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((p, i) => (
            <tr key={p.postId} className="border-b border-border last:border-none">
              <td className="py-3 pr-3">
                <div className="flex items-center gap-3">
                  <span className="w-4 text-xs font-bold text-ink-muted">{i + 1}</span>
                  <span className={cn("size-10 shrink-0 rounded-lg", p.previewClassName)} aria-hidden="true" />
                  <span className="min-w-0">
                    <span className="block truncate font-semibold text-ink">{p.title}</span>
                    <span className="flex items-center gap-1.5 text-xs text-ink-muted">
                      <PlatformIcon platform={p.platform} size="xs" /> {PLATFORMS[p.platform].name} · {formatDate(p.publishedAt, "d MMM")}
                    </span>
                  </span>
                </div>
              </td>
              <td className="px-3 py-3 font-medium text-ink">{formatCompact(p.impressions)}</td>
              <td className="px-3 py-3 text-ink-soft">{formatCompact(p.engagements)}</td>
              <td className="py-3 pl-3"><span className="font-semibold text-success-700">{formatPercent(p.engagementRate)}</span></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default function AnalyticsPage() {
  // Mode API : statistiques réellement collectées (GET /analytics), jamais de jeu simulé (ADR-068).
  return isApiEnabled ? <LiveAnalytics /> : <AnalyticsDemo />
}


/** Mode démonstration (sans API) : jeu simulé, signalé comme tel en tête de page. */
function AnalyticsDemo() {
  const [period, setPeriod] = useState<AnalyticsPeriod>("7d")
  const { data, isLoading, isError, refetch, isFetching } = useAnalytics(period)
  const accounts = useSocialAccounts()
  const noAccount = accounts.data && !accounts.data.some((a) => a.status === "connected")

  return (
    <>
      <PageHeader
        title="Analyses"
        description="Suivez la performance de vos contenus."
        actions={
          <Select value={period} onValueChange={(v) => setPeriod(v as AnalyticsPeriod)}>
            <SelectTrigger className="w-48" aria-label="Période analysée">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(PERIOD_LABEL) as AnalyticsPeriod[]).map((p) => (
                <SelectItem key={p} value={p}>{PERIOD_LABEL[p]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        }
      />

      <DemoNotice className="mb-4">Mode démonstration : statistiques simulées. Chaque réseau fournit des métriques différentes : les valeurs indisponibles sont affichées « n/d ».</DemoNotice>

      {noAccount ? (
        <EmptyState icon={BarChart3} title="Aucune donnée Analytics" description="Connectez au moins un réseau social : vos statistiques apparaîtront ici après la première synchronisation." action={<Button asChild><Link to="/social-accounts">Connecter un réseau</Link></Button>} />
      ) : isLoading ? (
        <CardsSkeleton count={6} className="xl:grid-cols-3 2xl:grid-cols-6" />
      ) : isError || !data ? (
        <ErrorState onRetry={() => refetch()} />
      ) : (
        <div className={cn("transition-opacity", isFetching && "opacity-60")} aria-busy={isFetching}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <StatCard label="Impressions" value={formatCompact(data.kpis.impressions.value)} change={data.kpis.impressions.change} icon={Eye} accent="brand" />
            <StatCard label="Portée" value={formatCompact(data.kpis.reach.value)} change={data.kpis.reach.change} icon={Radio} accent="info" />
            <StatCard label="Engagement" value={formatCompact(data.kpis.engagements.value)} change={data.kpis.engagements.change} icon={Heart} accent="violet" />
            <StatCard label="Vues" value={formatCompact(data.kpis.views.value)} change={data.kpis.views.change} icon={BarChart3} accent="warning" />
            <StatCard label="Clics sur le lien" value={formatCompact(data.kpis.clicks.value)} change={data.kpis.clicks.change} icon={MousePointerClick} accent="success" />
            <StatCard label="Followers" value={`+${formatCompact(data.kpis.followers.value)}`} change={data.kpis.followers.change} icon={UserPlus} accent="brand" />
          </div>

          <Tabs defaultValue="overview" className="mt-6">
            <TabsList variant="line" className="overflow-x-auto">
              <TabsTrigger value="overview">Vue d'ensemble</TabsTrigger>
              <TabsTrigger value="networks">Réseaux</TabsTrigger>
              <TabsTrigger value="content">Contenus</TabsTrigger>
              <TabsTrigger value="audience">Audience</TabsTrigger>
            </TabsList>

            <TabsContent value="overview" className="mt-6 space-y-6">
              <SectionCard title="Impressions">
                <div className="h-72 w-full" role="img" aria-label={`Impressions sur ${PERIOD_LABEL[period]} comparées à la période précédente`}>
                  <ComparisonChart data={data.series} />
                </div>
              </SectionCard>
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <SectionCard title="Engagement par jour">
                  <div className="h-64 w-full" role="img" aria-label="Engagement quotidien">
                    <EngagementBarChart data={data.series} />
                  </div>
                </SectionCard>
                <SectionCard title="Top contenus" action={<span className="text-xs text-ink-muted">{PERIOD_LABEL[period]}</span>}>
                  <TopPostsTable data={data} limit={3} />
                </SectionCard>
              </div>
            </TabsContent>

            <TabsContent value="networks" className="mt-6 space-y-6">
              <SectionCard title="Impressions par réseau">
                <div className="h-72 w-full" role="img" aria-label="Comparaison des impressions par réseau">
                  <PlatformBarChart data={data.platforms.map((p) => ({ name: PLATFORMS[p.platform].name, value: p.impressions }))} />
                </div>
              </SectionCard>
              <SectionCard title="Comparaison des plateformes" action={<span className="text-xs text-ink-muted">n/d = non fourni par la plateforme</span>}>
                <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Tableau de comparaison des plateformes">
                  <table className="w-full min-w-[720px] text-left text-sm">
                    <caption className="sr-only">Performances par plateforme</caption>
                    <thead>
                      <tr className="border-b border-border text-xs text-ink-muted">
                        {["Réseau", "Impressions", "Portée", "Engagement", "Taux", "Vues", "Clics", "Abonnés"].map((h) => (
                          <th key={h} scope="col" className="px-3 py-2 font-medium first:pl-0">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {data.platforms.map((p) => (
                        <tr key={p.platform} className="border-b border-border last:border-none">
                          <th scope="row" className="py-3 pr-3 text-left font-semibold text-ink">
                            <span className="flex items-center gap-2"><PlatformIcon platform={p.platform} size="sm" /> {PLATFORMS[p.platform].name}</span>
                          </th>
                          <td className="px-3 py-3">{formatCompact(p.impressions)}</td>
                          <td className={cn("px-3 py-3", p.reach === null && "text-ink-muted")}>{formatCompact(p.reach)}</td>
                          <td className="px-3 py-3">{formatCompact(p.engagements)}</td>
                          <td className="px-3 py-3 font-semibold text-success-700">{formatPercent(p.engagementRate)}</td>
                          <td className={cn("px-3 py-3", p.views === null && "text-ink-muted")}>{formatCompact(p.views)}</td>
                          <td className={cn("px-3 py-3", p.clicks === null && "text-ink-muted")}>{formatCompact(p.clicks)}</td>
                          <td className="px-3 py-3">
                            {formatCompact(p.followers)}{" "}
                            <span className={cn("text-xs", (p.followersChange ?? 0) >= 0 ? "text-success-700" : "text-danger-700")}>{formatChange(p.followersChange)}</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </SectionCard>
            </TabsContent>

            <TabsContent value="content" className="mt-6">
              <SectionCard title="Contenus les plus performants">
                <TopPostsTable data={data} />
              </SectionCard>
            </TabsContent>

            <TabsContent value="audience" className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
              <SectionCard title="Évolution des abonnés">
                <div className="h-72 w-full" role="img" aria-label="Évolution du nombre total d'abonnés">
                  <FollowersLineChart data={data.series} />
                </div>
              </SectionCard>
              <div className="space-y-6">
                <SectionCard title="Âge de l'audience" action={<Users className="size-4 text-ink-muted" aria-hidden="true" />}>
                  <ul className="space-y-3">
                    {data.audience.map((a) => (
                      <li key={a.label}>
                        <div className="mb-1 flex justify-between text-xs">
                          <span className="text-ink-soft">{a.label}</span>
                          <span className="font-semibold text-ink">{a.value} %</span>
                        </div>
                        <div className="h-2 rounded-full bg-muted">
                          <div className="h-2 rounded-full bg-brand-500" style={{ width: `${a.value}%` }} />
                        </div>
                      </li>
                    ))}
                  </ul>
                </SectionCard>
                <SectionCard title="Abonnés par réseau">
                  <ul className="space-y-2.5">
                    {data.platforms.map((p) => (
                      <li key={p.platform} className="flex items-center gap-3 text-sm">
                        <PlatformIcon platform={p.platform} size="sm" />
                        <span className="flex-1 text-ink-soft">{PLATFORMS[p.platform].name}</span>
                        <span className="font-semibold text-ink">{formatInt(p.followers)}</span>
                      </li>
                    ))}
                  </ul>
                </SectionCard>
              </div>
            </TabsContent>
          </Tabs>
        </div>
      )}
      {isLoading && <Skeleton className="mt-6 h-72 w-full rounded-2xl" />}
    </>
  )
}
