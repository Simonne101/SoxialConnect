import { createBrowserRouter, Navigate } from "react-router"
import { AuthLayout } from "@/layouts/AuthLayout"
import { AuthSplitLayout } from "@/layouts/AuthSplitLayout"
import { GuestOnly } from "@/layouts/GuestOnly"
import { RouteError } from "@/pages/RouteError"
import {
  AppLayout,
  LandingPage,
  LegalPage,
  LoginPage,
  RegisterPage,
  ForgotPasswordPage,
  OnboardingPage,
  DashboardPage,
  PostsPage,
  ComposerPage,
  CalendarPage,
  DraftsPage,
  SocialAccountsPage,
  MediaPage,
  VideoStudioPage,
  VideoProjectPage,
  AnalyticsPage,
  AIAssistantPage,
  NotificationsPage,
  SettingsPage,
  DesignSystemPage,
  NotFoundPage,
} from "./lazyPages"

export const router = createBrowserRouter([
  {
    errorElement: <RouteError />,
    children: [
      { path: "/", element: <LandingPage /> },
      // Pages légales publiques (URL officielles déclarées aux plateformes : TikTok, Google…).
      { path: "/terms", element: <LegalPage doc="terms" /> },
      { path: "/privacy", element: <LegalPage doc="privacy" /> },
      { path: "/privacy-policy", element: <LegalPage doc="privacy" /> },
      { path: "/legal/:doc", element: <LegalPage /> },
      { path: "/design-system", element: <DesignSystemPage /> },
      {
        // Écrans d'authentification fixes (100dvh, formulaire + panneau visuel), réservés aux
        // visiteurs non connectés : une session ouverte renvoie vers le tableau de bord.
        element: (
          <GuestOnly>
            <AuthSplitLayout />
          </GuestOnly>
        ),
        children: [
          { path: "/login", element: <LoginPage /> },
          { path: "/register", element: <RegisterPage /> },
          { path: "/forgot-password", element: <ForgotPasswordPage /> },
        ],
      },
      {
        element: <AuthLayout />,
        children: [{ path: "/onboarding", element: <OnboardingPage /> }],
      },
      {
        element: <AppLayout />,
        children: [
          { path: "/dashboard", element: <DashboardPage /> },
          { path: "/content", element: <PostsPage /> },
          { path: "/content/create", element: <ComposerPage /> },
          { path: "/content/:postId/edit", element: <ComposerPage /> },
          { path: "/content/calendar", element: <CalendarPage /> },
          { path: "/content/drafts", element: <DraftsPage /> },
          { path: "/social-accounts", element: <SocialAccountsPage /> },
          { path: "/media", element: <MediaPage /> },
          { path: "/video-studio", element: <VideoStudioPage /> },
          { path: "/video-studio/:projectId", element: <VideoProjectPage /> },
          { path: "/analytics", element: <AnalyticsPage /> },
          { path: "/ai", element: <AIAssistantPage /> },
          { path: "/notifications", element: <NotificationsPage /> },
          { path: "/settings", element: <Navigate to="/settings/profile" replace /> },
          { path: "/settings/:tab", element: <SettingsPage /> },
          { path: "/app", element: <Navigate to="/dashboard" replace /> },
        ],
      },
      { path: "*", element: <NotFoundPage /> },
    ],
  },
])
