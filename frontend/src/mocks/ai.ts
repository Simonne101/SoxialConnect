import type { AIConversation, AIPromptInput, AITask, AITone } from "@/types"
import { PLATFORMS } from "@/lib/platforms"
import { minutesAgo } from "./helpers"

export const AI_TASKS: { task: AITask; label: string; description: string; example: string }[] = [
  { task: "caption", label: "Générer une légende", description: "Une légende courte et engageante", example: "Une légende pour la photo de mon nouvel atelier" },
  { task: "post", label: "Générer un post", description: "Un post complet prêt à publier", example: "Un post pour annoncer mon live de vendredi" },
  { task: "rephrase", label: "Reformuler", description: "Réécrire un texte existant", example: "Rends ce texte plus percutant : « Nouvelle vidéo en ligne »" },
  { task: "hashtags", label: "Générer des hashtags", description: "Des hashtags pertinents", example: "Des hashtags pour un Reel sur la productivité" },
  { task: "ideas", label: "Trouver des idées", description: "Des idées de contenus", example: "Des idées de contenus pour novembre" },
  { task: "script", label: "Écrire un script", description: "Un script de vidéo courte", example: "Un script de 30 secondes sur ma routine créative" },
]

export const AI_TONES: { value: AITone; label: string }[] = [
  { value: "friendly", label: "Chaleureux" },
  { value: "professional", label: "Professionnel" },
  { value: "inspiring", label: "Inspirant" },
  { value: "fun", label: "Fun" },
  { value: "educational", label: "Pédagogique" },
]

const TONE_EMOJI: Record<AITone, string> = {
  friendly: "😊",
  professional: "",
  inspiring: "✨",
  fun: "🎉",
  educational: "📚",
}

/** Réponse simulée — aucun appel à un fournisseur IA (phase frontend). */
export function mockAIResponse(input: AIPromptInput): { content: string; items?: string[] } {
  const subject = input.prompt.replace(/^(une?|des|rends ce texte plus percutant\s*:)\s*/i, "").trim() || "votre sujet"
  const emoji = TONE_EMOJI[input.tone]
  const platform = input.platform ? PLATFORMS[input.platform].name : "vos réseaux"
  switch (input.task) {
    case "caption":
      return {
        content: `${emoji ? emoji + " " : ""}Petit aperçu de ce qui se prépare… ${capitalizeFirst(subject)} ! Dites-moi en commentaire ce que vous en pensez 👇`,
      }
    case "post":
      return {
        content: `${capitalizeFirst(subject)} ${emoji}\n\nJe vous en parle depuis des semaines, c'est enfin le moment. Voici ce qui vous attend :\n\n→ des conseils concrets\n→ des exemples réels\n→ vos questions en direct\n\nEnregistrez ce post pour ne pas oublier, et partagez-le à quelqu'un que ça pourrait aider.`,
      }
    case "rephrase":
      return {
        content: `Voici trois versions pour ${platform} :`,
        items: [
          "🎬 C'est en ligne ! La vidéo que vous attendiez est enfin disponible.",
          "Nouvelle vidéo : tout ce que j'aurais aimé savoir en commençant.",
          "Elle est là ✨ 10 minutes pour changer votre façon de créer.",
        ],
      }
    case "hashtags":
      return {
        content: `Hashtags suggérés pour ${platform} (mélangez populaires et spécialisés) :`,
        items: ["#creationdecontenu", "#productivite", "#reelsfrance", "#entrepreneure", "#conseilsinstagram", "#organisation", "#marketingdigital", "#creatrice"],
      }
    case "ideas":
      return {
        content: "Voici 5 idées de contenus faciles à produire :",
        items: [
          "Avant / après : votre espace de travail en 15 secondes",
          "Les 3 outils que vous utilisez tous les jours (carrousel)",
          "Une erreur que vous ne referez plus (vidéo face caméra)",
          "Répondre à la question la plus posée en commentaire",
          "Coulisses : préparer une semaine de contenus en 2 heures",
        ],
      }
    case "script":
      return {
        content: `Script (≈ 30 s) — ${capitalizeFirst(subject)}\n\n[0-3 s] HOOK : « Voici la routine qui m'a fait gagner 5 heures par semaine. »\n[3-12 s] Étape 1 : je bloque 2 h le lundi pour écrire tous mes posts.\n[12-22 s] Étape 2 : je tourne 4 vidéos d'affilée, même tenue, même lumière.\n[22-28 s] Étape 3 : je programme tout d'un coup dans SoxialConnect.\n[28-30 s] CTA : « Abonne-toi pour la suite. »`,
      }
    case "tone":
    default:
      return { content: `Voici votre texte adapté au ton demandé : ${capitalizeFirst(subject)} ${emoji}` }
  }
}

function capitalizeFirst(s: string) {
  return s.charAt(0).toUpperCase() + s.slice(1)
}

export const mockConversations: AIConversation[] = [
  {
    id: "conv-1",
    title: "Légende atelier",
    task: "caption",
    platform: "instagram",
    tone: "inspiring",
    updatedAt: minutesAgo(60 * 26),
    messages: [
      { id: "cm-1", role: "user", content: "Une légende pour la photo de mon nouvel atelier", task: "caption", createdAt: minutesAgo(60 * 26 + 2) },
      { id: "cm-2", role: "assistant", content: "✨ Petit aperçu de ce qui se prépare… La photo de mon nouvel atelier ! Dites-moi en commentaire ce que vous en pensez 👇", task: "caption", createdAt: minutesAgo(60 * 26) },
    ],
  },
  {
    id: "conv-2",
    title: "Idées de novembre",
    task: "ideas",
    platform: null,
    tone: "friendly",
    updatedAt: minutesAgo(60 * 24 * 3),
    messages: [
      { id: "cm-3", role: "user", content: "Des idées de contenus pour novembre", task: "ideas", createdAt: minutesAgo(60 * 24 * 3 + 1) },
      {
        id: "cm-4",
        role: "assistant",
        content: "Voici 5 idées de contenus faciles à produire :",
        items: ["Bilan d'automne en photos", "Préparer les fêtes : checklist", "Votre process en 5 étapes", "Question/réponse en live", "Le projet dont vous êtes le plus fière"],
        task: "ideas",
        createdAt: minutesAgo(60 * 24 * 3),
      },
    ],
  },
  {
    id: "conv-3",
    title: "Hashtags productivité",
    task: "hashtags",
    platform: "tiktok",
    tone: "fun",
    updatedAt: minutesAgo(60 * 24 * 6),
    messages: [],
  },
]
