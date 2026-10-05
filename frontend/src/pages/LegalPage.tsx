import { ArrowLeft } from "lucide-react"
import { Link, useParams } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { LEGAL_DOCUMENTS, LEGAL_UPDATED_AT } from "./legal/documents"
import NotFoundPage from "./NotFoundPage"

/**
 * Pages légales publiques (sans authentification) : /terms, /privacy et /legal/:doc
 * (exigées par les revues des plateformes — docs UX-19, TikTok Developer, Google OAuth).
 */
export default function LegalPage({ doc: forced }: { doc?: string }) {
  const { doc: param = "" } = useParams()
  const key = forced ?? param
  const document = LEGAL_DOCUMENTS[key]
  useDocumentTitle(document?.title ?? "Page introuvable")
  if (!document) return <NotFoundPage />

  return (
    <div className="min-h-dvh bg-landing">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-4 sm:px-6">
          <Link to="/" aria-label="Accueil SoxialConnect">
            <Logo />
          </Link>
          <Link to="/" className="inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-brand-600">
            <ArrowLeft className="size-4" aria-hidden="true" /> Accueil
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <article>
          <h1 className="text-3xl font-bold text-ink sm:text-4xl">{document.title}</h1>
          <p className="mt-2 text-sm text-ink-muted">Dernière mise à jour : {LEGAL_UPDATED_AT}</p>
          <div className="legal-text mt-6">{document.intro}</div>

          <nav aria-label="Sommaire" className="mt-8 rounded-xl border border-border bg-card p-5">
            <h2 className="text-sm font-semibold text-ink">Sommaire</h2>
            <ol className="mt-3 grid gap-1.5 text-sm sm:grid-cols-2">
              {document.sections.map((s) => (
                <li key={s.id}>
                  <a href={`#${s.id}`} className="text-ink-muted hover:text-brand-600">
                    {s.title}
                  </a>
                </li>
              ))}
            </ol>
          </nav>

          {document.sections.map((s) => (
            <section key={s.id} id={s.id} aria-labelledby={`${s.id}-title`} className="mt-10 scroll-mt-6">
              <h2 id={`${s.id}-title`} className="text-xl font-semibold text-ink">
                {s.title}
              </h2>
              <div className="legal-text mt-3">{s.body}</div>
            </section>
          ))}
        </article>

        <nav aria-label="Documents légaux" className="mt-14 flex flex-wrap gap-x-6 gap-y-2 border-t border-border pt-6 text-sm">
          <Link to="/terms" className="text-ink-muted hover:text-brand-600">Conditions d'utilisation</Link>
          <Link to="/privacy" className="text-ink-muted hover:text-brand-600">Politique de confidentialité</Link>
          <Link to="/legal/data-deletion" className="text-ink-muted hover:text-brand-600">Suppression de vos données</Link>
        </nav>
      </main>
    </div>
  )
}
