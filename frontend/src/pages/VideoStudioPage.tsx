import { Clapperboard, Plus, Trash2 } from "lucide-react"
import { useState } from "react"
import { Link } from "react-router"
import { toast } from "sonner"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { PageHeader } from "@/components/shared/PageHeader"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { CreateStudioProjectDialog } from "@/components/video-studio/CreateStudioProjectDialog"
import { formatSeconds } from "@/components/video-studio/labels"
import { useDeleteStudioProject, useStudioProjects } from "@/hooks/queries/videoStudio"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { formatRelative } from "@/lib/format"
import { apiErrorMessage, isApiEnabled } from "@/lib/http"
import { STUDIO_UNAVAILABLE } from "@/services/videoStudio"
import type { StudioProject } from "@/types"

const STATUS_LABELS = { draft: "Brouillon", rendering: "Rendu en cours", ready: "Vidéo prête", failed: "Échec" } as const

/** Video Studio : projets vidéo de l'utilisateur (données réelles du serveur, aucune simulation). */
export default function VideoStudioPage() {
  useDocumentTitle("Video Studio")
  const [creating, setCreating] = useState(false)
  const [toDelete, setToDelete] = useState<StudioProject | null>(null)
  const projects = useStudioProjects()
  const remove = useDeleteStudioProject()

  return (
    <div>
      <PageHeader
        title="Video Studio"
        description="Composez des vidéos courtes : scènes, médias de votre médiathèque, textes et sons sur une timeline."
        actions={isApiEnabled ? <Button onClick={() => setCreating(true)}><Plus /> Nouveau projet</Button> : undefined}
      />
      {!isApiEnabled ? (
        <EmptyState icon={Clapperboard} title="Video Studio indisponible" description={STUDIO_UNAVAILABLE} />
      ) : projects.isLoading ? (
        <ListSkeleton rows={4} />
      ) : projects.isError ? (
        <ErrorState onRetry={() => projects.refetch()} />
      ) : !projects.data?.length ? (
        <EmptyState
          icon={Clapperboard}
          title="Aucun projet vidéo"
          description="Créez un projet, ajoutez des médias de votre médiathèque puis placez-les sur la timeline."
          action={<Button onClick={() => setCreating(true)}><Plus /> Créer mon premier projet</Button>}
        />
      ) : (
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {projects.data.map((p) => (
            <li key={p.id} className="flex flex-col gap-2 rounded-2xl border border-border bg-card p-4">
              <div className="flex items-start justify-between gap-2">
                <Link to={`/video-studio/${p.id}`} className="font-semibold text-ink hover:text-brand-600">{p.title}</Link>
                <Badge variant={p.status === "failed" ? "danger" : p.status === "rendering" ? "warning" : "neutral"}>{STATUS_LABELS[p.status]}</Badge>
              </div>
              {p.description && <p className="line-clamp-2 text-sm text-ink-muted">{p.description}</p>}
              <p className="text-xs text-ink-muted">{p.aspectRatio} · {p.width} × {p.height} · {formatSeconds(p.durationMs)} · modifié {formatRelative(p.updatedAt)}</p>
              <div className="mt-auto flex gap-2 pt-1">
                <Button asChild size="sm" className="flex-1"><Link to={`/video-studio/${p.id}`}>Ouvrir</Link></Button>
                <Button
                  variant="outline"
                  size="sm"
                  aria-label={`Supprimer « ${p.title} »`}
                  disabled={remove.isPending}
                  onClick={() => setToDelete(p)}
                >
                  <Trash2 />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      <CreateStudioProjectDialog open={creating} onOpenChange={setCreating} />
      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={`Supprimer « ${toDelete?.title ?? ""} » ?`}
        description="Le projet, ses scènes et sa timeline seront supprimés. Les médias restent dans votre médiathèque."
        confirmLabel="Supprimer le projet"
        onConfirm={() => {
          if (toDelete) remove.mutate(toDelete.id, { onSuccess: () => toast.success("Projet supprimé."), onError: (e) => toast.error(apiErrorMessage(e)) })
          setToDelete(null)
        }}
      />
    </div>
  )
}
