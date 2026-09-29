import type { PlatformKey, Post, PostFormat, PostStatus, PostVariant, VariantStatus } from "@/types"
import { at, seeded } from "./helpers"

const ACCOUNT_BY_PLATFORM: Record<PlatformKey, string> = {
  facebook: "acc-fb",
  instagram: "acc-ig",
  tiktok: "acc-tt",
  youtube: "acc-yt",
  linkedin: "acc-li",
  x: "acc-x",
  pinterest: "acc-pin",
  threads: "acc-th",
}

const DEFAULT_FORMAT: Record<PlatformKey, PostFormat> = {
  facebook: "feed",
  instagram: "feed",
  tiktok: "video",
  youtube: "video",
  linkedin: "feed",
  x: "feed",
  pinterest: "pin",
  threads: "feed",
}

interface Seed {
  title: string | null
  body: string
  platforms: PlatformKey[]
  day: number
  hour: number
  minute?: number
  status: PostStatus
  media?: string[]
  /** Statuts par plateforme pour les cas partiels ou en échec. */
  variantStatus?: Partial<Record<PlatformKey, VariantStatus>>
  error?: string
  source?: Post["source"]
}

// Titres repris de la maquette (Prochaines publications, Publications du jour).
const SEEDS: Seed[] = [
  { title: "Conseils de création", body: "Mes 3 rituels pour rester créative toute la semaine ✨ Lequel testez-vous en premier ?", platforms: ["instagram"], day: 0, hour: 9, status: "scheduled", media: ["m-03"] },
  { title: "Vidéo tendance", body: "Le trend du moment, version coulisses de l'atelier 🎬", platforms: ["tiktok"], day: 0, hour: 11, minute: 30, status: "scheduled", media: ["m-13"] },
  { title: "Post entreprise", body: "Nouvelle collaboration : nous accompagnons 5 artisans locaux ce trimestre.", platforms: ["facebook"], day: 0, hour: 14, status: "scheduled", media: ["m-07"] },
  { title: "Nouveau tutoriel", body: "Tutoriel complet : planifier un mois de contenu en 2 heures.", platforms: ["youtube"], day: 0, hour: 16, status: "scheduled", media: ["m-14"] },
  { title: "Conseils pour booster votre visibilité", body: "Publier régulièrement ne suffit pas : voici 5 leviers simples pour booster votre visibilité 🚀 #conseils #visibilité", platforms: ["instagram", "facebook"], day: 1, hour: 10, status: "scheduled", media: ["m-01"] },
  { title: "Nouvelle vidéo YouTube", body: "Nouvelle vidéo en ligne : comment je transforme une interview en 10 clips courts.", platforms: ["youtube"], day: 1, hour: 14, minute: 30, status: "scheduled", media: ["m-14"] },
  { title: "Post LinkedIn", body: "Ce que 12 mois de création de contenu m'ont appris sur la régularité. 🧵", platforms: ["linkedin"], day: 2, hour: 9, status: "scheduled" },
  { title: "Citation motivante", body: "« La constance bat le talent quand le talent n'est pas constant. »", platforms: ["x", "threads"], day: 2, hour: 16, status: "scheduled" },
  { title: "Carrousel : 7 idées de Reels", body: "Vous manquez d'idées ? Voici 7 formats de Reels faciles à tourner cette semaine.", platforms: ["instagram"], day: 4, hour: 18, status: "scheduled", media: ["m-02", "m-05", "m-08"] },
  { title: "Épingle : moodboard automne", body: "Moodboard automne : couleurs chaudes et textures naturelles 🍂", platforms: ["pinterest"], day: 6, hour: 12, status: "scheduled", media: ["m-10"] },
  { title: "Live Q&A vendredi", body: "Rendez-vous vendredi 18h pour un live questions-réponses !", platforms: ["instagram", "facebook", "linkedin"], day: 9, hour: 17, status: "scheduled" },
  { title: "Bilan du mois", body: "Bilan du mois : +24 % d'audience. Merci pour votre soutien 💜", platforms: ["linkedin", "x"], day: 14, hour: 9, status: "scheduled" },

  { title: "5 astuces pour des Reels qui performent", body: "5 astuces concrètes pour des Reels qui retiennent l'attention dès la première seconde.", platforms: ["instagram"], day: 0, hour: 7, status: "published", media: ["m-04"] },
  { title: "Coulisses de l'atelier", body: "Petite visite des coulisses de l'atelier 🎥", platforms: ["tiktok", "instagram"], day: -1, hour: 18, status: "published", media: ["m-13"] },
  { title: "Retour d'expérience client", body: "Témoignage : comment Léa a doublé son engagement en 3 mois.", platforms: ["linkedin", "facebook"], day: -2, hour: 10, status: "partially_published", variantStatus: { linkedin: "failed", facebook: "published" }, error: "LinkedIn : l'autorisation a expiré. Reconnectez le compte puis réessayez." },
  { title: "Checklist de rentrée", body: "Ma checklist de rentrée pour les créateurs, à télécharger gratuitement.", platforms: ["instagram", "facebook", "x"], day: -3, hour: 12, status: "published", media: ["m-06"] },
  { title: "Tutoriel montage mobile", body: "Monter une vidéo en 10 minutes depuis son téléphone.", platforms: ["youtube"], day: -5, hour: 15, status: "published", media: ["m-14"], source: "clip" },
  { title: "Sondage : vos formats préférés", body: "Quel format préférez-vous ? Vidéos courtes, carrousels ou lives ?", platforms: ["x"], day: -6, hour: 11, status: "failed", variantStatus: { x: "failed" }, error: "X : la permission « Publier des posts » a été refusée." },
  { title: "Inspiration du lundi", body: "Nouvelle semaine, nouveaux projets. Qu'allez-vous créer ?", platforms: ["instagram", "threads"], day: -7, hour: 8, status: "published", media: ["m-11"] },
  { title: "Nos meilleurs outils gratuits", body: "La liste des outils gratuits que j'utilise chaque semaine.", platforms: ["linkedin", "facebook"], day: -9, hour: 9, status: "published" },
  { title: "Avant / après : refonte du feed", body: "Avant / après : comment j'ai refondu mon feed Instagram.", platforms: ["instagram", "pinterest"], day: -12, hour: 19, status: "published", media: ["m-09"] },
  { title: "Annonce atelier annulée", body: "L'atelier de samedi est reporté.", platforms: ["facebook"], day: -4, hour: 9, status: "cancelled" },

  { title: "Idées de contenu pour novembre", body: "Brouillon : liste d'idées à trier pour novembre…", platforms: ["instagram", "tiktok"], day: -1, hour: 20, status: "draft", source: "ai" },
  { title: "Script vidéo : ma routine créative", body: "Intro : présenter la routine. Partie 1 : le matin…", platforms: ["youtube", "tiktok"], day: -2, hour: 21, status: "draft" },
  { title: "Annonce partenariat", body: "Très heureuse d'annoncer un nouveau partenariat avec…", platforms: ["linkedin"], day: -3, hour: 17, status: "draft" },
  { title: null, body: "Un petit mot pour vous remercier d'être plus de 20 000 à suivre ce compte 💜", platforms: ["instagram"], day: -6, hour: 22, status: "draft" },
]

function variantStatusFor(post: Seed, platform: PlatformKey): VariantStatus {
  const override = post.variantStatus?.[platform]
  if (override) return override
  switch (post.status) {
    case "published":
      return "published"
    case "scheduled":
      return "scheduled"
    case "draft":
      return "draft"
    case "cancelled":
      return "cancelled"
    case "failed":
      return "failed"
    case "processing":
      return "publishing"
    default:
      return "published"
  }
}

const rand = seeded(42)

export const mockPosts: Post[] = SEEDS.map((s, index) => {
  const id = `post-${String(index + 1).padStart(2, "0")}`
  const when = at(s.day, s.hour, s.minute ?? 0)
  const isPast = s.status === "published" || s.status === "partially_published" || s.status === "failed"
  const variants: PostVariant[] = s.platforms.map((platform) => {
    const status = variantStatusFor(s, platform)
    return {
      id: `${id}-${platform}`,
      postId: id,
      platform,
      socialAccountId: ACCOUNT_BY_PLATFORM[platform],
      format: s.platforms.length === 1 && s.media && s.media.length > 1 && platform === "instagram" ? "carousel" : DEFAULT_FORMAT[platform],
      body: null,
      options: platform === "youtube" ? { title: s.title ?? "", youtubeVisibility: "public" } : {},
      status,
      scheduledAt: s.status === "draft" ? null : when,
      publishedAt: status === "published" ? when : null,
      externalUrl: status === "published" ? "#" : null,
      errorMessage: status === "failed" ? (s.error ?? "La plateforme a refusé la publication.") : null,
    }
  })
  const impressions = Math.round(1500 + rand() * 18000)
  return {
    id,
    title: s.title ?? null,
    body: s.body,
    status: s.status,
    scheduledAt: s.status === "draft" ? null : when,
    publishedAt: isPast && s.status !== "failed" ? when : null,
    timezone: "Europe/Paris",
    source: s.source ?? "manual",
    mediaIds: s.media ?? [],
    variants,
    metrics:
      s.status === "published" || s.status === "partially_published"
        ? {
            impressions,
            reach: Math.round(impressions * (0.55 + rand() * 0.25)),
            engagements: Math.round(impressions * (0.04 + rand() * 0.06)),
            views: s.platforms.some((p) => p === "tiktok" || p === "youtube") ? Math.round(impressions * 0.8) : null,
            clicks: Math.round(impressions * (0.01 + rand() * 0.02)),
          }
        : null,
    lockVersion: 0,
    createdAt: at(s.day - 2, 10),
    updatedAt: at(Math.min(s.day, 0) - 1, 18),
  }
})
