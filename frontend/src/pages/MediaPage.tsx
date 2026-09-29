import { Check, Folder, FolderPlus, ImagePlus, LayoutGrid, List, Plus, Search, SearchX, Tag, Trash2, Upload, X } from "lucide-react"
import { useDeferredValue, useState, type DragEvent } from "react"
import { toast } from "sonner"
import { MediaPreviewDialog } from "@/components/media/MediaPreviewDialog"
import { MediaThumb } from "@/components/media/MediaThumb"
import { UploadDialog } from "@/components/media/UploadDialog"
import { useUploadFiles } from "@/hooks/useUploadFiles"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { PageHeader } from "@/components/shared/PageHeader"
import { EmptyState, ErrorState } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Progress } from "@/components/ui/progress"
import { Skeleton } from "@/components/ui/skeleton"
import { useCreateFolder, useDeleteMedia, useMedia, useMediaFolders, useMediaTags } from "@/hooks/queries/media"
import { formatBytes, formatDate, formatDuration } from "@/lib/format"
import { MEDIA_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { MediaAsset, MediaType } from "@/types"

// Maquette : Tous / Images / Vidéos / GIF — complété par Audio et Documents (cahier des charges).
const TABS: { value: string; label: string }[] = [
  { value: "all", label: "Tous" },
  { value: "image", label: "Images" },
  { value: "video", label: "Vidéos" },
  { value: "gif", label: "GIF" },
  { value: "audio", label: "Audio" },
  { value: "document", label: "Documents" },
]

export default function MediaPage() {
  const [tab, setTab] = useState("all")
  const [folderId, setFolderId] = useState<string>("all")
  const [tag, setTag] = useState<string | null>(null)
  const [search, setSearch] = useState("")
  const deferred = useDeferredValue(search)
  const [layout, setLayout] = useState<"grid" | "list">("grid")
  const [selected, setSelected] = useState<string[]>([])
  const [preview, setPreview] = useState<MediaAsset | null>(null)
  const [toDelete, setToDelete] = useState<string[] | null>(null)
  const [uploadOpen, setUploadOpen] = useState(false)
  const [folderDialog, setFolderDialog] = useState(false)
  const [folderName, setFolderName] = useState("")
  const [dragging, setDragging] = useState(false)

  const type: MediaType | "all" = tab === "gif" ? "image" : (tab as MediaType | "all")
  const { data, isLoading, isError, refetch } = useMedia({ type, folderId, tag, search: deferred })
  const folders = useMediaFolders()
  const tags = useMediaTags()
  const createFolder = useCreateFolder()
  const del = useDeleteMedia()
  const uploadFiles = useUploadFiles()

  const items = (data ?? []).filter((m) => (tab === "gif" ? m.isGif : true))
  const filtering = Boolean(deferred) || tag !== null || folderId !== "all" || tab !== "all"
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  const confirmDelete = () => {
    if (!toDelete) return
    del.mutate(toDelete, {
      onSuccess: ({ deleted, blocked }) => {
        if (deleted.length) toast.success(`${deleted.length} média(s) supprimé(s).`)
        if (blocked.length) toast.error(`Impossible de supprimer ${blocked.join(", ")} : utilisé par une publication programmée.`)
        setSelected((s) => s.filter((id) => !deleted.includes(id)))
        setPreview(null)
      },
    })
    setToDelete(null)
  }

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    if (e.dataTransfer.files.length) uploadFiles(Array.from(e.dataTransfer.files), folderId === "all" ? null : folderId)
  }

  const folderList = folders.data ?? []

  return (
    <>
      <PageHeader
        title="Bibliothèque média"
        description="Toutes vos photos, vidéos et fichiers, rangés au même endroit."
        actions={
          <Button onClick={() => setUploadOpen(true)}>
            <Upload /> Importer
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        {/* Dossiers et tags */}
        <aside className="space-y-5">
          <nav aria-label="Dossiers" className="surface-card p-3">
            <div className="flex items-center justify-between px-2 pb-2">
              <h2 className="text-sm font-semibold">Dossiers</h2>
              <Button variant="ghost" size="icon-xs" onClick={() => setFolderDialog(true)} aria-label="Nouveau dossier">
                <FolderPlus />
              </Button>
            </div>
            <ul className="flex gap-1 overflow-x-auto lg:flex-col">
              {[{ id: "all", name: "Tous les médias", count: null as number | null }, ...folderList].map((f) => (
                <li key={f.id} className="shrink-0">
                  <button
                    type="button"
                    onClick={() => setFolderId(f.id)}
                    aria-current={folderId === f.id ? "true" : undefined}
                    className={cn("flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm whitespace-nowrap", folderId === f.id ? "bg-brand-50 font-semibold text-brand-700" : "text-ink-soft hover:bg-muted")}
                  >
                    <Folder className="size-4 shrink-0" aria-hidden="true" />
                    <span className="flex-1 truncate">{f.name}</span>
                    {f.count !== null && <span className="text-xs text-ink-muted">{f.count}</span>}
                  </button>
                </li>
              ))}
            </ul>
          </nav>
          <section aria-labelledby="tags-title" className="surface-card hidden p-4 lg:block">
            <h2 id="tags-title" className="flex items-center gap-2 text-sm font-semibold"><Tag className="size-4" aria-hidden="true" /> Tags</h2>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {(tags.data ?? []).map((t) => (
                <button key={t} type="button" aria-pressed={tag === t} onClick={() => setTag(tag === t ? null : t)} className={cn("rounded-full px-2.5 py-1 text-xs font-medium", tag === t ? "bg-brand-500 text-white" : "bg-muted text-ink-soft hover:bg-brand-50")}>
                  {t}
                </button>
              ))}
            </div>
          </section>
        </aside>

        {/* Contenu */}
        <section
          aria-label="Médias"
          className={cn("surface-card relative min-h-[420px]", dragging && "ring-2 ring-brand-500")}
          onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
          onDragLeave={(e) => { if (e.currentTarget === e.target) setDragging(false) }}
          onDrop={onDrop}
        >
          <div className="border-b border-border px-5 pt-2">
            <FilterTabs label="Type de média" value={tab} onChange={setTab} items={TABS} />
          </div>

          <div className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un média…" className="pl-10" aria-label="Rechercher un média" />
            </div>
            {tag && (
              <Button variant="secondary" size="sm" onClick={() => setTag(null)}>
                #{tag} <X />
              </Button>
            )}
            <div className="flex rounded-lg border border-border p-0.5" role="group" aria-label="Affichage">
              <Button variant={layout === "grid" ? "secondary" : "ghost"} size="icon-sm" aria-pressed={layout === "grid"} aria-label="Grille" onClick={() => setLayout("grid")}><LayoutGrid /></Button>
              <Button variant={layout === "list" ? "secondary" : "ghost"} size="icon-sm" aria-pressed={layout === "list"} aria-label="Liste" onClick={() => setLayout("list")}><List /></Button>
            </div>
          </div>

          {selected.length > 0 && (
            <div className="mx-5 mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-brand-50 px-4 py-2.5" role="status">
              <span className="text-sm font-semibold text-brand-700">{selected.length} sélectionné(s)</span>
              <Button size="sm" variant="ghost" onClick={() => setSelected(items.map((m) => m.id))}>Tout sélectionner</Button>
              <div className="ml-auto flex gap-2">
                <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Annuler</Button>
                <Button size="sm" variant="destructive" onClick={() => setToDelete(selected)}><Trash2 /> Supprimer</Button>
              </div>
            </div>
          )}

          <div className="px-5 pb-5">
            {isLoading ? (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                {Array.from({ length: 10 }, (_, i) => <Skeleton key={i} className="aspect-square rounded-xl" />)}
              </div>
            ) : isError ? (
              <ErrorState onRetry={() => refetch()} />
            ) : items.length === 0 ? (
              filtering ? (
                <EmptyState icon={SearchX} title="Aucun média trouvé" description="Modifiez votre recherche ou vos filtres pour afficher d'autres médias." action={<Button variant="outline" onClick={() => { setSearch(""); setTag(null); setFolderId("all"); setTab("all") }}>Effacer les filtres</Button>} />
              ) : (
                <EmptyState icon={ImagePlus} title="Aucun média pour le moment" description="Importez vos photos et vidéos pour les réutiliser dans vos publications. Vous pouvez aussi les glisser ici." action={<Button onClick={() => setUploadOpen(true)}><Upload /> Importer des médias</Button>} />
              )
            ) : layout === "grid" ? (
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                {items.map((m) => {
                  const isSel = selected.includes(m.id)
                  const busy = m.status === "uploading" || m.status === "processing"
                  return (
                    <li key={m.id} className="group relative">
                      <button type="button" onClick={() => (selected.length ? toggle(m.id) : setPreview(m))} disabled={busy} className={cn("block w-full overflow-hidden rounded-xl text-left ring-offset-2 transition-shadow", isSel ? "ring-2 ring-brand-500" : "hover:shadow-float")} aria-label={`${m.name}${busy ? ` — ${MEDIA_STATUS[m.status].label}` : ""}`}>
                        <MediaThumb media={m} className="aspect-square w-full rounded-xl" />
                        {busy && (
                          <span className="absolute inset-0 flex flex-col items-center justify-center gap-2 rounded-xl bg-navy-950/55 p-3 text-xs font-medium text-white">
                            {MEDIA_STATUS[m.status].label}
                            {m.status === "uploading" && <Progress value={m.progress ?? 0} className="h-1.5 w-3/4 bg-white/30" aria-label={`Envoi de ${m.name}`} />}
                          </span>
                        )}
                      </button>
                      <button
                        type="button"
                        onClick={() => toggle(m.id)}
                        aria-pressed={isSel}
                        aria-label={`${isSel ? "Désélectionner" : "Sélectionner"} ${m.name}`}
                        className={cn("absolute top-2 left-2 inline-flex size-6 items-center justify-center rounded-md border-2 border-white shadow transition-opacity", isSel ? "bg-brand-500 opacity-100" : "bg-navy-950/30 opacity-0 group-hover:opacity-100 focus:opacity-100")}
                      >
                        {isSel && <Check className="size-3.5 text-white" strokeWidth={3} />}
                      </button>
                      <p className="mt-1.5 truncate text-xs font-medium text-ink-soft">{m.name}</p>
                    </li>
                  )
                })}
                <li>
                  <button type="button" onClick={() => setUploadOpen(true)} className="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand-200 text-brand-500 hover:bg-brand-50">
                    <Plus className="size-7" aria-hidden="true" />
                    <span className="text-xs font-semibold">Importer</span>
                  </button>
                </li>
              </ul>
            ) : (
              <table className="w-full text-left text-sm">
                <caption className="sr-only">Médias</caption>
                <thead>
                  <tr className="border-b border-border text-xs text-ink-muted">
                    <th scope="col" className="w-10 py-2"><span className="sr-only">Sélection</span></th>
                    <th scope="col" className="py-2 font-medium">Nom</th>
                    <th scope="col" className="hidden py-2 font-medium sm:table-cell">Taille</th>
                    <th scope="col" className="hidden py-2 font-medium md:table-cell">Ajouté</th>
                    <th scope="col" className="py-2 font-medium">Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((m) => (
                    <tr key={m.id} className="border-b border-border last:border-none hover:bg-muted/40">
                      <td className="py-2">
                        <input type="checkbox" checked={selected.includes(m.id)} onChange={() => toggle(m.id)} aria-label={`Sélectionner ${m.name}`} className="size-4 accent-brand-500" />
                      </td>
                      <td className="max-w-0 py-2">
                        <button type="button" onClick={() => setPreview(m)} className="flex w-full items-center gap-3 text-left">
                          <MediaThumb media={m} className="size-10 shrink-0" showMeta={false} />
                          <span className="min-w-0">
                            <span className="block truncate font-medium text-ink">{m.name}</span>
                            <span className="block text-xs text-ink-muted">{m.durationMs ? formatDuration(m.durationMs) : m.mimeType}</span>
                          </span>
                        </button>
                      </td>
                      <td className="hidden py-2 text-ink-soft sm:table-cell">{formatBytes(m.sizeBytes)}</td>
                      <td className="hidden py-2 text-ink-soft md:table-cell">{formatDate(m.createdAt)}</td>
                      <td className="py-2"><StatusBadge meta={MEDIA_STATUS[m.status]} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {dragging && (
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-brand-50/90">
              <p className="text-base font-semibold text-brand-700">Déposez vos fichiers pour les importer</p>
            </div>
          )}
        </section>
      </div>

      <UploadDialog open={uploadOpen} onOpenChange={setUploadOpen} folders={folderList} defaultFolder={folderId === "all" ? null : folderId} />
      <MediaPreviewDialog media={preview ? items.find((m) => m.id === preview.id) ?? preview : null} folders={folderList} onOpenChange={(o) => !o && setPreview(null)} onDelete={(m) => setToDelete([m.id])} />
      <ConfirmDialog
        open={Boolean(toDelete)}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={toDelete && toDelete.length > 1 ? `Supprimer ${toDelete.length} médias ?` : "Supprimer ce média ?"}
        description="Les médias supprimés ne pourront plus être utilisés dans vos publications. Un média utilisé par une publication programmée ne peut pas être supprimé."
        confirmLabel="Supprimer"
        onConfirm={confirmDelete}
      />
      <Dialog open={folderDialog} onOpenChange={setFolderDialog}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Nouveau dossier</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              const n = folderName.trim()
              if (!n) return
              createFolder.mutate(n, { onSuccess: (f) => { toast.success(`Dossier « ${f.name} » créé.`); setFolderId(f.id) } })
              setFolderName("")
              setFolderDialog(false)
            }}
          >
            <Input autoFocus value={folderName} maxLength={120} onChange={(e) => setFolderName(e.target.value)} placeholder="Nom du dossier" aria-label="Nom du dossier" />
            <DialogFooter className="mt-4">
              <Button type="button" variant="outline" onClick={() => setFolderDialog(false)}>Annuler</Button>
              <Button type="submit" disabled={!folderName.trim()}>Créer</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}
