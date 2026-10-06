import { Film, Music } from "lucide-react"
import { MediaThumb } from "@/components/media/MediaThumb"
import { Slider } from "@/components/ui/slider"
import { cn } from "@/lib/utils"
import type { StudioAsset, StudioItem, StudioProject } from "@/types"
import { formatSeconds } from "./labels"

/**
 * Aperçu INDICATIF au format du projet : à l'instant choisi, les visuels et textes actifs, du calque
 * d'arrière-plan au premier plan. Ce n'est pas un rendu : la vidéo finale sera produite par le serveur.
 */
export function StudioPreview({ project, timeMs, onTimeChange }: { project: StudioProject; timeMs: number; onTimeChange: (ms: number) => void }) {
  const assets = new Map<string, StudioAsset>(project.assets.map((a) => [a.id, a]))
  const active = (item: StudioItem) => item.startMs <= timeMs && timeMs < item.endMs
  const layers = project.tracks.filter((t) => !t.hidden).flatMap((t) => t.items.filter(active).map((item) => ({ item, track: t })))
  const visuals = layers.filter(({ item }) => item.type === "image" || item.type === "video")
  const texts = layers.filter(({ item }) => item.type === "text")
  const sounds = layers.filter(({ item, track }) => item.type === "audio" && !track.muted)
  const duration = Math.max(project.durationMs, 1000)

  return (
    <div className="flex flex-col gap-3">
      <div className="flex justify-center rounded-xl bg-navy-950/90 p-3">
        <div
          className="relative w-full max-w-[420px] overflow-hidden rounded-md bg-black"
          style={{ aspectRatio: `${project.width} / ${project.height}`, maxHeight: "46dvh" }}
          aria-label="Aperçu indicatif de la vidéo"
        >
          {visuals.map(({ item }) => {
            const media = item.assetId ? assets.get(item.assetId)?.media : null
            return media ? (
              <div key={item.id} className="absolute inset-0">
                <MediaThumb media={media} showMeta={false} className="h-full w-full rounded-none" />
                {item.type === "video" && (
                  <span className="absolute top-2 left-2 inline-flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white"><Film className="size-3" aria-hidden="true" /> Vidéo</span>
                )}
              </div>
            ) : null
          })}
          {texts.map(({ item }) => (
            <p
              key={item.id}
              className={cn(
                "absolute inset-x-3 font-semibold whitespace-pre-wrap drop-shadow",
                item.properties.position === "top" ? "top-4" : item.properties.position === "center" ? "top-1/2 -translate-y-1/2" : "bottom-6",
                item.properties.align === "left" ? "text-left" : item.properties.align === "right" ? "text-right" : "text-center",
              )}
              style={{ color: item.properties.color ?? "#FFFFFF", fontSize: `${Math.max(10, Math.round((item.properties.font_size ?? 48) / 3))}px` }}
            >
              {item.properties.text}
            </p>
          ))}
          {layers.length === 0 && <p className="absolute inset-0 flex items-center justify-center p-4 text-center text-xs text-white/60">Rien à cet instant de la timeline.</p>}
          {sounds.length > 0 && (
            <span className="absolute right-2 bottom-2 inline-flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white"><Music className="size-3" aria-hidden="true" /> {sounds.length} son{sounds.length > 1 ? "s" : ""}</span>
          )}
        </div>
      </div>
      <div className="flex items-center gap-3">
        <Slider value={[Math.min(timeMs, duration)]} min={0} max={duration} step={100} onValueChange={([v]) => onTimeChange(v)} aria-label="Position dans la vidéo" className="flex-1" />
        <span className="w-24 text-right text-xs tabular-nums text-ink-muted">{formatSeconds(timeMs)} / {formatSeconds(project.durationMs)}</span>
      </div>
      <p className="text-xs text-ink-muted">Aperçu indicatif ({project.width} × {project.height}, {project.fps} i/s) : la vidéo finale sera produite par le serveur.</p>
    </div>
  )
}
