import {
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  isToday,
  startOfMonth,
  startOfWeek,
} from "date-fns"
import { fr } from "date-fns/locale"
import { CalendarPlus, Plus } from "lucide-react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { EmptyState } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import { capitalize, formatTime } from "@/lib/format"
import { POST_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { Post } from "@/types"
import { postTitle, postsOn } from "../postUtils"

const WEEKDAYS = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"]

function EventChip({ post, onOpen, compact = false }: { post: Post; onOpen: (p: Post) => void; compact?: boolean }) {
  const meta = POST_STATUS[post.status]
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onOpen(post)
      }}
      className="group flex w-full items-center gap-1.5 rounded-md bg-card px-1.5 py-1 text-left text-[11px] shadow-sm ring-1 ring-border transition-colors hover:ring-brand-300"
      aria-label={`${postTitle(post)} — ${formatTime(post.scheduledAt!)} — ${meta.label}`}
    >
      <span className={cn("h-3.5 w-0.5 shrink-0 rounded-full", meta.dot)} aria-hidden="true" />
      <PlatformIcon platform={post.variants[0]?.platform ?? "instagram"} size="xs" className="size-4 [&_svg]:size-2" />
      {!compact && <span className="shrink-0 font-semibold text-ink-soft tabular-nums">{formatTime(post.scheduledAt!)}</span>}
      <span className="truncate text-ink">{postTitle(post)}</span>
    </button>
  )
}

export function MonthView({
  cursor,
  posts,
  selected,
  onSelect,
  onOpen,
  onCreate,
}: {
  cursor: Date
  posts: Post[]
  selected: Date
  onSelect: (d: Date) => void
  onOpen: (p: Post) => void
  onCreate: (d: Date) => void
}) {
  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(cursor), { weekStartsOn: 1 }),
    end: endOfWeek(endOfMonth(cursor), { weekStartsOn: 1 }),
  })
  return (
    <div aria-label={capitalize(format(cursor, "MMMM yyyy", { locale: fr }))} role="group">
      <div className="grid grid-cols-7 border-b border-border" aria-hidden="true">
        {WEEKDAYS.map((d) => (
          <div key={d} className="py-2 text-center text-xs font-semibold text-ink-muted">
            {d}
          </div>
        ))}
      </div>
      <div className="grid grid-cols-7">
        {days.map((day) => {
          const dayPosts = postsOn(posts, day)
          const inMonth = isSameMonth(day, cursor)
          const isSel = isSameDay(day, selected)
          return (
            <div
              key={day.toISOString()}
              className={cn(
                "group relative min-h-[72px] border-r border-b border-border p-1 transition-colors last:border-r-0 sm:min-h-[112px] sm:p-1.5 [&:nth-child(7n)]:border-r-0",
                !inMonth && "bg-page/60",
                isSel && "bg-brand-50/70",
              )}
            >
              <div className="flex items-center justify-between">
                <button
                  type="button"
                  onClick={() => onSelect(day)}
                  className={cn(
                    "inline-flex size-7 items-center justify-center rounded-full text-xs font-semibold",
                    isToday(day) ? "bg-brand-500 text-white" : isSel ? "text-brand-700" : inMonth ? "text-ink" : "text-ink-muted",
                  )}
                  aria-label={`${format(day, "EEEE d MMMM", { locale: fr })}${dayPosts.length ? `, ${dayPosts.length} publication(s)` : ""}`}
                >
                  {format(day, "d")}
                </button>
                <button
                  type="button"
                  onClick={() => onCreate(day)}
                  className="hidden size-6 items-center justify-center rounded-md text-ink-muted opacity-0 group-hover:opacity-100 hover:bg-brand-100 hover:text-brand-600 focus:opacity-100 sm:inline-flex"
                  aria-label={`Créer une publication le ${format(day, "d MMMM", { locale: fr })}`}
                >
                  <Plus className="size-3.5" />
                </button>
              </div>
              {/* Mobile : points ; ≥ sm : puces */}
              <div className="mt-1 flex flex-wrap gap-1 sm:hidden" aria-hidden="true">
                {dayPosts.slice(0, 4).map((p) => (
                  <span key={p.id} className={cn("size-1.5 rounded-full", POST_STATUS[p.status].dot)} />
                ))}
              </div>
              <div className="mt-1 hidden space-y-1 sm:block">
                {dayPosts.slice(0, 3).map((p) => (
                  <EventChip key={p.id} post={p} onOpen={onOpen} compact />
                ))}
                {dayPosts.length > 3 && (
                  <button type="button" onClick={() => onSelect(day)} className="px-1 text-[11px] font-semibold text-brand-600 hover:underline">
                    +{dayPosts.length - 3} autre(s)
                  </button>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function WeekView({ cursor, posts, onOpen, onCreate }: { cursor: Date; posts: Post[]; onOpen: (p: Post) => void; onCreate: (d: Date) => void }) {
  const days = eachDayOfInterval({ start: startOfWeek(cursor, { weekStartsOn: 1 }), end: endOfWeek(cursor, { weekStartsOn: 1 }) })
  return (
    <div className="overflow-x-auto">
      <div className="grid min-w-[760px] grid-cols-7 gap-2 p-3">
        {days.map((day) => {
          const dayPosts = postsOn(posts, day)
          return (
            <section key={day.toISOString()} aria-label={format(day, "EEEE d MMMM", { locale: fr })} className={cn("flex min-h-[340px] flex-col rounded-xl p-2", isToday(day) ? "bg-brand-50/70 ring-1 ring-brand-200" : "bg-page/70")}>
              <header className="mb-2 flex items-center justify-between">
                <span className="text-xs font-semibold text-ink-muted">{capitalize(format(day, "EEE", { locale: fr }))}</span>
                <span className={cn("inline-flex size-7 items-center justify-center rounded-full text-sm font-bold", isToday(day) ? "bg-brand-500 text-white" : "text-ink")}>{format(day, "d")}</span>
              </header>
              <div className="flex-1 space-y-1.5">
                {dayPosts.map((p) => (
                  <EventChip key={p.id} post={p} onOpen={onOpen} />
                ))}
              </div>
              <button type="button" onClick={() => onCreate(day)} className="mt-2 inline-flex items-center justify-center gap-1 rounded-md py-1.5 text-xs font-medium text-ink-muted hover:bg-card hover:text-brand-600" aria-label={`Ajouter une publication le ${format(day, "d MMMM", { locale: fr })}`}>
                <Plus className="size-3.5" /> Ajouter
              </button>
            </section>
          )
        })}
      </div>
    </div>
  )
}

export function DayView({ cursor, posts, onOpen, onCreate }: { cursor: Date; posts: Post[]; onOpen: (p: Post) => void; onCreate: (d: Date) => void }) {
  const dayPosts = postsOn(posts, cursor)
  const hours = Array.from({ length: 17 }, (_, i) => i + 7)
  return (
    <ol className="divide-y divide-border" aria-label={`Programme du ${format(cursor, "EEEE d MMMM", { locale: fr })}`}>
      {hours.map((h) => {
        const slot = dayPosts.filter((p) => new Date(p.scheduledAt!).getHours() === h)
        return (
          <li key={h} className="group flex min-h-14 gap-3 px-3 py-2">
            <span className="w-12 shrink-0 pt-1 text-xs font-medium text-ink-muted tabular-nums">{String(h).padStart(2, "0")}:00</span>
            <div className="flex-1 space-y-1.5">
              {slot.map((p) => (
                <button key={p.id} type="button" onClick={() => onOpen(p)} className="flex w-full items-center gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-left hover:border-brand-200">
                  <PlatformIcon platform={p.variants[0]?.platform ?? "instagram"} size="sm" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-ink">{postTitle(p)}</span>
                    <span className="block text-xs text-ink-muted">{formatTime(p.scheduledAt!)} · {p.variants.length} réseau(x)</span>
                  </span>
                  <StatusBadge meta={POST_STATUS[p.status]} />
                </button>
              ))}
              {slot.length === 0 && (
                <button
                  type="button"
                  onClick={() => {
                    const d = new Date(cursor)
                    d.setHours(h, 0, 0, 0)
                    onCreate(d)
                  }}
                  className="h-10 w-full rounded-lg text-left text-xs text-transparent group-hover:bg-page group-hover:text-ink-muted focus:bg-page focus:text-ink-muted"
                  aria-label={`Programmer à ${h}:00`}
                >
                  <span className="px-3">+ Programmer à {h}:00</span>
                </button>
              )}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

export function ListView({ posts, onOpen, onCreate }: { posts: Post[]; onOpen: (p: Post) => void; onCreate: () => void }) {
  const dated = posts.filter((p) => p.scheduledAt).sort((a, b) => new Date(a.scheduledAt!).getTime() - new Date(b.scheduledAt!).getTime())
  if (dated.length === 0) {
    return (
      <div className="p-5">
        <EmptyState icon={CalendarPlus} title="Aucune publication sur cette période" description="Programmez une publication pour la voir apparaître dans votre calendrier." action={<Button onClick={onCreate}><Plus /> Nouvelle publication</Button>} />
      </div>
    )
  }
  const groups = new Map<string, Post[]>()
  for (const p of dated) {
    const key = format(new Date(p.scheduledAt!), "yyyy-MM-dd")
    groups.set(key, [...(groups.get(key) ?? []), p])
  }
  return (
    <div className="divide-y divide-border">
      {[...groups.entries()].map(([key, items]) => {
        const day = new Date(`${key}T12:00:00`)
        return (
          <section key={key} className="p-4" aria-label={format(day, "EEEE d MMMM", { locale: fr })}>
            <h3 className={cn("mb-2 text-sm font-semibold", isToday(day) ? "text-brand-600" : "text-ink")}>
              {isToday(day) ? "Aujourd'hui · " : ""}
              {capitalize(format(day, "EEEE d MMMM", { locale: fr }))}
            </h3>
            <ul className="space-y-2">
              {items.map((p) => (
                <li key={p.id}>
                  <button type="button" onClick={() => onOpen(p)} className="flex w-full items-center gap-3 rounded-xl border border-border px-3 py-2.5 text-left hover:border-brand-200">
                    <span className="w-12 shrink-0 text-sm font-semibold text-ink-soft tabular-nums">{formatTime(p.scheduledAt!)}</span>
                    <span className="flex -space-x-1.5">
                      {p.variants.slice(0, 3).map((v) => (
                        <PlatformIcon key={v.id} platform={v.platform} size="sm" className="ring-2 ring-card" />
                      ))}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">{postTitle(p)}</span>
                    <StatusBadge meta={POST_STATUS[p.status]} className="hidden sm:inline-flex" />
                  </button>
                </li>
              ))}
            </ul>
          </section>
        )
      })}
    </div>
  )
}
