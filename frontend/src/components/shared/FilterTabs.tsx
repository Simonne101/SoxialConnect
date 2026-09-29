import type { ReactNode } from "react"
import { NavLink } from "react-router"
import { cn } from "@/lib/utils"

export interface FilterTabItem<T extends string> {
  value: T
  label: ReactNode
  badge?: ReactNode
}

const LINE_ITEM =
  "relative inline-flex h-11 shrink-0 items-center gap-1.5 px-1 text-sm font-medium whitespace-nowrap text-ink-muted transition-colors hover:text-ink after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary after:opacity-0 aria-[pressed=true]:font-semibold aria-[pressed=true]:text-primary aria-[pressed=true]:after:opacity-100 aria-[current=page]:font-semibold aria-[current=page]:text-primary aria-[current=page]:after:opacity-100"
const PILL_ITEM =
  "inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md px-3 text-sm font-medium whitespace-nowrap text-ink-muted transition-colors hover:text-ink aria-[pressed=true]:bg-card aria-[pressed=true]:text-ink aria-[pressed=true]:shadow-sm"

/**
 * Filtres visuellement identiques aux onglets de la maquette (soulignés ou segmentés).
 * Sémantique : groupe de boutons à bascule (aria-pressed) — les onglets Radix sont réservés
 * aux cas avec panneaux de contenu associés.
 */
export function FilterTabs<T extends string>({
  items,
  value,
  onChange,
  label,
  variant = "line",
  className,
}: {
  items: FilterTabItem<T>[]
  value: T
  onChange: (v: T) => void
  label: string
  variant?: "line" | "pill"
  className?: string
}) {
  return (
    <div
      role="group"
      aria-label={label}
      className={cn(
        variant === "line" ? "flex gap-5 overflow-x-auto" : "inline-flex gap-0.5 rounded-lg bg-muted p-1",
        className,
      )}
    >
      {items.map((item) => (
        <button key={item.value} type="button" aria-pressed={value === item.value} onClick={() => onChange(item.value)} className={variant === "line" ? LINE_ITEM : PILL_ITEM}>
          {item.label}
          {item.badge}
        </button>
      ))}
    </div>
  )
}

/** Variante de navigation (liens) : aria-current="page" sur l'élément actif. */
export function NavTabs({ items, label, className }: { items: { to: string; label: ReactNode }[]; label: string; className?: string }) {
  return (
    <nav aria-label={label} className={cn("flex gap-5 overflow-x-auto border-b border-border", className)}>
      {items.map((item) => (
        <NavLink key={item.to} to={item.to} className={LINE_ITEM}>
          {item.label}
        </NavLink>
      ))}
    </nav>
  )
}
