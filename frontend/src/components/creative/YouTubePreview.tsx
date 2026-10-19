import { PlayCircle } from "lucide-react"
import { formatTimecode } from "./labels"

/**
 * Aperçu d'un passage dans le lecteur YouTube intégré (ADR-049) : lecture du passage start → end
 * dans le lecteur officiel (domaine youtube-nocookie), sans aucun téléchargement. Ce n'est PAS un
 * clip : le MP4 n'existe qu'une fois généré à partir d'une source vidéo autorisée.
 */
export function YouTubePreview({ videoId, segment, title }: { videoId: string; segment: { startMs: number; endMs: number; label: string } | null; title: string }) {
  const params = new URLSearchParams({ rel: "0", modestbranding: "1", playsinline: "1" })
  if (segment) {
    params.set("start", String(Math.floor(segment.startMs / 1000)))
    params.set("end", String(Math.ceil(segment.endMs / 1000)))
    params.set("autoplay", "1")
  }
  const src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(videoId)}?${params.toString()}`

  return (
    <figure className="space-y-2">
      <div className="aspect-video w-full overflow-hidden rounded-xl bg-navy-950">
        <iframe
          key={src}
          src={src}
          title={segment ? `Aperçu du passage « ${segment.label} » — ${title}` : `Vidéo YouTube — ${title}`}
          className="size-full"
          allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
          referrerPolicy="strict-origin-when-cross-origin"
          allowFullScreen
        />
      </div>
      <figcaption className="flex items-start gap-1.5 text-xs text-ink-muted">
        <PlayCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span className="min-w-0">
          {segment
            ? <>Aperçu du passage <span className="font-medium text-ink tabular-nums">{formatTimecode(segment.startMs)} → {formatTimecode(segment.endMs)}</span> dans le lecteur YouTube — ce n'est pas encore un clip MP4.</>
            : "Lecteur YouTube : choisissez « Aperçu du passage » sur une proposition pour la regarder ici."}
        </span>
      </figcaption>
    </figure>
  )
}
