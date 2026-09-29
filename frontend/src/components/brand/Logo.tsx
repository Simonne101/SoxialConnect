import { useId } from "react"
import { cn } from "@/lib/utils"

/** Monogramme « S » en dégradé indigo → violet, d'après le logo de la maquette. */
export function LogoMark({ className }: { className?: string }) {
  const id = useId()
  return (
    <svg viewBox="0 0 32 32" aria-hidden="true" className={cn("size-8", className)}>
      <defs>
        <linearGradient id={`${id}-a`} x1="4" y1="2" x2="28" y2="30" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="var(--color-brand-400)" />
          <stop offset="0.55" stopColor="var(--color-brand-500)" />
          <stop offset="1" stopColor="var(--color-violet-500)" />
        </linearGradient>
      </defs>
      <path
        fill={`url(#${id}-a)`}
        d="M19.6 2.4c-6.9-.9-13 3.6-13 9.6 0 4.1 2.8 6.4 6.6 7.9l3.7 1.4c1.7.7 2.4 1.3 2.4 2.3 0 1.6-2 2.7-4.7 2.7-2.4 0-4.6-.9-6.5-2.5l-3 4.4C7.6 30.5 11 31.8 15 31.8c6.4 0 11.1-3.7 11.1-9 0-4-2.4-6.3-6.7-8l-3.8-1.5c-1.8-.7-2.7-1.3-2.7-2.4 0-1.5 1.7-2.4 4-2.4 1.9 0 3.5.5 5.1 1.6l3-4.2c-1.7-1.3-3.6-2.1-5.4-3.5Z"
      />
      <circle cx="25.5" cy="6.5" r="2.5" fill="var(--color-violet-500)" opacity="0.85" />
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
