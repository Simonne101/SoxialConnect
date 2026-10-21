import { CalendarClock, Eye, LoaderCircle, Send } from "lucide-react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { MediaThumb } from "@/components/media/MediaThumb"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { formatDate } from "@/lib/format"
import type { MediaAsset } from "@/types"
import type { RecapLine } from "./recap"

/**
 * Récapitulatif avant l'envoi (publication immédiate ou programmation) : réseaux et comptes ciblés,
 * média, texte et visibilité réellement transmis. Rien n'est enregistré avant « Confirmer ».
 */
export function PublishRecapDialog({
  open,
  mode,
  scheduledAt,
  lines,
  media,
  pending,
  onOpenChange,
  onConfirm,
}: {
  open: boolean
  mode: "publish" | "schedule"
  scheduledAt: string | null
  lines: RecapLine[]
  media: MediaAsset[]
  pending: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: () => void
}) {
  const video = media.length === 1 && media[0].type === "video" && media[0].url ? media[0] : null
  return (
    <Dialog open={open} onOpenChange={(o) => !pending && onOpenChange(o)}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle>{mode === "publish" ? "Vérifier avant de publier" : "Vérifier avant de programmer"}</DialogTitle>
          <DialogDescription>
            {mode === "publish"
              ? `Envoi immédiat vers ${lines.length} destination${lines.length > 1 ? "s" : ""}, chacune publiée et suivie séparément.`
              : `Envoi prévu le ${scheduledAt ? formatDate(scheduledAt, "EEEE d MMMM yyyy 'à' HH:mm") : "—"} vers ${lines.length} destination${lines.length > 1 ? "s" : ""}, chacune publiée et suivie séparément.`}
          </DialogDescription>
        </DialogHeader>

        <section aria-labelledby="recap-media" className="space-y-2">
          <h3 id="recap-media" className="text-sm font-semibold">Média</h3>
          {media.length === 0 ? (
            <p className="text-sm text-ink-muted">Aucun média : texte seul.</p>
          ) : video ? (
            <figure className="space-y-1">
              <video src={video.url ?? undefined} controls preload="metadata" className="max-h-64 w-full rounded-xl bg-navy-950" aria-label={`Aperçu de ${video.name}`} />
              <figcaption className="truncate text-xs text-ink-muted">{video.name}</figcaption>
            </figure>
          ) : (
            <ul className="flex flex-wrap gap-2">
              {media.map((m) => (
                <li key={m.id} title={m.name}>
                  <MediaThumb media={m} className="size-16" />
                </li>
              ))}
            </ul>
          )}
        </section>

        <section aria-labelledby="recap-networks" className="space-y-2">
          <h3 id="recap-networks" className="text-sm font-semibold">Destinations ({lines.length})</h3>
          <ul className="space-y-2">
            {lines.map((line) => (
              <li key={line.key} className="rounded-xl border border-border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <PlatformIcon platform={line.platform} size="sm" />
                  <span className="font-medium text-ink">{line.network}</span>
                  <span className="truncate text-xs text-ink-muted">{line.account}</span>
                  {line.visibility && (
                    <span className="ml-auto inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-ink-soft">
                      <Eye className="size-3" aria-hidden="true" /> Visibilité : {line.visibility}
                    </span>
                  )}
                </div>
                <p className="mt-1.5 line-clamp-3 whitespace-pre-line text-xs text-ink-soft">{line.text || <span className="italic text-ink-muted">Sans texte</span>}</p>
              </li>
            ))}
          </ul>
        </section>

        <DialogFooter className="gap-2">
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Modifier
          </Button>
          <Button type="button" disabled={pending} onClick={onConfirm}>
            {pending ? <LoaderCircle className="animate-spin" /> : mode === "publish" ? <Send /> : <CalendarClock />}
            {mode === "publish" ? "Confirmer et publier" : "Confirmer la programmation"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
