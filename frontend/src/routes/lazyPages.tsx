import { lazy } from "react"

// Chaque page est un chunk séparé (code splitting) chargé à la navigation.
export const LandingPage = lazy(() => import("@/pages/LandingPage"))
export const LegalPage = lazy(() => import("@/pages/LegalPage"))
export const LoginPage = lazy(() => import("@/pages/auth/LoginPage"))
export const RegisterPage = lazy(() => import("@/pages/auth/RegisterPage"))
export const ForgotPasswordPage = lazy(() => import("@/pages/auth/ForgotPasswordPage"))
export const OnboardingPage = lazy(() => import("@/pages/OnboardingPage"))
export const DashboardPage = lazy(() => import("@/pages/DashboardPage"))
export const PostsPage = lazy(() => import("@/pages/content/PostsPage"))
export const ComposerPage = lazy(() => import("@/pages/content/ComposerPage"))
export const CalendarPage = lazy(() => import("@/pages/content/CalendarPage"))
export const DraftsPage = lazy(() => import("@/pages/content/DraftsPage"))
export const SocialAccountsPage = lazy(() => import("@/pages/SocialAccountsPage"))
export const MediaPage = lazy(() => import("@/pages/MediaPage"))
export const VideoStudioPage = lazy(() => import("@/pages/VideoStudioPage"))
export const AnalyticsPage = lazy(() => import("@/pages/AnalyticsPage"))
export const AIAssistantPage = lazy(() => import("@/pages/AIAssistantPage"))
export const NotificationsPage = lazy(() => import("@/pages/NotificationsPage"))
export const SettingsPage = lazy(() => import("@/pages/SettingsPage"))
export const DesignSystemPage = lazy(() => import("@/pages/DesignSystemPage"))
export const NotFoundPage = lazy(() => import("@/pages/NotFoundPage"))

// Layout de l'application connectée (barre latérale, requêtes) : chargé après la landing.
export const AppLayout = lazy(() => import("@/layouts/AppLayout").then((m) => ({ default: m.AppLayout })))
