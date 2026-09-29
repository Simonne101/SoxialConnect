import { cn } from "@/lib/utils"
import type { FeatureItem } from "./features"

/** Carte de fonctionnalité : carte blanche, bordure fine, icône colorée, titre et description. */
export function FeatureCard({ feature, className }: { feature: FeatureItem; className?: string }) {
  const { icon: Icon, title, description, tone } = feature
  return (
    <article className={cn("surface-card h-full p-6 transition-shadow hover:shadow-float", className)}>
      <span className={cn("inline-flex size-11 items-center justify-center rounded-xl", tone)} aria-hidden="true">
        <Icon className="size-5" />
      </span>
      <h3 className="mt-4 text-lg font-bold">{title}</h3>
      <p className="mt-1.5 text-sm leading-relaxed text-ink-muted">{description}</p>
    </article>
  )
}
