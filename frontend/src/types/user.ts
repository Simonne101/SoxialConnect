export interface UserProfile {
  id: string
  name: string
  email: string
  role: string
  bio: string
  timezone: string
  locale: "fr" | "en"
  emailVerified: boolean
  twoFactorEnabled: boolean
}

export interface UserSession {
  id: string
  device: string
  location: string
  ipMasked: string
  lastActiveAt: string
  current: boolean
}

export interface NotificationPreferences {
  publishSuccessEmail: boolean
  publishFailureEmail: boolean
  accountAlertsEmail: boolean
  videoReadyEmail: boolean
  weeklyReportEmail: boolean
  inAppAll: boolean
}

export interface SecurityEvent {
  id: string
  action: string
  label: string
  ipMasked: string
  createdAt: string
}

export interface ActivityItem {
  id: string
  kind: "published" | "scheduled" | "failed" | "connected" | "media" | "clip" | "ai"
  title: string
  detail: string
  createdAt: string
  href: string | null
}
