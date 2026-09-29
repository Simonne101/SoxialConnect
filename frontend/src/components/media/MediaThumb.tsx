import { FileText, Film, ImageIcon, Music, Play } from "lucide-react"
import { memo } from "react"
import { formatDuration } from "@/lib/format"
import { cn } from "@/lib/utils"
import type { MediaAsset } from "@/types"

const PLACEHOLDER_ICON = { image: ImageIcon, video: Film, audio: Music, document: FileText } as const

/**
 * Vignette d'un média : image (miniature signée ou asset local), dégradé fictif (démonstration)
 * ou emplacement neutre avec l'icône du type (vidéo sans miniature, audio, document).
 */
function MediaThumbBase({ media, className, showMeta = true }: { media: MediaAsset | undefined; className?: string; showMeta?: boolean }) {
  if (!media) return <div className={cn("rounded-lg bg-muted", className)} aria-hidden="true" />
  const Placeholder = PLACEHOLDER_ICON[media.type]
  return (
    <div className={cn("relative overflow-hidden rounded-lg bg-muted", className)}>
      {media.preview.kind === "image" ? (
        <img src={media.preview.src} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
      ) : media.preview.kind === "gradient" ? (
        <div className={cn("size-full", media.preview.className)} aria-hidden="true" />
      ) : (
        <span className="flex size-full items-center justify-center bg-gradient-to-br from-navy-900 to-navy-800 text-white/85" aria-hidden="true">
          <Placeholder className="size-1/3 max-h-10 max-w-10 min-h-4 min-w-4" />
        </span>
      )}
      {media.preview.kind === "gradient" && media.type === "document" && (
        <span className="absolute inset-0 flex items-center justify-center text-white/90" aria-hidden="true">
          <FileText className="size-7" />
        </span>
      )}
      {media.preview.kind === "gradient" && media.type === "audio" && (
        <span className="absolute inset-0 flex items-center justify-center text-white/90" aria-hidden="true">
          <Music className="size-7" />
        </span>
      )}
      {showMeta && media.type === "video" && media.durationMs && (
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
