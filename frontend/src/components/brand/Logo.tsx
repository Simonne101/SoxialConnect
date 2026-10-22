import { useId } from "react"
import { cn } from "@/lib/utils"

/** Courbe du « S » et ses deux nœuds de connexion (repère 64 × 64, cadré par le viewBox). */
const S_PATH =
  "M44.6 19.4C41.6 15.1 36.9 13 31.5 13 24 13 18.6 17.1 18.6 23.1c0 6.4 5.9 8.3 13.4 9.6 7.5 1.3 13.4 3.3 13.4 9.8 0 6.1-5.5 9.5-13 9.5-6 0-10.9-2.2-13.9-6.5"
const NODES = [
  [44.6, 19.4],
  [18.5, 46],
] as const

/** Monogramme « S » en dégradé indigo → violet dont les extrémités sont des nœuds de connexion (centres évidés). */
export function LogoMark({ className }: { className?: string }) {
  const id = useId()
  return (
    <svg viewBox="8 8.5 48 48" aria-hidden="true" className={cn("size-8", className)}>
      <defs>
        <linearGradient id={`${id}-a`} x1="18" y1="12" x2="46" y2="54" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="var(--color-brand-400)" />
          <stop offset="0.45" stopColor="var(--color-brand-500)" />
          <stop offset="1" stopColor="var(--color-violet-500)" />
        </linearGradient>
        <mask id={`${id}-h`} maskUnits="userSpaceOnUse" x="0" y="0" width="64" height="64">
          <rect width="64" height="64" fill="#fff" />
          {NODES.map(([cx, cy]) => (
            <circle key={cx} cx={cx} cy={cy} r="2.5" fill="#000" />
          ))}
        </mask>
      </defs>
      <g fill={`url(#${id}-a)`} mask={`url(#${id}-h)`}>
        <path d={S_PATH} fill="none" stroke={`url(#${id}-a)`} strokeWidth="7.2" strokeLinecap="round" />
        {NODES.map(([cx, cy]) => (
          <circle key={cx} cx={cx} cy={cy} r="6.3" />
        ))}
      </g>
    </svg>
  )
}

export function Logo({ className, tone = "dark" }: { className?: string; tone?: "dark" | "light" }) {
  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
      <LogoMark />
      <span className={cn("text-lg font-bold tracking-tight", tone === "dark" ? "text-ink" : "text-white")}>
        SoxialConnect
      </span>
    </span>
  )
}
