// Éléments en ligne des pages légales : liens internes et externes, information à compléter.
import type { ReactNode } from "react"
import { Link } from "react-router"

/** Information à fournir par l'éditeur avant la mise en production (visible, jamais inventée). */
export function ToComplete({ children }: { children: ReactNode }) {
  return <mark className="rounded bg-warning-50 px-1 font-medium text-warning-700">[À compléter : {children}]</mark>
}

const ext = "font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700"

export function External({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={ext}>
      {children}
    </a>
  )
}

export function Internal({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} className={ext}>
      {children}
    </Link>
  )
}
