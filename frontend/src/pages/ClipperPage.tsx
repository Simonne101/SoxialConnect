import { useQuery } from "@tanstack/react-query"
import { ArrowLeft, Film, Link2, LoaderCircle, Scissors, Trash2 } from "lucide-react"
import { useEffect, useState, type FormEvent } from "react"
import { Link, useNavigate } from "react-router"
import { toast } from "sonner"
import { MAX_CLIP_MS, PROJECT_STATUS, formatTimecode, isYouTubeUrl } from "@/components/creative/labels"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { useClipperProjects, useCreateClipperProject, useDeleteClipperProject } from "@/hooks/queries/creative"
import { formatRelative } from "@/lib/format"
import { apiErrorMessage, isApiEnabled } from "@/lib/http"
import { cn } from "@/lib/utils"
import { CREATIVE_UNAVAILABLE, lookupYouTubeVideo } from "@/services/creative"
import type { ClipAspectRatio, ClipperProject } from "@/types"

const FORMATS: { value: ClipAspectRatio; label: string; hint: string }[] = [
  { value: "9:16", label: "9:16", hint: "Shorts, Reels, TikTok" },
  { value: "1:1", label: "1:1", hint: "Carré" },
  { value: "16:9", label: "16:9", hint: "Paysage" },
]

/** Valeur prise en compte après une courte pause de saisie (pas d'appel à chaque touche). */
function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(value), ms)
    return () => window.clearTimeout(t)
  }, [value, ms])
  return debounced
}

/**
 * YouTube Clipper, parcours URL-first (ADR-048, ADR-049) : coller le lien → vidéo identifiée (titre,
 * chaîne, miniature) → format → analyse. Aucun fichier demandé ici : la source vidéo autorisée,
 * nécessaire au seul rendu des MP4, est suivie (et si besoin finalisée) depuis le projet.
 */
export default function ClipperPage() {
  const navigate = useNavigate()
  const projects = useClipperProjects()
  const create = useCreateClipperProject()
  const remove = useDeleteClipperProject()
  const [url, setUrl] = useState("")
  const [aspectRatio, setAspectRatio] = useState<ClipAspectRatio>("9:16")
  const [rightsConfirmed, setRightsConfirmed] = useState(false)
  const [toDelete, setToDelete] = useState<ClipperProject | null>(null)

  const trimmed = url.trim()
  const debouncedUrl = useDebounced(trimmed, 500)
  const validFormat = isYouTubeUrl(debouncedUrl)
  const preview = useQuery({
    queryKey: ["clipper", "lookup", debouncedUrl],
    queryFn: () => lookupYouTubeVideo(debouncedUrl),
    enabled: isApiEnabled && validFormat,
    retry: false,
    staleTime: 5 * 60_000,
  })
  const settled = debouncedUrl === trimmed
  const formatError = trimmed !== "" && settled && !validFormat ? "Collez le lien d'une vidéo YouTube (youtube.com/watch?v=…, youtu.be/…, /shorts/…)." : null
  const capabilities = projects.data?.capabilities
  const ready = Boolean(preview.data) && settled && validFormat

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!ready || !preview.data) return
    create.mutate({ youtubeUrl: preview.data.url, aspectRatio, rightsConfirmed: Boolean(capabilities?.videoDownloadAvailable) && rightsConfirmed }, { onSuccess: (p) => navigate(`/studio/clipper/${p.id}`) })
  }

  return (
    <div>
      <Link to="/studio" className="mb-3 inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden="true" /> AI Creative Studio
      </Link>
      <PageHeader title="Créer un clip YouTube" description={`Collez le lien d'une vidéo : SoxialConnect l'identifie, propose les meilleurs passages et génère des clips de ${MAX_CLIP_MS / 1000} secondes maximum.`} />

      {!isApiEnabled ? (
        <EmptyState icon={Scissors} title="Clipper indisponible" description={CREATIVE_UNAVAILABLE} />
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
          <SectionCard title="Nouvelle vidéo">
            <form onSubmit={submit} className="space-y-5" noValidate>
              <div className="space-y-1.5">
                <Label htmlFor="youtube-url">Lien de la vidéo YouTube</Label>
                <div className="relative">
                  <Link2 className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
                  <Input
                    id="youtube-url"
                    type="url"
                    inputMode="url"
                    autoComplete="off"
                    placeholder="https://www.youtube.com/watch?v=…"
                    className="pl-10"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    aria-invalid={formatError || preview.isError ? true : undefined}
                    aria-describedby="youtube-url-status"
                  />
                </div>
                <div id="youtube-url-status" aria-live="polite">
                  {formatError && <p role="alert" className="text-sm text-danger-700">{formatError}</p>}
                  {validFormat && settled && preview.isFetching && (
                    <p className="flex items-center gap-2 text-sm text-ink-muted"><LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> Identification de la vidéo…</p>
                  )}
                  {validFormat && settled && preview.isError && !preview.isFetching && (
                    <p role="alert" className="text-sm text-danger-700">{apiErrorMessage(preview.error, "Cette vidéo n'a pas pu être identifiée.")}</p>
                  )}
                </div>
              </div>

              {ready && preview.data && (
                <div className="flex flex-col gap-3 rounded-xl border border-success-100 bg-success-50/40 p-3 sm:flex-row sm:items-center">
                  {preview.data.thumbnail ? (
                    <img src={preview.data.thumbnail} alt="" className="aspect-video w-full rounded-lg object-cover sm:w-40" referrerPolicy="no-referrer" />
                  ) : (
                    <div className="flex aspect-video w-full items-center justify-center rounded-lg bg-muted sm:w-40"><Film className="size-6 text-ink-muted" aria-hidden="true" /></div>
                  )}
                  <div className="min-w-0">
                    <p className="text-xs font-semibold tracking-wide text-success-700 uppercase">Vidéo détectée</p>
                    <p className="mt-0.5 font-semibold text-ink">{preview.data.title}</p>
                    {preview.data.author && <p className="text-sm text-ink-muted">{preview.data.author}</p>}
                  </div>
                </div>
              )}

              <fieldset className="space-y-2">
                <legend className="text-sm font-medium text-ink">Format des clips</legend>
                <RadioGroup value={aspectRatio} onValueChange={(v) => setAspectRatio(v as ClipAspectRatio)} className="grid grid-cols-3 gap-2">
                  {FORMATS.map((f) => (
                    <Label
                      key={f.value}
                      htmlFor={`format-${f.value}`}
                      className={cn("flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border p-2.5 text-center font-normal", aspectRatio === f.value ? "border-brand-500 bg-brand-50" : "border-border hover:bg-muted")}
                    >
                      <RadioGroupItem id={`format-${f.value}`} value={f.value} className="sr-only" />
                      <span className="text-sm font-semibold text-ink">{f.label}</span>
                      <span className="text-xs text-ink-muted">{f.hint}</span>
                    </Label>
                  ))}
                </RadioGroup>
              </fieldset>

              {capabilities && (
                <p className={cn("rounded-xl p-3 text-sm", capabilities.urlAnalysisAvailable ? "bg-brand-50 text-brand-700" : "bg-warning-50 text-warning-700")}>
                  {capabilities.urlAnalysisAvailable
                    ? "Analyse directe depuis le lien : l'IA regarde la vidéo publique, propose les meilleurs passages et vous les prévisualisez dans le lecteur YouTube."
                    : "L'analyse directe depuis le lien n'est pas configurée sur ce serveur (fournisseur compatible requis, ex. Gemini)."}
                  {" "}Les MP4 sont générés dès qu'une source vidéo autorisée est disponible pour cette vidéo.
                </p>
              )}
              {capabilities?.videoDownloadAvailable && (
                <div className="flex items-start gap-2 rounded-xl border border-border p-3">
                  <Checkbox id="clipper-rights" checked={rightsConfirmed} onCheckedChange={(v) => setRightsConfirmed(v === true)} className="mt-0.5" />
                  <Label htmlFor="clipper-rights" className="block text-sm font-normal leading-snug text-ink-soft">
                    <span className="font-medium text-ink">J'atteste détenir les droits ou l'autorisation d'utiliser cette vidéo.</span>{" "}
                    SoxialConnect pourra alors récupérer automatiquement le fichier de cette vidéo publique pour générer les clips. Sans cette attestation, vous importerez le fichier vous-même.
                  </Label>
                </div>
              )}
              {capabilities && !capabilities.rendererAvailable && (
                <p className="rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
                  Le moteur vidéo (FFmpeg) n'est pas disponible sur le serveur : les clips ne pourront pas être générés tant qu'il n'est pas installé.
                </p>
              )}

              {create.isError && <p role="alert" className="text-sm font-medium text-danger-700">{apiErrorMessage(create.error, "L'analyse n'a pas pu être lancée.")}</p>}

              <Button type="submit" className="w-full" disabled={!ready || create.isPending}>
                {create.isPending ? <LoaderCircle className="animate-spin" /> : <Scissors />} Analyser la vidéo
              </Button>
            </form>
          </SectionCard>

          <SectionCard title="Vos vidéos">
            {projects.isLoading ? (
              <ListSkeleton rows={3} />
            ) : projects.isError ? (
              <ErrorState onRetry={() => projects.refetch()} />
            ) : !projects.data?.projects.length ? (
              <EmptyState icon={Scissors} title="Aucune vidéo pour l'instant" description="Collez un lien YouTube pour obtenir vos premiers passages." className="py-8" />
            ) : (
              <ul className="divide-y divide-border">
                {projects.data.projects.map((p) => (
                  <li key={p.id} className="flex items-start gap-3 py-3">
                    {p.youtubeThumbnail && <img src={p.youtubeThumbnail} alt="" className="aspect-video w-20 shrink-0 rounded-md object-cover" referrerPolicy="no-referrer" />}
                    <div className="min-w-0 flex-1">
                      <Link to={`/studio/clipper/${p.id}`} className="font-medium text-ink hover:text-brand-600">{p.title}</Link>
                      <p className="mt-0.5 text-xs text-ink-muted">
                        {p.durationMs !== null ? `${formatTimecode(p.durationMs)} · ` : ""}{p.aspectRatio} · {formatRelative(p.createdAt)}
                      </p>
                      <div className="mt-1.5 flex flex-wrap gap-1.5">
                        <Badge variant={PROJECT_STATUS[p.status].variant}>{PROJECT_STATUS[p.status].label}</Badge>
                        {!p.renderable && <Badge variant="neutral">{["pending", "resolving", "waiting_for_provider"].includes(p.sourceStatus.status) ? "Source en préparation" : "Source requise pour les MP4"}</Badge>}
                      </div>
                    </div>
                    <Button variant="ghost" size="icon-sm" aria-label={`Supprimer « ${p.title} »`} disabled={remove.isPending} onClick={() => setToDelete(p)}>
                      <Trash2 />
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>
        </div>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={`Supprimer « ${toDelete?.title ?? ""} » ?`}
        description="L'analyse et les passages proposés seront supprimés. Les clips déjà générés restent dans votre médiathèque."
        confirmLabel="Supprimer"
        onConfirm={() => {
          if (toDelete) remove.mutate(toDelete.id, { onSuccess: () => toast.success("Projet supprimé."), onError: (e) => toast.error(apiErrorMessage(e)) })
          setToDelete(null)
        }}
      />
    </div>
  )
}
