import { Heart, ImageOff, MessageCircle, Play, Repeat2, Send } from "lucide-react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { MediaThumb } from "@/components/media/MediaThumb"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { PLATFORMS } from "@/lib/platforms"
import { cn } from "@/lib/utils"
import type { MediaAsset, PlatformKey } from "@/types"
import type { VariantDraft } from "./model"

/** Aperçu approximatif d'une variante — le rendu réel dépend de chaque plateforme. */
export function VariantPreview({
  platform,
  text,
  variant,
  media,
  accountName,
}: {
  platform: PlatformKey
  text: string
  variant: VariantDraft
  media: MediaAsset[]
  accountName: string
}) {
  const cfg = PLATFORMS[platform]
  const first = media[0]
  const title = variant.options.title
  const vertical = platform === "tiktok" || variant.format === "reel" || variant.format === "short" || variant.format === "story"
  const ratio = vertical ? "9 / 16" : cfg.preview.ratio
  const renderText = (t: string) =>
    t.split(/(\s+)/).map((w, i) =>
      w.startsWith("#") ? (
        <span key={i} className="text-brand-600">
          {w}
        </span>
      ) : (
        w
      ),
    )

  return (
    <article className="overflow-hidden rounded-2xl border border-border bg-card shadow-card" aria-label={`Aperçu ${cfg.name}`}>
      <header className="flex items-center gap-2.5 px-3.5 py-3">
        <Avatar className="size-8">
          <AvatarFallback className="bg-brand-gradient text-[11px] font-semibold text-white">MD</AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-semibold text-ink">{accountName}</p>
          <p className="text-[11px] text-ink-muted">À l'instant</p>
        </div>
        <PlatformIcon platform={platform} size="xs" />
      </header>

      {(platform === "youtube" || platform === "pinterest") && title && <p className="px-3.5 pb-2 text-sm font-semibold text-ink">{title}</p>}

      {(first || cfg.requiresMedia) && (
        <div className={cn("relative mx-auto w-full bg-muted", vertical && "max-w-[220px]")} style={{ aspectRatio: ratio }}>
          {first ? (
            <MediaThumb media={first} className="absolute inset-0 rounded-none" showMeta={false} />
          ) : (
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-ink-muted">
              <ImageOff className="size-6" aria-hidden="true" />
              <span className="text-xs">Ajoutez un média</span>
            </div>
          )}
          {first?.type === "video" && (
            <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
              <span className="inline-flex size-11 items-center justify-center rounded-full bg-navy-950/60 text-white">
                <Play className="size-5 fill-current" />
              </span>
            </span>
          )}
          {media.length > 1 && (
            <span className="absolute top-2 right-2 rounded-full bg-navy-950/70 px-2 py-0.5 text-[10px] font-semibold text-white">1/{media.length}</span>
          )}
        </div>
      )}

      {platform !== "youtube" && (
        <p className="line-clamp-5 px-3.5 py-3 text-[13px] leading-relaxed whitespace-pre-line text-ink-soft">
          {text.trim() ? renderText(text) : <span className="text-ink-muted italic">Votre texte apparaîtra ici…</span>}
        </p>
      )}

      <footer className="flex items-center gap-4 border-t border-border px-3.5 py-2.5 text-ink-muted" aria-hidden="true">
        <Heart className="size-4" />
        <MessageCircle className="size-4" />
        {platform === "x" || platform === "threads" ? <Repeat2 className="size-4" /> : <Send className="size-4" />}
        <span className="ml-auto text-[11px]">{cfg.preview.width} × {cfg.preview.height}</span>
      </footer>
    </article>
  )
}
