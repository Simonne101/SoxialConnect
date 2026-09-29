import { Download, ExternalLink, Pencil, ShieldAlert, SquarePen, Trash2, X } from "lucide-react"
import { useState } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useMediaTag, useUpdateMedia } from "@/hooks/queries/media"
import { formatBytes, formatDate, formatDuration } from "@/lib/format"
import { apiErrorMessage } from "@/lib/http"
import { MEDIA_STATUS, SCAN_STATUS } from "@/lib/status"
import { normalizeTag } from "@/services/media"
import type { MediaAsset, MediaFolder } from "@/types"
import { MediaThumb } from "./MediaThumb"

const TYPE_LABEL: Record<MediaAsset["type"], string> = { image: "Image", video: "Vidéo", audio: "Audio", document: "Document" }

/** Aperçu réel si le fichier est disponible (URL signée), sinon la vignette. */
function Preview({ media }: { media: MediaAsset }) {
  if (media.url && media.type === "image") {
    return <img src={media.url} alt={media.name} className="max-h-[60dvh] w-full rounded-2xl bg-muted object-contain" />
  }
  if (media.url && media.type === "video") {
    return <video src={media.url} controls preload="metadata" className="max-h-[60dvh] w-full rounded-2xl bg-navy-950" aria-label={`Lecture de ${media.name}`} />
  }
  if (media.url && media.type === "audio") {
    return (
      <div className="flex flex-col gap-4">
        <MediaThumb media={media} className="aspect-video w-full rounded-2xl" />
        <audio src={media.url} controls preload="metadata" className="w-full" aria-label={`Lecture de ${media.name}`} />
      </div>
    )
  }
  return <MediaThumb media={media} className="aspect-square w-full rounded-2xl md:aspect-[4/5]" />
}

export function MediaPreviewDialog({
  media,
  folders,
  onOpenChange,
  onDelete,
}: {
  media: MediaAsset | null
  folders: MediaFolder[]
  onOpenChange: (o: boolean) => void
  onDelete: (m: MediaAsset) => void
}) {
  const navigate = useNavigate()
  const update = useUpdateMedia()
  const tagMutation = useMediaTag()
  const [tag, setTag] = useState("")
  const [name, setName] = useState<string | null>(null)

  const onError = (error: unknown) => toast.error(apiErrorMessage(error, "La modification a échoué. Réessayez."))
  const save = (patch: { name?: string; folderId?: string | null }, message: string) =>
    media && update.mutate({ id: media.id, patch }, { onSuccess: () => toast.success(message), onError })
  const changeTag = (value: string, action: "add" | "remove") =>
    media && tagMutation.mutate({ id: media.id, tag: value, action }, { onSuccess: () => toast.success(action === "add" ? "Tag ajouté." : "Tag retiré."), onError })

  return (
    <Dialog open={Boolean(media)} onOpenChange={(o) => { setName(null); onOpenChange(o) }}>
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-[860px]">
        {media && (
          <>
            <DialogHeader>
              <DialogTitle className="truncate pr-8">{media.name}</DialogTitle>
              <DialogDescription>{TYPE_LABEL[media.type]} · {formatBytes(media.sizeBytes)}</DialogDescription>
            </DialogHeader>
            <div className="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
              <div className="space-y-3">
                <Preview media={media} />
                {!media.usable && (
                  <p className="flex items-start gap-2 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700">
                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    {media.failureReason ?? "Ce fichier n'est pas encore disponible (traitement ou analyse de sécurité en cours)."}
                  </p>
                )}
              </div>
              <div className="space-y-5">
                <div className="space-y-1.5">
                  <Label htmlFor="media-name" className="text-[13px] font-semibold">Nom</Label>
                  <div className="flex gap-2">
                    <Input id="media-name" value={name ?? media.name} maxLength={200} onChange={(e) => setName(e.target.value)} />
                    <Button variant="outline" size="icon" disabled={!name?.trim() || name === media.name} onClick={() => { save({ name: name ?? media.name }, "Nom mis à jour."); setName(null) }} aria-label="Enregistrer le nom">
                      <Pencil />
                    </Button>
                  </div>
                </div>
                <dl className="grid grid-cols-2 gap-3 text-sm">
                  <div><dt className="text-xs text-ink-muted">Ajouté le</dt><dd className="font-medium text-ink">{formatDate(media.createdAt)}</dd></div>
                  <div><dt className="text-xs text-ink-muted">Format</dt><dd className="font-medium text-ink uppercase">{media.extension ?? media.mimeType}</dd></div>
                  {media.width && <div><dt className="text-xs text-ink-muted">Dimensions</dt><dd className="font-medium text-ink">{media.width} × {media.height}</dd></div>}
                  {media.durationMs && <div><dt className="text-xs text-ink-muted">Durée</dt><dd className="font-medium text-ink">{formatDuration(media.durationMs)}</dd></div>}
                  <div><dt className="text-xs text-ink-muted">Statut</dt><dd><StatusBadge meta={MEDIA_STATUS[media.status]} /></dd></div>
                  <div>
                    <dt className="text-xs text-ink-muted">Analyse antivirus</dt>
                    <dd><StatusBadge meta={SCAN_STATUS[media.scanStatus]} /></dd>
                  </div>
                </dl>
                <div className="space-y-1.5">
                  <Label htmlFor="media-folder" className="text-[13px] font-semibold">Dossier</Label>
                  <Select value={media.folderId ?? "none"} onValueChange={(v) => save({ folderId: v === "none" ? null : v }, "Média déplacé.")}>
                    <SelectTrigger id="media-folder" className="w-full"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">Aucun dossier</SelectItem>
                      {folders.map((f) => <SelectItem key={f.id} value={f.id}>{f.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="media-tag" className="text-[13px] font-semibold">Tags</Label>
                  <div className="flex flex-wrap gap-1.5">
                    {media.tags.map((t) => (
                      <span key={t} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pr-1 pl-2.5 text-xs font-medium text-brand-700">
                        {t}
                        <button type="button" className="rounded-full p-0.5 hover:bg-brand-100" aria-label={`Retirer le tag ${t}`} onClick={() => changeTag(t, "remove")}>
                          <X className="size-3" />
                        </button>
                      </span>
                    ))}
                    {media.tags.length === 0 && <span className="text-xs text-ink-muted">Aucun tag</span>}
                  </div>
                  <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                      e.preventDefault()
                      const t = normalizeTag(tag)
                      if (t && !media.tags.includes(t)) changeTag(t, "add")
                      setTag("")
                    }}
                  >
                    <Input id="media-tag" value={tag} maxLength={40} onChange={(e) => setTag(e.target.value)} placeholder="Ajouter un tag" className="h-9" />
                    <Button type="submit" size="sm" variant="secondary" disabled={!normalizeTag(tag)}>Ajouter</Button>
                  </form>
                </div>
                <div className="flex flex-col gap-2 border-t border-border pt-4">
                  {(media.type === "image" || media.type === "video") && (
                    <Button disabled={!media.usable} onClick={() => navigate("/content/create", { state: { mediaIds: [media.id] } })}>
                      <SquarePen /> Utiliser dans une publication
                    </Button>
                  )}
                  <div className="flex gap-2">
                    {media.downloadUrl ? (
                      <Button variant="outline" className="flex-1" asChild>
                        <a href={media.downloadUrl} rel="noopener">
                          {media.type === "document" ? <ExternalLink /> : <Download />} Télécharger
                        </a>
                      </Button>
                    ) : (
                      <Button variant="outline" className="flex-1" disabled title={media.usable ? "Téléchargement indisponible en démonstration" : "Fichier pas encore disponible"}>
                        <Download /> Télécharger
                      </Button>
                    )}
                    <Button variant="destructive-soft" className="flex-1" onClick={() => onDelete(media)}>
                      <Trash2 /> Supprimer
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
