import { Trash2 } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useStudioAction } from "@/hooks/queries/videoStudio"
import { ApiError, apiErrorMessage } from "@/lib/http"
import * as studio from "@/services/videoStudio"
import type { StudioItem, StudioItemInput, StudioItemProperties, StudioProject } from "@/types"
import { ITEM_LABELS, msToSeconds, secondsToMs, TRACK_ACCEPTS, TRACK_LABELS } from "./labels"

const NO_SCENE = "none"

/** Inspecteur de l'élément sélectionné : déplacer, redimensionner, contenu ; validation finale par le serveur. */
export function StudioItemInspector({ project, item, onDeleted }: { project: StudioProject; item: StudioItem; onDeleted: () => void }) {
  const [start, setStart] = useState(String(msToSeconds(item.startMs)))
  const [duration, setDuration] = useState(String(msToSeconds(item.durationMs)))
  const [sourceStart, setSourceStart] = useState(item.sourceStartMs === null ? "" : String(msToSeconds(item.sourceStartMs)))
  const [trackId, setTrackId] = useState(item.trackId)
  const [sceneId, setSceneId] = useState(item.sceneId ?? NO_SCENE)
  const [props, setProps] = useState<StudioItemProperties>(item.properties)
  const save = useStudioAction(project.id, (input: StudioItemInput) => studio.updateItem(item.id, input, project.lockVersion))
  const remove = useStudioAction(project.id, () => studio.deleteItem(item.id))
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const toMs = (v: string) => secondsToMs(Number(v.replace(",", ".")))
  const hasSource = item.type === "video" || item.type === "audio"
  const targets = project.tracks.filter((t) => TRACK_ACCEPTS[t.type].includes(item.type))

  const submit = () => {
    const input: StudioItemInput = {
      start_ms: toMs(start),
      duration_ms: toMs(duration),
      video_scene_id: sceneId === NO_SCENE ? null : sceneId,
      properties: Object.keys(props).length ? props : null,
    }
    if (trackId !== item.trackId) input.video_track_id = trackId
    if (hasSource) input.source_start_ms = sourceStart.trim() === "" ? null : toMs(sourceStart)
    save.mutate(input, { onSuccess: () => toast.success("Élément enregistré."), onError: (e) => toast.error(apiErrorMessage(e)) })
  }

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault()
        submit()
      }}
    >
      <p className="text-sm font-semibold text-ink">{ITEM_LABELS[item.type]}</p>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Début (s)" id="insp-start" error={fieldError("start_ms")}><Input id="insp-start" value={start} onChange={(e) => setStart(e.target.value)} inputMode="decimal" /></Field>
        <Field label="Durée (s)" id="insp-duration" error={fieldError("duration_ms")}><Input id="insp-duration" value={duration} onChange={(e) => setDuration(e.target.value)} inputMode="decimal" /></Field>
      </div>
      {hasSource && (
        <Field label="Début dans le média source (s)" id="insp-source" error={fieldError("source_start_ms")}>
          <Input id="insp-source" value={sourceStart} onChange={(e) => setSourceStart(e.target.value)} inputMode="decimal" placeholder="0" />
        </Field>
      )}
      <Field label="Piste" id="insp-track" error={fieldError("video_track_id") ?? fieldError("type")}>
        <Select value={trackId} onValueChange={setTrackId}>
          <SelectTrigger id="insp-track"><SelectValue /></SelectTrigger>
          <SelectContent>{targets.map((t) => <SelectItem key={t.id} value={t.id}>{t.name || TRACK_LABELS[t.type]}</SelectItem>)}</SelectContent>
        </Select>
      </Field>
      <Field label="Scène" id="insp-scene" error={fieldError("video_scene_id")}>
        <Select value={sceneId} onValueChange={setSceneId}>
          <SelectTrigger id="insp-scene"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value={NO_SCENE}>Aucune</SelectItem>
            {project.scenes.map((s, i) => <SelectItem key={s.id} value={s.id}>{s.name || `Scène ${i + 1}`}</SelectItem>)}
          </SelectContent>
        </Select>
      </Field>

      {item.type === "text" && (
        <>
          <Field label="Texte" id="insp-text" error={fieldError("properties.text")}>
            <Input id="insp-text" value={props.text ?? ""} onChange={(e) => setProps({ ...props, text: e.target.value })} maxLength={500} />
          </Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Position" id="insp-pos">
              <Select value={props.position ?? "bottom"} onValueChange={(v) => setProps({ ...props, position: v as StudioItemProperties["position"] })}>
                <SelectTrigger id="insp-pos"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value="top">Haut</SelectItem><SelectItem value="center">Centre</SelectItem><SelectItem value="bottom">Bas</SelectItem></SelectContent>
              </Select>
            </Field>
            <Field label="Taille" id="insp-size" error={fieldError("properties.font_size")}>
              <Input id="insp-size" type="number" min={12} max={200} value={props.font_size ?? 48} onChange={(e) => setProps({ ...props, font_size: Number(e.target.value) })} />
            </Field>
          </div>
          <Field label="Couleur" id="insp-color" error={fieldError("properties.color")}>
            <Input id="insp-color" type="color" value={props.color ?? "#ffffff"} onChange={(e) => setProps({ ...props, color: e.target.value.toUpperCase() })} className="h-9 w-20 p-1" />
          </Field>
        </>
      )}
      {(item.type === "video" || item.type === "audio") && (
        <Field label="Volume (0 à 100)" id="insp-volume" error={fieldError("properties.volume")}>
          <Input id="insp-volume" type="number" min={0} max={100} value={props.volume ?? 100} onChange={(e) => setProps({ ...props, volume: Number(e.target.value) })} />
        </Field>
      )}
      {(item.type === "video" || item.type === "image") && (
        <Field label="Cadrage" id="insp-fit">
          <Select value={props.fit ?? "cover"} onValueChange={(v) => setProps({ ...props, fit: v as StudioItemProperties["fit"] })}>
            <SelectTrigger id="insp-fit"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value="cover">Remplir</SelectItem><SelectItem value="contain">Contenir</SelectItem></SelectContent>
          </Select>
        </Field>
      )}

      <div className="flex gap-2 pt-1">
        <Button type="submit" className="flex-1" disabled={save.isPending}>Enregistrer</Button>
        <Button type="button" variant="outline" aria-label="Supprimer l'élément" disabled={remove.isPending} onClick={() => remove.mutate(undefined, { onSuccess: onDeleted, onError: (e) => toast.error(apiErrorMessage(e)) })}><Trash2 /></Button>
      </div>
    </form>
  )
}

function Field({ label, id, error, children }: { label: string; id: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <Label htmlFor={id} className="text-xs">{label}</Label>
      {children}
      {error && <p className="text-xs text-danger-700">{error}</p>}
    </div>
  )
}
