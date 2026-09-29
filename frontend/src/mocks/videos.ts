import type { TranscriptSegment, VideoClip, VideoProject } from "@/types"
import { minutesAgo } from "./helpers"

const TRANSCRIPT_LINES = [
  "Bonjour à toutes et à tous, bienvenue dans ce nouvel épisode.",
  "Aujourd'hui on parle stratégie de contenu, et surtout de régularité.",
  "La première erreur que je vois, c'est de vouloir être partout en même temps.",
  "Choisissez deux réseaux maximum au départ, et maîtrisez-les vraiment.",
  "Le hook, c'est les trois premières secondes : c'est là que tout se joue.",
  "Posez une question, montrez le résultat final, ou créez une surprise.",
  "Ensuite, recyclez vos contenus longs : une interview peut devenir dix clips.",
  "C'est exactement ce que je fais chaque semaine, et ça change tout.",
  "Autre point clé : analysez vos statistiques, mais pas tous les jours.",
  "Une fois par semaine suffit pour repérer ce qui fonctionne.",
  "Enfin, parlez à une seule personne quand vous créez.",
  "Votre contenu devient plus personnel, et votre audience le ressent.",
  "Merci de m'avoir écoutée, on se retrouve la semaine prochaine !",
]

const transcript: TranscriptSegment[] = TRANSCRIPT_LINES.map((text, i) => ({
  id: `seg-${i + 1}`,
  startMs: i * 190_000 + 4_000,
  endMs: i * 190_000 + 4_000 + 11_000,
  text,
}))

const clip = (
  id: string,
  title: string,
  startMs: number,
  seconds: number,
  score: number,
  reason: string,
  extra: Partial<VideoClip> = {},
): VideoClip => ({
  id,
  projectId: "vp-1",
  title,
  startMs,
  endMs: startMs + seconds * 1000,
  score,
  aspectRatio: "9:16",
  subtitlesEnabled: true,
  subtitleStyle: { preset: "bold", position: "bottom" },
  source: "ai",
  status: "suggested",
  reason,
  ...extra,
})

export const mockVideoProjects: VideoProject[] = [
  {
    id: "vp-1",
    title: "Interview — Stratégie de contenu 2026",
    sourceType: "upload",
    status: "ready",
    durationMs: 2_532_000,
    language: "fr",
    sizeBytes: 842_000_000,
    previewClassName: "thumb-12",
    transcript,
    clips: [
      clip("clip-1", "Le hook parfait en 3 secondes", 764_000, 32, 94, "Conseil actionnable + formulation percutante", { status: "rendered" }),
      clip("clip-2", "Une interview = dix clips", 1_144_000, 41, 89, "Idée forte, facile à comprendre"),
      clip("clip-3", "Ne soyez pas partout", 384_000, 28, 86, "Contre-intuitif, suscite le débat"),
      clip("clip-4", "Parlez à une seule personne", 1_904_000, 36, 81, "Émotion + message clair"),
      clip("clip-5", "Analysez une fois par semaine", 1_524_000, 25, 74, "Conseil pratique", { aspectRatio: "1:1" }),
      clip("clip-6", "La régularité avant tout", 194_000, 45, 68, "Thème central de la vidéo", { aspectRatio: "16:9", subtitlesEnabled: false }),
    ],
    createdAt: minutesAgo(15),
  },
  {
    id: "vp-2",
    title: "Webinaire — Planifier son mois",
    sourceType: "upload",
    status: "transcribing",
    durationMs: 3_605_000,
    language: "fr",
    sizeBytes: 1_020_000_000,
    previewClassName: "thumb-5",
    transcript: [],
    clips: [],
    createdAt: minutesAgo(4),
  },
  {
    id: "vp-3",
    title: "Vlog atelier (fichier corrompu)",
    sourceType: "upload",
    status: "failed",
    durationMs: null,
    language: null,
    sizeBytes: 312_000_000,
    previewClassName: "thumb-10",
    transcript: [],
    clips: [],
    createdAt: minutesAgo(60 * 24 * 2),
  },
]
