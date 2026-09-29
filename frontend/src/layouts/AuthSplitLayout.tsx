import { ArrowLeft } from "lucide-react"
import { Suspense } from "react"
import { Link, Outlet } from "react-router"
import { HERO_PNG, HERO_SIZES, HERO_SRCSET } from "@/components/landing/heroImage"
import { PageLoader } from "@/components/shared/PageLoader"

/**
 * Écrans d'authentification (connexion, inscription, mot de passe oublié) : un écran fixe
 * de la hauteur du viewport (100dvh, qui suit aussi l'apparition du clavier mobile).
 *
 * - ≥ 1024 px : formulaire à gauche, panneau visuel à droite (pleine hauteur, sans défilement propre).
 * - < 1024 px : une seule colonne ; le panneau visuel est masqué.
 * - Si le contenu dépasse malgré tout (zoom fort, très petite hauteur, clavier ouvert), seul le
 *   panneau du formulaire défile : rien n'est masqué ni coupé.
 */
export function AuthSplitLayout() {
  return (
    <div className="grid h-dvh grid-cols-1 overflow-hidden bg-card lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] short:[&_[data-slot=input]]:h-10 short:[&_[data-size=lg]]:h-11 tiny:[&_[data-size=lg]]:h-10">
      <main className="relative flex min-h-0 flex-col overflow-y-auto">
        <div className="absolute top-3 left-5 z-10 sm:left-8">
          <Link to="/" className="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-ink-muted hover:text-ink">
            <ArrowLeft className="size-4" aria-hidden="true" /> Retour au site
          </Link>
        </div>
        <div className="flex flex-1 items-center justify-center px-5 pt-11 pb-5 sm:px-8 tiny:pt-9 tiny:pb-3">
          <Suspense fallback={<PageLoader />}>
            <Outlet />
          </Suspense>
        </div>
      </main>

      <aside className="relative hidden h-dvh overflow-hidden bg-landing lg:flex lg:flex-col lg:items-center lg:justify-center lg:px-10" aria-hidden="true">
        <div className="pointer-events-none absolute -top-32 -right-24 size-[460px] rounded-full bg-lavender opacity-80 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-40 -left-32 size-[420px] rounded-full bg-brand-100 opacity-70 blur-3xl" />
        <picture className="relative w-full max-w-[620px]">
          <source type="image/webp" srcSet={HERO_SRCSET} sizes={HERO_SIZES} />
          <img src={HERO_PNG} width={1520} height={1035} alt="" decoding="async" className="hero-mask h-auto max-h-[62dvh] w-full object-contain" />
        </picture>
        <p className="relative mt-4 text-center font-script text-3xl leading-tight text-ink-soft xl:text-[34px]">
          Créez. Planifiez. Publiez.
          <br />
          Analysez. Grandissez.
        </p>
      </aside>
    </div>
  )
}
