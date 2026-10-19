import { useQueryClient } from "@tanstack/react-query"
import { ArrowLeft, Download, ImageOff, ImagePlus, LoaderCircle, Sparkles, SquarePen } from "lucide-react"
import { useEffect, useId, useRef, useState, type FormEvent } from "react"
import { Link, useNavigate } from "react-router"
import { toast } from "sonner"
import { IMAGE_FORMATS } from "@/components/creative/labels"
import { PageHeader } from "@/components/shared/PageHeader"
import { SectionCard } from "@/components/shared/SectionCard"
import { EmptyState, ErrorState, ListSkeleton } from "@/components/shared/States"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Textarea } from "@/components/ui/textarea"
import { useGenerateImage, useImageGenerations } from "@/hooks/queries/creative"
import { qk } from "@/hooks/queries/keys"
import { apiErrorMessage, isApiEnabled } from "@/lib/http"
import { cn } from "@/lib/utils"
import { CREATIVE_UNAVAILABLE } from "@/services/creative"
import type { AiImageGeneration, ImageAspectRatio } from "@/types"

const MAX_PROMPT = 2000

/** Ratio CSS d'un format (« 4:5 » → « 4 / 5 »). */
const cssRatio = (ratio: string | null) => (ratio ?? "1:1").replace(":", " / ")

/** Génération d'images par IA : prompt, format, image enregistrée dans la médiathèque (ADR-046). */
export default function ImageStudioPage() {
  const id = useId()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const generations = useImageGenerations()
  const generate = useGenerateImage()
  const [prompt, setPrompt] = useState("")
  const [aspectRatio, setAspectRatio] = useState<ImageAspectRatio>("1:1")
  const [currentId, setCurrentId] = useState<string | null>(null)

  const list = generations.data?.generations ?? []
  const configured = generations.data?.configured ?? true
  const current = list.find((g) => g.id === currentId) ?? null
  const trimmed = prompt.trim()

  // Une image vient d'être enregistrée pendant que la page est ouverte : la médiathèque est relue.
  const succeeded = generations.data ? list.filter((g) => g.status === "succeeded").length : null
  const previous = useRef<number | null>(null)
  useEffect(() => {
    if (succeeded === null) return
    if (previous.current !== null && succeeded > previous.current) void qc.invalidateQueries({ queryKey: qk.media.all })
    previous.current = succeeded
  }, [succeeded, qc])

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (trimmed.length < 3) return
    generate.mutate({ prompt: trimmed, aspectRatio }, {
      onSuccess: (g) => setCurrentId(g.id),
      onError: (err) => toast.error(apiErrorMessage(err, "La génération n'a pas pu être lancée.")),
    })
  }

  const use = (g: AiImageGeneration) => g.media && navigate("/content/create", { state: { mediaIds: [g.media.id] } })

  return (
    <div>
      <Link to="/studio" className="mb-3 inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden="true" /> AI Creative Studio
      </Link>
      <PageHeader title="Générer une image" description="Décrivez le visuel, choisissez son format : l'image générée rejoint votre médiathèque." />

      {!isApiEnabled ? (
        <EmptyState icon={ImagePlus} title="Génération indisponible" description={CREATIVE_UNAVAILABLE} />
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <SectionCard title="Votre image">
              <form onSubmit={submit} className="space-y-4" noValidate>
                <div className="space-y-1.5">
                  <Label htmlFor={`${id}-prompt`}>Description</Label>
                  <Textarea
                    id={`${id}-prompt`}
                    value={prompt}
                    maxLength={MAX_PROMPT}
                    rows={5}
                    onChange={(e) => setPrompt(e.target.value)}
                    placeholder="Ex. : une image moderne et professionnelle pour accompagner un post sur l'intelligence artificielle"
                    aria-describedby={`${id}-count`}
                  />
                  <p id={`${id}-count`} className="text-right text-xs text-ink-muted tabular-nums">{prompt.length} / {MAX_PROMPT}</p>
                </div>

                <fieldset className="space-y-2">
                  <legend className="text-sm font-medium text-ink">Format</legend>
                  <RadioGroup value={aspectRatio} onValueChange={(v) => setAspectRatio(v as ImageAspectRatio)} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {IMAGE_FORMATS.map((f) => (
                      <Label
                        key={f.value}
                        htmlFor={`${id}-${f.value}`}
                        className={cn(
                          "flex cursor-pointer flex-col items-center gap-2 rounded-xl border p-3 text-center font-normal transition-colors",
                          aspectRatio === f.value ? "border-brand-500 bg-brand-50" : "border-border hover:bg-muted",
                        )}
                      >
                        <RadioGroupItem id={`${id}-${f.value}`} value={f.value} className="sr-only" />
                        <span className="flex h-12 items-center" aria-hidden="true">
                          <span className="block h-full rounded border-2 border-current text-brand-500" style={{ aspectRatio: cssRatio(f.value), maxWidth: "4.5rem" }} />
                        </span>
                        <span className="text-sm font-semibold text-ink">{f.label}</span>
                        <span className="text-xs text-ink-muted">{f.hint}</span>
                      </Label>
                    ))}
                  </RadioGroup>
                </fieldset>

                {!configured && (
                  <p className="rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
                    La génération d'images n'est pas configurée sur le serveur (fournisseur ou modèle d'image manquant).
                  </p>
                )}
                <Button type="submit" className="w-full" disabled={trimmed.length < 3 || generate.isPending || !configured}>
                  {generate.isPending ? <LoaderCircle className="animate-spin" /> : <Sparkles />} Générer l'image
                </Button>
              </form>
            </SectionCard>

            <SectionCard title="Résultat">
              {current ? <GenerationResult generation={current} onUse={use} /> : (
                <EmptyState icon={ImagePlus} title="Aucune image en cours" description="Votre image apparaîtra ici, puis dans votre médiathèque." className="py-8" />
              )}
            </SectionCard>
          </div>

          <SectionCard title="Images récentes">
            {generations.isLoading ? (
              <ListSkeleton rows={2} />
            ) : generations.isError ? (
              <ErrorState onRetry={() => generations.refetch()} />
            ) : list.length === 0 ? (
              <p className="text-sm text-ink-muted">Aucune image générée pour le moment.</p>
            ) : (
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {list.map((g) => (
                  <li key={g.id}>
                    <button
                      type="button"
                      onClick={() => setCurrentId(g.id)}
                      aria-pressed={g.id === currentId}
                      className={cn("block w-full overflow-hidden rounded-xl border text-left", g.id === currentId ? "border-brand-500 ring-2 ring-brand-500/30" : "border-border")}
                    >
                      <Preview generation={g} />
                      <span className="block truncate px-2 py-1.5 text-xs text-ink-muted">{g.prompt}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>
        </div>
      )}
    </div>
  )
}

/** Vignette d'une génération (image, attente ou échec), au ratio du format choisi. */
function Preview({ generation: g, large = false }: { generation: AiImageGeneration; large?: boolean }) {
  const src = g.media?.url ?? (g.media?.preview.kind === "image" ? g.media.preview.src : null)
  return (
    <div className={cn("relative flex w-full items-center justify-center overflow-hidden bg-muted", large && "max-h-[28rem]")} style={{ aspectRatio: cssRatio(g.aspectRatio) }}>
      {g.status === "succeeded" && src ? (
        <img src={src} alt={g.prompt ?? "Image générée"} className="size-full object-cover" />
      ) : g.status === "failed" ? (
        <ImageOff className="size-6 text-ink-muted" aria-hidden="true" />
      ) : g.status === "succeeded" ? (
        <span className="px-2 text-center text-xs text-ink-muted">Traitement dans la médiathèque…</span>
      ) : (
        <LoaderCircle className="size-6 animate-spin text-brand-500" aria-hidden="true" />
      )}
    </div>
  )
}

function GenerationResult({ generation: g, onUse }: { generation: AiImageGeneration; onUse: (g: AiImageGeneration) => void }) {
  return (
    <div className="space-y-3">
      <div className="mx-auto max-w-sm overflow-hidden rounded-xl border border-border">
        <Preview generation={g} large />
      </div>
      {g.status === "pending" || g.status === "processing" ? (
        <p className="flex items-center justify-center gap-2 text-sm text-ink-muted" role="status">
          <LoaderCircle className="size-4 animate-spin text-brand-500" aria-hidden="true" /> Génération en cours… (jusqu'à une minute)
        </p>
      ) : g.status === "failed" ? (
        <p role="alert" className="rounded-lg bg-danger-50 p-2.5 text-sm text-danger-700">{g.errorMessage ?? "La génération a échoué."}</p>
      ) : (
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="success">Enregistrée dans la médiathèque</Badge>
            {g.width && g.height && <span className="text-xs text-ink-muted">{g.aspectRatio} · {g.width} × {g.height}</span>}
          </div>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={!g.media?.usable} onClick={() => onUse(g)}><SquarePen /> Utiliser dans une publication</Button>
            {g.media?.downloadUrl && (
              <Button asChild size="sm" variant="outline"><a href={g.media.downloadUrl}><Download /> Télécharger</a></Button>
            )}
            <Button asChild size="sm" variant="ghost"><Link to="/media">Voir la médiathèque</Link></Button>
          </div>
        </div>
      )}
    </div>
  )
}
