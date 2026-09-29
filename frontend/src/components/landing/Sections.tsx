import type { LucideIcon } from "lucide-react";
import {
  ArrowRight,
  CheckCircle2,
  BarChart3,
  Clock,
  KeyRound,
  Layers,
  Lock,
  PenLine,
  Send,
  Share2,
  ShieldCheck,
  Unplug,
} from "lucide-react";
import type { ReactNode } from "react";
import { Link } from "react-router";
import { LogoMark } from "@/components/brand/Logo";
import { FeatureCarousel } from "./FeatureCarousel";
import { FEATURES } from "./features";
import {
  WHY_MOBILE_SIZE,
  WHY_MOBILE_SRC,
  WHY_SIZE,
  WHY_SIZES,
  WHY_SRC,
  WHY_SRCSET,
} from "./whyImage";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  AIVisual,
  AnalyticsVisual,
  CalendarVisual,
  ComposerVisual,
  MediaVisual,
  PublishVisual,
} from "./FeatureVisuals";

/*
 * Page d'accueil — structure : Hero → Problème / valeur → Fonctionnalités → Comment ça fonctionne
 * (+ confiance, questions fréquentes) → Aperçu de la plateforme → CTA final (+ tarifs) → Footer.
 * Les ancres du menu (#fonctionnalites, #tarifs, #comment-ca-marche, #securite, #faq) restent valides.
 */

function SectionHeading({
  eyebrow,
  title,
  description,
  id,
}: {
  eyebrow: string;
  title: string;
  description?: string;
  id: string;
}) {
  return (
    <div className="mx-auto max-w-2xl text-center">
      <p className="text-sm font-semibold tracking-wide text-brand-600 uppercase">
        {eyebrow}
      </p>
      <h2 id={id} className="mt-3 text-3xl font-extrabold sm:text-4xl">
        {title}
      </h2>
      {description && (
        <p className="mt-3 text-base text-ink-muted sm:text-lg">
          {description}
        </p>
      )}
    </div>
  );
}

// --- Problème / valeur ----------------------------------------------------------------------
const VALUES: { icon: LucideIcon; title: string; text: string }[] = [
  {
    icon: Layers,
    title: "Une seule interface",
    text: "Fini les allers-retours entre les applications : tous vos réseaux au même endroit.",
  },
  {
    icon: Clock,
    title: "Un contenu, adapté partout",
    text: "Écrivez une fois ; formats, limites et options de chaque réseau sont vérifiés pour vous.",
  },
  {
    icon: BarChart3,
    title: "Des résultats lisibles",
    text: "Vos statistiques de tous les réseaux, réunies et comparables d'un coup d'œil.",
  },
];

export function ProblemSection() {
  return (
    <section
      aria-labelledby="probleme-title"
      className="bg-card py-16 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading
          id="probleme-title"
          eyebrow="Pourquoi SoxialConnect"
          title="Publier partout ne devrait pas prendre des heures"
          description="Chaque réseau a son application, ses formats et ses statistiques. SoxialConnect réunit tout au même endroit."
        />
        {/* Démonstration produit (Images/sect2.png) : hors du premier écran → chargement différé. */}
        <picture className="mt-10 block">
          <source
            media="(max-width: 639px)"
            type="image/webp"
            srcSet={WHY_MOBILE_SRC}
            width={WHY_MOBILE_SIZE.width}
            height={WHY_MOBILE_SIZE.height}
          />
          <img
            src={WHY_SRC}
            srcSet={WHY_SRCSET}
            sizes={WHY_SIZES}
            width={WHY_SIZE.width}
            height={WHY_SIZE.height}
            alt="Aperçu de SoxialConnect : la page Publications avec le calendrier, les réseaux et quatre publications illustrées, à côté du tableau Analytics (portée, engagement, clics, abonnés et répartition par réseau)."
            loading="lazy"
            decoding="async"
            className="mx-auto h-auto w-full max-w-[420px] sm:max-w-[1370px]"
          />
        </picture>
        <ul className="mt-12 grid gap-5 md:grid-cols-3">
          {VALUES.map(({ icon: Icon, title, text }) => (
            <li
              key={title}
              className="flex items-start gap-4 rounded-2xl border border-border bg-page/60 p-5 md:flex-col lg:flex-row"
            >
              <span
                className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"
                aria-hidden="true"
              >
                <Icon className="size-5" />
              </span>
              <div>
                <h3 className="text-base font-bold">{title}</h3>
                <p className="mt-1 text-sm leading-relaxed text-ink-muted">
                  {text}
                </p>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

// --- Fonctionnalités principales (6) : carrousel (données dans ./features.ts) ---------------------
export function FeaturesSection() {
  return (
    <section
      id="fonctionnalites"
      aria-labelledby="features-title"
      className="py-16 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading
          id="features-title"
          eyebrow="Fonctionnalités"
          title="Tout ce qu'il faut, rien de compliqué"
          description="Les outils essentiels pour créer, publier et mesurer, pensés pour être compris dès la première utilisation."
        />
        <div className="mt-10">
          <FeatureCarousel items={FEATURES} label="Fonctionnalités de SoxialConnect" />
        </div>
      </div>
    </section>
  );
}

// --- Comment ça fonctionne (+ confiance + questions fréquentes) ---------------------------------
const STEPS = [
  {
    icon: Share2,
    title: "Connectez vos comptes",
    text: "Via les connexions officielles de chaque réseau, en quelques clics.",
  },
  {
    icon: PenLine,
    title: "Créez et organisez",
    text: "Rédigez une fois, ajoutez vos médias et adaptez chaque version.",
  },
  {
    icon: Send,
    title: "Programmez, publiez, suivez",
    text: "Publiez tout de suite ou à l'heure prévue, puis suivez vos résultats.",
  },
];

// Fusion de l'ancienne section « Sécurité » : l'essentiel en une ligne de confiance.
const TRUST: { icon: LucideIcon; text: string }[] = [
  {
    icon: ShieldCheck,
    text: "Connexions officielles : votre mot de passe de réseau n'est jamais partagé",
  },
  { icon: KeyRound, text: "Autorisations d'accès chiffrées" },
  { icon: Unplug, text: "Déconnexion d'un réseau à tout moment" },
];

// Fusion de l'ancienne section « FAQ » : accordéon compact (le menu « Ressources » y pointe).
const FAQ = [
  {
    q: "Faut-il des compétences techniques ?",
    a: "Non. SoxialConnect a été conçu pour être utilisé sans connaissances techniques : chaque écran vous guide pas à pas.",
  },
  {
    q: "Quels réseaux sont pris en charge ?",
    a: "Facebook, Instagram, TikTok, YouTube, LinkedIn, X et Pinterest. Threads arrive bientôt, et d'autres plateformes suivront.",
  },
  {
    q: "Puis-je publier sur mon profil Facebook personnel ?",
    a: "Les réseaux ne l'autorisent pas toujours : Facebook permet de publier sur une Page, et Instagram demande un compte professionnel (gratuit).",
  },
  {
    q: "Puis-je déconnecter un réseau ?",
    a: "Oui, à tout moment depuis la page « Réseaux sociaux ». L'accès est retiré immédiatement.",
  },
];

export function HowItWorksSection() {
  return (
    <section
      id="comment-ca-marche"
      aria-labelledby="how-title"
      className="bg-card py-16 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading
          id="how-title"
          eyebrow="Comment ça fonctionne"
          title="Trois étapes, et c'est tout"
        />

        <ol className="relative mt-10 grid gap-4 md:grid-cols-3">
          <span
            aria-hidden="true"
            className="absolute top-9 right-[16%] left-[16%] hidden h-px bg-gradient-to-r from-brand-200 via-violet-100 to-brand-200 md:block"
          />
          {STEPS.map(({ icon: Icon, title, text }, i) => (
            <li
              key={title}
              className="relative flex flex-col items-center text-center md:px-4"
            >
              <span
                className="relative inline-flex size-[72px] items-center justify-center rounded-2xl bg-brand-500 text-white shadow-brand"
                aria-hidden="true"
              >
                <Icon className="size-7" />
                <span className="absolute -top-2 -right-2 inline-flex size-7 items-center justify-center rounded-full bg-card text-xs font-bold text-brand-600 ring-2 ring-brand-100">
                  {String(i + 1).padStart(2, "0")}
                </span>
              </span>
              <h3 className="mt-4 text-lg font-bold">
                <span className="sr-only">Étape {i + 1} : </span>
                {title}
              </h3>
              <p className="mt-1 max-w-xs text-sm leading-relaxed text-ink-muted">
                {text}
              </p>
            </li>
          ))}
        </ol>

        <ul
          id="securite"
          aria-label="Sécurité"
          className="mt-10 flex scroll-mt-28 flex-col items-center justify-center gap-3 rounded-2xl bg-success-50/60 px-5 py-4 text-sm text-ink-soft lg:flex-row lg:gap-8"
        >
          {TRUST.map(({ icon: Icon, text }) => (
            <li key={text} className="flex items-center gap-2">
              <Icon
                className="size-4 shrink-0 text-success-700"
                aria-hidden="true"
              />
              {text}
            </li>
          ))}
        </ul>

        <div id="faq" className="mt-12 scroll-mt-28">
          <h3 className="text-center text-xl font-bold">
            Questions fréquentes
          </h3>
          <div className="mx-auto mt-5 grid max-w-5xl gap-3 md:grid-cols-2">
            {FAQ.map((item) => (
              <details
                key={item.q}
                className="group self-start rounded-xl border border-border bg-page/60 px-4 py-3 [&_summary::-webkit-details-marker]:hidden"
              >
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-ink">
                  {item.q}
                  <span
                    className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 transition-transform group-open:rotate-45"
                    aria-hidden="true"
                  >
                    +
                  </span>
                </summary>
                <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                  {item.a}
                </p>
              </details>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}

// --- Aperçu de la plateforme --------------------------------------------------------------------
// Une seule vitrine de l'interface (remplace les 6 illustrations de l'ancienne section Fonctionnalités).
const PREVIEWS: {
  value: string;
  label: string;
  title: string;
  caption: string;
  points: string[];
  visual: ReactNode;
}[] = [
  {
    value: "creer",
    label: "Créer",
    title: "Le Composer",
    caption:
      "Rédigez une fois : chaque réseau reçoit une version adaptée à ses formats.",
    points: [
      "Aperçu fidèle par réseau",
      "Limites de caractères vérifiées",
      "Brouillons enregistrés automatiquement",
    ],
    visual: <ComposerVisual />,
  },
  {
    value: "planifier",
    label: "Planifier",
    title: "Le calendrier éditorial",
    caption:
      "Toutes vos publications de la semaine ou du mois, au même endroit.",
    points: [
      "Vue semaine et vue mois",
      "Programmation à l'heure près",
      "Filtres par réseau",
    ],
    visual: <CalendarVisual />,
  },
  {
    value: "publier",
    label: "Publier",
    title: "Le suivi de publication",
    caption:
      "Le statut de chaque réseau est suivi, publication par publication.",
    points: [
      "Statut clair pour chaque réseau",
      "Message compréhensible en cas d'erreur",
      "Relance en un clic",
    ],
    visual: <PublishVisual />,
  },
  {
    value: "medias",
    label: "Médias & vidéo",
    title: "Médiathèque et Video Studio",
    caption:
      "Vos images et vidéos organisées, et des clips courts extraits de vos vidéos longues.",
    points: [
      "Dossiers et recherche",
      "Clips détectés automatiquement",
      "Sous-titres générés",
    ],
    visual: <MediaVisual />,
  },
  {
    value: "ia",
    label: "Assistant IA",
    title: "L'assistant de rédaction",
    caption:
      "Des propositions de légendes et de hashtags en quelques secondes.",
    points: [
      "Ton adapté à votre marque",
      "Idées de publications",
      "Vous validez toujours avant publication",
    ],
    visual: <AIVisual />,
  },
  {
    value: "analyser",
    label: "Analyser",
    title: "Le tableau de bord analytics",
    caption: "Vos indicateurs clés et leur évolution, tous réseaux confondus.",
    points: [
      "Impressions, portée, engagement",
      "Comparaison entre réseaux",
      "Évolution sur la période",
    ],
    visual: <AnalyticsVisual />,
  },
];

export function PlatformPreviewSection() {
  return (
    <section
      id="apercu"
      aria-labelledby="preview-title"
      className="py-16 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHeading
          id="preview-title"
          eyebrow="Aperçu de la plateforme"
          title="Découvrez votre futur espace de travail"
          description="Un aperçu des écrans que vous utiliserez au quotidien."
        />
        <Tabs defaultValue="creer" className="mt-8 items-center">
          <TabsList className="h-auto max-w-full flex-wrap justify-center gap-1 bg-muted p-1">
            {PREVIEWS.map((p) => (
              <TabsTrigger
                key={p.value}
                value={p.value}
                className="h-9 flex-none px-3.5"
              >
                {p.label}
              </TabsTrigger>
            ))}
          </TabsList>
          {/* Panneaux superposés dans une même cellule : la section garde la hauteur du plus grand
              visuel, ce qui évite que la page « saute » à chaque changement d'onglet. */}
          <div className="mt-8 grid w-full">
            {PREVIEWS.map((p) => (
              <TabsContent
                key={p.value}
                value={p.value}
                forceMount
                className="col-start-1 row-start-1 w-full self-start data-[state=inactive]:invisible"
              >
                <div className="grid items-center gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-16">
                  <div className="text-center lg:text-left">
                    <h3 className="text-2xl font-bold">{p.title}</h3>
                    <p className="mt-2 text-base leading-relaxed text-ink-muted">
                      {p.caption}
                    </p>
                    <ul className="mt-5 inline-flex flex-col gap-2.5 text-left">
                      {p.points.map((pt) => (
                        <li
                          key={pt}
                          className="flex items-center gap-2.5 text-sm text-ink-soft"
                        >
                          <CheckCircle2
                            className="size-4 shrink-0 text-success-700"
                            aria-hidden="true"
                          />
                          {pt}
                        </li>
                      ))}
                    </ul>
                  </div>
                  <div className="relative mx-auto w-full max-w-2xl">
                    <div
                      aria-hidden="true"
                      className="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-brand-50 to-violet-50 sm:-inset-6"
                    />
                    <div className="relative">{p.visual}</div>
                  </div>
                </div>
              </TabsContent>
            ))}
          </div>
        </Tabs>
      </div>
    </section>
  );
}

// --- CTA final (+ tarifs : le lien « Tarifs » du menu pointe ici) --------------------------------
export function CtaSection() {
  return (
    <section
      id="tarifs"
      aria-labelledby="cta-title"
      className="scroll-mt-28 px-4 pb-16 sm:px-6 sm:pb-20 lg:px-8"
    >
      <div className="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-brand-gradient px-6 py-12 text-center shadow-brand sm:px-12 sm:py-14">
        <div
          aria-hidden="true"
          className="absolute -top-24 -right-24 size-72 rounded-full bg-white/10"
        />
        <div
          aria-hidden="true"
          className="absolute -bottom-28 -left-16 size-72 rounded-full bg-white/10"
        />
        <h2
          id="cta-title"
          className="relative text-3xl font-extrabold text-white sm:text-4xl"
        >
          Prêt à gagner du temps sur vos réseaux ?
        </h2>
        <p className="relative mx-auto mt-3 max-w-xl text-brand-50">
          Une seule plateforme. Tous vos réseaux. Tout votre contenu.
        </p>
        <div className="relative mt-7 flex justify-center">
          <Button
            size="lg"
            asChild
            className="bg-white text-brand-700 shadow-none hover:bg-brand-50"
          >
            <Link to="/register">
              Inscription <ArrowRight />
            </Link>
          </Button>
        </div>
        <p className="relative mt-4 text-sm text-brand-50/90">
          Tarifs : les offres seront annoncées prochainement. Aucun abonnement
          pendant la version 1.
        </p>
      </div>
    </section>
  );
}

// --- Footer ---------------------------------------------------------------------------------
const YEAR = new Date().getFullYear();
export function LandingFooter() {
  const cols = [
    {
      title: "Produit",
      links: [
        ["Fonctionnalités", "#fonctionnalites"],
        ["Tarifs", "#tarifs"],
        ["Démo", "/dashboard"],
      ],
    },
    {
      title: "Ressources",
      links: [
        ["Comment ça marche", "#comment-ca-marche"],
        ["Questions fréquentes", "#faq"],
        ["Design system", "/design-system"],
      ],
    },
    {
      title: "Légal",
      links: [
        ["Confidentialité", "/legal/privacy"],
        ["Conditions d'utilisation", "/legal/terms"],
        ["Suppression des données", "/legal/data-deletion"],
      ],
    },
  ];
  return (
    <footer className="border-t border-border bg-card">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_repeat(3,1fr)] lg:px-8">
        <div>
          <div className="flex items-center gap-2">
            <LogoMark />
            <span className="text-lg font-bold text-ink">SoxialConnect</span>
          </div>
          <p className="mt-4 max-w-xs text-sm text-ink-muted">
            Gérez tous vos réseaux sociaux depuis une seule plateforme.
          </p>
          <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-ink-muted">
            <Lock className="size-3.5" aria-hidden="true" /> Connexions
            officielles uniquement
          </p>
        </div>
        {cols.map((c) => (
          <nav key={c.title} aria-label={c.title}>
            <h2 className="text-sm font-semibold text-ink">{c.title}</h2>
            <ul className="mt-4 space-y-2.5">
              {c.links.map(([label, href]) => (
                <li key={label}>
                  {href.startsWith("#") ? (
                    <a
                      href={href}
                      className="text-sm text-ink-muted hover:text-brand-600"
                    >
                      {label}
                    </a>
                  ) : (
                    <Link
                      to={href}
                      className="text-sm text-ink-muted hover:text-brand-600"
                    >
                      {label}
                    </Link>
                  )}
                </li>
              ))}
            </ul>
          </nav>
        ))}
      </div>
      <div className="border-t border-border py-6 text-center text-xs text-ink-muted">
        © {YEAR} SoxialConnect. Tous droits réservés.
      </div>
    </footer>
  );
}
