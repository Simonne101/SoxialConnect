import { BarChart3, ExternalLink, RefreshCw } from "lucide-react"
import { useMemo, useState } from "react"
import { Link } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { CardsSkeleton, EmptyState, ErrorState } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useLiveAnalytics } from "@/hooks/queries/misc"
import { useSocialAccounts } from "@/hooks/queries/social"
import {
  LIVE_PERIOD_LABEL,
  METRIC_LABELS,
  type LivePeriod,
  type NetworkStatusKind,
  formatMetric,
  latestPoints,
  metricColumns,
  networkStatus,
  periodRange,
} from "@/lib/analyticsView"
import { formatDate } from "@/lib/format"
import { apiErrorMessage } from "@/lib/http"
import { PLATFORMS } from "@/lib/platforms"
import type { LiveAnalyticsPoint, PlatformKey, SocialAccount } from "@/types"

const STATUS_BADGE: Record<NetworkStatusKind, "success" | "neutral" | "warning" | "danger" | "info"> = {
  available: "success",
  pending: "info",
  permission: "warning",
  reconnect: "danger",
  error: "danger",
  unavailable: "neutral",
}

/**
 * Page Analytics en mode API (P4 — ADR-068) : uniquement les statistiques réellement collectées
 * (GET /analytics). Les relevés sont des cumuls datés : aucune courbe, variation ni pourcentage n'est
 * calculé ; une valeur non fournie reste « Non disponible ».
 */
export function LiveAnalytics() {
  const [period, setPeriod] = useState<LivePeriod>("30d")
  const [platform, setPlatform] = useState<PlatformKey | "all">("all")
  const range = useMemo(() => periodRange(period), [period])
  const query = useLiveAnalytics({ ...range, ...(platform === "all" ? {} : { platform }) })
  const accounts = useSocialAccounts()
  const data = query.data

  const accountById = useMemo(() => new Map((accounts.data ?? []).map((a) => [a.id, a])), [accounts.data])
  // Réseaux proposés au filtre : ceux des comptes de l'utilisateur (le serveur accepte tout réseau connu).
  const networks = useMemo(() => [...new Set((accounts.data ?? []).map((a) => a.platform))], [accounts.data])

  const header = (
    <PageHeader
      title="Analyses"
      description="Statistiques réellement collectées auprès de vos réseaux."
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Select value={period} onValueChange={(v) => setPeriod(v as LivePeriod)}>
            <SelectTrigger className="w-auto min-w-44" aria-label="Période des collectes"><SelectValue /></SelectTrigger>
            <SelectContent>
              {(Object.keys(LIVE_PERIOD_LABEL) as LivePeriod[]).map((p) => <SelectItem key={p} value={p}>{LIVE_PERIOD_LABEL[p]}</SelectItem>)}
            </SelectContent>
          </Select>
          <Select value={platform} onValueChange={(v) => setPlatform(v as PlatformKey | "all")}>
            <SelectTrigger className="w-44" aria-label="Réseau"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Tous les réseaux</SelectItem>
              {networks.map((p) => <SelectItem key={p} value={p}>{PLATFORMS[p].name}</SelectItem>)}
            </SelectContent>
          </Select>
          <Button variant="outline" size="icon" aria-label="Actualiser les statistiques" disabled={query.isFetching} onClick={() => void query.refetch()}>
            <RefreshCw className={query.isFetching ? "animate-spin" : undefined} />
          </Button>
        </div>
      }
    />
  )

  if (query.isLoading || accounts.isLoading) {
    return <>{header}<CardsSkeleton count={3} /></>
  }
  if (query.isError || !data) {
    return <>{header}<ErrorState title="Statistiques indisponibles" description={apiErrorMessage(query.error, "Les statistiques n'ont pas pu être chargées.")} onRetry={() => void query.refetch()} /></>
  }

  const accountPoints = latestPoints(data.points, "account")
  const postPoints = latestPoints(data.points, "post")
  const postById = new Map(data.posts.map((p) => [p.postVariantId, p]))
  const postColumns = metricColumns(postPoints, ["followers"])

  return (
    <>
      {header}
      <div className="space-y-6">
        <p className="text-xs text-ink-muted">
          Valeurs cumulées relevées à la date indiquée ({formatDate(data.meta.from, "d MMM yyyy")} – {formatDate(data.meta.to, "d MMM yyyy")}). « {formatMetric(null)} » : le réseau ne fournit pas cette valeur.
          {query.dataUpdatedAt > 0 && ` Affichage actualisé à ${formatDate(new Date(query.dataUpdatedAt).toISOString(), "HH:mm")}.`}
        </p>

        <SectionCard title="État de la collecte par compte">
          {data.sync.length === 0 ? (
            <EmptyState icon={BarChart3} title="Aucun compte connecté" description="Connectez un réseau social pour suivre ses statistiques." action={<Button asChild><Link to="/social-accounts">Connecter un réseau</Link></Button>} />
          ) : (
            <ul className="divide-y divide-border">
              {data.sync.map((s) => {
                const status = networkStatus(s, data.points.some((p) => p.socialAccountId === s.socialAccountId))
                const account = accountById.get(s.socialAccountId)
                return (
                  <li key={s.socialAccountId} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:gap-4">
                    <AccountLabel platform={s.platform} account={account} />
                    <div className="min-w-0 flex-1 text-sm">
                      <p className="text-ink-soft">{status.description}</p>
                      {s.lastSuccessAt && <p className="mt-0.5 text-xs text-ink-muted">Dernière collecte réussie : {formatDate(s.lastSuccessAt, "d MMM yyyy 'à' HH:mm")}</p>}
                      {status.kind === "error" && s.nextAttemptAt && <p className="mt-0.5 text-xs text-ink-muted">Nouvel essai prévu : {formatDate(s.nextAttemptAt, "d MMM yyyy 'à' HH:mm")}</p>}
                    </div>
                    <Badge variant={STATUS_BADGE[status.kind]} className="self-start">{status.label}</Badge>
                  </li>
                )
              })}
            </ul>
          )}
        </SectionCard>

        {data.points.length === 0 ? (
          <EmptyState icon={BarChart3} title="Aucune statistique sur cette période" description="Aucun relevé n'a été enregistré pour ces filtres. Les statistiques apparaissent après une collecte réussie (voir l'état de chaque compte ci-dessus)." />
        ) : (
          <>
            {accountPoints.length > 0 && (
              <SectionCard title="Comptes">
                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {accountPoints.map((point) => (
                    <li key={point.socialAccountId} className="rounded-xl border border-border p-4">
                      <AccountLabel platform={point.platform} account={accountById.get(point.socialAccountId)} />
                      <MetricList point={point} />
                      <p className="mt-2 text-xs text-ink-muted">Relevé du {formatDate(point.date, "d MMM yyyy")}</p>
                    </li>
                  ))}
                </ul>
              </SectionCard>
            )}

            {postPoints.length > 0 && (
              <SectionCard title="Publications">
                <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Statistiques des publications">
                  <table className="w-full min-w-[560px] text-left text-sm">
                    <caption className="sr-only">Dernier relevé de chaque publication</caption>
                    <thead>
                      <tr className="border-b border-border text-xs text-ink-muted">
                        <th scope="col" className="py-2 pr-3 font-medium">Publication</th>
                        {postColumns.map((key) => <th key={key} scope="col" className="px-3 py-2 text-right font-medium">{METRIC_LABELS[key]}</th>)}
                        <th scope="col" className="py-2 pl-3 text-right font-medium">Relevé</th>
                      </tr>
                    </thead>
                    <tbody>
                      {postPoints.map((point) => {
                        const post = point.postVariantId ? postById.get(point.postVariantId) : undefined
                        return (
                          <tr key={point.postVariantId} className="border-b border-border last:border-none">
                            <td className="py-3 pr-3">
                              <div className="flex items-center gap-2">
                                {point.platform && <PlatformIcon platform={point.platform} size="sm" />}
                                <span className="min-w-0">
                                  <span className="block truncate font-semibold text-ink">{post?.title ?? "Publication sans titre"}</span>
                                  <span className="flex items-center gap-1.5 text-xs text-ink-muted">
                                    {post?.publishedAt ? `Publiée le ${formatDate(post.publishedAt, "d MMM yyyy")}` : "Date de publication inconnue"}
                                    {post?.externalUrl && (
                                      <a href={post.externalUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-brand-600 hover:underline">
                                        Voir <ExternalLink className="size-3" aria-hidden="true" /><span className="sr-only"> (nouvel onglet)</span>
                                      </a>
                                    )}
                                  </span>
                                </span>
                              </div>
                            </td>
                            {postColumns.map((key) => (
                              <td key={key} className={point.metrics[key] === null ? "px-3 py-3 text-right text-xs text-ink-muted" : "px-3 py-3 text-right font-medium text-ink tabular-nums"}>
                                {formatMetric(point.metrics[key])}
                              </td>
                            ))}
                            <td className="py-3 pl-3 text-right text-xs text-ink-muted">{formatDate(point.date, "d MMM")}</td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              </SectionCard>
            )}
            {data.meta.truncated && <p className="text-xs text-warning-700">Affichage limité aux premiers relevés : réduisez la période ou choisissez un réseau.</p>}
          </>
        )}
      </div>
    </>
  )
}

function AccountLabel({ platform, account }: { platform: PlatformKey | null; account: SocialAccount | undefined }) {
  return (
    <div className="flex min-w-0 items-center gap-2 sm:w-56 sm:shrink-0">
      {platform && <PlatformIcon platform={platform} size="sm" />}
      <span className="min-w-0">
        <span className="block truncate text-sm font-semibold text-ink">{platform ? PLATFORMS[platform].name : "Réseau inconnu"}</span>
        {(account?.displayName || account?.username) && <span className="block truncate text-xs text-ink-muted">{account.displayName ?? account.username}</span>}
      </span>
    </div>
  )
}

/** Métriques d'un compte : uniquement celles que le réseau fournit (au moins une valeur), null → « Non disponible ». */
function MetricList({ point }: { point: LiveAnalyticsPoint }) {
  const keys = metricColumns([point])
  if (keys.length === 0) return <p className="mt-3 text-sm text-ink-muted">Aucune métrique fournie par le réseau pour ce relevé.</p>
  return (
    <dl className="mt-3 grid grid-cols-2 gap-2">
      {keys.map((key) => (
        <div key={key}>
          <dt className="text-xs text-ink-muted">{METRIC_LABELS[key]}</dt>
          <dd className="text-lg font-bold text-ink tabular-nums">{formatMetric(point.metrics[key])}</dd>
        </div>
      ))}
    </dl>
  )
}
