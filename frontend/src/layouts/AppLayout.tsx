import { Menu, Plus } from "lucide-react"
import { Suspense, useEffect, useState } from "react"
import { Link, Outlet, useLocation } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { Sidebar } from "@/components/navigation/Sidebar"
import { NotificationBell } from "@/components/notifications/NotificationBell"
import { PageLoader } from "@/components/shared/PageLoader"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet"
import { RequireAuth } from "./RequireAuth"

/**
 * Layout de l'application connectée.
 * ≥ 1024 px : barre latérale complète (maquette) · 768–1023 px : rail d'icônes ·
 * < 768 px : barre supérieure + tiroir de navigation.
 */
export function AppLayout() {
  return (
    <RequireAuth>
      <AppShell />
    </RequireAuth>
  )
}

function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false)
  const { pathname } = useLocation()

  // Remonte en haut à chaque changement de page (le contenu défile dans <main>).
  useEffect(() => {
    document.getElementById("main-content")?.scrollTo({ top: 0 })
    window.scrollTo({ top: 0 })
  }, [pathname])

  return (
    <div className="min-h-dvh bg-page">
      <a href="#main-content" className="sr-only z-50 rounded-lg bg-card px-4 py-2 font-medium text-ink shadow-float focus:not-sr-only focus:fixed focus:top-3 focus:left-3">
        Aller au contenu
      </a>

      {/* Barre latérale fixe — desktop et tablette */}
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-[84px] md:block lg:w-64" aria-label="Barre latérale">
        <div className="h-full lg:hidden">
          <Sidebar mode="rail" />
        </div>
        <div className="hidden h-full lg:block">
          <Sidebar mode="full" />
        </div>
      </aside>

      {/* Barre supérieure — mobile */}
      <header className="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-border bg-card/95 px-4 backdrop-blur md:hidden">
        <Button variant="ghost" size="icon" aria-label="Ouvrir le menu" onClick={() => setMobileOpen(true)}>
          <Menu className="size-5" />
        </Button>
        <Link to="/dashboard" aria-label="Tableau de bord" className="mr-auto">
          <Logo />
        </Link>
        <NotificationBell className="border-transparent bg-transparent shadow-none" />
        <Button size="icon-sm" asChild>
          <Link to="/content/create" aria-label="Créer une publication">
            <Plus />
          </Link>
        </Button>
      </header>

      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-[290px] max-w-[85vw] border-none bg-sidebar p-0 text-sidebar-foreground" showCloseButton={false}>
          <SheetTitle className="sr-only">Menu</SheetTitle>
          <SheetDescription className="sr-only">Navigation principale de SocialPulse</SheetDescription>
          <Sidebar mode="full" onNavigate={() => setMobileOpen(false)} />
        </SheetContent>
      </Sheet>

      <main id="main-content" tabIndex={-1} className="outline-none md:pl-[84px] lg:pl-64">
        <div className="mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <Suspense fallback={<PageLoader />}>
            <Outlet />
          </Suspense>
        </div>
      </main>
    </div>
  )
}
