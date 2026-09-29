import type { LucideIcon } from "lucide-react"
import { ArrowDownRight, ArrowUpRight } from "lucide-react"
import { cn } from "@/lib/utils"
import { formatChange } from "@/lib/format"

type Accent = "brand" | "success" | "warning" | "violet" | "info" | "danger"

const ACCENT: Record<Accent, string> = {
  brand: "bg-brand-50 text-brand-600",
  success: "bg-success-50 text-success-700",
  warning: "bg-warning-50 text-warning-700",
  violet: "bg-violet-50 text-violet-600",
  info: "bg-info-50 text-info-700",
  danger: "bg-danger-50 text-danger-700",
}

interface StatCardProps {
  label: string
  value: string
  change?: number | null
  changeLabel?: string
  icon?: LucideIcon
  accent?: Accent
  className?: string
}

/** Carte KPI de la maquette : libellé, grande valeur, variation verte « vs hier ». */
export function StatCard({ label, value, change, changeLabel = "vs période précédente", icon: Icon, accent = "brand", className }: StatCardProps) {
  const positive = (change ?? 0) >= 0
  return (
    <div className={cn("surface-card flex min-w-0 flex-col gap-3 p-4 sm:p-5", className)}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[13px] font-medium text-ink-muted">{label}</p>
        {Icon && (
          <span className={cn("inline-flex size-9 items-center justify-center rounded-xl", ACCENT[accent])} aria-hidden="true">
            <Icon className="size-[18px]" />
          </span>
        )}
      </div>
      <p className="text-2xl leading-none font-bold tracking-tight text-ink sm:text-[28px]">{value}</p>
      {change !== undefined && (
        <p className="flex flex-wrap items-center gap-x-1 text-xs">
          <span className={cn("inline-flex items-center gap-0.5 font-semibold", positive ? "text-success-700" : "text-danger-700")}>
            {positive ? <ArrowUpRight className="size-3.5" aria-hidden="true" /> : <ArrowDownRight className="size-3.5" aria-hidden="true" />}
            {formatChange(change)}
          </span>
          <span className="text-ink-muted">{changeLabel}</span>
        </p>
      )}
    </div>
  )
}
