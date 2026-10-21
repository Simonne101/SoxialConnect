import studioCreator from "@/assets/images/studio-creator.webp";
import {
  ArrowRight,
  Check,
  Clapperboard,
  Captions,
  Scissors,
  Sparkles,
  CalendarDays,
  PenLine,
  BarChart3,
} from "lucide-react";
import { Link } from "react-router";
import { PlatformIcon } from "@/components/brand/PlatformIcon";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms";

export function NetworkSection() {
  return (
    <section
      aria-label="Vos réseaux sociaux réunis"
      className="border-y border-border bg-white py-8 sm:py-10"
    >
      <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
        <p className="text-center text-xs font-semibold tracking-[0.15em] text-ink-muted uppercase">
          Votre présence, au-delà d’un seul réseau
        </p>
        <ul className="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-5 sm:gap-x-9">
          {PLATFORM_ORDER.filter(
            (key) => PLATFORMS[key].availability === "available",
          ).map((key) => (
            <li key={key} className="network-item flex items-center gap-2.5">
              <PlatformIcon platform={key} size="sm" />
              <span className="text-sm font-semibold text-ink-soft">
                {PLATFORMS[key].name}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

export function VideoSpotlight() {
  return (
    <section
      id="studio-video"
      aria-labelledby="video-title"
      className="video-spotlight overflow-hidden py-16 sm:py-24"
    >
      <div className="mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-6 lg:grid-cols-2 lg:gap-20 lg:px-8">
        <div data-reveal>
          <p className="section-kicker text-brand-200">
            <Clapperboard className="size-4" aria-hidden="true" /> Video Studio
          </p>
          <h2
            id="video-title"
            className="mt-5 text-4xl leading-[1.12] font-extrabold text-white sm:text-5xl"
          >
            Une vidéo.
            <br />
            <span className="text-brand-200">De nouvelles histoires.</span>
          </h2>
          <p className="mt-6 max-w-md text-base leading-relaxed text-navy-200 sm:text-lg">
            Donnez une seconde vie à vos contenus longs. Repérez les moments
            forts, préparez vos clips et ajoutez les sous-titres qui font passer
            votre message.
          </p>
          <ul className="mt-8 space-y-4 text-sm text-white sm:text-base">
            {[
              {
                icon: Scissors,
                text: "Des extraits courts à partir de vos vidéos",
              },
              {
                icon: Captions,
                text: "Des sous-titres pour suivre chaque mot",
              },
              { icon: Sparkles, text: "Votre sélection, votre touche finale" },
            ].map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-center gap-3">
                <Icon
                  className="size-5 shrink-0 text-brand-300"
                  aria-hidden="true"
                />
                {text}
              </li>
            ))}
          </ul>
          <a
            href="#apercu"
            className="mt-8 inline-flex min-h-11 items-center gap-2 font-semibold text-white underline decoration-brand-400 underline-offset-8"
          >
            Découvrir les outils de création{" "}
            <ArrowRight className="size-4" aria-hidden="true" />
          </a>
        </div>
        <div
          data-reveal
          data-loop
          className="studio-scene"
          role="img"
          aria-label="Illustration du studio : une vidéo source devient des clips verticaux, avec sélection des extraits et sous-titres."
        >
          <div className="studio-topline">
            <span className="flex items-center gap-2">
              <Clapperboard className="size-4" /> Votre prochaine histoire
            </span>
            <span className="studio-pill">9:16</span>
          </div>
          <div className="studio-frames" aria-hidden="true">
            <div className="studio-frame studio-frame-back">
              <img
                src={studioCreator}
                width={640}
                height={960}
                alt=""
                loading="lazy"
                decoding="async"
              />
              <span className="clip-number">01</span>
              <div className="clip-copy">
                <span>Les coulisses</span>
                <strong>L’idée prend vie.</strong>
              </div>
              <span className="clip-duration">00:24</span>
            </div>
            <div data-loop-motion className="studio-frame studio-frame-front">
              <img
                src={studioCreator}
                width={640}
                height={960}
                alt=""
                loading="lazy"
                decoding="async"
              />
              <div className="clip-top">
                <span className="clip-number">02</span>
                <span className="clip-selected">
                  <Check className="size-3" /> Extrait sélectionné
                </span>
              </div>
              <div className="studio-caption">
                Faites entendre
                <br />
                <em>votre différence.</em>
              </div>
              <div className="clip-bottom">
                <span>LE MOMENT FORT</span>
                <span>00:32</span>
              </div>
            </div>
            <div className="studio-frame studio-frame-end">
              <img
                src={studioCreator}
                width={640}
                height={960}
                alt=""
                loading="lazy"
                decoding="async"
              />
              <span className="clip-number">03</span>
              <div className="clip-copy">
                <span>Le conseil</span>
                <strong>À vous de créer.</strong>
              </div>
              <span className="clip-duration">00:18</span>
            </div>
          </div>
          <div className="studio-timeline" aria-hidden="true">
            <div data-loop-motion className="studio-playhead" />
            {Array.from({ length: 24 }, (_, i) => (
              <i key={i} style={{ height: `${12 + ((i * 17) % 28)}px` }} />
            ))}
          </div>
          <div className="flex items-center justify-between text-xs text-navy-200">
            <span>Votre contenu, réinventé.</span>
            <span>Clips · Sous-titres</span>
          </div>
        </div>
      </div>
    </section>
  );
}

const AUDIENCES = [
  {
    id: "createurs",
    label: "Créateurs",
    eyebrow: "Pour celles et ceux qui ont des choses à dire",
    title: "Restez dans votre élan créatif.",
    text: "Une idée dans le métro, un tournage terminé, une envie de partager : rassemblez vos contenus et préparez la suite sans perdre le fil.",
    points: [
      "Gardez vos idées en brouillon",
      "Déclinez vos vidéos en clips",
      "Programmez vos prochains rendez-vous",
    ],
    cards: [
      {
        icon: PenLine,
        time: "L’idée du jour",
        title: "Les coulisses de ma création",
        tag: "Brouillon",
      },
      {
        icon: Clapperboard,
        time: "Le contenu à décliner",
        title: "Un tournage, plusieurs formats",
        tag: "Vidéo",
      },
      {
        icon: CalendarDays,
        time: "Le prochain rendez-vous",
        title: "Un post pour ma communauté",
        tag: "À programmer",
      },
    ],
  },
  {
    id: "marques",
    label: "Entreprises",
    eyebrow: "Pour les marques qui veulent rester présentes",
    title: "Faites vivre votre marque, au quotidien.",
    text: "Présentez votre activité, racontez vos nouveautés et gardez une communication régulière. Votre calendrier vous aide à voir ce qui vient, sans tout improviser.",
    points: [
      "Rassemblez les médias de votre marque",
      "Préparez vos temps forts à l’avance",
      "Comparez les résultats de vos publications",
    ],
    cards: [
      {
        icon: PenLine,
        time: "Faire découvrir",
        title: "L’histoire derrière notre produit",
        tag: "Création",
      },
      {
        icon: CalendarDays,
        time: "Préparer le lancement",
        title: "Notre prochaine collection",
        tag: "Calendrier",
      },
      {
        icon: BarChart3,
        time: "Comprendre ce qui plaît",
        title: "Les contenus qui font réagir",
        tag: "Analyse",
      },
    ],
  },
  {
    id: "community",
    label: "Community managers",
    eyebrow: "Pour les pros qui jonglent avec les réseaux",
    title: "Gardez la vue d’ensemble.",
    text: "Passez de la rédaction au calendrier, puis aux résultats dans le même espace. Des statuts clairs et des contenus organisés pour avancer avec méthode.",
    points: [
      "Adaptez le message à chaque réseau",
      "Visualisez votre planning éditorial",
      "Suivez les statuts de publication",
    ],
    cards: [
      {
        icon: PenLine,
        time: "Créer les variantes",
        title: "Un message adapté à chaque réseau",
        tag: "Rédaction",
      },
      {
        icon: CalendarDays,
        time: "Organiser la semaine",
        title: "Le planning éditorial",
        tag: "Calendrier",
      },
      {
        icon: Check,
        time: "Faire le point",
        title: "Le suivi des publications",
        tag: "Suivi",
      },
    ],
  },
];

export function AudienceSection() {
  return (
    <section
      id="pour-vous"
      aria-labelledby="audience-title"
      className="bg-white py-16 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
        <div data-reveal className="mx-auto max-w-2xl text-center">
          <p className="section-kicker justify-center">
            À chacun sa façon de créer
          </p>
          <h2
            id="audience-title"
            className="mt-4 text-3xl font-extrabold sm:text-5xl"
          >
            Votre quotidien.
            <br />
            Votre façon de rayonner.
          </h2>
        </div>
        <Tabs defaultValue="createurs" className="mt-9">
          <TabsList
            aria-label="Votre activité"
            className="mx-auto h-auto! max-w-full flex-wrap rounded-full bg-brand-50 p-1.5"
          >
            {AUDIENCES.map((item) => (
              <TabsTrigger
                key={item.id}
                value={item.id}
                className="min-h-11 rounded-full px-4 sm:px-6"
              >
                {item.label}
              </TabsTrigger>
            ))}
          </TabsList>
          {AUDIENCES.map((item) => (
            <TabsContent
              key={item.id}
              value={item.id}
              className="audience-panel mt-8 rounded-[2rem] border border-brand-100 bg-landing p-6 sm:p-10 lg:p-12"
            >
              <div className="grid items-center gap-9 lg:grid-cols-2 lg:gap-16">
                <div>
                  <p className="text-sm font-semibold text-brand-700">
                    {item.eyebrow}
                  </p>
                  <h3 className="mt-3 text-3xl font-bold">{item.title}</h3>
                  <p className="mt-4 max-w-lg text-base leading-relaxed text-ink-muted">
                    {item.text}
                  </p>
                  <ul className="mt-6 space-y-3">
                    {item.points.map((point) => (
                      <li
                        key={point}
                        className="flex items-start gap-2.5 text-sm text-ink-soft"
                      >
                        <Check
                          className="mt-0.5 size-4 shrink-0 text-brand-600"
                          aria-hidden="true"
                        />
                        {point}
                      </li>
                    ))}
                  </ul>
                  <Button asChild variant="link" className="mt-5 h-11 px-0">
                    <Link to="/register">
                      Créer mon espace <ArrowRight aria-hidden="true" />
                    </Link>
                  </Button>
                </div>
                <div className="audience-cards space-y-3">
                  {item.cards.map(({ icon: Icon, time, title, tag }, i) => (
                    <div
                      key={title}
                      className="audience-card flex items-center gap-4 rounded-2xl border border-border bg-white p-5 shadow-card"
                      style={{ animationDelay: `${i * 70}ms` }}
                    >
                      <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-600">
                        <Icon className="size-5" aria-hidden="true" />
                      </span>
                      <div>
                        <p className="text-xs text-ink-muted">{time}</p>
                        <p className="mt-1 text-sm font-semibold text-ink">
                          {title}
                        </p>
                        <span className="mt-2 inline-block rounded-full bg-page px-2.5 py-1 text-xs text-brand-700">
                          {tag}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </TabsContent>
          ))}
        </Tabs>
      </div>
    </section>
  );
}
