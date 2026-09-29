import { ChevronLeft, ChevronRight } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

/** Pagination simple « Précédent / Page x sur y / Suivant » — masquée s'il n'y a qu'une page. */
export function Pagination({
  page,
  lastPage,
  total,
  onChange,
  busy = false,
  label = "Pagination",
  unit = ["publication", "publications"],
  className,
}: {
  page: number
  lastPage: number
  total: number
  onChange: (page: number) => void
  busy?: boolean
  label?: string
  /** Libellé des éléments comptés : [singulier, pluriel]. */
  unit?: [string, string]
  className?: string
}) {
  if (lastPage <= 1) return null
  return (
    <nav aria-label={label} className={cn("flex items-center justify-between gap-3 pt-4", className)}>
      <p className="text-xs text-ink-muted" aria-live="polite">
        Page {page} sur {lastPage} · {total.toLocaleString("fr-FR")} {total > 1 ? unit[1] : unit[0]}
      </p>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" disabled={page <= 1 || busy} onClick={() => onChange(page - 1)}>
          <ChevronLeft /> <span className="hidden sm:inline">Précédent</span>
          <span className="sr-only sm:hidden">Page précédente</span>
        </Button>
        <Button variant="outline" size="sm" disabled={page >= lastPage || busy} onClick={() => onChange(page + 1)}>
          <span className="hidden sm:inline">Suivant</span>
          <span className="sr-only sm:hidden">Page suivante</span> <ChevronRight />
        </Button>
      </div>
    </nav>
  )
}
