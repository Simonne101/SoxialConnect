import { useId } from "react"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { ASPECT_LABELS } from "@/components/video-studio/labels"
import type { ClipAspectRatio, ClipSubtitlePosition, ClipSubtitleSize, ClipSubtitleStyle } from "@/types"
import { SUBTITLE_POSITIONS, SUBTITLE_SIZES, type RenderOptionsValue } from "./labels"

/**
 * Options de rendu d'un clip : format, sous-titres incrustés (activés, position, taille).
 * Les sous-titres ne sont proposés que si la vidéo a été transcrite.
 */
export function RenderOptions({ value, onChange, canSubtitle }: { value: RenderOptionsValue; onChange: (v: RenderOptionsValue) => void; canSubtitle: boolean }) {
  const id = useId()
  const style = (patch: Partial<ClipSubtitleStyle>) => onChange({ ...value, subtitleStyle: { ...value.subtitleStyle, ...patch } })

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label htmlFor={`${id}-format`}>Format</Label>
        <Select value={value.aspectRatio} onValueChange={(v) => onChange({ ...value, aspectRatio: v as ClipAspectRatio })}>
          <SelectTrigger id={`${id}-format`} className="h-auto! min-h-10 w-full whitespace-normal text-left"><SelectValue className="line-clamp-none!" /></SelectTrigger>
          <SelectContent>
            {(Object.keys(ASPECT_LABELS) as ClipAspectRatio[]).map((r) => <SelectItem key={r} value={r}>{ASPECT_LABELS[r]}</SelectItem>)}
          </SelectContent>
        </Select>
      </div>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <Label htmlFor={`${id}-subtitles`}>Sous-titres incrustés</Label>
          <p className="text-xs text-ink-muted">
            {canSubtitle ? "Texte de la transcription, affiché pendant le clip." : "Indisponibles : la vidéo n'a pas été transcrite."}
          </p>
        </div>
        <Switch id={`${id}-subtitles`} checked={value.subtitles && canSubtitle} disabled={!canSubtitle} onCheckedChange={(checked) => onChange({ ...value, subtitles: checked })} />
      </div>
      {value.subtitles && canSubtitle && (
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={`${id}-position`}>Position</Label>
            <Select value={value.subtitleStyle.position} onValueChange={(v) => style({ position: v as ClipSubtitlePosition })}>
              <SelectTrigger id={`${id}-position`} className="w-full"><SelectValue /></SelectTrigger>
              <SelectContent>{SUBTITLE_POSITIONS.map((p) => <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={`${id}-size`}>Taille</Label>
            <Select value={value.subtitleStyle.size} onValueChange={(v) => style({ size: v as ClipSubtitleSize })}>
              <SelectTrigger id={`${id}-size`} className="w-full"><SelectValue /></SelectTrigger>
              <SelectContent>{SUBTITLE_SIZES.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}</SelectContent>
            </Select>
          </div>
        </div>
      )}
    </div>
  )
}
