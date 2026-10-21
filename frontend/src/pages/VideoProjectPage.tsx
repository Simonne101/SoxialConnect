import { ArrowLeft, Clapperboard } from "lucide-react"
import { useState } from "react"
import { Link, useParams } from "react-router"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { formatSeconds } from "@/components/video-studio/labels"
import { StudioAssetsPanel } from "@/components/video-studio/StudioAssetsPanel"
import { StudioItemInspector } from "@/components/video-studio/StudioItemInspector"
import { StudioPreview } from "@/components/video-studio/StudioPreview"
import { StudioRenderPanel } from "@/components/video-studio/StudioRenderPanel"
import { StudioScenesPanel } from "@/components/video-studio/StudioScenesPanel"
import { StudioTimeline } from "@/components/video-studio/StudioTimeline"
import { useStudioProject } from "@/hooks/queries/videoStudio"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { ApiError, isApiEnabled } from "@/lib/http"
import { STUDIO_UNAVAILABLE } from "@/services/videoStudio"

/**
 * Éditeur structurel d'un projet vidéo : scènes et médias (gauche), aperçu indicatif (centre),
 * inspecteur et rendus (droite), timeline (bas). Chaque action est enregistrée par le serveur.
 */
export default function VideoProjectPage() {
  const { projectId } = useParams()
  const { data, isLoading, isError, error, refetch } = useStudioProject(isApiEnabled ? projectId : undefined)
  const [timeMs, setTimeMs] = useState(0)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  useDocumentTitle(data?.project.title ?? "Video Studio")

  if (!isApiEnabled) return <EmptyState icon={Clapperboard} title="Video Studio indisponible" description={STUDIO_UNAVAILABLE} />
  if (isLoading) return <ListSkeleton rows={6} />
  if (isError || !data) {
    return error instanceof ApiError && error.status === 404
      ? <EmptyState icon={Clapperboard} title="Projet introuvable" description="Ce projet n'existe pas ou n'est pas accessible." action={<Link to="/video-studio" className="text-sm font-medium text-brand-600">Retour au Video Studio</Link>} />
      : <ErrorState onRetry={() => refetch()} />
  }

  const { project, rendererAvailable } = data
  const selected = project.tracks.flatMap((t) => t.items).find((i) => i.id === selectedId) ?? null

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <Link to="/video-studio" className="inline-flex items-center gap-1 text-sm text-ink-muted hover:text-brand-600"><ArrowLeft className="size-4" aria-hidden="true" /> Video Studio</Link>
          <h1 className="truncate text-2xl font-bold text-ink">{project.title}</h1>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
          <Badge variant="neutral">{project.aspectRatio}</Badge>
          <span>{project.width} × {project.height} · {project.fps} i/s · {formatSeconds(project.durationMs)}</span>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)_18rem]">
        <section aria-label="Scènes et médias" className="rounded-xl border border-border bg-card p-3">
          <Tabs defaultValue="scenes">
            <TabsList className="mb-3 w-full">
              <TabsTrigger value="scenes" className="flex-1">Scènes ({project.scenes.length})</TabsTrigger>
              <TabsTrigger value="assets" className="flex-1">Médias ({project.assets.length})</TabsTrigger>
            </TabsList>
            <TabsContent value="scenes"><StudioScenesPanel project={project} /></TabsContent>
            <TabsContent value="assets"><StudioAssetsPanel project={project} /></TabsContent>
          </Tabs>
        </section>

        <section aria-label="Aperçu" className="rounded-xl border border-border bg-card p-3">
          <StudioPreview project={project} timeMs={timeMs} onTimeChange={setTimeMs} />
        </section>

        <section aria-label="Élément et rendu" className="rounded-xl border border-border bg-card p-3">
          <Tabs value={selected ? "item" : "render"} onValueChange={(v) => v === "render" && setSelectedId(null)}>
            <TabsList className="mb-3 w-full">
              <TabsTrigger value="item" className="flex-1" disabled={!selected}>Élément</TabsTrigger>
              <TabsTrigger value="render" className="flex-1">Rendu</TabsTrigger>
            </TabsList>
            <TabsContent value="item">
              {selected && <StudioItemInspector key={`${selected.id}-${project.lockVersion}`} project={project} item={selected} onDeleted={() => setSelectedId(null)} />}
            </TabsContent>
            <TabsContent value="render"><StudioRenderPanel project={project} rendererAvailable={rendererAvailable} /></TabsContent>
          </Tabs>
        </section>
      </div>

      <StudioTimeline project={project} selectedId={selectedId} onSelect={setSelectedId} timeMs={timeMs} />
    </div>
  )
}
