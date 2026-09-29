import { ArrowLeft, Check, CircleAlert, CloudUpload, Link2, LoaderCircle, Sparkles } from "lucide-react"
import { useRef, useState, type DragEvent } from "react"
import { toast } from "sonner"
import { PageHeader } from "@/components/shared/PageHeader"
import { DemoNotice, EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Progress } from "@/components/ui/progress"
import { Switch } from "@/components/ui/switch"
import { VideoWorkspace } from "@/components/video/VideoWorkspace"
import { useCreateVideoProject, useVideoProject, useVideoProjects } from "@/hooks/queries/video"
import { formatBytes, formatDuration, formatRelative } from "@/lib/format"
import { PROJECT_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { VideoProject, VideoProjectStatus } from "@/types"

const PIPELINE: { key: VideoProjectStatus | "clips" | "export"; label: string }[] = [
  { key: "importing", label: "Import" },
  { key: "analyzing", label: "Analyse" },
  { key: "transcribing", label: "Transcription" },
  { key: "detecting", label: "Détection" },
  { key: "clips", label: "Clips" },
  { key: "export", label: "Export" },
]
const ORDER: VideoProjectStatus[] = ["pending", "importing", "analyzing", "transcribing", "detecting", "ready"]
const MAX_BYTES = 1024 * 1024 * 1024

function Pipeline({ project }: { project: VideoProject }) {
  const reached = project.status === "ready" ? 4 : Math.max(0, ORDER.indexOf(project.status) - 1)
  const hasExport = project.clips.some((c) => c.status === "rendered")
  return (
    <ol className="flex flex-wrap items-center gap-2" aria-label="Étapes du traitement">
      {PIPELINE.map((step, i) => {
        const done = project.status === "failed" ? false : i < reached || (i === 4 && project.status === "ready") || (i === 5 && hasExport)
        const active = project.status !== "ready" && project.status !== "failed" && i === reached
        return (
          <li key={step.key} className="flex items-center gap-2">
            <span className={cn("inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold", done ? "bg-success-50 text-success-700" : active ? "bg-brand-50 text-brand-700" : "bg-muted text-ink-muted")}>
              {done ? <Check className="size-3.5" aria-hidden="true" /> : active ? <LoaderCircle className="size-3.5 animate-spin" aria-hidden="true" /> : <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />}
              {step.label}
            </span>
            {i < PIPELINE.length - 1 && <span className="hidden h-px w-4 bg-border sm:block" aria-hidden="true" />}
          </li>
        )
      })}
    </ol>
  )
}

function UploadCard() {
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [dragging, setDragging] = useState(false)
  const [url, setUrl] = useState("")
  const [opts, setOpts] = useState({ moments: true, clips: true, formats: true })
  const create = useCreateVideoProject()

  const pick = (f: File | undefined) => {
    if (!f) return
    if (!["video/mp4", "video/quicktime", "video/x-msvideo", "video/webm"].includes(f.type)) return toast.error("Format non accepté. Utilisez MP4, MOV, AVI ou WebM.")
    if (f.size > MAX_BYTES) return toast.error("Vidéo trop volumineuse (1 Go maximum).")
    setFile(f)
  }
  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    pick(e.dataTransfer.files[0])
  }
  const analyze = () => {
    const source = file ?? (url ? { name: url.split("/").pop() || "video-importee.mp4", size: 0 } : null)
    if (!source) return toast.error("Ajoutez d'abord une vidéo.")
    create.mutate(source, {
      onSuccess: () => {
        toast.success("Analyse lancée. Vous pouvez suivre la progression ci-contre.")
        setFile(null)
        setUrl("")
      },
    })
  }

  return (
    <section className="surface-card p-5" aria-labelledby="upload-title">
      <h2 id="upload-title" className="text-[15px] font-semibold">Nouvelle vidéo</h2>
      <div
        onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={cn("mt-4 flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors", dragging ? "border-brand-500 bg-brand-50" : "border-brand-200 bg-brand-50/30")}
      >
        <CloudUpload className="size-10 text-brand-500" aria-hidden="true" />
        {file ? (
          <>
            <p className="text-sm font-semibold text-ink">{file.name}</p>
            <p className="text-xs text-ink-muted">{formatBytes(file.size)}</p>
            <Button variant="ghost" size="sm" onClick={() => setFile(null)}>Changer de vidéo</Button>
          </>
        ) : (
          <>
            <p className="text-sm font-semibold text-ink">Glissez votre vidéo ici</p>
            <button type="button" onClick={() => inputRef.current?.click()} className="text-sm font-medium text-brand-600 hover:underline">ou cliquez pour importer</button>
            <p className="text-xs text-ink-muted">Formats : MP4, MOV, AVI · Max 1 Go</p>
          </>
        )}
        <input ref={inputRef} type="file" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm" className="sr-only" tabIndex={-1} aria-hidden="true" onChange={(e) => pick(e.target.files?.[0])} />
      </div>

      <div className="mt-4 space-y-1.5">
        <Label htmlFor="video-url" className="flex items-center gap-1.5 text-[13px] font-semibold"><Link2 className="size-4" aria-hidden="true" /> Ou lien direct vers un fichier vidéo</Label>
        <Input id="video-url" type="url" placeholder="https://…/ma-video.mp4" value={url} onChange={(e) => setUrl(e.target.value)} disabled={Boolean(file)} />
        <p className="text-xs text-ink-muted">Uniquement une vidéo dont vous détenez les droits. Les liens YouTube ou TikTok ne sont pas acceptés.</p>
      </div>

      <fieldset className="mt-5">
        <legend className="text-[13px] font-semibold text-ink">Analyse automatique</legend>
        <div className="mt-2 space-y-2">
          {(
            [
              ["moments", "Détection des moments clés"],
              ["clips", "Génération de clips courts"],
              ["formats", "Adaptation aux formats sociaux"],
            ] as const
          ).map(([key, label]) => (
            <div key={key} className="flex items-center justify-between gap-3">
              <Label htmlFor={`opt-${key}`} className="text-sm font-normal text-ink-soft">{label}</Label>
              <Switch id={`opt-${key}`} checked={opts[key]} onCheckedChange={(c) => setOpts((o) => ({ ...o, [key]: c }))} />
            </div>
          ))}
        </div>
      </fieldset>

      <Button size="lg" className="mt-5 w-full" onClick={analyze} disabled={create.isPending || (!file && !url)}>
        {create.isPending ? <LoaderCircle className="animate-spin" /> : <Sparkles />} Analyser la vidéo
      </Button>
    </section>
  )
}

export default function VideoStudioPage() {
  const [openId, setOpenId] = useState<string | null>(null)
  const projects = useVideoProjects()
  const project = useVideoProject(openId)
  const p = project.data

  return (
    <>
      <PageHeader
        title="Clipping vidéo"
        description="Transformez vos longues vidéos en clips courts, prêts à publier."
        actions={
          openId ? (
            <Button variant="outline" onClick={() => setOpenId(null)}>
              <ArrowLeft /> Tous les projets
            </Button>
          ) : undefined
        }
      />
      <DemoNotice className="mb-6">
        Video Studio — aperçu fonctionnel. L'interface est prête pour la future chaîne de traitement (FFmpeg + IA côté serveur) : aucune vidéo n'est réellement analysée ni découpée pour le moment.
      </DemoNotice>

      {openId ? (
        project.isLoading ? (
          <ListSkeleton rows={6} />
        ) : project.isError || !p ? (
          <ErrorState title="Projet introuvable" onRetry={() => project.refetch()} />
        ) : (
          <div className="space-y-6">
            <div className="surface-card flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <h2 className="text-lg font-bold">{p.title}</h2>
                <p className="text-sm text-ink-muted">{formatDuration(p.durationMs)} · {p.language === "fr" ? "Français" : "Langue en cours de détection"} · {formatBytes(p.sizeBytes)}</p>
              </div>
              <Pipeline project={p} />
            </div>
            {p.status === "ready" ? (
              p.clips.length ? <VideoWorkspace project={p} /> : <EmptyState icon={Sparkles} title="Aucun clip détecté" description="Ajoutez un clip manuellement depuis la timeline." />
            ) : p.status === "failed" ? (
              <ErrorState title="Le traitement de cette vidéo a échoué" description="Le fichier semble corrompu ou dans un format non pris en charge. Réimportez la vidéo." />
            ) : (
              <div className="surface-card flex flex-col items-center gap-4 p-10 text-center" role="status" aria-live="polite">
                <LoaderCircle className="size-10 animate-spin text-brand-500" aria-hidden="true" />
                <p className="text-base font-semibold text-ink">{PROJECT_STATUS[p.status].label}</p>
                <Progress value={(Math.max(1, ORDER.indexOf(p.status)) / (ORDER.length - 1)) * 100} className="w-full max-w-sm" aria-label="Progression du traitement" />
                <p className="max-w-sm text-sm text-ink-muted">Vous pouvez quitter cette page : vous recevrez une notification quand les clips seront prêts.</p>
              </div>
            )}
          </div>
        )
      ) : (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
          <UploadCard />
          <section className="surface-card p-5" aria-labelledby="projects-title">
            <h2 id="projects-title" className="text-[15px] font-semibold">Mes projets vidéo</h2>
            {projects.isLoading ? (
              <ListSkeleton rows={3} className="mt-4" />
            ) : projects.isError ? (
              <ErrorState onRetry={() => projects.refetch()} />
            ) : !projects.data?.length ? (
              <EmptyState icon={CloudUpload} title="Aucun projet" description="Importez une première vidéo pour en extraire des clips." className="mt-4" />
            ) : (
              <ul className="mt-4 space-y-3">
                {projects.data.map((pr) => {
                  const busy = !["ready", "failed", "cancelled"].includes(pr.status)
                  return (
                    <li key={pr.id}>
                      <button type="button" onClick={() => setOpenId(pr.id)} className="flex w-full items-center gap-4 rounded-xl border border-border p-3 text-left hover:border-brand-200">
                        <span className={cn("aspect-video w-24 shrink-0 rounded-lg sm:w-32", pr.previewClassName)} aria-hidden="true" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-ink">{pr.title}</span>
                          <span className="block text-xs text-ink-muted">{pr.durationMs ? formatDuration(pr.durationMs) : "Durée inconnue"} · {formatRelative(pr.createdAt)}</span>
                          <span className="mt-2 flex flex-wrap items-center gap-2">
                            <StatusBadge meta={PROJECT_STATUS[pr.status]} />
                            {pr.status === "ready" && <span className="text-xs font-medium text-violet-600">{pr.clips.length} clips</span>}
                            {pr.status === "failed" && <CircleAlert className="size-4 text-danger-500" aria-hidden="true" />}
                          </span>
                          {busy && <Progress value={(Math.max(1, ORDER.indexOf(pr.status)) / (ORDER.length - 1)) * 100} className="mt-2 h-1.5" aria-label={`Progression : ${PROJECT_STATUS[pr.status].label}`} />}
                        </span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
        </div>
      )}
    </>
  )
}
