import { Check, ImagePlus, Search, Upload } from "lucide-react"
import { useDeferredValue, useRef, useState } from "react"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Pagination } from "@/components/shared/Pagination"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { useMedia } from "@/hooks/queries/media"
import { useUploadFiles } from "@/hooks/useUploadFiles"
import { cn } from "@/lib/utils"
import { UploadQueuePanel } from "./UploadQueuePanel"
import { MediaThumb } from "./MediaThumb"

const PICKER_ACCEPT = "image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
const AUDIO_ACCEPT = "audio/mpeg,audio/mp4,audio/x-m4a,audio/wav"

/**
 * Sélection de médias depuis la médiathèque (ou import) : Composer (images, vidéos) et Video Studio
 * (allowAudio : sons et musiques en plus). Par défaut, comportement inchangé pour le Composer.
 */
export function MediaPickerDialog({
  open,
  onOpenChange,
  initial,
  onConfirm,
  allowAudio = false,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  initial: string[]
  onConfirm: (ids: string[]) => void
  allowAudio?: boolean
}) {
  const [type, setType] = useState<"all" | "image" | "video" | "audio">("all")
  const [search, setSearch] = useState("")
  const deferred = useDeferredValue(search)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<string[]>(initial)
  const fileRef = useRef<HTMLInputElement>(null)
  const uploadFiles = useUploadFiles()
  // Images et vidéos seulement (publiables) ; « all » exclut audio et documents côté affichage.
  const { data, isLoading, isError, isFetching, refetch } = useMedia({ type, search: deferred, page, perPage: 20 })
  const items = (data?.items ?? []).filter((m) => m.type === "image" || m.type === "video" || (allowAudio && m.type === "audio"))

  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  const onFiles = async (files: FileList | null) => {
    if (!files?.length) return
    const uploaded = await uploadFiles(Array.from(files), null)
    setSelected((s) => [...s, ...uploaded.filter((m) => m.usable).map((m) => m.id)])
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-[760px]">
        <DialogHeader>
          <DialogTitle>Ajouter des médias</DialogTitle>
          <DialogDescription>Choisissez dans votre médiathèque ou importez depuis votre ordinateur.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <FilterTabs
            variant="pill"
            label="Type de média"
            value={type}
            onChange={(v) => { setType(v); setPage(1) }}
            items={[
              { value: "all", label: "Tous" },
              { value: "image", label: "Images" },
              { value: "video", label: "Vidéos" },
              ...(allowAudio ? [{ value: "audio" as const, label: "Sons" }] : []),
            ]}
          />
          <input ref={fileRef} type="file" multiple accept={allowAudio ? `${PICKER_ACCEPT},${AUDIO_ACCEPT}` : PICKER_ACCEPT} className="sr-only" onChange={(e) => void onFiles(e.target.files)} tabIndex={-1} aria-hidden="true" />
          <Button variant="outline" onClick={() => fileRef.current?.click()}>
            <Upload /> Importer depuis l'ordinateur
          </Button>
        </div>
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
          <Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} placeholder="Rechercher un média…" className="pl-10" aria-label="Rechercher un média" />
        </div>
        <UploadQueuePanel />
        <div className="-mx-1 px-1 py-1">
          {isLoading ? (
            <ListSkeleton rows={3} />
          ) : isError ? (
            <ErrorState onRetry={() => refetch()} />
          ) : items.length === 0 ? (
            <EmptyState icon={ImagePlus} title="Aucun média" description="Importez une image ou une vidéo pour l'ajouter à votre publication." />
          ) : (
            <ul className="grid grid-cols-3 gap-2.5 sm:grid-cols-5">
              {items.map((m) => {
                const index = selected.indexOf(m.id)
                const isSel = index >= 0
                const busy = !m.usable
                return (
                  <li key={m.id}>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => toggle(m.id)}
                      aria-pressed={isSel}
                      aria-label={`${m.name}${busy ? " (pas encore disponible)" : ""}`}
                      className={cn("relative block w-full rounded-xl p-0.5 transition-shadow", isSel ? "ring-2 ring-brand-500" : "ring-1 ring-transparent hover:ring-border", busy && "opacity-60")}
                    >
                      <MediaThumb media={m} className="aspect-square w-full" />
                      {isSel && (
                        <span className="absolute top-1.5 left-1.5 inline-flex size-6 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white ring-2 ring-white">
                          {selected.length > 1 ? index + 1 : <Check className="size-3.5" />}
                        </span>
                      )}
                      {busy && <span className="absolute inset-x-1 bottom-1 rounded bg-navy-950/70 py-0.5 text-center text-[10px] text-white">Indisponible</span>}
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
          {data && <Pagination page={data.page} lastPage={data.lastPage} total={data.total} onChange={setPage} busy={isFetching} label="Pages de médias" unit={["média", "médias"]} />}
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
