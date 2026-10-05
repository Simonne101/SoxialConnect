// Assistance IA simulée dans le Composer (aucun appel à un fournisseur — phase frontend).
import type { PlatformKey } from "@/types"
import { PLATFORMS } from "@/lib/platforms"

const HASHTAGS: Record<PlatformKey, string[]> = {
  facebook: [],
  instagram: ["creationdecontenu", "conseils", "inspiration"],
  tiktok: ["astuce", "pourtoi", "createur"],
  youtube: [],
  linkedin: ["contenu", "entrepreneuriat"],
  x: ["conseil"],
  pinterest: [],
  threads: [],
  telegram: [],
}

export function suggestHashtags(platform: PlatformKey): string[] {
  return HASHTAGS[platform]
}

export function improveText(text: string): string {
  const t = text.trim()
  if (!t) return "✨ Nouvelle publication à venir ! Restez connectés, je vous prépare quelque chose de spécial."
  const firstLine = t.split("\n")[0]
  return `${firstLine.endsWith("!") || firstLine.endsWith("?") ? firstLine : `${firstLine} 👇`}${t.length > firstLine.length ? t.slice(firstLine.length) : ""}\n\nQu'en pensez-vous ? Dites-le-moi en commentaire !`
}

/** Adapte le texte principal aux usages de chaque plateforme. */
export function adaptForPlatform(text: string, platform: PlatformKey): { body: string; title?: string } {
  const base = text.trim() || "Découvrez ma nouvelle publication"
  const max = PLATFORMS[platform].maxChars
  switch (platform) {
    case "x":
    case "threads": {
      const short = base.length > max - 20 ? `${base.slice(0, max - 21).trimEnd()}…` : base
      return { body: short }
    }
    case "linkedin":
      return { body: `${base}\n\nEt vous, comment faites-vous ? Partagez votre expérience en commentaire.` }
    case "instagram":
    case "tiktok":
      return { body: `${base} ✨` }
    case "youtube":
      return { body: `${base}\n\n⏱️ Chapitres\n00:00 Introduction\n\n🔔 Abonnez-vous pour ne rien manquer.`, title: base.slice(0, 90) }
    case "pinterest":
      return { body: base.slice(0, 480), title: base.slice(0, 90) }
    default:
      return { body: base }
  }
}
