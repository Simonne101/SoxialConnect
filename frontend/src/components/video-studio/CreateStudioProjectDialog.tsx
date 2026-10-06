import { LoaderCircle } from "lucide-react"
import { useState } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { useCreateStudioProject } from "@/hooks/queries/videoStudio"
import { ApiError, apiErrorMessage } from "@/lib/http"
import type { StudioAspectRatio } from "@/types"
import { ASPECT_LABELS } from "./labels"

/** Nouveau projet : titre et format ; le serveur crée aussi les pistes et la première scène. */
export function CreateStudioProjectDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [title, setTitle] = useState("")
  const [description, setDescription] = useState("")
  const [aspectRatio, setAspectRatio] = useState<StudioAspectRatio>("9:16")
  const create = useCreateStudioProject()
  const navigate = useNavigate()
  const titleError = create.error instanceof ApiError ? create.error.field("title") : undefined

  const submit = () =>
    create.mutate(
      { title: title.trim(), aspectRatio, description: description.trim() || undefined },
      {
        onSuccess: (project) => {
          toast.success("Projet vidéo créé.")
          onOpenChange(false)
          navigate(`/video-studio/${project.id}`)
        },
        onError: (e) => toast.error(apiErrorMessage(e)),
      },
    )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>Nouveau projet vidéo</DialogTitle>
          <DialogDescription>Choisissez un nom et un format. Les pistes Vidéo, Texte et Audio sont prêtes dès la création.</DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            submit()
          }}
        >
          <div className="space-y-1.5">
            <Label htmlFor="studio-title">Nom du projet</Label>
            <Input id="studio-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} required aria-invalid={Boolean(titleError)} />
            {titleError && <p className="text-xs text-danger-700">{titleError}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="studio-format">Format</Label>
            <Select value={aspectRatio} onValueChange={(v) => setAspectRatio(v as StudioAspectRatio)}>
              <SelectTrigger id="studio-format">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {(Object.keys(ASPECT_LABELS) as StudioAspectRatio[]).map((r) => (
                  <SelectItem key={r} value={r}>{ASPECT_LABELS[r]}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="studio-description">Description (facultatif)</Label>
            <Textarea id="studio-description" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={2000} rows={3} />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Annuler</Button>
            <Button type="submit" disabled={!title.trim() || create.isPending}>
              {create.isPending && <LoaderCircle className="animate-spin" />} Créer le projet
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
