import { Bell, BellOff, CheckCheck } from "lucide-react"
import { useState } from "react"
import { Link, useNavigate } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { useMarkNotificationsRead, useNotifications } from "@/hooks/queries/misc"
import { formatRelative } from "@/lib/format"
import { cn } from "@/lib/utils"
import type { AppNotification } from "@/types"
import { SEVERITY } from "./severity"

/**
 * Cloche de notifications (en-tête des pages) : badge du nombre de non lues,
 * aperçu des 5 dernières au clic, accès à la page complète.
 */
export function NotificationBell({ className }: { className?: string }) {
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()
  const { data } = useNotifications()
  const markRead = useMarkNotificationsRead()
  const all = data ?? []
  const unread = all.filter((n) => !n.readAt).length
  const latest = all.slice(0, 5)

  const openItem = (n: AppNotification) => {
    if (!n.readAt) markRead.mutate([n.id])
    setOpen(false)
    navigate(n.href ?? "/notifications")
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="icon"
          className={cn("relative shrink-0", className)}
          aria-label={unread > 0 ? `Notifications : ${unread} non lue${unread > 1 ? "s" : ""}` : "Notifications : aucune non lue"}
        >
          <Bell className="size-5" />
          {unread > 0 && (
            <span className="absolute -top-1.5 -right-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger-600 px-1 text-[11px] leading-none font-bold text-white ring-2 ring-page" aria-hidden="true">
              {unread > 9 ? "9+" : unread}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        sideOffset={8}
        tabIndex={-1}
        aria-label="Aperçu des notifications"
        // Focus sur le panneau (et non sur « Tout lire ») : pas de contour parasite au clic, Tab mène aux actions.
        onOpenAutoFocus={(e) => {
          e.preventDefault()
          ;(e.currentTarget as HTMLElement | null)?.focus()
        }}
        className="w-[min(380px,calc(100vw-2rem))] gap-0 rounded-2xl p-0 shadow-float outline-none"
      >
        <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
          <p className="text-sm font-semibold text-ink">
            Notifications
            {unread > 0 && <span className="ml-2 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-semibold text-danger-700">{unread} non lue{unread > 1 ? "s" : ""}</span>}
          </p>
          {unread > 0 && (
            <Button variant="ghost" size="xs" onClick={() => markRead.mutate("all")} disabled={markRead.isPending}>
              <CheckCheck /> Tout lire
            </Button>
          )}
        </div>

        {latest.length === 0 ? (
          <div className="flex flex-col items-center gap-2 px-6 py-8 text-center">
            <BellOff className="size-7 text-ink-muted" aria-hidden="true" />
            <p className="text-sm font-medium text-ink">Aucune notification</p>
            <p className="text-xs text-ink-muted">Nous vous prévenons ici dès qu'une publication part ou qu'un compte demande votre attention.</p>
          </div>
        ) : (
          <ul className="max-h-[min(420px,60dvh)] overflow-y-auto py-1">
            {latest.map((n) => {
              const { icon: Icon, tone } = SEVERITY[n.severity]
              const isUnread = !n.readAt
              return (
                <li key={n.id}>
                  <button type="button" onClick={() => openItem(n)} className={cn("flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60", isUnread && "bg-brand-50/40")}>
                    <span className={cn("relative inline-flex size-9 shrink-0 items-center justify-center rounded-full", tone)} aria-hidden="true">
                      <Icon className="size-[18px]" />
                      {n.platform && <PlatformIcon platform={n.platform} size="xs" className="absolute -right-1 -bottom-1 size-4 ring-2 ring-card [&_svg]:size-2" />}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className={cn("block text-sm text-ink", isUnread ? "font-semibold" : "font-medium")}>
                        {isUnread && <span className="sr-only">Non lue : </span>}
                        {n.title}
                      </span>
                      <span className="line-clamp-2 block text-xs text-ink-muted">{n.message}</span>
                      <span className="mt-1 block text-[11px] text-ink-muted">{formatRelative(n.createdAt)}</span>
                    </span>
                    {isUnread && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand-500" aria-hidden="true" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}

        <div className="border-t border-border p-2">
          <Button variant="ghost" className="w-full text-brand-600" asChild>
            <Link to="/notifications" onClick={() => setOpen(false)}>
              Voir toutes les notifications
            </Link>
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
