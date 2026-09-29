import { FileText, Music, Play } from "lucide-react"
import { memo } from "react"
import { formatDuration } from "@/lib/format"
import { cn } from "@/lib/utils"
import type { MediaAsset } from "@/types"

/** Vignette d'un média (dégradé fictif ou image locale) avec badge de type / durée. */
function MediaThumbBase({ media, className, showMeta = true }: { media: MediaAsset | undefined; className?: string; showMeta?: boolean }) {
  if (!media) return <div className={cn("rounded-lg bg-muted", className)} aria-hidden="true" />
  return (
    <div className={cn("relative overflow-hidden rounded-lg bg-muted", className)}>
      {media.preview.kind === "image" ? (
        <img src={media.preview.src} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
      ) : (
        <div className={cn("size-full", media.preview.className)} aria-hidden="true" />
      )}
      {media.type === "document" && (
        <span className="absolute inset-0 flex items-center justify-center text-white/90" aria-hidden="true">
          <FileText className="size-7" />
        </span>
      )}
      {media.type === "audio" && (
        <span className="absolute inset-0 flex items-center justify-center text-white/90" aria-hidden="true">
          <Music className="size-7" />
        </span>
      )}
      {showMeta && media.type === "video" && (
        <span className="absolute right-1.5 bottom-1.5 inline-flex items-center gap-1 rounded-md bg-navy-950/75 px-1.5 py-0.5 text-[11px] font-semibold text-white">
          <Play className="size-2.5 fill-current" aria-hidden="true" />
          {formatDuration(media.durationMs)}
        </span>
      )}
      {showMeta && media.isGif && (
        <span className="absolute right-1.5 bottom-1.5 rounded-md bg-navy-950/75 px-1.5 py-0.5 text-[11px] font-bold text-white">GIF</span>
      )}
    </div>
  )
}

export const MediaThumb = memo(MediaThumbBase)
