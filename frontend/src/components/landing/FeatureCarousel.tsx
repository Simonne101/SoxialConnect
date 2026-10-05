import { ChevronLeft, ChevronRight } from "lucide-react"
import { type FocusEvent, type KeyboardEvent, useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { FeatureCard } from "./FeatureCard"
import type { FeatureItem } from "./features"

/** Mesures du défilement, recalculées au redimensionnement et pendant le défilement. */
interface Layout {
  /** Largeur d'une carte + espacement (px). */
  step: number
  /** Cartes visibles simultanément (3 desktop, 2 tablette, 1 mobile). */
  perView: number
  pages: number
  page: number
}

const REDUCED_MOTION = "(prefers-reduced-motion: reduce)"
const prefersReducedMotion = () => typeof window !== "undefined" && window.matchMedia(REDUCED_MOTION).matches

/** Défilement automatique : une carte toutes les 6 s ; reprise 6 s après une action manuelle. */
const AUTOPLAY_MS = 6000
const RESUME_AFTER_MS = 6000

/**
 * Carrousel horizontal natif (défilement CSS scroll-snap) : swipe tactile, défilement fluide,
 * flèches clavier, boutons précédent / suivant et indicateurs de page.
 * Défilement automatique permanent : une carte toutes les 6 s, retour à la première après la
 * dernière. Il attend 6 s après une action manuelle (glisser, molette, flèches, indicateurs) et
 * s'arrête seulement hors de l'écran, onglet masqué ou pendant la navigation au clavier.
 * « Réduire les animations » : le défilement continue, sans effet de glissement.
 * Le défilement est contenu dans la liste : jamais de défilement horizontal de la page.
 */
export function FeatureCarousel({ items, label }: { items: FeatureItem[]; label: string }) {
  const scroller = useRef<HTMLUListElement>(null)
  const frame = useRef(0)
  const [layout, setLayout] = useState<Layout>({ step: 0, perView: 1, pages: items.length, page: 0 })
  // Défilement automatique : conditions de pause (hors écran, clavier, action manuelle récente).
  const [keyboardFocus, setKeyboardFocus] = useState(false)
  const [onScreen, setOnScreen] = useState(false)
  const [holdUntil, setHoldUntil] = useState(0)
  const root = useRef<HTMLDivElement>(null)

  const measure = () => {
    const el = scroller.current
    const cards = el?.children
    if (!el || !cards || cards.length === 0) return
    const first = cards[0] as HTMLElement
    const step = cards.length > 1 ? (cards[1] as HTMLElement).offsetLeft - first.offsetLeft : first.offsetWidth
    if (step <= 0) return
    const perView = Math.max(1, Math.round(el.clientWidth / step))
    const pages = Math.max(1, Math.ceil(cards.length / perView))
    const maxScroll = el.scrollWidth - el.clientWidth
    const page = el.scrollLeft >= maxScroll - 2 ? pages - 1 : Math.min(pages - 1, Math.round(el.scrollLeft / (perView * step)))
    setLayout((prev) =>
      prev.step === step && prev.perView === perView && prev.pages === pages && prev.page === page ? prev : { step, perView, pages, page },
    )
  }

  // Redimensionnement : ResizeObserver déclenche aussi la première mesure (asynchrone).
  useEffect(() => {
    const el = scroller.current
    if (!el) return
    const observer = new ResizeObserver(() => measure())
    observer.observe(el)
    return () => {
      observer.disconnect()
      cancelAnimationFrame(frame.current)
    }
  }, [])

  // Visible à l'écran et onglet actif : sinon aucune avance automatique.
  useEffect(() => {
    const el = root.current
    if (!el) return
    let inView = false
    const update = () => setOnScreen(inView && document.visibilityState === "visible")
    const observer = new IntersectionObserver(([entry]) => {
      inView = entry.isIntersecting
      update()
    }, { threshold: 0.35 })
    observer.observe(el)
    document.addEventListener("visibilitychange", update)
    return () => {
      observer.disconnect()
      document.removeEventListener("visibilitychange", update)
    }
  }, [])

  const { page, pages } = layout
  const running = !keyboardFocus && onScreen && pages > 1

  // Carte suivante toutes les AUTOPLAY_MS ; après la dernière, retour à la première.
  // Mesures lues dans le DOM à chaque avance : toujours justes après un redimensionnement.
  useEffect(() => {
    if (!running) return
    const advance = () => {
      const el = scroller.current
      const cards = el?.children
      if (!el || !cards || cards.length < 2) return
      const step = (cards[1] as HTMLElement).offsetLeft - (cards[0] as HTMLElement).offsetLeft
      const max = el.scrollWidth - el.clientWidth
      const left = el.scrollLeft >= max - 2 ? 0 : Math.min(el.scrollLeft + step, max)
      el.scrollTo({ left, behavior: prefersReducedMotion() ? "auto" : "smooth" })
    }
    let interval = 0
    const start = window.setTimeout(() => {
      advance()
      interval = window.setInterval(advance, AUTOPLAY_MS)
    }, Math.max(AUTOPLAY_MS, holdUntil - Date.now()))
    return () => {
      window.clearTimeout(start)
      window.clearInterval(interval)
    }
  }, [running, holdUntil])

  /** Action manuelle : la lecture automatique attend avant de reprendre. */
  const hold = () => setHoldUntil(Date.now() + RESUME_AFTER_MS)

  const onFocus = (e: FocusEvent<HTMLDivElement>) => {
    if (e.target.matches(":focus-visible")) setKeyboardFocus(true)
  }
  const onBlur = (e: FocusEvent<HTMLDivElement>) => {
    if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setKeyboardFocus(false)
  }

  const onScroll = () => {
    cancelAnimationFrame(frame.current)
    frame.current = requestAnimationFrame(measure)
  }

  const goTo = (target: number) => {
    const el = scroller.current
    if (!el) return
    const page = Math.min(Math.max(target, 0), layout.pages - 1)
    const left = Math.min(page * layout.perView * layout.step, el.scrollWidth - el.clientWidth)
    el.scrollTo({ left, behavior: prefersReducedMotion() ? "auto" : "smooth" })
  }

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const keys: Record<string, number> = { ArrowLeft: layout.page - 1, ArrowRight: layout.page + 1, Home: 0, End: layout.pages - 1 }
    if (e.key in keys) {
      e.preventDefault()
      hold()
      goTo(keys[e.key])
    }
  }

  return (
    <div
      ref={root}
      onFocus={onFocus}
      onBlur={onBlur}
    >
      <div
        role="region"
        aria-roledescription="carrousel"
        aria-label={label}
        tabIndex={0}
        onKeyDown={onKeyDown}
        className="rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4"
      >
        <ul
          ref={scroller}
          onScroll={onScroll}
          onPointerDown={hold}
          onWheel={hold}
          className={cn(
            // Débord jusqu'aux bords sur mobile (carte suivante visible), aligné sur la gouttière.
            "-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-5 overflow-x-auto px-4 py-3 sm:mx-0 sm:scroll-px-0 sm:px-0",
            "[scrollbar-width:none] [&::-webkit-scrollbar]:hidden",
          )}
        >
          {items.map((feature, i) => (
            <li
              key={feature.title}
              role="group"
              aria-roledescription="diapositive"
              aria-label={`${i + 1} sur ${items.length} : ${feature.title}`}
              className="shrink-0 basis-[85%] snap-start sm:basis-[calc((100%_-_1.25rem)/2)] lg:basis-[calc((100%_-_2.5rem)/3)]"
            >
              <FeatureCard feature={feature} />
            </li>
          ))}
        </ul>
      </div>

      <div className="mt-6 flex items-center justify-between gap-4">
        <div className="flex items-center gap-1" role="group" aria-label="Pages du carrousel">
          {Array.from({ length: pages }, (_, i) => (
            <button
              key={i}
              type="button"
              onClick={() => {
                hold()
                goTo(i)
              }}
              aria-label={`Afficher la page ${i + 1} sur ${pages}`}
              aria-current={i === page ? "true" : undefined}
              className="group inline-flex size-6 items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span
                aria-hidden="true"
                className={cn(
                  "h-2 rounded-full transition-all motion-reduce:transition-none",
                  i === page ? "w-6 bg-brand-500" : "w-2 bg-brand-200 group-hover:bg-brand-300",
                )}
              />
            </button>
          ))}
        </div>
        {/* Annonce désactivée pendant la lecture automatique (pas d'annonce toutes les 5 s). */}
        <p className="sr-only" aria-live={running ? "off" : "polite"}>
          Page {page + 1} sur {pages}
        </p>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" onClick={() => { hold(); goTo(page - 1) }} disabled={page <= 0} aria-label="Fonctionnalités précédentes">
            <ChevronLeft />
          </Button>
          <Button variant="outline" size="icon" onClick={() => { hold(); goTo(page + 1) }} disabled={page >= pages - 1} aria-label="Fonctionnalités suivantes">
            <ChevronRight />
          </Button>
        </div>
      </div>
    </div>
  )
}
