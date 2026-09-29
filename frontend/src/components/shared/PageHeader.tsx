import type { ReactNode } from "react"
import { NotificationBell } from "@/components/notifications/NotificationBell"
import { cn } from "@/lib/utils"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"

interface PageHeaderProps {
  title: string
  description?: string
  actions?: ReactNode
  className?: string
  /** Cloche de notifications (masquée sur la page Notifications elle-même). */
  showBell?: boolean
}

/** En-tête de page de l'application : titre bleu nuit + sous-titre gris (maquette). */
export function PageHeader({ title, description, actions, className, showBell = true }: PageHeaderProps) {
  useDocumentTitle(title)
  return (
    <header className={cn("mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between", className)}>
      <div className="min-w-0">
        <h1 className="text-2xl font-bold sm:text-[26px]">{title}</h1>
        {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
      </div>
      {(actions || showBell) && (
        <div className="flex flex-wrap items-center gap-2">
          {actions}
          {/* Sur mobile, la cloche est dans la barre supérieure de l'application. */}
          {showBell && <NotificationBell className="hidden md:inline-flex" />}
        </div>
      )}
    </header>
  )
}
