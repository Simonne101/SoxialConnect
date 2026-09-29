import type { LucideIcon } from "lucide-react"
import {
  ArrowRight,
  BarChart3,
  CalendarClock,
  Check,
  Clapperboard,
  Clock,
  KeyRound,
  Layers,
  Lock,
  PenLine,
  Send,
  ServerCog,
  ShieldCheck,
  Share2,
  Sparkles,
  Unplug,
} from "lucide-react"
import type { ReactNode } from "react"
import { Link } from "react-router"
import { LogoMark } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import {
  AIVisual,
  AnalyticsVisual,
  CalendarVisual,
  ComposerVisual,
  MediaVisual,
  PublishVisual,
} from "./FeatureVisuals"

function SectionHeading({ eyebrow, title, description, id }: { eyebrow: string; title: string; description?: string; id: string }) {
  return (
    <div className="mx-auto max-w-2xl text-center">
      <p className="text-sm font-semibold tracking-wide text-brand-600 uppercase">{eyebrow}</p>
      <h2 id={id} className="mt-3 text-3xl font-extrabold sm:text-4xl">
        {title}
      </h2>
      {description && <p className="mt-4 text-base text-ink-muted sm:text-lg">{description}</p>}
    </div>
  )
}

// --- Problème -------------------------------------------------------------------------------
const PAINS: { icon: LucideIcon; title: string; text: string }[] = [
  { icon: Layers, title: "Une application par réseau", text: "Vous passez de Facebook à TikTok, puis à LinkedIn… et vous recommencez chaque jour." },
  { icon: Clock, title: "Des heures perdues", text: "Chaque réseau impose ses formats, ses dimensions et ses limites de texte." },
  { icon: BarChart3, title: "Des chiffres éparpillés", text: "Impossible de savoir d'un coup d'œil ce qui fonctionne vraiment." },
]

export function ProblemSection() {
  return (
    <section aria-labelledby="probleme-title" className="bg-card py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading id="probleme-title" eyebrow="Le problème" title="Publier partout vous prend trop de temps ?" description="Gérer plusieurs réseaux sociaux ne devrait pas être un métier à plein temps." />
        <div className="mt-12 grid gap-5 md:grid-cols-3">
          {PAINS.map(({ icon: Icon, title, text }) => (
            <div key={title} className="rounded-2xl border border-border bg-page/60 p-6">
              <span className="inline-flex size-11 items-center justify-center rounded-xl bg-danger-50 text-danger-500" aria-hidden="true">
                <Icon className="size-5" />
              </span>
              <h3 className="mt-4 text-lg font-bold">{title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-ink-muted">{text}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

// --- Comment ça marche (reprend les 3 étapes de l'onboarding) ------------------------------
const STEPS = [
  { icon: Share2, title: "Connectez vos réseaux", text: "En quelques clics, grâce aux connexions officielles de chaque plateforme." },
  { icon: PenLine, title: "Créez une seule fois", text: "Écrivez votre message, ajoutez vos médias : SocialPulse l'adapte à chaque réseau." },
  { icon: Send, title: "Publiez et analysez", text: "Publiez tout de suite ou programmez, puis suivez vos résultats au même endroit." },
]

export function HowItWorksSection() {
  return (
    <section id="comment-ca-marche" aria-labelledby="how-title" className="py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading id="how-title" eyebrow="La solution" title="Trois étapes, et c'est tout" description="Pas besoin d'être expert : SocialPulse vous guide à chaque étape." />
        <ol className="mt-12 grid gap-5 md:grid-cols-3">
          {STEPS.map(({ icon: Icon, title, text }, i) => (
            <li key={title} className="surface-card relative p-6">
              <span className="absolute top-6 right-6 text-5xl font-extrabold text-brand-50" aria-hidden="true">
                {i + 1}
              </span>
              <span className="inline-flex size-11 items-center justify-center rounded-xl bg-brand-500 text-white shadow-brand" aria-hidden="true">
                <Icon className="size-5" />
              </span>
              <h3 className="mt-4 text-lg font-bold">
                <span className="sr-only">Étape {i + 1} : </span>
                {title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-ink-muted">{text}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

// --- Fonctionnalités ------------------------------------------------------------------------
interface Feature {
  id: string
  icon: LucideIcon
  eyebrow: string
  title: string
  text: string
  points: string[]
  visual: ReactNode
}

const FEATURES: Feature[] = [
  {
    id: "creation",
    icon: PenLine,
    eyebrow: "Création de contenu",
    title: "Un message, une version parfaite pour chaque réseau",
    text: "Rédigez une fois, puis ajustez la version de chaque plateforme : légende Instagram, titre YouTube, tableau Pinterest…",
    points: ["Aperçu fidèle par réseau", "Limites de caractères vérifiées", "Médias adaptés aux bons formats"],
    visual: <ComposerVisual />,
  },
  {
    id: "planification",
    icon: CalendarClock,
    eyebrow: "Planification",
    title: "Votre calendrier éditorial, enfin clair",
    text: "Visualisez toutes vos publications par mois, semaine ou jour, et programmez à l'heure qui vous convient.",
    points: ["Vues mois, semaine, jour et liste", "Filtres par réseau et par statut", "Rappels et alertes en cas de problème"],
    visual: <CalendarVisual />,
  },
  {
    id: "publication",
    icon: Send,
    eyebrow: "Publication",
    title: "Publiez partout, en un clic",
    text: "SocialPulse publie sur chaque réseau et vous indique clairement ce qui est publié, en cours ou à corriger.",
    points: ["Statut par réseau en temps réel", "Relance simple en cas d'échec", "Aucune publication en double"],
    visual: <PublishVisual />,
  },
  {
    id: "medias",
    icon: Clapperboard,
    eyebrow: "Médias & Video Studio",
    title: "Vos médias rangés, vos vidéos découpées en clips",
    text: "Retrouvez photos et vidéos en un instant, et transformez une longue vidéo en clips courts prêts à publier.",
    points: ["Dossiers, tags et recherche", "Détection des meilleurs passages", "Formats 9:16, 1:1 et 16:9 avec sous-titres"],
    visual: <MediaVisual />,
  },
  {
    id: "ia",
    icon: Sparkles,
    eyebrow: "Assistant IA",
    title: "Ne restez plus jamais devant une page blanche",
    text: "Générez des légendes, des idées, des hashtags ou des scripts vidéo, dans le ton qui vous ressemble.",
    points: ["Légendes et posts complets", "Reformulation et adaptation du ton", "Idées de contenus et scripts"],
    visual: <AIVisual />,
  },
  {
    id: "analytics",
    icon: BarChart3,
    eyebrow: "Analytics",
    title: "Comprenez ce qui fonctionne vraiment",
    text: "Impressions, portée, engagement, vues, abonnés : tous vos chiffres réunis et comparables d'un réseau à l'autre.",
    points: ["Évolution sur la période", "Top contenus", "Comparaison des plateformes"],
    visual: <AnalyticsVisual />,
  },
]

export function FeaturesSection() {
  return (
    <section id="fonctionnalites" aria-labelledby="features-title" className="bg-card py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading id="features-title" eyebrow="Fonctionnalités" title="Tout ce qu'il faut, rien de compliqué" description="Chaque outil a été pensé pour être compris dès la première utilisation." />
        <div className="mt-16 space-y-20 sm:space-y-24">
          {FEATURES.map((f, i) => {
            const Icon = f.icon
            return (
              <article key={f.id} id={f.id} aria-labelledby={`${f.id}-title`} className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                <div className={cn(i % 2 === 1 && "lg:order-2")}>
                  <p className="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-700">
                    <Icon className="size-4" aria-hidden="true" /> {f.eyebrow}
                  </p>
                  <h3 id={`${f.id}-title`} className="mt-4 text-2xl font-extrabold sm:text-3xl">
                    {f.title}
                  </h3>
                  <p className="mt-4 text-base leading-relaxed text-ink-muted">{f.text}</p>
                  <ul className="mt-6 space-y-3">
                    {f.points.map((p) => (
                      <li key={p} className="flex items-start gap-3 text-sm font-medium text-ink-soft">
                        <span className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-700" aria-hidden="true">
                          <Check className="size-3.5" />
                        </span>
                        {p}
                      </li>
                    ))}
                  </ul>
                </div>
                <div className={cn("relative mx-auto w-full max-w-lg", i % 2 === 1 && "lg:order-1")}>
                  <div aria-hidden="true" className="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-brand-50 to-violet-50" />
                  <div className="relative">{f.visual}</div>
                </div>
              </article>
            )
          })}
        </div>
      </div>
    </section>
  )
}

// --- Sécurité -------------------------------------------------------------------------------
const SECURITY: { icon: LucideIcon; title: string; text: string }[] = [
  { icon: ShieldCheck, title: "Connexions officielles", text: "Nous utilisons uniquement les connexions officielles des réseaux sociaux. Votre mot de passe de réseau ne nous est jamais communiqué." },
  { icon: KeyRound, title: "Autorisations chiffrées", text: "Les autorisations d'accès à vos comptes sont chiffrées et ne quittent jamais nos serveurs." },
  { icon: Unplug, title: "Vous gardez le contrôle", text: "Déconnectez un réseau à tout moment : l'accès est immédiatement retiré." },
  { icon: ServerCog, title: "Médias privés", text: "Vos fichiers sont stockés de façon privée et vérifiés avant tout traitement." },
]

export function SecuritySection() {
  return (
    <section id="securite" aria-labelledby="security-title" className="py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading id="security-title" eyebrow="Sécurité" title="Vos comptes entre de bonnes mains" description="La sécurité de vos réseaux sociaux est au cœur de SocialPulse." />
        <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {SECURITY.map(({ icon: Icon, title, text }) => (
            <div key={title} className="surface-card p-6">
              <span className="inline-flex size-11 items-center justify-center rounded-xl bg-success-50 text-success-700" aria-hidden="true">
                <Icon className="size-5" />
              </span>
              <h3 className="mt-4 text-base font-bold">{title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-ink-muted">{text}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

// --- Tarifs (lien « Tarifs » de la maquette ; la V1 n'est pas un SaaS — voir rapport UX-01) ---
export function PricingSection() {
  return (
    <section id="tarifs" aria-labelledby="pricing-title" className="bg-card py-20 sm:py-24">
      <div className="mx-auto max-w-3xl px-4 text-center sm:px-6">
        <SectionHeading id="pricing-title" eyebrow="Tarifs" title="Les offres arrivent bientôt" description="SocialPulse est en version 1. Créez votre compte dès maintenant pour découvrir toutes les fonctionnalités." />
        <div className="mt-8 flex justify-center">
          <Button size="lg" asChild>
            <Link to="/register">
              Créer mon compte <ArrowRight />
            </Link>
          </Button>
        </div>
      </div>
    </section>
  )
}

// --- FAQ ------------------------------------------------------------------------------------
const FAQ = [
  { q: "Faut-il des compétences techniques ?", a: "Non. SocialPulse a été conçu pour être utilisé sans connaissances techniques : chaque écran vous guide pas à pas." },
  { q: "Quels réseaux sont pris en charge ?", a: "Facebook, Instagram, TikTok, YouTube, LinkedIn, X et Pinterest. Threads arrive bientôt, et d'autres plateformes suivront." },
  { q: "Puis-je publier sur mon profil Facebook personnel ?", a: "Les réseaux ne l'autorisent pas toujours : Facebook permet de publier sur une Page, et Instagram demande un compte professionnel (gratuit)." },
  { q: "Puis-je déconnecter un réseau ?", a: "Oui, à tout moment depuis la page « Réseaux sociaux ». L'accès est retiré immédiatement." },
]

export function FaqSection() {
  return (
    <section id="faq" aria-labelledby="faq-title" className="py-20 sm:py-24">
      <div className="mx-auto max-w-3xl px-4 sm:px-6">
        <SectionHeading id="faq-title" eyebrow="Questions fréquentes" title="Vous avez des questions ?" />
        <div className="mt-10 space-y-3">
          {FAQ.map((item) => (
            <details key={item.q} className="group surface-card p-5 [&_summary::-webkit-details-marker]:hidden">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold text-ink">
                {item.q}
                <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 transition-transform group-open:rotate-45" aria-hidden="true">
                  +
                </span>
              </summary>
              <p className="mt-3 text-sm leading-relaxed text-ink-muted">{item.a}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  )
}

// --- CTA ------------------------------------------------------------------------------------
export function CtaSection() {
  return (
    <section aria-labelledby="cta-title" className="px-4 pb-20 sm:px-6 sm:pb-24 lg:px-8">
      <div className="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-brand-gradient px-6 py-14 text-center shadow-brand sm:px-12">
        <div aria-hidden="true" className="absolute -top-24 -right-24 size-72 rounded-full bg-white/10" />
        <div aria-hidden="true" className="absolute -bottom-28 -left-16 size-72 rounded-full bg-white/10" />
        <h2 id="cta-title" className="relative text-3xl font-extrabold text-white sm:text-4xl">
          Prêt à gagner du temps sur vos réseaux ?
        </h2>
        <p className="relative mx-auto mt-4 max-w-xl text-brand-50">
          Une seule plateforme. Tous vos réseaux. Tout votre contenu. Tout votre potentiel.
        </p>
        <div className="relative mt-8 flex flex-col justify-center gap-3 sm:flex-row">
          <Button size="lg" asChild className="bg-white text-brand-700 shadow-none hover:bg-brand-50">
            <Link to="/register">Inscription</Link>
          </Button>
          <Button size="lg" variant="outline" asChild className="border-white/40 bg-transparent text-white hover:border-white hover:bg-white/10">
            <Link to="/dashboard">Voir la démo</Link>
          </Button>
        </div>
      </div>
    </section>
  )
}

// --- Footer ---------------------------------------------------------------------------------
const YEAR = new Date().getFullYear()
export function LandingFooter() {
  const cols = [
    { title: "Produit", links: [["Fonctionnalités", "#fonctionnalites"], ["Tarifs", "#tarifs"], ["Démo", "/dashboard"]] },
    { title: "Ressources", links: [["Comment ça marche", "#comment-ca-marche"], ["Questions fréquentes", "#faq"], ["Design system", "/design-system"]] },
    { title: "Légal", links: [["Confidentialité", "/legal/privacy"], ["Conditions d'utilisation", "/legal/terms"], ["Suppression des données", "/legal/data-deletion"]] },
  ]
  return (
    <footer className="border-t border-border bg-card">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_repeat(3,1fr)] lg:px-8">
        <div>
          <div className="flex items-center gap-2">
            <LogoMark />
            <span className="text-lg font-bold text-ink">SocialPulse</span>
          </div>
          <p className="mt-4 max-w-xs text-sm text-ink-muted">Gérez tous vos réseaux sociaux depuis une seule plateforme.</p>
          <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-ink-muted">
            <Lock className="size-3.5" aria-hidden="true" /> Connexions officielles uniquement
          </p>
        </div>
        {cols.map((c) => (
          <nav key={c.title} aria-label={c.title}>
            <h2 className="text-sm font-semibold text-ink">{c.title}</h2>
            <ul className="mt-4 space-y-2.5">
              {c.links.map(([label, href]) => (
                <li key={label}>
                  {href.startsWith("#") ? (
                    <a href={href} className="text-sm text-ink-muted hover:text-brand-600">{label}</a>
                  ) : (
                    <Link to={href} className="text-sm text-ink-muted hover:text-brand-600">{label}</Link>
                  )}
                </li>
              ))}
            </ul>
          </nav>
        ))}
      </div>
      <div className="border-t border-border py-6 text-center text-xs text-ink-muted">
        © {YEAR} SocialPulse. Tous droits réservés.
      </div>
    </footer>
  )
}
