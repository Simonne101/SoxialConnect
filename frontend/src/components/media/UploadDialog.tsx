import { CloudUpload } from "lucide-react"
import { useRef, useState, type DragEvent } from "react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useUploadFiles } from "@/hooks/useUploadFiles"
import { ACCEPT_ATTRIBUTE } from "@/services/media"
import { cn } from "@/lib/utils"
import type { MediaFolder } from "@/types"

export const ACCEPT = ACCEPT_ATTRIBUTE

export function UploadDialog({
  open,
  onOpenChange,
  folders,
  defaultFolder,
  antivirus,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  folders: MediaFolder[]
  defaultFolder: string | null
  /** Moteur antivirus réellement disponible (meta de l'API) : le texte ne l'annonce que dans ce cas. */
  antivirus: boolean
}) {
  const [folder, setFolder] = useState<string>(defaultFolder ?? "none")
  const [dragging, setDragging] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const uploadFiles = useUploadFiles()

  const send = (list: FileList | null) => {
    if (!list?.length) return
    void uploadFiles(Array.from(list), folder === "none" ? null : folder)
    onOpenChange(false)
  }
  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    send(e.dataTransfer.files)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[520px]">
        <DialogHeader>
          <DialogTitle>Importer des médias</DialogTitle>
          <DialogDescription>Ajoutez des photos, vidéos, sons ou documents à votre médiathèque.</DialogDescription>
        </DialogHeader>
        <div className="space-y-1.5">
          <Label htmlFor="upload-folder" className="text-[13px] font-semibold">Dossier de destination</Label>
          <Select value={folder} onValueChange={setFolder}>
            <SelectTrigger id="upload-folder" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">Aucun dossier</SelectItem>
              {folders.map((f) => (
                <SelectItem key={f.id} value={f.id}>{f.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div
          onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={cn("flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors", dragging ? "border-brand-500 bg-brand-50" : "border-brand-200 bg-brand-50/40")}
        >
          <CloudUpload className="size-10 text-brand-500" aria-hidden="true" />
          <p className="text-sm font-semibold text-ink">Glissez vos fichiers ici</p>
          <p className="text-xs text-ink-muted">ou</p>
          <input ref={inputRef} type="file" multiple accept={ACCEPT} className="sr-only" tabIndex={-1} aria-hidden="true" onChange={(e) => send(e.target.files)} />
          <Button onClick={() => inputRef.current?.click()}>Choisir des fichiers</Button>
          <p className="text-xs text-ink-muted">JPG, PNG, WebP, GIF (20 Mo) · MP4, MOV, WebM · MP3, M4A, WAV · PDF (25 Mo)</p>
        </div>
        <p className="text-xs text-ink-muted">
          {antivirus
            ? "Chaque fichier est vérifié (format réel, taille, dimensions) puis analysé par l'antivirus avant d'être disponible."
            : "Chaque fichier est vérifié (format réel, taille, dimensions) avant d'être disponible."}
        </p>
      </DialogContent>
    </Dialog>
  )
}
