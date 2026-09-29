import { ChevronDown, Menu } from "lucide-react"
import { useEffect, useState } from "react"
import { Link } from "react-router"
import { LogoMark } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet"
import { cn } from "@/lib/utils"

const LINKS = [
  { href: "#accueil", label: "Accueil" },
  { href: "#fonctionnalites", label: "Fonctionnalités" },
  { href: "#tarifs", label: "Tarifs" },
]

const RESOURCES = [
  { href: "#comment-ca-marche", label: "Comment ça marche" },
  { href: "#securite", label: "Sécurité" },
  { href: "#faq", label: "Questions fréquentes" },
]

/**
 * Navbar FIXE de la landing (règle non négociable) : position fixed + z-50,
 * toujours visible au scroll ; « Connexion » et « Inscription » restent dans la barre
 * sur toutes les largeurs (y compris 390 px). Le contenu est décalé de --landing-nav-h.
 */
export function LandingNavbar() {
  const [scrolled, setScrolled] = useState(false)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener("scroll", onScroll, { passive: true })
    return () => window.removeEventListener("scroll", onScroll)
  }, [])

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 h-(--landing-nav-h) border-b transition-[background-color,box-shadow,border-color] duration-200",
        scrolled ? "border-border bg-card/90 shadow-card backdrop-blur-md" : "border-transparent bg-landing/80 backdrop-blur-sm",
      )}
    >
      <div className="mx-auto flex h-full max-w-7xl items-center gap-2 px-4 sm:px-6 lg:px-8">
        <a href="#accueil" className="flex shrink-0 items-center gap-2 rounded-lg" aria-label="SoxialConnect — Accueil">
          <LogoMark />
          <span className="hidden text-lg font-bold tracking-tight text-ink min-[440px]:inline">SoxialConnect</span>
        </a>

        <nav aria-label="Navigation du site" className="ml-10 hidden items-center gap-1 lg:flex">
          {LINKS.map((l) => (
            <a key={l.href} href={l.href} className="rounded-lg px-3 py-2 text-sm font-medium text-ink-soft transition-colors hover:bg-brand-50 hover:text-brand-600">
              {l.label}
            </a>
          ))}
          <DropdownMenu>
            <DropdownMenuTrigger className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-ink-soft outline-none hover:bg-brand-50 hover:text-brand-600 focus-visible:ring-2 focus-visible:ring-ring">
              Ressources <ChevronDown className="size-4" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              {RESOURCES.map((r) => (
                <DropdownMenuItem key={r.href} asChild>
                  <a href={r.href}>{r.label}</a>
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </nav>

        {/* Connexion / Inscription : toujours visibles, jamais masqués au scroll */}
        <div className="ml-auto flex items-center gap-1.5 sm:gap-3">
          <Button variant="ghost" size="sm" asChild className="px-2.5 text-brand-600 hover:bg-brand-50 hover:text-brand-700 sm:px-3">
            <Link to="/login">Connexion</Link>
          </Button>
          <Button size="sm" asChild className="px-3 sm:h-10 sm:px-5">
            <Link to="/register">Inscription</Link>
          </Button>
          <Button variant="ghost" size="icon-sm" className="lg:hidden" aria-label="Ouvrir le menu" aria-expanded={open} onClick={() => setOpen(true)}>
            <Menu className="size-5" />
          </Button>
        </div>
      </div>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="w-[300px] max-w-[85vw] p-6">
          <SheetTitle className="text-left text-base">Menu</SheetTitle>
          <SheetDescription className="sr-only">Liens du site SoxialConnect</SheetDescription>
          <nav aria-label="Navigation du site (mobile)" className="mt-2 flex flex-col gap-1">
            {[...LINKS, ...RESOURCES].map((l) => (
              <a key={l.href} href={l.href} onClick={() => setOpen(false)} className="rounded-lg px-3 py-3 text-[15px] font-medium text-ink hover:bg-brand-50">
                {l.label}
              </a>
            ))}
          </nav>
          <div className="mt-auto flex flex-col gap-2">
            <Button variant="outline" asChild>
              <Link to="/login">Connexion</Link>
            </Button>
            <Button asChild>
              <Link to="/register">Inscription</Link>
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </header>
  )
}
