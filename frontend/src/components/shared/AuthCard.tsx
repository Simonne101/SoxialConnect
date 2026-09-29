import type { ReactNode } from "react"
import { Link } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { cn } from "@/lib/utils"

/**
 * Bloc des écrans Connexion / Créer un compte (maquette : logo, titre, sous-titre, formulaire).
 * Espacements calibrés pour tenir dans la hauteur du viewport (écran d'authentification fixe).
 */
export function AuthCard({
  title,
  subtitle,
  children,
  footer,
  wide = false,
  className,
}: {
  title: string
  subtitle: string
  children: ReactNode
  footer?: ReactNode
  /** Formulaire plus large (inscription sur deux colonnes). */
  wide?: boolean
  className?: string
}) {
  useDocumentTitle(title)
  return (
    <div className={cn("w-full", wide ? "max-w-[520px]" : "max-w-[400px]", className)}>
      <div className="flex flex-col items-center text-center">
        <Link to="/" aria-label="SoxialConnect — Accueil">
          <Logo className="tiny:[&_svg]:size-7" />
        </Link>
        <h1 className="mt-4 text-2xl font-bold short:mt-3 tiny:mt-2 tiny:text-xl">{title}</h1>
        <p className="mt-1 text-sm text-ink-muted">{subtitle}</p>
      </div>
      <div className="mt-6 short:mt-4 tiny:mt-3">{children}</div>
      {footer && <div className="mt-5 text-center text-sm text-ink-muted short:mt-3 tiny:mt-2">{footer}</div>}
    </div>
  )
}
