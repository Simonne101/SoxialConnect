import type { PlatformKey } from "./social"

export type NotificationType =
  | "post_published"
  | "post_failed"
  | "post_scheduled"
  | "video_ready"
  | "clip_rendered"
  | "account_disconnected"
  | "account_expired"
  | "permission_missing"
  | "media_ready"
  | "media_rejected"

export type NotificationSeverity = "success" | "info" | "warning" | "error"

export interface AppNotification {
  id: string
  type: NotificationType
  severity: NotificationSeverity
  title: string
  message: string
  platform: PlatformKey | null
  /** Lien interne vers la ressource concernée. */
  href: string | null
  readAt: string | null
  createdAt: string
}
