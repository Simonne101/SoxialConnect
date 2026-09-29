import { ArrowLeft } from "lucide-react"
import { Suspense } from "react"
import { Link, Outlet } from "react-router"
import { PageLoader } from "@/components/shared/PageLoader"

/** Pages d'authentification et d'onboarding : carte blanche centrée sur fond lavande (maquette). */
export function AuthLayout() {
  return (
    <div className="relative flex min-h-dvh flex-col overflow-hidden bg-landing">
      <div aria-hidden="true" className="pointer-events-none absolute -top-40 -right-32 size-[520px] rounded-full bg-lavender opacity-70 blur-3xl" />
      <div aria-hidden="true" className="pointer-events-none absolute -bottom-48 -left-40 size-[480px] rounded-full bg-brand-100 opacity-60 blur-3xl" />
      <div className="relative z-10 px-4 pt-5 sm:px-8">
        <Link to="/" className="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-ink-muted hover:text-ink">
          <ArrowLeft className="size-4" aria-hidden="true" /> Retour au site
        </Link>
      </div>
      <main className="relative z-10 flex flex-1 items-center justify-center px-4 py-8 sm:py-12">
        <Suspense fallback={<PageLoader />}>
          <Outlet />
        </Suspense>
      </main>
    </div>
  )
}
