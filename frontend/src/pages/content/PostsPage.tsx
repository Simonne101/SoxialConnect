import { CalendarDays, FileText, FilePen, Plus, Search, SearchX } from "lucide-react"
import { useDeferredValue, useState } from "react"
import { Link, useSearchParams } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PostActionsMenu } from "@/components/content/PostActionsMenu"
import { PostPlatforms, PostStatusBadge } from "@/components/content/PostBits"
import { postTitle } from "@/components/content/postUtils"
import { PostDetailDialog } from "@/components/content/PostDetailDialog"
import { usePostActions } from "@/components/content/usePostActions"
import { MediaThumb } from "@/components/media/MediaThumb"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { Pagination } from "@/components/shared/Pagination"
import { PageHeader } from "@/components/shared/PageHeader"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { usePosts } from "@/hooks/queries/content"
import { useMediaMap } from "@/hooks/useMediaMap"
import { formatCompact, formatDate } from "@/lib/format"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import type { PlatformKey, Post, PostStatus } from "@/types"

const PER_PAGE = 20

const TABS: { value: string; label: string; statuses: PostStatus[] | null }[] = [
  { value: "all", label: "Toutes", statuses: null },
  { value: "scheduled", label: "Programmées", statuses: ["scheduled", "processing"] },
  { value: "published", label: "Publiées", statuses: ["published", "partially_published"] },
  { value: "draft", label: "Brouillons", statuses: ["draft"] },
  { value: "failed", label: "En erreur", statuses: ["failed", "partially_published"] },
]

export default function PostsPage() {
  const [params, setParams] = useSearchParams()
  const tabParam = params.get("status") ?? "all"
  const tab = TABS.some((t) => t.value === tabParam) ? tabParam : tabParam === "partially_published" ? "failed" : "all"
  const [search, setSearch] = useState("")
  const deferredSearch = useDeferredValue(search)
  const [platform, setPlatform] = useState<PlatformKey | "all">("all")
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<Post | null>(null)
  const { actions, dialogs } = usePostActions()
  const mediaMap = useMediaMap()

  const current = TABS.find((t) => t.value === tab)!
  // Filtres, recherche et pagination côté serveur ; les compteurs d'onglets viennent de meta.status_counts.
  const { data, isLoading, isFetching, isError, refetch } = usePosts({
    search: deferredSearch,
    platform,
    status: current.statuses ?? "all",
    page,
    perPage: PER_PAGE,
  })

  // Page vidée (suppression de sa dernière publication) → dernière page existante.
  // (ajustement pendant le rendu, sans effet : https://react.dev/learn/you-might-not-need-an-effect)
  if (data && page > data.lastPage) setPage(data.lastPage)

  const counts: Record<string, number> = {}
  for (const t of TABS) {
    const statuses = t.statuses ?? (Object.keys(data?.statusCounts ?? {}) as PostStatus[])
    counts[t.value] = statuses.reduce((sum, s) => sum + (data?.statusCounts[s] ?? 0), 0)
  }

  const posts = data?.items ?? []
  const filtering = Boolean(deferredSearch) || platform !== "all"

  return (
    <>
      <PageHeader
        title="Publications"
        description="Retrouvez, modifiez et suivez toutes vos publications."
        actions={
          <>
            <Button variant="outline" asChild>
              <Link to="/content/calendar">
                <CalendarDays /> Calendrier
              </Link>
            </Button>
            <Button asChild>
              <Link to="/content/create">
                <Plus /> Créer une publication
              </Link>
            </Button>
          </>
        }
      />

      <div className="surface-card">
        <div className="border-b border-border px-5 pt-2">
          <FilterTabs
            label="Filtrer par statut"
            value={tab}
            onChange={(v) => {
              setPage(1)
              setParams(v === "all" ? {} : { status: v }, { replace: true })
            }}
            items={TABS.map((t) => ({ value: t.value, label: t.label, badge: <span className="rounded-full bg-muted px-1.5 text-[11px] font-semibold text-ink-muted">{counts[t.value] ?? 0}</span> }))}
          />
        </div>

        <div className="flex flex-col gap-3 p-5 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <Input value={search} onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }} placeholder="Rechercher une publication…" className="pl-10" aria-label="Rechercher une publication" />
          </div>
          <Select value={platform} onValueChange={(v) => {
            setPlatform(v as PlatformKey | "all")
            setPage(1)
          }}>
            <SelectTrigger className="w-full sm:w-52" aria-label="Filtrer par réseau">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Tous les réseaux</SelectItem>
              {PLATFORM_ORDER.map((p) => (
                <SelectItem key={p} value={p}>
                  <PlatformIcon platform={p} size="xs" /> {PLATFORMS[p].name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="px-5 pb-5">
          {isLoading ? (
            <ListSkeleton rows={6} />
          ) : isError ? (
            <ErrorState onRetry={() => refetch()} />
          ) : posts.length === 0 ? (
            filtering ? (
              <EmptyState icon={SearchX} title="Aucun résultat" description="Aucune publication ne correspond à votre recherche. Essayez un autre mot ou un autre réseau." action={<Button variant="outline" onClick={() => { setSearch(""); setPlatform("all"); setPage(1) }}>Effacer les filtres</Button>} />
            ) : (
              <EmptyState icon={tab === "draft" ? FilePen : FileText} title="Aucune publication ici" description="Créez votre première publication : vous pourrez la publier tout de suite ou la programmer." action={<Button asChild><Link to="/content/create"><Plus /> Créer une publication</Link></Button>} />
            )
          ) : (
            <>
              {/* Tableau — tablette et desktop */}
              <table className="hidden w-full text-left text-sm md:table">
                <caption className="sr-only">Liste des publications</caption>
                <thead>
                  <tr className="border-b border-border text-xs text-ink-muted">
                    <th scope="col" className="py-3 pr-4 font-medium">Publication</th>
                    <th scope="col" className="px-4 py-3 font-medium">Réseaux</th>
                    <th scope="col" className="px-4 py-3 font-medium">Statut</th>
                    <th scope="col" className="px-4 py-3 font-medium">Date</th>
                    <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">Impressions</th>
                    <th scope="col" className="py-3 pl-4"><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody>
                  {posts.map((p) => (
                    <tr key={p.id} className="border-b border-border last:border-none hover:bg-muted/40">
                      <td className="max-w-0 py-3 pr-4">
                        <button type="button" onClick={() => setDetail(p)} className="flex w-full items-center gap-3 rounded-lg text-left">
                          <MediaThumb media={p.mediaIds[0] ? mediaMap[p.mediaIds[0]] : undefined} className="size-11 shrink-0" showMeta={false} />
                          <span className="min-w-0">
                            <span className="block truncate font-semibold text-ink hover:text-brand-600">{postTitle(p)}</span>
                            <span className="block truncate text-xs text-ink-muted">{p.body}</span>
                          </span>
                        </button>
                      </td>
                      <td className="px-4 py-3"><PostPlatforms post={p} /></td>
                      <td className="px-4 py-3"><PostStatusBadge post={p} /></td>
                      <td className="px-4 py-3 whitespace-nowrap text-ink-soft">{p.scheduledAt ? formatDate(p.scheduledAt, "d MMM · HH:mm") : "—"}</td>
                      <td className="hidden px-4 py-3 text-ink-soft lg:table-cell">{p.metrics ? formatCompact(p.metrics.impressions) : "—"}</td>
                      <td className="py-3 pl-4 text-right"><PostActionsMenu post={p} actions={actions} onOpen={setDetail} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* Cartes — mobile */}
              <ul className="space-y-3 md:hidden">
                {posts.map((p) => (
                  <li key={p.id} className="rounded-xl border border-border p-3">
                    <div className="flex items-start gap-3">
                      <button type="button" onClick={() => setDetail(p)} className="flex min-w-0 flex-1 items-start gap-3 text-left">
                        <MediaThumb media={p.mediaIds[0] ? mediaMap[p.mediaIds[0]] : undefined} className="size-14 shrink-0" showMeta={false} />
                        <span className="min-w-0">
                          <span className="block truncate text-sm font-semibold text-ink">{postTitle(p)}</span>
                          <span className="mt-0.5 block text-xs text-ink-muted">{p.scheduledAt ? formatDate(p.scheduledAt, "d MMM · HH:mm") : "Brouillon"}</span>
                        </span>
                      </button>
                      <PostActionsMenu post={p} actions={actions} onOpen={setDetail} />
                    </div>
                    <div className="mt-3 flex items-center justify-between">
                      <PostPlatforms post={p} size="xs" />
                      <PostStatusBadge post={p} />
                    </div>
                  </li>
                ))}
              </ul>

              {data && <Pagination page={data.page} lastPage={data.lastPage} total={data.total} onChange={setPage} busy={isFetching} label="Pages de publications" />}
            </>
          )}
        </div>
      </div>

      <PostDetailDialog post={detail} onOpenChange={(o) => !o && setDetail(null)} actions={actions} mediaMap={mediaMap} />
      {dialogs}
    </>
  )
}
