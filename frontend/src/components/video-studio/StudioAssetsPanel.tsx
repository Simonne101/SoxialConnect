import { CircleAlert, ImagePlus, Trash2 } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { MediaPickerDialog } from "@/components/media/MediaPickerDialog"
import { MediaThumb } from "@/components/media/MediaThumb"
import { Button } from "@/components/ui/button"
import { useStudioAction } from "@/hooks/queries/videoStudio"
import { apiErrorMessage } from "@/lib/http"
import * as studio from "@/services/videoStudio"
import type { StudioProject } from "@/types"

/**
 * Médias du projet : choisis dans la médiathèque existante (aucune copie de fichier). Un média
 * supprimé de la médiathèque reste listé comme indisponible jusqu'à son remplacement.
 */
export function StudioAssetsPanel({ project }: { project: StudioProject }) {
  const [picking, setPicking] = useState(false)
  const attach = useStudioAction(project.id, async (ids: string[]) => {
    for (const id of ids) await studio.attachAsset(project.id, id)
  })
  const detach = useStudioAction(project.id, (assetId: string) => studio.detachAsset(assetId))
  const onError = (e: unknown) => toast.error(apiErrorMessage(e))

  return (
    <div className="space-y-2">
      {project.assets.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-ink-muted">Aucun média. Ajoutez des images, vidéos ou sons depuis la médiathèque.</p>
      ) : (
        <ul className="grid grid-cols-2 gap-2">
          {project.assets.map((asset) => (
            <li key={asset.id} className="rounded-xl border border-border bg-card p-1.5">
              {asset.media ? <MediaThumb media={asset.media} className="aspect-video w-full" /> : <div className="aspect-video rounded-lg bg-muted" aria-hidden="true" />}
              <div className="mt-1 flex items-center justify-between gap-1">
                <span className="truncate text-xs text-ink-soft" title={asset.media?.name}>{asset.media?.name ?? "Média supprimé"}</span>
                <Button variant="ghost" size="icon" className="size-6 shrink-0" aria-label="Retirer du projet" disabled={detach.isPending} onClick={() => detach.mutate(asset.id, { onError })}><Trash2 /></Button>
              </div>
              {!asset.available && <p className="flex items-center gap-1 text-[11px] text-warning-700"><CircleAlert className="size-3" aria-hidden="true" /> Indisponible</p>}
            </li>
          ))}
        </ul>
      )}
      <Button variant="outline" size="sm" className="w-full" disabled={attach.isPending} onClick={() => setPicking(true)}>
        <ImagePlus /> Ajouter depuis la médiathèque
      </Button>
      {picking && (
        <MediaPickerDialog
          open
          allowAudio
          initial={[]}
          onOpenChange={setPicking}
          onConfirm={(ids) => attach.mutate(ids, { onSuccess: () => toast.success("Médias ajoutés au projet."), onError })}
        />
      )}
    </div>
  )
}
