import type { ReactNode } from "react"
import { Link } from "react-router"
import { Logo } from "@/components/brand/Logo"

/** Section numérotée d'un document légal (title sert aussi d'entrée de la table des matières). */
export function LegalSection({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section id={id} className="scroll-mt-24">
      <h2 className="text-lg font-semibold text-ink">{title}</h2>
      <div className="mt-3 space-y-3 text-[15px] leading-relaxed text-ink-muted">{children}</div>
    </section>
  )
}

/** Liste à puces standard des documents légaux. */
export function LegalList({ items }: { items: ReactNode[] }) {
  return (
    <ul className="list-disc space-y-1.5 pl-5 marker:text-brand-500">
      {items.map((item, i) => (
        <li key={i}>{item}</li>
      ))}
    </ul>
  )
}

/**
 * Gabarit commun des pages légales publiques (accessibles sans authentification —
 * exigé notamment pour les révisions TikTok Developer / Meta / Google : docs UX-19).
 */
export function LegalLayout({
  title,
  updatedAt,
  intro,
  toc,
  children,
}: {
  title: string
  updatedAt: string
  intro?: ReactNode
  toc?: { id: string; label: string }[]
  children: ReactNode
}) {
  return (
    <div className="min-h-dvh bg-landing">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
          <Link to="/" aria-label="Accueil SoxialConnect">
            <Logo />
          </Link>
          <nav aria-label="Documents légaux" className="flex items-center gap-4 text-sm">
            <Link to="/terms" className="text-ink-muted hover:text-brand-600">
              Conditions d'utilisation
            </Link>
            <Link to="/privacy" className="text-ink-muted hover:text-brand-600">
              Politique de confidentialité
            </Link>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <h1 className="text-3xl font-bold tracking-tight text-ink">{title}</h1>
        <p className="mt-2 text-sm text-ink-muted">
          Dernière mise à jour&nbsp;: <time dateTime={updatedAt}>{updatedAt}</time>
        </p>
        {intro && <div className="mt-6 text-[15px] leading-relaxed text-ink-muted">{intro}</div>}

        {toc && toc.length > 0 && (
          <nav aria-label="Sommaire" className="mt-8 rounded-xl border border-border bg-card p-5">
            <h2 className="text-sm font-semibold text-ink">Sommaire</h2>
            <ol className="mt-3 grid gap-x-6 gap-y-1.5 text-sm text-ink-muted sm:grid-cols-2">
              {toc.map((entry, i) => (
                <li key={entry.id}>
                  <a href={`#${entry.id}`} className="hover:text-brand-600">
                    {i + 1}. {entry.label}
                  </a>
                </li>
              ))}
            </ol>
          </nav>
        )}

        <div className="mt-10 space-y-10">{children}</div>

        <footer className="mt-14 border-t border-border pt-6 text-sm text-ink-muted">
          <p>
            <Link to="/terms" className="hover:text-brand-600">Conditions d'utilisation</Link>
            {" · "}
            <Link to="/privacy" className="hover:text-brand-600">Politique de confidentialité</Link>
            {" · "}
            <Link to="/legal/data-deletion" className="hover:text-brand-600">Suppression des données</Link>
            {" · "}
            <Link to="/" className="hover:text-brand-600">Retour à l'accueil</Link>
          </p>
        </footer>
      </main>
    </div>
  )
}
