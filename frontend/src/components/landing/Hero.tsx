import { ArrowDown, ArrowRight, CalendarCheck2, Sparkles } from "lucide-react";
import { Link } from "react-router";
import { Button } from "@/components/ui/button";
import { HERO_PNG, HERO_SIZES, HERO_SRCSET } from "./heroImage";

export function Hero() {
  return (
    <section
      id="accueil"
      aria-labelledby="hero-title"
      className="landing-hero relative overflow-hidden pt-(--landing-nav-h)"
    >
      <div className="landing-orbit" aria-hidden="true" />
      <div className="relative mx-auto grid max-w-7xl items-center gap-8 px-5 pt-12 pb-14 sm:px-6 sm:pt-16 lg:grid-cols-[1fr_1.05fr] lg:gap-3 lg:px-8 lg:pt-20 lg:pb-20">
        <div className="hero-enter relative z-10 mx-auto max-w-xl text-center lg:text-left">
          <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white/80 px-3.5 py-2 text-xs font-semibold text-brand-700 sm:text-sm">
            <Sparkles className="size-4" aria-hidden="true" /> Votre créativité.
            Sans les limites.
          </p>
          <h1
            id="hero-title"
            className="text-[clamp(2.5rem,4.6vw,4.25rem)] leading-[1.06] font-extrabold tracking-[-0.055em]"
          >
            De grandes idées.
            <br />
            Des réseaux vivants.
            <br />
            <span className="hero-highlight">Un seul espace.</span>
          </h1>
          <p className="mx-auto mt-6 max-w-md text-base leading-relaxed text-ink-muted sm:text-lg lg:mx-0">
            Créez, planifiez et publiez sur vos réseaux sociaux avec
            SoxialConnect. Moins d’allers-retours. Plus de place pour ce que
            vous avez à raconter.
          </p>
          <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
            <Button size="lg" asChild className="h-12 rounded-full px-6">
              <Link to="/register">
                Commencer gratuitement <ArrowRight aria-hidden="true" />
              </Link>
            </Button>
            <Button
              size="lg"
              variant="outline"
              asChild
              className="h-12 rounded-full border-brand-200 bg-white/70 px-6"
            >
              <a href="#apercu">
                Explorer la plateforme <ArrowDown aria-hidden="true" />
              </a>
            </Button>
          </div>
          <p className="mt-5 text-xs leading-relaxed text-ink-muted sm:text-sm">
            Création · Programmation · Vidéo · Analyse
          </p>
        </div>
        <div className="hero-enter hero-enter-later relative mx-auto w-full max-w-[680px]">
          <div data-loop className="hero-image-halo" aria-hidden="true" />
          <picture>
            <source type="image/webp" srcSet={HERO_SRCSET} sizes={HERO_SIZES} />
            <img
              src={HERO_PNG}
              width={1520}
              height={1035}
              alt="Une créatrice prépare ses publications, entourée des réseaux sociaux réunis dans SoxialConnect."
              fetchPriority="high"
              decoding="async"
              className="hero-mask relative h-auto w-full rounded-[28px] object-contain"
            />
          </picture>
          <div
            data-loop
            className="hero-note absolute bottom-16 left-2 flex items-center gap-3 rounded-2xl border border-white bg-white/95 px-4 py-3 shadow-float sm:bottom-20 sm:left-5"
          >
            <span className="grid size-10 place-items-center rounded-xl bg-success-50 text-success-700">
              <CalendarCheck2 className="size-5" aria-hidden="true" />
            </span>
            <span className="text-sm">
              <strong className="block text-ink">
                Une semaine bien organisée.
              </strong>
              <span className="text-ink-muted">L’esprit libre pour créer.</span>
            </span>
          </div>
          <p
            className="mt-9 text-right font-script text-3xl text-brand-700 sm:mt-5 sm:text-4xl"
            aria-hidden="true"
          >
            Vos idées méritent d’être vues.
          </p>
        </div>
      </div>
    </section>
  );
}
