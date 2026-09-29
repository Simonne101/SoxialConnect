import type { ReactNode } from "react"
import { cn } from "@/lib/utils"

/** Carte de section avec titre et action à droite (« Voir tout », sélecteur de période…). */
export function SectionCard({
  title,
  action,
  children,
  className,
  bodyClassName,
  headingLevel: H = "h2",
}: {
  title: ReactNode
  action?: ReactNode
  children: ReactNode
  className?: string
  bodyClassName?: string
  headingLevel?: "h2" | "h3"
}) {
  return (
    <section className={cn("surface-card flex flex-col", className)}>
      <div className="flex items-center justify-between gap-3 px-5 pt-5">
        <H className="text-[15px] font-semibold">{title}</H>
        {action}
      </div>
      <div className={cn("flex-1 p-5 pt-4", bodyClassName)}>{children}</div>
    </section>
  )
}
