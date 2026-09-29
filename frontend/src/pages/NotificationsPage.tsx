import { BellOff, CheckCheck, MoreHorizontal, Trash2 } from "lucide-react"
import { useState } from "react"
import { Link } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { SEVERITY } from "@/components/notifications/severity"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { PageHeader } from "@/components/shared/PageHeader"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useDeleteNotification, useMarkNotificationsRead, useNotifications } from "@/hooks/queries/misc"
import { formatRelative } from "@/lib/format"
import { cn } from "@/lib/utils"

type Filter = "all" | "unread" | "read"

export default function NotificationsPage() {
  const [filter, setFilter] = useState<Filter>("all")
  const { data, isLoading, isError, refetch } = useNotifications()
  const markRead = useMarkNotificationsRead()
  const remove = useDeleteNotification()
  const all = data ?? []
  const unreadCount = all.filter((n) => !n.readAt).length
  const items = all.filter((n) => (filter === "unread" ? !n.readAt : filter === "read" ? Boolean(n.readAt) : true))

  return (
    <>
      <PageHeader
        title="Notifications"
        showBell={false}
        description="Suivez ce qui se passe sur vos publications et vos comptes."
        actions={
          <Button variant="outline" disabled={unreadCount === 0 || markRead.isPending} onClick={() => markRead.mutate("all")}>
            <CheckCheck /> Tout marquer comme lu
          </Button>
        }
      />

      <div className="surface-card">
        <div className="border-b border-border px-5 pt-2">
          <FilterTabs
            label="Filtrer les notifications"
            value={filter}
            onChange={setFilter}
            items={[
              { value: "all", label: "Toutes" },
              { value: "unread", label: "Non lues", badge: unreadCount > 0 ? <span className="rounded-full bg-danger-600 px-1.5 text-[11px] font-semibold text-white">{unreadCount}</span> : undefined },
              { value: "read", label: "Lues" },
            ]}
          />
        </div>

        <div className="p-3 sm:p-4">
          {isLoading ? (
            <div className="p-2"><ListSkeleton rows={6} /></div>
          ) : isError ? (
            <ErrorState onRetry={() => refetch()} />
          ) : items.length === 0 ? (
            <EmptyState
              icon={BellOff}
              title={filter === "unread" ? "Vous êtes à jour !" : "Aucune notification"}
              description={filter === "unread" ? "Aucune notification non lue. Nous vous prévenons dès qu'une publication part ou qu'un compte demande votre attention." : "Vos notifications apparaîtront ici : publications réussies, clips prêts, comptes à reconnecter…"}
              action={filter !== "all" ? <Button variant="outline" onClick={() => setFilter("all")}>Voir toutes les notifications</Button> : <Button asChild><Link to="/content/create">Créer une publication</Link></Button>}
            />
          ) : (
            <ul className="divide-y divide-border">
              {items.map((n) => {
                const { icon: Icon, tone } = SEVERITY[n.severity]
                const unread = !n.readAt
                return (
                  <li key={n.id} className={cn("relative flex items-start gap-4 rounded-xl px-3 py-4", unread && "bg-brand-50/40")}>
                    <span className={cn("relative inline-flex size-10 shrink-0 items-center justify-center rounded-full", tone)} aria-hidden="true">
                      <Icon className="size-5" />
                      {n.platform && <PlatformIcon platform={n.platform} size="xs" className="absolute -right-1 -bottom-1 size-4 ring-2 ring-card [&_svg]:size-2" />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-start justify-between gap-3">
                        <p className={cn("text-sm text-ink", unread ? "font-semibold" : "font-medium")}>
                          {unread && <span className="sr-only">Non lue : </span>}
                          {n.title}
                        </p>
                        <span className="shrink-0 text-xs text-ink-muted">{formatRelative(n.createdAt)}</span>
                      </div>
                      <p className="mt-0.5 text-sm text-ink-muted">{n.message}</p>
                      {n.href && (
                        <Link to={n.href} onClick={() => unread && markRead.mutate([n.id])} className="mt-2 inline-block text-sm font-semibold text-brand-600 hover:underline">
                          Voir le détail
                        </Link>
                      )}
                    </div>
                    {unread && <span className="mt-2 size-2 shrink-0 rounded-full bg-brand-500" aria-hidden="true" />}
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon-xs" aria-label={`Actions pour « ${n.title} »`}><MoreHorizontal /></Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {unread && <DropdownMenuItem onSelect={() => markRead.mutate([n.id])}><CheckCheck /> Marquer comme lue</DropdownMenuItem>}
                        <DropdownMenuItem variant="destructive" onSelect={() => remove.mutate(n.id)}><Trash2 /> Supprimer</DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      </div>
    </>
  )
}
