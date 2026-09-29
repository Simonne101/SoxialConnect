import { FileText } from "lucide-react"
import { Link, useParams } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"

const DOCS: Record<string, string> = {
  privacy: "Politique de confidentialité",
  terms: "Conditions d'utilisation",
  "data-deletion": "Suppression de vos données",
}

/** Emplacement des pages légales (obligatoires pour les revues des plateformes — docs UX-19). */
export default function LegalPage() {
  const { doc = "" } = useParams()
  const title = DOCS[doc] ?? "Document"
  useDocumentTitle(title)
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-landing px-4 text-center">
      <Link to="/" aria-label="Accueil">
        <Logo />
      </Link>
      <span className="inline-flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500" aria-hidden="true">
        <FileText className="size-7" />
      </span>
      <div>
        <h1 className="text-3xl font-bold">{title}</h1>
        <p className="mx-auto mt-3 max-w-md text-ink-muted">
          Ce document est en cours de rédaction et sera publié avant l'ouverture du service.
        </p>
      </div>
      <Button variant="outline" asChild>
        <Link to="/">Retour à l'accueil</Link>
      </Button>
    </main>
  )
}
