import { Check, CircleAlert, LoaderCircle } from "lucide-react"
import { cn } from "@/lib/utils"
import type { ClipperProject } from "@/types"

type StepState = "done" | "active" | "todo" | "attention"

const ANALYZING = ["pending", "analyzing", "transcribing", "detecting"]

/**
 * Parcours URL-first de bout en bout (ADR-049), état RÉEL de chaque étape : identification →
 * analyse → source → transcription → préparation des clips → rendu → clip prêt.
 * « Action requise » quand une étape attend l'utilisateur ; jamais d'étape présentée comme faite
 * sans l'avoir été (ex. transcription absente).
 */
export function ClipperPipeline({ project }: { project: ClipperProject }) {
  const analyzing = ANALYZING.includes(project.status)
  const source = project.sourceStatus.status
  const sourceBlocked = !project.renderable && ["unavailable", "failed", "expired"].includes(source)
  const rendering = project.clips.some((c) => c.status === "rendering")
  const rendered = project.clips.some((c) => c.status === "rendered")
  const proposals = project.clips.some((c) => c.source !== "manual")

  const steps: { label: string; state: StepState }[] = [
    { label: "Vidéo identifiée", state: "done" },
    {
      label: "Analyse",
      state: project.status === "failed" ? "attention" : analyzing && !project.renderable ? "active" : project.analysis ? "done" : analyzing ? "active" : "todo",
    },
    { label: project.renderable ? "Source prête" : sourceBlocked ? "Action requise" : "Source en préparation", state: project.renderable ? "done" : sourceBlocked ? "attention" : "active" },
    {
      label: "Transcription",
      state: project.hasTranscript ? "done" : project.status === "transcribing" ? "active" : project.renderable && !analyzing && project.analysis ? "attention" : "todo",
    },
    { label: "Préparation des clips", state: project.renderable && !analyzing && project.analysis ? (proposals ? "done" : "attention") : project.renderable && analyzing ? "active" : "todo" },
    { label: "Rendu", state: rendering ? "active" : rendered ? "done" : "todo" },
    { label: "Clip prêt", state: rendered ? "done" : "todo" },
  ]

  return (
    <ol className="grid grid-cols-2 gap-2 sm:grid-cols-4 2xl:grid-cols-7" aria-label="Avancement du clipping">
      {steps.map((step) => (
        <li
          key={step.label}
          aria-current={step.state === "active" ? "step" : undefined}
          className={cn(
            "flex min-w-0 items-center gap-2 rounded-xl border px-3 py-2 text-sm",
            step.state === "done" && "border-success-100 bg-success-50 text-success-700",
            step.state === "active" && "border-brand-200 bg-brand-50 text-brand-700",
            step.state === "attention" && "border-warning-100 bg-warning-50 text-warning-700",
            step.state === "todo" && "border-border text-ink-muted",
          )}
        >
          {step.state === "done" ? <Check className="size-4 shrink-0" aria-hidden="true" />
            : step.state === "active" ? <LoaderCircle className="size-4 shrink-0 animate-spin" aria-hidden="true" />
            : step.state === "attention" ? <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
            : <span className="size-4 shrink-0 rounded-full border border-current" aria-hidden="true" />}
          <span className="min-w-0 break-words">{step.label}</span>
        </li>
      ))}
    </ol>
  )
}
