import { AlertCircle, CheckCircle2, Copy, FileText, Film, ImageIcon, LoaderCircle, Music, ShieldAlert, X } from "lucide-react"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import { Progress } from "@/components/ui/progress"
import { useUploadQueue } from "@/hooks/useUploadQueue"
import { formatBytes } from "@/lib/format"
import { UPLOAD_STATE } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { UploadItem } from "@/types"

const TYPE_ICON = { image: ImageIcon, video: Film, audio: Music, document: FileText } as const
const TYPE_LABEL = { image: "Image", video: "Vidéo", audio: "Audio", document: "Document" } as const

function StateIcon({ item }: { item: UploadItem }) {
  if (item.state === "uploading" || item.state === "processing" || item.state === "pending") {
    return <LoaderCircle className="size-4 animate-spin text-brand-500 motion-reduce:animate-none" aria-hidden="true" />
  }
  if (item.state === "failed") return <AlertCircle className="size-4 text-danger-600" aria-hidden="true" />
  if (item.state === "quarantined") return <ShieldAlert className="size-4 text-warning-600" aria-hidden="true" />
  return <CheckCircle2 className="size-4 text-success-600" aria-hidden="true" />
}

/** Suivi des imports : nom, type, taille, progression, état, succès ou erreur compréhensible. */
export function UploadQueuePanel({ className }: { className?: string }) {
  const { items, dismiss, clearFinished } = useUploadQueue()
  if (items.length === 0) return null
  const active = items.some((i) => i.state === "pending" || i.state === "uploading")

  return (
    <section aria-labelledby="uploads-title" className={cn("rounded-xl border border-border bg-page/60 p-3", className)}>
      <div className="mb-2 flex items-center justify-between gap-3">
        <h2 id="uploads-title" className="text-sm font-semibold">Imports</h2>
        {!active && (
          <Button variant="ghost" size="sm" onClick={clearFinished}>
            Effacer la liste
          </Button>
        )}
      </div>
      <ul className="max-h-64 space-y-2 overflow-y-auto" aria-live="polite">
        {items.map((item) => {
          const Icon = item.type ? TYPE_ICON[item.type] : FileText
          const finished = item.state !== "pending" && item.state !== "uploading"
          return (
            <li key={item.id} className="rounded-lg bg-card px-3 py-2.5 shadow-card">
              <div className="flex items-center gap-3">
                <Icon className="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-ink" title={item.name}>{item.name}</p>
                  <p className="text-xs text-ink-muted">
                    {item.type ? TYPE_LABEL[item.type] : "Format inconnu"} · {formatBytes(item.sizeBytes)}
                  </p>
                </div>
                <StateIcon item={item} />
                <StatusBadge meta={UPLOAD_STATE[item.state]} />
                {finished && (
                  <Button variant="ghost" size="icon-xs" onClick={() => dismiss(item.id)} aria-label={`Retirer ${item.name} de la liste`}>
                    <X />
                  </Button>
                )}
              </div>
              {item.state === "uploading" && (
                <Progress value={item.progress} className="mt-2 h-1.5" aria-label={`Envoi de ${item.name} : ${item.progress} %`} />
              )}
              {item.error && (
                <p className="mt-1.5 text-xs text-danger-700">{item.error}</p>
              )}
              {item.state === "quarantined" && (
                <p className="mt-1.5 text-xs text-warning-700">Le fichier sera disponible après l'analyse de sécurité.</p>
              )}
              {item.duplicate && (
                <p className="mt-1.5 inline-flex items-center gap-1 text-xs text-ink-muted">
                  <Copy className="size-3" aria-hidden="true" /> Ce fichier existe déjà dans votre médiathèque (importé quand même).
                </p>
              )}
            </li>
          )
        })}
      </ul>
    </section>
  )
}
