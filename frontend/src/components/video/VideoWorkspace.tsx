import { Captions, Download, LoaderCircle, Pause, Play, Plus, Scissors, Search, SquarePen, Star } from "lucide-react"
import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Progress } from "@/components/ui/progress"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Slider } from "@/components/ui/slider"
import { Switch } from "@/components/ui/switch"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useAddClip, useExportClip, useUpdateClip } from "@/hooks/queries/video"
import { formatDuration } from "@/lib/format"
import { CLIP_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { AspectRatio, SubtitleStyle, VideoClip, VideoProject } from "@/types"

const RATIOS: { value: AspectRatio; label: string; css: string }[] = [
  { value: "9:16", label: "9:16 · Vertical", css: "9 / 16" },
  { value: "1:1", label: "1:1 · Carré", css: "1 / 1" },
  { value: "16:9", label: "16:9 · Paysage", css: "16 / 9" },
]

function scoreTone(score: number | null) {
  if (score === null) return "neutral" as const
  if (score >= 85) return "success" as const
  if (score >= 70) return "info" as const
  return "warning" as const
}

function ZoneLabel({ children }: { children: string }) {
  return <span className="rounded-md bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-violet-600 uppercase">{children}</span>
}

export function VideoWorkspace({ project }: { project: VideoProject }) {
  const navigate = useNavigate()
  const duration = project.durationMs ?? 0
  const clips = useMemo(() => [...project.clips].sort((a, b) => (b.score ?? 0) - (a.score ?? 0)), [project.clips])
  const [selectedId, setSelectedId] = useState<string | null>(clips[0]?.id ?? null)
  const clip = clips.find((c) => c.id === selectedId) ?? null
  const [current, setCurrent] = useState(clip?.startMs ?? 0)
  const [playing, setPlaying] = useState(false)
  const [transcriptQuery, setTranscriptQuery] = useState("")
  const update = useUpdateClip()
  const exportClip = useExportClip()
  const addClip = useAddClip()

  // Lecture simulée (aucune vidéo réelle) : fait avancer la tête de lecture, en boucle sur le clip.
  useEffect(() => {
    if (!playing) return
    const t = setInterval(() => {
      setCurrent((c) => {
        const end = clip ? clip.endMs : duration
        const start = clip ? clip.startMs : 0
        const next = c + 250
        return next > end ? start : next
      })
    }, 250)
    return () => clearInterval(t)
  }, [playing, clip, duration])

  const select = (c: VideoClip) => {
    setSelectedId(c.id)
    setCurrent(c.startMs)
    setPlaying(false)
  }

  const patch = (p: Parameters<typeof update.mutate>[0]["patch"]) => clip && update.mutate({ projectId: project.id, clipId: clip.id, patch: p })

  const subtitle = project.transcript.find((s) => current >= s.startMs && current <= s.endMs + 8000)?.text
  const ratio = RATIOS.find((r) => r.value === clip?.aspectRatio) ?? RATIOS[0]
  const clipLen = clip ? clip.endMs - clip.startMs : 0
  const segments = project.transcript.filter((s) => s.text.toLowerCase().includes(transcriptQuery.toLowerCase()))

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
      <div className="space-y-6">
        {/* Aperçu vidéo */}
        <section className="surface-card p-5" aria-labelledby="preview-title">
          <div className="mb-4 flex items-center justify-between gap-2">
            <h2 id="preview-title" className="text-[15px] font-semibold">Aperçu du clip</h2>
            <ZoneLabel>Lecteur simulé</ZoneLabel>
          </div>
          <div className="flex justify-center rounded-2xl bg-navy-950 p-4">
            <div className={cn("relative w-full overflow-hidden rounded-xl", project.previewClassName, ratio.value === "16:9" ? "max-w-[560px]" : ratio.value === "1:1" ? "max-w-[340px]" : "max-w-[230px]")} style={{ aspectRatio: ratio.css }}>
              <button type="button" onClick={() => setPlaying((p) => !p)} className="absolute inset-0 flex items-center justify-center" aria-label={playing ? "Pause" : "Lecture"}>
                {!playing && (
                  <span className="inline-flex size-14 items-center justify-center rounded-full bg-white/90 text-navy-900 shadow-float">
                    <Play className="ml-0.5 size-6 fill-current" />
                  </span>
                )}
              </button>
              {clip?.subtitlesEnabled && subtitle && (
                <p
                  className={cn(
                    "pointer-events-none absolute inset-x-3 text-center text-sm leading-snug font-bold text-white [text-shadow:0_2px_6px_rgb(0_0_0/0.7)]",
                    clip.subtitleStyle.position === "top" ? "top-4" : clip.subtitleStyle.position === "center" ? "top-1/2 -translate-y-1/2" : "bottom-4",
                    clip.subtitleStyle.preset === "karaoke" && "text-warning-100",
                    clip.subtitleStyle.preset === "minimal" && "font-medium",
                    clip.subtitleStyle.preset === "bold" && "text-base uppercase",
                  )}
                >
                  {subtitle}
                </p>
              )}
            </div>
          </div>
          <div className="mt-3 flex items-center gap-3">
            <Button variant="outline" size="icon-sm" onClick={() => setPlaying((p) => !p)} aria-label={playing ? "Pause" : "Lecture"}>
              {playing ? <Pause /> : <Play />}
            </Button>
            <span className="text-sm font-medium text-ink tabular-nums">{formatDuration(current)}</span>
            <span className="text-sm text-ink-muted">/ {formatDuration(duration)}</span>
            {clip && <span className="ml-auto text-xs text-ink-muted">Clip : {formatDuration(clip.startMs)} → {formatDuration(clip.endMs)}</span>}
          </div>
        </section>

        {/* Timeline */}
        <section className="surface-card p-5" aria-labelledby="timeline-title">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h2 id="timeline-title" className="text-[15px] font-semibold">Timeline</h2>
            <ZoneLabel>Édition future : découpe précise</ZoneLabel>
          </div>
          <div
            className="relative h-16 cursor-pointer rounded-xl bg-page ring-1 ring-border"
            onClick={(e) => {
              const rect = e.currentTarget.getBoundingClientRect()
              setCurrent(Math.round(((e.clientX - rect.left) / rect.width) * duration))
            }}
            role="slider"
            tabIndex={0}
            aria-label="Position de lecture"
            aria-valuemin={0}
            aria-valuemax={Math.round(duration / 1000)}
            aria-valuenow={Math.round(current / 1000)}
            aria-valuetext={formatDuration(current)}
            onKeyDown={(e) => {
              if (e.key === "ArrowRight") setCurrent((c) => Math.min(duration, c + 5000))
              if (e.key === "ArrowLeft") setCurrent((c) => Math.max(0, c - 5000))
            }}
          >
            {/* Forme d'onde décorative */}
            <div className="absolute inset-x-2 inset-y-4 flex items-center gap-[2px] opacity-40" aria-hidden="true">
              {Array.from({ length: 120 }, (_, i) => (
                <span key={i} className="flex-1 rounded-full bg-ink-muted" style={{ height: `${20 + ((i * 37) % 70)}%` }} />
              ))}
            </div>
            {clips.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={(e) => { e.stopPropagation(); select(c) }}
                className={cn("absolute top-1.5 bottom-1.5 rounded-md border-2 transition-colors", c.id === selectedId ? "border-brand-500 bg-brand-500/35" : "border-violet-500/60 bg-violet-500/20 hover:bg-violet-500/35")}
                style={{ left: `${(c.startMs / duration) * 100}%`, width: `${Math.max(0.8, ((c.endMs - c.startMs) / duration) * 100)}%` }}
                aria-label={`Clip « ${c.title} » de ${formatDuration(c.startMs)} à ${formatDuration(c.endMs)}`}
              />
            ))}
            <span className="pointer-events-none absolute top-0 bottom-0 w-0.5 bg-danger-500" style={{ left: `${(current / Math.max(duration, 1)) * 100}%` }} aria-hidden="true" />
          </div>
          <div className="mt-2 flex justify-between text-[11px] text-ink-muted tabular-nums" aria-hidden="true">
            {[0, 0.25, 0.5, 0.75, 1].map((f) => <span key={f}>{formatDuration(duration * f)}</span>)}
          </div>
        </section>

        {/* Édition du clip */}
        {clip && (
          <section className="surface-card p-5" aria-labelledby="edit-title">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <h2 id="edit-title" className="flex items-center gap-2 text-[15px] font-semibold"><Scissors className="size-4 text-brand-500" aria-hidden="true" /> Modifier le clip</h2>
              <StatusBadge meta={CLIP_STATUS[clip.status]} />
            </div>
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
              <div className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="clip-title" className="text-[13px] font-semibold">Titre</Label>
                  <Input id="clip-title" key={clip.id} defaultValue={clip.title} onBlur={(e) => e.target.value !== clip.title && patch({ title: e.target.value })} />
                </div>
                <div className="space-y-3">
                  <div className="flex items-center justify-between text-[13px]">
                    <span className="font-semibold text-ink">Début et fin</span>
                    <span className="text-ink-muted tabular-nums">{formatDuration(clip.startMs)} → {formatDuration(clip.endMs)} · {Math.round(clipLen / 1000)} s</span>
                  </div>
                  <Slider
                    key={clip.id}
                    min={Math.max(0, clip.startMs - 60_000)}
                    max={Math.min(duration, clip.endMs + 60_000)}
                    step={500}
                    defaultValue={[clip.startMs, clip.endMs]}
                    minStepsBetweenThumbs={6}
                    onValueCommit={([s, e]) => { patch({ startMs: s, endMs: e }); setCurrent(s) }}
                    aria-label="Début et fin du clip"
                  />
                  {clipLen > 90_000 && <p className="text-xs text-warning-700">Au-delà de 90 secondes, certains formats courts (Reels, Shorts) peuvent refuser le clip (limites à confirmer).</p>}
                </div>
                <fieldset>
                  <legend className="text-[13px] font-semibold text-ink">Format</legend>
                  <div className="mt-2 grid grid-cols-3 gap-2">
                    {RATIOS.map((r) => (
                      <button key={r.value} type="button" aria-pressed={clip.aspectRatio === r.value} onClick={() => patch({ aspectRatio: r.value })} className={cn("flex flex-col items-center gap-1.5 rounded-xl border px-2 py-2.5 text-xs font-medium", clip.aspectRatio === r.value ? "border-brand-500 bg-brand-50 text-brand-700" : "border-border text-ink-soft hover:border-brand-200")}>
                        <span className="block rounded-sm border-2 border-current" style={{ aspectRatio: r.css, height: 26 }} aria-hidden="true" />
                        {r.label}
                      </button>
                    ))}
                  </div>
                </fieldset>
              </div>
              <div className="space-y-4">
                <div className="flex items-center justify-between rounded-xl border border-border px-3 py-2.5">
                  <Label htmlFor="clip-subs" className="flex items-center gap-2 text-sm font-medium"><Captions className="size-4" aria-hidden="true" /> Sous-titres automatiques</Label>
                  <Switch id="clip-subs" checked={clip.subtitlesEnabled} onCheckedChange={(c) => patch({ subtitlesEnabled: c })} />
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="sub-style" className="text-[13px] font-semibold">Style</Label>
                    <Select value={clip.subtitleStyle.preset} disabled={!clip.subtitlesEnabled} onValueChange={(v) => patch({ subtitleStyle: { ...clip.subtitleStyle, preset: v as SubtitleStyle["preset"] } })}>
                      <SelectTrigger id="sub-style" className="w-full"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="classic">Classique</SelectItem>
                        <SelectItem value="bold">Impact</SelectItem>
                        <SelectItem value="karaoke">Karaoké</SelectItem>
                        <SelectItem value="minimal">Minimal</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="sub-pos" className="text-[13px] font-semibold">Position</Label>
                    <Select value={clip.subtitleStyle.position} disabled={!clip.subtitlesEnabled} onValueChange={(v) => patch({ subtitleStyle: { ...clip.subtitleStyle, position: v as SubtitleStyle["position"] } })}>
                      <SelectTrigger id="sub-pos" className="w-full"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="bottom">En bas</SelectItem>
                        <SelectItem value="center">Au centre</SelectItem>
                        <SelectItem value="top">En haut</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                {clip.reason && (
                  <p className="flex items-start gap-2 rounded-xl bg-violet-50 px-3 py-2.5 text-xs text-violet-600">
                    <Star className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> Pourquoi ce passage : {clip.reason}
                  </p>
                )}
                {clip.status === "rendering" ? (
                  <div className="space-y-2" role="status">
                    <p className="flex items-center gap-2 text-sm font-medium text-brand-600"><LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> Export en cours…</p>
                    <Progress value={clip.renderProgress ?? 0} aria-label="Progression de l'export" />
                  </div>
                ) : clip.status === "rendered" ? (
                  <div className="flex flex-col gap-2 2xl:flex-row">
                    <Button className="flex-1" onClick={() => navigate("/content/create", { state: { body: clip.title } })}><SquarePen /> Créer une publication</Button>
                    <Button variant="outline" className="flex-1" onClick={() => exportClip.mutate({ projectId: project.id, clipId: clip.id })}><Download /> Réexporter</Button>
                  </div>
                ) : (
                  <Button className="w-full" onClick={() => exportClip.mutate({ projectId: project.id, clipId: clip.id }, { onSuccess: () => toast.info("Export du clip lancé (simulation).") })}>
                    <Download /> Exporter le clip
                  </Button>
                )}
              </div>
            </div>
          </section>
        )}
      </div>

      {/* Clips + transcription */}
      <aside className="surface-card flex max-h-none flex-col xl:sticky xl:top-6 xl:max-h-[calc(100dvh-3rem)] xl:self-start">
        <Tabs defaultValue="clips" className="flex min-h-0 flex-1 flex-col">
          <div className="border-b border-border px-5 pt-2">
            <TabsList variant="line" className="border-none">
              <TabsTrigger value="clips">Clips détectés ({clips.length})</TabsTrigger>
              <TabsTrigger value="transcript">Transcription</TabsTrigger>
            </TabsList>
          </div>
          <TabsContent value="clips" className="min-h-0 flex-1 overflow-y-auto p-3">
            <ul className="space-y-2">
              {clips.map((c) => (
                <li key={c.id}>
                  <button type="button" onClick={() => select(c)} aria-current={c.id === selectedId ? "true" : undefined} className={cn("flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-colors", c.id === selectedId ? "border-brand-500 bg-brand-50/60" : "border-border hover:border-brand-200")}>
                    <span className={cn("relative w-12 shrink-0 overflow-hidden rounded-lg", project.previewClassName)} style={{ aspectRatio: RATIOS.find((r) => r.value === c.aspectRatio)?.css }} aria-hidden="true" />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-semibold text-ink">{c.title}</span>
                      <span className="block text-xs text-ink-muted tabular-nums">{formatDuration(c.startMs)} · {Math.round((c.endMs - c.startMs) / 1000)} s · {c.aspectRatio}</span>
                      <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <Badge variant={scoreTone(c.score)}>{c.score !== null ? `Score ${c.score}` : "Manuel"}</Badge>
                        <StatusBadge meta={CLIP_STATUS[c.status]} withDot={false} />
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
            <Button variant="outline" className="mt-3 w-full" onClick={() => addClip.mutate({ projectId: project.id, startMs: current }, { onSuccess: (c) => { setSelectedId(c.id); toast.success("Clip ajouté à la position actuelle.") } })}>
              <Plus /> Ajouter un clip ici ({formatDuration(current)})
            </Button>
          </TabsContent>
          <TabsContent value="transcript" className="flex min-h-0 flex-1 flex-col p-3">
            <div className="relative mb-3">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
              <Input value={transcriptQuery} onChange={(e) => setTranscriptQuery(e.target.value)} placeholder="Rechercher dans la transcription" className="h-9 pl-9" aria-label="Rechercher dans la transcription" />
            </div>
            <ol className="min-h-0 flex-1 space-y-1 overflow-y-auto">
              {segments.map((s) => {
                const active = current >= s.startMs && current <= s.endMs
                return (
                  <li key={s.id}>
                    <button type="button" onClick={() => setCurrent(s.startMs)} className={cn("flex w-full gap-3 rounded-lg px-2 py-2 text-left text-sm", active ? "bg-brand-50" : "hover:bg-muted/60")}>
                      <span className="w-12 shrink-0 text-xs font-semibold text-brand-600 tabular-nums">{formatDuration(s.startMs)}</span>
                      <span className="text-ink-soft">{s.text}</span>
                    </button>
                  </li>
                )
              })}
              {segments.length === 0 && <li className="p-3 text-sm text-ink-muted">Aucun passage ne contient « {transcriptQuery} ».</li>}
            </ol>
          </TabsContent>
        </Tabs>
      </aside>
    </div>
  )
}
