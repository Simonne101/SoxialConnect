import { ArrowRight, Clapperboard, ImagePlus, Scissors, type LucideIcon } from "lucide-react"
import { Link } from "react-router"
import { PageHeader } from "@/components/shared/PageHeader"
import { Button } from "@/components/ui/button"

interface Entry {
  to: string
  icon: LucideIcon
  title: string
  description: string
  details: string
  action: string
  accent: string
}

// Trois flux distincts (ADR-046) : la composition vidéo existante, le YouTube Clipper et les images IA.
const ENTRIES: Entry[] = [
  {
    to: "/video-studio",
    icon: Clapperboard,
    title: "Créer une vidéo",
    description: "Composez une vidéo avec le Video Studio.",
    details: "Scènes, médias de votre médiathèque, textes et sons sur une timeline, rendu MP4.",
    action: "Ouvrir le Video Studio",
    accent: "bg-brand-50 text-brand-600",
  },
  {
    to: "/studio/clipper",
    icon: Scissors,
    title: "Créer un clip YouTube",
    description: "Transformez une vidéo en clips courts de 60 secondes maximum.",
    details: "Collez le lien YouTube, choisissez la vidéo dans votre médiathèque : l'IA propose les meilleurs passages.",
    action: "Coller un lien YouTube",
    accent: "bg-danger-50 text-danger-600",
  },
  {
    to: "/studio/images",
    icon: ImagePlus,
    title: "Générer une image",
    description: "Créez un visuel avec l'IA pour accompagner vos publications.",
    details: "Décrivez l'image, choisissez le format (1:1, 4:5, 9:16, 16:9) : elle rejoint votre médiathèque.",
    action: "Générer une image",
    accent: "bg-violet-50 text-violet-600",
  },
]

/** AI Creative Studio : point d'entrée des trois outils de création (ADR-046). */
export default function CreativeStudioPage() {
  return (
    <div>
      <PageHeader title="AI Creative Studio" description="Créez des vidéos, des clips courts et des visuels pour vos réseaux sociaux." />
      <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {ENTRIES.map((e) => (
          <li key={e.to} className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-card">
            <span className={`inline-flex size-11 items-center justify-center rounded-xl ${e.accent}`} aria-hidden="true">
              <e.icon className="size-5" />
            </span>
            <div>
              <h2 className="text-base font-semibold">{e.title}</h2>
              <p className="mt-1 text-sm font-medium text-ink-soft">{e.description}</p>
              <p className="mt-2 text-sm text-ink-muted">{e.details}</p>
            </div>
            <Button asChild className="mt-auto w-full">
              <Link to={e.to}>
                {e.action} <ArrowRight />
              </Link>
            </Button>
          </li>
        ))}
      </ul>
    </div>
  )
}
