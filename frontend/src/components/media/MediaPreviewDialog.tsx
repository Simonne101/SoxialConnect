import { Download, Pencil, SquarePen, Trash2, X } from "lucide-react"
import { useState } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
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
import { useUpdateMedia } from "@/hooks/queries/media"
import { formatBytes, formatDate, formatDuration } from "@/lib/format"
import type { MediaAsset, MediaFolder } from "@/types"
import { MediaThumb } from "./MediaThumb"

const TYPE_LABEL: Record<MediaAsset["type"], string> = { image: "Image", video: "Vidéo", audio: "Audio", document: "Document" }

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
  const [tag, setTag] = useState("")
  const [name, setName] = useState<string | null>(null)

  const save = (patch: Parameters<typeof update.mutate>[0]["patch"], message: string) =>
    media && update.mutate({ id: media.id, patch }, { onSuccess: () => toast.success(message) })

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
              <MediaThumb media={media} className="aspect-square w-full rounded-2xl md:aspect-[4/5]" />
              <div className="space-y-5">
                <div className="space-y-1.5">
                  <Label htmlFor="media-name" className="text-[13px] font-semibold">Nom</Label>
                  <div className="flex gap-2">
                    <Input id="media-name" value={name ?? media.name} onChange={(e) => setName(e.target.value)} />
                    <Button variant="outline" size="icon" disabled={!name || name === media.name} onClick={() => { save({ name: name ?? media.name }, "Nom mis à jour."); setName(null) }} aria-label="Enregistrer le nom">
                      <Pencil />
                    </Button>
                  </div>
                </div>
                <dl className="grid grid-cols-2 gap-3 text-sm">
                  <div><dt className="text-xs text-ink-muted">Ajouté le</dt><dd className="font-medium text-ink">{formatDate(media.createdAt)}</dd></div>
                  <div><dt className="text-xs text-ink-muted">Format</dt><dd className="font-medium text-ink">{media.mimeType}</dd></div>
                  {media.width && <div><dt className="text-xs text-ink-muted">Dimensions</dt><dd className="font-medium text-ink">{media.width} × {media.height}</dd></div>}
                  {media.durationMs && <div><dt className="text-xs text-ink-muted">Durée</dt><dd className="font-medium text-ink">{formatDuration(media.durationMs)}</dd></div>}
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
                        <button type="button" className="rounded-full p-0.5 hover:bg-brand-100" aria-label={`Retirer le tag ${t}`} onClick={() => save({ tags: media.tags.filter((x) => x !== t) }, "Tag retiré.")}>
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
                      const t = tag.trim().toLowerCase()
                      if (t && !media.tags.includes(t)) save({ tags: [...media.tags, t] }, "Tag ajouté.")
                      setTag("")
                    }}
                  >
                    <Input id="media-tag" value={tag} onChange={(e) => setTag(e.target.value)} placeholder="Ajouter un tag" className="h-9" />
                    <Button type="submit" size="sm" variant="secondary">Ajouter</Button>
                  </form>
                </div>
                <div className="flex flex-col gap-2 border-t border-border pt-4">
                  {(media.type === "image" || media.type === "video") && (
                    <Button onClick={() => navigate("/content/create", { state: { mediaIds: [media.id] } })}>
                      <SquarePen /> Utiliser dans une publication
                    </Button>
                  )}
                  <div className="flex gap-2">
                    <Button variant="outline" className="flex-1" disabled title="Disponible avec le stockage sécurisé (backend)">
                      <Download /> Télécharger
                    </Button>
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
