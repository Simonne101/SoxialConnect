import { Bot, Cpu, Hand } from "lucide-react"
import type { ClipperProject } from "@/types"

/**
 * Ce que l'analyse a réellement fait (ADR-047), en clair pour l'utilisateur :
 * IA disponible → passages proposés par l'IA ; IA indisponible → analyse locale (sans IA) ou
 * sélection manuelle. Jamais d'analyse locale présentée comme une IA.
 */
export function AnalysisSummary({ project }: { project: ClipperProject }) {
  const analysis = project.analysis
  if (!analysis) return null
  const transcription =
    analysis.transcription === "local" ? `transcription locale (${analysis.transcriptionEngine ?? "whisper.cpp"})`
    : analysis.transcription === "ai" ? "transcription par l'IA"
    : null

  const quota = analysis.fallback === "QUOTA_EXCEEDED" || analysis.fallback === "RATE_LIMITED"
  // Indisponibilité passagère (surcharge, délai, réseau) : une relance a du sens.
  const transient = ["PROVIDER_UNAVAILABLE", "TIMEOUT", "NETWORK_ERROR", "RATE_LIMITED"].includes(analysis.fallback ?? "")
  // Réponse de l'IA coupée ou illisible, ou passages tous écartés : jamais présentés comme « aucun passage ».
  const unusable = analysis.fallback === "INCOMPLETE_RESPONSE" || analysis.fallback === "INVALID_RESPONSE"
  const rejected = analysis.fallback === "NO_VALID_CLIPS"

  if (analysis.mode === "ai") {
    return (
      <Banner icon={Bot} tone="success" title="Analyse automatique par l'IA">
        {analysis.input === "youtube_url"
          ? analysis.recalibrated
            ? `Passages proposés par l'IA depuis le lien YouTube, puis recalés sur la vidéo réelle${transcription ? ` (${transcription}, fins de phrase)` : ""}.`
            : "Passages proposés par l'IA directement depuis le lien YouTube : prévisualisez-les dans le lecteur."
          : `Passages proposés par l'IA${transcription ? ` à partir d'une ${transcription}` : ""}.`}{" "}
        Vous pouvez aussi choisir un passage vous-même.
      </Banner>
    )
  }
  if (analysis.mode === "local") {
    return (
      <Banner icon={Cpu} tone="info" title="Analyse automatique locale (sans IA)">
        {analysis.fallback && analysis.fallback !== "NO_TRANSCRIPT" ? "L'IA est indisponible : passages proposés" : "Passages proposés"} d'après {transcription ? `une ${transcription} (phrases complètes, débit de parole)` : "les pauses de la vidéo"}. Vérifiez-les avant de créer vos clips.
        {analysis.notes.length > 0 && <Notes notes={analysis.notes} />}
      </Banner>
    )
  }

  return (
    <Banner icon={Hand} tone="warning" title={quota || transient ? "Service IA temporairement indisponible" : unusable ? "Réponse de l'IA inexploitable" : rejected ? "Passages de l'IA écartés" : "Aucun passage proposé"}>
      {quota || transient
        ? `Le service IA est temporairement indisponible. ${transient ? "Relancez l'analyse dans un instant, ou c" : "C"}hoisissez les passages manuellement.`
        : unusable
          ? "La réponse de l'IA a été interrompue ou est illisible : ce n'est pas une absence de passage. Relancez l'analyse."
          : rejected
            ? "L'IA a proposé des passages qui ne respectent pas les règles du Clipper (détail ci-dessous). Relancez l'analyse ou choisissez un passage vous-même."
            : analysis.input === "youtube_url"
              ? "Aucun passage n'a pu être proposé depuis le lien. Relancez l'analyse ; dès qu'une source vidéo autorisée sera disponible, elle sera aussi analysée."
              : "L'analyse automatique n'a proposé aucun passage. Vous pouvez sélectionner manuellement le passage à créer en clip."}
      {analysis.notes.length > 0 && <Notes notes={analysis.notes} />}
    </Banner>
  )
}

function Banner({ icon: Icon, tone, title, children }: { icon: typeof Bot; tone: "success" | "info" | "warning"; title: string; children: React.ReactNode }) {
  const tones = { success: "bg-success-50 text-success-700", info: "bg-brand-50 text-brand-700", warning: "bg-warning-50 text-warning-700" }
  return (
    <div role="status" className={`flex items-start gap-3 rounded-xl p-3 text-sm ${tones[tone]}`}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <div className="min-w-0">
        <p className="font-semibold">{title}</p>
        <div className="mt-0.5">{children}</div>
      </div>
    </div>
  )
}

function Notes({ notes }: { notes: string[] }) {
  return (
    <ul className="mt-1.5 list-disc space-y-0.5 pl-4 text-xs opacity-90">
      {notes.map((n) => <li key={n}>{n}</li>)}
    </ul>
  )
}
