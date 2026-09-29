import { Link, isRouteErrorResponse, useRouteError } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"

/** Erreur de rendu ou de chargement d'une route (ex. chunk indisponible après déploiement). */
export function RouteError() {
  const error = useRouteError()
  const status = isRouteErrorResponse(error) ? error.status : null
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-page px-4 text-center">
      <Logo />
      <div>
        <p className="text-sm font-semibold text-brand-600">{status ?? "Erreur"}</p>
        <h1 className="mt-2 text-2xl font-bold">Une erreur est survenue</h1>
        <p className="mt-2 max-w-md text-sm text-ink-muted">
          La page n'a pas pu s'afficher. Rechargez la page ; si le problème persiste, revenez au tableau de bord.
        </p>
      </div>
      <div className="flex gap-3">
        <Button variant="outline" onClick={() => window.location.reload()}>Recharger</Button>
        <Button asChild>
          <Link to="/dashboard">Tableau de bord</Link>
        </Button>
      </div>
    </main>
  )
}
