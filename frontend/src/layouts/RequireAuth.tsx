import type { ReactNode } from "react"
import { Navigate, useLocation } from "react-router"
import { PageLoader } from "@/components/shared/PageLoader"
import { ErrorState } from "@/components/shared/States"
import { useMe } from "@/hooks/queries/misc"
import { ApiError, isApiEnabled } from "@/lib/http"

/**
 * Protège les pages de l'application : sans session valide (401), redirection vers /login,
 * puis retour à la page demandée après connexion. En mode démonstration (sans backend), accès libre.
 * Rappel : ce contrôle est un confort d'interface — l'autorisation réelle est faite par l'API.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
  const location = useLocation()
  const { isLoading, error, refetch } = useMe()

  if (!isApiEnabled) return children
  if (isLoading) return <PageLoader label="Vérification de votre session…" />
  if (error instanceof ApiError && error.status === 401) {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  }
  if (error) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-page px-4">
        <ErrorState title="Impossible de vérifier votre session" description="Le serveur ne répond pas. Réessayez dans un instant." onRetry={() => refetch()} className="max-w-md" />
      </div>
    )
  }
  return children
}
