import { ArrowDown, ArrowUp, Eye, EyeOff, Lock, LockOpen, Plus, Trash2, Volume2, VolumeX } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useStudioAction } from "@/hooks/queries/videoStudio"
import { ApiError, apiErrorMessage } from "@/lib/http"
import { cn } from "@/lib/utils"
import * as studio from "@/services/videoStudio"
import type { StudioItemType, StudioProject, StudioTrack, StudioTrackType } from "@/types"
import { formatSeconds, ITEM_LABELS, msToSeconds, secondsToMs, TRACK_ACCEPTS, TRACK_LABELS } from "./labels"

const ITEM_COLORS: Record<StudioItemType, string> = {
  video: "bg-brand-500/85 text-white",
  image: "bg-sky-500/85 text-white",
  text: "bg-amber-400/90 text-navy-950",
  audio: "bg-emerald-500/85 text-white",
}

/**
 * Timeline structurelle : une ligne par piste (calque, de l'arrière-plan au premier plan), éléments
 * placés à l'échelle (temps en ms). Cliquer sur un élément le sélectionne pour l'inspecteur.
 */
export function StudioTimeline({ project, selectedId, onSelect, timeMs }: { project: StudioProject; selectedId: string | null; onSelect: (id: string | null) => void; timeMs: number }) {
  const [newType, setNewType] = useState<StudioTrackType>("overlay")
  const [addingTo, setAddingTo] = useState<StudioTrack | null>(null)
  const addTrack = useStudioAction(project.id, (type: StudioTrackType) => studio.createTrack(project.id, { type }, project.lockVersion))
  const scaleMs = Math.max(project.durationMs + 5000, 15000)
  const ticks = Array.from({ length: Math.floor(scaleMs / 5000) + 1 }, (_, i) => i * 5000)

  return (
    <div className="rounded-xl border border-border bg-card">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
        <h2 className="text-sm font-semibold text-ink">Timeline · {formatSeconds(project.durationMs)}</h2>
        <div className="flex items-center gap-2">
          <Select value={newType} onValueChange={(v) => setNewType(v as StudioTrackType)}>
            <SelectTrigger className="h-8 w-40 text-xs" aria-label="Type de la nouvelle piste"><SelectValue /></SelectTrigger>
            <SelectContent>
              {(Object.keys(TRACK_LABELS) as StudioTrackType[]).map((t) => <SelectItem key={t} value={t}>{TRACK_LABELS[t]}</SelectItem>)}
            </SelectContent>
          </Select>
          <Button size="sm" variant="outline" disabled={addTrack.isPending} onClick={() => addTrack.mutate(newType, { onError: (e) => toast.error(apiErrorMessage(e)) })}><Plus /> Piste</Button>
        </div>
      </div>

      <div className="overflow-x-auto">
        <div className="min-w-[720px]">
          <div className="grid grid-cols-[13rem_minmax(0,1fr)] border-b border-border text-[10px] text-ink-muted">
            <span className="px-3 py-1">Pistes</span>
            <div className="relative h-5">
              {ticks.map((t) => (
                <span key={t} className="absolute top-0.5 -translate-x-1/2 tabular-nums" style={{ left: `${(t / scaleMs) * 100}%` }}>{t / 1000} s</span>
              ))}
            </div>
          </div>
          {project.tracks.map((track, index) => (
            <TrackRow key={track.id} project={project} track={track} index={index} scaleMs={scaleMs} timeMs={timeMs} selectedId={selectedId} onSelect={onSelect} onAdd={() => setAddingTo(track)} />
          ))}
          {project.tracks.length === 0 && <p className="p-4 text-center text-sm text-ink-muted">Aucune piste : ajoutez-en une pour commencer.</p>}
        </div>
      </div>
      {addingTo && <AddItemDialog project={project} track={addingTo} onClose={() => setAddingTo(null)} onAdded={onSelect} />}
    </div>
  )
}

function TrackRow({ project, track, index, scaleMs, timeMs, selectedId, onSelect, onAdd }: {
  project: StudioProject
  track: StudioTrack
  index: number
  scaleMs: number
  timeMs: number
  selectedId: string | null
  onSelect: (id: string | null) => void
  onAdd: () => void
}) {
  const update = useStudioAction(project.id, (input: studio.TrackInput) => studio.updateTrack(track.id, input, project.lockVersion))
  const remove = useStudioAction(project.id, () => studio.deleteTrack(track.id))
  const onError = (e: unknown) => toast.error(apiErrorMessage(e))
  const busy = update.isPending || remove.isPending
  const editable = !track.locked && !busy

  return (
    <div className={cn("grid grid-cols-[13rem_minmax(0,1fr)] border-b border-border last:border-b-0", track.hidden && "opacity-60")}>
      <div className="flex flex-col gap-1 border-r border-border px-3 py-2">
        <span className="truncate text-xs font-semibold text-ink" title={track.name}>{track.name || TRACK_LABELS[track.type]} <span className="font-normal text-ink-muted">· {TRACK_LABELS[track.type]}</span></span>
        <div className="flex flex-wrap gap-0.5">
          <IconToggle label={track.locked ? "Déverrouiller la piste" : "Verrouiller la piste"} disabled={busy} onClick={() => update.mutate({ locked: !track.locked }, { onError })}>{track.locked ? <Lock /> : <LockOpen />}</IconToggle>
          {(track.type === "audio" || track.type === "video") && (
            <IconToggle label={track.muted ? "Rétablir le son" : "Couper le son"} disabled={!editable} onClick={() => update.mutate({ muted: !track.muted }, { onError })}>{track.muted ? <VolumeX /> : <Volume2 />}</IconToggle>
          )}
          <IconToggle label={track.hidden ? "Afficher la piste" : "Masquer la piste"} disabled={!editable} onClick={() => update.mutate({ hidden: !track.hidden }, { onError })}>{track.hidden ? <EyeOff /> : <Eye />}</IconToggle>
          <IconToggle label="Placer la piste en dessous" disabled={!editable || index === 0} onClick={() => update.mutate({ position: index - 1 }, { onError })}><ArrowUp /></IconToggle>
          <IconToggle label="Placer la piste au-dessus" disabled={!editable || index === project.tracks.length - 1} onClick={() => update.mutate({ position: index + 1 }, { onError })}><ArrowDown /></IconToggle>
          <IconToggle label="Supprimer la piste et ses éléments" disabled={!editable} onClick={() => remove.mutate(undefined, { onError })}><Trash2 /></IconToggle>
          <IconToggle label="Ajouter un élément" disabled={!editable} onClick={onAdd}><Plus /></IconToggle>
        </div>
      </div>
      <div className="relative h-14" onClick={() => onSelect(null)}>
        <span className="pointer-events-none absolute inset-y-0 w-px bg-danger-500/70" style={{ left: `${(timeMs / scaleMs) * 100}%` }} aria-hidden="true" />
        {track.items.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={(e) => {
              e.stopPropagation()
              onSelect(item.id)
            }}
            className={cn("absolute top-2 bottom-2 min-w-2 overflow-hidden rounded-md px-1.5 text-left text-[11px] font-medium", ITEM_COLORS[item.type], selectedId === item.id && "ring-2 ring-navy-950 ring-offset-1")}
            style={{ left: `${(item.startMs / scaleMs) * 100}%`, width: `${(item.durationMs / scaleMs) * 100}%` }}
            aria-pressed={selectedId === item.id}
            aria-label={`${ITEM_LABELS[item.type]} de ${formatSeconds(item.startMs)} à ${formatSeconds(item.endMs)}`}
          >
            <span className="block truncate">{item.type === "text" ? item.properties.text : ITEM_LABELS[item.type]}</span>
          </button>
        ))}
      </div>
    </div>
  )
}

function IconToggle({ label, disabled, onClick, children }: { label: string; disabled?: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <Button type="button" variant="ghost" size="icon" className="size-7 [&_svg]:size-3.5" aria-label={label} title={label} disabled={disabled} onClick={onClick}>
      {children}
    </Button>
  )
}

/** Ajout d'un élément sur une piste : type accepté par la piste, média du projet, position et durée. */
function AddItemDialog({ project, track, onClose, onAdded }: { project: StudioProject; track: StudioTrack; onClose: () => void; onAdded: (id: string) => void }) {
  const accepted = TRACK_ACCEPTS[track.type]
  const [type, setType] = useState<StudioItemType>(accepted[0])
  const candidates = project.assets.filter((a) => a.available && a.media?.type === type)
  const [assetId, setAssetId] = useState<string>("")
  const trackEnd = track.items.reduce((max, i) => Math.max(max, i.endMs), 0)
  const [start, setStart] = useState(String(msToSeconds(trackEnd)))
  const [duration, setDuration] = useState("3")
  const [text, setText] = useState("")
  const add = useStudioAction(project.id, () =>
    studio.addItem(track.id, {
      type,
      start_ms: secondsToMs(Number(start.replace(",", "."))),
      duration_ms: secondsToMs(Number(duration.replace(",", "."))),
      video_project_asset_id: type === "text" ? null : assetId || null,
      properties: type === "text" ? { text } : null,
    }, project.lockVersion),
  )
  const fieldError = (name: string) => (add.error instanceof ApiError ? add.error.field(name) : undefined)

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-[440px]">
        <DialogHeader>
          <DialogTitle>Ajouter un élément · {track.name || TRACK_LABELS[track.type]}</DialogTitle>
          <DialogDescription>Temps en secondes. L'élément ne doit pas chevaucher un autre élément de la piste.</DialogDescription>
        </DialogHeader>
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            add.mutate(undefined, {
              onSuccess: (item) => {
                onAdded(item.id)
                onClose()
              },
              onError: (err) => toast.error(apiErrorMessage(err)),
            })
          }}
        >
          {accepted.length > 1 && (
            <div className="space-y-1.5">
              <Label htmlFor="item-type">Type</Label>
              <Select value={type} onValueChange={(v) => { setType(v as StudioItemType); setAssetId("") }}>
                <SelectTrigger id="item-type"><SelectValue /></SelectTrigger>
                <SelectContent>{accepted.map((t) => <SelectItem key={t} value={t}>{ITEM_LABELS[t]}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          )}
          {type === "text" ? (
            <div className="space-y-1.5">
              <Label htmlFor="item-text">Texte affiché</Label>
              <Input id="item-text" value={text} onChange={(e) => setText(e.target.value)} maxLength={500} required />
              {fieldError("properties.text") && <p className="text-xs text-danger-700">{fieldError("properties.text")}</p>}
            </div>
          ) : (
            <div className="space-y-1.5">
              <Label htmlFor="item-asset">Média du projet</Label>
              {candidates.length === 0 ? (
                <p className="text-sm text-ink-muted">Aucun média « {ITEM_LABELS[type]} » disponible : ajoutez-en dans les médias du projet.</p>
              ) : (
                <Select value={assetId} onValueChange={setAssetId}>
                  <SelectTrigger id="item-asset"><SelectValue placeholder="Choisir un média" /></SelectTrigger>
                  <SelectContent>{candidates.map((a) => <SelectItem key={a.id} value={a.id}>{a.media?.name}</SelectItem>)}</SelectContent>
                </Select>
              )}
              {fieldError("video_project_asset_id") && <p className="text-xs text-danger-700">{fieldError("video_project_asset_id")}</p>}
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="item-start">Début (s)</Label>
              <Input id="item-start" value={start} onChange={(e) => setStart(e.target.value)} inputMode="decimal" />
              {fieldError("start_ms") && <p className="text-xs text-danger-700">{fieldError("start_ms")}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="item-duration">Durée (s)</Label>
              <Input id="item-duration" value={duration} onChange={(e) => setDuration(e.target.value)} inputMode="decimal" />
              {fieldError("duration_ms") && <p className="text-xs text-danger-700">{fieldError("duration_ms")}</p>}
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Annuler</Button>
            <Button type="submit" disabled={add.isPending || (type !== "text" && !assetId)}>Ajouter</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
