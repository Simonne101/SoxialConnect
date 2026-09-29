import type { LucideIcon } from "lucide-react"
import {
  Bell,
  CalendarDays,
  Clapperboard,
  FileText,
  Images,
  LayoutDashboard,
  LineChart,
  Settings,
  Share2,
  ShieldCheck,
  Sparkles,
  SquarePen,
} from "lucide-react"

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Correspondance exacte (sinon les sous-routes activent l'élément). */
  end?: boolean
  badge?: "notifications"
  /** Autres chemins qui activent l'élément. */
  also?: string[]
}

// Ordre et libellés de la maquette. « Publications » et « Assistant IA » sont ajoutés
// (cahier des charges : liste des contenus et assistant IA) — signalés dans le rapport.
export const MAIN_NAV: NavItem[] = [
  { to: "/dashboard", label: "Tableau de bord", icon: LayoutDashboard },
  { to: "/content/create", label: "Créer du contenu", icon: SquarePen },
  { to: "/content", label: "Publications", icon: FileText, end: true, also: ["/content/drafts"] },
  { to: "/content/calendar", label: "Calendrier", icon: CalendarDays },
  { to: "/social-accounts", label: "Réseaux sociaux", icon: Share2 },
  { to: "/media", label: "Médias", icon: Images },
  { to: "/video-studio", label: "Clipping vidéo", icon: Clapperboard },
  { to: "/ai", label: "Assistant IA", icon: Sparkles },
  { to: "/analytics", label: "Analytics", icon: LineChart },
  { to: "/notifications", label: "Notifications", icon: Bell, badge: "notifications" },
]

export const SECONDARY_NAV: NavItem[] = [
  { to: "/settings", label: "Paramètres", icon: Settings, end: false },
  { to: "/settings/security", label: "Sécurité", icon: ShieldCheck },
]
