import { Compass } from "lucide-react"
import { Link } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"

export default function NotFoundPage() {
  useDocumentTitle("Page introuvable")
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-landing px-4 text-center">
      <Link to="/" aria-label="Accueil">
        <Logo />
      </Link>
      <span className="inline-flex size-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-500" aria-hidden="true">
        <Compass className="size-8" />
      </span>
      <div>
        <p className="text-sm font-semibold text-brand-600">Erreur 404</p>
        <h1 className="mt-2 text-3xl font-bold">Cette page n'existe pas</h1>
        <p className="mt-2 text-ink-muted">Le lien est peut-être incorrect ou la page a été déplacée.</p>
      </div>
      <div className="flex flex-wrap justify-center gap-3">
        <Button variant="outline" asChild>
          <Link to="/">Retour à l'accueil</Link>
        </Button>
        <Button asChild>
          <Link to="/dashboard">Aller au tableau de bord</Link>
        </Button>
      </div>
    </main>
  )
}
