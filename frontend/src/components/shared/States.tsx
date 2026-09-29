import type { LucideIcon } from "lucide-react"
import { AlertTriangle, RotateCw } from "lucide-react"
import type { ReactNode } from "react"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

/** État vide : explique la situation ET propose l'action utile (utilisateur débutant). */
export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: {
  icon: LucideIcon
  title: string
  description: string
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-brand-200 bg-brand-50/40 px-6 py-12 text-center", className)}>
      <span className="inline-flex size-14 items-center justify-center rounded-2xl bg-card text-brand-500 shadow-card" aria-hidden="true">
        <Icon className="size-7" />
      </span>
      <div className="max-w-sm">
        <h3 className="text-base font-semibold">{title}</h3>
        <p className="mt-1 text-sm text-ink-muted">{description}</p>
      </div>
      {action && <div className="mt-1 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  )
}

export function ErrorState({
  title = "Impossible de charger ces données",
  description = "Vérifiez votre connexion puis réessayez.",
  onRetry,
  className,
}: {
  title?: string
  description?: string
  onRetry?: () => void
  className?: string
}) {
  return (
    <div role="alert" className={cn("flex flex-col items-center gap-3 rounded-2xl border border-danger-100 bg-danger-50/50 px-6 py-10 text-center", className)}>
      <AlertTriangle className="size-8 text-danger-500" aria-hidden="true" />
      <div>
        <h3 className="text-base font-semibold">{title}</h3>
        <p className="mt-1 text-sm text-ink-muted">{description}</p>
      </div>
      {onRetry && (
        <Button variant="outline" onClick={onRetry}>
          <RotateCw /> Réessayer
        </Button>
      )}
    </div>
  )
}

export function CardsSkeleton({ count = 4, className }: { count?: number; className?: string }) {
  return (
    <div className={cn("grid gap-4 sm:grid-cols-2 xl:grid-cols-4", className)} aria-busy="true" aria-label="Chargement">
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="surface-card space-y-3 p-5">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-8 w-2/3" />
          <Skeleton className="h-3 w-1/3" />
        </div>
      ))}
    </div>
  )
}

export function ListSkeleton({ rows = 5, className }: { rows?: number; className?: string }) {
  return (
    <div className={cn("space-y-3", className)} aria-busy="true" aria-label="Chargement">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="flex items-center gap-3">
          <Skeleton className="size-10 rounded-full" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-4 w-2/5" />
            <Skeleton className="h-3 w-3/5" />
          </div>
        </div>
      ))}
    </div>
  )
}

/** Bandeau signalant une donnée simulée (transparence : aucune API n'est connectée). */
export function DemoNotice({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <p className={cn("rounded-xl border border-brand-100 bg-brand-50/70 px-4 py-2.5 text-xs text-brand-700", className)}>
      {children}
    </p>
  )
}
