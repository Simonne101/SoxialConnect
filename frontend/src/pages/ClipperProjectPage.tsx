import { useQueryClient } from "@tanstack/react-query"
import { ArrowLeft, CircleStop, ExternalLink, LoaderCircle, RefreshCw, Scissors } from "lucide-react"
import { useEffect, useRef, useState } from "react"
import { Link, useParams, useSearchParams } from "react-router"
import { toast } from "sonner"
import { AnalysisSummary } from "@/components/creative/AnalysisSummary"
import { ClipCard } from "@/components/creative/ClipCard"
import { ClipperPipeline } from "@/components/creative/ClipperPipeline"
import { ManualClipForm } from "@/components/creative/ManualClipForm"
import { SourceCard } from "@/components/creative/SourceCard"
import { YouTubePreview } from "@/components/creative/YouTubePreview"
import { PROJECT_STATUS, defaultRenderOptions, formatTimecode } from "@/components/creative/labels"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { useCancelClipper, useClipperProject, useReanalyzeClipper, useRequestClip } from "@/hooks/queries/creative"
import { qk } from "@/hooks/queries/keys"
import { ApiError, apiErrorMessage } from "@/lib/http"

const ANALYZING = ["pending", "analyzing", "transcribing", "detecting"]

/** Retour du consentement Google Data Portability (codes renvoyés par le callback Laravel). */
const PORTABILITY_ERRORS: Record<string, string> = {
  ACCESS_DENIED: "Autorisation refusée sur l'écran Google : la vidéo ne peut pas être récupérée automatiquement.",
  SCOPE_DENIED: "Aucun groupe de vidéos n'a été autorisé sur l'écran Google. Réessayez en cochant vos vidéos.",
  INVALID_STATE: "L'autorisation a expiré ou a déjà été utilisée. Recommencez depuis ce projet.",
  ACCOUNT_DISCONNECTED: "La chaîne YouTube n'est plus connectée : reconnectez-la puis recommencez.",
  OAUTH_CLIENT_INVALID: "La configuration Google du serveur est invalide. Contactez l'administrateur.",
}

/**
 * Projet du YouTube Clipper URL-first (ADR-049) : la vidéo et ses passages proposés depuis le lien,
 * prévisualisables dans le lecteur YouTube ; état réel de la source vidéo autorisée ; génération des
 * clips (seulement quand la source est prête), téléchargement, publication et programmation.
 */
export default function ClipperProjectPage() {
  const { projectId } = useParams()
  const qc = useQueryClient()
  const query = useClipperProject(projectId)
  const request = useRequestClip(projectId ?? "")
  const reanalyze = useReanalyzeClipper(projectId ?? "")
  const cancel = useCancelClipper(projectId ?? "")
  const [pendingId, setPendingId] = useState<string | null>(null)
  const [preview, setPreview] = useState<{ startMs: number; endMs: number; label: string } | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const player = useRef<HTMLDivElement>(null)
  const [params, setParams] = useSearchParams()

  // Retour de l'écran de consentement Google : résultat affiché une fois, puis retiré de l'adresse.
  useEffect(() => {
    const result = params.get("portability")
    if (!result) return
    if (result === "connected") toast.success("Export Google autorisé : récupération de la vidéo en cours.", { id: "portability" })
    else toast.error(PORTABILITY_ERRORS[params.get("code") ?? ""] ?? "L'autorisation Google n'a pas abouti. Réessayez.", { id: "portability" })
    setParams({}, { replace: true })
  }, [params, setParams])

  // Un clip vient d'être publié pendant que la page est ouverte : la médiathèque est relue.
  // Comparaison seulement entre deux chargements réels (jamais à l'ouverture de la page).
  const rendered = query.data ? query.data.project.clips.filter((c) => c.status === "rendered").length : null
  const previous = useRef<number | null>(null)
  useEffect(() => {
    if (rendered === null) return
    if (previous.current !== null && rendered > previous.current) {
      void qc.invalidateQueries({ queryKey: qk.media.all })
      toast.success("Clip prêt : il est disponible dans votre médiathèque.")
    }
    previous.current = rendered
  }, [rendered, qc])

  if (query.isLoading) return <ListSkeleton rows={5} />
  // Écran d'erreur seulement si AUCUNE donnée n'a pu être chargée ; « introuvable » uniquement sur un vrai 404.
  // Un échec passager du suivi automatique (serveur occupé par un rendu) garde la page et l'indique (bandeau).
  if (!query.data) {
    const notFound = query.error instanceof ApiError && query.error.status === 404
    return (
      <ErrorState
        title={notFound ? "Projet introuvable" : "Impossible de charger le projet"}
        description={apiErrorMessage(query.error, notFound ? "Ce projet n'existe pas ou a été supprimé." : "Le serveur n'a pas répondu. Réessayez.")}
        onRetry={() => query.refetch()}
      />
    )
  }
  const connectionLost = query.isError

  const { project, capabilities } = query.data
  const analyzing = ANALYZING.includes(project.status)
  const suggestions = project.clips.filter((c) => c.source !== "manual")
  const manual = project.clips.filter((c) => c.source === "manual")
  const canClip = !analyzing && project.renderable && project.durationMs !== null
  const renderBlocked = !capabilities.rendererAvailable || !project.renderable
  const blockedReason = !capabilities.rendererAvailable
    ? "Le moteur vidéo n'est pas disponible sur le serveur."
    : !project.renderable
      ? "Disponible dès que la source vidéo autorisée est prête."
      : analyzing ? "Préparation des clips en cours (recalage sur la vidéo réelle)." : undefined
  const selectable = suggestions.filter((c) => ["suggested", "edited", "failed"].includes(c.status))
  const selection = selected.filter((id) => selectable.some((c) => c.id === id))

  const create = (key: string, vars: Parameters<typeof request.mutate>[0], quiet = false) => {
    setPendingId(key)
    request.mutate(vars, {
      onSuccess: () => !quiet && toast.success("Génération du clip lancée."),
      onError: (e) => toast.error(apiErrorMessage(e, "Le clip n'a pas pu être lancé.")),
      onSettled: () => setPendingId(null),
    })
  }
  const generateSelection = () => {
    const defaults = defaultRenderOptions(project.aspectRatio)
    for (const id of selection) {
      create(id, { suggestionId: id, aspectRatio: defaults.aspectRatio, subtitles: project.hasTranscript, subtitleStyle: defaults.subtitleStyle }, true)
    }
    toast.success(`${selection.length} clip(s) en cours de génération.`)
    setSelected([])
  }
  const showPreview = (segment: { startMs: number; endMs: number; label: string }) => {
    setPreview(segment)
    player.current?.scrollIntoView({ behavior: "smooth", block: "start" })
  }
  // Traitement en cours (analyse, recherche de source, rendu) : annulable (ADR-051).
  const busy = analyzing || project.clips.some((c) => c.status === "rendering") || ["pending", "resolving", "waiting_for_provider"].includes(project.sourceStatus.status)
  const stop = () =>
    cancel.mutate(undefined, {
      onSuccess: () => toast.success("Traitement annulé. Les clips déjà prêts sont conservés."),
      onError: (e) => toast.error(apiErrorMessage(e, "Le traitement n'a pas pu être annulé.")),
    })
  const relaunch = () =>
    reanalyze.mutate(undefined, {
      onSuccess: () => toast.success("Analyse relancée."),
      onError: (e) => toast.error(apiErrorMessage(e, "L'analyse n'a pas pu être relancée.")),
    })

  return (
    <div>
      <Link to="/studio/clipper" className="mb-3 inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden="true" /> Créer un clip YouTube
      </Link>
      <PageHeader
        title={project.title}
        description={[project.youtubeAuthor, project.durationMs !== null ? `Vidéo de ${formatTimecode(project.durationMs)}` : null].filter(Boolean).join(" · ") || undefined}
        actions={
          <a href={project.youtubeUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline">
            Voir sur YouTube <ExternalLink className="size-4" aria-hidden="true" />
          </a>
        }
      />

      {connectionLost && (
        <p role="status" className="mb-4 flex items-center gap-2 rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
          <LoaderCircle className="size-4 shrink-0 animate-spin" aria-hidden="true" />
          Mise à jour momentanément interrompue (serveur occupé) : nouvel essai automatique. Le traitement continue côté serveur.
        </p>
      )}

      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
        <div ref={player} className="scroll-mt-20">
          {project.youtubeVideoId ? (
            <YouTubePreview videoId={project.youtubeVideoId} segment={preview} title={project.title} />
          ) : project.youtubeThumbnail ? (
            <img src={project.youtubeThumbnail} alt="" className="aspect-video w-full rounded-xl object-cover" referrerPolicy="no-referrer" />
          ) : null}
        </div>
        <div className="min-w-0 space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={PROJECT_STATUS[project.status].variant}>{PROJECT_STATUS[project.status].label}</Badge>
            {busy && project.status !== "cancelled" && (
              <Button size="sm" variant="outline" disabled={cancel.isPending} onClick={stop}>
                {cancel.isPending ? <LoaderCircle className="animate-spin" /> : <CircleStop />} Annuler le traitement
              </Button>
            )}
            {!analyzing && (project.status === "ready" || project.status === "failed" || project.status === "cancelled") && (
              <Button size="sm" variant="outline" disabled={reanalyze.isPending || project.clips.some((c) => c.status === "rendering")} onClick={relaunch}>
                {reanalyze.isPending ? <LoaderCircle className="animate-spin" /> : <RefreshCw />} Relancer l'analyse
              </Button>
            )}
          </div>
          <SourceCard project={project} disabled={analyzing} />
          {project.status === "ready" && <AnalysisSummary project={project} />}
          {project.status === "cancelled" && (
            <p role="status" className="rounded-xl bg-muted p-3 text-sm text-ink-soft">
              Traitement annulé. Les clips déjà prêts restent dans votre médiathèque ; relancez l'analyse pour reprendre.
            </p>
          )}
          {project.status === "failed" && (
            <p role="alert" className="rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
              {project.errorMessage ?? "L'analyse de la vidéo a échoué."} Vous pouvez relancer l'analyse.
            </p>
          )}
          {!capabilities.rendererAvailable && (
            <p className="rounded-xl bg-warning-50 p-3 text-sm text-warning-700">Le moteur vidéo (FFmpeg) n'est pas disponible sur le serveur : les clips ne peuvent pas être générés.</p>
          )}
        </div>
      </div>

      <div className="mb-6">
        <ClipperPipeline project={project} />
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <SectionCard title="Passages proposés">
          {selectable.length > 0 && (
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <p className="text-xs text-ink-muted">Cochez les passages à générer, ou générez-les un par un.</p>
              <Button size="sm" disabled={selection.length === 0 || !canClip || renderBlocked || pendingId !== null} onClick={generateSelection}>
                <Scissors /> Générer la sélection{selection.length > 0 ? ` (${selection.length})` : ""}
              </Button>
            </div>
          )}
          {analyzing && suggestions.length === 0 ? (
            <p className="py-6 text-center text-sm text-ink-muted" role="status">
              {project.renderable ? "Préparation des clips : les passages apparaîtront ici automatiquement." : "Analyse de la vidéo depuis le lien : les passages apparaîtront ici automatiquement."}
            </p>
          ) : suggestions.length === 0 ? (
            <EmptyState icon={Scissors} title="Aucun passage proposé" description={canClip ? "Choisissez un passage vous-même : début et fin, 60 secondes maximum." : "Relancez l'analyse ; les passages proposés apparaîtront ici."} className="py-8" />
          ) : (
            <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
              {suggestions.map((clip, i) => (
                <li key={clip.id}>
                  <ClipCard
                    clip={clip}
                    index={i}
                    busy={pendingId === clip.id}
                    disabled={!canClip || renderBlocked}
                    disabledReason={blockedReason}
                    canSubtitle={project.hasTranscript}
                    onPreview={project.youtubeVideoId ? () => showPreview({ startMs: clip.startMs, endMs: clip.endMs, label: clip.title }) : undefined}
                    selected={selection.includes(clip.id)}
                    onSelectedChange={(on) => setSelected((s) => (on ? [...s, clip.id] : s.filter((id) => id !== clip.id)))}
                    onCreate={(o) => create(clip.id, { suggestionId: clip.id, aspectRatio: o.aspectRatio, subtitles: o.subtitles && project.hasTranscript, subtitleStyle: o.subtitleStyle })}
                  />
                </li>
              ))}
            </ul>
          )}
        </SectionCard>

        <div className="space-y-6">
          <SectionCard title="Choisir un passage">
            {canClip && project.durationMs !== null ? (
              <ManualClipForm
                sourceDurationMs={project.durationMs}
                minClipMs={project.minClipMs}
                defaultAspectRatio={project.aspectRatio}
                canSubtitle={project.hasTranscript}
                busy={pendingId === "manual"}
                disabled={!capabilities.rendererAvailable}
                onSubmit={(input) => create("manual", input)}
              />
            ) : (
              <p className="text-sm text-ink-muted">Disponible dès que la source vidéo autorisée est prête (sa durée réelle est mesurée par le serveur).</p>
            )}
          </SectionCard>

          {manual.length > 0 && (
            <SectionCard title="Vos clips">
              <ul className="space-y-3">
                {manual.map((clip) => (
                  <li key={clip.id}>
                    <ClipCard
                      clip={clip}
                      busy={pendingId === clip.id}
                      disabled={renderBlocked}
                      disabledReason={blockedReason}
                      canSubtitle={project.hasTranscript}
                      onPreview={project.youtubeVideoId ? () => showPreview({ startMs: clip.startMs, endMs: clip.endMs, label: clip.title }) : undefined}
                      onCreate={(o) => create(clip.id, { suggestionId: clip.id, aspectRatio: o.aspectRatio, subtitles: o.subtitles && project.hasTranscript, subtitleStyle: o.subtitleStyle })}
                    />
                  </li>
                ))}
              </ul>
            </SectionCard>
          )}
        </div>
      </div>
    </div>
  )
}
