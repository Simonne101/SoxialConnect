import { Badge } from "@/components/ui/badge"
import type { StatusMeta } from "@/lib/status"
import { cn } from "@/lib/utils"

/** Badge pilule avec point coloré (maquette : « ● Connecté »). */
export function StatusBadge({ meta, className, withDot = true }: { meta: StatusMeta; className?: string; withDot?: boolean }) {
  return (
    <Badge variant={meta.tone} className={cn("font-medium", className)}>
      {withDot && <span aria-hidden="true" className={cn("size-1.5 rounded-full", meta.dot)} />}
      {meta.label}
    </Badge>
  )
}
