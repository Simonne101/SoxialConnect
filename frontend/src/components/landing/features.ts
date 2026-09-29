// Fonctionnalités présentées sur la page d'accueil (carrousel « Fonctionnalités »).
import type { LucideIcon } from "lucide-react"
import { BarChart3, CalendarClock, Clapperboard, PenLine, Send, Sparkles } from "lucide-react"

export interface FeatureItem {
  icon: LucideIcon
  title: string
  description: string
  /** Couleurs de la pastille d'icône (tokens du design system). */
  tone: string
}

export const FEATURES: FeatureItem[] = [
  {
    icon: PenLine,
    title: "Création multi-réseaux",
    description: "Un message, une version adaptée à chaque réseau, avec aperçu avant publication.",
    tone: "bg-brand-50 text-brand-600",
  },
  {
    icon: CalendarClock,
    title: "Calendrier & programmation",
    description: "Planifiez à l'heure voulue et visualisez votre mois en un coup d'œil.",
    tone: "bg-info-50 text-info-700",
  },
  {
    icon: Send,
    title: "Publication & suivi",
    description: "Publiez en un clic et suivez le statut de chaque réseau, avec relance en cas d'échec.",
    tone: "bg-success-50 text-success-700",
  },
  {
    icon: Clapperboard,
    title: "Médias & Video Studio",
    description: "Une médiathèque organisée, et vos longues vidéos découpées en clips courts sous-titrés.",
    tone: "bg-violet-50 text-violet-600",
  },
  {
    icon: Sparkles,
    title: "Assistant IA",
    description: "Légendes, idées, hashtags et scripts vidéo, dans le ton qui vous ressemble.",
    tone: "bg-warning-50 text-warning-700",
  },
  {
    icon: BarChart3,
    title: "Analytics",
    description: "Impressions, portée, engagement et abonnés, comparés d'un réseau à l'autre.",
    tone: "bg-danger-50 text-danger-700",
  },
]
