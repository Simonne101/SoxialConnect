import { Check, Folder, FolderInput, FolderPlus, ImagePlus, LayoutGrid, List, MoreHorizontal, Pencil, Plus, Search, SearchX, Send, Tag, Trash2, Upload, X } from "lucide-react"
import { useDeferredValue, useState, type DragEvent } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { MediaPreviewDialog } from "@/components/media/MediaPreviewDialog"
import { MediaThumb } from "@/components/media/MediaThumb"
import { UploadDialog } from "@/components/media/UploadDialog"
import { UploadQueuePanel } from "@/components/media/UploadQueuePanel"
import { composerStateFor, publishableSelection } from "@/lib/mediaPublishing"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { PageHeader } from "@/components/shared/PageHeader"
import { Pagination } from "@/components/shared/Pagination"
import { EmptyState, ErrorState } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import {
  useCreateFolder,
  useDeleteFolder,
  useDeleteMedia,
  useMedia,
  useMediaFolders,
  useMediaTags,
  useRenameFolder,
  useUpdateMedia,
} from "@/hooks/queries/media"
import { useUploadFiles } from "@/hooks/useUploadFiles"
import { formatBytes, formatDate, formatDuration } from "@/lib/format"
import { apiErrorMessage } from "@/lib/http"
import { MEDIA_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { MediaAsset, MediaFilters, MediaFolder, MediaSort } from "@/types"

// Maquette : Tous / Images / Vidéos / GIF — complété par Audio et Documents (cahier des charges).
const TABS: { value: NonNullable<MediaFilters["type"]>; label: string }[] = [
  { value: "all", label: "Tous" },
  { value: "image", label: "Images" },
  { value: "video", label: "Vidéos" },
  { value: "gif", label: "GIF" },
  { value: "audio", label: "Audio" },
  { value: "document", label: "Documents" },
]

const SORTS: { value: string; label: string; sort: MediaSort; direction: "asc" | "desc" }[] = [
  { value: "recent", label: "Plus récents", sort: "created_at", direction: "desc" },
  { value: "oldest", label: "Plus anciens", sort: "created_at", direction: "asc" },
  { value: "name", label: "Nom (A → Z)", sort: "name", direction: "asc" },
  { value: "size", label: "Plus volumineux", sort: "size", direction: "desc" },
]

/** Dossiers dans l'ordre de l'arborescence, avec leur profondeur (indentation). */
function folderTree(folders: MediaFolder[]): { folder: MediaFolder; depth: number }[] {
  const byParent = new Map<string | null, MediaFolder[]>()
  for (const f of folders) byParent.set(f.parentId, [...(byParent.get(f.parentId) ?? []), f])
  const out: { folder: MediaFolder; depth: number }[] = []
  const walk = (parent: string | null, depth: number) => {
    for (const f of (byParent.get(parent) ?? []).sort((a, b) => a.name.localeCompare(b.name, "fr"))) {
      out.push({ folder: f, depth })
      walk(f.id, depth + 1)
    }
  }
  walk(null, 0)
  return out
}

/** Libellé d'un média pas encore utilisable (grille). */
function unavailableLabel(m: MediaAsset): string | null {
  if (m.usable) return null
  if (m.status === "uploading" || m.status === "processing") return MEDIA_STATUS[m.status].label
  if (m.status === "failed") return "Échec"
  return "En quarantaine"
}

export default function MediaPage() {
  const navigate = useNavigate()
  const [tab, setTab] = useState<NonNullable<MediaFilters["type"]>>("all")
  const [folderId, setFolderId] = useState<string>("all")
  const [tag, setTag] = useState<string | null>(null)
  const [search, setSearch] = useState("")
  const deferred = useDeferredValue(search)
  const [sortKey, setSortKey] = useState("recent")
  const [perPage, setPerPage] = useState(20)
  const [page, setPage] = useState(1)
  const [layout, setLayout] = useState<"grid" | "list">("grid")
  const [selected, setSelected] = useState<string[]>([])
  const [preview, setPreview] = useState<MediaAsset | null>(null)
  const [toDelete, setToDelete] = useState<string[] | null>(null)
  const [uploadOpen, setUploadOpen] = useState(false)
  const [folderDialog, setFolderDialog] = useState<{ mode: "create"; parentId: string | null } | { mode: "rename"; folder: MediaFolder } | null>(null)
  const [folderName, setFolderName] = useState("")
  const [folderToDelete, setFolderToDelete] = useState<MediaFolder | null>(null)
  const [dragging, setDragging] = useState(false)

  const sort = SORTS.find((s) => s.value === sortKey) ?? SORTS[0]
  const { data, isLoading, isFetching, isError, refetch } = useMedia({
    type: tab,
    folderId,
    tag,
    search: deferred,
    sort: sort.sort,
    direction: sort.direction,
    page,
    perPage,
  })
  const folders = useMediaFolders()
  const tags = useMediaTags()
  const createFolder = useCreateFolder()
  const renameFolder = useRenameFolder()
  const deleteFolder = useDeleteFolder()
  const updateMedia = useUpdateMedia()
  const del = useDeleteMedia()
  const uploadFiles = useUploadFiles()

  // Page vidée (suppression, déplacement) → dernière page existante (ajustement pendant le rendu).
  if (data && page > data.lastPage) setPage(data.lastPage)

  const items = data?.items ?? []
  // Publication directe de la sélection : même parcours que depuis le Clipper (Composer, médias référencés).
  const publishable = publishableSelection(selected, items)
  const folderList = folders.data ?? []
  const tree = folderTree(folderList)
  const filtering = Boolean(deferred) || tag !== null || folderId !== "all" || tab !== "all"
  const antivirus = data?.antivirus.available ?? false
  const names = Object.fromEntries(items.map((m) => [m.id, m.name]))
  const onError = (fallback: string) => (error: unknown) => toast.error(apiErrorMessage(error, fallback))
  const resetPage = <T,>(set: (v: T) => void) => (v: T) => {
    set(v)
    setPage(1)
    setSelected([])
  }
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  const currentFolder = folderId !== "all" && folderId !== "root" ? folderId : null

  const confirmDelete = () => {
    if (!toDelete) return
    del.mutate(
      { ids: toDelete, names },
      {
        onSuccess: ({ deleted, blocked, failed }) => {
          if (deleted.length) toast.success(deleted.length === 1 ? "Média supprimé." : `${deleted.length} médias supprimés.`)
          if (blocked.length) toast.error(`Impossible de supprimer ${blocked.join(", ")} : utilisé par une publication programmée.`)
          if (failed.length) toast.error(`La suppression a échoué pour ${failed.join(", ")}. Réessayez.`)
          setSelected((s) => s.filter((id) => !deleted.includes(id)))
          setPreview(null)
        },
        onError: onError("La suppression a échoué. Réessayez."),
      },
    )
    setToDelete(null)
  }

  const moveSelection = async (target: string | null) => {
    const ids = [...selected]
    const results = await Promise.allSettled(ids.map((id) => updateMedia.mutateAsync({ id, patch: { folderId: target } })))
    const moved = results.filter((r) => r.status === "fulfilled").length
    if (moved) toast.success(moved === 1 ? "Média déplacé." : `${moved} médias déplacés.`)
    if (moved < ids.length) toast.error(`${ids.length - moved} média(s) n'ont pas pu être déplacés.`)
    setSelected([])
  }

  const submitFolder = () => {
    const name = folderName.trim()
    if (!name || !folderDialog) return
    if (folderDialog.mode === "create") {
      createFolder.mutate(
        { name, parentId: folderDialog.parentId },
        {
          onSuccess: (f) => {
            toast.success(`Dossier « ${f.name} » créé.`)
            resetPage(setFolderId)(f.id)
            setFolderDialog(null)
          },
          onError: onError("Le dossier n'a pas pu être créé."),
        },
      )
    } else {
      renameFolder.mutate(
        { id: folderDialog.folder.id, name },
        {
          onSuccess: () => {
            toast.success("Dossier renommé.")
            setFolderDialog(null)
          },
          onError: onError("Le dossier n'a pas pu être renommé."),
        },
      )
    }
  }

  const openFolderDialog = (value: NonNullable<typeof folderDialog>) => {
    setFolderName(value.mode === "rename" ? value.folder.name : "")
    setFolderDialog(value)
  }

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    if (e.dataTransfer.files.length) void uploadFiles(Array.from(e.dataTransfer.files), currentFolder)
  }

  const folderButton = (id: string, label: string, count: number | null, depth = 0) => (
    <button
      type="button"
      onClick={() => resetPage(setFolderId)(id)}
      aria-current={folderId === id ? "true" : undefined}
      style={{ paddingLeft: `${0.625 + depth * 0.875}rem` }}
      className={cn("flex min-w-0 flex-1 items-center gap-2.5 rounded-lg py-2 pr-2 text-left text-sm whitespace-nowrap", folderId === id ? "bg-brand-50 font-semibold text-brand-700" : "text-ink-soft hover:bg-muted")}
    >
      <Folder className="size-4 shrink-0" aria-hidden="true" />
      <span className="flex-1 truncate">{label}</span>
      {count !== null && <span className="text-xs text-ink-muted">{count}</span>}
    </button>
  )

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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
        {/* Dossiers et tags */}
        <aside className="space-y-5">
          <nav aria-label="Dossiers" className="surface-card p-3">
            <div className="flex items-center justify-between px-2 pb-2">
              <h2 className="text-sm font-semibold">Dossiers</h2>
              <Button variant="ghost" size="icon-xs" onClick={() => openFolderDialog({ mode: "create", parentId: currentFolder })} aria-label={currentFolder ? "Nouveau sous-dossier" : "Nouveau dossier"}>
                <FolderPlus />
              </Button>
            </div>
            <ul className="flex gap-1 overflow-x-auto lg:flex-col">
              <li className="flex shrink-0">{folderButton("all", "Tous les médias", null)}</li>
              <li className="flex shrink-0">{folderButton("root", "Sans dossier", null)}</li>
              {tree.map(({ folder, depth }) => (
                <li key={folder.id} className="group flex shrink-0 items-center">
                  {folderButton(folder.id, folder.name, folder.count, depth)}
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon-xs" className="shrink-0 opacity-60 group-hover:opacity-100 focus-visible:opacity-100" aria-label={`Actions du dossier ${folder.name}`}>
                        <MoreHorizontal />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onSelect={() => openFolderDialog({ mode: "rename", folder })}>
                        <Pencil /> Renommer
                      </DropdownMenuItem>
                      <DropdownMenuItem disabled={depth >= 4} onSelect={() => openFolderDialog({ mode: "create", parentId: folder.id })}>
                        <FolderPlus /> Nouveau sous-dossier
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" onSelect={() => setFolderToDelete(folder)}>
                        <Trash2 /> Supprimer
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </li>
              ))}
            </ul>
          </nav>
          <section aria-labelledby="tags-title" className="surface-card hidden p-4 lg:block">
            <h2 id="tags-title" className="flex items-center gap-2 text-sm font-semibold"><Tag className="size-4" aria-hidden="true" /> Tags</h2>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {(tags.data ?? []).map((t) => (
                <button key={t} type="button" aria-pressed={tag === t} onClick={() => resetPage(setTag)(tag === t ? null : t)} className={cn("rounded-full px-2.5 py-1 text-xs font-medium", tag === t ? "bg-brand-500 text-white" : "bg-muted text-ink-soft hover:bg-brand-50")}>
                  {t}
                </button>
              ))}
              {tags.data?.length === 0 && <p className="text-xs text-ink-muted">Ajoutez des tags depuis le détail d'un média pour les retrouver ici.</p>}
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
            <FilterTabs label="Type de média" value={tab} onChange={resetPage(setTab)} items={TABS} />
          </div>

          <div className="flex flex-col gap-3 p-5 xl:flex-row xl:items-center">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
              <Input value={search} onChange={(e) => resetPage(setSearch)(e.target.value)} placeholder="Rechercher un média…" className="pl-10" aria-label="Rechercher un média" />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {tag && (
                <Button variant="secondary" size="sm" onClick={() => resetPage(setTag)(null)} aria-label={`Retirer le filtre ${tag}`}>
                  #{tag} <X />
                </Button>
              )}
              <Select value={sortKey} onValueChange={resetPage(setSortKey)}>
                <SelectTrigger size="sm" className="w-44" aria-label="Trier les médias"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SORTS.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}
                </SelectContent>
              </Select>
              <Select value={String(perPage)} onValueChange={(v) => resetPage(setPerPage)(Number(v))}>
                <SelectTrigger size="sm" className="w-32" aria-label="Médias par page"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="20">20 par page</SelectItem>
                  <SelectItem value="50">50 par page</SelectItem>
                </SelectContent>
              </Select>
              <div className="flex rounded-lg border border-border p-0.5" role="group" aria-label="Affichage">
                <Button variant={layout === "grid" ? "secondary" : "ghost"} size="icon-sm" aria-pressed={layout === "grid"} aria-label="Grille" onClick={() => setLayout("grid")}><LayoutGrid /></Button>
                <Button variant={layout === "list" ? "secondary" : "ghost"} size="icon-sm" aria-pressed={layout === "list"} aria-label="Liste" onClick={() => setLayout("list")}><List /></Button>
              </div>
            </div>
          </div>

          <UploadQueuePanel className="mx-5 mb-4" />

          {selected.length > 0 && (
            <div className="mx-5 mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-brand-50 px-4 py-2.5" role="status">
              <span className="text-sm font-semibold text-brand-700">{selected.length} sélectionné(s)</span>
              <Button size="sm" variant="ghost" onClick={() => setSelected(items.map((m) => m.id))}>Tout sélectionner</Button>
              {!publishable.ok && <span id="media-publish-reason" className="text-xs text-ink-muted">{publishable.reason}</span>}
              <div className="ml-auto flex flex-wrap gap-2">
                <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Annuler</Button>
                <Button
                  size="sm"
                  disabled={!publishable.ok}
                  aria-describedby={publishable.ok ? undefined : "media-publish-reason"}
                  onClick={() => publishable.ok && navigate("/content/create", { state: composerStateFor(publishable.mediaIds) })}
                >
                  <Send /> Publier sur les réseaux
                </Button>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button size="sm" variant="outline"><FolderInput /> Déplacer vers</Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="max-h-72 w-56 overflow-y-auto">
                    <DropdownMenuLabel>Dossier de destination</DropdownMenuLabel>
                    <DropdownMenuItem onSelect={() => void moveSelection(null)}>Aucun dossier</DropdownMenuItem>
                    {tree.map(({ folder, depth }) => (
                      <DropdownMenuItem key={folder.id} onSelect={() => void moveSelection(folder.id)} style={{ paddingLeft: `${0.5 + depth * 0.75}rem` }}>
                        <Folder /> {folder.name}
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuContent>
                </DropdownMenu>
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
                <EmptyState icon={SearchX} title="Aucun média trouvé" description="Modifiez votre recherche ou vos filtres pour afficher d'autres médias." action={<Button variant="outline" onClick={() => { setSearch(""); setTag(null); setFolderId("all"); setTab("all"); setPage(1) }}>Effacer les filtres</Button>} />
              ) : (
                <EmptyState icon={ImagePlus} title="Aucun média pour le moment" description="Importez vos photos et vidéos pour les réutiliser dans vos publications. Vous pouvez aussi les glisser ici." action={<Button onClick={() => setUploadOpen(true)}><Upload /> Importer des médias</Button>} />
              )
            ) : layout === "grid" ? (
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                {items.map((m) => {
                  const isSel = selected.includes(m.id)
                  const unavailable = unavailableLabel(m)
                  return (
                    <li key={m.id} className="group relative">
                      <button type="button" onClick={() => (selected.length ? toggle(m.id) : setPreview(m))} className={cn("block w-full overflow-hidden rounded-xl text-left ring-offset-2 transition-shadow", isSel ? "ring-2 ring-brand-500" : "hover:shadow-float")} aria-label={`${m.name}${unavailable ? ` — ${unavailable}` : ""}`}>
                        <MediaThumb media={m} className="aspect-square w-full rounded-xl" />
                        {unavailable && (
                          <span className={cn("absolute inset-x-0 bottom-0 m-1.5 rounded-lg px-2 py-1 text-center text-[11px] font-medium text-white", m.status === "failed" ? "bg-danger-600/90" : "bg-navy-950/70")}>
                            {unavailable}
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
                      <p className="mt-1.5 truncate text-xs font-medium text-ink-soft" title={m.name}>{m.name}</p>
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
                            <span className="block text-xs text-ink-muted uppercase">{m.durationMs ? formatDuration(m.durationMs) : (m.extension ?? m.mimeType)}</span>
                          </span>
                        </button>
                      </td>
                      <td className="hidden py-2 text-ink-soft sm:table-cell">{formatBytes(m.sizeBytes)}</td>
                      <td className="hidden py-2 text-ink-soft md:table-cell">{formatDate(m.createdAt)}</td>
                      <td className="py-2">{m.usable || m.status !== "ready" ? <StatusBadge meta={MEDIA_STATUS[m.status]} /> : <span className="text-xs font-medium text-warning-700">En quarantaine</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            {data && <Pagination page={data.page} lastPage={data.lastPage} total={data.total} onChange={setPage} busy={isFetching} label="Pages de la médiathèque" unit={["média", "médias"]} />}
          </div>

          {dragging && (
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-brand-50/90">
              <p className="text-base font-semibold text-brand-700">Déposez vos fichiers pour les importer</p>
            </div>
          )}
        </section>
      </div>

      <UploadDialog key={String(uploadOpen)} open={uploadOpen} onOpenChange={setUploadOpen} folders={folderList} defaultFolder={currentFolder} antivirus={antivirus} />
      <MediaPreviewDialog media={preview ? items.find((m) => m.id === preview.id) ?? preview : null} folders={folderList} onOpenChange={(o) => !o && setPreview(null)} onDelete={(m) => setToDelete([m.id])} />
      <ConfirmDialog
        open={Boolean(toDelete)}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={toDelete && toDelete.length > 1 ? `Supprimer ${toDelete.length} médias ?` : "Supprimer ce média ?"}
        description="Les médias supprimés ne pourront plus être utilisés dans vos publications. Un média utilisé par une publication programmée ne peut pas être supprimé."
        confirmLabel="Supprimer"
        onConfirm={confirmDelete}
      />
      <ConfirmDialog
        open={Boolean(folderToDelete)}
        onOpenChange={(o) => !o && setFolderToDelete(null)}
        title={`Supprimer le dossier « ${folderToDelete?.name ?? ""} » ?`}
        description="Ses sous-dossiers seront aussi supprimés. Les médias qu'ils contiennent ne sont pas supprimés : ils reviennent dans « Sans dossier »."
        confirmLabel="Supprimer le dossier"
        onConfirm={() => {
          const target = folderToDelete
          if (!target) return
          deleteFolder.mutate(target.id, {
            onSuccess: () => {
              toast.success("Dossier supprimé.")
              if (folderId === target.id) resetPage(setFolderId)("all")
            },
            onError: onError("Le dossier n'a pas pu être supprimé."),
          })
          setFolderToDelete(null)
        }}
      />
      <Dialog open={Boolean(folderDialog)} onOpenChange={(o) => !o && setFolderDialog(null)}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>{folderDialog?.mode === "rename" ? "Renommer le dossier" : folderDialog?.parentId ? "Nouveau sous-dossier" : "Nouveau dossier"}</DialogTitle>
            {folderDialog?.mode === "create" && folderDialog.parentId && (
              <DialogDescription>Dans « {folderList.find((f) => f.id === folderDialog.parentId)?.name} ».</DialogDescription>
            )}
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              submitFolder()
            }}
          >
            <Input autoFocus value={folderName} maxLength={120} onChange={(e) => setFolderName(e.target.value)} placeholder="Nom du dossier" aria-label="Nom du dossier" />
            <DialogFooter className="mt-4">
              <Button type="button" variant="outline" onClick={() => setFolderDialog(null)}>Annuler</Button>
              <Button type="submit" disabled={!folderName.trim() || createFolder.isPending || renameFolder.isPending}>{folderDialog?.mode === "rename" ? "Renommer" : "Créer"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}
