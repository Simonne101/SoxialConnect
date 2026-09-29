import type { LucideIcon } from "lucide-react"
import { AlertTriangle, CheckCircle2, Info, XCircle } from "lucide-react"
import type { NotificationSeverity } from "@/types"

/** Icône et couleurs par gravité de notification (page Notifications et cloche). */
export const SEVERITY: Record<NotificationSeverity, { icon: LucideIcon; tone: string }> = {
  success: { icon: CheckCircle2, tone: "bg-success-50 text-success-700" },
  info: { icon: Info, tone: "bg-brand-50 text-brand-600" },
  warning: { icon: AlertTriangle, tone: "bg-warning-50 text-warning-700" },
  error: { icon: XCircle, tone: "bg-danger-50 text-danger-700" },
}
