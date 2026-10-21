import { CalendarClock, Captions, Check, Download, LoaderCircle, PlayCircle, RotateCcw, Scissors, Send, TriangleAlert } from "lucide-react"
import { useId, useState } from "react"
import { useNavigate } from "react-router"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { cn } from "@/lib/utils"
import type { ClipperClip } from "@/types"
import { CLIP_STATUS, RENDER_STEPS, SCORE_CRITERIA, SUGGESTION_ORIGIN, defaultRenderOptions, formatDuration, formatTimecode, type RenderOptionsValue } from "./labels"
import { RenderOptions } from "./RenderOptions"

/** Prochaine heure pleine (au moins 30 min plus tard) : proposée par « Planifier ». */
function nextSlot(): string {
  const at = new Date(Date.now() + 30 * 60_000)
  at.setMinutes(0, 0, 0)
  at.setHours(at.getHours() + 1)
  return at.toISOString()
}

/**
 * Un passage (proposé par l'IA, par l'analyse locale, ou choisi) et son clip : aperçu dans le lecteur
 * YouTube, options de rendu, étapes du rendu (préparation → rendu → validation → publication), puis
 * clip prêt : lecture, téléchargement (URL signée), publication ou programmation via le composer.
 * « Clip prêt » n'apparaît qu'une fois le fichier validé par FFprobe et publié dans la médiathèque.
 */
/** Libellé de l'étape « Rendu » selon la sous-étape réelle transmise par le serveur. */
function renderDetail(progress: NonNullable<ClipperClip["renderProgress"]>): string {
  switch (progress.step) {
    case "cutting":
      return "Découpe (FFmpeg)"
    case "composing":
      return progress.percent !== null ? `Composition ${progress.percent} %` : "Composition"
    case "fallback":
      return "Rendu de secours (FFmpeg)"
    default:
      return "Rendu"
  }
}

export function ClipCard({
  clip,
  index,
  busy,
  disabled,
  disabledReason,
  canSubtitle,
  onCreate,
  onPreview,
  selected,
  onSelectedChange,
}: {
  clip: ClipperClip
  index?: number
  busy: boolean
  disabled: boolean
  /** Pourquoi la génération est impossible (source absente…), affiché sous le bouton. */
  disabledReason?: string
  canSubtitle: boolean
  onCreate: (options: RenderOptionsValue) => void
  /** Aperçu du passage dans le lecteur YouTube (avant tout MP4). */
  onPreview?: () => void
  selected?: boolean
  onSelectedChange?: (selected: boolean) => void
}) {
  const navigate = useNavigate()
  const selectId = useId()
  const [options, setOptions] = useState<RenderOptionsValue>(() => ({
    ...defaultRenderOptions(clip.aspectRatio),
    subtitles: clip.subtitles,
    subtitleStyle: clip.subtitleStyle ?? defaultRenderOptions(clip.aspectRatio).subtitleStyle,
  }))
  const status = CLIP_STATUS[clip.status]
  const canCreate = clip.status === "suggested" || clip.status === "edited" || clip.status === "failed"
  const stageIndex = clip.renderStage ? RENDER_STEPS.findIndex((s) => s.stage === clip.renderStage) : -1

  return (
    <article className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        {onSelectedChange && canCreate && (
          <Checkbox id={selectId} checked={Boolean(selected)} onCheckedChange={(v) => onSelectedChange(v === true)} aria-label={`Sélectionner « ${clip.title} »`} className="mt-1" />
        )}
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
            {SUGGESTION_ORIGIN[clip.source]}{clip.source !== "manual" && index !== undefined ? ` · ${index + 1}` : ""}
          </p>
          <h3 className="mt-0.5 font-semibold text-ink">{clip.title}</h3>
        </div>
        <Badge variant={status.variant}>{status.label}</Badge>
      </header>

      <p className="text-sm text-ink-soft">
        <span className="font-medium text-ink tabular-nums">{formatTimecode(clip.startMs)} → {formatTimecode(clip.endMs)}</span>
        <span className="text-ink-muted"> · {formatDuration(clip.durationMs)}</span>
        {clip.score !== null && <span className="text-ink-muted"> · score {Math.round(clip.score)}/100</span>}
        {clip.subtitles && <span className="ml-1 inline-flex items-center gap-1 text-ink-muted"><Captions className="size-3.5" aria-hidden="true" /> sous-titré</span>}
      </p>

      {clip.hook && (
        <p className="rounded-lg border-l-4 border-brand-500 bg-brand-50 px-3 py-2 text-sm font-semibold text-ink wrap-anywhere">« {clip.hook} »</p>
      )}

      {clip.scoreBreakdown && (
        <details className="rounded-lg border border-border px-3 py-2 text-sm">
          <summary className="cursor-pointer font-medium text-ink">
            Détail du score{clip.confidence !== null ? ` · confiance de l'IA ${clip.confidence} %` : ""}
          </summary>
          <dl className="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
            {SCORE_CRITERIA.map((c) => {
              const value = clip.scoreBreakdown![c.key]
              return (
                <div key={c.key} className="flex min-w-0 items-center gap-2">
                  <dt className="w-24 shrink-0 text-ink-muted">{c.label}</dt>
                  <dd className="flex min-w-0 flex-1 items-center gap-2">
                    <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                      <span className="block h-full rounded-full bg-brand-500" style={{ width: `${Math.max(0, Math.min(100, value))}%` }} />
                    </span>
                    <span className="w-8 shrink-0 text-right tabular-nums text-ink">{value}</span>
                  </dd>
                </div>
              )
            })}
          </dl>
        </details>
      )}

      {(clip.topic || clip.reason) && (
        <dl className="space-y-1.5 text-sm">
          {clip.topic && (
            <div>
              <dt className="font-medium text-ink">Sujet</dt>
              <dd className="line-clamp-3 text-ink-soft">{clip.topic}</dd>
            </div>
          )}
          {clip.reason && (
            <div>
              <dt className="font-medium text-ink">Pourquoi ce passage</dt>
              <dd className="text-ink-soft">{clip.reason}</dd>
            </div>
          )}
        </dl>
      )}

      {onPreview && (
        <Button variant="outline" size="sm" className="self-start" onClick={onPreview}>
          <PlayCircle /> Aperçu du passage
        </Button>
      )}

      {clip.status === "failed" && clip.errorMessage && (
        <p role="alert" className="rounded-lg bg-danger-50 p-2.5 text-sm text-danger-700">{clip.errorMessage}</p>
      )}

      {clip.status === "rendering" && (
        <ol className="grid grid-cols-2 gap-1.5 sm:grid-cols-4" aria-label="Avancement du rendu" role="status">
          {RENDER_STEPS.map((step, i) => {
            const done = i < stageIndex
            const active = i === stageIndex || (stageIndex === -1 && i === 0)
            return (
              <li
                key={step.stage}
                aria-current={active ? "step" : undefined}
                className={cn(
                  "flex items-center gap-1.5 rounded-lg border px-2 py-1.5 text-xs",
                  done ? "border-success-100 bg-success-50 text-success-700" : active ? "border-brand-200 bg-brand-50 text-brand-700" : "border-border text-ink-muted",
                )}
              >
                {done ? <Check className="size-3.5 shrink-0" aria-hidden="true" /> : active ? <LoaderCircle className="size-3.5 shrink-0 animate-spin" aria-hidden="true" /> : <span className="size-3.5 shrink-0 rounded-full border border-current" aria-hidden="true" />}
                <span className="min-w-0">{active && step.stage === "rendering" && clip.renderProgress ? renderDetail(clip.renderProgress) : step.label}</span>
              </li>
            )
          })}
        </ol>
      )}
      {clip.status === "rendering" && clip.queuedMs !== null && clip.queuedMs >= 60_000 && (
        <p role="status" className={cn("rounded-lg p-2.5 text-sm", clip.queuedMs >= 300_000 ? "bg-warning-50 text-warning-700" : "bg-muted text-ink-soft")}>
          En file d'attente depuis {Math.floor(clip.queuedMs / 60_000)} min
          {clip.queuedMs >= 300_000 ? " : aucun worker vidéo ne semble traiter la file. Vérifiez que les workers sont démarrés (npm run dev)." : " (un autre traitement vidéo est peut-être en cours)."}
        </p>
      )}
      {clip.status === "rendering" && clip.renderProgress?.step === "composing" && clip.renderProgress.percent !== null && (
        <div className="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Composition du clip" aria-valuemin={0} aria-valuemax={100} aria-valuenow={clip.renderProgress.percent}>
          <div className="h-full rounded-full bg-brand-500 transition-[width]" style={{ width: `${clip.renderProgress.percent}%` }} />
        </div>
      )}

      {clip.status === "rendered" && clip.output && (
        <div className="space-y-2">
          {(clip.render?.audio === "source_without_audio" || clip.render?.audio === "source_silent") && (
            <p role="note" className="flex items-start gap-2 rounded-lg bg-warning-50 p-2.5 text-sm text-warning-700">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              <span>
                <span className="font-semibold">Clip sans son.</span>{" "}
                {clip.render.audio === "source_without_audio" ? "La vidéo source n'a pas de piste audio." : "Ce passage de la vidéo source est silencieux."}
              </span>
            </p>
          )}
          {clip.render?.fallback && (
            <p role="note" className="flex items-start gap-2 rounded-lg bg-warning-50 p-2.5 text-sm text-warning-700">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              <span>
                <span className="font-semibold">Rendu de secours FFmpeg — accroche non disponible.</span>{" "}
                La mise en forme habituelle n'a pas pu être appliquée : ce clip a été produit sans l'accroche en début de vidéo{clip.subtitles ? ", sous-titres compris" : ""}.
              </span>
            </p>
          )}
          {clip.output.url && (
            <video src={clip.output.url} controls preload="metadata" className="max-h-80 w-full rounded-xl bg-navy-950" aria-label={`Clip « ${clip.title} »`} />
          )}
          <div className="flex flex-wrap gap-2">
            {clip.output.downloadUrl && (
              <Button asChild variant="outline" size="sm">
                <a href={clip.output.downloadUrl}><Download /> Télécharger</a>
              </Button>
            )}
            <Button size="sm" onClick={() => navigate("/content/create", { state: { mediaIds: [clip.output!.id] } })}>
              <Send /> Publier
            </Button>
            <Button size="sm" variant="outline" onClick={() => navigate("/content/create", { state: { mediaIds: [clip.output!.id], scheduledAt: nextSlot() } })}>
              <CalendarClock /> Planifier
            </Button>
          </div>
          <p className="text-xs text-ink-muted">MP4 vérifié et enregistré dans votre médiathèque. Les réseaux qui n'acceptent pas encore la vidéo le signalent au moment de publier.</p>
        </div>
      )}

      {canCreate && (
        <div className="mt-auto space-y-3 border-t border-border pt-3">
          <RenderOptions value={options} onChange={setOptions} canSubtitle={canSubtitle} />
          <Button className="w-full" disabled={disabled || busy} onClick={() => onCreate(options)}>
            {busy ? <LoaderCircle className="animate-spin" /> : clip.status === "failed" ? <RotateCcw /> : <Scissors />}
            {clip.status === "failed" ? "Réessayer" : "Générer le clip"}
          </Button>
          {disabled && disabledReason && <p className="text-xs text-ink-muted">{disabledReason}</p>}
        </div>
      )}
    </article>
  )
}
