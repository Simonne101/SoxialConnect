import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import type { AnalyticsPeriod, NotificationPreferences, UserProfile } from "@/types"
import * as svc from "@/services/misc"
import { qk } from "./keys"

export const useAnalytics = (period: AnalyticsPeriod) =>
  useQuery({ queryKey: qk.analytics(period), queryFn: () => svc.getAnalytics(period), placeholderData: (prev) => prev })

export const useDashboardSummary = (range: svc.DashboardRange = "today") =>
  useQuery({ queryKey: qk.dashboardRange(range), queryFn: () => svc.getDashboardSummary(range), placeholderData: (prev) => prev })
export const useActivity = () => useQuery({ queryKey: qk.activity, queryFn: svc.getActivity })
export const useNotifications = () =>
  useQuery({ queryKey: qk.notifications, queryFn: svc.listNotifications, refetchInterval: 30_000 })

export function useMarkNotificationsRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: svc.markNotificationsRead,
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.notifications }),
  })
}

export function useDeleteNotification() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: svc.deleteNotification,
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.notifications }),
  })
}

export const useMe = () => useQuery({ queryKey: qk.me, queryFn: svc.getMe, staleTime: Infinity })

export function useUpdateMe() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (patch: Partial<UserProfile>) => svc.updateMe(patch),
    onSuccess: (user) => qc.setQueryData(qk.me, user),
  })
}

export const useSessions = () => useQuery({ queryKey: qk.sessions, queryFn: svc.listSessions })

export function useRevokeSession() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: svc.revokeSession,
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.sessions }),
  })
}

export const useSecurityEvents = () => useQuery({ queryKey: qk.securityEvents, queryFn: svc.listSecurityEvents })

export const useNotificationPreferences = () =>
  useQuery({ queryKey: qk.notificationPreferences, queryFn: svc.getNotificationPreferences })

export function useUpdateNotificationPreferences() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (patch: Partial<NotificationPreferences>) => svc.updateNotificationPreferences(patch),
    onSuccess: (prefs) => qc.setQueryData(qk.notificationPreferences, prefs),
  })
}

export const useChangePassword = () => useMutation({ mutationFn: svc.changePassword })
