import { CircleAlert, Clapperboard, Download, LoaderCircle } from "lucide-react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Progress } from "@/components/ui/progress"
import { useStudioAction } from "@/hooks/queries/videoStudio"
import { apiErrorMessage } from "@/lib/http"
import { formatRelative } from "@/lib/format"
import * as studio from "@/services/videoStudio"
import type { StudioProject } from "@/types"
import { RENDER_LABELS } from "./labels"

/**
 * Rendus : la demande fige la timeline et la met en file ; le serveur produit le MP4 (FFmpeg), le
 * valide puis l'ajoute à la médiathèque. Lecture et téléchargement par URL signée uniquement.
 */
export function StudioRenderPanel({ project, rendererAvailable }: { project: StudioProject; rendererAvailable: boolean }) {
  const request = useStudioAction(project.id, () => studio.requestRender(project.id, project.lockVersion))
  const cancel = useStudioAction(project.id, (renderId: string) => studio.cancelRender(renderId))
  const active = project.renders.some((r) => r.status === "queued" || r.status === "rendering")
  const empty = project.tracks.every((t) => t.items.length === 0)

  return (
    <div className="space-y-3">
      {!rendererAvailable && (
        <p className="flex gap-2 rounded-xl bg-warning-50 p-3 text-xs text-warning-700">
          <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
          Le moteur de rendu (FFmpeg) n'est pas disponible sur le serveur : un rendu peut être planifié, mais il échouera tant qu'il ne sera pas installé.
        </p>
      )}
      <Button
        className="w-full"
        disabled={active || empty || request.isPending}
        onClick={() => request.mutate(undefined, { onSuccess: (r) => toast.info(r.message ?? "Rendu planifié."), onError: (e) => toast.error(apiErrorMessage(e)) })}
      >
        {request.isPending ? <LoaderCircle className="animate-spin" /> : <Clapperboard />} Générer la vidéo
      </Button>
      {empty && <p className="text-xs text-ink-muted">Ajoutez au moins un élément à la timeline pour lancer un rendu.</p>}
      {project.renders.length > 0 && (
        <ul className="space-y-2">
          {project.renders.map((r) => (
            <li key={r.id} className="rounded-xl border border-border p-2.5 text-xs">
              <div className="flex items-center justify-between gap-2">
                <Badge variant={RENDER_LABELS[r.status].tone}>{RENDER_LABELS[r.status].label}</Badge>
                <span className="text-ink-muted">{formatRelative(r.createdAt)}</span>
              </div>
              {r.status === "rendering" && (
                <div className="mt-2 flex items-center gap-2">
                  <Progress value={r.progress} className="h-1.5 flex-1" aria-label="Progression du rendu" />
                  <span className="tabular-nums text-ink-muted">{r.progress} %</span>
                </div>
              )}
              {r.status === "succeeded" && r.output?.url && (
                <div className="mt-2 space-y-2">
                  <video src={r.output.url} controls preload="metadata" className="w-full rounded-lg bg-black" aria-label="Vidéo produite" />
                  {r.output.downloadUrl && (
                    <Button asChild variant="outline" size="sm" className="w-full">
                      <a href={r.output.downloadUrl}><Download /> Télécharger le MP4</a>
                    </Button>
                  )}
                  <p className="text-ink-muted">Également disponible dans votre médiathèque.</p>
                </div>
              )}
              {r.status === "succeeded" && !r.output?.url && <p className="mt-1.5 text-ink-soft">La vidéo produite n'est plus disponible dans la médiathèque.</p>}
              {r.errorMessage && <p className="mt-1.5 text-ink-soft">{r.errorMessage}</p>}
              {(r.status === "queued" || r.status === "rendering") && (
                <Button variant="outline" size="sm" className="mt-2" disabled={cancel.isPending} onClick={() => cancel.mutate(r.id, { onError: (e) => toast.error(apiErrorMessage(e)) })}>Annuler</Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
