import { LoaderCircle, Scissors } from "lucide-react"
import { useId, useState, type FormEvent } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import type { ClipAspectRatio } from "@/types"
import { MAX_CLIP_MS, defaultRenderOptions, formatDuration, formatTimecode, parseTimecode, type RenderOptionsValue } from "./labels"
import { RenderOptions } from "./RenderOptions"

/**
 * Passage choisi par l'utilisateur (début, fin, format, sous-titres). Fonctionne sans aucune IA.
 * Au-delà de 60 s, la génération est bloquée et peut être ramenée à 60 s ; le serveur revérifie tout
 * (le formulaire ne protège rien).
 */
export function ManualClipForm({
  sourceDurationMs,
  minClipMs,
  defaultAspectRatio,
  canSubtitle,
  busy,
  disabled,
  onSubmit,
}: {
  sourceDurationMs: number
  minClipMs: number
  defaultAspectRatio: ClipAspectRatio
  canSubtitle: boolean
  busy: boolean
  disabled: boolean
  onSubmit: (input: { startMs: number; endMs: number; title: string } & RenderOptionsValue) => void
}) {
  const id = useId()
  const [start, setStart] = useState("00:00")
  const [end, setEnd] = useState(formatTimecode(Math.min(30_000, sourceDurationMs)))
  const [title, setTitle] = useState("")
  const [options, setOptions] = useState<RenderOptionsValue>(() => defaultRenderOptions(defaultAspectRatio))

  const startMs = parseTimecode(start)
  const endMs = parseTimecode(end)
  const duration = startMs !== null && endMs !== null ? endMs - startMs : null
  const error =
    startMs === null || endMs === null ? "Saisissez des temps au format mm:ss ou hh:mm:ss."
    : duration !== null && duration <= 0 ? "La fin doit être après le début."
    : duration !== null && duration > MAX_CLIP_MS ? "Un clip ne peut pas dépasser 60 secondes."
    : duration !== null && duration < minClipMs ? `Un clip doit durer au moins ${minClipMs / 1000} secondes.`
    : endMs !== null && endMs > sourceDurationMs ? `La vidéo dure ${formatTimecode(sourceDurationMs)}.`
    : null
  const tooLong = duration !== null && duration > MAX_CLIP_MS && startMs !== null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (error || startMs === null || endMs === null) return
    onSubmit({ startMs, endMs, title: title.trim(), ...options, subtitles: options.subtitles && canSubtitle })
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor={`${id}-start`}>Début</Label>
          <Input id={`${id}-start`} value={start} onChange={(e) => setStart(e.target.value)} inputMode="numeric" placeholder="mm:ss" className="tabular-nums" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`${id}-end`}>Fin</Label>
          <Input id={`${id}-end`} value={end} onChange={(e) => setEnd(e.target.value)} inputMode="numeric" placeholder="mm:ss" className="tabular-nums" aria-describedby={`${id}-duration`} />
        </div>
      </div>
      <p id={`${id}-duration`} className="text-sm text-ink-soft" aria-live="polite">
        Durée : <span className="font-medium text-ink">{duration !== null && duration > 0 ? formatDuration(duration) : "—"}</span>
        <span className="text-ink-muted"> (60 s maximum · vidéo de {formatTimecode(sourceDurationMs)})</span>
      </p>
      {error && (
        <div role="alert" className="flex flex-col gap-2 rounded-lg bg-danger-50 p-2.5 text-sm text-danger-700 sm:flex-row sm:items-center sm:justify-between">
          <span>{error}</span>
          {tooLong && (
            <Button type="button" variant="outline" size="sm" className="self-start sm:self-auto" onClick={() => setEnd(formatTimecode(Math.min(startMs + MAX_CLIP_MS, sourceDurationMs)))}>
              Ramener à 60 s
            </Button>
          )}
        </div>
      )}
      {/* Champs empilés : le formulaire occupe une colonne étroite à côté des passages proposés. */}
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor={`${id}-title`}>Titre (facultatif)</Label>
          <Input id={`${id}-title`} value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. : L'idée clé en 45 secondes" />
        </div>
        <RenderOptions value={options} onChange={setOptions} canSubtitle={canSubtitle} />
      </div>
      <Button type="submit" className="w-full" disabled={Boolean(error) || busy || disabled}>
        {busy ? <LoaderCircle className="animate-spin" /> : <Scissors />} Créer ce clip
      </Button>
    </form>
  )
}
