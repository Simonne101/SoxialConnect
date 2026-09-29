import { ArrowRight, CheckCircle2, PlayCircle } from "lucide-react"
import { Link } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { Button } from "@/components/ui/button"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { HERO_PNG, HERO_SIZES, HERO_SRCSET } from "./heroImage"

// TODO(UX-02) : « 10k+ » et « 99 % » viennent de la maquette et sont fictifs —
// à remplacer par des chiffres réels (ou à retirer) avant toute mise en ligne publique.
const STATS = [
  { value: "8+", label: "réseaux sociaux" },
  { value: "10k+", label: "créateurs & entreprises" },
  { value: "99%", label: "de satisfaction" },
]

export function Hero() {
  return (
    <section id="accueil" aria-labelledby="hero-title" className="relative overflow-hidden pt-(--landing-nav-h)">
      {/* Formes décoratives lavande */}
      <div aria-hidden="true" className="pointer-events-none absolute top-24 right-[-10%] size-[640px] rounded-full bg-lavender/70 blur-3xl" />
      <div aria-hidden="true" className="pointer-events-none absolute -bottom-40 left-[-15%] size-[520px] rounded-full bg-brand-100/60 blur-3xl" />

      <div className="relative mx-auto grid max-w-7xl items-center gap-10 px-4 pt-10 pb-16 sm:px-6 md:pt-14 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.08fr)] lg:gap-6 lg:px-8 lg:pt-16 lg:pb-24">
        {/* Colonne texte */}
        <div className="max-w-xl text-center lg:text-left">
          <h1 id="hero-title" className="text-[34px] leading-[1.12] font-extrabold tracking-tight sm:text-5xl lg:text-[54px]">
            Gérez tous vos <span className="text-brand-gradient">réseaux sociaux</span> depuis une seule plateforme
          </h1>
          <p className="mx-auto mt-5 max-w-lg text-base leading-relaxed text-ink-muted sm:text-lg lg:mx-0">
            Créez, planifiez, publiez et analysez votre contenu sur tous vos réseaux sociaux. Gagnez du temps et
            développez votre audience avec SocialPulse.
          </p>

          <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
            <Button size="lg" asChild>
              <Link to="/register">
                Commencer gratuitement <ArrowRight />
              </Link>
            </Button>
            <Button size="lg" variant="outline" asChild>
              <Link to="/dashboard">
                <PlayCircle /> Voir la démo
              </Link>
            </Button>
          </div>

          <div className="mt-8">
            <ul className="flex flex-wrap items-center justify-center gap-2.5 lg:justify-start" aria-label="Réseaux pris en charge">
              {PLATFORM_ORDER.map((p) => (
                <li key={p}>
                  <PlatformIcon platform={p} size="sm" title={PLATFORMS[p].name} className="shadow-card ring-2 ring-white" />
                </li>
              ))}
            </ul>
            <p className="mt-3 text-sm text-ink-muted">+ d'autres plateformes bientôt</p>
          </div>

          <dl className="mt-10 grid grid-cols-3 gap-4 border-t border-border pt-8 text-left">
            {STATS.map((s) => (
              <div key={s.label}>
                <dt className="sr-only">{s.label}</dt>
                <dd className="text-2xl font-extrabold text-ink-soft sm:text-3xl">{s.value}</dd>
                <dd className="mt-1 text-xs text-ink-muted sm:text-sm">{s.label}</dd>
              </div>
            ))}
          </dl>
        </div>

        {/* Visuel : Images/Image sect-hero.png (versions WebP optimisées + PNG d'origine en repli) */}
        <div className="relative mx-auto w-full max-w-[680px] lg:max-w-none">
          <div aria-hidden="true" className="absolute inset-[6%] rounded-[42%_58%_55%_45%/50%_45%_55%_50%] bg-gradient-to-br from-lavender via-brand-100 to-violet-100" />
          <picture>
            <source type="image/webp" srcSet={HERO_SRCSET} sizes={HERO_SIZES} />
            <img
              src={HERO_PNG}
              width={1520}
              height={1035}
              alt="Une créatrice de contenu souriante travaille sur son ordinateur, entourée des logos des réseaux sociaux et de statistiques d'audience en hausse."
              fetchPriority="high"
              decoding="async"
              className="relative h-auto w-full rounded-[28px] object-contain hero-mask"
            />
          </picture>

          <div className="absolute bottom-[6%] left-[2%] hidden animate-float items-center gap-3 rounded-2xl border border-border bg-card/95 px-4 py-3 shadow-float backdrop-blur sm:flex">
            <span className="inline-flex size-9 items-center justify-center rounded-full bg-success-50 text-success-500" aria-hidden="true">
              <CheckCircle2 className="size-5" />
            </span>
            <span className="text-sm leading-tight">
              <span className="block font-semibold text-ink">Votre contenu</span>
              <span className="text-ink-muted">est publié !</span>
            </span>
          </div>

          <p className="relative mt-2 text-right font-script text-[26px] leading-tight text-ink-soft sm:text-3xl" aria-hidden="true">
            Créez. Planifiez. Publiez.
            <br />
            Analysez. Grandissez.
          </p>
        </div>
      </div>
    </section>
  )
}
