import {
  addDays,
  addMonths,
  addWeeks,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  startOfMonth,
  startOfWeek,
} from "date-fns"
import { fr } from "date-fns/locale"
import { CalendarPlus, ChevronLeft, ChevronRight, Plus } from "lucide-react"
import { useState } from "react"
import { useNavigate } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { DayView, ListView, MonthView, WeekView } from "@/components/content/calendar/CalendarViews"
import { postTitle, postsOn } from "@/components/content/postUtils"
import { PostDetailDialog } from "@/components/content/PostDetailDialog"
import { usePostActions } from "@/components/content/usePostActions"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { PageHeader } from "@/components/shared/PageHeader"
import { ErrorState, ListSkeleton } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { usePosts } from "@/hooks/queries/content"
import { useMediaMap } from "@/hooks/useMediaMap"
import { useMediaQuery } from "@/hooks/useMediaQuery"
import { useNow } from "@/hooks/useNow"
import { capitalize, formatTime } from "@/lib/format"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { POST_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { PlatformKey, Post, PostStatus } from "@/types"

type View = "month" | "week" | "day" | "list"

const LEGEND: PostStatus[] = ["published", "scheduled", "draft", "failed", "partially_published", "processing", "cancelled"]

export default function CalendarPage() {
  const navigate = useNavigate()
  const isDesktop = useMediaQuery("(min-width: 768px)")
  const [view, setView] = useState<View | null>(null)
  const currentView: View = view ?? (isDesktop ? "month" : "list")
  const [cursor, setCursor] = useState(() => new Date())
  const [selected, setSelected] = useState(() => new Date())
  const [platform, setPlatform] = useState<PlatformKey | "all">("all")
  const [status, setStatus] = useState<PostStatus | "all">("all")
  const [detail, setDetail] = useState<Post | null>(null)
  const now = useNow()
  const { actions, dialogs } = usePostActions()
  const mediaMap = useMediaMap()

  const { data, isLoading, isError, refetch } = usePosts({ platform, status })
  const posts = (data ?? []).filter((p) => p.scheduledAt)

  const move = (dir: 1 | -1) => {
    const next = currentView === "month" || currentView === "list" ? addMonths(cursor, dir) : currentView === "week" ? addWeeks(cursor, dir) : addDays(cursor, dir)
    setCursor(next)
    if (currentView === "day") setSelected(next)
  }
  const goToday = () => {
    setCursor(new Date())
    setSelected(new Date())
  }

  const label =
    currentView === "month" || currentView === "list"
      ? capitalize(format(cursor, "MMMM yyyy", { locale: fr }))
      : currentView === "week"
        ? `${format(startOfWeek(cursor, { weekStartsOn: 1 }), "d MMM", { locale: fr })} – ${format(endOfWeek(cursor, { weekStartsOn: 1 }), "d MMM yyyy", { locale: fr })}`
        : capitalize(format(cursor, "EEEE d MMMM yyyy", { locale: fr }))

  const createAt = (d: Date) => {
    const at = new Date(d)
    if (at.getHours() === 0) at.setHours(9, 0, 0, 0)
    navigate("/content/create", { state: { scheduledAt: at.toISOString() } })
  }

  const monthPosts = posts.filter((p) => {
    const t = new Date(p.scheduledAt!).getTime()
    return t >= startOfMonth(cursor).getTime() && t <= endOfMonth(cursor).getTime()
  })
  const selectedPosts = postsOn(posts, selected)

  return (
    <>
      <PageHeader
        title="Calendrier éditorial"
        description="Planifiez et organisez vos publications."
        actions={
          <Button onClick={() => navigate("/content/create")}>
            <Plus /> Nouvelle publication
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div className="surface-card overflow-hidden">
          {/* Barre d'outils */}
          <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-2">
              <Button variant="outline" size="icon-sm" onClick={() => move(-1)} aria-label="Période précédente">
                <ChevronLeft />
              </Button>
              <p className="min-w-0 flex-1 rounded-lg border sm:min-w-44 sm:flex-none border-border px-3 py-1.5 text-center text-sm font-semibold text-ink" aria-live="polite">
                {label}
              </p>
              <Button variant="outline" size="icon-sm" onClick={() => move(1)} aria-label="Période suivante">
                <ChevronRight />
              </Button>
              <Button variant="ghost" size="sm" onClick={goToday}>
                Aujourd'hui
              </Button>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <FilterTabs
                variant="pill"
                label="Vue du calendrier"
                value={currentView}
                onChange={(v) => {
                  if (v === "day") setCursor(selected)
                  setView(v)
                }}
                items={[
                  { value: "month", label: "Mois" },
                  { value: "week", label: "Semaine" },
                  { value: "day", label: "Jour" },
                  { value: "list", label: "Liste" },
                ]}
              />
              <Select value={platform} onValueChange={(v) => setPlatform(v as PlatformKey | "all")}>
                <SelectTrigger size="sm" className="w-40" aria-label="Filtrer par réseau">
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
              <Select value={status} onValueChange={(v) => setStatus(v as PostStatus | "all")}>
                <SelectTrigger size="sm" className="w-40" aria-label="Filtrer par statut">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Tous les statuts</SelectItem>
                  {LEGEND.map((s) => (
                    <SelectItem key={s} value={s}>{POST_STATUS[s].label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {isLoading ? (
            <div className="p-5"><ListSkeleton rows={6} /></div>
          ) : isError ? (
            <div className="p-5"><ErrorState onRetry={() => refetch()} /></div>
          ) : currentView === "month" ? (
            <MonthView cursor={cursor} posts={posts} selected={selected} onSelect={setSelected} onOpen={setDetail} onCreate={createAt} />
          ) : currentView === "week" ? (
            <WeekView cursor={cursor} posts={posts} onOpen={setDetail} onCreate={createAt} />
          ) : currentView === "day" ? (
            <DayView cursor={cursor} posts={posts} onOpen={setDetail} onCreate={createAt} />
          ) : (
            <ListView posts={monthPosts} onOpen={setDetail} onCreate={() => navigate("/content/create")} />
          )}
        </div>

        <aside className="space-y-6">
          <section className="surface-card p-5" aria-labelledby="day-title">
            <h2 id="day-title" className="text-[15px] font-semibold">
              {isSameDay(selected, now) ? "Publications du jour" : capitalize(format(selected, "EEEE d MMMM", { locale: fr }))}
            </h2>
            {selectedPosts.length === 0 ? (
              <div className="mt-4 flex flex-col items-center gap-3 rounded-xl bg-page p-5 text-center">
                <CalendarPlus className="size-6 text-brand-500" aria-hidden="true" />
                <p className="text-sm text-ink-muted">Rien de prévu ce jour-là.</p>
                <Button size="sm" variant="secondary" onClick={() => createAt(selected)}>
                  <Plus /> Programmer ce jour
                </Button>
              </div>
            ) : (
              <ul className="mt-4 space-y-3">
                {selectedPosts.map((p) => (
                  <li key={p.id}>
                    <button type="button" onClick={() => setDetail(p)} className="-mx-2 flex w-full items-start gap-3 rounded-lg px-2 py-1.5 text-left hover:bg-muted/60">
                      <span className="w-11 shrink-0 pt-1 text-xs font-medium text-ink-muted tabular-nums">{formatTime(p.scheduledAt!)}</span>
                      <PlatformIcon platform={p.variants[0]?.platform ?? "instagram"} size="md" />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold text-ink">{PLATFORMS[p.variants[0]?.platform ?? "instagram"].name}</span>
                        <span className="block truncate text-xs text-ink-muted">{postTitle(p)}</span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="surface-card p-5" aria-labelledby="legend-title">
            <h2 id="legend-title" className="text-[15px] font-semibold">Légende</h2>
            <ul className="mt-3 grid grid-cols-2 gap-2.5 xl:grid-cols-1">
              {LEGEND.map((s) => (
                <li key={s} className="flex items-center gap-2.5 text-sm text-ink-soft">
                  <span className={cn("size-2.5 rounded-full", POST_STATUS[s].dot)} aria-hidden="true" />
                  {POST_STATUS[s].label}
                </li>
              ))}
            </ul>
          </section>
        </aside>
      </div>

      <PostDetailDialog post={detail} onOpenChange={(o) => !o && setDetail(null)} actions={actions} mediaMap={mediaMap} />
      {dialogs}
    </>
  )
}
