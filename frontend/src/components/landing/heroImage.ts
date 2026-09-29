// Visuel du hero : Images/Image sect-hero.png (PNG d'origine en repli + versions WebP optimisées).
import hero640 from "@/assets/images/hero-640.webp"
import hero1024 from "@/assets/images/hero-1024.webp"
import hero1520 from "@/assets/images/hero-1520.webp"
import heroPng from "@/assets/images/hero-original.png"

export const HERO_PNG = heroPng
export const HERO_SRCSET = `${hero640} 640w, ${hero1024} 1024w, ${hero1520} 1520w`
export const HERO_SIZES = "(min-width: 1024px) 52vw, (min-width: 640px) 680px, 100vw"

/**
 * Précharge le visuel (élément LCP de la landing) dès le démarrage de l'application,
 * sans attendre le chargement du chunk de la page. Pas de script inline (compatible CSP).
 */
export function preloadHeroImage() {
  if (document.querySelector("link[data-hero-preload]")) return
  const link = document.createElement("link")
  link.rel = "preload"
  link.as = "image"
  link.type = "image/webp"
  link.setAttribute("imagesrcset", HERO_SRCSET)
  link.setAttribute("imagesizes", HERO_SIZES)
  link.setAttribute("fetchpriority", "high")
  link.dataset.heroPreload = ""
  document.head.appendChild(link)
}
