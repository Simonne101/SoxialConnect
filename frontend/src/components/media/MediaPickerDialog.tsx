import { Check, ImagePlus, Upload } from "lucide-react"
import { useRef, useState } from "react"
import { toast } from "sonner"
import { EmptyState, ListSkeleton } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { useMedia, useUploadMedia } from "@/hooks/queries/media"
import { cn } from "@/lib/utils"
import type { MediaType } from "@/types"
import { MediaThumb } from "./MediaThumb"

/** Sélection de médias depuis la médiathèque (ou import) pour le Composer. */
export function MediaPickerDialog({
  open,
  onOpenChange,
  initial,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  initial: string[]
  onConfirm: (ids: string[]) => void
}) {
  const [type, setType] = useState<MediaType | "all">("all")
  const [selected, setSelected] = useState<string[]>(initial)
  const fileRef = useRef<HTMLInputElement>(null)
  const { data, isLoading } = useMedia({ type })
  const upload = useUploadMedia()
  const items = (data ?? []).filter((m) => m.type === "image" || m.type === "video")

  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  const onFiles = (files: FileList | null) => {
    if (!files?.length) return
    upload.mutate(
      { files: Array.from(files), folderId: null },
      {
        onSuccess: ({ accepted, rejected }) => {
          setSelected((s) => [...s, ...accepted.map((a) => a.id)])
          if (accepted.length) toast.success(`${accepted.length} fichier(s) en cours d'import.`)
          rejected.forEach((r) => toast.error(`${r.name} : ${r.reason}`))
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-hidden sm:max-w-[760px]">
        <DialogHeader>
          <DialogTitle>Ajouter des médias</DialogTitle>
          <DialogDescription>Choisissez dans votre médiathèque ou importez depuis votre ordinateur.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <FilterTabs
            variant="pill"
            label="Type de média"
            value={type}
            onChange={setType}
            items={[
              { value: "all", label: "Tous" },
              { value: "image", label: "Images" },
              { value: "video", label: "Vidéos" },
            ]}
          />
          <input ref={fileRef} type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" className="sr-only" onChange={(e) => onFiles(e.target.files)} tabIndex={-1} aria-hidden="true" />
          <Button variant="outline" onClick={() => fileRef.current?.click()}>
            <Upload /> Importer depuis l'ordinateur
          </Button>
        </div>
        <div className="-mx-1 max-h-[46dvh] overflow-y-auto px-1 py-1">
          {isLoading ? (
            <ListSkeleton rows={3} />
          ) : items.length === 0 ? (
            <EmptyState icon={ImagePlus} title="Aucun média" description="Importez une image ou une vidéo pour l'ajouter à votre publication." />
          ) : (
            <ul className="grid grid-cols-3 gap-2.5 sm:grid-cols-5">
              {items.map((m) => {
                const index = selected.indexOf(m.id)
                const isSel = index >= 0
                const busy = m.status !== "ready"
                return (
                  <li key={m.id}>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => toggle(m.id)}
                      aria-pressed={isSel}
                      aria-label={`${m.name}${busy ? " (en cours de traitement)" : ""}`}
                      className={cn("relative block w-full rounded-xl p-0.5 transition-shadow", isSel ? "ring-2 ring-brand-500" : "ring-1 ring-transparent hover:ring-border", busy && "opacity-60")}
                    >
                      <MediaThumb media={m} className="aspect-square w-full" />
                      {isSel && (
                        <span className="absolute top-1.5 left-1.5 inline-flex size-6 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white ring-2 ring-white">
                          {selected.length > 1 ? index + 1 : <Check className="size-3.5" />}
                        </span>
                      )}
                      {busy && <span className="absolute inset-x-1 bottom-1 rounded bg-navy-950/70 py-0.5 text-center text-[10px] text-white">Traitement…</span>}
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
        <DialogFooter className="items-center sm:justify-between">
          <p className="text-sm text-ink-muted">{selected.length} sélectionné{selected.length > 1 ? "s" : ""}</p>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => onOpenChange(false)}>Annuler</Button>
            <Button onClick={() => { onConfirm(selected); onOpenChange(false) }}>Ajouter{selected.length ? ` (${selected.length})` : ""}</Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
